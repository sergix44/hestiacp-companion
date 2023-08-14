<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class PhpTemplateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'php-template {name=custom} {--phpVersion=} {--maxChildren=10} {--processIdleTimeout=5} {--maxRequests=10000}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Makes a custom PHP-FPM template';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->argument('name');
        $phpVersion = $this->option('phpVersion') ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $maxChildren = $this->option('maxChildren');
        $processIdleTimeout = $this->option('processIdleTimeout');
        $maxRequests = $this->option('maxRequests');

        if (!$this->option('no-interaction')) {
            $name = $this->ask('FPM template name:', $name);
            $phpVersion = $this->ask('PHP version:', $phpVersion);
            $maxChildren = $this->ask('Max children:', $maxChildren);
            $processIdleTimeout = $this->ask('Process idle timeout:', $processIdleTimeout);
            $maxRequests = $this->ask('Max requests per child:', $maxRequests);
        }

        $success = $this->makeTemplate($name, $phpVersion, $maxChildren, $processIdleTimeout, $maxRequests);

        if (!$success) {
            $this->error("PHP-FPM template $name could not be created.");
            return 1;
        }

        $this->info("PHP-FPM template $name created.");
        return 0;
    }

    private function makeTemplate($name, $phpVersion, $maxChildren, $processIdleTimeout, $maxRequests)
    {
        $data = view('php-fpm', [
            'ondemandMaxChildren' => $maxChildren,
            'ondemandProcessIdleTimeout' => $processIdleTimeout,
            'ondemandMaxRequests' => $maxRequests,
        ]);

        $phpSuffix = str_replace('.', '_', $phpVersion);
        $file = sprintf("%s/%s-PHP-%s.tpl", config('hestia.templates.php-fpm'), $name, $phpSuffix);
        file_put_contents($file, $data);

        return file_exists($file);
    }
}
