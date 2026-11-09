<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Discussion threads on documents. Comments are portal-owned content (not a shadow
 * of the document itself) and are available from the read-only interface, so a
 * reviewer or COR who may see a document can discuss it without edit rights.
 *
 * Authorization is enforced by the controller: the caller must be able to SEE the
 * document (Documents::canSee — zone, company scope, and the US-person export gate)
 * and hold document.comment. These methods assume that check has passed.
 */
final class DocumentComments
{
    public const MAX_LEN = 4000;

    /**
     * Comments for a set of documents in a program, grouped by document id and
     * ordered oldest-first. One query for the whole page (no N+1).
     *
     * @param int[] $docIds
     * @return array<int,array<int,array{id:int,body:string,author:string,at:string}>>
     */
    public static function forDocuments(array $docIds, int $programId): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($docIds, static fn ($v) => (int) $v > 0))));
        if ($ids === [] || !Db::isConfigured()) {
            return [];
        }
        $place = [];
        $params = ['p' => $programId];
        foreach ($ids as $i => $v) {
            $place[] = ':d' . $i;
            $params['d' . $i] = $v;
        }
        $rows = Db::fetchAll(
            "SELECT dc.document_id, dc.id, dc.body,
                    to_char(dc.created_at, 'YYYY-MM-DD HH24:MI') AS at,
                    COALESCE(u.display_name, u.email) AS author
               FROM document_comment dc
               LEFT JOIN app_user u ON u.id = dc.author_id
              WHERE dc.program_id = :p AND dc.document_id IN (" . implode(',', $place) . ")
              ORDER BY dc.document_id, dc.id",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['document_id']][] = [
                'id'     => (int) $r['id'],
                'body'   => (string) $r['body'],
                'author' => (string) ($r['author'] ?: 'Unknown'),
                'at'     => (string) $r['at'],
            ];
        }
        return $out;
    }

    /** Add a comment to a document. Returns the new comment id. */
    public static function add(int $docId, int $programId, ?int $authorId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }
        if (mb_strlen($body) > self::MAX_LEN) {
            $body = mb_substr($body, 0, self::MAX_LEN);
        }
        $id = Db::insert('document_comment', [
            'document_id' => $docId,
            'program_id'  => $programId,
            'author_id'   => $authorId,
            'body'        => $body,
        ]);
        Audit::log('document.comment', 'document#' . $docId, $programId, $authorId);
        return $id;
    }
}
