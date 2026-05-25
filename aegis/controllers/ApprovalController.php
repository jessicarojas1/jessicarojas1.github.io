<?php
/**
 * ApprovalController — multi-level approval chains.
 *
 * Approval templates define who must approve and in what order.
 * When a risk is being accepted, or a policy published, AEGIS checks for
 * a matching template and blocks the status change until all steps approve.
 *
 * Key methods called by other controllers:
 *   ApprovalController::requiresApproval($entityType, $entityData) → bool
 *   ApprovalController::createRequest($entityType, $entityId, $entityData) → int|null
 *   ApprovalController::isPending($entityType, $entityId) → bool
 */
class ApprovalController {

    // ── External API (called by other controllers) ────────────────────────────

    /**
     * Check if a matching active template exists for this entity+state.
     */
    public static function requiresApproval(string $entityType, array $entityData): bool {
        return self::findTemplate($entityType, $entityData) !== null;
    }

    /**
     * Check if there's already a pending approval request for this entity.
     */
    public static function isPending(string $entityType, int $entityId): bool {
        $row = Database::fetchOne(
            "SELECT id FROM approval_requests WHERE entity_type = ? AND entity_id = ? AND status = 'pending'",
            [$entityType, $entityId]
        );
        return $row !== null;
    }

    /**
     * Create an approval request. Returns the new request ID, or null if no template matched.
     */
    public static function createRequest(string $entityType, int $entityId, array $entityData): ?int {
        $template = self::findTemplate($entityType, $entityData);
        if (!$template) return null;

        $steps = Database::fetchAll(
            "SELECT * FROM approval_template_steps WHERE template_id = ? ORDER BY step_number",
            [$template['id']]
        );
        if (empty($steps)) return null;

        Database::query(
            "INSERT INTO approval_requests (template_id, entity_type, entity_id, requested_by, current_step, status, context_data)
             VALUES (?, ?, ?, ?, 1, 'pending', ?)",
            [$template['id'], $entityType, $entityId, Auth::id(), json_encode($entityData)]
        );
        $req = Database::fetchOne(
            "SELECT id FROM approval_requests WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [$entityType, $entityId]
        );
        if (!$req) return null;
        $reqId = $req['id'];

        // Insert step records
        foreach ($steps as $step) {
            $dueAt = date('Y-m-d H:i:s', time() + ($step['due_hours'] * 3600));
            Database::query(
                "INSERT INTO approval_request_steps (request_id, step_number, label, required_role, required_user_id, due_at)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$reqId, $step['step_number'], $step['label'],
                 $step['required_role'], $step['required_user_id'], $dueAt]
            );
        }

        // Notify first-step approvers
        self::notifyStep($reqId, 1);
        Auth::log("approval_requested", $entityType, $entityId, ['template' => $template['name']]);

