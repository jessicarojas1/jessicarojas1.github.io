<?php
/**
 * Reactions — emoji reactions for pages, documents, blogs and comments.
 * A user may add several distinct emoji to one entity. Never throws.
 */
final class Reactions {

    /** The emoji a user can pick from. */
    public const PALETTE = ['👍', '❤️', '🎉', '👀', '🚀', '✅'];

    /**
     * Per-entity reaction summary from the current user's perspective.
     * @return array<int,array{total:int,emojis:array<int,array{emoji:string,count:int,reacted:bool}>}>
     */
    public static function summary(string $type, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach ($ids as $id) $out[$id] = ['total' => 0, 'emojis' => []];
        try {
            $arr = '{' . implode(',', $ids) . '}';
            $rows = Database::fetchAll(
                "SELECT entity_id, emoji, COUNT(*) AS c, BOOL_OR(user_id = ?) AS reacted
                 FROM reactions WHERE entity_type = ? AND entity_id = ANY(?::int[])
                 GROUP BY entity_id, emoji
                 ORDER BY COUNT(*) DESC, emoji",
                [Auth::id(), $type, $arr]
            );
            foreach ($rows as $r) {
                $eid = (int)$r['entity_id'];
                $count = (int)$r['c'];
                $out[$eid]['total'] += $count;
                $out[$eid]['emojis'][] = [
                    'emoji'   => $r['emoji'] ?: '👍',
                    'count'   => $count,
                    'reacted' => ($r['reacted'] === true || $r['reacted'] === 't'),
                ];
            }
        } catch (Throwable) {}
        return $out;
    }

    public static function one(string $type, int $id): array {
        return self::summary($type, [$id])[$id] ?? ['total' => 0, 'emojis' => []];
    }
}
