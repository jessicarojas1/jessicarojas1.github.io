<?php
declare(strict_types=1);

class ThreatController {

    public function index(): void {
        Auth::requirePermission('threat.view');
        $filter   = Security::sanitizeInput($_GET['category'] ?? '');
        $statusF  = Security::sanitizeInput($_GET['status'] ?? '');
        $params   = [];
        $where    = [];
        if ($filter) { $where[] = 't.category = ?'; $params[] = $filter; }
        if ($statusF) { $where[] = 't.status = ?'; $params[] = $statusF; }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $threats = Database::fetchAll(
            "SELECT t.*, u.name as owner_name,
                    COUNT(trl.risk_id) as linked_risks,
                    (t.likelihood * t.impact) as threat_score
             FROM threats t
             LEFT JOIN users u ON u.id = t.owner_id
             LEFT JOIN threat_risk_links trl ON trl.threat_id = t.id
             {$whereStr}
             GROUP BY t.id, u.name ORDER BY (t.likelihood * t.impact) DESC NULLS LAST, t.title",
            $params
        );
        // Category stats
        $stats = Database::fetchAll(
            "SELECT category, COUNT(*) as cnt, AVG(likelihood*impact) as avg_score
             FROM threats GROUP BY category ORDER BY category"
        );
        $pageTitle    = 'Threat Register';
        $activeModule = 'threats';
        $breadcrumbs  = [['Threat Register', null]];
        ob_start();
        require AEGIS_ROOT . '/views/threat/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('threat.create');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'New Threat';
        $activeModule = 'threats';
        $breadcrumbs  = [['Threat Register', '/threats'], ['New Threat', null]];
        ob_start();
        require AEGIS_ROOT . '/views/threat/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('threat.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $title    = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $category = Security::sanitizeInput($_POST['category'] ?? 'technology');
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /threats/create'); return;
        }
        $validCats = ['people','process','technology','natural','regulatory','financial'];
        if (!in_array($category, $validCats, true)) $category = 'technology';
        $likelihood = (int)($_POST['likelihood'] ?? 3);
        $impact     = (int)($_POST['impact'] ?? 3);
        $likelihood = max(1, min(5, $likelihood));
        $impact     = max(1, min(5, $impact));
        // Generate threat number from next sequential ID
        $maxRow       = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM threats");
        $threatNumber = 'THR-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $id = Database::insert('threats', [
            'threat_number' => $threatNumber,
            'title'         => $title,
            'category'      => $category,
            'description'   => Security::sanitizeInput($_POST['description'] ?? ''),
            'likelihood'    => $likelihood,
            'impact'        => $impact,
            'status'        => 'active',
            'source'        => Security::sanitizeInput($_POST['source'] ?? ''),
            'mitigations'   => Security::sanitizeInput($_POST['mitigations'] ?? ''),
            'owner_id'      => (int)($_POST['owner_id'] ?? 0) ?: null,
            'created_by'    => Auth::id(),
        ]);
        Auth::log('threat_created', 'threats', $id, ['threat_number' => $threatNumber, 'title' => $title]);
        $_SESSION['flash_success'] = "Threat {$threatNumber} added to register.";
        header("Location: /threats/{$id}");
    }

    public function view(string $id): void {
        Auth::requirePermission('threat.view');
        $id = (int)$id;
        $threat = Database::fetchOne(
            "SELECT t.*, u.name as owner_name, cb.name as created_by_name
             FROM threats t
             LEFT JOIN users u ON u.id = t.owner_id
             LEFT JOIN users cb ON cb.id = t.created_by
             WHERE t.id=?", [$id]
        );
        if (!$threat) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $linkedRisks = Database::fetchAll(
            "SELECT r.id, r.title, r.status, r.likelihood, r.impact, r.inherent_score
             FROM risks r JOIN threat_risk_links trl ON trl.risk_id = r.id
             WHERE trl.threat_id=? ORDER BY r.inherent_score DESC",
            [$id]
        );
        // Risks not yet linked (for the link form)
        $unlinkdRisks = Database::fetchAll(
            "SELECT id, title FROM risks WHERE status != 'closed'
             AND id NOT IN (SELECT risk_id FROM threat_risk_links WHERE threat_id=?)
             ORDER BY title LIMIT 100",
            [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = $threat['title'];
        $activeModule = 'threats';
        $breadcrumbs  = [['Threat Register', '/threats'], [$threat['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/threat/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('threat.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id     = (int)$id;
        $threat = Database::fetchOne("SELECT id FROM threats WHERE id=?", [$id]);
        if (!$threat) { http_response_code(404); return; }
        $validCats = ['people','process','technology','natural','regulatory','financial'];
        $validStatus = ['active','mitigated','accepted','retired'];
        $category = in_array($_POST['category'] ?? '', $validCats, true) ? $_POST['category'] : 'technology';
        $status   = in_array($_POST['status'] ?? '', $validStatus, true) ? $_POST['status'] : 'active';
        $likelihood = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact     = max(1, min(5, (int)($_POST['impact'] ?? 3)));
        Database::query(
            "UPDATE threats SET title=?,category=?,description=?,likelihood=?,impact=?,status=?,
             source=?,mitigations=?,owner_id=?,updated_at=NOW() WHERE id=?",
            [
                trim(Security::sanitizeInput($_POST['title'] ?? '')),
                $category,
                Security::sanitizeInput($_POST['description'] ?? ''),
                $likelihood, $impact, $status,
                Security::sanitizeInput($_POST['source'] ?? ''),
                Security::sanitizeInput($_POST['mitigations'] ?? ''),
                (int)($_POST['owner_id'] ?? 0) ?: null,
                $id,
            ]
        );
        Auth::log('threat_updated', 'threats', $id, ['status' => $status]);
        $_SESSION['flash_success'] = 'Threat updated.';
        header("Location: /threats/{$id}");
    }

    public function linkRisk(string $id): void {
        Auth::requirePermission('threat.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id     = (int)$id;
        $riskId = (int)($_POST['risk_id'] ?? 0);
        if (!$riskId) { header("Location: /threats/{$id}"); return; }
        try {
            Database::insert('threat_risk_links', ['threat_id' => $id, 'risk_id' => $riskId]);
            $_SESSION['flash_success'] = 'Risk linked.';
        } catch (Throwable) {
            $_SESSION['flash_error'] = 'Already linked.';
        }
        header("Location: /threats/{$id}");
    }

    public function unlinkRisk(string $id, string $riskId): void {
        Auth::requirePermission('threat.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query(
            "DELETE FROM threat_risk_links WHERE threat_id=? AND risk_id=?",
            [(int)$id, (int)$riskId]
        );
        $_SESSION['flash_success'] = 'Risk unlinked.';
        header("Location: /threats/{$id}");
    }
}
