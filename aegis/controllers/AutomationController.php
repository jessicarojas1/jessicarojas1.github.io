<?php
declare(strict_types=1);

class AutomationController {

    private const TRIGGER_LABELS = [
        'risk_score_high'          => 'Risk Score Exceeds Threshold',
        'control_non_compliant'    => 'Control Becomes Non-Compliant',
        'audit_overdue'            => 'Audit Becomes Overdue',
        'incident_created'         => 'New Incident Created',
        'policy_review_due'        => 'Policy Review Due',
        'vendor_contract_expiring' => 'Vendor Contract Expiring',
        'scheduled_daily'          => 'Daily Schedule',
        'scheduled_weekly'         => 'Weekly Schedule',
    ];

    private const ACTION_LABELS = [
        'create_issue'             => 'Create Issue',
        'send_webhook'             => 'Send Webhook',
        'send_email_notification'  => 'Send Email Notification',
        'assign_user'              => 'Assign User',
    ];

    public function index(): void {
        Auth::requireAuth();
        $rules = Database::fetchAll(
            "SELECT ar.*, u.name AS created_by_name,
                    COUNT(al.id) FILTER (WHERE al.triggered_at > NOW() - INTERVAL '7 days' AND al.status='success') AS recent_success,
                    COUNT(al.id) FILTER (WHERE al.triggered_at > NOW() - INTERVAL '7 days' AND al.status='failed') AS recent_failed
             FROM automation_rules ar
             LEFT JOIN users u ON u.id = ar.created_by
             LEFT JOIN automation_logs al ON al.rule_id = ar.id
             GROUP BY ar.id, u.name
             ORDER BY ar.created_at DESC"
        );
        $triggerLabels = self::TRIGGER_LABELS;
        $actionLabels  = self::ACTION_LABELS;
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'Automation Rules';
        $activeModule = 'automation';
        $breadcrumbs  = [['Automation', null]];
        ob_start();
        require AEGIS_ROOT . '/views/automation/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $triggerLabels = self::TRIGGER_LABELS;
        $actionLabels  = self::ACTION_LABELS;
        $pageTitle    = 'New Automation Rule';
        $activeModule = 'automation';
        $breadcrumbs  = [['Automation', '/automation'], ['New Rule', null]];
        ob_start();
        require AEGIS_ROOT . '/views/automation/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name        = trim(Security::sanitizeInput($_POST['name'] ?? ''));
        $triggerType = $_POST['trigger_type'] ?? '';
        $actionType  = $_POST['action_type'] ?? '';

        if (!$name) { $_SESSION['flash_error'] = 'Name is required.'; header('Location: /automation/create'); return; }
        if (!array_key_exists($triggerType, self::TRIGGER_LABELS)) { $_SESSION['flash_error'] = 'Invalid trigger.'; header('Location: /automation/create'); return; }
        if (!array_key_exists($actionType, self::ACTION_LABELS)) { $_SESSION['flash_error'] = 'Invalid action.'; header('Location: /automation/create'); return; }

        $triggerConfig = [];
        if ($triggerType === 'risk_score_high') $triggerConfig['threshold'] = (int)($_POST['risk_threshold'] ?? 15);
        if ($triggerType === 'vendor_contract_expiring') $triggerConfig['days_before'] = (int)($_POST['days_before'] ?? 30);

        $actionConfig = [];
        if ($actionType === 'create_issue') {
            $actionConfig['title'] = Security::sanitizeInput($_POST['issue_title_template'] ?? '');
            $actionConfig['severity'] = $_POST['issue_severity'] ?? 'medium';
        }
        if ($actionType === 'send_webhook') $actionConfig['url'] = Security::sanitizeInput($_POST['webhook_url'] ?? '');
        if ($actionType === 'send_email_notification') {
            $actionConfig['recipients'] = Security::sanitizeInput($_POST['email_recipients'] ?? '');
            $actionConfig['subject']    = Security::sanitizeInput($_POST['email_subject'] ?? '');
        }
        if ($actionType === 'assign_user') $actionConfig['user_id'] = (int)($_POST['assign_user_id'] ?? 0);

        $id = Database::insert('automation_rules', [
            'name'           => $name,
            'description'    => Security::sanitizeInput($_POST['description'] ?? ''),
            'trigger_type'   => $triggerType,
            'trigger_config' => json_encode($triggerConfig),
            'action_type'    => $actionType,
            'action_config'  => json_encode($actionConfig),
            'is_active'      => isset($_POST['is_active']) ? true : false,
            'created_by'     => Auth::id(),
        ]);

        Auth::log('automation_rule_created', 'automation_rules', $id, ['name' => $name]);
        $_SESSION['flash_success'] = 'Automation rule created.';
        header("Location: /automation/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $rule = Database::fetchOne("SELECT ar.*, u.name AS created_by_name FROM automation_rules ar LEFT JOIN users u ON u.id=ar.created_by WHERE ar.id=?", [$id]);
        if (!$rule) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }
        $rule['trigger_config'] = json_decode($rule['trigger_config'] ?: '{}', true);
        $rule['action_config']  = json_decode($rule['action_config'] ?: '{}', true);
        $logs = Database::fetchAll("SELECT * FROM automation_logs WHERE rule_id=? ORDER BY triggered_at DESC LIMIT 20", [$id]);
        $triggerLabels = self::TRIGGER_LABELS;
        $actionLabels  = self::ACTION_LABELS;
        $pageTitle    = Security::h($rule['name']);
        $activeModule = 'automation';
        $breadcrumbs  = [['Automation', '/automation'], [$rule['name'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/automation/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function toggle(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $rule = Database::fetchOne("SELECT is_active FROM automation_rules WHERE id=?", [$id]);
        if (!$rule) { http_response_code(404); return; }
        Database::query("UPDATE automation_rules SET is_active=?, updated_at=NOW() WHERE id=?", [!$rule['is_active'], $id]);
        header('Location: /automation');
    }

    public function delete(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM automation_rules WHERE id=?", [$id]);
        Auth::log('automation_rule_deleted', 'automation_rules', $id, []);
        $_SESSION['flash_success'] = 'Rule deleted.';
        header('Location: /automation');
    }

    public function testRun(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $rule = Database::fetchOne("SELECT * FROM automation_rules WHERE id=?", [$id]);
        if (!$rule) { http_response_code(404); return; }
        $config = json_decode($rule['trigger_config'] ?: '{}', true);

        $matches = [];
        try {
            switch ($rule['trigger_type']) {
                case 'risk_score_high':
                    $threshold = (int)($config['threshold'] ?? 15);
                    $risks = Database::fetchAll("SELECT id, title, inherent_score AS score FROM risks WHERE inherent_score >= ? AND status='open' LIMIT 20", [$threshold]);
                    $matches = ['message' => "Found " . count($risks) . " risks with score ≥ {$threshold}", 'items' => $risks];
                    break;
                case 'control_non_compliant':
                    $controls = Database::fetchAll("SELECT co.code, co.title FROM control_implementations ci JOIN compliance_objectives co ON co.id=ci.objective_id WHERE ci.status='non_compliant' LIMIT 20");
                    $matches = ['message' => "Found " . count($controls) . " non-compliant controls", 'items' => $controls];
                    break;
                case 'audit_overdue':
                    $audits = Database::fetchAll("SELECT id, title, due_date FROM audits WHERE status NOT IN ('completed') AND due_date < NOW() LIMIT 20");
                    $matches = ['message' => "Found " . count($audits) . " overdue audits", 'items' => $audits];
                    break;
                case 'incident_created':
                    $incidents = Database::fetchAll("SELECT id, incident_number, title FROM incidents ORDER BY created_at DESC LIMIT 5");
                    $matches = ['message' => "Would trigger on new incidents. Recent: " . count($incidents), 'items' => $incidents];
                    break;
                case 'policy_review_due':
                    $policies = Database::fetchAll("SELECT id, title, next_review_date FROM policies WHERE next_review_date <= NOW() + INTERVAL '7 days' AND status='published' LIMIT 20");
                    $matches = ['message' => "Found " . count($policies) . " policies due for review", 'items' => $policies];
                    break;
                case 'vendor_contract_expiring':
                    $days = (int)($config['days_before'] ?? 30);
                    $vendors = Database::fetchAll("SELECT v.id, v.name, vc.end_date FROM vendors v JOIN vendor_contracts vc ON vc.vendor_id=v.id WHERE vc.end_date <= NOW() + INTERVAL '{$days} days' AND vc.status='active' LIMIT 20");
                    $matches = ['message' => "Found " . count($vendors) . " contracts expiring within {$days} days", 'items' => $vendors];
                    break;
                default:
                    $matches = ['message' => 'Scheduled trigger — would run on schedule', 'items' => []];
            }
        } catch (Throwable $e) {
            $matches = ['message' => 'Test error: ' . $e->getMessage(), 'items' => []];
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'dry_run' => true, 'result' => $matches]);
    }
}
