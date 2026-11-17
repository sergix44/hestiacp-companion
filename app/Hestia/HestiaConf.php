<?php

namespace App\Hestia;

/**
 * hestia.conf is a shell file: it is read as data, never sourced.
 */
final class HestiaConf
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_readable($this->path);
    }

    public function get(string $key, string $default = ''): string
    {
        foreach ($this->exists() ? file($this->path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (str_starts_with($line, "$key=")) {
                $value = str_replace(["'", '"'], '', substr($line, strlen($key) + 1));

                return $value === '' ? $default : $value;
            }
        }

        return $default;
    }
}
