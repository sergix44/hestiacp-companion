<?php

use App\Php\ProductionSettings;

it('renders the FPM file with the defaults', function () {
    expect(ProductionSettings::render(ProductionSettings::DEFAULTS, cli: false))->toBe(<<<'INI'
; Managed by hcpc php:optimize. Changes here are overwritten on the next run.

; Runtime
memory_limit = 512M
realpath_cache_size = 4096K
realpath_cache_ttl = 120

; Security and logging
display_errors = Off
log_errors = On
expose_php = Off

; OPcache
opcache.enable = 1
opcache.memory_consumption = 512
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 100000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
opcache.save_comments = 1

; JIT
opcache.jit = tracing
opcache.jit_buffer_size = 64M

INI);
});

it('renders the CLI file with unlimited memory and OPcache enabled', function () {
    $cli = ProductionSettings::render(ProductionSettings::DEFAULTS, cli: true);

    expect($cli)->toContain("memory_limit = -1\n")
        ->toContain("opcache.enable = 1\nopcache.enable_cli = 1\n")
        ->not->toContain('512M');
});

it('renders the disabled functions override', function () {
    expect(view('php.disabled-functions')->render())->toEndWith("\ndisable_functions =\n");
});

it('validates values', function (string $key, string $value, bool $valid) {
    expect(ProductionSettings::validate($key, $value) === null)->toBe($valid);
})->with([
    ['memory_limit', '512M', true],
    ['memory_limit', '512m', true],
    ['memory_limit', '-1', true],
    ['memory_limit', 'lots', false],
    ['realpath_cache_size', '4096K', true],
    ['realpath_cache_ttl', '120', true],
    ['realpath_cache_ttl', '2m', false],
    ['opcache.memory_consumption', '256', true],
    ['opcache.memory_consumption', '256M', false],
    ['opcache.validate_timestamps', '0', true],
    ['opcache.validate_timestamps', '2', false],
    ['opcache.jit', 'tracing', true],
    ['opcache.jit', 'off', true],
    ['opcache.jit', '1255', true],
    ['opcache.jit', 'fast', false],
    ['opcache.jit_buffer_size', '64M', true],
    ['opcache.jit_buffer_size', ' 64M ', true],
    ['opcache.jit_buffer_size', '', false],
]);

it('names the validated setting in the error', function () {
    expect(ProductionSettings::validate('opcache.jit', 'fast'))->toContain('opcache.jit');
});

it('reads known values from an existing file and ignores the rest', function () {
    $file = __DIR__.'/../Tmp/settings.ini';
    file_put_contents($file, "; edited by hand\nopcache.jit = off\nmemory_limit = 1G\nsomething.else = 1\n");

    expect(ProductionSettings::fromIni($file))->toBe(['opcache.jit' => 'off', 'memory_limit' => '1G']);

    file_put_contents($file, "opcache.jit = \"unterminated\n[[[");
    expect(ProductionSettings::fromIni($file))->toBe([]);

    unlink($file);
});

it('treats a missing file as no values', function () {
    expect(ProductionSettings::fromIni(__DIR__.'/../Tmp/missing.ini'))->toBe([]);
});
