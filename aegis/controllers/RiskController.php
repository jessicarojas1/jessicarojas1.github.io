<?php
class RiskController {
    public function index(): void {
        Auth::requireAuth();

        $status   = Security::sanitizeInput($_GET['status'] ?? '');
        $category = Security::sanitizeInput($_GET['category'] ?? '');
        $level    = Security::sanitizeInput($_GET['level'] ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($status)   { $where[] = 'r.status = ?'; $params[] = $status; }
        if ($category) { $where[] = 'r.category_id = ?'; $params[] = (int)$category; }
        if ($level === 'critical') { $where[] = 'r.inherent_score > 14'; }
        elseif ($level === 'high')   { $where[] = 'r.inherent_score BETWEEN 10 AND 14'; }
        elseif ($level === 'medium') { $where[] = 'r.inherent_score BETWEEN 5 AND 9'; }
        elseif ($level === 'low')    { $where[] = 'r.inherent_score <= 4'; }

        $whereSQL = implode(' AND ', $where);

        $risks = Database::fetchAll(
            "SELECT r.*, rc.name as category_name, rc.color as category_color, u.name as owner_name
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             LEFT JOIN users u ON r.owner_id = u.id
             WHERE {$whereSQL}
             ORDER BY r.inherent_score DESC, r.created_at DESC",
            $params
        );

        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) as total,
               COUNT(*) FILTER (WHERE inherent_score > 14) as critical,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14) as high,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 5 AND 9) as medium,
               COUNT(*) FILTER (WHERE inherent_score <= 4) as low,
               COUNT(*) FILTER (WHERE status = 'open') as open,
               COUNT(*) FILTER (WHERE status = 'accepted') as accepted,
               COUNT(*) FILTER (WHERE status = 'mitigated') as mitigated
             FROM risks"
        );

        require AEGIS_ROOT . '/views/risk/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('risk.write');
        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/risk/create.php';
    }

    public function create(): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $title       = Security::sanitizeInput($_POST['title'] ?? '');
        $desc        = Security::sanitizeInput($_POST['description'] ?? '');
        $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $likelihood  = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact      = max(1, min(5, (int)($_POST['impact'] ?? 3)));
        $treatment   = in_array($_POST['treatment_type'] ?? '', ['mitigate','accept','avoid','transfer','']) ? $_POST['treatment_type'] : null;
        $treatDesc   = Security::sanitizeInput($_POST['treatment_description'] ?? '');
        $ownerId     = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id();
        $reviewDate  = Security::sanitizeInput($_POST['review_date'] ?? '');
        $riskId      = Security::sanitizeInput($_POST['risk_id'] ?? '');

        if (!$title) {
            $_SESSION['risk_error'] = 'Risk title is required.';
            header('Location: /risk/create'); return;
        }

        if (!$riskId) {
            $count = Database::fetchOne("SELECT COUNT(*)+1 as n FROM risks")['n'];
            $riskId = 'RSK-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
        }

        $riskDbId = Database::insert('risks', [
            'title'                => $title,
            'risk_id'              => $riskId,
            'description'          => $desc,
            'category_id'          => $categoryId,
            'likelihood'           => $likelihood,
            'impact'               => $impact,
            'treatment_type'       => $treatment,
            'treatment_description' => $treatDesc,
            'owner_id'             => $ownerId,
            'review_date'          => $reviewDate ?: null,
            'identified_date'      => date('Y-m-d'),
            'created_by'           => Auth::id(),
        ]);

        Auth::log('create_risk', 'risks', $riskDbId);
        header('Location: /risk/' . $riskDbId);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $risk = Database::fetchOne(
            "SELECT r.*, rc.name as category_name, rc.color as category_color,
               u.name as owner_name, u2.name as created_by_name
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             LEFT JOIN users u ON r.owner_id = u.id
             LEFT JOIN users u2 ON r.created_by = u2.id
             WHERE r.id = ?", [$id]
        );
        if (!$risk) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $treatments = Database::fetchAll(
            "SELECT rt.*, u.name as owner_name FROM risk_treatments rt
             LEFT JOIN users u ON u.id = rt.owner_id
             WHERE rt.risk_id = ? ORDER BY rt.created_at DESC", [$id]
        );

        $matrix = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active = TRUE LIMIT 1");
        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        require AEGIS_ROOT . '/views/risk/view.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        $likelihood = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact     = max(1, min(5, (int)($_POST['impact'] ?? 3)));
        $resLikelihood = !empty($_POST['residual_likelihood']) ? max(1, min(5, (int)$_POST['residual_likelihood'])) : null;
        $resImpact     = !empty($_POST['residual_impact']) ? max(1, min(5, (int)$_POST['residual_impact'])) : null;
        $status     = in_array($_POST['status'] ?? '', ['open','accepted','mitigated','closed','transferred']) ? $_POST['status'] : 'open';
        $treatment  = in_array($_POST['treatment_type'] ?? '', ['mitigate','accept','avoid','transfer','']) ? $_POST['treatment_type'] : null;
        $treatDesc  = Security::sanitizeInput($_POST['treatment_description'] ?? '');
        $reviewDate = Security::sanitizeInput($_POST['review_date'] ?? '');

        Database::query(
            "UPDATE risks SET likelihood=?, impact=?, residual_likelihood=?, residual_impact=?, status=?, treatment_type=?, treatment_description=?, review_date=?, updated_at=NOW() WHERE id=?",
            [$likelihood, $impact, $resLikelihood, $resImpact, $status, $treatment, $treatDesc, $reviewDate ?: null, $id]
        );

        if (!empty($_POST['add_treatment'])) {
            $type = Security::sanitizeInput($_POST['treat_type'] ?? 'mitigate');
            $desc = Security::sanitizeInput($_POST['treat_desc'] ?? '');
            if ($desc) {
                Database::insert('risk_treatments', [
                    'risk_id'        => $id,
                    'treatment_type' => $type,
                    'description'    => $desc,
                    'due_date'       => !empty($_POST['treat_due']) ? $_POST['treat_due'] : null,
                    'owner_id'       => Auth::id(),
                ]);
            }
        }

        Auth::log('update_risk', 'risks', $id, ['status' => $status, 'likelihood' => $likelihood, 'impact' => $impact]);
        header('Location: /risk/' . $id . '?saved=1');
    }

    public function delete(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        Database::query("DELETE FROM risks WHERE id = ?", [$id]);
        Auth::log('delete_risk', 'risks', $id);
        header('Location: /risk?deleted=1');
    }

    public function matrix(): void {
        Auth::requireAuth();

        $matrixConfig = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active = TRUE ORDER BY id LIMIT 1");
        $risks = Database::fetchAll(
            "SELECT r.id, r.title, r.risk_id, r.likelihood, r.impact, r.inherent_score, r.status,
               rc.name as category_name, rc.color as category_color
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             WHERE r.status IN ('open','accepted')
             ORDER BY r.inherent_score DESC"
        );

        require AEGIS_ROOT . '/views/risk/matrix.php';
    }

    public function editForm(string $id): void {
        $this->view($id);
    }

    public function roadmap(): void {
        Auth::requireAuth();
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $ownerFilter = !empty($_GET['owner']) ? (int)$_GET['owner'] : null;
        $levelFilter = $_GET['level'] ?? '';
        $statusFilter = $_GET['status'] ?? '';

        $where = ["r.status NOT IN ('closed','accepted')"];
        $params = [];
        if ($ownerFilter) { $where[] = "r.owner_id = ?"; $params[] = $ownerFilter; }
        if ($statusFilter) { $where[] = "r.status = ?"; $params[] = $statusFilter; }
        if ($levelFilter === 'critical') { $where[] = "r.inherent_score >= 20"; }
        elseif ($levelFilter === 'high') { $where[] = "r.inherent_score BETWEEN 15 AND 19"; }
        elseif ($levelFilter === 'medium') { $where[] = "r.inherent_score BETWEEN 8 AND 14"; }
        elseif ($levelFilter === 'low') { $where[] = "r.inherent_score < 8"; }

        $whereClause = implode(' AND ', $where);
        $risks = Database::fetchAll(
            "SELECT r.*, u.name as owner_name
             FROM risks r LEFT JOIN users u ON u.id = r.owner_id
             WHERE {$whereClause}
             ORDER BY r.inherent_score DESC, r.review_date ASC",
            $params
        );

        $pageTitle    = 'Risk Treatment Roadmap';
        $activeModule = 'risk_matrix';
        $breadcrumbs  = [['Risk', '/risk'], ['Treatment Roadmap', null]];
        require AEGIS_ROOT . '/views/risk/roadmap.php';
    }
}
