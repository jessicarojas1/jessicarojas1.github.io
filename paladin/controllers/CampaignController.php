<?php
declare(strict_types=1);

/**
 * CampaignController — Acknowledgement Campaigns (QMS).
 *
 * A campaign targets a published, ack-required document revision at a defined
 * audience (everyone / a role / a space's members) with an optional due date.
 * The audience is resolved to a fixed target list at launch so progress has a
 * stable denominator. A target counts as complete when a row exists in
 * document_acknowledgements for (document_id, user_id, campaign.revision) — so
 * the existing "Acknowledge" button on the document drives campaign progress.
 */
class CampaignController
{
    /** Manage campaigns = the same authority that publishes controlled docs. */
    private function requireManage(): void { Auth::requirePermission('document.publish'); }

    public function index(): void
    {
        $this->requireManage();
        $campaigns = Database::fetchAll(
            "SELECT c.*, d.title AS doc_title, d.document_code,
                    (SELECT COUNT(*) FROM ack_campaign_targets t WHERE t.campaign_id = c.id) AS target_count,
                    (SELECT COUNT(*) FROM ack_campaign_targets t
                       JOIN document_acknowledgements da
                         ON da.user_id = t.user_id AND da.document_id = c.document_id AND da.revision = c.revision
                      WHERE t.campaign_id = c.id) AS done_count
             FROM ack_campaigns c JOIN documents d ON d.id = c.document_id
             ORDER BY c.status, c.due_date NULLS LAST, c.created_at DESC"
        );
        require PALADIN_ROOT . '/views/campaigns/index.php';
    }

    public function createForm(): void
    {
        $this->requireManage();
        $documents = Database::fetchAll(
            "SELECT id, document_code, title, revision FROM documents
             WHERE status = 'published' ORDER BY document_code"
        );
        $roles  = Database::fetchAll("SELECT DISTINCT role FROM users WHERE role IS NOT NULL ORDER BY role");
        $spaces = Database::fetchAll("SELECT id, name FROM spaces WHERE is_archived = FALSE ORDER BY name");
        require PALADIN_ROOT . '/views/campaigns/create.php';
    }

    public function create(): void
    {
        $this->requireManage();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $docId = (int)($_POST['document_id'] ?? 0);
        $doc = $docId ? Database::fetchOne("SELECT id, title, revision FROM documents WHERE id = ?", [$docId]) : null;
        if (!$doc) {
            $_SESSION['flash_error'] = 'Choose a published document.';
            header('Location: /campaigns/create'); return;
        }
        $title    = Security::sanitizeInput($_POST['title'] ?? '') ?: ('Acknowledge: ' . $doc['title']);
        $audience = in_array($_POST['audience'] ?? 'all', ['all', 'role', 'space'], true) ? $_POST['audience'] : 'all';
        $value    = match ($audience) {
            'role'  => Security::sanitizeInput($_POST['role_value'] ?? ''),
            'space' => Security::sanitizeInput($_POST['space_value'] ?? ''),
            default => '',
        };
        $due      = Security::sanitizeInput($_POST['due_date'] ?? '');
        if ($audience === 'role' && $value === '')  { $_SESSION['flash_error'] = 'Pick a role for the audience.';  header('Location: /campaigns/create'); return; }
        if ($audience === 'space' && $value === '') { $_SESSION['flash_error'] = 'Pick a space for the audience.'; header('Location: /campaigns/create'); return; }

        // Resolve the audience to a concrete user list.
        $targets = $this->resolveAudience($audience, $value);
        if (!$targets) {
            $_SESSION['flash_error'] = 'That audience has no users — pick a different audience.';
            header('Location: /campaigns/create'); return;
        }

        $campaignId = Database::insert('ack_campaigns', [
            'document_id'    => $docId,
            'revision'       => $doc['revision'],
            'title'          => $title,
            'audience'       => $audience,
            'audience_value' => $value !== '' ? $value : null,
            'due_date'       => $due !== '' ? $due : null,
            'status'         => 'active',
            'created_by'     => Auth::id(),
        ]);
        foreach ($targets as $uid) {
            Database::query(
                "INSERT INTO ack_campaign_targets (campaign_id, user_id) VALUES (?, ?)
                 ON CONFLICT (campaign_id, user_id) DO NOTHING",
                [$campaignId, $uid]
            );
        }
        // Ensure the document is flagged ack-required so the Acknowledge button shows.
        Database::update('documents', ['requires_ack' => 't'], 'id = ?', [$docId]);
        Auth::log('create_ack_campaign', 'ack_campaigns', $campaignId, ['targets' => count($targets)]);
        Webhook::dispatch('approval.requested', ['campaign' => $campaignId, 'document' => $docId, 'kind' => 'acknowledgement']);
        $_SESSION['flash_success'] = 'Campaign launched to ' . count($targets) . ' user(s).';
        header('Location: /campaigns/' . $campaignId);
    }

