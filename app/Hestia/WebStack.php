<?php

namespace App\Hestia;

use RuntimeException;

enum WebStack: string
{
    case NGINX_APACHE = 'Nginx + Apache';
    case NGINX = 'Nginx standalone';

    public static function fromConf(string $path): self
    {
        $conf = new HestiaConf($path);
        if (! $conf->exists()) {
            throw new RuntimeException("Cannot read $path. Is HestiaCP installed?");
        }

        $web = $conf->get('WEB_SYSTEM');
        $proxy = $conf->get('PROXY_SYSTEM');

        return match (true) {
            $web === 'apache2' && $proxy === 'nginx' => self::NGINX_APACHE,
            $web === 'nginx' => self::NGINX,
            default => throw new RuntimeException("Unsupported Hestia web configuration: WEB_SYSTEM='$web', PROXY_SYSTEM='$proxy'."),
        };
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
