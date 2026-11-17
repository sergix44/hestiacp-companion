<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

const SYNC_SOURCE = __DIR__.'/../Tmp/sync-source';

/**
 * A database in WAL mode with its rows still in the -wal file, like one held open by a running container.
 */
function liveDatabase(string $path): SQLite3
{
    $db = new SQLite3($path);
    $db->exec('PRAGMA journal_mode=WAL; CREATE TABLE items (id INTEGER); INSERT INTO items VALUES (1), (2), (3);');

    return $db;
}

/**
 * Fakes rclone and records each call, reading the temporary files the command deletes when it finishes.
 */
function fakeRclone(int $exitCode = 0): ArrayObject
{
    $calls = new ArrayObject;

    Process::fake(function (PendingProcess $process) use ($calls, $exitCode) {
        $command = $process->command;
        $file = fn (string $flag) => file_get_contents($command[array_search($flag, $command) + 1]);

        $calls[] = [
            'command' => $command,
            'read' => match ($command[1]) {
                'sync' => $file('--exclude-from'),
                'copyto' => (new SQLite3($command[2], SQLITE3_OPEN_READONLY))->querySingle('SELECT COUNT(*) FROM items'),
                'delete' => $file('--files-from0'),
            },
        ];

        return Process::result(exitCode: $exitCode);
    });

    return $calls;
}

beforeEach(fn () => (new Filesystem)->ensureDirectoryExists(SYNC_SOURCE.'/app/data'));

afterEach(fn () => (new Filesystem)->deleteDirectory(SYNC_SOURCE));

it('uploads a consistent snapshot in place of each live database', function (string $destination, string $remoteDatabase) {
    $db = liveDatabase(SYNC_SOURCE.'/app/data/app.db');
    file_put_contents(SYNC_SOURCE.'/app/compose.yaml', "services: {}\n");
    $source = realpath(SYNC_SOURCE);
    $calls = fakeRclone();

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE, 'destination' => $destination])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect($calls)->toHaveCount(3);
    [$sync, $copy, $delete] = $calls;

    expect($sync['command'])->toMatchArray([1 => 'sync', $source, $destination, '--links', '--exclude-from'])
        ->and(end($sync['command']))->toBe('--dry-run')
        ->and($sync['read'])->toBe("/app/data/app.db\n/app/data/app.db-wal\n/app/data/app.db-shm\n/app/data/app.db-journal\n");

    expect($copy['command'])->toMatchArray([1 => 'copyto', 3 => $remoteDatabase, 4 => '--dry-run'])
        ->and($copy['read'])->toBe(3);

    expect($delete['command'])->toMatchArray([1 => 'delete', $destination, '--files-from0', 5 => '--dry-run'])
        ->and($delete['read'])->toBe("app/data/app.db-wal\0app/data/app.db-shm\0app/data/app.db-journal\0");

    // The snapshots live in a private temporary directory that is removed afterwards.
    expect(dirname($copy['command'][2], 4))->not->toBeDirectory();

    $db->close();
})->with([
    'path on a remote' => ['remote:backup', 'remote:backup/app/data/app.db'],
    'remote root' => ['remote:', 'remote:app/data/app.db'],
    'trailing slash' => ['remote:backup/', 'remote:backup/app/data/app.db'],
]);

it('runs without --dry-run when applied and passes extra flags to rclone', function () {
    $calls = fakeRclone();

    $this->artisan('rclone:sync-path', [
        'source' => SYNC_SOURCE,
        'destination' => 'remote:backup',
        'flags' => ['--bwlimit', '8M'],
        '--apply' => true,
    ])->assertExitCode(0);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['command'])->toBe([
            'rclone', 'sync', realpath(SYNC_SOURCE), 'remote:backup', '--links', '--exclude-from', $calls[0]['command'][6], '--bwlimit', '8M',
        ])
        ->and($calls[0]['read'])->toBe('');
});

it('escapes rclone glob characters in database paths', function () {
    $db = liveDatabase(SYNC_SOURCE.'/app/data/we[ir]d{1}*?\\.db');
    $calls = fakeRclone();

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE, 'destination' => 'remote:backup'])->assertExitCode(0);

    expect(explode("\n", $calls[0]['read'])[0])->toBe('/app/data/we\\[ir\\]d\\{1\\}\\*\\?\\\\.db');

    $db->close();
});

it('skips symlinks to databases', function () {
    $db = liveDatabase(SYNC_SOURCE.'/app/data/app.db');
    symlink(SYNC_SOURCE.'/app/data/app.db', SYNC_SOURCE.'/app/link.db');
    $calls = fakeRclone();

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE, 'destination' => 'remote:backup'])->assertExitCode(0);

    expect($calls[0]['read'])->not->toContain('link.db');

    $db->close();
});

it('stops when rclone fails', function () {
    $db = liveDatabase(SYNC_SOURCE.'/app/data/app.db');
    $calls = fakeRclone(exitCode: 1);

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE, 'destination' => 'remote:backup'])
        ->expectsOutputToContain('rclone sync failed with exit code 1.')
        ->assertExitCode(1);

    expect($calls)->toHaveCount(1);

    $db->close();
});

it('fails when the source directory does not exist', function () {
    Process::fake();

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE.'/missing', 'destination' => 'remote:backup'])
        ->expectsOutputToContain('Source directory not found')
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('refuses to run while another sync to the same destination is running', function () {
    Process::fake();
    $lock = fopen('/run/lock/hcpc-rclone-'.sha1('remote:backup').'.lock', 'c');
    flock($lock, LOCK_EX);

    $this->artisan('rclone:sync-path', ['source' => SYNC_SOURCE, 'destination' => 'remote:backup'])
        ->expectsOutputToContain('already running')
        ->assertExitCode(1);

    Process::assertNothingRan();
    fclose($lock);
});
