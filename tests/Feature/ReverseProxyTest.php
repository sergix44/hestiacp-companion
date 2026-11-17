<?php

use Illuminate\Filesystem\Filesystem;

const PROXY_HESTIA = __DIR__.'/../Tmp/hestia';
const PROXY_TEMPLATES = PROXY_HESTIA.'/data/templates/web/nginx';

function writeHestiaConf(string $web, string $proxy = ''): void
{
    file_put_contents(PROXY_HESTIA.'/conf/hestia.conf', "WEB_SYSTEM='$web'\nPROXY_SYSTEM='$proxy'\n");
}

beforeEach(function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists(PROXY_HESTIA.'/conf');
    foreach ([PROXY_TEMPLATES, PROXY_TEMPLATES.'/php-fpm'] as $dir) {
        $files->ensureDirectoryExists($dir);
        touch("$dir/default.tpl");
        touch("$dir/default.stpl");
    }

    config([
        'hestia.conf' => PROXY_HESTIA.'/conf/hestia.conf',
        'hestia.templates.nginx' => PROXY_TEMPLATES,
    ]);
});

afterEach(fn () => (new Filesystem)->deleteDirectory(PROXY_HESTIA));

it('creates web templates when Nginx runs standalone', function () {
    writeHestiaConf('nginx');

    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '3552'])
        ->expectsOutputToContain('Web Template')
        ->assertExitCode(0);

    $http = file_get_contents(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.tpl');
    expect($http)
        ->toContain("# Backend: http://127.0.0.1:3552\nserver {")
        ->toContain('listen %ip%:%web_port%;')
        ->toContain('proxy_pass http://127.0.0.1:3552;');

    $https = file_get_contents(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.stpl');
    expect($https)
        ->toContain('listen %ip%:%web_ssl_port% ssl;')
        ->toContain('proxy_pass http://127.0.0.1:3552;');

    expect(fileperms(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.tpl') & 0777)->toBe(0644);
});

it('creates proxy templates when Nginx runs in front of Apache', function () {
    writeHestiaConf('apache2', 'nginx');

    $this->artisan('reverse-proxy', ['service' => 'sonarr', 'port' => '8989'])
        ->expectsOutputToContain('Proxy Template')
        ->assertExitCode(0);

    expect(file_get_contents(PROXY_TEMPLATES.'/proxy-sonarr.tpl'))->toContain('listen %ip%:%proxy_port%;');
    expect(file_get_contents(PROXY_TEMPLATES.'/proxy-sonarr.stpl'))->toContain('listen %ip%:%proxy_ssl_port% ssl;');
    expect(PROXY_TEMPLATES.'/php-fpm/proxy-sonarr.tpl')->not->toBeFile();
});

it('refuses to replace existing templates without --force', function () {
    writeHestiaConf('nginx');
    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '3552'])->assertExitCode(0);

    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '4000'])
        ->expectsOutputToContain('already exists')
        ->assertExitCode(1);

    expect(file_get_contents(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.tpl'))->toContain('127.0.0.1:3552;');
});

it('backs up existing templates when replacing them with --force', function () {
    writeHestiaConf('nginx');
    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '3552'])->assertExitCode(0);

    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '4000', '--force' => true])->assertExitCode(0);

    expect(file_get_contents(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.tpl'))->toContain('127.0.0.1:4000;');

    $backups = glob(PROXY_TEMPLATES.'/php-fpm/proxy-arcane.*.bak-*');
    expect($backups)->toHaveCount(2);
    foreach ($backups as $backup) {
        expect(file_get_contents($backup))->toContain('127.0.0.1:3552;');
    }
});

it('rejects invalid arguments', function (string $service, string $port) {
    writeHestiaConf('nginx');

    $this->artisan('reverse-proxy', ['service' => $service, 'port' => $port])->assertExitCode(1);

    expect(glob(PROXY_TEMPLATES.'/php-fpm/proxy-*'))->toBeEmpty();
})->with([
    'uppercase name' => ['Arcane', '3552'],
    'name starting with a digit' => ['1arcane', '3552'],
    'name with a slash' => ['../arcane', '3552'],
    'port zero' => ['arcane', '0'],
    'port too high' => ['arcane', '65536'],
    'port not a number' => ['arcane', '35a'],
]);

it('fails on unsupported web stacks', function () {
    writeHestiaConf('apache2');

    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '3552'])
        ->expectsOutputToContain('Unsupported Hestia web configuration')
        ->assertExitCode(1);
});

it('fails when HestiaCP is not installed', function () {
    $this->artisan('reverse-proxy', ['service' => 'arcane', 'port' => '3552'])
        ->expectsOutputToContain('Is HestiaCP installed?')
        ->assertExitCode(1);
});
