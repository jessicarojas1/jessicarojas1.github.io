<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Directly granting a NEW entitlement to an existing account — the shared
 * engine behind every "admin/supervisor adds access right now" surface in
 * this app (a campaign review's "+ Add entitlement", the Identity detail
 * page's access editor, and the Access Matrix's "+ Grant Access"). Pulled
 * out of Campaigns.php once a second, non-campaign caller needed the exact
 * same logic — see that class's own (still-present) grantEntitlement()
 * wrapper for the original reasoning this was extracted from.
 *
 * Creating the entitlement_assignment row directly (rather than opening an
 * access_request) is deliberate: the caller already IS the authorized
 * approver for this account (admin, or the account holder's supervisor —
 * see Authorize::isInReportingChain()), so routing it through a second
 * approval step would be theater, not governance. source='manual' carries
 * the same meaning it already does everywhere else in this schema — see
 * AccessRequests.php's own doc comment.
 *
 * There is deliberately NO matching "revoke/delete" method here. Removing
 * access in this app always means flagging a RemediationTasks row
 * (task_type='remove_access'), never deleting the entitlement_assignment
 * record — the same invariant Campaigns.php's "revoked" decision and
 * CsvImport's add/update-only sync both already hold, for the identical
 * reason: no connector in this build claims revoke_access, so there is no
 * live system Verity could actually push a removal to, and a 'manual'
 * assignment this engine created is still a real claim about access that
 * was genuinely granted — deleting it on a later "undo" would misrepresent
 * that the grant never happened. See RemediationTasks::create() with
 * task_type='remove_access' for how every revoke path in this app works.
 */
final class EntitlementAssignments
{
    /** @return array{assignment_id:int} */
    public static function grant(int $systemAccountId, int $entitlementId, int $actingUserId, ?int $actingPersonId, bool $actingIsAdmin, ?string $note = null): array
    {
        $account = Db::fetchOne('SELECT id, person_id, application_id FROM system_account WHERE id = :id', ['id' => $systemAccountId]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        if (!$actingIsAdmin) {
            $accountPersonId = $account['person_id'] !== null ? (int) $account['person_id'] : null;
            if ($actingPersonId === null || $accountPersonId === null || !Authorize::isInReportingChain($actingPersonId, $accountPersonId)) {
                throw new \RuntimeException('You are not authorized to add access for this account.');
            }
        }

        $entitlement = Db::fetchOne('SELECT id, application_id FROM entitlement WHERE id = :id', ['id' => $entitlementId]);
        if ($entitlement === null) {
            throw new \RuntimeException('Entitlement not found.');
        }
        if ((int) $entitlement['application_id'] !== (int) $account['application_id']) {
            throw new \InvalidArgumentException('That entitlement does not belong to this account\'s application.');
        }
        $already = Db::fetchOne(
            "SELECT id FROM entitlement_assignment WHERE system_account_id = :sa AND entitlement_id = :e AND assignment_type = 'direct'",
            ['sa' => $systemAccountId, 'e' => $entitlementId]
        );
        if ($already !== null) {
            throw new \RuntimeException('This account already has that entitlement.');
        }

        $row = Db::fetchOne(
            "INSERT INTO entitlement_assignment (system_account_id, entitlement_id, assignment_type, source)
             VALUES (:sa, :e, 'direct', 'manual')
             RETURNING id",
            ['sa' => $systemAccountId, 'e' => $entitlementId]
        );
        $assignmentId = (int) $row['id'];
        Audit::log(
            'entitlement.grant',
            'entitlement_assignment#' . $assignmentId,
            null,
            ['system_account_id' => $systemAccountId, 'entitlement_id' => $entitlementId, 'note' => $note],
            $note,
            $actingUserId
        );
        return ['assignment_id' => $assignmentId];
    }
}
