<?php

namespace App\Commands;

use AcmePhp\Core\Challenge\Dns\GandiSolver;
use AcmePhp\Core\Challenge\Dns\Route53Solver;
use App\Certificates\Challenge\CloudflareSolver;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use SleekDB\Store;

class AddWildcardDomain extends Command
{

    private const SOLVERS = [
        'cloudflare' => CloudflareSolver::class,
        //'aws route53' => Route53Solver::class,
        //'gandi' => GandiSolver::class,
    ];


    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'add-wildcard-domain';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Save a new wildcard domain to the system';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $store = app(Store::class, ['certificates']);

        $users = json_decode(shell_exec('v-list-users json'), true);
        $user = $this->choice('Select a user', array_keys($users));

        $domains = json_decode(shell_exec("v-list-web-domains $user json"), true);
        $domain = $this->choice('Select a domain', array_keys($domains));

        $wildcardDomain = $this->ask('Enter the wildcard domain name (e.g. *.example.com):', "*.{$domain}");
        $solverName = $this->choice('Select a DNS provider', array_keys(self::SOLVERS));

        /** @var \AcmePhp\Core\Challenge\Dns\SOL $solver */
        $solver = $this->makeSolver($solverName);




        //$domainInfo = json_decode(shell_exec("v-list-web-domain $user $domain json"));






    }
}
