<?php

namespace App\Commands;

use App\Certificates\SolverFactory;
use App\Tasks\IssueWildcardCertificate;
use LaravelZero\Framework\Commands\Command;
use SleekDB\Store;

class AddWildcardCertificateCommand extends Command
{
    use IssueWildcardCertificate;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'certificate:add-wildcard';

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

        $wildcardDomain = $this->ask('Enter the wildcard domain name (e.g. *.example.com)', "*.{$domain}");
        $solverName = $this->choice('Select a DNS provider', array_keys(SolverFactory::solvers()));

        $solverConfig = [];
        foreach (SolverFactory::solverParameters($solverName) as $key => $label) {
            $solverConfig[$key] = $this->ask($label);
        }

        $solver = app(SolverFactory::class)->getSolver($solverName, $solverConfig);

        $this->task('Ensure wildcard alias is configured', function () use ($user, $domain, $wildcardDomain) {
            $domainInfo = json_decode(shell_exec("v-list-web-domain $user $domain json"));

            if (!str_contains($domainInfo->$domain->ALIAS, $wildcardDomain)) {
                shell_exec("v-add-web-domain-alias $user $domain $wildcardDomain");
            }
            return true;
        });

        $this->task('Ensure lets encrypt user exists', function () use ($user) {
            shell_exec("v-add-letsencrypt-user $user");
            return true;
        });

        $this->issueWildcardCertificate($store, $user, $domain, $solver, $solverConfig);

        return 0;
    }
}
