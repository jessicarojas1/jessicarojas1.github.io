<?php
class PolicyController {
    public function index(): void {
        Auth::requireAuth();

        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $where  = $status ? "WHERE p.status = ?" : "WHERE 1=1";
        $params = $status ? [$status] : [];

        $policies = Database::fetchAll(
            "SELECT p.*, u.name as owner_name, u2.name as approver_name,
               COUNT(pm.id) as mapping_count
             FROM policies p
             LEFT JOIN users u ON p.owner_id = u.id
             LEFT JOIN users u2 ON p.approver_id = u2.id
             LEFT JOIN policy_mappings pm ON pm.policy_id = p.id
             {$where}
             GROUP BY p.id, u.name, u2.name
             ORDER BY p.updated_at DESC",
            $params
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'draft') as drafts,
               COUNT(*) FILTER (WHERE status = 'under_review') as under_review,
               COUNT(*) FILTER (WHERE status = 'published') as published,
               COUNT(*) FILTER (WHERE next_review_date <= CURRENT_DATE) as overdue
             FROM policies"
        );

        require AEGIS_ROOT . '/views/policy/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('policy.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/policy/create.php';
    }

    public function create(): void {
        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $title    = Security::sanitizeInput($_POST['title'] ?? '');
        $number   = Security::sanitizeInput($_POST['policy_number'] ?? '');
        $desc     = Security::sanitizeInput($_POST['description'] ?? '');
        $content  = $_POST['content'] ?? '';
        $category = Security::sanitizeInput($_POST['category'] ?? '');
        $ownerId  = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id();
        $approverId = !empty($_POST['approver_id']) ? (int)$_POST['approver_id'] : null;
        $frequency = in_array($_POST['review_frequency'] ?? '', ['monthly','quarterly','biannual','annual','biennial']) ? $_POST['review_frequency'] : 'annual';
        $reviewDate = Security::sanitizeInput($_POST['next_review_date'] ?? '');

        if (!$title) {
            $_SESSION['policy_error'] = 'Policy title is required.';
            header('Location: /policy/create'); return;
        }

        if (!$reviewDate) {
            $reviewDate = match($frequency) {
                'monthly'   => date('Y-m-d', strtotime('+1 month')),
                'quarterly' => date('Y-m-d', strtotime('+3 months')),
                'biannual'  => date('Y-m-d', strtotime('+6 months')),
                'biennial'  => date('Y-m-d', strtotime('+2 years')),
                default     => date('Y-m-d', strtotime('+1 year')),
            };
        }

        $policyId = Database::insert('policies', [
            'title'            => $title,
            'policy_number'    => $number,
            'description'      => $desc,
            'content'          => $content,
            'category'         => $category,
            'owner_id'         => $ownerId,
            'approver_id'      => $approverId,
            'review_frequency' => $frequency,
            'next_review_date' => $reviewDate,
            'status'           => 'draft',
            'version'          => '1.0',
        ]);

        Database::insert('policy_versions', [
            'policy_id'    => $policyId,
            'version'      => '1.0',
            'content'      => $content,
            'change_summary' => 'Initial version',
            'created_by'   => Auth::id(),
        ]);

        Auth::log('create_policy', 'policies', $policyId);
        header('Location: /policy/' . $policyId);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $policy = Database::fetchOne(
            "SELECT p.*, u.name as owner_name, u2.name as approver_name
             FROM policies p
             LEFT JOIN users u ON p.owner_id = u.id
             LEFT JOIN users u2 ON p.approver_id = u2.id
             WHERE p.id = ?", [$id]
        );
        if (!$policy) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $versions = Database::fetchAll(
            "SELECT pv.*, u.name as author FROM policy_versions pv
             LEFT JOIN users u ON u.id = pv.created_by
             WHERE pv.policy_id = ? ORDER BY pv.created_at DESC", [$id]
        );

        $mappings = Database::fetchAll(
            "SELECT pm.*, co.code, co.title as objective_title, co.category,
               cp.name as package_name, cp.id as package_id
             FROM policy_mappings pm
             JOIN compliance_objectives co ON co.id = pm.objective_id
             JOIN compliance_packages cp ON cp.id = co.package_id
             WHERE pm.policy_id = ? ORDER BY co.sort_order", [$id]
        );

        $reviews = Database::fetchAll(
            "SELECT pr.*, u.name as reviewer_name
             FROM policy_reviews pr LEFT JOIN users u ON u.id = pr.reviewer_id
             WHERE pr.policy_id = ? ORDER BY pr.scheduled_date DESC", [$id]
        );

        $availableObjectives = Database::fetchAll(
            "SELECT co.id, co.code, co.title, cp.name as package_name
             FROM compliance_objectives co
             JOIN compliance_packages cp ON cp.id = co.package_id
             WHERE co.level = 2 AND co.id NOT IN (SELECT objective_id FROM policy_mappings WHERE policy_id = ?)
             ORDER BY cp.name, co.sort_order", [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/policy/view.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        $policy = Database::fetchOne("SELECT * FROM policies WHERE id = ?", [$id]);
        if (!$policy) { http_response_code(404); return; }

        $action = $_POST['action'] ?? 'save';

        if ($action === 'publish') {
            Database::query("UPDATE policies SET status='published', published_at=NOW(), updated_at=NOW() WHERE id=?", [$id]);
        } elseif ($action === 'approve') {
            Database::query("UPDATE policies SET status='published', approved_at=NOW(), approver_id=?, updated_at=NOW() WHERE id=?", [Auth::id(), $id]);
        } elseif ($action === 'submit_review') {
            Database::query("UPDATE policies SET status='under_review', updated_at=NOW() WHERE id=?", [$id]);
        } elseif ($action === 'archive') {
            Database::query("UPDATE policies SET status='archived', updated_at=NOW() WHERE id=?", [$id]);
        } else {
            $title   = Security::sanitizeInput($_POST['title'] ?? '');
            $desc    = Security::sanitizeInput($_POST['description'] ?? '');
            $content = $_POST['content'] ?? '';
            $reviewDate = Security::sanitizeInput($_POST['next_review_date'] ?? '');
            $newVersion = $_POST['new_version'] ?? '';

            Database::query(
                "UPDATE policies SET title=?, description=?, content=?, next_review_date=?, updated_at=NOW() WHERE id=?",
                [$title, $desc, $content, $reviewDate ?: null, $id]
            );

            if ($newVersion) {
                $ver = $newVersion;
                Database::insert('policy_versions', [
                    'policy_id'    => $id,
                    'version'      => $ver,
                    'content'      => $content,
                    'change_summary' => Security::sanitizeInput($_POST['change_summary'] ?? 'Updated'),
                    'created_by'   => Auth::id(),
                ]);
                Database::query("UPDATE policies SET version=? WHERE id=?", [$ver, $id]);
            }
        }

        Auth::log('update_policy', 'policies', $id, ['action' => $action]);
        header('Location: /policy/' . $id . '?saved=1');
    }

    public function mapObjective(string $id): void {
        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $policyId  = (int)$id;
        $objectiveId = (int)($_POST['objective_id'] ?? 0);
        $notes     = Security::sanitizeInput($_POST['notes'] ?? '');

        if ($objectiveId) {
            Database::query(
                "INSERT INTO policy_mappings (policy_id, objective_id, notes) VALUES (?,?,?) ON CONFLICT DO NOTHING",
                [$policyId, $objectiveId, $notes]
            );
            Auth::log('map_policy_objective', 'policy_mappings', $policyId, ['objective_id' => $objectiveId]);
        }

        header('Location: /policy/' . $policyId . '?saved=1');
    }

    public function unmapObjective(string $policyId, string $mappingId): void {
        Auth::requirePermission('policy.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        Database::query("DELETE FROM policy_mappings WHERE id = ? AND policy_id = ?", [(int)$mappingId, (int)$policyId]);
        header('Location: /policy/' . (int)$policyId . '?saved=1');
    }

    public function editForm(string $id): void {
        Auth::requirePermission('policy.write');
        $policy = Database::fetchOne("SELECT * FROM policies WHERE id = ?", [(int)$id]);
        if (!$policy) { http_response_code(404); return; }
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/policy/create.php';
    }

    // List all attestation campaigns (admin/manager)
    public function attestations(): void {
        Auth::requireAuth();
        // load campaigns with policy name, attested count, total user count
        $campaigns = Database::fetchAll(
            "SELECT pac.*, p.title as policy_title,
                    COUNT(DISTINCT pa.user_id) as attested_count,
                    (SELECT COUNT(*) FROM users WHERE is_active = TRUE) as total_users
             FROM policy_attestation_campaigns pac
             JOIN policies p ON p.id = pac.policy_id
             LEFT JOIN policy_attestations pa ON pa.policy_id = pac.policy_id
             GROUP BY pac.id, p.title ORDER BY pac.created_at DESC"
        );
        $pageTitle    = 'Policy Attestations';
        $activeModule = 'policy_attestations';
        $breadcrumbs  = [['Policies', '/policy'], ['Attestations', null]];
        ob_start();
        require AEGIS_ROOT . '/views/policy/attestations.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // Create attestation campaign
    public function createCampaign(): void {
        Auth::requirePermission('policy.write');
        $policies = Database::fetchAll("SELECT id, title FROM policies WHERE status='approved' ORDER BY title");
        $pageTitle    = 'New Attestation Campaign';
        $activeModule = 'policy_attestations';
        $breadcrumbs  = [['Policies', '/policy'], ['Attestations', '/policy/attestations'], ['New Campaign', null]];
        ob_start();
        require AEGIS_ROOT . '/views/policy/campaign_create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveCampaign(): void {
        Auth::requirePermission('policy.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $policyId = (int)($_POST['policy_id'] ?? 0);
        $title    = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $dueDate  = Security::sanitizeInput($_POST['due_date'] ?? '');
        if (!$policyId || !$title) {
            $_SESSION['flash_error'] = 'Policy and title are required.';
            header('Location: /policy/attestations/create'); return;
        }
        $policy = Database::fetchOne("SELECT id FROM policies WHERE id=?", [$policyId]);
        if (!$policy) { http_response_code(404); return; }
        $id = Database::insert('policy_attestation_campaigns', [
            'policy_id'  => $policyId,
            'title'      => $title,
            'due_date'   => $dueDate ?: null,
            'is_active'  => true,
            'created_by' => Auth::id(),
        ]);
        Auth::log('campaign_created', 'policy_attestation_campaigns', $id, ['title'=>$title]);
        $_SESSION['flash_success'] = 'Campaign created.';
        header("Location: /policy/attestations/{$id}");
    }

    // View a campaign — shows attestation matrix (who signed, who hasn't)
    public function viewCampaign(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;
        $campaign = Database::fetchOne(
            "SELECT pac.*, p.title as policy_title, p.id as pid FROM policy_attestation_campaigns pac
             JOIN policies p ON p.id = pac.policy_id WHERE pac.id=?", [$id]
        );
        if (!$campaign) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $attested = Database::fetchAll(
            "SELECT pa.*, u.name as user_name, u.email FROM policy_attestations pa
             JOIN users u ON u.id = pa.user_id WHERE pa.policy_id=? ORDER BY pa.attested_at DESC",
            [$campaign['pid']]
        );
        $pending = Database::fetchAll(
            "SELECT u.id, u.name, u.email FROM users u
             WHERE u.is_active=TRUE
               AND u.id NOT IN (SELECT user_id FROM policy_attestations WHERE policy_id=?)
             ORDER BY u.name",
            [$campaign['pid']]
        );
        $pageTitle    = 'Campaign: ' . $campaign['title'];
        $activeModule = 'policy_attestations';
        $breadcrumbs  = [['Policies', '/policy'], ['Attestations', '/policy/attestations'], [$campaign['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/policy/campaign_view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // User reads and signs off on a policy
    public function attestForm(string $policyId): void {
        Auth::requireAuth();
        $policyId = (int)$policyId;
        $policy = Database::fetchOne("SELECT * FROM policies WHERE id=?", [$policyId]);
        if (!$policy) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $existing = Database::fetchOne(
            "SELECT * FROM policy_attestations WHERE policy_id=? AND user_id=?",
            [$policyId, Auth::id()]
        );
        $pageTitle    = 'Attest: ' . $policy['title'];
        $activeModule = 'policy';
        $breadcrumbs  = [['Policies', '/policy'], [$policy['title'], "/policy/{$policyId}"], ['Attest', null]];
        ob_start();
        require AEGIS_ROOT . '/views/policy/attest.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function attest(string $policyId): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $policyId = (int)$policyId;
        $policy = Database::fetchOne("SELECT id, title FROM policies WHERE id=?", [$policyId]);
        if (!$policy) { http_response_code(404); return; }
        if (empty($_POST['confirmed'])) {
            $_SESSION['flash_error'] = 'You must check the confirmation box.';
            header("Location: /policy/{$policyId}/attest"); return;
        }
        $notes = trim(Security::sanitizeInput($_POST['notes'] ?? ''));
        try {
            Database::query(
                "INSERT INTO policy_attestations (policy_id, user_id, ip_address, notes)
                 VALUES (?,?,?,?)
                 ON CONFLICT (policy_id, user_id) DO UPDATE SET attested_at=NOW(), ip_address=EXCLUDED.ip_address, notes=EXCLUDED.notes",
                [$policyId, Auth::id(), $_SERVER['REMOTE_ADDR'] ?? '', $notes]
            );
            Auth::log('policy_attested', 'policy_attestations', $policyId, ['notes' => $notes]);
            $_SESSION['flash_success'] = 'Policy attested successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Attestation failed.';
        }
        header("Location: /policy/{$policyId}");
    }

    // My attestations (profile page)
    public function myAttestations(): void {
        Auth::requireAuth();
        $records = Database::fetchAll(
            "SELECT pa.*, p.title as policy_title, p.id as policy_id FROM policy_attestations pa
             JOIN policies p ON p.id = pa.policy_id
             WHERE pa.user_id=? ORDER BY pa.attested_at DESC",
            [Auth::id()]
        );
        // Campaigns where user hasn't attested
        $pending = Database::fetchAll(
            "SELECT pac.*, p.title as policy_title, p.id as policy_id
             FROM policy_attestation_campaigns pac
             JOIN policies p ON p.id = pac.policy_id
             WHERE pac.is_active=TRUE
               AND p.id NOT IN (SELECT policy_id FROM policy_attestations WHERE user_id=?)
             ORDER BY pac.due_date ASC NULLS LAST",
            [Auth::id()]
        );
        $pageTitle    = 'My Attestations';
        $activeModule = 'my_attestations';
        $breadcrumbs  = [['My Attestations', null]];
        ob_start();
        require AEGIS_ROOT . '/views/policy/my_attestations.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }
}
