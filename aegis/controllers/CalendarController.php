<?php
class CalendarController {

    public function index(): void {
        Auth::requireAuth();

        $today        = date('Y-m-d');
        $month        = (int)($_GET['month'] ?? date('n'));
        $year         = (int)($_GET['year'] ?? date('Y'));

        // Clamp month/year to valid ranges
        if ($month < 1)  { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }
        if ($year < 2000) $year = 2000;
        if ($year > 2099) $year = 2099;

        $daysInMonth    = cal_days_in_month(CAL_GREGORIAN, $month, $year);
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
        Auth::requireAuth();

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

    private static function getEvents(int $year, int $month): array {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
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

        return $events;
    }
}
