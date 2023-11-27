<?php

namespace App\Certificates;

use AcmePhp\Core\Challenge\ConfigurableServiceInterface;
use AcmePhp\Core\Challenge\Dns\GandiSolver;
use AcmePhp\Core\Challenge\SolverInterface;
use App\Certificates\Challenge\CloudflareSolver;
use InvalidArgumentException;

class SolverFactory
{

    private const SOLVERS = [
        'cloudflare' => CloudflareSolver::class,
        //'aws route53' => Route53Solver::class,
        'gandi' => GandiSolver::class,
    ];

    private const SOLVER_PARAMETERS = [
        'cloudflare' => [
            'zone_id' => 'Cloudflare DNS zone ID',
            'api_token' => 'Cloudflare API Token',
        ],
        'gandi' => [
            'api_key' => 'Gandi API key',
        ],
    ];

    public static function solvers(): array
    {
        return self::SOLVERS;
    }

    public static function solverParameters(string $solverName): array
    {
        return self::SOLVER_PARAMETERS[$solverName] ?? [];
    }

    public static function getNameByClass(string $solverClass): string
    {
        return array_search($solverClass, self::SOLVERS);
    }

    public function getSolver(string $solverName, array $config): SolverInterface
    {
        if (!isset(self::SOLVERS[$solverName])) {
            $solverName = self::getNameByClass($solverName);
        }

        if (!isset(self::SOLVERS[$solverName])) {
            throw new InvalidArgumentException("Solver $solverName not found");
        }

        $solver = app(self::SOLVERS[$solverName]);

        if ($solver instanceof ConfigurableServiceInterface) {
            $solver->configure($config);
        }

        return $solver;
    }

}
