<?php
declare(strict_types=1);

class RiskController {

    private const STRATEGIES = ['mitigate', 'accept', 'transfer', 'avoid'];
    private const STATUSES   = ['open', 'in_review', 'monitoring', 'accepted', 'closed', 'transferred'];

    public function index(): void {
        Auth::requireAuth();

        $status   = Security::sanitizeInput($_GET['status']   ?? '');
        $category = Security::sanitizeInput($_GET['category'] ?? '');
        $level    = Security::sanitizeInput($_GET['level']    ?? '');
        $treatment = Security::sanitizeInput($_GET['treatment'] ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($status && in_array($status, self::STATUSES, true)) {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($category) {
            $where[] = 'r.category_id = ?';
            $params[] = (int)$category;
        }
        if ($treatment && in_array($treatment, self::STRATEGIES, true)) {
            $where[] = 'r.treatment_strategies @> ?::jsonb';
            $params[] = json_encode([$treatment]);
        }
        if ($level === 'critical')     { $where[] = 'r.inherent_score > 14'; }
        elseif ($level === 'high')     { $where[] = 'r.inherent_score BETWEEN 10 AND 14'; }
        elseif ($level === 'medium')   { $where[] = 'r.inherent_score BETWEEN 5 AND 9'; }
        elseif ($level === 'low')      { $where[] = 'r.inherent_score <= 4'; }

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
               COUNT(*) FILTER (WHERE inherent_score > 14)          as critical,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14) as high,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 5 AND 9)   as medium,
               COUNT(*) FILTER (WHERE inherent_score <= 4)              as low,
               COUNT(*) FILTER (WHERE status = 'open')        as open,
               COUNT(*) FILTER (WHERE status = 'in_review')   as in_review,
               COUNT(*) FILTER (WHERE status = 'monitoring')  as monitoring,
               COUNT(*) FILTER (WHERE status = 'accepted')    as accepted,
               COUNT(*) FILTER (WHERE status = 'closed')      as closed
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

        $title      = Security::sanitizeInput($_POST['title'] ?? '');
        $desc       = Security::sanitizeInput($_POST['description'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $likelihood = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact     = max(1, min(5, (int)($_POST['impact'] ?? 3)));
        $ownerId    = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id();
        $reviewDate = Security::sanitizeInput($_POST['review_date'] ?? '');
        $riskId     = Security::sanitizeInput($_POST['risk_id'] ?? '');
        $treatDesc  = Security::sanitizeInput($_POST['treatment_description'] ?? '');

        $strategies = array_values(array_filter(
            (array)($_POST['treatment_strategies'] ?? []),
            fn($s) => in_array($s, self::STRATEGIES, true)
        ));

        if (!$title) {
            $_SESSION['risk_error'] = 'Risk title is required.';
            header('Location: /risk/create'); return;
        }

        if (!$riskId) {
            $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM risks");
            $riskId = 'RSK-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);
        }

        $riskDbId = Database::insert('risks', [
            'title'                 => $title,
            'risk_id'               => $riskId,
            'description'           => $desc,
            'category_id'           => $categoryId,
            'likelihood'            => $likelihood,
            'impact'                => $impact,
            'treatment_strategies'  => json_encode($strategies),
            'treatment_type'        => $strategies[0] ?? null,
            'treatment_description' => $treatDesc ?: null,
            'owner_id'              => $ownerId,
            'status'                => 'open',
            'review_date'           => $reviewDate ?: null,
            'identified_date'       => date('Y-m-d'),
            'created_by'            => Auth::id(),
        ]);

        Auth::log('create_risk', 'risks', $riskDbId, ['strategies' => $strategies]);
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

        // Decode treatment strategies
        $risk['treatment_strategies_arr'] = json_decode($risk['treatment_strategies'] ?? '[]', true) ?: [];

        $responseActions = Database::fetchAll(
            "SELECT rt.*, u.name as owner_name FROM risk_treatments rt
             LEFT JOIN users u ON u.id = rt.owner_id
             WHERE rt.risk_id = ? ORDER BY
               CASE rt.status WHEN 'in_progress' THEN 1 WHEN 'planned' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,
               rt.due_date ASC NULLS LAST",
            [$id]
        );

        $matrix     = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active = TRUE LIMIT 1");
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
        $likelihood    = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact        = max(1, min(5, (int)($_POST['impact'] ?? 3)));
        $resLikelihood = !empty($_POST['residual_likelihood']) ? max(1, min(5, (int)$_POST['residual_likelihood'])) : null;
        $resImpact     = !empty($_POST['residual_impact'])     ? max(1, min(5, (int)$_POST['residual_impact']))     : null;
        $status        = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'open';
        $treatDesc     = Security::sanitizeInput($_POST['treatment_description'] ?? '');
        $reviewDate    = Security::sanitizeInput($_POST['review_date'] ?? '');
        $ownerId       = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;

        $strategies = array_values(array_filter(
            (array)($_POST['treatment_strategies'] ?? []),
            fn($s) => in_array($s, self::STRATEGIES, true)
        ));

        Database::query(
            "UPDATE risks SET
               likelihood=?, impact=?,
               residual_likelihood=?, residual_impact=?,
               status=?,
               treatment_strategies=?::jsonb,
               treatment_type=?,
               treatment_description=?,
               owner_id=?,
               review_date=?,
               updated_at=NOW()
             WHERE id=?",
            [
                $likelihood, $impact,
                $resLikelihood, $resImpact,
                $status,
                json_encode($strategies),
                $strategies[0] ?? null,
                $treatDesc ?: null,
                $ownerId,
                $reviewDate ?: null,
                $id,
            ]
        );

        Auth::log('update_risk', 'risks', $id, [
            'status'     => $status,
            'strategies' => $strategies,
            'likelihood' => $likelihood,
            'impact'     => $impact,
        ]);

        $_SESSION['flash_success'] = 'Risk updated.';
        header('Location: /risk/' . $id);
    }

    public function addResponseAction(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id   = (int)$id;
        $type = Security::sanitizeInput($_POST['action_type'] ?? 'mitigate');
        $desc = Security::sanitizeInput($_POST['description'] ?? '');
        $dueDate = Security::sanitizeInput($_POST['due_date'] ?? '');
        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : Auth::id();
        $effort = Security::sanitizeInput($_POST['effort'] ?? '');

        if (!in_array($type, self::STRATEGIES, true)) $type = 'mitigate';

        if (!$desc) {
            $_SESSION['flash_error'] = 'Action description is required.';
            header('Location: /risk/' . $id); return;
        }

        Database::insert('risk_treatments', [
            'risk_id'        => $id,
            'treatment_type' => $type,
            'description'    => $desc,
            'status'         => 'planned',
            'due_date'       => $dueDate ?: null,
            'owner_id'       => $assignedTo,
            'effort'         => $effort ?: null,
        ]);

        Auth::log('add_response_action', 'risks', $id, ['type' => $type]);
        $_SESSION['flash_success'] = 'Response action added.';
        header('Location: /risk/' . $id);
    }

    public function updateResponseAction(string $tid): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $tid    = (int)$tid;
        $action = Database::fetchOne("SELECT * FROM risk_treatments WHERE id = ?", [$tid]);
        if (!$action) { http_response_code(404); return; }

        $status  = Security::sanitizeInput($_POST['status'] ?? '');
        $notes   = Security::sanitizeInput($_POST['completion_notes'] ?? '');

        $validStatuses = ['planned', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses, true)) $status = $action['status'];

        $data = [
            'status'           => $status,
            'completion_notes' => $notes ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        if ($status === 'completed' && !$action['completion_date']) {
            $data['completion_date'] = date('Y-m-d');
        }

        Database::query(
            "UPDATE risk_treatments SET status=?, completion_notes=?, completion_date=?, updated_at=NOW() WHERE id=?",
            [$data['status'], $data['completion_notes'], $data['completion_date'] ?? $action['completion_date'], $tid]
        );

        Auth::log('update_response_action', 'risks', (int)$action['risk_id'], ['action_id' => $tid, 'status' => $status]);
        $_SESSION['flash_success'] = 'Action updated.';
        header('Location: /risk/' . $action['risk_id']);
    }

    public function delete(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        Database::query("DELETE FROM risks WHERE id = ?", [$id]);
        Auth::log('delete_risk', 'risks', $id, []);
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
             WHERE r.status NOT IN ('closed','transferred')
             ORDER BY r.inherent_score DESC"
        );

        require AEGIS_ROOT . '/views/risk/matrix.php';
    }

