<?php

namespace App\Commands;

use App\Certificates\SolverFactory;
use App\Tasks\IssueWildcardCertificate;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use SleekDB\Store;

class RenewCertificatesCommand extends Command
{
    use IssueWildcardCertificate;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'certificate:renew';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Renew certificates that are about to expire';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $store = app(Store::class, ['certificates']);

        $next15Days = (new \DateTime('now +15 days'))->getTimestamp();

        $toRenew = $store->findBy([
            ['expires_at', '<=', $next15Days]
        ]);

        foreach ($toRenew as $certificate) {
            $this->info("Renewing certificate for {$certificate['domain']}");

            $solver = app(SolverFactory::class)->getSolver(
                $certificate['solver'],
                $certificate['solver_config']
            );

            $this->issueWildcardCertificate(
                $store,
                $certificate['user'],
                $certificate['domain'],
                $solver,
                $certificate['solver_config']
            );
        }

        return 0;
    }

    /**
     * Define the command's schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class)->dailyAt('01:00');
    }
}
