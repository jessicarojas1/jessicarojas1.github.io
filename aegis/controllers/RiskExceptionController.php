<?php
class RiskExceptionController {

    public function index(): void {
        Auth::requireAuth();

        $userId = Auth::id();
        $role   = Auth::role();
        $isMgr  = in_array($role, ['admin', 'manager'], true);

        $params = [];
        if ($isMgr) {
            $sql = "SELECT re.*,
                           r.title  AS risk_title,
                           r.risk_id AS risk_code,
                           u1.name  AS requester_name,
                           u2.name  AS approver_name
                    FROM risk_exceptions re
                    LEFT JOIN risks r  ON re.risk_id      = r.id
                    LEFT JOIN users u1 ON re.requested_by = u1.id
                    LEFT JOIN users u2 ON re.approved_by  = u2.id
                    ORDER BY
                        CASE re.status WHEN 'pending' THEN 0 ELSE 1 END,
                        re.created_at DESC";
        } else {
            $sql    = "SELECT re.*,
                              r.title  AS risk_title,
                              r.risk_id AS risk_code,
                              u1.name  AS requester_name,
                              u2.name  AS approver_name
                       FROM risk_exceptions re
                       LEFT JOIN risks r  ON re.risk_id      = r.id
                       LEFT JOIN users u1 ON re.requested_by = u1.id
                       LEFT JOIN users u2 ON re.approved_by  = u2.id
                       WHERE re.requested_by = ?
                       ORDER BY
                           CASE re.status WHEN 'pending' THEN 0 ELSE 1 END,
                           re.created_at DESC";
            $params = [$userId];
        }

        $exceptions = Database::fetchAll($sql, $params);

        // Summary stats (always global for dashboard awareness)
        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'pending')  AS pending_count,
               COUNT(*) FILTER (WHERE status = 'approved') AS approved_count,
               COUNT(*) FILTER (
                   WHERE status = 'approved'
                     AND expiry_date IS NOT NULL
                     AND expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
               ) AS expiring_soon_count
             FROM risk_exceptions"
        );

        $pageTitle    = 'Risk Exceptions & Waivers';
        $activeModule = 'risk_exceptions';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Exceptions & Waivers', null]];

        require AEGIS_ROOT . '/views/risk/exceptions.php';
    }

    public function createForm(string $riskId): void {
        Auth::requireAuth();

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne(
            "SELECT r.*, rc.name AS category_name
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             WHERE r.id = ?",
            [$riskId]
        );

        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $pageTitle    = 'Request Risk Exception';
        $activeModule = 'risk_exceptions';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [Security::h($risk['title']), '/risk/' . $riskId],
            ['Request Exception', null],
        ];

        require AEGIS_ROOT . '/views/risk/exception_create.php';
    }

    public function create(string $riskId): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne("SELECT id, title FROM risks WHERE id = ?", [$riskId]);
        if (!$risk) {
            http_response_code(404);
            return;
        }

        $rationale   = Security::sanitizeInput($_POST['rationale'] ?? '');
        $controls    = Security::sanitizeInput($_POST['compensating_controls'] ?? '');
        $expiryDate  = Security::sanitizeInput($_POST['expiry_date'] ?? '');
        $exceptionType = Security::sanitizeInput($_POST['exception_type'] ?? 'accept');
        $ackChecked  = !empty($_POST['residual_risk_acknowledged']);

        if (!$rationale) {
            $_SESSION['exception_error'] = 'Rationale is required.';
            header('Location: /risk/' . $riskId . '/exception/create');
            return;
        }

        $allowedTypes = ['accept', 'transfer', 'defer'];
        if (!in_array($exceptionType, $allowedTypes, true)) {
            $exceptionType = 'accept';
        }

        // Validate expiry date is in the future if provided
        $expiryDateDb = null;
        if ($expiryDate) {
            $parsed = date_create($expiryDate);
            if (!$parsed || $parsed <= new DateTime('today')) {
                $_SESSION['exception_error'] = 'Expiry date must be in the future.';
                header('Location: /risk/' . $riskId . '/exception/create');
                return;
            }
            $expiryDateDb = date_format($parsed, 'Y-m-d');
        }

        $id = Database::insert('risk_exceptions', [
            'risk_id'                    => $riskId,
            'requested_by'               => Auth::id(),
            'status'                     => 'pending',
            'exception_type'             => $exceptionType,
            'rationale'                  => $rationale,
            'compensating_controls'      => $controls ?: null,
            'residual_risk_acknowledged' => $ackChecked,
            'expiry_date'                => $expiryDateDb,
            'created_at'                 => date('Y-m-d H:i:s'),
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]);

        Auth::log('create_risk_exception', 'risk_exceptions', $id, [
            'risk_id'        => $riskId,
            'exception_type' => $exceptionType,
        ]);

        $_SESSION['exception_success'] = 'Exception request submitted successfully.';
        header('Location: /risk/exceptions');
    }

    public function view(string $id): void {
        Auth::requireAuth();

        $id        = (int)$id;
        $exception = Database::fetchOne(
            "SELECT re.*,
                    r.title   AS risk_title,
                    r.risk_id AS risk_code,
                    r.id      AS risk_db_id,
                    u1.name   AS requester_name,
                    u2.name   AS approver_name
             FROM risk_exceptions re
             LEFT JOIN risks r  ON re.risk_id      = r.id
             LEFT JOIN users u1 ON re.requested_by = u1.id
             LEFT JOIN users u2 ON re.approved_by  = u2.id
             WHERE re.id = ?",
            [$id]
        );

        if (!$exception) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $pageTitle    = 'Risk Exception #' . $id;
        $activeModule = 'risk_exceptions';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            ['Exceptions & Waivers', '/risk/exceptions'],
            ['Exception #' . $id, null],
        ];

        require AEGIS_ROOT . '/views/risk/exception_view.php';
    }

    public function decide(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id     = (int)$id;
        $action = Security::sanitizeInput($_POST['action'] ?? '');

        $exception = Database::fetchOne("SELECT * FROM risk_exceptions WHERE id = ?", [$id]);
        if (!$exception) {
            http_response_code(404);
            return;
        }

        if ($action === 'approve') {
            Database::query(
                "UPDATE risk_exceptions
                 SET status = 'approved',
                     approved_by = ?,
                     approved_at = NOW(),
                     updated_at  = NOW()
                 WHERE id = ?",
                [Auth::id(), $id]
            );
            Auth::log('approve_risk_exception', 'risk_exceptions', $id, ['status' => 'approved']);

        } elseif ($action === 'reject') {
            $reason = Security::sanitizeInput($_POST['rejection_reason'] ?? '');
            Database::query(
                "UPDATE risk_exceptions
                 SET status = 'rejected',
                     rejected_at = NOW(),
                     rejection_reason = ?,
                     updated_at  = NOW()
                 WHERE id = ?",
                [$reason, $id]
            );
            Auth::log('reject_risk_exception', 'risk_exceptions', $id, [
                'status' => 'rejected',
                'reason' => $reason,
            ]);
        }

        header('Location: /risk/exceptions');
    }

    public function checkExpired(): void {
        $isCli = php_sapi_name() === 'cli';
        if (!$isCli) {
            Auth::requireAdmin();
        }

        // Mark approved exceptions past their expiry date as expired
        Database::query(
            "UPDATE risk_exceptions
             SET status = 'expired', updated_at = NOW()
             WHERE status = 'approved'
               AND expiry_date IS NOT NULL
               AND expiry_date < CURRENT_DATE"
        );

        // Get count of rows just updated
        $result = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM risk_exceptions WHERE status = 'expired' AND updated_at >= NOW() - INTERVAL '5 seconds'"
        );
        $count = (int)($result['c'] ?? 0);

        if ($isCli) {
            echo "Marked {$count} exception(s) as expired.\n";
        }
    }
}
