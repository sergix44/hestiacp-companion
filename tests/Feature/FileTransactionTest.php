<?php

use App\Support\FileTransaction;
use Illuminate\Filesystem\Filesystem;

const TRANSACTION_DIR = __DIR__.'/../Tmp/transaction';

beforeEach(fn () => (new Filesystem)->ensureDirectoryExists(TRANSACTION_DIR));

afterEach(fn () => (new Filesystem)->deleteDirectory(TRANSACTION_DIR));

it('backs up a file before replacing it', function () {
    file_put_contents(TRANSACTION_DIR.'/a.ini', 'old');
    $transaction = new FileTransaction('20261117-120000');

    $transaction->put(TRANSACTION_DIR.'/a.ini', 'new');

    expect(file_get_contents(TRANSACTION_DIR.'/a.ini'))->toBe('new')
        ->and(file_get_contents(TRANSACTION_DIR.'/a.ini.bak-20261117-120000'))->toBe('old')
        ->and($transaction->backups())->toBe([TRANSACTION_DIR.'/a.ini.bak-20261117-120000'])
        ->and(fileperms(TRANSACTION_DIR.'/a.ini') & 0777)->toBe(0644);
});

it('creates new files without a backup', function () {
    $transaction = new FileTransaction('20261117-120000');

    $transaction->put(TRANSACTION_DIR.'/a.ini', 'new');

    expect(file_get_contents(TRANSACTION_DIR.'/a.ini'))->toBe('new')
        ->and($transaction->backups())->toBe([]);
});

it('removes files by moving them to a backup', function () {
    file_put_contents(TRANSACTION_DIR.'/a.ini', 'old');
    $transaction = new FileTransaction('20261117-120000');

    $transaction->delete(TRANSACTION_DIR.'/a.ini');

    expect(TRANSACTION_DIR.'/a.ini')->not->toBeFile()
        ->and(file_get_contents(TRANSACTION_DIR.'/a.ini.bak-20261117-120000'))->toBe('old');
});

it('rolls everything back', function () {
    file_put_contents(TRANSACTION_DIR.'/replaced.ini', 'old');
    file_put_contents(TRANSACTION_DIR.'/deleted.ini', 'kept');
    $transaction = new FileTransaction('20261117-120000');
    $transaction->put(TRANSACTION_DIR.'/replaced.ini', 'new');
    $transaction->put(TRANSACTION_DIR.'/created.ini', 'new');
    $transaction->delete(TRANSACTION_DIR.'/deleted.ini');

    $transaction->rollback();

    expect(file_get_contents(TRANSACTION_DIR.'/replaced.ini'))->toBe('old')
        ->and(TRANSACTION_DIR.'/created.ini')->not->toBeFile()
        ->and(file_get_contents(TRANSACTION_DIR.'/deleted.ini'))->toBe('kept')
        ->and(glob(TRANSACTION_DIR.'/*.bak-*'))->toBe([]);
});

it('fails with the path when a file cannot be written', function () {
    $transaction = new FileTransaction('20261117-120000');

    expect(fn () => $transaction->put(TRANSACTION_DIR.'/missing/a.ini', 'new'))
        ->toThrow(RuntimeException::class, TRANSACTION_DIR.'/missing/a.ini');
});
