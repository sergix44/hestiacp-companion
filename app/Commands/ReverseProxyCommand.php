<?php

namespace App\Commands;

use App\Hestia\WebStack;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ReverseProxyCommand extends Command
{
    protected $signature = 'reverse-proxy
        {service : Service name, the templates are named proxy-<service>}
        {port : Local port of the service, proxied as http://127.0.0.1:<port>}
        {--force : Replace existing templates, keeping a .bak copy of each}';

    protected $description = 'Create Nginx reverse proxy templates for a local HTTP service';

    public function handle(): int
    {
        $service = $this->argument('service');
        $port = $this->argument('port');

        if (! preg_match('/^[a-z][a-z0-9_-]{0,47}$/', $service)) {
            return $this->failWith('Service name must contain lowercase letters, digits, - or _, starting with a letter (max 48 characters).');
        }

        if (! preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            return $this->failWith('Port must be a number between 1 and 65535.');
        }
        $port = (int) $port;

        try {
            $stack = WebStack::fromConf(config('hestia.conf'));
        } catch (RuntimeException $e) {
            return $this->failWith($e->getMessage());
        }

        $dir = $stack->templateDir(config('hestia.templates.nginx'));
        if (! is_file("$dir/default.tpl") || ! is_file("$dir/default.stpl")) {
            return $this->failWith("Expected Hestia Nginx templates not found in $dir");
        }
        if (! is_writable($dir)) {
            return $this->failWith("Cannot write to $dir. Run as root (with sudo).");
        }

        $template = "proxy-$service";
        $templates = [
            "$dir/$template.tpl" => view('nginx.reverse-proxy-tpl', ['port' => $port, 'listen' => $stack->httpListen()])->render(),
            "$dir/$template.stpl" => view('nginx.reverse-proxy-stpl', ['port' => $port, 'listen' => $stack->httpsListen()])->render(),
        ];

        $existing = array_filter(array_keys($templates), 'file_exists');
        if ($existing && ! $this->option('force')) {
            return $this->failWith("Template '$template' already exists. Use --force to back up and replace it.");
        }

        $stamp = date_create()->format('Ymd-His-u');
        foreach ($existing as $path) {
            if (! copy($path, "$path.bak-$stamp")) {
                return $this->failWith("Could not back up $path");
            }
            $this->components->twoColumnDetail('Backup', "$path.bak-$stamp");
        }

        foreach ($templates as $path => $contents) {
            if (file_put_contents($path, $contents) === false || ! chmod($path, 0644)) {
                return $this->failWith("Could not write $path");
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Template', $template);
        $this->components->twoColumnDetail('Mode', $stack->value);
        $this->components->twoColumnDetail('Backend', "http://127.0.0.1:$port");
        $this->components->twoColumnDetail('HTTP template', "$dir/$template.tpl");
        $this->components->twoColumnDetail('HTTPS template', "$dir/$template.stpl");
        $this->components->twoColumnDetail('Hestia field', $stack->panelField());

        $this->components->info("Next: create the web domain in HestiaCP, enable SSL / Let's Encrypt, then select \"$template\" in the \"{$stack->panelField()}\" dropdown. After applying, check the config with: sudo nginx -t");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
