<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ReverseProxyCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'reverse-proxy';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Install templates to create reverse proxy sites';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        foreach (
            [
                'http_reverse_proxy' => 'http',
                'https_reverse_proxy' => 'https',
                'unix_reverse_proxy' => 'unix'
            ] as $name => $protocol
        ) {
            foreach (['tpl', 'stpl'] as $type) {
                $success = $this->makeTemplate($name, $type, $protocol);

                if (!$success) {
                    $this->error("Reverse proxy $name.$type could not be created.");
                    return 1;
                }
            }
        }

        $this->info("Reverse proxy templates created.");

        $this->comment(
            "To create a reverse proxy site, select the 'reverse_proxy' template in the Hestia control panel," .
            " then in proxy extensions textarea, delete everything and put the hostname, like '127.0.0.1:9000' " .
            "or 'localhost:8080'"
        );

        return 0;
    }

    private function makeTemplate(string $name, string $type, string $protocol): bool
    {
        $data = view('nginx.reverse-proxy-' . $type, [
            'proxyProtocol' => $protocol
        ]);
        $path = config('hestia.templates.nginx-proxy') . "/$name.$type";
        return file_put_contents($path, $data) !== false;
    }
}
