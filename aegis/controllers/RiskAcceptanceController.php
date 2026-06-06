<?php
declare(strict_types=1);

class RiskAcceptanceController {

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function scoreToLevel(int $score): string {
        if ($score > 14) return 'critical';
        if ($score > 9)  return 'high';
        if ($score > 4)  return 'medium';
        return 'low';
    }

    // ── index ─────────────────────────────────────────────────────────────────
    public function index(): void {
        Auth::requirePermission('risk.view');

        $acceptances = Database::fetchAll(
            "SELECT ra.*,
                    r.title          AS risk_title,
                    r.risk_id        AS risk_code,
                    r.inherent_score AS risk_inherent_score,
                    r.treatment_strategies AS risk_treatment_strategies,
                    u_acc.name       AS acceptor_name,
                    u_rev.name       AS revoker_name
             FROM risk_acceptances ra
             JOIN risks r        ON r.id  = ra.risk_id
             JOIN users u_acc    ON u_acc.id = ra.accepted_by
             LEFT JOIN users u_rev ON u_rev.id = ra.revoked_by
             ORDER BY
               CASE ra.status
                 WHEN 'active'     THEN 0
                 WHEN 'expired'    THEN 1
                 WHEN 'superseded' THEN 2
                 WHEN 'revoked'    THEN 3
                 ELSE 4
               END,
               ra.created_at DESC"
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'active')     AS active_count,
               COUNT(*) FILTER (WHERE status = 'expired')    AS expired_count,
               COUNT(*) FILTER (WHERE status = 'revoked')    AS revoked_count,
               COUNT(*) FILTER (WHERE status = 'superseded') AS superseded_count,
               COUNT(*) FILTER (
                   WHERE status = 'active'
                     AND valid_until BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
               ) AS expiring_soon_count
             FROM risk_acceptances"
        );

        $pageTitle    = 'Risk Acceptance Certificates';
        $activeModule = 'risk_acceptances';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Acceptance Certificates', null]];

        ob_start();
        require AEGIS_ROOT . '/views/risk/acceptances.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ── createForm ────────────────────────────────────────────────────────────
    public function createForm(string $riskId): void {
        Auth::requirePermission('risk.accept');

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne(
            "SELECT r.*, rc.name AS category_name
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             WHERE r.id = ?",
            [$riskId]
        );

        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $existingActive = Database::fetchOne(
            "SELECT ra.*, u.name AS acceptor_name
             FROM risk_acceptances ra
             JOIN users u ON u.id = ra.accepted_by
             WHERE ra.risk_id = ? AND ra.status = 'active'
             LIMIT 1",
            [$riskId]
        );

        $renewFrom   = null;    // not a renewal
        $prefill     = [];      // no prefill for fresh form
        $currentUser = Auth::user();

        $pageTitle    = 'Issue Acceptance Certificate';
        $activeModule = 'risk_acceptances';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [Security::h($risk['title']), '/risk/' . $riskId],
            ['Issue Acceptance', null],
        ];

