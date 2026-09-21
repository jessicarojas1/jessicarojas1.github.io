<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * FAQ / Knowledge Base module (Annex H). Curated Q&A, audience-trimmed. Any
 * program member may view; managers (faq.manage) maintain entries. Feeds search.
 */
final class Faq
{
    /** @return array<int,array<string,mixed>> entries visible to the user */
    public static function listForUser(array $user, int $programId, bool $isManager = false): array
    {
        $rows = Db::fetchAll('SELECT * FROM faq WHERE program_id = :p ORDER BY id', ['p' => $programId]);
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
        $id = Db::insert('faq', [
            'program_id' => $programId,
            'question'   => (string) ($d['question'] ?? ''),
            'answer'     => (string) ($d['answer'] ?? ''),
            'audience'   => $d['audience'] ?? [],
        ]);
        Audit::log('faq.create', 'faq#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $d, ?int $actorId): void
    {
        Db::update('faq', [
            'question' => (string) ($d['question'] ?? ''),
            'answer'   => (string) ($d['answer'] ?? ''),
            'audience' => $d['audience'] ?? [],
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('faq.edit', 'faq#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM faq WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('faq.delete', 'faq#' . $id, $programId, $actorId);
    }

    private static function hydrate(array $r): array
    {
        return [
            'id'       => (int) $r['id'],
            'question' => $r['question'],
            'answer'   => $r['answer'],
            'audience' => Audience::decode($r['audience'] ?? null),
        ];
    }
}
