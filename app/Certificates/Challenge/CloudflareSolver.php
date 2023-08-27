<?php

namespace App\Certificates\Challenge;

use AcmePhp\Core\Challenge\Dns\DnsResolverInterface;
use AcmePhp\Core\Challenge\MultipleChallengesSolverInterface;
use AcmePhp\Core\Challenge\SolverInterface;
use AcmePhp\Core\Protocol\AuthorizationChallenge;

class CloudflareSolver implements SolverInterface
{
    public function supports(AuthorizationChallenge $authorizationChallenge): bool
    {
        return $authorizationChallenge->getType() === 'dns-01';
    }

    public function solve(AuthorizationChallenge $authorizationChallenge)
    {

    }

    public function cleanup(AuthorizationChallenge $authorizationChallenge)
    {
        // TODO: Implement cleanup() method.
    }
}
