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
            "SELECT dc.document_id, dc.id, dc.body, dc.author_id,
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
                'id'        => (int) $r['id'],
                'body'      => (string) $r['body'],
                'author'    => (string) ($r['author'] ?: 'Unknown'),
                'author_id' => $r['author_id'] !== null ? (int) $r['author_id'] : null,
                'at'        => (string) $r['at'],
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

    /** One comment (id, document_id, author_id, body) scoped to a program, or null. */
    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT id, document_id, author_id, body FROM document_comment WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ?: null;
    }

    /** Delete a comment. Authorization (author or document editor) is the controller's. */
    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM document_comment WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('document.comment.delete', 'comment#' . $id, $programId, $actorId);
    }

    /**
     * Notify the other participants in a document's comment thread that a new
     * comment landed. Recipients are the distinct prior commenters on that document
     * (the conversation), minus the actor, and only those who can STILL see the
     * document — Documents::canSee re-applies zone, company scope, and the
     * US-person export gate, so a comment notification never leaks a document to
     * someone whose access has since changed. Returns the number notified.
     *
     * @param array<string,mixed> $docRow a raw document_ref row (from Documents::get)
     */
    public static function notifyParticipants(array $docRow, int $programId, ?int $actorId): int
    {
        if (!Db::isConfigured()) {
            return 0;
        }
        $docId = (int) ($docRow['id'] ?? 0);
        if ($docId <= 0) {
            return 0;
        }
        $priorIds = array_map(
            static fn ($r) => (int) $r['author_id'],
            Db::fetchAll('SELECT DISTINCT author_id FROM document_comment WHERE document_id = :d AND author_id IS NOT NULL', ['d' => $docId])
        );
        if ($priorIds === []) {
            return 0;
        }
        $title = (string) ($docRow['title'] ?? 'a document');
        $url = '/app/documents?program_id=' . $programId . '#doc-' . $docId;
        return Notifications::fanOut(
            $programId,
            $actorId,
            'document.comment',
            'New comment on: ' . $title,
            $url,
            static fn (array $m): bool => in_array((int) $m['id'], $priorIds, true)
                && Documents::canSee($m, $docRow, $programId)
        );
    }
}
