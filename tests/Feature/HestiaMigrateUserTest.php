<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

const MIGRATE_ROOT = __DIR__.'/../Tmp/migrate';
const NEW_ARCHIVE = 'alice.2026-11-17_03-00-00.tar';
const OLD_ARCHIVE = 'alice.2026-11-16_03-00-00.tar';
const ARCHIVE_CONTENT = 'archive!';

function localHestia(): void
{
    $files = new Filesystem;
    foreach (['conf', 'bin', 'users', 'backup', 'home'] as $dir) {
        $files->ensureDirectoryExists(MIGRATE_ROOT."/$dir");
    }
    file_put_contents(MIGRATE_ROOT.'/conf/hestia.conf', "BACKUP='".MIGRATE_ROOT."/backup'\n");
    touch(MIGRATE_ROOT.'/bin/v-restore-user');
    chmod(MIGRATE_ROOT.'/bin/v-restore-user', 0755);

    config([
        'hestia.conf' => MIGRATE_ROOT.'/conf/hestia.conf',
        'hestia.bin' => MIGRATE_ROOT.'/bin',
        'hestia.users_path' => MIGRATE_ROOT.'/users',
        'hestia.home_path' => MIGRATE_ROOT.'/home',
    ]);
}

function migrationReport(array $overrides = []): array
{
    return [
        'backup_dir' => '/backup',
        'backup_system' => 'local',
        'rsync' => true,
        'user' => ['alice' => ['U_DISK' => '10', 'BACKUPS' => '3']],
        'web' => ['a.test' => ['ALIAS' => 'www.a.test', 'BACKEND' => 'PHP-8_3']],
        'dns' => ['a.test' => []],
        'mail' => ['a.test' => []],
        'databases' => ['alice_db' => []],
        'archives' => [OLD_ARCHIVE => 4],
        ...$overrides,
    ];
}

function migrateLog(): ArrayObject
{
    static $log = new ArrayObject;

    return $log;
}

function migrateRan(callable $match): bool
{
    return array_filter(migrateLog()->getArrayCopy(), $match) !== [];
}

/**
 * Fakes the old server (ssh), rsync and the local Hestia commands.
 */
function fakeServers(array $o = []): void
{
    Process::swap(new Factory);
    migrateLog()->exchangeArray([]);
    $report = $o['report'] ?? migrationReport();

    Process::fake(function (PendingProcess $process) use ($o, $report) {
        $command = $process->command;
        migrateLog()->append($command);
        if (in_array('sha256sum', $command, true) || in_array('stat', $command, true)) {
            migrateLog()->append(['timeout', in_array('stat', $command, true) ? 'stat' : 'sha256sum', $process->timeout]);
        }
        $bin = config('hestia.bin');
        $content = $o['archiveContent'] ?? ARCHIVE_CONTENT;

        if ($command === ['ssh', '-V'] || $command === ['rsync', '--version']) {
            return Process::result();
        }
        if ($command[0] === 'ssh') {
            $input = (string) $process->input;
            if (preg_match('/^# hcpc remote: (\w+)$/m', $input, $script)) {
                migrateLog()->append(["remote:$script[1]"]);
            }

            return match (true) {
                str_contains($input, 'hcpc remote: report') => Process::result(
                    output: json_encode($report), errorOutput: $o['reportError'] ?? '', exitCode: $o['reportExit'] ?? 0,
                ),
                str_contains($input, 'hcpc remote: backup') => Process::result(
                    output: ($o['backupArchive'] ?? NEW_ARCHIVE)."\n", exitCode: $o['backupExit'] ?? 0,
                ),
                in_array('sha256sum', $command, true) => Process::result(output: ($o['remoteHash'] ?? hash('sha256', $content)).'  '.end($command)."\n"),
                in_array('stat', $command, true) => Process::result(output: ($o['remoteSize'] ?? strlen($content))."\n"),
                default => Process::result(),
            };
        }
        if ($command[0] === 'rsync') {
            file_put_contents(end($command), $content);

            return Process::result(exitCode: $o['rsyncExit'] ?? 0);
        }

        return match ($command[0]) {
            'getent' => Process::result(exitCode: $o['getentExit'] ?? 2),
            'df' => Process::result(output: sprintf(
                "     Avail Mounted on\n%d %s\n%d %s\n",
                $o['dfFree'] ?? 10 ** 12, '/',
                $o['dfHomeFree'] ?? $o['dfFree'] ?? 10 ** 12, isset($o['dfHomeFree']) ? '/home' : '/',
            )),
            // Hestia prints the installed backend templates as a JSON list.
            "$bin/v-list-web-templates-backend" => Process::result(output: json_encode(['PHP-8_3', 'default'])),
            "$bin/v-list-web-domains" => Process::result(output: json_encode($o['localWeb'] ?? $report['web'])),
            "$bin/v-list-dns-domains" => Process::result(output: json_encode($report['dns'])),
            "$bin/v-list-mail-domains" => Process::result(output: json_encode($report['mail'])),
            "$bin/v-list-databases" => Process::result(output: json_encode($report['databases'])),
            "$bin/v-restore-user" => Process::result(exitCode: $o['restoreExit'] ?? 0),
            default => Process::result(),
        };
    });
}

