<?php

use App\Hestia\RemoteAccount;

function accountReport(array $overrides = []): string
{
    return json_encode([
        'backup_dir' => '/backup',
        'backup_system' => 'local,rclone',
        'rsync' => true,
        'user' => ['alice' => ['U_DISK' => '120', 'BACKUPS' => '3']],
        'web' => [
            'a.test' => ['ALIAS' => 'www.a.test,old.a.test', 'BACKEND' => 'PHP-8_3'],
            'b.test' => ['ALIAS' => '', 'BACKEND' => 'default'],
        ],
        'dns' => ['a.test' => []],
        'mail' => ['a.test' => []],
        'databases' => ['alice_db' => []],
        'archives' => ['alice.2026-11-16_03-00-00.tar' => 50, 'alice.2026-11-10_03-00-00.tar' => 40],
        ...$overrides,
    ]);
}

it('reads the account report', function () {
    $account = RemoteAccount::fromJson(accountReport());

    expect($account->backupDir())->toBe('/backup')
        ->and($account->keepsLocalBackups())->toBeTrue()
        ->and($account->hasRsync())->toBeTrue()
        ->and($account->diskMb())->toBe(120)
        ->and($account->backupsLimit())->toBe(3)
        ->and($account->archives())->toBe(['alice.2026-11-10_03-00-00.tar' => 40, 'alice.2026-11-16_03-00-00.tar' => 50])
        ->and($account->newestArchive())->toBe('alice.2026-11-16_03-00-00.tar')
        ->and($account->oldestArchive())->toBe('alice.2026-11-10_03-00-00.tar')
        ->and($account->backends())->toBe(['a.test' => 'PHP-8_3', 'b.test' => 'default'])
        ->and($account->domainNames())->toBe(['a.test', 'www.a.test', 'old.a.test', 'b.test'])
        ->and($account->counts())->toBe(['web' => 2, 'dns' => 1, 'mail' => 1, 'databases' => 1]);
});

it('knows when the old server keeps no local backups', function () {
    expect(RemoteAccount::fromJson(accountReport(['backup_system' => 'rclone']))->keepsLocalBackups())->toBeFalse()
        ->and(RemoteAccount::fromJson(accountReport(['backup_system' => 'localhost-sftp']))->keepsLocalBackups())->toBeFalse();
});

it('reads an account without domains', function () {
    $account = RemoteAccount::fromJson(accountReport(['web' => [], 'dns' => [], 'mail' => [], 'databases' => [], 'archives' => []]));

    expect($account->counts())->toBe(['web' => 0, 'dns' => 0, 'mail' => 0, 'databases' => 0])
        ->and($account->domainNames())->toBe([])
        ->and($account->newestArchive())->toBeNull()
        ->and($account->oldestArchive())->toBeNull();
});

it('rejects an invalid report', function () {
    expect(fn () => RemoteAccount::fromJson('backing up...'))->toThrow(RuntimeException::class, 'Unexpected report from the old server');
});
