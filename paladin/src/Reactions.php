<?php
/**
 * Reactions — lightweight "like" toggle for pages, documents and comments.
 * One like per user per entity. Never throws.
 */
final class Reactions {

    /**
     * @return array<int,array{count:int,liked:bool}> map of entity_id => summary
     * for the given entity type and ids, from the current user's perspective.
     */
    public static function summary(string $type, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach ($ids as $id) $out[$id] = ['count' => 0, 'liked' => false];
        try {
            $arr = '{' . implode(',', $ids) . '}';
            $rows = Database::fetchAll(
                "SELECT entity_id, COUNT(*) AS c, BOOL_OR(user_id = ?) AS liked
                 FROM reactions WHERE entity_type = ? AND entity_id = ANY(?::int[])
                 GROUP BY entity_id",
                [Auth::id(), $type, $arr]
            );
            foreach ($rows as $r) {
                $out[(int)$r['entity_id']] = [
                    'count' => (int)$r['c'],
                    'liked' => ($r['liked'] === true || $r['liked'] === 't'),
                ];
            }
        } catch (Throwable) {}
        return $out;
    }

    public static function one(string $type, int $id): array {
        return self::summary($type, [$id])[$id] ?? ['count' => 0, 'liked' => false];
    }
}