/**
 * Adds a domain to another user's Hestia config on this server, as Hestia stores it.
 */
function localDomain(string $owner, string $type, string $domain, string $alias = ''): void
{
    (new Filesystem)->ensureDirectoryExists(MIGRATE_ROOT."/users/$owner");
    $line = $type === 'web' ? "DOMAIN='$domain' IP='10.0.0.1' ALIAS='$alias' TPL='default'" : "DOMAIN='$domain' IP='10.0.0.1'";
    file_put_contents(MIGRATE_ROOT."/users/$owner/$type.conf", $line."\n", FILE_APPEND);
}

function ranScript(string $script): bool
{
    return migrateRan(fn (array $command) => $command === ["remote:$script"]);
}

function ranCommand(string $name): bool
{
    return migrateRan(fn (array $command) => basename($command[0]) === $name);
}

beforeEach(fn () => localHestia());

afterEach(fn () => (new Filesystem)->deleteDirectory(MIGRATE_ROOT));

it('rejects invalid user names', function (array $arguments) {
    fakeServers();

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', ...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain('Invalid Hestia user name')
        ->assertExitCode(1);

    expect(migrateLog()->getArrayCopy())->toBe([]);
})->with([
    'uppercase' => [['user' => 'Alice']],
    'space' => [['user' => 'al ice']],
    'leading digit' => [['user' => '1alice']],
    'bad destination' => [['user' => 'alice', 'destination' => 'bad-name']],
]);

it('refuses when the Hestia user already exists here', function () {
    fakeServers();
    (new Filesystem)->ensureDirectoryExists(MIGRATE_ROOT.'/users/alice');
    touch(MIGRATE_ROOT.'/users/alice/user.conf');

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain("Hestia user 'alice' already exists here")
        ->assertExitCode(1);

    expect(ranScript('report'))->toBeFalse();
});

