<?php

namespace App\Php;

/**
 * The PHP that runs hcpc. Its ini settings cannot change at runtime, so tests replace this class.
 */
class Runtime
{
    /**
     * @return list<string>
     */
    public function disabledFunctions(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
    }

    public function version(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /**
     * @return list<string>
     */
    public function iniFiles(): array
    {
        $scanned = php_ini_scanned_files();

        return array_values(array_filter([
            php_ini_loaded_file() ?: null,
            ...($scanned ? array_map('trim', explode(',', $scanned)) : []),
        ]));
    }
}
