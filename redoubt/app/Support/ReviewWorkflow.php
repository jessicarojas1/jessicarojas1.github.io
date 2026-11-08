<?php

declare(strict_types=1);

namespace Redoubt\Support;

use RuntimeException;

/**
 * Review / approval workflow. Items carry a lifecycle stage (Concept → WIP →
 * Approved) and move through a routing chain:
 *   author → peer reader (OPTIONAL) → QA reviewer → executive authorization.
 *
 * current_step tracks who holds the item: author | peer | qa | exec | done.
 * Each decision is recorded in review_action and audited. A QA or exec rejection
 * sends the item back to the author (stage stays WIP).
 */
final class ReviewWorkflow
{
    public const STAGES = ['concept' => 'Concept', 'wip' => 'WIP', 'approved' => 'Approved'];
    public const STEPS  = ['author' => 'Author', 'peer' => 'Peer reader', 'qa' => 'QA reviewer', 'exec' => 'Exec authorization', 'done' => 'Done'];
    public const KINDS  = ['deliverable', 'document', 'policy', 'cdrl', 'proposal', 'other'];

    public static function create(int $programId, array $d, ?int $actorId): int
    {
        $id = Db::insert('review_item', [
            'program_id'  => $programId,
            'title'       => (string) ($d['title'] ?? ''),
            'kind'        => in_array($d['kind'] ?? '', self::KINDS, true) ? $d['kind'] : 'deliverable',
            'description' => trim((string) ($d['description'] ?? '')) ?: null,
            'ref_url'     => (($u = trim((string) ($d['ref_url'] ?? ''))) !== '' && preg_match('#^https?://#i', $u)) ? $u : null,
            'stage'       => 'concept',
            'current_step' => 'author',
            'created_by'  => $actorId,
        ]);
        Audit::log('review.create', 'item#' . $id, $programId, $actorId);
        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForProgram(int $programId, ?string $stage = null): array
    {
        $sql = 'SELECT ri.*, COALESCE(u.display_name, u.email) AS author
                  FROM review_item ri LEFT JOIN app_user u ON u.id = ri.created_by
                 WHERE ri.program_id = :p';
        $params = ['p' => $programId];
        if ($stage !== null && isset(self::STAGES[$stage])) {
            $sql .= ' AND ri.stage = :s';
            $params['s'] = $stage;
        }
        $sql .= ' ORDER BY ri.updated_at DESC, ri.id DESC';
        return array_map([self::class, 'hydrate'], Db::fetchAll($sql, $params));
    }

    /** @return array<string,mixed>|null raw row */
    public static function getRaw(int $id, int $programId): ?array
    {
        return Db::fetchOne('SELECT * FROM review_item WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
    }

    /** @return array<int,array<string,mixed>> decision history */
    public static function history(int $itemId): array
    {
        return Db::fetchAll(
            "SELECT ra.step, ra.action, ra.comment, to_char(ra.at,'YYYY-MM-DD HH24:MI') at, COALESCE(u.display_name,u.email) actor
               FROM review_action ra LEFT JOIN app_user u ON u.id = ra.actor_id
              WHERE ra.item_id = :i ORDER BY ra.id",
            ['i' => $itemId]
        );
    }

    /** Which workflow actions are valid from a given current_step/stage. @return string[] */
    public static function availableActions(array $row): array
    {
        return match (true) {
            $row['stage'] === 'approved'       => ['reopen'],
            $row['current_step'] === 'author'  => ['submit'],
            $row['current_step'] === 'peer'    => ['peer_approve', 'peer_skip'],
            $row['current_step'] === 'qa'      => ['qa_approve', 'qa_reject'],
            $row['current_step'] === 'exec'    => ['exec_approve', 'exec_reject'],
            default                            => [],
        };
    }

    /** Apply a workflow action. Validates the transition; records + audits it. */
    public static function act(int $id, int $programId, string $action, ?int $actorId, string $comment = ''): void
    {
        $it = self::getRaw($id, $programId);
        if ($it === null) {
            throw new RuntimeException('Item not found.');
        }
        if (!in_array($action, self::availableActions($it), true)) {
            throw new RuntimeException('Action not allowed from the current stage.');
        }
        [$stage, $step, $recStep, $recAction] = match ($action) {
            'submit'       => ['wip', 'peer', 'submit', 'submit'],
            'peer_approve' => [$it['stage'], 'qa', 'peer', 'approve'],
            'peer_skip'    => [$it['stage'], 'qa', 'peer', 'skip'],
            'qa_approve'   => [$it['stage'], 'exec', 'qa', 'approve'],
            'qa_reject'    => ['wip', 'author', 'qa', 'reject'],
            'exec_approve' => ['approved', 'done', 'exec', 'approve'],
            'exec_reject'  => ['wip', 'author', 'exec', 'reject'],
            'reopen'       => ['wip', 'author', 'reopen', 'reopen'],
        };
        Db::update('review_item', ['stage' => $stage, 'current_step' => $step], ['id' => $id, 'program_id' => $programId]);
        Db::insert('review_action', ['item_id' => $id, 'step' => $recStep, 'action' => $recAction, 'actor_id' => $actorId, 'comment' => trim($comment) ?: null]);
        Audit::log('review.' . $action, 'item#' . $id, $programId, $actorId);
        self::notify($it, $programId, $step, $recAction, $actorId);
    }

    /**
     * Items in this program that currently await the given user's action: review
     * steps they are permitted to act on (peer/QA/exec), plus items returned to
     * them in their capacity as author. Resolved through the same Authorize engine
     * used to enforce the action, so the queue never lists an item the user could
     * not actually act on.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function awaitingForUser(array $user, int $programId): array
    {
        if (!Db::isConfigured()) {
            return [];
        }
        $stepPerm = ['peer' => 'review.peer', 'qa' => 'review.qa', 'exec' => 'review.authorize'];
        $uid = isset($user['id']) ? (int) $user['id'] : null;
        $out = [];
        foreach (self::listForProgram($programId) as $it) {
            if ($it['stage'] === 'approved') {
                continue;
            }
            if ($it['current_step'] === 'author') {
                if ($uid !== null && $it['created_by'] === $uid
                    && Authorize::can($user, 'review.submit', ['program_id' => $programId])) {
                    $out[] = $it;
                }
                continue;
            }
            $perm = $stepPerm[$it['current_step']] ?? null;
            if ($perm !== null && Authorize::can($user, $perm, ['program_id' => $programId])) {
                $out[] = $it;
            }
        }
        return $out;
    }

    /**
     * Tell the people who now hold the item. On advance to a review step the next
     * approvers are notified it is waiting on them (fanned out through the same
     * permission engine, so no one is told about an item they cannot see); on a
     * return to, reopen for, or approval of the author, the author is told directly.
     *
     * @param array<string,mixed> $raw the pre-transition row (carries title + author)
     */
    private static function notify(array $raw, int $programId, string $newStep, string $recAction, ?int $actorId): void
    {
        if (!Db::isConfigured()) {
            return;
        }
        $title = (string) ($raw['title'] ?? 'Review item');
        $url = '/app/reviews?program_id=' . $programId;
        $stepPerm = ['peer' => 'review.peer', 'qa' => 'review.qa', 'exec' => 'review.authorize'];

        if (isset($stepPerm[$newStep])) {
            $perm = $stepPerm[$newStep];
            Notifications::fanOut(
                $programId,
                $actorId,
                'review.await',
                'Review awaiting you: ' . $title,
                $url,
                static fn (array $m): bool => Authorize::can($m, $perm, ['program_id' => $programId])
            );
            return;
        }

        // Otherwise the item rests with its author: returned (reject), reopened,
        // or finally approved (newStep 'done'). Tell the author directly.
        $authorId = isset($raw['created_by']) && $raw['created_by'] !== null ? (int) $raw['created_by'] : null;
        if ($authorId === null || $authorId === $actorId) {
            return;
        }
        $msg = match ($recAction) {
            'reject'  => 'Review returned to you: ' . $title,
            'approve' => 'Review approved: ' . $title,
            default   => 'Review reopened: ' . $title,
        };
        Notifications::add($authorId, 'review.' . $recAction, $msg, $url);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM review_item WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('review.delete', 'item#' . $id, $programId, $actorId);
    }

    private static function hydrate(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'title'        => $r['title'],
            'kind'         => $r['kind'],
            'description'  => $r['description'],
            'ref_url'      => $r['ref_url'],
            'stage'        => $r['stage'],
            'current_step' => $r['current_step'],
            'author'       => $r['author'] ?? null,
            'created_by'   => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'updated_at'   => $r['updated_at'] ?? null,
        ];
    }
}
