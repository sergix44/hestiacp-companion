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
use SleekDB\Store;

trait IssueWildcardCertificate
{

    public function issueWildcardCertificate(
        Store $store,
        string $user,
        string $domain,
        string $wildcardDomain,
        SolverInterface $solver,
        array $solverConfig
    ): void {
        $this->task('Ensure wildcard alias is configured', function () use ($user, $domain, $wildcardDomain) {
            $domainInfo = json_decode(shell_exec("v-list-web-domain $user $domain json"));

            if (!str_contains($domainInfo->$domain->ALIAS, $wildcardDomain)) {
                shell_exec("v-add-web-domain-alias $user $domain $wildcardDomain");
            }
            return true;
        });

        $this->task('Ensure ssl user exists', function () use ($user) {
            shell_exec("v-add-letsencrypt-user $user");
            return true;
        });

        $privateKey = file_get_contents(config('hestia.users_path') . "/$user/ssl/user.key");
        $acmeClient = app(AcmeClient::class, [$privateKey]);

        if (str_contains(config('acme.api_url'), 'staging')) {
            $acmeClient->registerAccount("info@$domain");
        }

        $challenges = [];
        $this->task('Requesting authorization', function () use ($wildcardDomain, $acmeClient, &$challenges) {
            $challenges = $acmeClient->requestAuthorization($wildcardDomain);
            return true;
        });

        $solvedChallenge = null;
        $this->task('Solving challenge', function () use ($solver, $challenges, $wildcardDomain, &$solvedChallenge) {
            foreach ($challenges as $challenge) {
                if ($solver->supports($challenge)) {
                    $solvedChallenge = $challenge;
                    $solver->solve($challenge);
                    return true;
                }
            }
            return false;
        });

        $this->task('Validating solved challenge', function () use ($solvedChallenge, $solver, $challenges) {
            if ($solvedChallenge === null) {
                return false;
            }

            $validator = new WaitingValidator(new DnsValidator(dnsResolver: new DohDnsResolver()), 60);
            if (!$validator->isValid($solvedChallenge, $solver)) {
                $this->warn('Unable to locally validate challenge, testing with LE servers...');
            }

            return true;
        });

        $this->task('Challenge authorization', function () use ($acmeClient, $solvedChallenge) {
            if ($solvedChallenge === null) {
                return false;
            }
            $acmeClient->challengeAuthorization($solvedChallenge);
            return true;
        });

        $certificate = null;
        $this->task('Requesting certificate', function () use ($user, $acmeClient, $wildcardDomain, &$certificate) {
            $leUserInfo = json_decode(shell_exec("v-list-letsencrypt-user $user json"), true);
            $mail = $leUserInfo[$user]['EMAIL'];

            $keyPair = (new KeyPairGenerator())->generateKeyPair();
            $dname = new DistinguishedName(
                $wildcardDomain,
                'US',
                'California',
                'San Francisco',
                'HCPC',
                'IT',
                $mail
            );

            $csr = new CertificateRequest($dname, $keyPair);

            $certificate = $acmeClient->requestCertificate($wildcardDomain, $csr);
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
            function () use ($solverConfig, $store, $user, $wildcardDomain, $domain, $certificate, $solver) {
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
                    ['wildcard_domain', '=', $wildcardDomain]
                ]);
                if ($storedRecord !== null) {
                    $store->deleteById($storedRecord['_id']);
                }

                $store->insert([
                    'user' => $user,
                    'domain' => $domain,
                    'wildcard_domain' => $wildcardDomain,
                    'expires_at' => $validToTimestamp,
                    'solver' => $solver::class,
                    'solver_config' => $solverConfig,
                ]);

                return true;
            });

        $this->task('Cleanup', function () use ($tempCertsDir, $solver, $solvedChallenge) {
            if ($solvedChallenge === null || $tempCertsDir === null) {
                return false;
            }
            // clear challenge
            $solver->cleanup($solvedChallenge);

            // clear temp files
            array_map('unlink', glob("$tempCertsDir/*"));
            rmdir($tempCertsDir);

            return true;
        });
    }

}
