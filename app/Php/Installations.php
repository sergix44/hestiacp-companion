<?php

namespace App\Php;

final class Installations
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<string> versions like 8.4, lowest first
     */
    public function versions(): array
    {
        $versions = array_filter(
            array_map('basename', glob("$this->root/*", GLOB_ONLYDIR) ?: []),
            fn (string $name) => preg_match('/^\d+\.\d+$/', $name) === 1,
        );
        usort($versions, 'version_compare');

        return $versions;
    }

    public function confDir(string $version, string $sapi): ?string
    {
        $dir = "$this->root/$version/$sapi/conf.d";

        return is_dir($dir) ? $dir : null;
    }
}
