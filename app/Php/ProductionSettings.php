<?php

namespace App\Php;

final class ProductionSettings
{
    public const FILE = '99-prod-optim-php.ini';

    public const DEFAULTS = [
        'memory_limit' => '512M',
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '120',
        'opcache.memory_consumption' => '512',
        'opcache.interned_strings_buffer' => '32',
        'opcache.max_accelerated_files' => '100000',
        'opcache.validate_timestamps' => '1',
        'opcache.revalidate_freq' => '2',
        'opcache.jit' => 'tracing',
        'opcache.jit_buffer_size' => '64M',
    ];

    private const RULES = [
        'memory_limit' => ['/^(-1|\d+[KMG]?)$/i', 'a size like 512M, or -1'],
        'realpath_cache_size' => ['/^\d+[KMG]?$/i', 'a size like 4096K'],
        'realpath_cache_ttl' => ['/^\d+$/', 'a whole number of seconds'],
        'opcache.memory_consumption' => ['/^\d+$/', 'a whole number of megabytes'],
        'opcache.interned_strings_buffer' => ['/^\d+$/', 'a whole number of megabytes'],
        'opcache.max_accelerated_files' => ['/^\d+$/', 'a whole number'],
        'opcache.validate_timestamps' => ['/^[01]$/', '0 or 1'],
        'opcache.revalidate_freq' => ['/^\d+$/', 'a whole number of seconds'],
        'opcache.jit' => ['/^(disable|off|on|tracing|function|\d{4})$/i', 'disable, off, on, tracing, function or a 4-digit number'],
        'opcache.jit_buffer_size' => ['/^\d+[KMG]?$/i', 'a size like 64M'],
    ];

    public static function optionName(string $key): string
    {
        return str_replace(['.', '_'], '-', $key);
    }

    public static function validate(string $key, string $value): ?string
    {
        [$pattern, $expected] = self::RULES[$key];

        return preg_match($pattern, trim($value)) ? null : "$key must be $expected.";
    }

    /**
     * @return array<string, string>
     */
    public static function fromIni(string $path): array
    {
        // A broken file counts as absent: the defaults apply and the next run rewrites it.
        $ini = is_file($path) ? @parse_ini_file($path, false, INI_SCANNER_RAW) : false;

        return $ini === false ? [] : array_map('strval', array_intersect_key($ini, self::DEFAULTS));
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function render(array $values, bool $cli): string
    {
        return view('php.production', ['values' => $values, 'cli' => $cli])->render();
    }
}
