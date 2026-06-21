<?php
declare(strict_types=1);

class ProcessController {

    /** Private-space membership gate for a process's space (admins/owners bypass). */
    private function canSeeProcessSpace(int $spaceId): bool {
        if (!$spaceId) { return true; }
        $sp = Database::fetchOne("SELECT id, is_private FROM spaces WHERE id = ?", [$spaceId]);
        return !$sp || SpaceAccess::canView(['id' => (int)$sp['id'], 'is_private' => $sp['is_private']]);
    }

    /** Allowed lifecycle statuses for a process. */
    private const STATUSES = ['draft', 'in_review', 'published', 'retired'];

    public function index(): void {
        Auth::requirePermission('process.view');
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $space  = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $q      = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['1=1']; $params = [];
        if ($status && in_array($status, self::STATUSES, true)) { $where[] = 'p.status = ?'; $params[] = $status; }
        if ($space)  { $where[] = 'p.space_id = ?'; $params[] = $space; }
        if ($q) { $where[] = '(p.name ILIKE ? OR p.process_code ILIKE ? OR p.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        // Hide processes in private spaces from non-members (admins see all).
        if (Auth::role() !== 'admin') {
            $where[] = '(p.space_id IS NULL OR s.is_private = FALSE OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = p.space_id AND m.user_id = ?))';
            $params[] = Auth::id();
        }
        $whereSql = implode(' AND ', $where);

        $processes = Database::fetchAll(
            "SELECT p.*, s.space_key, o.name AS owner_name
             FROM processes p LEFT JOIN spaces s ON s.id=p.space_id LEFT JOIN users o ON o.id=p.owner_id
             WHERE {$whereSql} ORDER BY p.updated_at DESC",
            $params
        );
        $stats = Database::fetchOne(
            "SELECT COUNT(*) total,
                    COUNT(*) FILTER (WHERE status='published') published,
                    COUNT(*) FILTER (WHERE status='draft') draft
             FROM processes"
        );
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        require PALADIN_ROOT . '/views/processes/index.php';
    }

    /** Export the (filtered) process register as CSV. */
    public function exportRegister(): void {
        Auth::requirePermission('process.view');
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $space  = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $q      = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['1=1']; $params = [];
        if ($status && in_array($status, self::STATUSES, true)) { $where[] = 'p.status = ?'; $params[] = $status; }
        if ($space)  { $where[] = 'p.space_id = ?'; $params[] = $space; }
        if ($q) { $where[] = '(p.name ILIKE ? OR p.process_code ILIKE ? OR p.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        // Hide processes in private spaces from non-members (admins see all).
        if (Auth::role() !== 'admin') {
            $where[] = '(p.space_id IS NULL OR s.is_private = FALSE OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = p.space_id AND m.user_id = ?))';
            $params[] = Auth::id();
        }
        $whereSql = implode(' AND ', $where);

        $rows = Database::fetchAll(
            "SELECT p.process_code, p.name, p.status, p.version, s.space_key, o.name AS owner_name, p.updated_at
             FROM processes p LEFT JOIN spaces s ON s.id=p.space_id LEFT JOIN users o ON o.id=p.owner_id
             WHERE {$whereSql} ORDER BY p.process_code",
            $params
        );
        Auth::log('export_process_register', 'processes', null, ['count' => count($rows)]);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="process-register-' . date('Ymd') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Code', 'Name', 'Status', 'Version', 'Space', 'Owner', 'Last Updated']);
        foreach ($rows as $r) {
            Csv::put($out, [
                $r['process_code'], $r['name'], $r['status'], $r['version'],
                $r['space_key'] ?? '', $r['owner_name'] ?? '', $r['updated_at'],
            ]);
        }
        fclose($out);
    }

    public function view(int $id): void {
        Auth::requirePermission('process.view');
        $process = Database::fetchOne(
            "SELECT p.*, s.space_key, s.name AS space_name, o.name AS owner_name, c.name AS creator_name
             FROM processes p
             LEFT JOIN spaces s ON s.id=p.space_id
             LEFT JOIN users o ON o.id=p.owner_id
             LEFT JOIN users c ON c.id=p.created_by
             WHERE p.id=?", [$id]
        );
        if (!$process) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if (!$this->canSeeProcessSpace((int)$process['space_id'])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $relations = Database::fetchAll("SELECT * FROM entity_relations WHERE source_type='process' AND source_id=? ORDER BY relation_type", [$id]);
        Recent::track('process', $id, $process['name']);
        require PALADIN_ROOT . '/views/processes/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('process.create');
        $process = null;
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $users  = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/processes/form.php';
    }

    public function create(): void {
        Auth::requirePermission('process.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Name is required.'; header('Location: /processes/create'); return; }

        $code = $this->nextCode();
        $data = $this->collectData($code);
        $data['name']       = $name;
        $data['created_by'] = Auth::id();

        $id = Database::insert('processes', $data);
        $this->saveRelations('process', $id);
        Auth::log('create_process', 'processes', $id, ['code' => $code]);
        $_SESSION['flash_success'] = "Process {$code} created.";
        header('Location: /processes/' . $id);
    }

    public function editForm(int $id): void {
        Auth::requirePermission('process.edit');
        $process = Database::fetchOne("SELECT * FROM processes WHERE id=?", [$id]);
        if (!$process) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if (!$this->canSeeProcessSpace((int)$process['space_id'])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $users  = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $relations = Database::fetchAll("SELECT * FROM entity_relations WHERE source_type='process' AND source_id=?", [$id]);
        require PALADIN_ROOT . '/views/processes/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('process.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $process = Database::fetchOne("SELECT * FROM processes WHERE id=?", [$id]);
        if (!$process) { http_response_code(404); return; }
        if (!$this->canSeeProcessSpace((int)$process['space_id'])) { http_response_code(403); return; }

        $data = $this->collectData($process['process_code']);
        unset($data['process_code']); // never change the code
        $data['name'] = Security::sanitizeInput($_POST['name'] ?? $process['name']) ?: $process['name'];

        Database::update('processes', $data, 'id=?', [$id]);
        Database::query("DELETE FROM entity_relations WHERE source_type='process' AND source_id=?", [$id]);
        $this->saveRelations('process', $id);
        Auth::log('update_process', 'processes', $id);
        $_SESSION['flash_success'] = 'Process updated.';
        header('Location: /processes/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('process.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $process = Database::fetchOne("SELECT id, space_id FROM processes WHERE id=?", [$id]);
        if (!$process) { http_response_code(404); return; }
        if (!$this->canSeeProcessSpace((int)$process['space_id'])) { http_response_code(403); return; }
        Database::update('processes', ['status' => 'retired'], 'id=?', [$id]);
        Auth::log('retire_process', 'processes', $id);
        $_SESSION['flash_success'] = 'Process retired.';
        header('Location: /processes');
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function nextCode(): string {
        $prefix = 'PROC';
        $row = Database::fetchOne("SELECT process_code FROM processes WHERE process_code LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '-%']);
        $n = 1;
        if ($row && preg_match('/-(\d+)$/', $row['process_code'], $m)) $n = (int)$m[1] + 1;
        return $prefix . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    private function collectData(string $code): array {
        $status = Security::sanitizeInput($_POST['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) $status = 'draft';
        // Only users with process.publish may set 'published'.
        if ($status === 'published' && !Auth::can('process.publish')) $status = 'in_review';

        return [
            'process_code' => $code,
            'space_id'     => !empty($_POST['space_id']) ? (int)$_POST['space_id'] : null,
            'owner_id'     => !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id(),
            'department'   => Security::sanitizeInput($_POST['department'] ?? '') ?: null,
            'status'       => $status,
            'version'      => Security::sanitizeInput($_POST['version'] ?? '1.0') ?: '1.0',
            'description'  => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'diagram'      => Security::sanitizeInput($_POST['diagram'] ?? '') ?: null,
        ];
    }

    private function saveRelations(string $type, int $id): void {
        $labels = $_POST['relation_label'] ?? [];
        $kinds  = $_POST['relation_type'] ?? [];
        if (!is_array($labels)) return;
        foreach ($labels as $i => $label) {
            $label = Security::sanitizeInput((string)$label);
            if ($label === '') continue;
            $kind = Security::sanitizeInput((string)($kinds[$i] ?? 'related_policy'));
            Database::insert('entity_relations', [
                'source_type' => $type, 'source_id' => $id, 'relation_type' => $kind, 'target_label' => $label,
            ]);
        }
    }
}