it('refuses when the Linux user already exists here', function () {
    fakeServers(['getentExit' => 0]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain("Linux user 'alice' already exists here")
        ->assertExitCode(1);

    expect(ranScript('report'))->toBeFalse();
});

it('explains how to reach the old server', function () {
    fakeServers(['reportExit' => 255, 'reportError' => 'Host key verification failed.']);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--identity' => '/root/key', '--no-interaction' => true])
        ->expectsOutputToContain('sudo ssh -i /root/key root@srv1.example.com true')
        ->expectsOutputToContain('Host key verification failed.')
        ->assertExitCode(1);

    expect(ranCommand('v-search-domain-owner'))->toBeFalse();
});

it('refuses domains that belong to another user here', function () {
    fakeServers();
    localDomain('bob', 'web', 'a.test');

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('a.test (bob)')
        ->assertExitCode(1);

    expect(ranScript('backup'))->toBeFalse();
});

it('refuses names that are aliases of another user\'s site here', function () {
    fakeServers();
    localDomain('bob', 'web', 'b.test', 'shop.b.test,www.a.test');

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('www.a.test (bob)')
        ->assertExitCode(1);

    expect(ranScript('backup'))->toBeFalse();
});

it('refuses DNS and mail domains that belong to another user here', function (string $type) {
    fakeServers();
    localDomain('bob', $type, 'a.test');

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('a.test (bob)')
        ->assertExitCode(1);
})->with(['dns', 'mail']);

it('does not warn about PHP versions that are installed here', function () {
    fakeServers();

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->doesntExpectOutputToContain('not installed here')
        ->assertExitCode(0);
});

it('warns about PHP versions that are not installed here', function () {
    fakeServers(['report' => migrationReport(['web' => ['a.test' => ['ALIAS' => '', 'BACKEND' => 'PHP-7_4']]])]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('PHP-7_4')
        ->assertExitCode(0);
});

it('warns that a new backup removes the oldest one on the old server', function () {
    fakeServers(['report' => migrationReport([
        'user' => ['alice' => ['U_DISK' => '10', 'BACKUPS' => '2']],
        'archives' => ['alice.2026-11-15_03-00-00.tar' => 4, OLD_ARCHIVE => 4],
    ])]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('alice.2026-11-15_03-00-00.tar')
        ->expectsOutputToContain('--archive='.OLD_ARCHIVE)
        ->assertExitCode(0);
});

it('refuses a new backup when the old server keeps no local copy', function () {
    fakeServers(['report' => migrationReport(['backup_system' => 'rclone'])]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('--archive')
        ->assertExitCode(1);
});

it('refuses an archive the old server does not have', function () {
    fakeServers();

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--archive' => 'alice.2026-01-01_03-00-00.tar', '--no-interaction' => true])
        ->expectsOutputToContain('alice.2026-01-01_03-00-00.tar')
        ->assertExitCode(1);
});

it('refuses an unexpected backup folder on the old server', function () {
    fakeServers(['report' => migrationReport(['backup_dir' => '/backup; rm -rf /'])]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('Unexpected backup folder')
        ->assertExitCode(1);
});

it('counts the restored files in the disk check', function () {
    fakeServers([
        'report' => migrationReport(['archives' => [OLD_ARCHIVE => 10 * 1024 * 1024]]),
        'dfFree' => 25 * 1024 * 1024,
    ]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--archive' => OLD_ARCHIVE, '--no-interaction' => true])
        ->expectsOutputToContain('Not enough disk space')
        ->assertExitCode(1);
});

it('checks the home folder separately when it is on another disk', function () {
    fakeServers(['dfHomeFree' => 5 * 1024 * 1024]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('Not enough disk space')
        ->expectsOutputToContain(MIGRATE_ROOT.'/home')
        ->assertExitCode(1);
});

it('refuses when there is not enough disk space', function () {
    fakeServers(['dfFree' => 10 * 1024 * 1024]);

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('Not enough disk space')
        ->assertExitCode(1);
});

it('only checks without --apply when not interactive', function () {
    fakeServers();

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--no-interaction' => true])
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(0);

    expect(ranScript('report'))->toBeTrue()
        ->and(ranScript('backup'))->toBeFalse()
        ->and(ranCommand('rsync'))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command[0] === 'rsync' && $command !== ['rsync', '--version']))->toBeFalse();
});

it('asks before migrating when interactive', function () {
    fakeServers();

    $this->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice'])
        ->expectsConfirmation('Migrate alice from srv1.example.com now?', 'no')
        ->assertExitCode(0);

    expect(ranScript('backup'))->toBeFalse();
});

function migrate(array $arguments = []): PendingCommand
{
    return test()->artisan('hestia:migrate-user', ['host' => 'srv1.example.com', 'user' => 'alice', '--apply' => true, '--no-interaction' => true, ...$arguments]);
}

function rsyncTransferRan(?string $target = null): bool
{
    return migrateRan(fn (array $command) => $command[0] === 'rsync' && $command !== ['rsync', '--version']
        && ($target === null || end($command) === $target));
}

it('migrates an account', function () {
    fakeServers();
    $archive = MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE;

    migrate()->expectsOutputToContain('Point DNS')->assertExitCode(0);

    expect(ranScript('backup'))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command[0] === 'rsync' && in_array('root@srv1.example.com:/backup/'.NEW_ARCHIVE, $command, true)))->toBeTrue()
        ->and(rsyncTransferRan("$archive.partial"))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command === [MIGRATE_ROOT.'/bin/v-restore-user', 'alice', NEW_ARCHIVE]))->toBeTrue()
        ->and(file_get_contents($archive))->toBe(ARCHIVE_CONTENT)
        ->and(fileperms($archive) & 0777)->toBe(0600)
        ->and("$archive.partial")->not->toBeFile();
});

it('restores under a new user name', function () {
    fakeServers();

    migrate(['destination' => 'alice2'])->assertExitCode(0);

    expect(migrateRan(fn (array $command) => $command === [MIGRATE_ROOT.'/bin/v-restore-user', 'alice2', NEW_ARCHIVE]))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command === ['getent', 'passwd', 'alice2']))->toBeTrue();
});

