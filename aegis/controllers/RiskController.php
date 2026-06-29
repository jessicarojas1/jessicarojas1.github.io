<?php
declare(strict_types=1);

class RiskController {

    private const STRATEGIES  = ['mitigate', 'accept', 'transfer', 'avoid'];
    private const STATUSES    = ['open', 'in_review', 'monitoring', 'accepted', 'closed', 'transferred'];
    private const SOURCES     = ['strategic','operational','financial','compliance','technology',
                                  'reputational','external','people','project'];
    private const PROXIMITIES = ['immediate','short_term','medium_term','long_term'];

    // ─────────────────────────────────────────── dashboard ──────────────────
    public function dashboard(): void {
        Auth::requirePermission('risk.view');

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*)                                         AS total,
               COUNT(*) FILTER (WHERE " . RiskScore::sqlCondition('critical') . ") AS critical,
               COUNT(*) FILTER (WHERE " . RiskScore::sqlCondition('high')     . ") AS high,
               COUNT(*) FILTER (WHERE " . RiskScore::sqlCondition('medium')   . ") AS medium,
               COUNT(*) FILTER (WHERE " . RiskScore::sqlCondition('low')      . ") AS low,
               COUNT(*) FILTER (WHERE status = 'open')                  AS open,
               COUNT(*) FILTER (WHERE status = 'in_review')             AS in_review,
               COUNT(*) FILTER (WHERE status = 'monitoring')            AS monitoring,
               COUNT(*) FILTER (WHERE status = 'accepted')              AS accepted,
               COUNT(*) FILTER (WHERE status = 'closed')                AS closed,
               COUNT(*) FILTER (WHERE assessment_status = 'approved')   AS approved,
               COUNT(*) FILTER (WHERE review_date < CURRENT_DATE
                                  AND status NOT IN ('closed','transferred','accepted')) AS overdue_reviews,
               COUNT(*) FILTER (WHERE review_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30
                                  AND status NOT IN ('closed','transferred','accepted')) AS due_soon
             FROM risks"
        );

        // Heat map: count of active risks at each L×I cell
        $heatMapData = Database::fetchAll(
            "SELECT likelihood, impact, COUNT(*) AS count,
                    string_agg(risk_id || ': ' || LEFT(title,40), '\n' ORDER BY inherent_score DESC) AS labels
             FROM risks
             WHERE status NOT IN ('closed','transferred')
             GROUP BY likelihood, impact"
        );
        $heatMap = [];
        foreach ($heatMapData as $row) {
            $heatMap[(int)$row['likelihood']][(int)$row['impact']] = [
                'count'  => (int)$row['count'],
                'labels' => $row['labels'],
            ];
        }

        // Top 10 open risks
        $topRisks = Database::fetchAll(
            "SELECT r.*, rc.name AS category_name, rc.color AS category_color, u.name AS owner_name
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE r.status NOT IN ('closed','transferred')
             ORDER BY r.inherent_score DESC, r.created_at DESC
             LIMIT 10"
        );

        // Portfolio score trend — average inherent score per week for last 12 weeks
        $trendData = Database::fetchAll(
            "SELECT DATE_TRUNC('week', created_at) AS week,
                    ROUND(AVG(score),1) AS avg_score,
                    MAX(score)          AS max_score,
                    COUNT(DISTINCT risk_id) AS risk_count
             FROM risk_score_history
             WHERE created_at >= NOW() - INTERVAL '12 weeks'
             GROUP BY DATE_TRUNC('week', created_at)
             ORDER BY week ASC"
        );

        // Risks exceeding appetite
        $exceedingAppetite = Database::fetchAll(
            "SELECT r.id, r.risk_id, r.title, r.inherent_score, ra.max_score, ra.appetite,
                    rc.name AS category_name
             FROM risks r
             JOIN risk_categories rc ON rc.id = r.category_id
             JOIN risk_appetite ra ON ra.category = rc.name
             WHERE ra.max_score IS NOT NULL
               AND r.inherent_score > ra.max_score
               AND r.status NOT IN ('closed','transferred')
             ORDER BY (r.inherent_score - ra.max_score) DESC
             LIMIT 5"
        );

        // Risks with no linked controls
        $uncontrolled = Database::fetchAll(
            "SELECT r.id, r.risk_id, r.title, r.inherent_score, rc.name AS category_name
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             LEFT JOIN risk_control_links rcl ON rcl.risk_id = r.id
             WHERE rcl.id IS NULL
               AND r.status NOT IN ('closed','transferred','accepted')
               AND r.inherent_score > 4
             ORDER BY r.inherent_score DESC
             LIMIT 8"
        );

        // Upcoming review schedule
        $upcomingReviews = Database::fetchAll(
            "SELECT r.id, r.risk_id, r.title, r.review_date, r.inherent_score,
                    r.status, u.name AS owner_name
             FROM risks r
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE r.review_date IS NOT NULL
               AND r.review_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 45
               AND r.status NOT IN ('closed','transferred')
             ORDER BY r.review_date ASC
             LIMIT 10"
        );

        // Recent score changes (last 7 days)
        $recentChanges = Database::fetchAll(
            "SELECT rsh.*, r.title, r.risk_id, u.name AS changed_by_name
             FROM risk_score_history rsh
             JOIN risks r ON r.id = rsh.risk_id
             LEFT JOIN users u ON u.id = rsh.changed_by
             WHERE rsh.created_at >= NOW() - INTERVAL '7 days'
             ORDER BY rsh.created_at DESC
             LIMIT 10"
        );

        // Treatment action backlog
        $actionBacklog = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'planned')     AS planned,
               COUNT(*) FILTER (WHERE status = 'in_progress') AS in_progress,
               COUNT(*) FILTER (WHERE status = 'completed')   AS completed,
               COUNT(*) FILTER (WHERE due_date < CURRENT_DATE
                                  AND status NOT IN ('completed','cancelled')) AS overdue
             FROM risk_treatments"
        );

        $matrixConfig = Database::fetchOne(
            "SELECT * FROM risk_matrix_config WHERE is_active = TRUE LIMIT 1"
        );

        $pageTitle    = 'Risk Dashboard';
        $activeModule = 'risk';
        $breadcrumbs  = [['Risk', '/risk'], ['Dashboard', null]];
        require AEGIS_ROOT . '/views/risk/dashboard.php';
    }

    // ─────────────────────────────────────────── index ──────────────────────
    public function index(): void {
        Auth::requirePermission('risk.view');

        $status    = Security::sanitizeInput($_GET['status']    ?? '');
        $category  = Security::sanitizeInput($_GET['category']  ?? '');
        $level     = Security::sanitizeInput($_GET['level']     ?? '');
        $treatment = Security::sanitizeInput($_GET['treatment'] ?? '');
        $source    = Security::sanitizeInput($_GET['source']    ?? '');
        $owner     = !empty($_GET['owner']) ? (int)$_GET['owner'] : null;
        $search    = Security::sanitizeInput($_GET['search']    ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($status && in_array($status, self::STATUSES, true)) {
            $where[] = 'r.status = ?'; $params[] = $status;
        }
        if ($category) {
            $where[] = 'r.category_id = ?'; $params[] = (int)$category;
        }
        if ($treatment && in_array($treatment, self::STRATEGIES, true)) {
            $where[] = 'r.treatment_strategies @> ?::jsonb'; $params[] = json_encode([$treatment]);
        }
        if ($source && in_array($source, self::SOURCES, true)) {
            $where[] = 'r.risk_source = ?'; $params[] = $source;
        }
        if ($owner) {
            $where[] = 'r.owner_id = ?'; $params[] = $owner;
        }
        if ($search) {
            $where[] = '(r.title ILIKE ? OR r.risk_id ILIKE ? OR r.description ILIKE ?)';
            $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%";
        }
        if (in_array($level, RiskScore::levels(), true)) {
            $where[] = RiskScore::sqlCondition($level, 'r.inherent_score');
        }

        $whereSQL = implode(' AND ', $where);

        // Server-side pagination (TD-5): count matching rows, then fetch one page.
        // The filter clauses reference only r.*, so COUNT needs no joins.
        $matchTotal = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM risks r WHERE {$whereSQL}", $params)['c'] ?? 0);
        $pagination = Pagination::build($matchTotal);

        $pageParams = array_merge($params, [$pagination['perPage'], $pagination['offset']]);
        $risks = Database::fetchAll(
            "SELECT r.*,
                    rc.name AS category_name, rc.color AS category_color,
                    u.name  AS owner_name,
                    -- trend: compare to first history entry in last 30 days
                    (SELECT score FROM risk_score_history
                     WHERE risk_id = r.id ORDER BY created_at ASC LIMIT 1) AS first_score,
                    -- count linked controls
                    (SELECT COUNT(*) FROM risk_control_links WHERE risk_id = r.id) AS control_count
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             LEFT JOIN users u ON r.owner_id = u.id
             WHERE {$whereSQL}
             ORDER BY r.inherent_score DESC, r.created_at DESC
             LIMIT ? OFFSET ?",
            $pageParams
        );

        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*)                                              AS total,
               COUNT(*) FILTER (WHERE inherent_score > 14)          AS critical,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14) AS high,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 5 AND 9)   AS medium,
               COUNT(*) FILTER (WHERE inherent_score <= 4)              AS low,
               COUNT(*) FILTER (WHERE status = 'open')               AS open,
               COUNT(*) FILTER (WHERE status = 'in_review')          AS in_review,
               COUNT(*) FILTER (WHERE status = 'monitoring')         AS monitoring,
               COUNT(*) FILTER (WHERE status = 'accepted')           AS accepted,
               COUNT(*) FILTER (WHERE status = 'closed')             AS closed,
               COUNT(*) FILTER (WHERE review_date < CURRENT_DATE
                                  AND status NOT IN ('closed','transferred','accepted')) AS overdue
             FROM risks"
        );

        require AEGIS_ROOT . '/views/risk/index.php';
    }

    // ─────────────────────────────────────────── createForm / create ─────────
    public function createForm(): void {
        Auth::requirePermission('risk.create');
        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $parentRisks = Database::fetchAll(
            "SELECT id, risk_id, title FROM risks WHERE status NOT IN ('closed','transferred') ORDER BY inherent_score DESC LIMIT 50"
        );
        require AEGIS_ROOT . '/views/risk/create.php';
    }

    public function create(): void {
        Auth::requirePermission('risk.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title        = Security::sanitizeInput($_POST['title'] ?? '');
        $desc         = Security::sanitizeInput($_POST['description'] ?? '');
        $categoryId   = !empty($_POST['category_id'])   ? (int)$_POST['category_id']   : null;
        $likelihood   = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact       = max(1, min(5, (int)($_POST['impact']     ?? 3)));
        $velocity     = max(1, min(5, (int)($_POST['velocity']   ?? 3)));
        $proximity    = in_array($_POST['proximity'] ?? '', self::PROXIMITIES, true) ? $_POST['proximity'] : 'medium_term';
        $source       = in_array($_POST['risk_source'] ?? '', self::SOURCES, true)   ? $_POST['risk_source'] : null;
        $confidence   = in_array($_POST['confidence'] ?? '', ['low','medium','high'], true) ? $_POST['confidence'] : 'medium';
        $ownerId      = !empty($_POST['owner_id'])      ? (int)$_POST['owner_id']      : Auth::id();
        $reviewDate   = Security::sanitizeInput($_POST['review_date'] ?? '');
        $treatDesc    = Security::sanitizeInput($_POST['treatment_description'] ?? '');
        $parentRiskId = !empty($_POST['parent_risk_id']) ? (int)$_POST['parent_risk_id'] : null;

        $finMin    = !empty($_POST['financial_min'])    ? (float)$_POST['financial_min']    : null;
        $finLikely = !empty($_POST['financial_likely']) ? (float)$_POST['financial_likely'] : null;
        $finMax    = !empty($_POST['financial_max'])    ? (float)$_POST['financial_max']    : null;

        $strategies = array_values(array_filter(
            (array)($_POST['treatment_strategies'] ?? []),
            fn($s) => in_array($s, self::STRATEGIES, true)
        ));

        if (!$title) {
            $_SESSION['risk_error'] = 'Risk title is required.';
            header('Location: /risk/create'); return;
        }

        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM risks");
        $riskId = 'RSK-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $riskDbId = Database::insert('risks', [
            'title'                 => $title,
            'risk_id'               => $riskId,
            'description'           => $desc,
            'category_id'           => $categoryId,
            'likelihood'            => $likelihood,
            'impact'                => $impact,
            'inherent_score'        => $likelihood * $impact,
            'velocity'              => $velocity,
            'proximity'             => $proximity,
            'risk_source'           => $source,
            'confidence'            => $confidence,
            'treatment_strategies'  => json_encode($strategies),
            'treatment_type'        => $strategies[0] ?? null,
            'treatment_description' => $treatDesc ?: null,
            'owner_id'              => $ownerId,
            'parent_risk_id'        => $parentRiskId,
            'status'                => 'open',
            'assessment_status'     => 'draft',
            'financial_min'         => $finMin,
            'financial_likely'      => $finLikely,
            'financial_max'         => $finMax,
            'review_date'           => $reviewDate ?: null,
            'identified_date'       => date('Y-m-d'),
            'created_by'            => Auth::id(),
        ]);

        // Log initial score history
        Database::insert('risk_score_history', [
            'risk_id'              => $riskDbId,
            'likelihood'           => $likelihood,
            'impact'               => $impact,
            'score'                => $likelihood * $impact,
            'status'               => 'open',
            'treatment_strategies' => json_encode($strategies),
            'changed_by'           => Auth::id(),
            'note'                 => 'Risk created',
        ]);

        Auth::log('create_risk', 'risks', $riskDbId, ['strategies' => $strategies, 'score' => $likelihood * $impact]);
        header('Location: /risk/' . $riskDbId);
    }

    // ─────────────────────────────────────────── view ───────────────────────
    public function view(string $id): void {
        Auth::requirePermission('risk.view');
        $id = (int)$id;

        $risk = Database::fetchOne(
            "SELECT r.*,
                    rc.name AS category_name, rc.color AS category_color,
                    u.name  AS owner_name,
                    u2.name AS created_by_name,
                    u3.name AS reviewed_by_name,
                    pr.title AS parent_title, pr.risk_id AS parent_risk_id_code,
                    pr.inherent_score AS parent_score
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             LEFT JOIN users u  ON u.id  = r.owner_id
             LEFT JOIN users u2 ON u2.id = r.created_by
             LEFT JOIN users u3 ON u3.id = r.reviewed_by
             LEFT JOIN risks pr ON pr.id = r.parent_risk_id
             WHERE r.id = ?", [$id]
        );
        if (!$risk) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $risk['treatment_strategies_arr'] = json_decode($risk['treatment_strategies'] ?? '[]', true) ?: [];

        // Response actions sorted by priority
        $responseActions = Database::fetchAll(
            "SELECT rt.*, u.name AS owner_name FROM risk_treatments rt
             LEFT JOIN users u ON u.id = rt.owner_id
             WHERE rt.risk_id = ?
             ORDER BY CASE rt.status
               WHEN 'in_progress' THEN 1 WHEN 'planned' THEN 2
               WHEN 'completed'   THEN 3 ELSE 4 END,
               rt.due_date ASC NULLS LAST",
            [$id]
        );

        // Score history — last 20 changes for the chart
        $scoreHistory = Database::fetchAll(
            "SELECT rsh.*, u.name AS changed_by_name
             FROM risk_score_history rsh
             LEFT JOIN users u ON u.id = rsh.changed_by
             WHERE rsh.risk_id = ?
             ORDER BY rsh.created_at ASC",
            [$id]
        );

        // Linked controls
        $linkedControls = Database::fetchAll(
            "SELECT rcl.*, ci.status AS control_status, ci.implementation_notes,
                    co.code AS objective_code, co.title AS objective_title,
                    cp.name AS package_name
             FROM risk_control_links rcl
             JOIN control_implementations ci ON ci.id = rcl.control_implementation_id
             JOIN compliance_objectives co ON co.id = ci.objective_id
             JOIN compliance_packages cp ON cp.id = co.package_id
             WHERE rcl.risk_id = ?
             ORDER BY co.code",
            [$id]
        );

        // Available controls to link (not already linked)
        $linkedIds = array_column($linkedControls, 'control_implementation_id');
        $availableControlsQuery =
            "SELECT ci.id, co.code, co.title, cp.name AS package_name, ci.status
             FROM control_implementations ci
             JOIN compliance_objectives co ON co.id = ci.objective_id
             JOIN compliance_packages cp ON cp.id = co.package_id
             ORDER BY cp.name, co.code
             LIMIT 200";
        $allControls = Database::fetchAll($availableControlsQuery);
        $availableControls = array_values(array_filter(
            $allControls,
            fn($c) => !in_array((int)$c['id'], array_map('intval', $linkedIds), true)
        ));

        // Child risks
        $childRisks = Database::fetchAll(
            "SELECT r.id, r.risk_id, r.title, r.inherent_score, r.status,
                    u.name AS owner_name
             FROM risks r
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE r.parent_risk_id = ?
             ORDER BY r.inherent_score DESC",
            [$id]
        );

        // Related risks
        $relatedRisks = Database::fetchAll(
            "SELECT rrl.*, rr.id AS related_risk_id, rr.risk_id AS related_risk_code,
                    rr.title AS related_title, rr.inherent_score AS related_score,
                    rr.status AS related_status
             FROM risk_related_links rrl
             JOIN risks rr ON rr.id = rrl.related_id
             WHERE rrl.risk_id = ?
             ORDER BY rr.inherent_score DESC",
            [$id]
        );

        // Treatment plans
        $treatmentPlans = Database::fetchAll(
            "SELECT tp.*, u.name AS owner_name,
                    COUNT(tm.id)           AS total_milestones,
                    COUNT(tm.completed_at) AS completed_milestones
             FROM treatment_plans tp
             LEFT JOIN users u ON u.id = tp.owner_id
             LEFT JOIN treatment_milestones tm ON tm.plan_id = tp.id
             WHERE tp.risk_id = ?
             GROUP BY tp.id, u.name
             ORDER BY tp.created_at DESC",
            [$id]
        );

        // Risk appetite for this category
        $appetite = null;
        try {
            if ($risk['category_name']) {
                $appetite = Database::fetchOne(
                    "SELECT * FROM risk_appetite WHERE category = ?",
                    [$risk['category_name']]
                );
            }
        } catch (Throwable $e) {}

        $categories     = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $users          = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $allRisks       = Database::fetchAll(
            "SELECT id, risk_id, title FROM risks WHERE id != ? AND status NOT IN ('closed') ORDER BY risk_id",
            [$id]
        );

        // Linked KRIs
        $linkedKRIs = [];
        try {
            $linkedKRIs = Database::fetchAll(
                "SELECT k.id, k.title, k.unit, k.threshold_red, k.threshold_amber,
                        k.direction
                 FROM kris k
                 WHERE k.linked_risk_id = ? AND k.is_active = TRUE
                 ORDER BY k.title",
                [$id]
            );
        } catch (Throwable) {}

        // Active acceptance certificate
        $activeAcceptance = null;
        try {
            $activeAcceptance = Database::fetchOne(
                "SELECT ra.*, u.name AS acceptor_name
                 FROM risk_acceptances ra
                 JOIN users u ON u.id = ra.accepted_by
                 WHERE ra.risk_id = ? AND ra.status = 'active'
                 LIMIT 1",
                [$id]
            );
        } catch (Throwable) {}

        // Scenarios
        $scenarios = [];
        try {
            $scenarios = Database::fetchAll(
                "SELECT * FROM risk_scenarios WHERE risk_id = ? ORDER BY scenario_score DESC NULLS LAST",
                [$id]
            );
        } catch (Throwable) {}

        // Control effectiveness → suggested residual score
        $controlEffSuggestion = null;
        if (!empty($linkedControls) && (int)$risk['likelihood'] > 0 && (int)$risk['impact'] > 0) {
            $effMap = ['full' => 0.5, 'substantial' => 0.65, 'partial' => 0.8, 'none' => 1.0];
            $bestEff = 'none';
            $order   = ['full', 'substantial', 'partial', 'none'];
            foreach ($linkedControls as $lc) {
                $eff = $lc['effectiveness'] ?? 'none';
                if (array_search($eff, $order, true) < array_search($bestEff, $order, true)) {
                    $bestEff = $eff;
                }
            }
            $mult  = $effMap[$bestEff] ?? 1.0;
            $sugL  = max(1, (int)round((int)$risk['likelihood'] * $mult));
            $sugI  = max(1, (int)round((int)$risk['impact'] * $mult));
            $controlEffSuggestion = [
                'effectiveness'  => $bestEff,
                'multiplier'     => $mult,
                'likelihood'     => $sugL,
                'impact'         => $sugI,
                'score'          => $sugL * $sugI,
            ];
        }

        require AEGIS_ROOT . '/views/risk/view.php';
    }

    // ─────────────────────────────────────────── update ─────────────────────
    public function update(string $id): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id = (int)$id;

        $likelihood    = max(1, min(5, (int)($_POST['likelihood'] ?? 3)));
        $impact        = max(1, min(5, (int)($_POST['impact']     ?? 3)));
        $resLikelihood = !empty($_POST['residual_likelihood']) ? max(1, min(5, (int)$_POST['residual_likelihood'])) : null;
        $resImpact     = !empty($_POST['residual_impact'])     ? max(1, min(5, (int)$_POST['residual_impact']))     : null;
        $tgtLikelihood = !empty($_POST['target_likelihood'])   ? max(1, min(5, (int)$_POST['target_likelihood']))   : null;
        $tgtImpact     = !empty($_POST['target_impact'])       ? max(1, min(5, (int)$_POST['target_impact']))       : null;
        $velocity      = max(1, min(5, (int)($_POST['velocity'] ?? 3)));
        $proximity     = in_array($_POST['proximity']   ?? '', self::PROXIMITIES, true) ? $_POST['proximity']   : 'medium_term';
        $source        = in_array($_POST['risk_source'] ?? '', self::SOURCES, true)     ? $_POST['risk_source'] : null;
        $confidence    = in_array($_POST['confidence']  ?? '', ['low','medium','high'], true) ? $_POST['confidence'] : 'medium';
        $status        = in_array($_POST['status']      ?? '', self::STATUSES, true)    ? $_POST['status']      : 'open';
        $treatDesc     = Security::sanitizeInput($_POST['treatment_description'] ?? '');
        $reviewDate    = Security::sanitizeInput($_POST['review_date'] ?? '');
        $ownerId       = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $parentRiskId  = !empty($_POST['parent_risk_id']) ? (int)$_POST['parent_risk_id'] : null;
        $updateNote    = Security::sanitizeInput($_POST['update_note'] ?? '');

        $finMin    = !empty($_POST['financial_min'])    ? (float)$_POST['financial_min']    : null;
        $finLikely = !empty($_POST['financial_likely']) ? (float)$_POST['financial_likely'] : null;
        $finMax    = !empty($_POST['financial_max'])    ? (float)$_POST['financial_max']    : null;

        $strategies = array_values(array_filter(
            (array)($_POST['treatment_strategies'] ?? []),
            fn($s) => in_array($s, self::STRATEGIES, true)
        ));

        // Fetch current values to compare for history
        $current = Database::fetchOne("SELECT * FROM risks WHERE id = ?", [$id]);
        $scoreChanged = $current && (
            (int)$current['likelihood'] !== $likelihood ||
            (int)$current['impact']     !== $impact
        );

        Database::query(
            "UPDATE risks SET
               likelihood=?, impact=?, inherent_score=?,
               residual_likelihood=?, residual_impact=?,
               target_likelihood=?, target_impact=?,
               velocity=?, proximity=?, risk_source=?, confidence=?,
               status=?,
               treatment_strategies=?::jsonb, treatment_type=?,
               treatment_description=?,
               owner_id=?, parent_risk_id=?,
               financial_min=?, financial_likely=?, financial_max=?,
               review_date=?,
               assessment_status = CASE WHEN assessment_status = 'approved' THEN 'draft' ELSE assessment_status END,
               updated_at=NOW()
             WHERE id=?",
            [
                $likelihood, $impact, $likelihood * $impact,
                $resLikelihood, $resImpact,
                $tgtLikelihood, $tgtImpact,
                $velocity, $proximity, $source, $confidence,
                $status,
                json_encode($strategies), $strategies[0] ?? null,
                $treatDesc ?: null,
                $ownerId, $parentRiskId,
                $finMin, $finLikely, $finMax,
                $reviewDate ?: null,
                $id,
            ]
        );

        // Always log score history so we have a full audit trail
        Database::insert('risk_score_history', [
            'risk_id'              => $id,
            'likelihood'           => $likelihood,
            'impact'               => $impact,
            'score'                => $likelihood * $impact,
            'residual_likelihood'  => $resLikelihood,
            'residual_impact'      => $resImpact,
            'residual_score'       => ($resLikelihood && $resImpact) ? $resLikelihood * $resImpact : null,
            'status'               => $status,
            'treatment_strategies' => json_encode($strategies),
            'changed_by'           => Auth::id(),
            'note'                 => $updateNote ?: null,
        ]);

        Auth::log('update_risk', 'risks', $id, [
            'status'     => $status,
            'score'      => $likelihood * $impact,
            'strategies' => $strategies,
        ]);

        $_SESSION['flash_success'] = 'Risk updated.';
        header('Location: /risk/' . $id);
    }

    // ─────────────────────────────────────────── assessment workflow ─────────
    public function submitReview(string $id): void {
        Auth::requirePermission('risk.review');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id = (int)$id;
        Database::query(
            "UPDATE risks SET assessment_status='pending_review', updated_at=NOW() WHERE id=?",
            [$id]
        );
        Auth::log('risk_submitted_review', 'risks', $id, []);
        $_SESSION['flash_success'] = 'Risk assessment submitted for review.';
        header('Location: /risk/' . $id);
    }

    public function approve(string $id): void {
        Auth::requirePermission('risk.review');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id    = (int)$id;
        $notes = Security::sanitizeInput($_POST['review_notes'] ?? '');
        Database::query(
            "UPDATE risks SET assessment_status='approved', reviewed_by=?, reviewed_at=NOW(), review_notes=?, updated_at=NOW() WHERE id=?",
            [Auth::id(), $notes ?: null, $id]
        );
        Auth::log('risk_approved', 'risks', $id, ['notes' => $notes]);
        $_SESSION['flash_success'] = 'Risk assessment approved.';
        header('Location: /risk/' . $id);
    }

    public function rejectReview(string $id): void {
        Auth::requirePermission('risk.review');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id    = (int)$id;
        $notes = Security::sanitizeInput($_POST['review_notes'] ?? '');
        Database::query(
            "UPDATE risks SET assessment_status='draft', review_notes=?, updated_at=NOW() WHERE id=?",
            [$notes ?: null, $id]
        );
        Auth::log('risk_review_rejected', 'risks', $id, ['notes' => $notes]);
        $_SESSION['flash_success'] = 'Risk sent back for revision.';
        header('Location: /risk/' . $id);
    }

    // ─────────────────────────────────────────── control links ───────────────
    public function linkControl(string $id): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id              = (int)$id;
        $controlId       = (int)($_POST['control_implementation_id'] ?? 0);
        $effectiveness   = Security::sanitizeInput($_POST['effectiveness'] ?? 'partial');
        $notes           = Security::sanitizeInput($_POST['notes'] ?? '');

        if (!in_array($effectiveness, ['none','partial','substantial','full'], true)) $effectiveness = 'partial';

        if ($controlId > 0) {
            Database::query(
                "INSERT INTO risk_control_links (risk_id, control_implementation_id, effectiveness, notes, created_by)
                 VALUES (?,?,?,?,?)
                 ON CONFLICT (risk_id, control_implementation_id) DO UPDATE
                   SET effectiveness=EXCLUDED.effectiveness, notes=EXCLUDED.notes",
                [$id, $controlId, $effectiveness, $notes ?: null, Auth::id()]
            );
            Auth::log('link_control_to_risk', 'risks', $id, ['control_id' => $controlId, 'effectiveness' => $effectiveness]);
            $_SESSION['flash_success'] = 'Control linked.';
        }
        header('Location: /risk/' . $id);
    }

    public function removeControlLink(string $linkId): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $linkId = (int)$linkId;
        $link   = Database::fetchOne("SELECT risk_id FROM risk_control_links WHERE id=?", [$linkId]);
        if ($link) {
            Database::query("DELETE FROM risk_control_links WHERE id=?", [$linkId]);
            Auth::log('unlink_control_from_risk', 'risks', (int)$link['risk_id'], ['link_id' => $linkId]);
            $_SESSION['flash_success'] = 'Control unlinked.';
            header('Location: /risk/' . $link['risk_id']); return;
        }
        header('Location: /risk');
    }

    // ─────────────────────────────────────────── related risks ───────────────
    public function linkRelated(string $id): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id        = (int)$id;
        $relatedId = (int)($_POST['related_risk_id'] ?? 0);
        $linkType  = Security::sanitizeInput($_POST['link_type'] ?? 'related');
        if (!in_array($linkType, ['related','causes','caused_by','aggregates'], true)) $linkType = 'related';

        if ($relatedId > 0 && $relatedId !== $id) {
            Database::query(
                "INSERT INTO risk_related_links (risk_id, related_id, link_type, created_by)
                 VALUES (?,?,?,?)
                 ON CONFLICT (risk_id, related_id) DO NOTHING",
                [$id, $relatedId, $linkType, Auth::id()]
            );
            Auth::log('link_related_risk', 'risks', $id, ['related_id' => $relatedId, 'type' => $linkType]);
            $_SESSION['flash_success'] = 'Related risk linked.';
        }
        header('Location: /risk/' . $id);
    }

    public function removeRelatedLink(string $linkId): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $linkId = (int)$linkId;
        $link   = Database::fetchOne("SELECT risk_id FROM risk_related_links WHERE id=?", [$linkId]);
        if ($link) {
            Database::query("DELETE FROM risk_related_links WHERE id=?", [$linkId]);
            Auth::log('unlink_related_risk', 'risks', (int)$link['risk_id'], ['link_id' => $linkId]);
            $_SESSION['flash_success'] = 'Link removed.';
            header('Location: /risk/' . $link['risk_id']); return;
        }
        header('Location: /risk');
    }

    // ─────────────────────────────────────────── response actions ────────────
    public function addResponseAction(string $id): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id         = (int)$id;
        $type       = Security::sanitizeInput($_POST['action_type'] ?? 'mitigate');
        $desc       = Security::sanitizeInput($_POST['description'] ?? '');
        $dueDate    = Security::sanitizeInput($_POST['due_date']    ?? '');
        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : Auth::id();
        $effort     = Security::sanitizeInput($_POST['effort']      ?? '');
        $cost       = !empty($_POST['cost_estimate']) ? (float)$_POST['cost_estimate'] : null;

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
            'cost_estimate'  => $cost,
        ]);

        Auth::log('add_response_action', 'risks', $id, ['type' => $type]);
        $_SESSION['flash_success'] = 'Response action added.';
        header('Location: /risk/' . $id);
    }

    public function updateResponseAction(string $tid): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $tid    = (int)$tid;
        $action = Database::fetchOne("SELECT * FROM risk_treatments WHERE id=?", [$tid]);
        if (!$action) { http_response_code(404); return; }

        $status = Security::sanitizeInput($_POST['status'] ?? '');
        $notes  = Security::sanitizeInput($_POST['completion_notes'] ?? '');
        if (!in_array($status, ['planned','in_progress','completed','cancelled'], true)) $status = $action['status'];

        $compDate = $action['completion_date'];
        if ($status === 'completed' && !$compDate) $compDate = date('Y-m-d');

        Database::query(
            "UPDATE risk_treatments SET status=?, completion_notes=?, completion_date=?, updated_at=NOW() WHERE id=?",
            [$status, $notes ?: null, $compDate, $tid]
        );
        Auth::log('update_response_action', 'risks', (int)$action['risk_id'], ['action_id' => $tid, 'status' => $status]);
        $_SESSION['flash_success'] = 'Action updated.';
        header('Location: /risk/' . $action['risk_id']);
    }

    // ─────────────────────────────────────────── delete ─────────────────────
    public function delete(string $id): void {
        Auth::requirePermission('risk.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id = (int)$id;
        Database::query("DELETE FROM risks WHERE id=?", [$id]);
        Auth::log('delete_risk', 'risks', $id, []);
        header('Location: /risk?deleted=1');
    }

    // ─────────────────────────────────────────── matrix ─────────────────────
    public function matrix(): void {
        Auth::requirePermission('risk.view');
        $matrixConfig = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active=TRUE ORDER BY id LIMIT 1");
        $risks = Database::fetchAll(
            "SELECT r.id, r.title, r.risk_id, r.likelihood, r.impact, r.inherent_score,
                    r.residual_score, r.status, r.treatment_strategies,
                    rc.name AS category_name, rc.color AS category_color
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             WHERE r.status NOT IN ('closed','transferred')
             ORDER BY r.inherent_score DESC"
        );
        require AEGIS_ROOT . '/views/risk/matrix.php';
    }

    public function editForm(string $id): void { $this->view($id); }

    // ─────────────────────────────────────────── bulk update ─────────────────
    public function bulkUpdate(): void {
        Auth::requirePermission('risk.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $action = Security::sanitizeInput($_POST['bulk_action'] ?? '');
        if (empty($ids) || !$action) {
            $_SESSION['flash_error'] = 'No risks selected or no action chosen.';
            header('Location: /risk'); return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $statusMap   = ['status_open'=>'open','status_in_review'=>'in_review','status_monitoring'=>'monitoring',
                        'status_accepted'=>'accepted','status_closed'=>'closed','status_transferred'=>'transferred'];
        $strategyMap = ['strategy_mitigate'=>'mitigate','strategy_accept'=>'accept',
                        'strategy_transfer'=>'transfer','strategy_avoid'=>'avoid'];

        if (isset($statusMap[$action])) {
            $ns = $statusMap[$action];
            Database::query("UPDATE risks SET status=?, updated_at=NOW() WHERE id IN ({$ph})",
                array_merge([$ns], $ids));
        } elseif (isset($strategyMap[$action])) {
            $s = $strategyMap[$action];
            Database::query("UPDATE risks SET treatment_strategies=?::jsonb, treatment_type=?, updated_at=NOW() WHERE id IN ({$ph})",
                array_merge([json_encode([$s]), $s], $ids));
        } elseif ($action === 'submit_review') {
            Database::query("UPDATE risks SET assessment_status='pending_review', updated_at=NOW() WHERE id IN ({$ph})",
                $ids);
        } else {
            $_SESSION['flash_error'] = 'Invalid action.';
            header('Location: /risk'); return;
        }

        Auth::log('bulk_update_risks', 'risks', 0, ['action'=>$action,'count'=>count($ids)]);
        $_SESSION['flash_success'] = 'Updated ' . count($ids) . ' risk(s).';
        header('Location: /risk');
    }

    // ─────────────────────────────────────────── roadmap ────────────────────
    public function roadmap(): void {
        Auth::requirePermission('risk.view');
        $users       = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $ownerFilter = !empty($_GET['owner'])  ? (int)$_GET['owner']  : null;
        $levelFilter = $_GET['level']  ?? '';
        $statusFilter= $_GET['status'] ?? '';

        $where  = ["r.status NOT IN ('closed','accepted','transferred')"];
        $params = [];
        if ($ownerFilter)  { $where[] = "r.owner_id=?"; $params[] = $ownerFilter; }
        if ($statusFilter && in_array($statusFilter, self::STATUSES, true)) {
            $where[] = "r.status=?"; $params[] = $statusFilter;
        }
        if ($levelFilter === 'critical')   { $where[] = "r.inherent_score >= 20"; }
        elseif ($levelFilter === 'high')   { $where[] = "r.inherent_score BETWEEN 15 AND 19"; }
        elseif ($levelFilter === 'medium') { $where[] = "r.inherent_score BETWEEN 8 AND 14"; }
        elseif ($levelFilter === 'low')    { $where[] = "r.inherent_score < 8"; }

        $risks = Database::fetchAll(
            "SELECT r.*, u.name AS owner_name
             FROM risks r LEFT JOIN users u ON u.id = r.owner_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY r.inherent_score DESC, r.review_date ASC",
            $params
        );

        $pageTitle    = 'Risk Treatment Roadmap';
        $activeModule = 'risk_matrix';
        $breadcrumbs  = [['Risk', '/risk'], ['Roadmap', null]];
        require AEGIS_ROOT . '/views/risk/roadmap.php';
    }
}