    public function view(int $id): void
    {
        $this->requireManage();
        $campaign = Database::fetchOne(
            "SELECT c.*, d.title AS doc_title, d.document_code
             FROM ack_campaigns c JOIN documents d ON d.id = c.document_id WHERE c.id = ?",
            [$id]
        );
        if (!$campaign) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $targets = Database::fetchAll(
            "SELECT u.id, u.name, u.email, u.department, t.notified_at,
                    da.acknowledged_at
             FROM ack_campaign_targets t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN document_acknowledgements da
                    ON da.user_id = t.user_id AND da.document_id = ? AND da.revision = ?
             WHERE t.campaign_id = ?
             ORDER BY (da.acknowledged_at IS NOT NULL), u.name",
            [(int)$campaign['document_id'], $campaign['revision'], $id]
        );
        require PALADIN_ROOT . '/views/campaigns/view.php';
    }

    /** Export campaign completion (who has/hasn't acknowledged) as CSV. */
    public function exportCsv(int $id): void
    {
        $this->requireManage();
        $campaign = Database::fetchOne(
            "SELECT c.*, d.document_code FROM ack_campaigns c JOIN documents d ON d.id = c.document_id WHERE c.id = ?",
            [$id]
        );
        if (!$campaign) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $targets = Database::fetchAll(
            "SELECT u.name, u.email, u.department, t.notified_at, da.acknowledged_at
             FROM ack_campaign_targets t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN document_acknowledgements da
                    ON da.user_id = t.user_id AND da.document_id = ? AND da.revision = ?
             WHERE t.campaign_id = ?
             ORDER BY (da.acknowledged_at IS NOT NULL), u.name",
            [(int)$campaign['document_id'], $campaign['revision'], $id]
        );
        Auth::log('export_campaign', 'ack_campaigns', $id, ['count' => count($targets)]);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', 'campaign-' . $campaign['document_code'] . '-rev' . $campaign['revision']) . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['User', 'Email', 'Department', 'Status', 'Notified At', 'Acknowledged At']);
        foreach ($targets as $t) {
            fputcsv($out, [
                $t['name'], $t['email'] ?? '', $t['department'] ?? '',
                $t['acknowledged_at'] ? 'Acknowledged' : 'Outstanding',
                $t['notified_at'] ?? '', $t['acknowledged_at'] ?? '',
            ]);
        }
        fclose($out);
    }

    public function notifyOutstanding(int $id): void
    {
        $this->requireManage();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = Database::fetchOne("SELECT * FROM ack_campaigns WHERE id = ?", [$id]);
        if (!$c) { http_response_code(404); return; }

        $outstanding = Database::fetchAll(
            "SELECT t.user_id FROM ack_campaign_targets t
             LEFT JOIN document_acknowledgements da
                    ON da.user_id = t.user_id AND da.document_id = ? AND da.revision = ?
             WHERE t.campaign_id = ? AND da.id IS NULL",
            [$c['document_id'], $c['revision'], $id]
        );
        $due = $c['due_date'] ? ' (due ' . View::fmtDate($c['due_date']) . ')' : '';
        $n = 0;
        foreach ($outstanding as $row) {
            Database::insert('alerts', [
                'user_id'  => (int)$row['user_id'],
                'title'    => 'Acknowledgement required',
                'body'     => 'Please review and acknowledge "' . $c['title'] . '"' . $due . '.',
                'severity' => 'warning',
                'link'     => '/documents/' . (int)$c['document_id'],
                'is_read'  => 'f',
            ]);
            $n++;
        }
        Database::query("UPDATE ack_campaign_targets SET notified_at = NOW() WHERE campaign_id = ?", [$id]);
        Auth::log('notify_ack_campaign', 'ack_campaigns', $id, ['notified' => $n]);
        $_SESSION['flash_success'] = "Reminder sent to {$n} outstanding user(s).";
        header('Location: /campaigns/' . $id);
    }

    public function close(int $id): void
    {
        $this->requireManage();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('ack_campaigns', ['status' => 'closed'], 'id = ?', [$id]);
        Auth::log('close_ack_campaign', 'ack_campaigns', $id);
        $_SESSION['flash_success'] = 'Campaign closed.';
        header('Location: /campaigns/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requireManage();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM ack_campaigns WHERE id = ?", [$id]);
        Auth::log('delete_ack_campaign', 'ack_campaigns', $id);
        $_SESSION['flash_success'] = 'Campaign deleted.';
        header('Location: /campaigns');
    }

    /** Resolve an audience selector to a list of active user ids. */
    private function resolveAudience(string $audience, string $value): array
    {
        if ($audience === 'role' && $value !== '') {
            $rows = Database::fetchAll("SELECT id FROM users WHERE is_active = TRUE AND role = ?", [$value]);
        } elseif ($audience === 'space' && ctype_digit($value)) {
            $rows = Database::fetchAll(
                "SELECT DISTINCT u.id FROM users u JOIN space_members m ON m.user_id = u.id
                 WHERE u.is_active = TRUE AND m.space_id = ?",
                [(int)$value]
            );
        } else {
            $rows = Database::fetchAll("SELECT id FROM users WHERE is_active = TRUE");
        }
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }
}