it('reuses an existing archive and resumes a partial transfer', function () {
    fakeServers();
    $partial = MIGRATE_ROOT.'/backup/'.OLD_ARCHIVE.'.partial';
    file_put_contents($partial, 'arch');

    migrate(['--archive' => OLD_ARCHIVE])->assertExitCode(0);

    expect(ranScript('backup'))->toBeFalse()
        ->and(rsyncTransferRan($partial))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command === [MIGRATE_ROOT.'/bin/v-restore-user', 'alice', OLD_ARCHIVE]))->toBeTrue();
});

it('refuses to overwrite an archive that already exists here', function () {
    fakeServers();
    file_put_contents(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE, 'other');

    migrate()->expectsOutputToContain('already has '.NEW_ARCHIVE)->assertExitCode(1);

    expect(rsyncTransferRan())->toBeFalse()
        ->and(file_get_contents(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE))->toBe('other');
});

it('deletes the partial file when the checksum does not match', function () {
    fakeServers(['remoteHash' => str_repeat('0', 64)]);

    migrate()->expectsOutputToContain('SHA-256')->assertExitCode(1);

    expect(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE.'.partial')->not->toBeFile()
        ->and(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE)->not->toBeFile()
        ->and(ranCommand('v-restore-user'))->toBeFalse();
});

it('explains how to resume when the transfer fails', function () {
    fakeServers(['rsyncExit' => 23]);

    migrate()->expectsOutputToContain('--archive='.NEW_ARCHIVE)->assertExitCode(1);

    expect(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE.'.partial')->toBeFile()
        ->and(ranCommand('v-restore-user'))->toBeFalse();
});

it('explains how to clean up when the restore fails', function () {
    fakeServers(['restoreExit' => 1]);

    migrate()
        ->expectsOutputToContain('v-delete-user alice')
        ->expectsOutputToContain('v-restore-user alice '.NEW_ARCHIVE)
        ->assertExitCode(1);
});

it('rechecks disk space with the real archive size', function () {
    fakeServers(['dfFree' => 30 * 1024 * 1024, 'remoteSize' => 20 * 1024 * 1024]);

    migrate()->expectsOutputToContain('Not enough disk space')->assertExitCode(1);

    expect(ranScript('backup'))->toBeTrue()
        ->and(rsyncTransferRan())->toBeFalse();
});

it('warns when the restored account differs', function () {
    fakeServers(['localWeb' => []]);

    migrate()->expectsOutputToContain('differs')->assertExitCode(0);
});

it('waits as long as the remote checksum and size take', function () {
    fakeServers();

    migrate()->assertExitCode(0);

    expect(migrateRan(fn (array $command) => $command === ['timeout', 'sha256sum', null]))->toBeTrue()
        ->and(migrateRan(fn (array $command) => $command === ['timeout', 'stat', null]))->toBeTrue();
});

it('keeps the copy private to root', function () {
    fakeServers();

    migrate()->assertExitCode(0);

    $rsync = array_values(array_filter(migrateLog()->getArrayCopy(), fn (array $command) => $command[0] === 'rsync' && $command !== ['rsync', '--version']))[0];
    expect($rsync)->toContain('--chmod=F600')
        ->and(array_intersect($rsync, ['-a', '-o', '-g', '--owner', '--group']))->toBe([]);
});

it('advises reusing the archive instead of making another backup', function (array $overrides, array $arguments) {
    if (isset($overrides['existing'])) {
        file_put_contents(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE, 'other');
        unset($overrides['existing']);
    }
    fakeServers($overrides);

    migrate($arguments)->expectsOutputToContain('--archive='.NEW_ARCHIVE)->assertExitCode(1);
})->with([
    'checksum mismatch' => [['remoteHash' => str_repeat('0', 64)], []],
    'not enough disk space after the backup' => [['dfFree' => 30 * 1024 * 1024, 'remoteSize' => 20 * 1024 * 1024], []],
]);

it('explains how to restore an archive that is already here', function () {
    fakeServers();
    file_put_contents(MIGRATE_ROOT.'/backup/'.NEW_ARCHIVE, 'other');

    migrate()->expectsOutputToContain('v-restore-user alice '.NEW_ARCHIVE)->assertExitCode(1);
});

it('tells how to reuse a backup when its name is unexpected', function () {
    fakeServers(['backupArchive' => 'garbage']);

    migrate()->expectsOutputToContain('--archive=')->assertExitCode(1);
});