        return $reqId;
    }

    // ── Web routes ────────────────────────────────────────────────────────────

    public function pending(): void {
        Auth::requireAuth();
        $userId = Auth::id();
        $role   = Auth::role();

        // Requests where the current step is waiting for this user or their role
        $requests = Database::fetchAll(
            "SELECT ar.*, at.name as template_name, at.entity_type,
                    ars.label as step_label, ars.required_role, ars.required_user_id, ars.due_at
             FROM approval_requests ar
             JOIN approval_templates at ON ar.template_id = at.id
             JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
             WHERE ar.status = 'pending'
               AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')
             ORDER BY ars.due_at ASC",
            [$userId, $role, $role]
        );

        $pageTitle    = 'Pending Approvals';
        $activeModule = 'approvals';
        $breadcrumbs  = [['Approvals', null]];
        ob_start();
        require AEGIS_ROOT . '/views/approval/pending.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function review(string $id): void {
        Auth::requireAuth();
        $id      = (int)$id;
        $userId  = Auth::id();
        $role    = Auth::role();

        $req = Database::fetchOne(
            "SELECT ar.*, at.name as template_name, at.entity_type
             FROM approval_requests ar
             JOIN approval_templates at ON ar.template_id = at.id
             WHERE ar.id = ?",
            [$id]
        );
        if (!$req) { http_response_code(404); echo 'Request not found.'; return; }

        $steps = Database::fetchAll(
            "SELECT ars.*, u.name as actioned_by_name
             FROM approval_request_steps ars
             LEFT JOIN users u ON ars.actioned_by = u.id
             WHERE ars.request_id = ?
             ORDER BY ars.step_number",
            [$id]
        );

        $currentStep = Database::fetchOne(
            "SELECT * FROM approval_request_steps WHERE request_id = ? AND step_number = ?",
            [$id, $req['current_step']]
        );

        $canAct = $req['status'] === 'pending' && $currentStep &&
            ($role === 'admin'
             || $currentStep['required_user_id'] === $userId
             || $currentStep['required_role'] === $role);

        // Resolve entity label for display
        $entityLabel = self::entityLabel($req['entity_type'], $req['entity_id']);

        $pageTitle    = 'Approval Review';
        $activeModule = 'approvals';
        $breadcrumbs  = [['Approvals', '/approvals'], ["#{$id}", null]];
        ob_start();
        require AEGIS_ROOT . '/views/approval/review.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function decide(string $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id       = (int)$id;
        $decision = $_POST['decision'] ?? '';
        $notes    = Security::sanitizeInput($_POST['notes'] ?? '');
        $userId   = Auth::id();
        $role     = Auth::role();

        if (!in_array($decision, ['approved', 'rejected'])) {
            $_SESSION['flash_error'] = 'Invalid decision.';
            header("Location: /approvals/{$id}"); exit;
        }

        $req = Database::fetchOne(
            "SELECT ar.*, at.entity_type FROM approval_requests ar
             JOIN approval_templates at ON ar.template_id = at.id WHERE ar.id = ?",
            [$id]
        );
        if (!$req || $req['status'] !== 'pending') {
            $_SESSION['flash_error'] = 'Request not found or already closed.';
            header('Location: /approvals'); exit;
        }

        $currentStep = Database::fetchOne(
            "SELECT * FROM approval_request_steps WHERE request_id = ? AND step_number = ?",
            [$id, $req['current_step']]
        );

        // Authorization
        $canAct = $role === 'admin'
            || $currentStep['required_user_id'] === $userId
            || $currentStep['required_role'] === $role;

        if (!$canAct) {
            http_response_code(403);
            $_SESSION['flash_error'] = 'You are not authorised to act on this step.';
            header("Location: /approvals/{$id}"); exit;
        }

        // Record the decision on this step
        Database::query(
            "UPDATE approval_request_steps
             SET decision = ?, notes = ?, actioned_by = ?, actioned_at = NOW()
             WHERE request_id = ? AND step_number = ?",
            [$decision, $notes, $userId, $id, $req['current_step']]
        );

        if ($decision === 'rejected') {
            Database::query(
                "UPDATE approval_requests SET status = 'rejected', completed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$id]
            );
            Auth::log('approval_rejected', $req['entity_type'], $req['entity_id']);
            $_SESSION['flash_error'] = 'Request rejected.';
            header('Location: /approvals'); exit;
        }

        // Approved — advance to next step or finalize
        $nextStep = Database::fetchOne(
            "SELECT * FROM approval_template_steps
             WHERE template_id = ? AND step_number > ?
             ORDER BY step_number LIMIT 1",
            [$req['template_id'], $req['current_step']]
        );

        if ($nextStep) {
            Database::query(
                "UPDATE approval_requests SET current_step = ?, updated_at = NOW() WHERE id = ?",
                [$nextStep['step_number'], $id]
            );
            self::notifyStep($id, $nextStep['step_number']);
            $_SESSION['flash_success'] = 'Step approved — next approver notified.';
        } else {
            // All steps done — finalize
            Database::query(
                "UPDATE approval_requests SET status = 'approved', completed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$id]
            );
            self::applyApproval($req['entity_type'], $req['entity_id'], json_decode($req['context_data'], true) ?? []);
            Auth::log('approval_completed', $req['entity_type'], $req['entity_id']);
            $_SESSION['flash_success'] = 'All steps approved — change applied.';
        }

        header('Location: /approvals'); exit;
    }

    public function templates(): void {
        Auth::requireAdmin();
        $templates = Database::fetchAll("SELECT * FROM approval_templates ORDER BY entity_type, name");
        $pageTitle    = 'Approval Templates';
        $activeModule = 'admin_approvals';
        $breadcrumbs  = [['Administration', '/admin'], ['Approval Templates', null]];
        ob_start();
        require AEGIS_ROOT . '/views/approval/templates.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function findTemplate(string $entityType, array $entityData): ?array {
        $templates = Database::fetchAll(
            "SELECT * FROM approval_templates WHERE entity_type = ? AND is_active = TRUE",
            [$entityType]
        );
        foreach ($templates as $t) {
            $cond = json_decode($t['trigger_condition'], true) ?? [];
            if (self::matchesCondition($cond, $entityData)) return $t;
        }
        return null;
    }

    private static function matchesCondition(array $cond, array $data): bool {
        if (empty($cond)) return true;

        if (isset($cond['min_score'])) {
            $score = $data['inherent_score'] ?? $data['score'] ?? 0;
            if ($score < $cond['min_score']) return false;
        }
        if (isset($cond['status_change'])) {
            if (($data['new_status'] ?? '') !== $cond['status_change']) return false;
        }
        if (isset($cond['risk_tier'])) {
            if (($data['risk_tier'] ?? '') !== $cond['risk_tier']) return false;
        }
        return true;
    }

    private static function notifyStep(int $requestId, int $stepNumber): void {
        $step = Database::fetchOne(
            "SELECT ars.*, ar.entity_type, ar.entity_id, at.name as template_name
             FROM approval_request_steps ars
             JOIN approval_requests ar ON ars.request_id = ar.id
             JOIN approval_templates at ON ar.template_id = at.id
             WHERE ars.request_id = ? AND ars.step_number = ?",
            [$requestId, $stepNumber]
        );
        if (!$step) return;

        $title   = "Approval required: {$step['template_name']} — {$step['label']}";
        $message = "You have a pending approval request. Please review at /approvals/{$requestId}";

        // In-app alerts
        $recipientIds = [];
        if ($step['required_user_id']) {
            $recipientIds[] = $step['required_user_id'];
        } elseif ($step['required_role']) {
            $users = Database::fetchAll(
                "SELECT id FROM users WHERE role = ? AND is_active = TRUE",
                [$step['required_role']]
            );
            $recipientIds = array_column($users, 'id');
        }

        foreach ($recipientIds as $uid) {
            Database::query(
                "INSERT INTO alerts (type, title, message, severity, user_id, related_type, related_id)
                 VALUES ('approval', ?, ?, 'warning', ?, 'approval_requests', ?)",
                [$title, $message, $uid, $requestId]
            );
        }
    }

    private static function applyApproval(string $entityType, int $entityId, array $ctx): void {
        // Apply the originally-requested status change now that approval is complete
        $newStatus = $ctx['new_status'] ?? null;
        if (!$newStatus) return;

        $table = match ($entityType) {
            'risk'     => 'risks',
            'policy'   => 'policies',
            'audit'    => 'audits',
            'vendor'   => 'vendors',
            'incident' => 'incidents',
            default    => null,
        };
        if (!$table) return;

        Database::query(
            "UPDATE {$table} SET status = ?, updated_at = NOW() WHERE id = ?",
            [$newStatus, $entityId]
        );
    }

    private static function entityLabel(string $entityType, int $entityId): string {
        $tableMap = [
            'risk'     => ['risks',     'title'],
            'policy'   => ['policies',  'title'],
            'audit'    => ['audits',    'name'],
            'vendor'   => ['vendors',   'name'],
            'incident' => ['incidents', 'title'],
        ];
        $info = $tableMap[$entityType] ?? null;
        if (!$info) return "{$entityType} #{$entityId}";
        $row = Database::fetchOne("SELECT {$info[1]} as label FROM {$info[0]} WHERE id = ?", [$entityId]);
        return $row['label'] ?? "{$entityType} #{$entityId}";
    }
}
