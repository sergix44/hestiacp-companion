<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class AddRenewalCronCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'hcp:add-cron';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Add a cron to HestiaCP to invoke the hpcp scheduler';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $path = \Phar::running();

        if (empty($path)) {
            $this->error('This command must be run from the phar file.');
            return 1;
        }

        $cd = getcwd();
        $command = "cd $cd; ". PHP_BINARY . " $path schedule:run >> /dev/null 2>&1";

        shell_exec('v-add-cron-job admin * * * * * "' . $command . '"');
        return 0;
    }
}
