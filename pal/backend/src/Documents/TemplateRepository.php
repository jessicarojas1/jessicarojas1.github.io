<?php

declare(strict_types=1);

namespace App\Documents;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Document templates: controlled starting content per document type (ADR-0009). Only
 * PAL administrators change them; authors choose one when creating a document.
 */
final class TemplateRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Every template, active or not, for the admin list (without bodies, which can be large).
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return array_map(fn (array $r): array => $this->summary($r), $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT t.id, t.name, t.description, t.is_active, t.updated_at, t.row_version, t.version, t.document_type_id,
                   dt.code AS type_code, dt.name AS type_name, u.display_name AS updated_by_name
            FROM document_template t
            JOIN document_type dt ON dt.id = t.document_type_id
            JOIN app_user u ON u.id = t.updated_by
            ORDER BY dt.name, t.name
            SQL));
    }

    /**
     * Active templates of one active document type, for the create-document form.
     *
     * @return list<array{id: string, name: string, description: string|null}>
     */
    public function activeForType(string $typeId): array
    {
        if (!Ids::isUuid($typeId)) {
            return [];
        }

        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'name' => Row::string($r, 'name'),
            'description' => Row::nullableString($r, 'description'),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT t.id, t.name, t.description FROM document_template t
            JOIN document_type dt ON dt.id = t.document_type_id
            WHERE t.document_type_id = ? AND t.is_active AND dt.is_active
            ORDER BY t.name
            SQL, [$typeId]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, bool $forUpdate = false): ?array
    {
        if (!Ids::isUuid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT t.*, dt.code AS type_code, dt.name AS type_name, u.display_name AS updated_by_name
            FROM document_template t
            JOIN document_type dt ON dt.id = t.document_type_id
            JOIN app_user u ON u.id = t.updated_by
            WHERE t.id = ?
            SQL.($forUpdate ? ' FOR UPDATE OF t' : ''), [$id]);

        return false === $row ? null : $row;
    }

    /**
     * The template an author may apply to a new document of the given type, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForType(string $id, string $typeId): ?array
    {
        $template = $this->find($id);
        if (null === $template || !Row::bool($template, 'is_active') || Row::string($template, 'document_type_id') !== $typeId) {
            return null;
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function summary(array $row): array
    {
        return [
            'id' => Row::string($row, 'id'),
            'name' => Row::string($row, 'name'),
            'description' => Row::nullableString($row, 'description'),
            'isActive' => Row::bool($row, 'is_active'),
            'documentType' => ['id' => Row::string($row, 'document_type_id'), 'code' => Row::string($row, 'type_code'), 'name' => Row::string($row, 'type_name')],
            'updatedAt' => Row::string($row, 'updated_at'),
            'updatedBy' => Row::string($row, 'updated_by_name'),
            'rowVersion' => Row::int($row, 'row_version'),
            'version' => Row::int($row, 'version'),
        ];
    }

    /**
     * Every saved version of a template, newest first (bodies omitted).
     *
     * @return list<array{version: int, name: string, description: string|null, createdBy: string, createdAt: string, bodySha256: string}>
     */
    public function versions(string $templateId): array
    {
        if (!Ids::isUuid($templateId)) {
            return [];
        }

        return array_map(fn (array $r): array => [
            'version' => Row::int($r, 'version'),
            'name' => Row::string($r, 'name'),
            'description' => Row::nullableString($r, 'description'),
            'createdBy' => Row::string($r, 'display_name'),
            'createdAt' => Row::string($r, 'created_at'),
            'bodySha256' => hash('sha256', Row::string($r, 'body_json')),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.version, v.name, v.description, v.body_json, v.created_at, u.display_name
            FROM document_template_version v JOIN app_user u ON u.id = v.created_by
            WHERE v.template_id = ?
            ORDER BY v.version DESC
            SQL, [$templateId]));
    }

    /**
     * One saved version, including its body.
     *
     * @return array<string, mixed>|null
     */
    public function version(string $templateId, int $version): ?array
    {
        if (!Ids::isUuid($templateId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT v.*, u.display_name FROM document_template_version v JOIN app_user u ON u.id = v.created_by
            WHERE v.template_id = ? AND v.version = ?
            SQL, [$templateId, $version]);

        return false === $row ? null : $row;
    }

    /**
     * Records the template's current content as an immutable version.
     */
    public function recordVersion(string $templateId, int $version, string $name, ?string $description, string $bodyJson, string $userId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO document_template_version (template_id, version, name, description, body_json, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$templateId, $version, $name, $description, $bodyJson, $userId],
        );
    }
}
