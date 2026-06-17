<?php
declare(strict_types=1);

class ApprovalController {

    public function index(): void {
        Auth::requirePermission('approval.view');
        $uid = Auth::id(); $role = Auth::role();

        $pending = Database::fetchAll(
            "SELECT ar.*, u.name AS requester,
                    ars.name AS step_name, ars.required_role, ars.required_user_id
             FROM approval_requests ar
             LEFT JOIN users u ON u.id = ar.requested_by
             JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.status='pending'
                  AND (ar.approval_mode IN ('parallel','consensus') OR ars.step_number = ar.current_step)
             WHERE ar.status='pending'
               AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')
             ORDER BY ar.due_at NULLS LAST, ar.created_at", [$uid, $role, $role]
        );
        $mine = Database::fetchAll(
            "SELECT ar.*, u.name AS requester FROM approval_requests ar LEFT JOIN users u ON u.id=ar.requested_by
             WHERE ar.requested_by=? ORDER BY ar.created_at DESC LIMIT 30", [$uid]
        );
        $all = [];
        if ($role === 'admin' || Auth::can('report.view')) {
            $all = Database::fetchAll(
                "SELECT ar.*, u.name AS requester FROM approval_requests ar LEFT JOIN users u ON u.id=ar.requested_by
                 ORDER BY ar.created_at DESC LIMIT 50"
            );
        }
        require PALADIN_ROOT . '/views/approvals/index.php';
    }

    public function startForm(): void {
        Auth::requirePermission('approval.view');
        $templates = Database::fetchAll("SELECT id, name, workflow_type, approval_mode FROM workflow_templates WHERE is_active=TRUE ORDER BY name");
        $users     = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $prefill   = [
            'entity_type' => Security::sanitizeInput($_GET['entity_type'] ?? ''),
            'entity_id'   => (int)($_GET['entity_id'] ?? 0),
            'title'       => Security::sanitizeInput($_GET['title'] ?? ''),
        ];
        require PALADIN_ROOT . '/views/approvals/start.php';
    }

    public function start(): void {
        Auth::requirePermission('approval.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        if ($title === '') { $_SESSION['flash_error'] = 'A title is required.'; header('Location: /approvals/start'); return; }

        $templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '') ?: null;
        $entityId   = !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null;

        $steps = [];
        $mode  = 'sequential';
        if ($templateId) {
            $tpl = Database::fetchOne("SELECT * FROM workflow_templates WHERE id=? AND is_active=TRUE", [$templateId]);
            if (!$tpl) { $_SESSION['flash_error'] = 'Invalid workflow template.'; header('Location: /approvals/start'); return; }
            $mode  = $tpl['approval_mode'];
            $steps = Database::fetchAll("SELECT * FROM workflow_steps WHERE template_id=? ORDER BY step_number", [$templateId]);
        }
        // Ad-hoc single approver fallback
        if (!$steps) {
            $approverId = !empty($_POST['approver_id']) ? (int)$_POST['approver_id'] : null;
            $approverRole = Security::sanitizeInput($_POST['approver_role'] ?? '') ?: ($approverId ? null : 'approver');
            $mode = 'single';
            $steps = [['step_number' => 1, 'name' => 'Approval', 'approver_role' => $approverRole, 'approver_user_id' => $approverId, 'sla_hours' => 72]];
        }

        $firstSla = (int)($steps[0]['sla_hours'] ?? 72);
        $reqId = Database::insert('approval_requests', [
            'title' => $title, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'template_id' => $templateId, 'approval_mode' => $mode, 'status' => 'pending', 'current_step' => 1,
            'requested_by' => Auth::id(),
            'due_at' => date('Y-m-d H:i:s', time() + $firstSla * 3600),
        ]);
        $n = 0;
        foreach ($steps as $s) {
            $n++;
            $due = date('Y-m-d H:i:s', time() + (int)($s['sla_hours'] ?? 72) * 3600);
            Database::insert('approval_request_steps', [
                'request_id' => $reqId, 'step_number' => $n, 'name' => $s['name'] ?? ('Step ' . $n),
                'required_role' => $s['approver_role'] ?? null, 'required_user_id' => $s['approver_user_id'] ?? null,
                'status' => 'pending', 'due_at' => $due,
            ]);
        }
        Database::insert('approval_history', ['request_id' => $reqId, 'user_id' => Auth::id(), 'action' => 'submitted', 'comment' => 'Approval requested.']);

        // Move a linked document into review
        if ($entityType === 'document' && $entityId) {
            $d = Database::fetchOne("SELECT status FROM documents WHERE id=?", [$entityId]);
            if ($d && $d['status'] === 'draft') Database::update('documents', ['status' => 'in_review'], 'id=?', [$entityId]);
        }
        $this->notifyActiveApprovers($reqId);
        Auth::log('start_approval', 'approval_requests', $reqId, ['title' => $title]);
        Webhook::dispatch('approval.requested', ['id' => $reqId, 'title' => $title, 'actor' => Auth::id()]);
        $_SESSION['flash_success'] = 'Approval request routed.';
        header('Location: /approvals/' . $reqId);
    }

