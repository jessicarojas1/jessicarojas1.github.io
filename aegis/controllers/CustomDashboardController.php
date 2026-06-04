<?php
declare(strict_types=1);

class CustomDashboardController {

    public function index(): void {
        Auth::requireAuth();
        $uid = Auth::id();
        $dashboards = Database::fetchAll(
            "SELECT cd.*, u.name AS owner_name, COUNT(w.id) AS widget_count
             FROM custom_dashboards cd
             LEFT JOIN users u ON u.id = cd.owner_id
             LEFT JOIN dashboard_widgets w ON w.dashboard_id = cd.id
             WHERE cd.owner_id = ? OR cd.is_shared = TRUE
             GROUP BY cd.id, u.name
             ORDER BY cd.updated_at DESC",
            [$uid]
        );
        $pageTitle    = 'Custom Dashboards';
        $activeModule = 'dashboards';
        $breadcrumbs  = [['Dashboards', null]];
        ob_start();
        require AEGIS_ROOT . '/views/dashboards/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = trim(Security::sanitizeInput($_POST['name'] ?? ''));
        if (!$name) { $_SESSION['flash_error'] = 'Dashboard name is required.'; header('Location: /dashboards'); return; }
        $id = Database::insert('custom_dashboards', [
            'name'        => $name,
            'description' => Security::sanitizeInput($_POST['description'] ?? ''),
            'is_shared'   => isset($_POST['is_shared']) ? true : false,
            'owner_id'    => Auth::id(),
        ]);
        $_SESSION['flash_success'] = 'Dashboard created.';
        header("Location: /dashboards/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $dashboard = Database::fetchOne(
            "SELECT cd.*, u.name AS owner_name FROM custom_dashboards cd LEFT JOIN users u ON u.id=cd.owner_id WHERE cd.id=?", [$id]
        );
        if (!$dashboard) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }
        if ($dashboard['owner_id'] !== Auth::id() && !$dashboard['is_shared']) {
            http_response_code(403); return;
        }

        $widgets = Database::fetchAll(
            "SELECT * FROM dashboard_widgets WHERE dashboard_id=? ORDER BY position, id", [$id]
        );

        foreach ($widgets as &$w) {
            $cfg = json_decode($w['config'] ?: '{}', true);
            $w['config'] = $cfg;
            $w['data']   = $this->fetchWidgetData($w['widget_type'], $cfg);
        }
        unset($w);

        $pageTitle    = Security::h($dashboard['name']);
        $activeModule = 'dashboards';
        $breadcrumbs  = [['Dashboards', '/dashboards'], [$dashboard['name'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/dashboards/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function addWidget(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $dashboard = Database::fetchOne("SELECT owner_id FROM custom_dashboards WHERE id=?", [$id]);
        if (!$dashboard || $dashboard['owner_id'] !== Auth::id()) { http_response_code(403); return; }

        $validTypes = ['stat_card','recent_risks','recent_incidents','compliance_summary','overdue_items','open_issues'];
        $type = $_POST['widget_type'] ?? 'stat_card';
        if (!in_array($type, $validTypes, true)) { http_response_code(422); return; }

        $config = [];
        if ($type === 'stat_card') $config['metric'] = $_POST['metric'] ?? 'open_risks';

        $maxPos = Database::fetchOne("SELECT COALESCE(MAX(position),0) AS m FROM dashboard_widgets WHERE dashboard_id=?", [$id]);
        Database::insert('dashboard_widgets', [
            'dashboard_id' => $id,
            'widget_type'  => $type,
            'title'        => Security::sanitizeInput($_POST['title'] ?? ucwords(str_replace('_', ' ', $type))),
            'config'       => json_encode($config),
            'position'     => (int)($maxPos['m'] ?? 0) + 1,
        ]);
        Database::query("UPDATE custom_dashboards SET updated_at=NOW() WHERE id=?", [$id]);
        header("Location: /dashboards/{$id}");
    }

    public function removeWidget(int $id, int $widgetId): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $dashboard = Database::fetchOne("SELECT owner_id FROM custom_dashboards WHERE id=?", [$id]);
        if (!$dashboard || $dashboard['owner_id'] !== Auth::id()) { http_response_code(403); return; }
        Database::query("DELETE FROM dashboard_widgets WHERE id=? AND dashboard_id=?", [$widgetId, $id]);
        header("Location: /dashboards/{$id}");
    }

    public function delete(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $dashboard = Database::fetchOne("SELECT owner_id FROM custom_dashboards WHERE id=?", [$id]);
        if (!$dashboard || $dashboard['owner_id'] !== Auth::id()) { http_response_code(403); return; }
        Database::query("DELETE FROM custom_dashboards WHERE id=?", [$id]);
        $_SESSION['flash_success'] = 'Dashboard deleted.';
        header('Location: /dashboards');
    }

    private function fetchWidgetData(string $type, array $config): mixed {
        try {
            switch ($type) {
                case 'stat_card':
                    return match($config['metric'] ?? '') {
                        'open_risks'            => Database::fetchOne("SELECT COUNT(*) AS val FROM risks WHERE status='open'")['val'] ?? 0,
                        'non_compliant_controls'=> Database::fetchOne("SELECT COUNT(*) AS val FROM control_implementations WHERE status='non_compliant'")['val'] ?? 0,
                        'open_incidents'        => Database::fetchOne("SELECT COUNT(*) AS val FROM incidents WHERE status NOT IN ('resolved','closed')")['val'] ?? 0,
                        'open_issues'           => Database::fetchOne("SELECT COUNT(*) AS val FROM issues WHERE status='open'")['val'] ?? 0,
                        'overdue_policies'      => Database::fetchOne("SELECT COUNT(*) AS val FROM policies WHERE next_review_date < NOW() AND status='published'")['val'] ?? 0,
                        'pending_approvals'     => Database::fetchOne("SELECT COUNT(*) AS val FROM approval_requests WHERE status='pending'")['val'] ?? 0,
                        default                 => 0,
                    };
                case 'recent_risks':
                    return Database::fetchAll("SELECT id, title, status, inherent_score FROM risks WHERE status='open' ORDER BY inherent_score DESC NULLS LAST LIMIT 5");
                case 'recent_incidents':
                    return Database::fetchAll("SELECT id, incident_number, title, severity, status FROM incidents ORDER BY created_at DESC LIMIT 5");
                case 'compliance_summary':
                    return Database::fetchAll(
                        "SELECT cp.id, cp.name,
                                COUNT(co.id) FILTER (WHERE co.level=2) AS total,
                                COUNT(ci.id) FILTER (WHERE ci.status='compliant') AS compliant
                         FROM compliance_packages cp
                         LEFT JOIN compliance_objectives co ON co.package_id=cp.id
                         LEFT JOIN control_implementations ci ON ci.objective_id=co.id
                         WHERE cp.is_active=TRUE GROUP BY cp.id ORDER BY cp.name LIMIT 5"
                    );
                case 'open_issues':
                    return Database::fetchAll("SELECT id, issue_number, title, severity, status FROM issues WHERE status='open' ORDER BY created_at DESC LIMIT 5");
                default:
                    return null;
            }
        } catch (Throwable) {
            return null;
        }
    }
}
