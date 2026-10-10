<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Enterprise Dashboard KPIs (module 1 / section 19). Every metric here is a
 * real COUNT against stored records — no placeholder numbers. KPIs that
 * depend on a module not yet built (risk scoring, SoD) are deliberately
 * OMITTED rather than shown as zero/fake — a zero would read as "nothing
 * pending" when the true state is "not implemented yet". See
 * OPEN_ITEMS.md for what's missing and why.
 */
final class Dashboard
{
    public static function kpis(): array
    {
        return [
            'total_identities' => (int) Db::fetchValue('SELECT COUNT(*) FROM person'),
            'active_identities' => (int) Db::fetchValue("SELECT COUNT(*) FROM person WHERE employment_status = 'active'"),
            'connected_applications' => (int) Db::fetchValue("SELECT COUNT(*) FROM application WHERE status = 'active'"),
            'discovered_accounts' => (int) Db::fetchValue('SELECT COUNT(*) FROM system_account'),
            'discovered_entitlement_assignments' => (int) Db::fetchValue('SELECT COUNT(*) FROM entitlement_assignment'),
            'privileged_accounts' => (int) Db::fetchValue("SELECT COUNT(*) FROM system_account WHERE account_type = 'privileged'"),
            'unmatched_accounts' => (int) Db::fetchValue('SELECT COUNT(*) FROM system_account WHERE person_id IS NULL'),
            'disabled_accounts' => (int) Db::fetchValue("SELECT COUNT(*) FROM system_account WHERE status = 'disabled'"),
            'privileged_entitlements' => (int) Db::fetchValue('SELECT COUNT(*) FROM entitlement WHERE is_privileged = TRUE'),
            'expired_temporary_access' => (int) Db::fetchValue(
                "SELECT COUNT(*) FROM entitlement_assignment WHERE assignment_type = 'temporary' AND expires_at < NOW()"
            ),
            'terminated_with_enabled_accounts' => (int) Db::fetchValue(
                "SELECT COUNT(DISTINCT sa.id) FROM system_account sa
                 JOIN person p ON p.id = sa.person_id
                 WHERE p.employment_status = 'terminated' AND sa.status = 'enabled'"
            ),
            'active_campaigns' => (int) Db::fetchValue("SELECT COUNT(*) FROM certification_campaign WHERE status = 'active'"),
            'pending_campaign_reviews' => (int) Db::fetchValue(
                "SELECT COUNT(*) FROM certification_campaign_item i
                 JOIN certification_campaign c ON c.id = i.campaign_id
                 WHERE i.decision = 'pending' AND c.status = 'active'"
            ),
            'open_remediation_tasks' => (int) Db::fetchValue("SELECT COUNT(*) FROM remediation_task WHERE status = 'open'"),
            'pending_access_requests' => (int) Db::fetchValue("SELECT COUNT(*) FROM access_request WHERE status = 'pending'"),
        ];
    }

    public static function connectorHealth(): array
    {
        return Db::fetchAll(
            "SELECT c.connection_health, COUNT(*) AS n FROM connector c GROUP BY c.connection_health"
        );
    }

    public static function byDepartment(): array
    {
        return Db::fetchAll(
            "SELECT department, COUNT(*) AS n FROM person WHERE department IS NOT NULL GROUP BY department ORDER BY n DESC"
        );
    }

    public static function byApplication(): array
    {
        return Db::fetchAll(
            'SELECT a.name, COUNT(sa.id) AS account_count
             FROM application a LEFT JOIN system_account sa ON sa.application_id = a.id
             GROUP BY a.name ORDER BY account_count DESC'
        );
    }
}
