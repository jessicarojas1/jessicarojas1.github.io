<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Remediation task tracking (Phase 6 — module 10/section 10 of the build
 * directive). A task is a TRACKED TO-DO, never an executed action — this
 * app has no connector that claims revoke_access/modify_access/
 * disable_accounts (see Connectors::defaultManifest()), so resolving a
 * task is a human recording that they handled it elsewhere, not this app
 * acting on their behalf. See Campaigns.php's own doc comment for the
 * same principle applied to a campaign's "revoked" decision, which is one
 * of this module's two creation sources.
 */
final class RemediationTasks
{
    public const TASK_TYPES = ['remove_access', 'disable_account', 'investigate'];

    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $clauses[] = 'rt.status = :status';
            $params['status'] = $filters['status'];
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT rt.*, sa.external_account_id, sa.username, a.name AS application_name,
                    e.name AS entitlement_name, p.display_name AS person_name,
                    asg.display_name AS assigned_to_name, cb.display_name AS created_by_name,
                    rb.display_name AS resolved_by_name
             FROM remediation_task rt
             JOIN system_account sa ON sa.id = rt.system_account_id
             JOIN application a ON a.id = sa.application_id
             LEFT JOIN entitlement e ON e.id = rt.entitlement_id
             LEFT JOIN person p ON p.id = sa.person_id
             LEFT JOIN person asg ON asg.id = rt.assigned_to_person_id
             LEFT JOIN app_user cb ON cb.id = rt.created_by_user_id
             LEFT JOIN app_user rb ON rb.id = rt.resolved_by_user_id
             WHERE $where
             ORDER BY rt.status = 'open' DESC, rt.created_at DESC
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    public static function countAll(array $filters = []): int
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $clauses[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        return (int) Db::fetchValue('SELECT COUNT(*) FROM remediation_task WHERE ' . implode(' AND ', $clauses), $params);
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM remediation_task WHERE id = :id', ['id' => $id]);
    }

    /** All tasks (open and closed) for one account, for the account-detail page. */
    public static function forAccount(int $systemAccountId): array
    {
        return Db::fetchAll(
            'SELECT rt.*, e.name AS entitlement_name
             FROM remediation_task rt
             LEFT JOIN entitlement e ON e.id = rt.entitlement_id
             WHERE rt.system_account_id = :aid
             ORDER BY rt.status = \'open\' DESC, rt.created_at DESC',
            ['aid' => $systemAccountId]
        );
    }

    /** Manual creation — from the account-detail page or any future caller. */
    public static function create(array $data, ?int $actorId): int
    {
        $taskType = (string) ($data['task_type'] ?? '');
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid task_type.');
        }
        $systemAccountId = (int) ($data['system_account_id'] ?? 0);
        if ($systemAccountId <= 0) {
            throw new \InvalidArgumentException('system_account_id is required.');
        }
        $id = Db::insert('remediation_task', [
            'source' => 'manual',
            'system_account_id' => $systemAccountId,
            'entitlement_id' => (int) ($data['entitlement_id'] ?? 0) ?: null,
            'task_type' => $taskType,
            'assigned_to_person_id' => (int) ($data['assigned_to_person_id'] ?? 0) ?: null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'created_by_user_id' => $actorId,
        ]);
        Audit::log('remediation.create', 'remediation_task#' . $id, null, $data, null, $actorId);
        return $id;
    }

    /**
     * Auto-creation from a campaign's "revoked" decision — called by
     * Campaigns::decide(). Creating the TASK (a tracking row) is safe to
     * do automatically; this is bookkeeping, not an access change, so it
     * doesn't violate the "nothing auto-applies" principle that governs
     * the revoke decision itself.
     */
    public static function createFromCampaignRevoke(int $campaignItemId, int $systemAccountId, int $entitlementId, ?string $note, ?int $actorId): int
    {
        $id = Db::insert('remediation_task', [
            'source' => 'campaign',
            'campaign_item_id' => $campaignItemId,
            'system_account_id' => $systemAccountId,
            'entitlement_id' => $entitlementId,
            'task_type' => 'remove_access',
            'description' => $note,
            'created_by_user_id' => $actorId,
        ]);
        Audit::log('remediation.create', 'remediation_task#' . $id, null, ['source' => 'campaign', 'campaign_item_id' => $campaignItemId], null, $actorId);
        return $id;
    }

    public static function resolve(int $id, ?string $note, int $actorId): void
    {
        self::setStatus($id, 'resolved', $note, $actorId);
    }

    public static function dismiss(int $id, ?string $note, int $actorId): void
    {
        self::setStatus($id, 'dismissed', $note, $actorId);
    }

    private static function setStatus(int $id, string $status, ?string $note, int $actorId): void
    {
        $before = self::get($id);
        if ($before === null) {
            throw new \RuntimeException('Remediation task not found.');
        }
        if ($before['status'] !== 'open') {
            throw new \RuntimeException('This task has already been ' . $before['status'] . '.');
        }
        Db::query(
            'UPDATE remediation_task SET status = :status, resolution_note = :note,
                resolved_by_user_id = :by, resolved_at = NOW() WHERE id = :id',
            ['id' => $id, 'status' => $status, 'note' => $note, 'by' => $actorId]
        );
        Audit::log('remediation.' . $status, 'remediation_task#' . $id, ['status' => 'open'], ['status' => $status, 'note' => $note], $note, $actorId);
    }
}
