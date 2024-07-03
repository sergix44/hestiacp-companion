<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use SleekDB\Store;

class RemoveCertificateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'certificate:remove';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Remove a certificate from the hcpc subsystem';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $store = app(Store::class, ['certificates']);

        $managedCertificates = $store->findAll();

        $choices = [];
        foreach ($managedCertificates as $certificate) {
            $choices[$certificate['_id']] = "{$certificate['user']} - {$certificate['domain']}";
        }

        if (empty($choices)) {
            $this->info('No certificates found in the system.');
            return 0;
        }

        $choosen = $this->choice('Select a certificate to remove', $choices);

        $id = array_flip($choices)[$choosen];

        $confirm = $this->choice('Are you sure you want to remove this certificate?', ['no', 'yes']);

        if ($confirm === 'no') {
            return 0;
        }

        $certificate = $store->findById($id);
        $removeFromHestia = $this->choice('Do you want to remove the certificate from HestiaCP?', ['no', 'yes']);

        if ($removeFromHestia === 'yes') {
            $this->task('Removing certificate from HestiaCP', function () use ($certificate) {
                shell_exec("v-delete-web-domain-ssl {$certificate['user']} {$certificate['domain']}");
                return true;
            });
        }

        $store->deleteById($id);
        return 0;
    }
}
