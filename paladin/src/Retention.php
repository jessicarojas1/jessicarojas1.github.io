<?php
declare(strict_types=1);

/**
 * Retention — evaluates and applies content retention rules.
 *
 * A rule targets documents or pages, optionally scoped to a space (and, for
 * documents, a doc_type), and matches content that has been inactive (no
 * update) for at least `age_days`. The configured action is either:
 *   - archive : set the item's status to 'archived' (soft, reversible)
 *   - notify  : raise an alert to the item owner (no mutation)
 *
 * preview() never mutates — it returns the count of items a rule would affect.
 * apply() performs the action and returns the number affected.
 */
final class Retention
{
    /** Build the WHERE clause + params common to preview() and apply(). */
    private static function criteria(array $rule): array
    {
        $type    = $rule['content_type'] === 'page' ? 'page' : 'document';
        $ageDays = max(1, (int)$rule['age_days']);
        $where   = ["updated_at < NOW() - (? || ' days')::interval"];
        $params  = [(string)$ageDays];

        if ($type === 'document') {
            $where[] = "status NOT IN ('archived','obsolete')";
            if (!empty($rule['doc_type'])) { $where[] = "doc_type = ?"; $params[] = $rule['doc_type']; }
        } else {
            $where[] = "status <> 'archived'";
        }
        if (!empty($rule['space_id'])) { $where[] = "space_id = ?"; $params[] = (int)$rule['space_id']; }

        $table = $type === 'page' ? 'pages' : 'documents';
        return [$table, implode(' AND ', $where), $params];
    }

    /** Count items a rule would affect right now (read-only). */
    public static function preview(array $rule): int
    {
        [$table, $whereSql, $params] = self::criteria($rule);
        try {
            return (int)(Database::fetchOne("SELECT COUNT(*) c FROM {$table} WHERE {$whereSql}", $params)['c'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Apply a rule's action; returns the number of items affected. */
    public static function apply(array $rule): int
    {
        [$table, $whereSql, $params] = self::criteria($rule);
        $action = $rule['action'] === 'notify' ? 'notify' : 'archive';

        // Count matches before any mutation so the reported number is accurate.
        $matched = self::preview($rule);
        if ($matched === 0) return 0;

        if ($action === 'archive') {
            Database::query(
                "UPDATE {$table} SET status = 'archived', updated_at = NOW() WHERE {$whereSql}",
                $params
            );
            return $matched;
        }

        // notify: alert each owner once (no mutation)
        $rows = Database::fetchAll(
            "SELECT id, title, owner_id FROM {$table} WHERE {$whereSql}",
            $params
        );
        $n = 0;
        foreach ($rows as $r) {
            if (empty($r['owner_id'])) continue;
            try {
                Database::insert('alerts', [
                    'user_id'  => (int)$r['owner_id'],
                    'title'    => 'Retention review due',
                    'body'     => 'Content "' . $r['title'] . '" is past its retention age and may need review or archival.',
                    'severity' => 'warning',
                    'is_read'  => 'f',
                ]);
                $n++;
            } catch (\Throwable) { /* skip */ }
        }
        return $n;
    }
}
