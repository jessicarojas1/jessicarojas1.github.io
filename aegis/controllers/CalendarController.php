<?php
class CalendarController {

    public function index(): void {
        Auth::requirePermission('risk.view');

        $today        = date('Y-m-d');
        $month        = (int)($_GET['month'] ?? date('n'));
        $year         = (int)($_GET['year'] ?? date('Y'));

        // Clamp month/year to valid ranges
        if ($month < 1)  { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }
        if ($year < 2000) $year = 2000;
        if ($year > 2099) $year = 2099;

        $daysInMonth    = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        $firstDayOfWeek = (int)date('w', mktime(0, 0, 0, $month, 1, $year)); // 0=Sun

        $events = self::getEvents($year, $month);

        $pageTitle    = 'Compliance Calendar';
        $activeModule = 'calendar';
        $breadcrumbs  = [['Calendar', null]];

        ob_start();
        require AEGIS_ROOT . '/views/calendar/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function feed(): void {
        Auth::requirePermission('risk.view');

        $month = (int)($_GET['month'] ?? date('n'));
        $year  = (int)($_GET['year'] ?? date('Y'));

        if ($month < 1 || $month > 12) { $month = (int)date('n'); }
        if ($year < 2000 || $year > 2099) { $year = (int)date('Y'); }

        $eventsByDate = self::getEvents($year, $month);

        $flat = [];
        foreach ($eventsByDate as $date => $dayEvents) {
            foreach ($dayEvents as $ev) {
                $flat[] = [
                    'date'  => $date,
                    'title' => $ev['title'],
                    'type'  => $ev['type'],
                    'url'   => $ev['url'],
                ];
            }
        }

        header('Content-Type: application/json');
        echo json_encode($flat);
    }