    public function view(int $id): void {
        Auth::requirePermission('approval.view');
        $req = Database::fetchOne("SELECT ar.*, u.name AS requester FROM approval_requests ar LEFT JOIN users u ON u.id=ar.requested_by WHERE ar.id=?", [$id]);
        if (!$req) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $steps = Database::fetchAll(
            "SELECT ars.*, du.name AS decided_by_name, ru.name AS required_user_name
             FROM approval_request_steps ars LEFT JOIN users du ON du.id=ars.decided_by LEFT JOIN users ru ON ru.id=ars.required_user_id
             WHERE ars.request_id=? ORDER BY ars.step_number", [$id]
        );
        $history = Database::fetchAll(
            "SELECT ah.*, u.name AS user_name FROM approval_history ah LEFT JOIN users u ON u.id=ah.user_id
             WHERE ah.request_id=? ORDER BY ah.created_at", [$id]
        );
        $canDecide = $this->actionableStep($req, Auth::id(), Auth::role()) !== null;
        $esignRequired = Workflow::esignatureRequired();
        require PALADIN_ROOT . '/views/approvals/view.php';
    }

    public function decide(int $id): void {
        Auth::requirePermission('approval.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $req = Database::fetchOne("SELECT * FROM approval_requests WHERE id=?", [$id]);
        if (!$req || $req['status'] !== 'pending') { $_SESSION['flash_error'] = 'This request is no longer open.'; header('Location: /approvals/' . $id); return; }

        if (!Auth::can('approval.approve') && Auth::role() !== 'admin') {
            $_SESSION['flash_error'] = 'You do not have approval authority.'; header('Location: /approvals/' . $id); return;
        }
        $step = $this->actionableStep($req, Auth::id(), Auth::role());
        if (!$step) { $_SESSION['flash_error'] = 'No step is awaiting your decision.'; header('Location: /approvals/' . $id); return; }

        $decision = Security::sanitizeInput($_POST['decision'] ?? '');
        $comment  = Security::sanitizeInput($_POST['comment'] ?? '');
        if (!in_array($decision, ['approve','reject','return'], true)) { http_response_code(400); return; }

        // Electronic signature (21 CFR Part 11): a tamper-evident affirmation on a
        // final decision (approve/reject). When the 'require_esignature' setting is
        // on, the signer must (a) type their full name exactly and (b) RE-AUTHENTICATE
        // with their password at the moment of signing. The immutable record binds
        // the signer, decision meaning, timestamp, IP and user agent into the hash.
        $signature = Security::sanitizeInput($_POST['signature'] ?? '');
        $sig = [];
        if (in_array($decision, ['approve','reject'], true)) {
            $myName  = (string)(Auth::user()['name'] ?? '');
            $meaning = $decision === 'approve'
                ? 'I am approving this record.'
                : 'I am rejecting this record.';

            if (Workflow::esignatureRequired()) {
                // (a) Typed name must match the account name exactly.
                if ($signature === '' || mb_strtolower(trim($signature)) !== mb_strtolower(trim($myName))) {
                    $_SESSION['flash_error'] = 'An electronic signature is required: type your full name exactly as it appears on your account to sign this decision.';
                    header('Location: /approvals/' . $id); return;
                }
                // (b) Re-authenticate with the account password (Part 11 §11.200).
                $pw   = (string)($_POST['signature_password'] ?? '');
                $hash = (string)(Database::fetchOne("SELECT password_hash FROM users WHERE id=?", [Auth::id()])['password_hash'] ?? '');
                if ($pw === '' || $hash === '' || !Security::verifyPassword($pw, $hash)) {
                    Auth::log('esignature_failed', 'approval_requests', $id, ['step' => (int)$step['id'], 'reason' => 'reauth_failed']);
                    $_SESSION['flash_error'] = 'Electronic signature failed: your password did not match. The signing attempt has been logged.';
                    header('Location: /approvals/' . $id); return;
                }
            }
            if ($signature !== '') {
                $signedAt = date('Y-m-d H:i:s');
                $ip = Security::clientIp();
                $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                $sig = [
                    'signature_name' => $signature,
                    'signed_at'      => $signedAt,
                    'signature_hash' => hash('sha256', implode('|', [Auth::id(), $step['id'], $decision, $meaning, $signedAt, $signature, $ip, $ua])),
                ];
                // Immutable Part 11 signing event in the audit trail.
                Auth::log('esignature', 'approval_requests', $id, [
                    'step' => (int)$step['id'], 'decision' => $decision, 'meaning' => $meaning,
                    'signer' => $signature, 'signed_at' => $signedAt, 'ip' => $ip, 'user_agent' => $ua,
                    'hash' => $sig['signature_hash'],
                ]);
            }
        }

        if ($decision === 'reject') {
            Database::update('approval_request_steps', array_merge(['status' => 'rejected', 'decided_by' => Auth::id(), 'decision_comment' => $comment, 'decided_at' => date('Y-m-d H:i:s')], $sig), 'id=?', [$step['id']]);
            Database::update('approval_requests', ['status' => 'rejected', 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            Database::insert('approval_history', ['request_id' => $id, 'user_id' => Auth::id(), 'action' => 'rejected', 'comment' => $comment ?: null]);
            $this->syncEntity($req, 'rejected');
            $this->notify((int)$req['requested_by'], 'Approval rejected', $req['title'] . ' was rejected.', '/approvals/' . $id, 'critical');
            Auth::log('reject_approval', 'approval_requests', $id);
            $_SESSION['flash_success'] = 'Request rejected.';
            header('Location: /approvals/' . $id); return;
        }

        if ($decision === 'return') {
            Database::update('approval_request_steps', ['decision_comment' => $comment, 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$step['id']]);
            Database::update('approval_requests', ['status' => 'returned', 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            Database::insert('approval_history', ['request_id' => $id, 'user_id' => Auth::id(), 'action' => 'returned', 'comment' => $comment ?: null]);
            $this->syncEntity($req, 'returned');
            $this->notify((int)$req['requested_by'], 'Returned for revision', $req['title'] . ' was returned for revisions.', '/approvals/' . $id, 'warning');
            Auth::log('return_approval', 'approval_requests', $id);
            $_SESSION['flash_success'] = 'Request returned for revisions.';
            header('Location: /approvals/' . $id); return;
        }

        // approve
        Database::update('approval_request_steps', array_merge(['status' => 'approved', 'decided_by' => Auth::id(), 'decision_comment' => $comment ?: null, 'decided_at' => date('Y-m-d H:i:s')], $sig), 'id=?', [$step['id']]);
        Database::insert('approval_history', ['request_id' => $id, 'user_id' => Auth::id(), 'action' => 'approved', 'comment' => $comment ?: null]);

        $pendingLeft = (int)(Database::fetchOne("SELECT COUNT(*) c FROM approval_request_steps WHERE request_id=? AND status='pending'", [$id])['c'] ?? 0);
        if ($pendingLeft === 0) {
            Database::update('approval_requests', ['status' => 'approved', 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            $this->syncEntity($req, 'approved');
            $this->notify((int)$req['requested_by'], 'Approved', $req['title'] . ' was fully approved.', '/approvals/' . $id, 'info');
            Auth::log('complete_approval', 'approval_requests', $id);
            Webhook::dispatch('approval.completed', ['id' => $id, 'title' => $req['title'], 'result' => 'approved', 'actor' => Auth::id()]);
            $_SESSION['flash_success'] = 'Final approval recorded — request approved.';
        } else {
            // Advance current_step for sequential/single to the next pending step
            if (in_array($req['approval_mode'], ['sequential','single'], true)) {
                $next = Database::fetchOne("SELECT step_number FROM approval_request_steps WHERE request_id=? AND status='pending' ORDER BY step_number LIMIT 1", [$id]);
                if ($next) Database::update('approval_requests', ['current_step' => (int)$next['step_number']], 'id=?', [$id]);
            }
            $this->notifyActiveApprovers($id);
            Auth::log('approve_step', 'approval_requests', $id, ['step' => $step['step_number']]);
            $_SESSION['flash_success'] = 'Your approval was recorded.';
        }
        header('Location: /approvals/' . $id);
    }

    public function cancel(int $id): void {
        Auth::requirePermission('approval.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $req = Database::fetchOne("SELECT * FROM approval_requests WHERE id=?", [$id]);
        if (!$req) { http_response_code(404); return; }
        if ((int)$req['requested_by'] !== Auth::id() && Auth::role() !== 'admin') { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        Database::update('approval_requests', ['status' => 'cancelled', 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        Database::insert('approval_history', ['request_id' => $id, 'user_id' => Auth::id(), 'action' => 'cancelled', 'comment' => null]);
        Auth::log('cancel_approval', 'approval_requests', $id);
        $_SESSION['flash_success'] = 'Request cancelled.';
        header('Location: /approvals/' . $id);
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function actionableStep(array $req, int $uid, string $role): ?array {
        if ($req['status'] !== 'pending') return null;
        $rows = Database::fetchAll(
            "SELECT * FROM approval_request_steps WHERE request_id=? AND status='pending'
                AND (? OR step_number = ?)
                AND (required_user_id = ? OR required_role = ? OR ? = 'admin')
             ORDER BY step_number LIMIT 1",
            [
                $req['id'],
                in_array($req['approval_mode'], ['parallel','consensus'], true) ? 1 : 0,
                $req['current_step'], $uid, $role, $role,
            ]
        );
        return $rows[0] ?? null;
    }

    private function syncEntity(array $req, string $outcome): void {
        if ($req['entity_type'] !== 'document' || !$req['entity_id']) return;
        $doc = Database::fetchOne("SELECT status FROM documents WHERE id=?", [$req['entity_id']]);
        if (!$doc) return;
        if ($outcome === 'approved' && in_array($doc['status'], ['in_review','draft'], true)) {
            Database::update('documents', ['status' => 'approved'], 'id=?', [$req['entity_id']]);
        } elseif ($outcome === 'rejected') {
            Database::update('documents', ['status' => 'rejected'], 'id=?', [$req['entity_id']]);
        } elseif ($outcome === 'returned') {
            Database::update('documents', ['status' => 'draft'], 'id=?', [$req['entity_id']]);
        }
    }

    private function notifyActiveApprovers(int $reqId): void {
        $req = Database::fetchOne("SELECT * FROM approval_requests WHERE id=?", [$reqId]);
        if (!$req) return;
        $steps = Database::fetchAll(
            "SELECT * FROM approval_request_steps WHERE request_id=? AND status='pending'
                AND (? OR step_number = ?)",
            [$reqId, in_array($req['approval_mode'], ['parallel','consensus'], true) ? 1 : 0, $req['current_step']]
        );
        foreach ($steps as $s) {
            if (!empty($s['required_user_id'])) {
                $this->notify((int)$s['required_user_id'], 'Approval needed', $req['title'], '/approvals/' . $reqId, 'warning');
            } elseif (!empty($s['required_role'])) {
                foreach (Database::fetchAll("SELECT id FROM users WHERE role=? AND is_active=TRUE", [$s['required_role']]) as $u) {
                    $this->notify((int)$u['id'], 'Approval needed', $req['title'], '/approvals/' . $reqId, 'warning');
                }
            }
        }
    }

    private function notify(int $userId, string $title, string $body, string $link, string $severity = 'info'): void {
        if ($userId <= 0) return;
        try {
            Database::insert('alerts', ['user_id' => $userId, 'title' => $title, 'body' => $body, 'severity' => $severity, 'link' => $link]);
        } catch (Throwable) {}
    }
}
