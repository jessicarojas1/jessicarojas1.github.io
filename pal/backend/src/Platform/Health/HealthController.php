<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness (process is up) and readiness (dependencies usable, schema current).
 * Responses never include connection details or error messages.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn (): bool => 1 === $this->connection->fetchOne('SELECT 1')),
            'migrations' => $this->check('migrations', fn (): bool => 0 === $this->migrations->getMigrationStatusCalculator()->getNewMigrations()->count()),
        ];
        $ready = !\in_array('fail', $checks, true);

        return new JsonResponse(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], $ready ? 200 : 503);
    }

    /**
     * @param callable(): bool $probe
     */
    private function check(string $name, callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            $this->logger->warning('Readiness check failed', ['check' => $name, 'exception' => $e]);

            return 'fail';
        }
    }
}
