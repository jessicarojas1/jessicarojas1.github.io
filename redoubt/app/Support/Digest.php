<?php

declare(strict_types=1);

namespace Redoubt\Support;

use Throwable;

/**
 * Review digest delivery — pushes pending-review reminders beyond the in-portal
 * notification bell, so approvers are told it is their turn without logging in.
 *
 * Two independent, OPTIONAL channels, both resolved at run time:
 *   • Microsoft Teams — one program summary card POSTed to the webhook URL of each
 *     active `teams` connector (config.webhook_url). No secret; just the URL.
 *   • Email — a personalized per-recipient message sent app-only via Microsoft
 *     Graph sendMail (GCC High), from Config::digestSender(). Only attempted when
 *     Graph is configured AND a sender is set.
 *
 * With nothing configured, run() is a safe no-op that makes no external calls and
 * reports what it skipped — the portal stays air-gap friendly. Recipients and
 * their pending items are resolved through the same Authorize engine as the UI, so
 * a digest never references an item its recipient could not see in the portal.
 */
final class Digest
{
    /**
     * Per-recipient pending summaries for a program — only members who actually
     * have something waiting (review items to act on, or unread notifications).
     *
     * @return array<int,array{user_id:int,email:?string,name:?string,pending:array<int,array<string,mixed>>,unread:int}>
     */
    public static function buildForProgram(int $programId): array
    {
        if (!Db::isConfigured()) {
            return [];
        }
        $out = [];
        foreach (Notifications::programMembers($programId) as $m) {
            $pending = ReviewWorkflow::awaitingForUser($m, $programId);
            $unread  = Notifications::unreadCount((int) $m['id']);
            if ($pending === [] && $unread === 0) {
                continue;
            }
            $out[] = [
                'user_id' => (int) $m['id'],
                'email'   => $m['email'] ?? null,
                'name'    => $m['name'] ?? null,
                'pending' => $pending,
                'unread'  => $unread,
            ];
        }
        return $out;
    }

    /** Plain-text body for one recipient's digest (also the Teams/email source). */
    public static function renderText(string $programName, array $digest): string
    {
        $lines = ['REDOUBT — ' . $programName . ' review digest', ''];
        $n = count($digest['pending']);
        $lines[] = $n === 0
            ? 'No review items are awaiting your action.'
            : $n . ' review item' . ($n === 1 ? '' : 's') . ' awaiting your action:';
        foreach ($digest['pending'] as $it) {
            $due = !empty($it['due_date']) ? ' (due ' . $it['due_date'] . ')' : '';
            $lines[] = '  • ' . ($it['title'] ?? 'Item') . ' — ' . ucfirst((string) $it['stage'])
                . ' / ' . ucfirst((string) $it['current_step']) . $due;
        }
        if (($digest['unread'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = $digest['unread'] . ' unread notification' . ($digest['unread'] === 1 ? '' : 's') . '.';
        }
        return implode("\n", $lines);
    }

    /** Microsoft Teams MessageCard for a program-level review summary. */
    public static function teamsCard(string $programName, array $metrics): array
    {
        $facts = [
            ['name' => 'Open', 'value' => (string) ($metrics['open'] ?? 0)],
            ['name' => 'Overdue', 'value' => (string) ($metrics['overdue'] ?? 0)],
            ['name' => 'Due within 7 days', 'value' => (string) ($metrics['due_soon'] ?? 0)],
            ['name' => 'Approved', 'value' => (string) ($metrics['by_stage']['approved'] ?? 0)],
        ];
        return [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'themeColor' => ($metrics['overdue'] ?? 0) > 0 ? 'c0392b' : '1e6fb8',
            'summary'    => $programName . ' review digest',
            'title'      => $programName . ' — Review & Approval digest',
            'sections'   => [[
                'activityTitle' => 'Pending approvals in REDOUBT',
                'facts'         => $facts,
                'markdown'      => true,
            ]],
        ];
    }

    /** Graph sendMail payload for one recipient. */
    public static function emailPayload(string $programName, array $digest): array
    {
        $text = self::renderText($programName, $digest);
        return [
            'message' => [
                'subject' => 'REDOUBT — ' . $programName . ' review digest',
                'body'    => ['contentType' => 'Text', 'content' => $text],
                'toRecipients' => [[
                    'emailAddress' => ['address' => (string) ($digest['email'] ?? '')],
                ]],
            ],
            'saveToSentItems' => false,
        ];
    }

    /**
     * Build and deliver digests for a program through whatever channels are
     * configured. Returns a summary of what was sent/skipped; never throws.
     *
     * @return array{recipients:int,teams:?bool,emails_sent:int,emails_skipped:int,reason:?string}
     */
    public static function run(int $programId, ?int $actorId): array
    {
        $result = ['recipients' => 0, 'teams' => null, 'emails_sent' => 0, 'emails_skipped' => 0, 'reason' => null];
        if (!Db::isConfigured()) {
            $result['reason'] = 'database not configured';
            return $result;
        }
        $programName = (string) (Db::fetchOne('SELECT name FROM program WHERE id = :p', ['p' => $programId])['name'] ?? 'Program');
        $digests = self::buildForProgram($programId);
        $result['recipients'] = count($digests);

        // Teams: one program card per active teams connector that has a webhook URL
        // (webhook_url preferred; base_url accepted for older connector rows).
        foreach (Connectors::listForProgram($programId) as $c) {
            $hook = $c['config']['webhook_url'] ?? ($c['config']['base_url'] ?? null);
            if ($c['kind'] === 'teams' && $c['active'] && is_string($hook) && $hook !== '') {
                $ok = self::postJson($hook, self::teamsCard($programName, ReviewWorkflow::metrics($programId)));
                $result['teams'] = (bool) ($result['teams'] ?? false) || $ok;
            }
        }

        // Email: per-recipient via Graph sendMail, only when fully configured.
        $sender = Config::digestSender();
        $emailReady = Graph::isConfigured() && $sender !== null && $sender !== '';
        foreach ($digests as $d) {
            if (empty($d['email'])) {
                $result['emails_skipped']++;
                continue;
            }
            if (!$emailReady) {
                $result['emails_skipped']++;
                continue;
            }
            try {
                Graph::post('/v1.0/users/' . rawurlencode($sender) . '/sendMail', self::emailPayload($programName, $d));
                $result['emails_sent']++;
            } catch (Throwable $e) {
                error_log('[DIGEST] email send failed for user#' . $d['user_id'] . ': ' . $e->getMessage());
                $result['emails_skipped']++;
            }
        }

        if ($result['teams'] === null && !$emailReady) {
            $result['reason'] = 'no delivery channel configured (add a Teams webhook connector or set Graph + DIGEST_MAIL_SENDER)';
        }
        Audit::log('digest.run', 'recipients=' . $result['recipients']
            . ' teams=' . ($result['teams'] ? '1' : '0')
            . ' emails=' . $result['emails_sent'], $programId, $actorId);
        return $result;
    }

    /** POST a JSON body to a URL (Teams incoming webhook). Returns success. */
    private static function postJson(string $url, array $payload): bool
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            return $code >= 200 && $code < 300;
        } catch (Throwable $e) {
            error_log('[DIGEST] Teams post failed: ' . $e->getMessage());
            return false;
        }
    }
}
