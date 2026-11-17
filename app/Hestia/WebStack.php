<?php

namespace App\Hestia;

use RuntimeException;

enum WebStack: string
{
    case NGINX_APACHE = 'Nginx + Apache';
    case NGINX = 'Nginx standalone';

    public static function fromConf(string $path): self
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read $path. Is HestiaCP installed?");
        }

        $web = self::setting($path, 'WEB_SYSTEM');
        $proxy = self::setting($path, 'PROXY_SYSTEM');

        return match (true) {
            $web === 'apache2' && $proxy === 'nginx' => self::NGINX_APACHE,
            $web === 'nginx' => self::NGINX,
            default => throw new RuntimeException("Unsupported Hestia web configuration: WEB_SYSTEM='$web', PROXY_SYSTEM='$proxy'."),
        };
    }

    // hestia.conf is a shell file: read it as data instead of sourcing it.
    private static function setting(string $path, string $key): string
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "$key=")) {
                return str_replace(["'", '"'], '', substr($line, strlen($key) + 1));
            }
        }

        return '';
    }

    public function templateDir(string $nginxTemplates): string
    {
        return match ($this) {
            self::NGINX_APACHE => $nginxTemplates,
            self::NGINX => "$nginxTemplates/php-fpm",
        };
    }

    public function httpListen(): string
    {
        return match ($this) {
            self::NGINX_APACHE => '%proxy_port%',
            self::NGINX => '%web_port%',
        };
    }

    public function httpsListen(): string
    {
        return match ($this) {
            self::NGINX_APACHE => '%proxy_ssl_port%',
            self::NGINX => '%web_ssl_port%',
        };
    }

    public function panelField(): string
    {
        return match ($this) {
            self::NGINX_APACHE => 'Proxy Template',
            self::NGINX => 'Web Template',
        };
    }
}