        ob_start();
        require AEGIS_ROOT . '/views/risk/acceptance_form.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ── create ────────────────────────────────────────────────────────────────
    public function create(string $riskId): void {
        Auth::requirePermission('risk.accept');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne(
            "SELECT id, title, risk_id, inherent_score, treatment_strategies FROM risks WHERE id = ?",
            [$riskId]
        );
        if (!$risk) {
            http_response_code(404);
            return;
        }

        $acceptanceReason  = Security::sanitizeInput($_POST['acceptance_reason'] ?? '');
        $conditions        = Security::sanitizeInput($_POST['conditions'] ?? '');
        $validUntil        = Security::sanitizeInput($_POST['valid_until'] ?? '');
        $renewalRequired   = !empty($_POST['renewal_required']);
        $renewedFromRaw    = !empty($_POST['renewed_from']) ? (int)$_POST['renewed_from'] : null;

        // Validate required fields
        if (!$acceptanceReason) {
            $_SESSION['flash_error'] = 'Acceptance reason is required.';
            $redirect = $renewedFromRaw
                ? '/risk-acceptances/' . $renewedFromRaw . '/renew'
                : '/risk/' . $riskId . '/accept';
            header('Location: ' . $redirect);
            return;
        }

        if (!$validUntil) {
            $_SESSION['flash_error'] = 'Valid Until date is required.';
            $redirect = $renewedFromRaw
                ? '/risk-acceptances/' . $renewedFromRaw . '/renew'
                : '/risk/' . $riskId . '/accept';
            header('Location: ' . $redirect);
            return;
        }

        $parsedDate = date_create($validUntil);
        if (!$parsedDate || $parsedDate <= new DateTime('today')) {
            $_SESSION['flash_error'] = 'Valid Until must be a future date.';
            $redirect = $renewedFromRaw
                ? '/risk-acceptances/' . $renewedFromRaw . '/renew'
                : '/risk/' . $riskId . '/accept';
            header('Location: ' . $redirect);
            return;
        }
        $validUntilDb = date_format($parsedDate, 'Y-m-d');

        // Validate renewed_from if set
        $renewedFrom = null;
        if ($renewedFromRaw !== null) {
            $oldAcceptance = Database::fetchOne(
                "SELECT id, risk_id FROM risk_acceptances WHERE id = ?",
                [$renewedFromRaw]
            );
            if ($oldAcceptance && (int)$oldAcceptance['risk_id'] === $riskId) {
                $renewedFrom = $renewedFromRaw;
            }
        }

        // Capture score & level at time of acceptance
        $scoreAtAcceptance = (int)$risk['inherent_score'];
        $levelAtAcceptance = self::scoreToLevel($scoreAtAcceptance);

        // Supersede any existing active acceptance for this risk
        $existing = Database::fetchOne(
            "SELECT id FROM risk_acceptances WHERE risk_id = ? AND status = 'active' LIMIT 1",
            [$riskId]
        );
        if ($existing) {
            Database::query(
                "UPDATE risk_acceptances SET status = 'superseded', updated_at = NOW() WHERE id = ?",
                [(int)$existing['id']]
            );
        }

        $newId = Database::insert('risk_acceptances', [
            'risk_id'                  => $riskId,
            'accepted_by'              => Auth::id(),
            'acceptance_reason'        => $acceptanceReason,
            'conditions'               => $conditions ?: null,
            'valid_until'              => $validUntilDb,
            'status'                   => 'active',
            'risk_score_at_acceptance' => $scoreAtAcceptance,
            'risk_level_at_acceptance' => $levelAtAcceptance,
            'renewal_required'         => $renewalRequired ? 'TRUE' : 'FALSE',
            'renewed_from'             => $renewedFrom,
            'created_at'               => date('Y-m-d H:i:s'),
            'updated_at'               => date('Y-m-d H:i:s'),
        ]);

        Auth::log('risk_acceptance_created', 'risk_acceptances', $newId, [
            'risk_id'       => $riskId,
            'valid_until'   => $validUntilDb,
            'renewed_from'  => $renewedFrom,
            'score'         => $scoreAtAcceptance,
            'level'         => $levelAtAcceptance,
        ]);

        $_SESSION['flash_success'] = 'Acceptance certificate issued successfully.';
        header('Location: /risk/' . $riskId);
    }

    // ── revoke ────────────────────────────────────────────────────────────────
    public function revoke(string $id): void {
        Auth::requirePermission('risk.accept');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;
        $acceptance = Database::fetchOne(
            "SELECT * FROM risk_acceptances WHERE id = ?",
            [$id]
        );

        if (!$acceptance) {
            http_response_code(404);
            return;
        }

        $revocationReason = Security::sanitizeInput($_POST['revocation_reason'] ?? '');

        Database::query(
            "UPDATE risk_acceptances
             SET status            = 'revoked',
                 revoked_by        = ?,
                 revoked_at        = NOW(),
                 revocation_reason = ?,
                 updated_at        = NOW()
             WHERE id = ?",
            [Auth::id(), $revocationReason ?: null, $id]
        );

        Auth::log('risk_acceptance_revoked', 'risk_acceptances', $id, [
            'risk_id'           => (int)$acceptance['risk_id'],
            'revocation_reason' => $revocationReason,
        ]);

        $_SESSION['flash_success'] = 'Acceptance certificate has been revoked.';
        header('Location: /risk-acceptances');
    }

    // ── renew (GET — shows form pre-populated from existing acceptance) ────────
    public function renew(string $id): void {
        Auth::requirePermission('risk.accept');

        $id = (int)$id;
        $oldAcceptance = Database::fetchOne(
            "SELECT ra.*, r.title AS risk_title
             FROM risk_acceptances ra
             JOIN risks r ON r.id = ra.risk_id
             WHERE ra.id = ?",
            [$id]
        );

        if (!$oldAcceptance) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $riskId = (int)$oldAcceptance['risk_id'];
        $risk   = Database::fetchOne(
            "SELECT r.*, rc.name AS category_name
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             WHERE r.id = ?",
            [$riskId]
        );

        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $existingActive = Database::fetchOne(
            "SELECT ra.*, u.name AS acceptor_name
             FROM risk_acceptances ra
             JOIN users u ON u.id = ra.accepted_by
             WHERE ra.risk_id = ? AND ra.status = 'active'
             LIMIT 1",
            [$riskId]
        );

        $renewFrom   = $oldAcceptance;   // used in view to set renewed_from hidden field
        $prefill     = [
            'acceptance_reason' => $oldAcceptance['acceptance_reason'],
            'conditions'        => $oldAcceptance['conditions'] ?? '',
            'renewal_required'  => (bool)$oldAcceptance['renewal_required'],
        ];
        $currentUser = Auth::user();

        $pageTitle    = 'Renew Acceptance Certificate';
        $activeModule = 'risk_acceptances';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [Security::h($risk['title']), '/risk/' . $riskId],
            ['Renew Acceptance', null],
        ];

        ob_start();
        require AEGIS_ROOT . '/views/risk/acceptance_form.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }
}
