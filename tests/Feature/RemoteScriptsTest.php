<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

const REMOTE_ROOT = __DIR__.'/../Tmp/remote-hestia';

/**
 * A fake HestiaCP install for the scripts that run on the old server.
 */
function fakeHestia(string $backupSystem = 'local', bool $withDomains = true, bool $backupCreatesArchive = true): void
{
    $files = new Filesystem;
    $root = REMOTE_ROOT;
    foreach (['conf', 'bin', 'data/users/alice', 'backup'] as $dir) {
        $files->ensureDirectoryExists("$root/$dir");
    }
    file_put_contents("$root/conf/hestia.conf", "BACKUP='$root/backup'\nBACKUP_SYSTEM='$backupSystem'\n");
    touch("$root/data/users/alice/user.conf");
    file_put_contents("$root/backup/alice.2026-11-16_03-00-00.tar", 'old!!');

    $lists = [
        'v-list-user' => '{"alice": {"U_DISK": "100", "BACKUPS": "3"}}',
        'v-list-web-domains' => $withDomains ? '{"a.test": {"ALIAS": "www.a.test", "BACKEND": "PHP-8_3"}}' : '',
        'v-list-dns-domains' => $withDomains ? '{"a.test": {}}' : '{}',
        'v-list-mail-domains' => $withDomains ? '{"a.test": {}}' : '[]',
        'v-list-databases' => $withDomains ? '{"alice_db": {}}' : '{}',
    ];
    foreach ($lists as $command => $json) {
        file_put_contents("$root/bin/$command", "#!/usr/bin/env bash\nprintf '%s' '$json'\n");
    }
    $backup = $backupCreatesArchive ? "printf 'new' > '$root/backup/alice.2026-11-17_03-00-00.tar'" : 'true';
    file_put_contents("$root/bin/v-backup-user", "#!/usr/bin/env bash\necho 'backing up'\n$backup\n");

    foreach (glob("$root/bin/*") as $stub) {
        chmod($stub, 0755);
    }
}

function runRemote(string $script, string ...$args): ProcessResult
{
    return Process::env(['HESTIA' => REMOTE_ROOT])->run(['bash', base_path("resources/remote/$script.sh"), ...$args]);
}

afterEach(fn () => (new Filesystem)->deleteDirectory(REMOTE_ROOT));

it('reports the account as JSON', function () {
    fakeHestia(backupSystem: 'local,rclone');

    $result = runRemote('report', 'alice');
    $report = json_decode($result->output(), true);

    expect($result->successful())->toBeTrue()
        ->and($report['backup_dir'])->toBe(REMOTE_ROOT.'/backup')
        ->and($report['backup_system'])->toBe('local,rclone')
        ->and($report['rsync'])->toBeBool()
        ->and($report['user']['alice']['BACKUPS'])->toBe('3')
        ->and(array_keys($report['web']))->toBe(['a.test'])
        ->and($report['databases'])->toHaveKey('alice_db')
        ->and($report['archives'])->toBe(['alice.2026-11-16_03-00-00.tar' => 5]);
});

it('reports an account without domains', function () {
    fakeHestia(withDomains: false);

    $report = json_decode(runRemote('report', 'alice')->output(), true);

    expect($report['web'])->toBe([])
        ->and($report['dns'])->toBe([])
        ->and($report['mail'])->toBe([])
        ->and($report['databases'])->toBe([]);
});

it('fails for an unknown user', function () {
    fakeHestia();

    $result = runRemote('report', 'bob');

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("Hestia user 'bob' does not exist on the old server");
});

it('prints the new archive name after a backup', function () {
    fakeHestia();

    $result = runRemote('backup', 'alice');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("alice.2026-11-17_03-00-00.tar\n")
        ->and($result->errorOutput())->toContain('backing up');
});

it('fails when the backup leaves no new archive', function () {
    fakeHestia(backupCreatesArchive: false);

    $result = runRemote('backup', 'alice');

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('no new archive');
});
