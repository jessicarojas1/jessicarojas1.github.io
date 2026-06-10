<?php
declare(strict_types=1);

/**
 * Digest — builds and sends per-user notification digests. A digest summarises
 * the alerts a user accrued since their last digest (new pages in watched
 * spaces, page changes, approvals routed to them, etc.). Run on a schedule via
 * cli/send_digests.php (cron), or previewed for a single user from the UI.
 */
final class Digest {

    private static function appUrl(): string {
        return rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
    }

    /**
     * Build a digest for one user from alerts since a cutoff. Returns
     * ['subject' => , 'html' => , 'text' => , 'count' => ] or null if empty.
     */
    public static function buildForUser(array $user, ?string $since): ?array {
        $params = [(int)$user['id']];
        $sinceSql = '';
        if ($since !== null) { $sinceSql = ' AND created_at > ?'; $params[] = $since; }
        $alerts = Database::fetchAll(
            "SELECT title, body, severity, link, created_at FROM alerts
             WHERE user_id = ?{$sinceSql} ORDER BY created_at DESC LIMIT 100",
            $params
        );
        if (!$alerts) { return null; }

        $base = self::appUrl();
        $name = (string)($user['name'] ?? 'there');
        $count = count($alerts);
        $subject = "Your PALADIN digest — {$count} update" . ($count === 1 ? '' : 's');

        $rowsHtml = ''; $rowsText = '';
        foreach ($alerts as $a) {
            $when = date('M j, g:ia', strtotime((string)$a['created_at']));
            $url  = $a['link'] ? $base . Security::h((string)$a['link']) : '';
            $title = Security::h((string)$a['title']);
            $body  = Security::h((string)$a['body']);
            $rowsHtml .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e5e7eb">'
                . '<div style="font-weight:600;color:#0f172a">' . $title . '</div>'
                . '<div style="color:#475569;font-size:14px;margin:2px 0">' . $body . '</div>'
                . '<div style="color:#94a3b8;font-size:12px">' . Security::h($when)
                . ($url ? ' · <a href="' . $url . '" style="color:#2563eb">View</a>' : '') . '</div></td></tr>';
            $rowsText .= "• {$a['title']} — {$a['body']} ({$when})"
                . ($a['link'] ? " {$base}{$a['link']}" : '') . "\n";
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">'
            . '<h2 style="color:#0f172a">Hi ' . Security::h($name) . ',</h2>'
            . '<p style="color:#475569">Here\'s what happened in PALADIN since your last digest:</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $rowsHtml . '</table>'
            . '<p style="color:#94a3b8;font-size:12px;margin-top:18px">You receive this because you enabled email digests. '
            . 'Manage this in your notification preferences' . ($base ? ' at ' . $base . '/profile/notifications' : '') . '.</p></div>';
        $text = "Hi {$name},\n\nHere's what happened in PALADIN since your last digest:\n\n{$rowsText}\n"
            . "Manage email digests in your notification preferences" . ($base ? " ({$base}/profile/notifications)" : '') . ".\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'count' => $count];
    }

    /**
     * Send digests to all users whose frequency matches and who are due.
     * @return array{processed:int,sent:int,skipped:int}
     */
    public static function run(string $frequency = 'daily'): array {
        $frequency = in_array($frequency, ['daily', 'weekly'], true) ? $frequency : 'daily';
        $minGapHours = $frequency === 'weekly' ? 24 * 6.5 : 20; // guard against double-sends

        $users = Database::fetchAll(
            "SELECT id, name, email, digest_frequency, digest_last_sent_at
             FROM users
             WHERE is_active = TRUE AND digest_frequency = ? AND email IS NOT NULL AND email <> ''",
            [$frequency]
        );
        $processed = 0; $sent = 0; $skipped = 0;
        foreach ($users as $u) {
            $processed++;
            $last = $u['digest_last_sent_at'] ?? null;
            if ($last !== null && (time() - strtotime((string)$last)) < $minGapHours * 3600) { $skipped++; continue; }
            $digest = self::buildForUser($u, $last);
            if ($digest === null) {
                // Nothing to report — still advance the clock so we don't recheck constantly.
                Database::update('users', ['digest_last_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$u['id']]);
                $skipped++;
                continue;
            }
            Mailer::send((string)$u['email'], $digest['subject'], $digest['html'], $digest['text'], (int)$u['id']);
            Database::update('users', ['digest_last_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$u['id']]);
            $sent++;
        }
        return ['processed' => $processed, 'sent' => $sent, 'skipped' => $skipped];
    }
}
