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
        $data = view('nginx.reverse-proxy-tpl');
        $path = config('hestia.templates.nginx-proxy') . '/reverse_proxy.tpl';
        file_put_contents($path, $data);

        if (!file_exists($path)) {
            $this->error("Reverse proxy .tpl could not be created.");
            return 1;
        }

        $data = view('nginx.reverse-proxy-stpl');
        $path = config('hestia.templates.nginx-proxy') . '/reverse_proxy.stpl';
        file_put_contents($path, $data);

        if (!file_exists($path)) {
            $this->error("Reverse proxy .stpl could not be created.");
            return 1;
        }

        $this->info("Reverse proxy templates created.");

        $this->comment(
            "To create a reverse proxy site, select the 'reverse_proxy' template in the Hestia control panel," .
            " then in proxy extensions textarea, delete everything and put the hostname, like '127.0.0.1:9000' " .
            "or 'localhost:8080'"
        );

        return 0;
    }
}
