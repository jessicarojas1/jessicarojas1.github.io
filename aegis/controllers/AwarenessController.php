<?php
declare(strict_types=1);

class AwarenessController {

    public function index(): void {
        Auth::requireAuth();
        $programs = Database::fetchAll(
            "SELECT ap.*,
                    u.name AS created_by_name,
                    COUNT(aa.id)                                     AS total_assigned,
                    SUM(CASE WHEN aa.completed THEN 1 ELSE 0 END)    AS completed_count
             FROM awareness_programs ap
             LEFT JOIN users u  ON u.id  = ap.created_by
             LEFT JOIN awareness_assignments aa ON aa.program_id = ap.id
             GROUP BY ap.id, u.name
             ORDER BY ap.created_at DESC"
        );
        $pageTitle    = 'Awareness Training';
        $activeModule = 'awareness';
        $breadcrumbs  = [['Awareness Training', null]];
        ob_start();
        require AEGIS_ROOT . '/views/awareness/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'New Awareness Program';
        $activeModule = 'awareness';
        $breadcrumbs  = [['Awareness Training', '/awareness'], ['New Program', null]];
        ob_start();
        require AEGIS_ROOT . '/views/awareness/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /awareness/create'); return;
        }

        $validTypes = ['document','video','policy','quiz'];
        $type = in_array($_POST['content_type'] ?? '', $validTypes, true) ? $_POST['content_type'] : 'document';

        $id = Database::insert('awareness_programs', [
            'title'        => $title,
            'description'  => Security::sanitizeInput($_POST['description'] ?? ''),
            'content_type' => $type,
            'content_body' => Security::sanitizeInput($_POST['content_body'] ?? ''),
            'content_url'  => Security::sanitizeInput($_POST['content_url']  ?? ''),
            'due_date'     => $_POST['due_date'] ?: null,
            'status'       => 'active',
            'created_by'   => Auth::id(),
        ]);

        // Assign selected users
        $userIds = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));
        foreach ($userIds as $uid) {
            try {
                Database::insert('awareness_assignments', ['program_id' => $id, 'user_id' => $uid]);
            } catch (Throwable) {}
        }

        // Assign all users if requested
        if (!empty($_POST['assign_all'])) {
            $allUsers = Database::fetchAll("SELECT id FROM users WHERE is_active=TRUE");
            foreach ($allUsers as $u) {
                try {
                    Database::insert('awareness_assignments', ['program_id' => $id, 'user_id' => $u['id']]);
                } catch (Throwable) {}
            }
        }

        Auth::log('awareness_created', 'awareness_programs', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'Awareness program created.';
        header("Location: /awareness/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $program = Database::fetchOne(
            "SELECT ap.*, u.name AS created_by_name
             FROM awareness_programs ap
             LEFT JOIN users u ON u.id = ap.created_by
             WHERE ap.id = ?", [$id]
        );
        if (!$program) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $assignments = Database::fetchAll(
            "SELECT aa.*, u.name AS user_name, u.email AS user_email
             FROM awareness_assignments aa
             JOIN users u ON u.id = aa.user_id
             WHERE aa.program_id = ?
             ORDER BY aa.completed, u.name",
            [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");

        $pageTitle    = Security::h($program['title']);
        $activeModule = 'awareness';
        $breadcrumbs  = [['Awareness Training', '/awareness'], [$program['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/awareness/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function complete(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        Database::query(
            "UPDATE awareness_assignments SET completed=TRUE, completed_at=NOW(), notes=?
             WHERE program_id=? AND user_id=?",
            [Security::sanitizeInput($_POST['notes'] ?? ''), $id, Auth::id()]
        );
        $_SESSION['flash_success'] = 'Marked as complete.';
        header("Location: /awareness/{$id}");
    }

    public function assign(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $userIds = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));
        $added = 0;
        foreach ($userIds as $uid) {
            try {
                Database::insert('awareness_assignments', ['program_id' => $id, 'user_id' => $uid]);
                $added++;
            } catch (Throwable) {}
        }
        $_SESSION['flash_success'] = "Added {$added} user(s) to program.";
        header("Location: /awareness/{$id}");
    }

    public function delete(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM awareness_programs WHERE id=?", [$id]);
        Auth::log('awareness_deleted', 'awareness_programs', $id, []);
        $_SESSION['flash_success'] = 'Program deleted.';
        header('Location: /awareness');
    }
}
