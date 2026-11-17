<?php

use App\Hestia\HestiaConf;

const HESTIA_CONF_FILE = __DIR__.'/../Tmp/hestia-conf-test.conf';

afterEach(fn () => @unlink(HESTIA_CONF_FILE));

it('reads values without quotes', function () {
    file_put_contents(HESTIA_CONF_FILE, "BACKUP='/srv/backup'\nWEB_SYSTEM=\"nginx\"\n");
    $conf = new HestiaConf(HESTIA_CONF_FILE);

    expect($conf->get('BACKUP'))->toBe('/srv/backup')
        ->and($conf->get('WEB_SYSTEM'))->toBe('nginx');
});

it('falls back to the default for missing or empty keys', function () {
    file_put_contents(HESTIA_CONF_FILE, "BACKUP=''\n");
    $conf = new HestiaConf(HESTIA_CONF_FILE);

    expect($conf->get('BACKUP', '/backup'))->toBe('/backup')
        ->and($conf->get('MISSING', 'x'))->toBe('x')
        ->and($conf->get('MISSING'))->toBe('');
});

it('knows when the file is missing', function () {
    expect((new HestiaConf(HESTIA_CONF_FILE))->exists())->toBeFalse();

    file_put_contents(HESTIA_CONF_FILE, '');
    expect((new HestiaConf(HESTIA_CONF_FILE))->exists())->toBeTrue();
});
