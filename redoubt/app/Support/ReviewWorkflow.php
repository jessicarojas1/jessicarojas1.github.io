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
        $docId = isset($d['document_id']) && $d['document_id'] !== null && $d['document_id'] !== ''
            ? (int) $d['document_id'] : null;
        $due = trim((string) ($d['due_date'] ?? ''));
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) === 1 ? $due : null;
        $id = Db::insert('review_item', [
            'program_id'  => $programId,
            'title'       => (string) ($d['title'] ?? ''),
            'kind'        => in_array($d['kind'] ?? '', self::KINDS, true) ? $d['kind'] : 'deliverable',
            'description' => trim((string) ($d['description'] ?? '')) ?: null,
            'ref_url'     => (($u = trim((string) ($d['ref_url'] ?? ''))) !== '' && preg_match('#^https?://#i', $u)) ? $u : null,
            'stage'       => 'concept',
            'current_step' => 'author',
            'document_id' => $docId,
            'due_date'    => $dueDate,
            'created_by'  => $actorId,
        ]);
        Audit::log('review.create', 'item#' . $id . ($docId !== null ? ' doc#' . $docId : ''), $programId, $actorId);
        return $id;
    }

    /**
     * Latest review item per document, for a set of document ids in a program.
     * Keyed by document_id. Used by the Documents module to show each document's
     * own approval state and decide whether a new review may be started.
     *
     * @param int[] $docIds
     * @return array<int,array{id:int,stage:string,current_step:string,title:string}>
     */
    public static function statusForDocuments(array $docIds, int $programId): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($docIds, static fn ($v) => (int) $v > 0))));
        if ($ids === [] || !Db::isConfigured()) {
            return [];
        }
        // DISTINCT ON picks the most recent review_item row per document_id.
        $place = [];
        $params = ['p' => $programId];
        foreach ($ids as $i => $v) {
            $place[] = ':d' . $i;
            $params['d' . $i] = $v;
        }
        $sql = 'SELECT DISTINCT ON (document_id) document_id, id, stage, current_step, title
                  FROM review_item
                 WHERE program_id = :p AND document_id IN (' . implode(',', $place) . ')
                 ORDER BY document_id, id DESC';
        $out = [];
        foreach (Db::fetchAll($sql, $params) as $r) {
            $out[(int) $r['document_id']] = [
                'id'           => (int) $r['id'],
                'stage'        => (string) $r['stage'],
                'current_step' => (string) $r['current_step'],
                'title'        => (string) $r['title'],
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForProgram(int $programId, ?string $stage = null): array
    {
        $sql = 'SELECT ri.*, COALESCE(u.display_name, u.email) AS author,
                       d.title AS doc_title, d.export_controlled AS doc_export
                  FROM review_item ri
                  LEFT JOIN app_user u ON u.id = ri.created_by
                  LEFT JOIN document_ref d ON d.id = ri.document_id
                 WHERE ri.program_id = :p';
        $params = ['p' => $programId];
        if ($stage !== null && isset(self::STAGES[$stage])) {
            $sql .= ' AND ri.stage = :s';
            $params['s'] = $stage;
        }
        $sql .= ' ORDER BY ri.updated_at DESC, ri.id DESC';
        return array_map([self::class, 'hydrate'], Db::fetchAll($sql, $params));
    }

    /**
     * Governance projection for API clients (no US-person context). Returns only
     * lifecycle fields — no author, description, ref_url, or document title — and
     * suppresses the document_id of an export-controlled document, so an API token
     * can never learn the identity of an ITAR/EAR-marked item through the review
     * feed. Mirrors Documents::listForApiClient's export exclusion.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForApiClient(int $programId): array
    {
        $out = [];
        foreach (self::listForProgram($programId) as $i) {
            $export = !empty($i['doc_export']);
            $out[] = [
                'id'           => $i['id'],
                // Titles auto-generated from a document echo that document's name, so
                // redact the title (and hide the id) when the linked doc is controlled.
                'title'        => $export ? '[export-controlled review]' : $i['title'],
                'kind'         => $i['kind'],
                'stage'        => $i['stage'],
                'current_step' => $i['current_step'],
                'due_date'     => $i['due_date'],
                'document_id'  => $export ? null : $i['document_id'],
            ];
        }
        return $out;
    }

    /**
     * SLA / aging metrics for a program's review pipeline. Program-level
     * aggregates only (no per-item visibility concerns): counts by stage, open /
     * overdue / due-soon tallies, average approved cycle time, and the oldest
     * still-open items. Used by the Analytics page.
     *
     * @return array<string,mixed>
     */
    public static function metrics(int $programId): array
    {
        $empty = [
            'by_stage' => ['concept' => 0, 'wip' => 0, 'approved' => 0],
            'open' => 0, 'overdue' => 0, 'due_soon' => 0, 'avg_cycle_days' => null, 'oldest' => [],
        ];
        if (!Db::isConfigured()) {
            return $empty;
        }
        $byStage = ['concept' => 0, 'wip' => 0, 'approved' => 0];
        foreach (Db::fetchAll('SELECT stage, count(*) c FROM review_item WHERE program_id = :p GROUP BY stage', ['p' => $programId]) as $r) {
            if (isset($byStage[$r['stage']])) {
                $byStage[$r['stage']] = (int) $r['c'];
            }
        }
        $one = static fn (string $sql): int => (int) (Db::fetchOne($sql, ['p' => $programId])['c'] ?? 0);
        $overdue = $one("SELECT count(*) c FROM review_item WHERE program_id = :p AND stage <> 'approved' AND due_date IS NOT NULL AND due_date < CURRENT_DATE");
        $dueSoon = $one("SELECT count(*) c FROM review_item WHERE program_id = :p AND stage <> 'approved' AND due_date IS NOT NULL AND due_date >= CURRENT_DATE AND due_date <= CURRENT_DATE + 7");
        $avgRow = Db::fetchOne(
            "SELECT round(avg(EXTRACT(EPOCH FROM (updated_at - created_at)) / 86400)::numeric, 1) d
               FROM review_item WHERE program_id = :p AND stage = 'approved'",
            ['p' => $programId]
        );
        $today = date('Y-m-d');
        $oldest = array_map(static function (array $r) use ($today): array {
            return [
                'id'           => (int) $r['id'],
                'title'        => (string) $r['title'],
                'stage'        => (string) $r['stage'],
                'current_step' => (string) $r['current_step'],
                'days_open'    => (int) round((float) $r['days_open']),
                'due_date'     => $r['due_date'],
                'overdue'      => $r['due_date'] !== null && (string) $r['due_date'] < $today,
            ];
        }, Db::fetchAll(
            "SELECT id, title, stage, current_step, due_date,
                    EXTRACT(EPOCH FROM (now() - created_at)) / 86400 AS days_open
               FROM review_item
              WHERE program_id = :p AND stage <> 'approved'
              ORDER BY created_at ASC LIMIT 5",
            ['p' => $programId]
        ));
        return [
            'by_stage'       => $byStage,
            'open'           => $byStage['concept'] + $byStage['wip'],
            'overdue'        => $overdue,
            'due_soon'       => $dueSoon,
            'avg_cycle_days' => ($avgRow && $avgRow['d'] !== null) ? (float) $avgRow['d'] : null,
            'oldest'         => $oldest,
        ];
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
        self::emitWebhook($it, $id, $programId, $action, $stage, $step);
    }

    /**
     * Emit a signed webhook for the lifecycle milestones external systems care
     * about: submitted, approved (authorized), and rejected/returned. The payload
     * carries no free-text beyond the (program-visible) title and the document id —
     * subscriptions are program-scoped, so this does not cross a program boundary.
     */
    private static function emitWebhook(array $raw, int $id, int $programId, string $action, string $stage, string $step): void
    {
        $event = match ($action) {
            'submit'                   => 'review.submitted',
            'exec_approve'             => 'review.approved',
            'qa_reject', 'exec_reject' => 'review.rejected',
            default                    => null,
        };
        if ($event === null) {
            return;
        }
        Webhooks::dispatch($event, [
            'id'           => $id,
            'program_id'   => $programId,
            'title'        => (string) ($raw['title'] ?? ''),
            'kind'         => (string) ($raw['kind'] ?? ''),
            'stage'        => $stage,
            'current_step' => $step,
            'document_id'  => isset($raw['document_id']) && $raw['document_id'] !== null ? (int) $raw['document_id'] : null,
        ], $programId);
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
            'document_id'  => isset($r['document_id']) && $r['document_id'] !== null ? (int) $r['document_id'] : null,
            'doc_title'    => $r['doc_title'] ?? null,
            'doc_export'   => !empty($r['doc_export']),
            'due_date'     => $r['due_date'] ?? null,
            'created_by'   => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'updated_at'   => $r['updated_at'] ?? null,
        ];
    }
}
