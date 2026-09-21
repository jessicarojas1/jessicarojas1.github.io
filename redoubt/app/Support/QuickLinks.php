<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Quick Links module (Annex H). Curated links to enterprise systems, audience-
 * trimmed. Any program member may view; managers (quicklink.manage) maintain them.
 */
final class QuickLinks
{
    /** @return array<int,array<string,mixed>> links visible to the user */
    public static function listForUser(array $user, int $programId, bool $isManager = false): array
    {
        $rows = Db::fetchAll('SELECT * FROM quick_link WHERE program_id = :p ORDER BY label', ['p' => $programId]);
        $out = [];
        foreach ($rows as $r) {
            if ($isManager || Audience::visible($user, Audience::decode($r['audience'] ?? null), $programId)) {
                $out[] = self::hydrate($r);
            }
        }
        return $out;
    }

    public static function create(int $programId, array $d, ?int $actorId): int
    {
        $id = Db::insert('quick_link', [
            'program_id' => $programId,
            'label'      => (string) ($d['label'] ?? ''),
            'url'        => self::safeUrl((string) ($d['url'] ?? '')),
            'audience'   => $d['audience'] ?? [],
        ]);
        Audit::log('quicklink.create', 'quicklink#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $d, ?int $actorId): void
    {
        Db::update('quick_link', [
            'label'    => (string) ($d['label'] ?? ''),
            'url'      => self::safeUrl((string) ($d['url'] ?? '')),
            'audience' => $d['audience'] ?? [],
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('quicklink.edit', 'quicklink#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM quick_link WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('quicklink.delete', 'quicklink#' . $id, $programId, $actorId);
    }

    /** Only http(s) links are stored; anything else becomes '#'. */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        return preg_match('#^https?://#i', $url) ? $url : '#';
    }

    private static function hydrate(array $r): array
    {
        return [
            'id'       => (int) $r['id'],
            'label'    => $r['label'],
            'url'      => $r['url'],
            'audience' => Audience::decode($r['audience'] ?? null),
        ];
    }
}
