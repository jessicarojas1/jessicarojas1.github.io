<?php
declare(strict_types=1);

/**
 * Reminders — notifies document owners about upcoming/overdue review and
 * expiration dates. Run on a schedule via cli/send_review_reminders.php. Each
 * owner gets an in-app alert and an email (delivered via Mailer, or recorded in
 * the outbox when SMTP isn't configured). A per-document cooldown prevents
 * re-notifying too often.
 */
final class Reminders {

    private static function appUrl(): string {
        return rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
    }

    /**
     * Remind owners of published documents whose review or expiry falls within
     * the look-ahead window (default 14 days) — including overdue ones — that
     * haven't been reminded in the last $cooldownDays days.
     * @return array{processed:int,reminded:int}
     */
    public static function run(int $lookAheadDays = 14, int $cooldownDays = 7): array {
        $cutoff = date('Y-m-d', strtotime("+{$lookAheadDays} days"));
        try {
            $docs = Database::fetchAll(
                "SELECT d.id, d.document_code, d.title, d.review_date, d.expiration_date,
                        u.name AS owner_name, u.email AS owner_email
                 FROM documents d
                 JOIN users u ON u.id = d.owner_id
                 WHERE d.status = 'published'
                   AND u.is_active = TRUE AND u.email IS NOT NULL AND u.email <> ''
                   AND (
                        (d.review_date IS NOT NULL AND d.review_date <= ?)
                     OR (d.expiration_date IS NOT NULL AND d.expiration_date <= ?)
                   )
                   AND (d.last_review_reminder_at IS NULL
                        OR d.last_review_reminder_at < NOW() - (? || ' days')::interval)
                 ORDER BY COALESCE(d.review_date, d.expiration_date)",
                [$cutoff, $cutoff, (string)$cooldownDays]
            );
        } catch (\Throwable) {
            return ['processed' => 0, 'reminded' => 0]; // pre-migration / DB issue
        }

        $base = self::appUrl();
        $today = date('Y-m-d');
        $reminded = 0;
        foreach ($docs as $d) {
            $lines = self::dueLines($d, $today);
            if (!$lines) { continue; }
            $link = '/documents/' . (int)$d['id'];
            $summary = implode(' ', array_column($lines, 'short'));
            $severity = self::maxSeverity($lines);

            // In-app alert.
            try {
                Database::insert('alerts', [
                    'user_id'  => (int)self::ownerId($d),
                    'title'    => 'Document ' . $d['document_code'] . ' needs attention',
                    'body'     => $d['title'] . ' — ' . $summary,
                    'severity' => $severity,
                    'link'     => $link,
                    'is_read'  => 'f',
                ]);
            } catch (\Throwable) {}

            // Email.
            $rowsHtml = ''; $rowsText = '';
            foreach ($lines as $l) {
                $rowsHtml .= '<li>' . Security::h($l['long']) . '</li>';
                $rowsText .= '• ' . $l['long'] . "\n";
            }
            $url = $base . $link;
            $subject = 'Action needed: ' . $d['document_code'] . ' — ' . $summary;
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px">'
                . '<h2 style="color:#0f172a">' . Security::h((string)$d['document_code']) . ' — ' . Security::h((string)$d['title']) . '</h2>'
                . '<ul style="color:#334155">' . $rowsHtml . '</ul>'
                . ($base ? '<p><a href="' . $url . '" style="color:#2563eb">Open the document</a></p>' : '')
                . '<p style="color:#94a3b8;font-size:12px">You receive this because you own this controlled document.</p></div>';
            $text = $d['document_code'] . ' — ' . $d['title'] . "\n\n" . $rowsText
                . ($base ? "\n" . $url . "\n" : '');
            try { Mailer::send((string)$d['owner_email'], $subject, $html, $text, (int)self::ownerId($d)); } catch (\Throwable) {}

            try { Database::query("UPDATE documents SET last_review_reminder_at = NOW() WHERE id = ?", [(int)$d['id']]); } catch (\Throwable) {}
            $reminded++;
        }
        return ['processed' => count($docs), 'reminded' => $reminded];
    }

    /** Owner id resolved from the joined row (re-query kept simple). */
    private static function ownerId(array $d): int {
        $r = Database::fetchOne("SELECT owner_id FROM documents WHERE id = ?", [(int)$d['id']]);
        return (int)($r['owner_id'] ?? 0);
    }

    /** Build the list of due/overdue messages for a document. */
    private static function dueLines(array $d, string $today): array {
        $out = [];
        foreach ([['review_date', 'review'], ['expiration_date', 'expiry']] as [$col, $kind]) {
            if (empty($d[$col])) { continue; }
            $date = substr((string)$d[$col], 0, 10);
            $days = (int)floor((strtotime($date) - strtotime($today)) / 86400);
            if ($kind === 'review') {
                if ($days < 0)      { $out[] = ['short' => 'review overdue', 'long' => "Review was due on {$date} (" . abs($days) . ' days ago).', 'sev' => 'critical']; }
                else                { $out[] = ['short' => 'review due', 'long' => "Review is due on {$date} (in {$days} days).", 'sev' => 'warning']; }
            } else {
                if ($days < 0)      { $out[] = ['short' => 'expired', 'long' => "Expired on {$date} (" . abs($days) . ' days ago).', 'sev' => 'critical']; }
                else                { $out[] = ['short' => 'expiring', 'long' => "Expires on {$date} (in {$days} days).", 'sev' => 'warning']; }
            }
        }
        return $out;
    }

    private static function maxSeverity(array $lines): string {
        foreach ($lines as $l) { if ($l['sev'] === 'critical') { return 'critical'; } }
        return 'warning';
    }
}
