<?php
declare(strict_types=1);

/**
 * CalendarController — a month grid of everything with a due/review date:
 * open tasks (task.due_date), document reviews (document.review_date) and
 * pending approvals (approval_requests.due_at). Each source is only queried
 * when the viewer holds the matching permission.
 */
class CalendarController {

    public function index(): void {
        Auth::requireAuth();

        // Selected month, validated to YYYY-MM; default to the current month.
        $m = (string)($_GET['m'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }
        $first = DateTime::createFromFormat('Y-m-d', $m . '-01') ?: new DateTime('first day of this month');
        $first->setTime(0, 0, 0);

        $monthStart = (clone $first)->modify('first day of this month');
        $monthEnd   = (clone $first)->modify('last day of this month');
        // Grid spans whole weeks (Sun..Sat) covering the month.
        $gridStart  = (clone $monthStart)->modify('-' . ((int)$monthStart->format('w')) . ' days');
        $gridEnd    = (clone $monthEnd)->modify('+' . (6 - (int)$monthEnd->format('w')) . ' days');
        $from = $gridStart->format('Y-m-d');
        $to   = $gridEnd->format('Y-m-d');

        $events = []; // 'Y-m-d' => list of events
        $add = function (string $date, array $ev) use (&$events) {
            $events[$date][] = $ev;
        };
        $today = date('Y-m-d');

        // --- Tasks ---
        if (Auth::can('task.view') || Auth::id() !== null) {
            $params = [$from, $to];
            $scope = '';
            if (!Auth::can('task.view')) { $scope = ' AND (t.assigned_to = ? OR t.created_by = ?)'; $params[] = Auth::id(); $params[] = Auth::id(); }
            $rows = Database::fetchAll(
                "SELECT t.id, t.title, t.due_date, t.status
                 FROM tasks t
                 WHERE t.due_date IS NOT NULL AND t.due_date BETWEEN ? AND ?
                   AND t.status NOT IN ('done','completed','cancelled')" . $scope,
                $params
            );
            foreach ($rows as $r) {
                $d = substr((string)$r['due_date'], 0, 10);
                $add($d, ['type' => 'task', 'label' => $r['title'], 'url' => '/tasks/' . (int)$r['id'], 'overdue' => $d < $today]);
            }
        }

        // --- Document reviews ---
        if (Auth::can('document.view')) {
            $rows = Database::fetchAll(
                "SELECT id, title, document_code, review_date FROM documents
                 WHERE review_date IS NOT NULL AND review_date BETWEEN ? AND ? AND status='published'",
                [$from, $to]
            );
            foreach ($rows as $r) {
                $d = substr((string)$r['review_date'], 0, 10);
                $add($d, ['type' => 'review', 'label' => 'Review: ' . $r['document_code'], 'url' => '/documents/' . (int)$r['id'], 'overdue' => $d < $today]);
            }
        }

        // --- Pending approvals ---
        if (Auth::can('approval.view')) {
            $rows = Database::fetchAll(
                "SELECT id, title, due_at FROM approval_requests
                 WHERE due_at IS NOT NULL AND due_at::date BETWEEN ? AND ? AND status='pending'",
                [$from, $to]
            );
            foreach ($rows as $r) {
                $d = substr((string)$r['due_at'], 0, 10);
                $add($d, ['type' => 'approval', 'label' => 'Approval: ' . $r['title'], 'url' => '/approvals/' . (int)$r['id'], 'overdue' => $d < $today]);
            }
        }

        // Build the week-by-week grid of day cells.
        $weeks = []; $cur = clone $gridStart; $week = [];
        while ($cur <= $gridEnd) {
            $ds = $cur->format('Y-m-d');
            $week[] = [
                'date'       => $ds,
                'day'        => (int)$cur->format('j'),
                'in_month'   => $cur->format('Y-m') === $monthStart->format('Y-m'),
                'is_today'   => $ds === $today,
                'events'     => $events[$ds] ?? [],
            ];
            if (count($week) === 7) { $weeks[] = $week; $week = []; }
            $cur->modify('+1 day');
        }

        $prev  = (clone $monthStart)->modify('-1 month')->format('Y-m');
        $next  = (clone $monthStart)->modify('+1 month')->format('Y-m');
        $label = $monthStart->format('F Y');
        $totalEvents = array_sum(array_map('count', $events));
        require PALADIN_ROOT . '/views/calendar/index.php';
    }
}