    /** Public + static so it is unit/integration-testable; not a routed action. */
    public static function getEvents(int $year, int $month): array {
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $events = [];

        // 1. Controls due
        $controls = Database::fetchAll(
            "SELECT ci.id, ci.due_date, ci.objective_id, co.code, co.title, 'control' as type
             FROM control_implementations ci
             JOIN compliance_objectives co ON co.id = ci.objective_id
             WHERE ci.status NOT IN ('compliant','not_applicable')
               AND ci.due_date BETWEEN ? AND ?",
            [$start, $end]
        );
        foreach ($controls as $row) {
            $date = substr($row['due_date'], 0, 10);
            $events[$date][] = [
                'id'    => $row['id'],
                'title' => ($row['code'] ? $row['code'] . ': ' : '') . $row['title'],
                'type'  => 'control',
                'url'   => '/compliance/' . (int)$row['objective_id'],
            ];
        }

        // 2. Policy reviews
        $policies = Database::fetchAll(
            "SELECT p.id, p.next_review_date as due_date, p.title, 'policy_review' as type
             FROM policies p
             WHERE p.status = 'published'
               AND p.next_review_date BETWEEN ? AND ?",
            [$start, $end]
        );
        foreach ($policies as $row) {
            $date = substr($row['due_date'], 0, 10);
            $events[$date][] = [
                'id'    => $row['id'],
                'title' => $row['title'],
                'type'  => 'policy_review',
                'url'   => '/policy/' . (int)$row['id'],
            ];
        }

        // 3. Audit schedules
        $audits = Database::fetchAll(
            "SELECT a.id, a.scheduled_date as due_date, a.name as title, 'audit' as type
             FROM audits a
             WHERE a.status != 'completed'
               AND a.scheduled_date BETWEEN ? AND ?",
            [$start, $end]
        );
        foreach ($audits as $row) {
            $date = substr($row['due_date'], 0, 10);
            $events[$date][] = [
                'id'    => $row['id'],
                'title' => $row['title'],
                'type'  => 'audit',
                'url'   => '/audit/' . (int)$row['id'],
            ];
        }

        // 4. Risk treatment due dates
        $treatments = Database::fetchAll(
            "SELECT rt.id, rt.due_date, rt.risk_id, CONCAT('Treatment: ', r.title) as title, 'treatment' as type
             FROM risk_treatments rt
             JOIN risks r ON r.id = rt.risk_id
             WHERE rt.status != 'completed'
               AND rt.due_date BETWEEN ? AND ?",
            [$start, $end]
        );
        foreach ($treatments as $row) {
            $date = substr($row['due_date'], 0, 10);
            $events[$date][] = [
                'id'    => $row['id'],
                'title' => $row['title'],
                'type'  => 'treatment',
                'url'   => '/risk/' . (int)$row['risk_id'],
            ];
        }

        // 5. Control re-tests (latest control_tests.next_test_date per objective)
        foreach (Database::fetchAll(
            "SELECT co.package_id, co.code, co.title, latest.next_test_date AS due_date
             FROM compliance_objectives co
             JOIN control_implementations ci ON ci.objective_id = co.id
             JOIN LATERAL (
               SELECT ct.next_test_date FROM control_tests ct
               WHERE ct.objective_id = co.id AND ct.next_test_date IS NOT NULL
               ORDER BY ct.id DESC LIMIT 1
             ) latest ON TRUE
             WHERE latest.next_test_date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['package_id'],
                'title' => ($row['code'] ? $row['code'] . ': ' : '') . $row['title'],
                'type'  => 'control_retest',
                'url'   => '/compliance/' . (int)$row['package_id'],
            ];
        }

        // 6. Audit finding remediation deadlines
        foreach (Database::fetchAll(
            "SELECT id, deadline AS due_date, finding_number, title
             FROM audit_findings
             WHERE status NOT IN ('closed','resolved','risk_accepted')
               AND deadline BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['id'],
                'title' => ($row['finding_number'] ? $row['finding_number'] . ': ' : '') . $row['title'],
                'type'  => 'finding',
                'url'   => '/audit-findings/' . (int)$row['id'],
            ];
        }

        // 7. Vendor certification expiries
        foreach (Database::fetchAll(
            "SELECT vc.expiry_date AS due_date, vc.certification_type, v.id AS vendor_id, v.name AS vendor_name
             FROM vendor_certifications vc
             JOIN vendors v ON v.id = vc.vendor_id
             WHERE vc.status = 'active' AND vc.expiry_date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['vendor_id'],
                'title' => $row['certification_type'] . ' — ' . $row['vendor_name'],
                'type'  => 'vendor_cert',
                'url'   => '/vendor/' . (int)$row['vendor_id'],
            ];
        }

        // 8. Vendor contract end dates
        foreach (Database::fetchAll(
            "SELECT vc.end_date AS due_date, vc.title, v.id AS vendor_id
             FROM vendor_contracts vc
             JOIN vendors v ON v.id = vc.vendor_id
             WHERE vc.status = 'active' AND vc.end_date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['vendor_id'],
                'title' => $row['title'],
                'type'  => 'vendor_contract',
                'url'   => '/vendor/' . (int)$row['vendor_id'],
            ];
        }

        // 9. Policy attestation campaign due dates
        foreach (Database::fetchAll(
            "SELECT id, due_date, title
             FROM policy_attestation_campaigns
             WHERE is_active = TRUE AND due_date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['id'],
                'title' => $row['title'],
                'type'  => 'attestation',
                'url'   => '/policy/attestations',
            ];
        }

        // 10. SSP review dates
        foreach (Database::fetchAll(
            "SELECT id, next_review_date AS due_date, title
             FROM ssp_plans
             WHERE next_review_date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['id'],
                'title' => $row['title'],
                'type'  => 'ssp_review',
                'url'   => '/ssp/' . (int)$row['id'],
            ];
        }

        // 11. Asset annual reviews (last_reviewed or creation + 365 days)
        foreach (Database::fetchAll(
            "SELECT id, name,
                    (COALESCE(last_reviewed, created_at::date) + INTERVAL '365 days')::date AS due_date
             FROM assets
             WHERE status <> 'decommissioned'
               AND (COALESCE(last_reviewed, created_at::date) + INTERVAL '365 days')::date BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['id'],
                'title' => $row['name'],
                'type'  => 'asset_review',
                'url'   => '/assets/' . (int)$row['id'],
            ];
        }

        // 12. KRI next measurement due (last reading or creation + cadence window)
        foreach (Database::fetchAll(
            "SELECT k.id, k.title,
                    (COALESCE(latest.recorded_at, k.created_at::date)
                       + (CASE k.frequency WHEN 'daily' THEN 1 WHEN 'weekly' THEN 7
                            WHEN 'monthly' THEN 31 WHEN 'quarterly' THEN 92 ELSE 31 END || ' days')::interval)::date AS due_date
             FROM kris k
             LEFT JOIN LATERAL (
               SELECT kv.recorded_at FROM kri_values kv WHERE kv.kri_id = k.id
               ORDER BY kv.recorded_at DESC, kv.id DESC LIMIT 1
             ) latest ON TRUE
             WHERE k.is_active = TRUE
               AND (COALESCE(latest.recorded_at, k.created_at::date)
                       + (CASE k.frequency WHEN 'daily' THEN 1 WHEN 'weekly' THEN 7
                            WHEN 'monthly' THEN 31 WHEN 'quarterly' THEN 92 ELSE 31 END || ' days')::interval)::date
                   BETWEEN ? AND ?",
            [$start, $end]
        ) as $row) {
            $events[substr($row['due_date'], 0, 10)][] = [
                'id'    => (int)$row['id'],
                'title' => $row['title'],
                'type'  => 'kri_measurement',
                'url'   => '/kris/' . (int)$row['id'],
            ];
        }

        return $events;
    }
}
