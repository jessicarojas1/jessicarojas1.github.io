<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Dynamic field definitions + values (module 9). Retiring a field never
 * deletes it or its historical values — it is flagged inactive, and
 * "replace" creates a new definition row pointing back at the old one via
 * replaces_field_definition_id so certification/review history stays
 * reconstructable (section 9/24 of the directive).
 */
final class DynamicFields
{
    /** @return array<int,array<string,mixed>> active definitions visible for this scope (global + app-specific). */
    public static function activeDefinitions(?int $applicationId = null, ?string $entityType = null): array
    {
        $clauses = ['active = TRUE'];
        $params = [];
        if ($applicationId !== null) {
            $clauses[] = '(application_id IS NULL OR application_id = :aid)';
            $params['aid'] = $applicationId;
        } else {
            $clauses[] = 'application_id IS NULL';
        }
        if ($entityType !== null) {
            $clauses[] = 'entity_type = :etype';
            $params['etype'] = $entityType;
        }
        $where = implode(' AND ', $clauses);
        return Db::fetchAll("SELECT * FROM dynamic_field_definition WHERE $where ORDER BY label", $params);
    }

    public static function all(): array
    {
        return Db::fetchAll(
            'SELECT d.*, a.name AS application_name
             FROM dynamic_field_definition d LEFT JOIN application a ON a.id = d.application_id
             ORDER BY d.active DESC, d.label'
        );
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM dynamic_field_definition WHERE id = :id', ['id' => $id]);
    }

    public static function create(array $data, ?int $actorId): int
    {
        $payload = self::sanitize($data);
        $id = Db::insert('dynamic_field_definition', $payload);
        Audit::log('dynamicfield.create', 'dynamic_field_definition#' . $id, null, $payload, null, $actorId);
        return $id;
    }

    public static function retire(int $id, ?int $actorId): void
    {
        $before = self::get($id);
        Db::update('dynamic_field_definition', ['active' => false], ['id' => $id]);
        Audit::log('dynamicfield.retire', 'dynamic_field_definition#' . $id, $before, ['active' => false], null, $actorId);
    }

    /** Retire $oldId and create a replacement definition that preserves the lineage. */
    public static function replace(int $oldId, array $newData, ?int $actorId): int
    {
        $newData['replaces_field_definition_id'] = $oldId;
        $newId = self::create($newData, $actorId);
        self::retire($oldId, $actorId);
        return $newId;
    }

    /**
     * Values for a batch of entities of one entity_type, keyed by entity_id
     * then field_key. @param int[] $entityIds
     * @return array<int,array<string,mixed>>
     */
    public static function valuesFor(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $in = [];
        $params = ['etype' => $entityType];
        foreach (array_values($entityIds) as $i => $id) {
            $key = 'id' . $i;
            $in[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        $rows = Db::fetchAll(
            "SELECT v.entity_id, v.value, d.field_key
             FROM dynamic_field_value v JOIN dynamic_field_definition d ON d.id = v.field_definition_id
             WHERE v.entity_type = :etype AND v.entity_id IN (" . implode(',', $in) . ')',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $eid = (int) $r['entity_id'];
            $val = is_string($r['value']) ? json_decode($r['value'], true) : $r['value'];
            $out[$eid][$r['field_key']] = $val;
        }
        return $out;
    }

    public static function setValue(int $fieldDefinitionId, string $entityType, int $entityId, mixed $value, ?int $userId): void
    {
        Db::query(
            'INSERT INTO dynamic_field_value (field_definition_id, entity_type, entity_id, value, set_by_user_id)
             VALUES (:fid, :etype, :eid, :val::jsonb, :uid)
             ON CONFLICT (field_definition_id, entity_type, entity_id)
             DO UPDATE SET value = EXCLUDED.value, set_by_user_id = EXCLUDED.set_by_user_id, set_at = NOW()',
            [
                'fid' => $fieldDefinitionId,
                'etype' => $entityType,
                'eid' => $entityId,
                'val' => json_encode($value, JSON_THROW_ON_ERROR),
                'uid' => $userId,
            ]
        );
        Audit::log('dynamicfield.value_set', "$entityType#$entityId:field#$fieldDefinitionId", null, ['value' => $value], null, $userId);
    }

    private static function sanitize(array $data): array
    {
        $allowed = [
            'field_key', 'label', 'field_type', 'entity_type', 'application_id',
            'options', 'required', 'active', 'replaces_field_definition_id',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
