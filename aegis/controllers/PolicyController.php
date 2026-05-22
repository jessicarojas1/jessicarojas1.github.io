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
}
