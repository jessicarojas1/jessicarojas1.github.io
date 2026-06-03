<?php
declare(strict_types=1);

class AccountReviewController {

    public function index(): void {
        Auth::requireAuth();
        $reviews = Database::fetchAll(
            "SELECT ar.*,
                    u.name  AS reviewer_name,
                    cb.name AS created_by_name,
                    COUNT(ai.id)                                           AS total_items,
                    SUM(CASE WHEN ai.decision <> 'pending' THEN 1 ELSE 0 END) AS reviewed_items
             FROM account_reviews ar
             LEFT JOIN users u   ON u.id  = ar.reviewer_id
             LEFT JOIN users cb  ON cb.id = ar.created_by
             LEFT JOIN account_review_items ai ON ai.review_id = ar.id
             GROUP BY ar.id, u.name, cb.name
             ORDER BY ar.created_at DESC"
        );
        $pageTitle    = 'Account Reviews';
        $activeModule = 'account_reviews';
        $breadcrumbs  = [['Account Reviews', null]];
        ob_start();
        require AEGIS_ROOT . '/views/account_review/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('admin');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'New Account Review';
        $activeModule = 'account_reviews';
        $breadcrumbs  = [['Account Reviews', '/account-reviews'], ['New Review', null]];
        ob_start();
        require AEGIS_ROOT . '/views/account_review/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('admin');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /account-reviews/create'); return;
        }

        $id = Database::insert('account_reviews', [
            'title'       => $title,
            'description' => Security::sanitizeInput($_POST['description'] ?? ''),
            'scope'       => Security::sanitizeInput($_POST['scope'] ?? ''),
            'reviewer_id' => (int)($_POST['reviewer_id'] ?? 0) ?: null,
            'due_date'    => $_POST['due_date'] ?: null,
            'status'      => 'pending',
            'created_by'  => Auth::id(),
        ]);

        // Auto-populate items from existing users if requested
        if (!empty($_POST['auto_populate'])) {
            $existingUsers = Database::fetchAll(
                "SELECT id, name, email FROM users WHERE is_active=TRUE ORDER BY name"
            );
            foreach ($existingUsers as $u) {
                Database::insert('account_review_items', [
                    'review_id'     => $id,
                    'account_name'  => $u['email'],
                    'user_full_name'=> $u['name'],
                    'system_name'   => 'AEGIS GRC',
                    'access_level'  => 'Platform User',
                    'decision'      => 'pending',
                ]);
            }
        }

        Auth::log('account_review_created', 'account_reviews', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'Review campaign created.';
        header("Location: /account-reviews/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $review = Database::fetchOne(
            "SELECT ar.*, u.name AS reviewer_name, cb.name AS created_by_name
             FROM account_reviews ar
             LEFT JOIN users u  ON u.id = ar.reviewer_id
             LEFT JOIN users cb ON cb.id = ar.created_by
             WHERE ar.id = ?", [$id]
        );
        if (!$review) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $items = Database::fetchAll(
            "SELECT ai.*, rb.name AS reviewed_by_name
             FROM account_review_items ai
             LEFT JOIN users rb ON rb.id = ai.reviewed_by
             WHERE ai.review_id = ?
             ORDER BY ai.decision, ai.account_name",
            [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");

        $pageTitle    = Security::h($review['title']);
        $activeModule = 'account_reviews';
        $breadcrumbs  = [['Account Reviews', '/account-reviews'], [$review['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/account_review/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function addItem(int $id): void {
        Auth::requirePermission('admin');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $account = trim(Security::sanitizeInput($_POST['account_name'] ?? ''));
        if (!$account) { header("Location: /account-reviews/{$id}"); return; }

        Database::insert('account_review_items', [
            'review_id'     => $id,
            'account_name'  => $account,
            'user_full_name'=> Security::sanitizeInput($_POST['user_full_name'] ?? ''),
            'system_name'   => Security::sanitizeInput($_POST['system_name']    ?? ''),
            'access_level'  => Security::sanitizeInput($_POST['access_level']   ?? ''),
            'decision'      => 'pending',
        ]);

        // Move review to in_progress if still pending
        Database::query(
            "UPDATE account_reviews SET status='in_progress', updated_at=NOW() WHERE id=? AND status='pending'",
            [$id]
        );

        $_SESSION['flash_success'] = 'Account added to review.';
        header("Location: /account-reviews/{$id}");
    }

    public function decide(int $id, int $itemId): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $validDecisions = ['approved','revoked','modified','pending'];
        $decision = in_array($_POST['decision'] ?? '', $validDecisions, true) ? $_POST['decision'] : 'pending';

        Database::query(
            "UPDATE account_review_items
             SET decision=?, decision_notes=?, reviewed_at=NOW(), reviewed_by=?
             WHERE id=? AND review_id=?",
            [$decision, Security::sanitizeInput($_POST['notes'] ?? ''), Auth::id(), $itemId, $id]
        );

        // Auto-complete review if all items decided
        $pending = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM account_review_items WHERE review_id=? AND decision='pending'", [$id]
        );
        if (($pending['cnt'] ?? 1) == 0) {
            Database::query(
                "UPDATE account_reviews SET status='complete', completed_at=NOW(), updated_at=NOW() WHERE id=?",
                [$id]
            );
        } else {
            Database::query("UPDATE account_reviews SET status='in_progress', updated_at=NOW() WHERE id=? AND status='pending'", [$id]);
        }

        header("Location: /account-reviews/{$id}");
    }

    public function delete(int $id): void {
        Auth::requirePermission('admin');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM account_reviews WHERE id=?", [$id]);
        Auth::log('account_review_deleted', 'account_reviews', $id, []);
        $_SESSION['flash_success'] = 'Review deleted.';
        header('Location: /account-reviews');
    }
}