    public function editForm(string $id): void {
        $this->view($id);
    }

    public function bulkUpdate(): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $action = Security::sanitizeInput($_POST['bulk_action'] ?? '');
        if (empty($ids) || !$action) {
            $_SESSION['flash_error'] = 'No risks selected or no action chosen.';
            header('Location: /risk'); return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $statusMap = [
            'status_open'        => 'open',
            'status_in_review'   => 'in_review',
            'status_monitoring'  => 'monitoring',
            'status_accepted'    => 'accepted',
            'status_closed'      => 'closed',
            'status_transferred' => 'transferred',
        ];
        $strategyMap = [
            'strategy_mitigate' => 'mitigate',
            'strategy_accept'   => 'accept',
            'strategy_transfer' => 'transfer',
            'strategy_avoid'    => 'avoid',
        ];

        if (isset($statusMap[$action])) {
            $newStatus = $statusMap[$action];
            Database::query(
                "UPDATE risks SET status=?, updated_at=NOW() WHERE id IN ({$placeholders})",
                array_merge([$newStatus], $ids)
            );
        } elseif (isset($strategyMap[$action])) {
            $strategy = $strategyMap[$action];
            Database::query(
                "UPDATE risks SET treatment_strategies=?::jsonb, treatment_type=?, updated_at=NOW() WHERE id IN ({$placeholders})",
                array_merge([json_encode([$strategy]), $strategy], $ids)
            );
        } else {
            $_SESSION['flash_error'] = 'Invalid action.';
            header('Location: /risk'); return;
        }

        Auth::log('bulk_update_risks', 'risks', 0, ['action' => $action, 'count' => count($ids)]);
        $_SESSION['flash_success'] = 'Updated ' . count($ids) . ' risk(s).';
        header('Location: /risk');
    }

    public function roadmap(): void {
        Auth::requireAuth();
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $ownerFilter  = !empty($_GET['owner'])  ? (int)$_GET['owner']  : null;
        $levelFilter  = $_GET['level']  ?? '';
        $statusFilter = $_GET['status'] ?? '';

        $where  = ["r.status NOT IN ('closed','accepted','transferred')"];
        $params = [];
        if ($ownerFilter)  { $where[] = "r.owner_id = ?";  $params[] = $ownerFilter; }
        if ($statusFilter && in_array($statusFilter, self::STATUSES, true)) {
            $where[] = "r.status = ?"; $params[] = $statusFilter;
        }
        if ($levelFilter === 'critical')   { $where[] = "r.inherent_score >= 20"; }
        elseif ($levelFilter === 'high')   { $where[] = "r.inherent_score BETWEEN 15 AND 19"; }
        elseif ($levelFilter === 'medium') { $where[] = "r.inherent_score BETWEEN 8 AND 14"; }
        elseif ($levelFilter === 'low')    { $where[] = "r.inherent_score < 8"; }

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
