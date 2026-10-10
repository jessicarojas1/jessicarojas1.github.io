<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Certification campaigns (Phase 5 — module 9/section 9 of the build
 * directive). A campaign's scope is frozen at launch: `create()` snapshots
 * every currently in-scope entitlement_assignment into
 * certification_campaign_item, so the review set is never a moving target
 * mid-campaign. Reviewer assignment is either 'manager' (each item's
 * reviewer is the linked account's person's direct manager, falling back to
 * the campaign's default reviewer when unmatched/no manager) or 'fixed'
 * (every item goes to the same named reviewer).
 *
 * A 'revoked' decision records the reviewer's judgment and automatically
 * opens a RemediationTasks row — it does NOT itself delete the
 * entitlement_assignment row. No connector in this build claims
 * `revoke_access`/`modify_access` (see Connectors::defaultManifest()), so
 * there is no live system this app could push a revocation back to;
 * silently deleting Verity's own inventory record of the access would
 * claim a removal that didn't actually happen anywhere. Creating the
 * TASK is safe to automate (it's tracking, not an access change);
 * resolving it is still a deliberate human step — see
 * RemediationTasks.php.
 */
final class Campaigns
{
    public const SCOPE_TYPES = ['application', 'privileged', 'all'];
    public const REVIEWER_STRATEGIES = ['manager', 'fixed'];

    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $clauses[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT c.*, a.name AS scope_application_name, r.display_name AS default_reviewer_name,
                    u.display_name AS created_by_name,
                    (SELECT COUNT(*) FROM certification_campaign_item i WHERE i.campaign_id = c.id) AS total_items,
                    (SELECT COUNT(*) FROM certification_campaign_item i WHERE i.campaign_id = c.id AND i.decision != 'pending') AS decided_items,
                    (SELECT COUNT(*) FROM certification_campaign_item i WHERE i.campaign_id = c.id AND i.decision = 'revoked') AS revoked_items
             FROM certification_campaign c
             LEFT JOIN application a ON a.id = c.scope_application_id
             LEFT JOIN person r ON r.id = c.default_reviewer_person_id
             LEFT JOIN app_user u ON u.id = c.created_by_user_id
             WHERE $where
             ORDER BY c.created_at DESC
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
        return (int) Db::fetchValue('SELECT COUNT(*) FROM certification_campaign WHERE ' . implode(' AND ', $clauses), $params);
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT c.*, a.name AS scope_application_name, r.display_name AS default_reviewer_name
             FROM certification_campaign c
             LEFT JOIN application a ON a.id = c.scope_application_id
             LEFT JOIN person r ON r.id = c.default_reviewer_person_id
             WHERE c.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Create a campaign and immediately snapshot every in-scope
     * entitlement_assignment into certification_campaign_item. Returns the
     * new campaign id and the number of items snapshotted.
     * @return array{id:int, item_count:int}
     */
    public static function create(array $data, ?int $actorId): array
    {
        $scopeType = (string) ($data['scope_type'] ?? '');
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid scope_type.');
        }
        $reviewerStrategy = (string) ($data['reviewer_strategy'] ?? '');
        if (!in_array($reviewerStrategy, self::REVIEWER_STRATEGIES, true)) {
            throw new \InvalidArgumentException('Invalid reviewer_strategy.');
        }
        $defaultReviewerId = (int) ($data['default_reviewer_person_id'] ?? 0) ?: null;
        if ($defaultReviewerId === null) {
            // Required in both strategies: the mandatory fallback for
            // 'manager' (unmatched accounts, managerless people) and the
            // sole reviewer for 'fixed'. Without it, an item could end up
            // with no reviewer at all — nobody able to act on it.
            throw new \InvalidArgumentException('A default reviewer is required.');
        }
        $applicationId = $scopeType === 'application' ? ((int) ($data['scope_application_id'] ?? 0) ?: null) : null;
        if ($scopeType === 'application' && $applicationId === null) {
            throw new \InvalidArgumentException('scope_application_id is required when scope_type is "application".');
        }

        $campaignId = Db::insert('certification_campaign', [
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'scope_type' => $scopeType,
            'scope_application_id' => $applicationId,
            'reviewer_strategy' => $reviewerStrategy,
            'default_reviewer_person_id' => $defaultReviewerId,
            'due_at' => !empty($data['due_at']) ? (string) $data['due_at'] : null,
            'created_by_user_id' => $actorId,
        ]);

        $itemCount = self::snapshotItems($campaignId, $scopeType, $applicationId, $reviewerStrategy, $defaultReviewerId);

        Audit::log('campaign.create', 'certification_campaign#' . $campaignId, null, [
            'scope_type' => $scopeType, 'scope_application_id' => $applicationId,
            'reviewer_strategy' => $reviewerStrategy, 'item_count' => $itemCount,
        ], null, $actorId);

        return ['id' => $campaignId, 'item_count' => $itemCount];
    }

    private static function snapshotItems(int $campaignId, string $scopeType, ?int $applicationId, string $reviewerStrategy, int $defaultReviewerId): int
    {
        $joins = 'FROM entitlement_assignment ea
            JOIN system_account sa ON sa.id = ea.system_account_id
            LEFT JOIN person p ON p.id = sa.person_id
            LEFT JOIN person mgr ON mgr.id = p.manager_person_id
            JOIN entitlement e ON e.id = ea.entitlement_id';

        $where = '1=1';
        $params = ['campaign_id' => $campaignId, 'default_reviewer' => $defaultReviewerId];
        if ($scopeType === 'application') {
            $where = 'sa.application_id = :aid';
            $params['aid'] = $applicationId;
        } elseif ($scopeType === 'privileged') {
            $where = '(e.is_privileged = TRUE OR sa.account_type = \'privileged\')';
        }

        $reviewerExpr = $reviewerStrategy === 'manager'
            ? 'COALESCE(mgr.id, :default_reviewer)'
            : ':default_reviewer';

        $stmt = Db::query(
            "INSERT INTO certification_campaign_item (campaign_id, entitlement_assignment_id, system_account_id, entitlement_id, reviewer_person_id)
             SELECT :campaign_id, ea.id, sa.id, ea.entitlement_id, $reviewerExpr
             $joins
             WHERE $where",
            $params
        );
        return $stmt->rowCount();
    }

    /** Items within a campaign, for the campaign-management/oversight view. */
    public static function itemsFor(int $campaignId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $clauses = ['i.campaign_id = :cid'];
        $params = ['cid' => $campaignId];
        if (!empty($filters['decision'])) {
            $clauses[] = 'i.decision = :decision';
            $params['decision'] = $filters['decision'];
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT i.*, sa.external_account_id, sa.username, a.name AS application_name,
                    e.name AS entitlement_name, e.is_privileged, e.risk_level,
                    p.display_name AS person_name, r.display_name AS reviewer_name,
                    db.display_name AS decided_by_name
             FROM certification_campaign_item i
             JOIN system_account sa ON sa.id = i.system_account_id
             JOIN application a ON a.id = sa.application_id
             JOIN entitlement e ON e.id = i.entitlement_id
             LEFT JOIN person p ON p.id = sa.person_id
             LEFT JOIN person r ON r.id = i.reviewer_person_id
             LEFT JOIN app_user db ON db.id = i.decided_by_user_id
             WHERE $where
             ORDER BY i.decision = 'pending' DESC, i.id
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    public static function countItemsFor(int $campaignId, array $filters = []): int
    {
        $clauses = ['campaign_id = :cid'];
        $params = ['cid' => $campaignId];
        if (!empty($filters['decision'])) {
            $clauses[] = 'decision = :decision';
            $params['decision'] = $filters['decision'];
        }
        return (int) Db::fetchValue('SELECT COUNT(*) FROM certification_campaign_item WHERE ' . implode(' AND ', $clauses), $params);
    }

    /** Pending (and optionally all) items assigned to one reviewer, across every active campaign. */
    public static function reviewQueueFor(int $reviewerPersonId, bool $pendingOnly = true, int $limit = 100, int $offset = 0): array
    {
        $clauses = ['i.reviewer_person_id = :pid', "c.status = 'active'"];
        $params = ['pid' => $reviewerPersonId];
        if ($pendingOnly) {
            $clauses[] = "i.decision = 'pending'";
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT i.*, c.name AS campaign_name, c.due_at,
                    sa.external_account_id, sa.username, a.name AS application_name,
                    e.name AS entitlement_name, e.is_privileged, e.risk_level,
                    p.display_name AS person_name
             FROM certification_campaign_item i
             JOIN certification_campaign c ON c.id = i.campaign_id
             JOIN system_account sa ON sa.id = i.system_account_id
             JOIN application a ON a.id = sa.application_id
             JOIN entitlement e ON e.id = i.entitlement_id
             LEFT JOIN person p ON p.id = sa.person_id
             WHERE $where
             ORDER BY c.due_at NULLS LAST, i.id
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    public static function countReviewQueueFor(int $reviewerPersonId, bool $pendingOnly = true): int
    {
        $clauses = ['i.reviewer_person_id = :pid', "c.status = 'active'"];
        $params = ['pid' => $reviewerPersonId];
        if ($pendingOnly) {
            $clauses[] = "i.decision = 'pending'";
        }
        return (int) Db::fetchValue(
            'SELECT COUNT(*) FROM certification_campaign_item i JOIN certification_campaign c ON c.id = i.campaign_id WHERE ' . implode(' AND ', $clauses),
            $params
        );
    }

    /**
     * Record a reviewer's decision. $reviewerPersonId is the acting user's
     * OWN person_id — the caller (controller) must verify the item is
     * actually assigned to them before calling this; this method itself
     * re-checks it too, so there is no path to deciding someone else's
     * review item even if a caller forgets the check.
     */
    public static function decide(int $itemId, int $reviewerPersonId, string $decision, ?string $note, int $decidedByUserId): void
    {
        if (!in_array($decision, ['approved', 'revoked'], true)) {
            throw new \InvalidArgumentException('Invalid decision.');
        }
        $item = Db::fetchOne('SELECT * FROM certification_campaign_item WHERE id = :id AND reviewer_person_id = :pid', ['id' => $itemId, 'pid' => $reviewerPersonId]);
        if ($item === null) {
            throw new \RuntimeException('This review item is not assigned to you.');
        }
        if ($item['decision'] !== 'pending') {
            throw new \RuntimeException('This item has already been decided.');
        }

        Db::query(
            'UPDATE certification_campaign_item SET decision = :decision, decision_note = :note,
                decided_by_user_id = :by, decided_at = NOW() WHERE id = :id',
            ['id' => $itemId, 'decision' => $decision, 'note' => $note, 'by' => $decidedByUserId]
        );
        if ($decision === 'approved' && $item['entitlement_assignment_id'] !== null) {
            Db::query('UPDATE entitlement_assignment SET last_certified_at = NOW() WHERE id = :id', ['id' => (int) $item['entitlement_assignment_id']]);
        }
        if ($decision === 'revoked') {
            // Creating the TRACKING row is safe to automate — it's
            // bookkeeping, not an access change. See RemediationTasks's
            // own doc comment for why resolving it is still a manual step.
            RemediationTasks::createFromCampaignRevoke(
                $itemId, (int) $item['system_account_id'], (int) $item['entitlement_id'], $note, $decidedByUserId
            );
        }
        Audit::log(
            'campaign.item.decide',
            'certification_campaign_item#' . $itemId,
            ['decision' => 'pending'],
            ['decision' => $decision, 'note' => $note],
            $note,
            $decidedByUserId
        );
    }

    public static function complete(int $campaignId, ?int $actorId): void
    {
        $before = self::get($campaignId);
        Db::update('certification_campaign', ['status' => 'completed'], ['id' => $campaignId]);
        Audit::log('campaign.complete', 'certification_campaign#' . $campaignId, $before, ['status' => 'completed'], null, $actorId);
    }

    public static function cancel(int $campaignId, ?int $actorId): void
    {
        $before = self::get($campaignId);
        Db::update('certification_campaign', ['status' => 'cancelled'], ['id' => $campaignId]);
        Audit::log('campaign.cancel', 'certification_campaign#' . $campaignId, $before, ['status' => 'cancelled'], null, $actorId);
    }
}
