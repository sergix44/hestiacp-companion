<?php

namespace App\Tasks;

use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Challenge\Dns\DnsValidator;
use AcmePhp\Core\Challenge\SolverInterface;
use AcmePhp\Core\Challenge\WaitingValidator;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\DistinguishedName;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use App\Certificates\Resolver\DohDnsResolver;
use App\Certificates\SolverFactory;
use SleekDB\Store;

trait IssueWildcardCertificate
{

    protected function issueWildcardCertificate(
        Store $store,
        string $user,
        string $domain,
        SolverInterface $solver,
        array $solverConfig
    ): void {
        $domainInfo = json_decode(shell_exec("v-list-web-domain $user $domain json"));
        $domains = [$domain];
        if (!empty($domainInfo->$domain->ALIAS)) {
            $domains = array_merge($domains, explode(',', $domainInfo->$domain->ALIAS));
        }

        $domains = $this->removeOverlappingDomains($domains);

        $privateKey = file_get_contents(config('hestia.users_path') . "/$user/ssl/user.key");
        $acmeClient = app(AcmeClient::class, [$privateKey]);

        if (str_contains(config('acme.api_url'), 'staging')) {
            $acmeClient->registerAccount("info@$domain");
        }

        $order = null;
        $this->task('Requesting order', function () use ($domains, $acmeClient, &$order) {
            $order = $acmeClient->requestOrder($domains);
            return true;
        });

        $solvedChallenges = [];
        $this->task('Solving challenges', function () use ($solver, $order, &$solvedChallenges) {
            foreach ($order->getAuthorizationsChallenges() as $d => $challenges) {
                foreach ($challenges as $challenge) {
                    if ($challenge->isValid()) {
                        continue;
                    }
                    if ($challenge->isPending() && $solver->supports($challenge)) {
                        $solver->solve($challenge);
                        $solvedChallenges[] = $challenge;
                    }
                }
            }
            return !empty($solvedChallenges);
        });

        $this->task('Validating solved challenges', function () use ($solvedChallenges, $solver) {
            if (empty($solvedChallenges)) {
                return false;
            }

            $validator = new WaitingValidator(new DnsValidator(dnsResolver: new DohDnsResolver()), 60);

            foreach ($solvedChallenges as $solvedChallenge) {
                if (!$validator->isValid($solvedChallenge, $solver)) {
                    $this->warn('Unable to locally validate challenge, will see with LE servers...');
                }
            }

            return true;
        });

        $this->task('Challenge authorization', function () use ($acmeClient, $solvedChallenges) {
            if (empty($solvedChallenges)) {
                return false;
            }

            foreach ($solvedChallenges as $solvedChallenge) {
                $acmeClient->challengeAuthorization($solvedChallenge);
            }

            return true;
        });

        $certificate = null;
        $this->task('Requesting certificate', function () use ($user, $acmeClient, $domains, $order, &$certificate) {
            if ($order === null) {
                return false;
            }
            $leUserInfo = json_decode(shell_exec("v-list-letsencrypt-user $user json"), true);
            $mail = $leUserInfo[$user]['EMAIL'];

            $keyPair = (new KeyPairGenerator())->generateKeyPair();
            $dname = new DistinguishedName(
                array_shift($domains),
                'US',
                'California',
                'San Francisco',
                'HCPC',
                'IT',
                $mail,
                $domains
            );

            $csr = new CertificateRequest($dname, $keyPair);

            $certificate = $acmeClient->finalizeOrder($order, $csr);
            return true;
        });


        $tempCertsDir = null;
        $this->task('Import certificate to HestiaCP', function () use ($user, $certificate, $domain, &$tempCertsDir) {
            if ($certificate === null) {
                return false;
            }
            $tempCertsDir = sys_get_temp_dir() . '/hcpc_certs';
            mkdir($tempCertsDir);

            file_put_contents("$tempCertsDir/$domain.crt", $certificate->getCertificate()->getPEM());
            file_put_contents("$tempCertsDir/$domain.key",
                $certificate->getCertificateRequest()->getKeyPair()->getPrivateKey()->getPEM()
            );
            file_put_contents("$tempCertsDir/$domain.ca",
                $certificate->getCertificate()->getIssuerCertificate()->getPEM()
            );

            $sslInfo = json_decode(shell_exec("v-list-web-domain-ssl $user $domain json"));

            if (empty($sslInfo->$domain->CRT)) {
                shell_exec("v-add-web-domain-ssl $user $domain $tempCertsDir public_html");
                shell_exec("v-add-web-domain-ssl-force $user $domain");
            } else {
                shell_exec("v-change-web-domain-sslcert $user $domain $tempCertsDir");
            }

            return true;
        });

        $this->task('Store certificate in database',
            function () use ($solverConfig, $store, $user, $domain, $certificate, $solver) {
                if ($certificate === null) {
                    return false;
                }

                $cert = openssl_x509_read($certificate->getCertificate()->getPEM());

                if ($cert === false) {
                    return false;
                }

                $validToTimestamp = openssl_x509_parse($cert)['validTo_time_t'];

                $storedRecord = $store->findOneBy([
                    ['user', '=', $user],
                    ['domain', '=', $domain],
                ]);

                if ($storedRecord !== null) {
                    $store->updateById($storedRecord['_id'], [
                        'user' => $user,
                        'domain' => $domain,
                        'expires_at' => $validToTimestamp,
                        'solver' => SolverFactory::getNameByClass($solver::class),
                        'solver_config' => $solverConfig,
                    ]);
                } else {
                    $store->insert([
                        'user' => $user,
                        'domain' => $domain,
                        'expires_at' => $validToTimestamp,
                        'solver' => SolverFactory::getNameByClass($solver::class),
                        'solver_config' => $solverConfig,
                    ]);
                }

                return true;
            });

        $this->task('Cleanup', function () use ($tempCertsDir, $solver, $solvedChallenges) {
            if ($tempCertsDir === null) {
                return false;
            }

            // clear temp files
            array_map('unlink', glob("$tempCertsDir/*"));
            rmdir($tempCertsDir);

            // clear challenges
            foreach ($solvedChallenges as $solvedChallenge) {
                try {
                    $solver->cleanup($solvedChallenge);
                } catch (\Throwable $e) {
                    $this->warn("Error while cleaning up: {$e->getMessage()}");
                }
            }

            return true;
        });
    }

    /**
     * @param string[] $domains
     * @return array
     */
    private function removeOverlappingDomains(array $domains): array
    {
        $wildcardDomains = array_filter($domains, fn($domain) => str_contains($domain, '*.'));

        $domains = array_filter($domains, function ($domain) use ($wildcardDomains) {
            foreach ($wildcardDomains as $wildcardDomain) {
                if (
                    $domain !== $wildcardDomain &&
                    preg_match("/^" . str_replace('*', '.*', $wildcardDomain) . "$/", $domain)
                ) {
                    return false;
                }
            }
            return true;
        });

        return array_unique($domains);
    }

}
