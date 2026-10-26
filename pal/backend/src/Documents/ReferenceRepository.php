<?php

declare(strict_types=1);

namespace App\Documents;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Document types and departments (org units).
 */
final class ReferenceRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{id: string, code: string, name: string, reviewIntervalMonths: int|null, isActive: bool}>
     */
    public function documentTypes(bool $activeOnly): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM document_type'.($activeOnly ? ' WHERE is_active' : '').' ORDER BY name');

        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'code' => Row::string($r, 'code'),
            'name' => Row::string($r, 'name'),
            'reviewIntervalMonths' => null === $r['review_interval_months'] ? null : Row::int($r, 'review_interval_months'),
            'isActive' => Row::bool($r, 'is_active'),
        ], $rows);
    }

    /**
     * @return list<array{id: string, code: string, name: string, isActive: bool}>
     */
    public function departments(bool $activeOnly): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM org_unit'.($activeOnly ? ' WHERE is_active' : '').' ORDER BY name');

        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'code' => Row::string($r, 'code'),
            'name' => Row::string($r, 'name'),
            'isActive' => Row::bool($r, 'is_active'),
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveType(string $id): ?array
    {
        return $this->findActive('document_type', $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveDepartment(string $id): ?array
    {
        return $this->findActive('org_unit', $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActive(string $table, string $id): ?array
    {
        if (!Ids::isUuid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ? AND is_active', [$id]);

        return false === $row ? null : $row;
    }
}
