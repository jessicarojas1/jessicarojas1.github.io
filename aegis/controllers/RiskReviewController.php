<?php
declare(strict_types=1);

class RiskReviewController {

    // ─────────────────────────────────────────── index ───────────────────────
    public function index(): void {
        Auth::requireAuth();

        $reviews = Database::fetchAll(
            "SELECT rr.*, u.name AS lead_reviewer_name
             FROM risk_reviews rr
             LEFT JOIN users u ON u.id = rr.lead_reviewer_id
             ORDER BY rr.scheduled_date DESC"
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'planned')     AS planned,
               COUNT(*) FILTER (WHERE status = 'in_progress') AS in_progress,
               COUNT(*) FILTER (WHERE status = 'completed')   AS completed,
               COUNT(*) FILTER (WHERE status = 'cancelled')   AS cancelled
             FROM risk_reviews"
        );

        $pageTitle    = 'Risk Review Sessions';
        $activeModule = 'risk';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Review Sessions', null]];
        require AEGIS_ROOT . '/views/risk/reviews.php';
    }

    // ─────────────────────────────────────────── createForm ──────────────────
    public function createForm(): void {
        Auth::requirePermission('risk.write');

        $users      = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $categories = Database::fetchAll("SELECT * FROM risk_categories ORDER BY sort_order");
        $owners     = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = 'Schedule Risk Review';
        $activeModule = 'risk';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Review Sessions', '/risk/reviews'], ['Schedule', null]];
        require AEGIS_ROOT . '/views/risk/review_form.php';
    }

    // ─────────────────────────────────────────── create ──────────────────────
    public function create(): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title          = Security::sanitizeInput($_POST['title'] ?? '');
        $reviewType     = Security::sanitizeInput($_POST['review_type'] ?? 'periodic');
        $scheduledDate  = Security::sanitizeInput($_POST['scheduled_date'] ?? '');
        $leadReviewerId = !empty($_POST['lead_reviewer_id']) ? (int)$_POST['lead_reviewer_id'] : null;
        $scopeDesc      = Security::sanitizeInput($_POST['scope_description'] ?? '');

        if (!$title || !$scheduledDate) {
            $_SESSION['flash_error'] = 'Title and scheduled date are required.';
            header('Location: /risk/reviews/create');
            return;
        }

        $validTypes = ['periodic', 'triggered', 'ad_hoc', 'board'];
        if (!in_array($reviewType, $validTypes, true)) $reviewType = 'periodic';

        // Build scope filter from POST
        $scopeFilter = [];
        if (!empty($_POST['category_id'])) $scopeFilter['category_id'] = (int)$_POST['category_id'];
        if (!empty($_POST['owner_id']))     $scopeFilter['owner_id']    = (int)$_POST['owner_id'];
        if (!empty($_POST['min_score']))    $scopeFilter['min_score']   = (int)$_POST['min_score'];
        $statusFilter = [];
        if (!empty($_POST['status_filter']) && is_array($_POST['status_filter'])) {
            $allowed = ['open', 'in_review', 'monitoring'];
            foreach ($_POST['status_filter'] as $sf) {
                if (in_array($sf, $allowed, true)) $statusFilter[] = $sf;
            }
        }
        if (!empty($statusFilter)) $scopeFilter['status_filter'] = $statusFilter;

        $reviewId = Database::insert('risk_reviews', [
            'title'              => $title,
            'review_type'        => $reviewType,
            'scheduled_date'     => $scheduledDate,
            'lead_reviewer_id'   => $leadReviewerId,
            'scope_description'  => $scopeDesc ?: null,
            'scope_filter'       => !empty($scopeFilter) ? json_encode($scopeFilter) : '{}',
            'status'             => 'planned',
            'total_risks'        => 0,
            'reviewed_count'     => 0,
            'escalated_count'    => 0,
            'created_by'         => Auth::id(),
        ]);

        // Auto-populate risk_review_items
        $where  = ["r.status NOT IN ('closed','transferred')"];
        $params = [];

        if (!empty($scopeFilter['category_id'])) {
            $where[] = 'r.category_id = ?';
            $params[] = $scopeFilter['category_id'];
        }
        if (!empty($scopeFilter['owner_id'])) {
            $where[] = 'r.owner_id = ?';
            $params[] = $scopeFilter['owner_id'];
        }
        if (!empty($scopeFilter['min_score'])) {
            $where[] = 'r.inherent_score >= ?';
            $params[] = $scopeFilter['min_score'];
        }
        if (!empty($scopeFilter['status_filter'])) {
            $ph      = implode(',', array_fill(0, count($scopeFilter['status_filter']), '?'));
            $where[] = "r.status IN ({$ph})";
            $params  = array_merge($params, $scopeFilter['status_filter']);
        }

        $risks = Database::fetchAll(
            "SELECT r.id FROM risks r WHERE " . implode(' AND ', $where),
            $params
        );

        foreach ($risks as $r) {
            Database::insert('risk_review_items', [
                'review_id'  => $reviewId,
                'risk_id'    => (int)$r['id'],
                'status'     => 'pending',
            ]);
        }

        $totalRisks = count($risks);
        Database::query(
            "UPDATE risk_reviews SET total_risks = ?, updated_at = NOW() WHERE id = ?",
            [$totalRisks, $reviewId]
        );

        Auth::log('create_risk_review', 'risk_reviews', $reviewId, [
            'title'       => $title,
            'review_type' => $reviewType,
            'total_risks' => $totalRisks,
        ]);

        header('Location: /risk/reviews/' . $reviewId);
    }

    // ─────────────────────────────────────────── view ────────────────────────
    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $review = Database::fetchOne(
            "SELECT rr.*, u.name AS lead_reviewer_name, u2.name AS sign_off_by_name
             FROM risk_reviews rr
             LEFT JOIN users u  ON u.id  = rr.lead_reviewer_id
             LEFT JOIN users u2 ON u2.id = rr.sign_off_by
             WHERE rr.id = ?",
            [$id]
        );

        if (!$review) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $items = Database::fetchAll(
            "SELECT rri.*,
                    r.title               AS risk_title,
                    r.risk_id             AS risk_code,
                    r.inherent_score      AS risk_inherent_score,
                    r.residual_score      AS risk_residual_score,
                    r.likelihood          AS risk_likelihood,
                    r.impact              AS risk_impact,
                    r.status              AS risk_status,
                    r.treatment_strategies AS risk_treatment_strategies,
                    r.owner_id            AS risk_owner_id,
                    u_owner.name          AS owner_name,
                    u_rev.name            AS reviewer_name,
                    rc.name               AS category_name,
                    rc.color              AS category_color
             FROM risk_review_items rri
             JOIN risks r              ON r.id   = rri.risk_id
             LEFT JOIN users u_owner   ON u_owner.id = r.owner_id
             LEFT JOIN users u_rev     ON u_rev.id   = rri.reviewed_by
             LEFT JOIN risk_categories rc ON rc.id  = r.category_id
             WHERE rri.review_id = ?
             ORDER BY
               CASE rri.status WHEN 'pending' THEN 0 WHEN 'escalated' THEN 1
                 WHEN 'reviewed' THEN 2 WHEN 'deferred' THEN 3 ELSE 4 END,
               r.inherent_score DESC",
            [$id]
        );

        // Group items by status
        $grouped = [
            'pending'        => [],
            'escalated'      => [],
            'reviewed'       => [],
            'deferred'       => [],
            'not_applicable' => [],
        ];
        foreach ($items as $item) {
            $s = $item['status'] ?? 'pending';
            if (!isset($grouped[$s])) $grouped[$s] = [];
            $grouped[$s][] = $item;
        }

        // Status counts
        $statusCounts = [];
        foreach ($grouped as $s => $grp) $statusCounts[$s] = count($grp);

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = Security::h($review['title']) . ' — Review';
        $activeModule = 'risk';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Review Sessions', '/risk/reviews'], [$review['title'], null]];
        require AEGIS_ROOT . '/views/risk/review_view.php';
    }

    // ─────────────────────────────────────────── updateItem ──────────────────
    public function updateItem(string $reviewId, string $riskId): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $reviewId = (int)$reviewId;
        $riskId   = (int)$riskId;

        $status            = Security::sanitizeInput($_POST['status'] ?? 'pending');
        $scoreConfirmed    = !empty($_POST['score_confirmed']);
        $newLikelihood     = !empty($_POST['new_likelihood']) ? max(1, min(5, (int)$_POST['new_likelihood'])) : null;
        $newImpact         = !empty($_POST['new_impact'])     ? max(1, min(5, (int)$_POST['new_impact']))     : null;
        $treatmentAdequate = $_POST['treatment_adequate'] ?? null;
        $actionRequired    = Security::sanitizeInput($_POST['action_required'] ?? '');
        $reviewerNotes     = Security::sanitizeInput($_POST['reviewer_notes']  ?? '');

        $validStatuses = ['pending', 'reviewed', 'escalated', 'deferred', 'not_applicable'];
        if (!in_array($status, $validStatuses, true)) $status = 'pending';

        $treatBool = null;
        if ($treatmentAdequate === 'yes')     $treatBool = 'TRUE';
        elseif ($treatmentAdequate === 'no')  $treatBool = 'FALSE';
        elseif ($treatmentAdequate === 'partial') $treatBool = null; // store null for partial

        Database::query(
            "UPDATE risk_review_items
             SET status             = ?,
                 score_confirmed    = ?,
                 new_likelihood     = ?,
                 new_impact         = ?,
                 treatment_adequate = ?,
                 action_required    = ?,
                 reviewer_notes     = ?,
                 reviewed_by        = ?,
                 reviewed_at        = NOW()
             WHERE review_id = ? AND risk_id = ?",
            [
                $status,
                $scoreConfirmed ? 'TRUE' : 'FALSE',
                $newLikelihood,
                $newImpact,
                $treatBool,
                $actionRequired ?: null,
                $reviewerNotes  ?: null,
                Auth::id(),
                $reviewId,
                $riskId,
            ]
        );

        // If new scores differ from confirmed (score_confirmed=false) and scores provided, update risk
        if (!$scoreConfirmed && $newLikelihood !== null && $newImpact !== null) {
            $current = Database::fetchOne("SELECT likelihood, impact FROM risks WHERE id = ?", [$riskId]);
            if ($current && ((int)$current['likelihood'] !== $newLikelihood || (int)$current['impact'] !== $newImpact)) {
                Database::query(
                    "UPDATE risks SET likelihood = ?, impact = ?, updated_at = NOW() WHERE id = ?",
                    [$newLikelihood, $newImpact, $riskId]
                );
                Database::insert('risk_score_history', [
                    'risk_id'    => $riskId,
                    'likelihood' => $newLikelihood,
                    'impact'     => $newImpact,
                    'score'      => $newLikelihood * $newImpact,
                    'changed_by' => Auth::id(),
                    'note'       => 'Updated during risk review #' . $reviewId,
                ]);
            }
        }

        // Recalculate review progress
        $counts = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status != 'pending') AS reviewed_count,
               COUNT(*) FILTER (WHERE status = 'escalated') AS escalated_count
             FROM risk_review_items WHERE review_id = ?",
            [$reviewId]
        );

        Database::query(
            "UPDATE risk_reviews
             SET reviewed_count  = ?,
                 escalated_count = ?,
                 updated_at      = NOW()
             WHERE id = ?",
            [(int)$counts['reviewed_count'], (int)$counts['escalated_count'], $reviewId]
        );

        Auth::log('update_review_item', 'risk_reviews', $reviewId, [
            'risk_id' => $riskId,
            'status'  => $status,
        ]);

        header('Location: /risk/reviews/' . $reviewId);
    }

    // ─────────────────────────────────────────── start ───────────────────────
    public function start(string $id): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id = (int)$id;
        Database::query(
            "UPDATE risk_reviews SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status = 'planned'",
            [$id]
        );
        Auth::log('start_risk_review', 'risk_reviews', $id, []);
        header('Location: /risk/reviews/' . $id);
    }

    // ─────────────────────────────────────────── complete ────────────────────
    public function complete(string $id): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id = (int)$id;

        // Check for pending items
        $pendingCount = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM risk_review_items WHERE review_id = ? AND status = 'pending'",
            [$id]
        );
        if ((int)($pendingCount['c'] ?? 0) > 0) {
            $_SESSION['flash_error'] = 'Cannot complete: ' . (int)$pendingCount['c'] . ' item(s) are still pending review.';
            header('Location: /risk/reviews/' . $id);
            return;
        }

        $conclusion    = Security::sanitizeInput($_POST['conclusion']    ?? '');
        $signOffNotes  = Security::sanitizeInput($_POST['sign_off_notes'] ?? '');

        Database::query(
            "UPDATE risk_reviews
             SET status         = 'completed',
                 completed_date = CURRENT_DATE,
                 conclusion     = ?,
                 sign_off_by    = ?,
                 sign_off_at    = NOW(),
                 sign_off_notes = ?,
                 updated_at     = NOW()
             WHERE id = ?",
            [$conclusion ?: null, Auth::id(), $signOffNotes ?: null, $id]
        );

        // Update review_date on all reviewed risks based on inherent_score
        $riskIds = Database::fetchAll(
            "SELECT rri.risk_id
             FROM risk_review_items rri
             WHERE rri.review_id = ? AND rri.status != 'pending'",
            [$id]
        );

        if (!empty($riskIds)) {
            $ids = array_column($riskIds, 'risk_id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            Database::query(
                "UPDATE risks
                 SET review_date = CURRENT_DATE + (
                     CASE WHEN inherent_score > 14 THEN 90
                          WHEN inherent_score > 9  THEN 180
                          ELSE 365
                     END
                 ) * INTERVAL '1 day',
                 updated_at = NOW()
                 WHERE id IN ({$ph})",
                $ids
            );
        }

        Auth::log('complete_risk_review', 'risk_reviews', $id, ['conclusion' => $conclusion]);
        header('Location: /risk/reviews/' . $id . '?completed=1');
    }

    // ─────────────────────────────────────────── cancel ──────────────────────
    public function cancel(string $id): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id = (int)$id;
        Database::query(
            "UPDATE risk_reviews SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
            [$id]
        );
        Auth::log('cancel_risk_review', 'risk_reviews', $id, []);
        header('Location: /risk/reviews');
    }
}
