<?php
declare(strict_types=1);

/**
 * Webhook — outbound HTTP callbacks fired on platform events.
 *
 * Admins register endpoints (URL + optional shared secret + event filter) via
 * the Administration → Webhooks console. When an event occurs, call
 * Webhook::dispatch('page.published', [...]) and every active subscriber whose
 * filter matches receives a signed POST. Delivery is best-effort with a short
 * timeout so it never blocks the request; each attempt is logged.
 */
final class Webhook
{
    /** Canonical event catalogue (key => human label) shown in the admin UI. */
    public const EVENTS = [
        'page.published'        => 'Page published',
        'page.updated'          => 'Page updated',
        'document.published'    => 'Document published',
        'document.approved'     => 'Document approved',
        'document.archived'     => 'Document archived',
        'document.expired'      => 'Document expired',
        'document.superseded'   => 'Document superseded',
        'document.retired'      => 'Document retired',
        'workflow.transitioned' => 'Workflow state changed',
        'approval.requested'    => 'Approval requested',
        'approval.completed'    => 'Approval completed',
        'space.created'         => 'Space created',
        'comment.created'       => 'Comment added',
    ];

    /**
     * Fire an event to every matching active webhook. Never throws — a failing
     * endpoint is logged and the request continues.
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        try {
            $hooks = Database::fetchAll("SELECT * FROM webhooks WHERE is_active = TRUE");
        } catch (\Throwable) {
            return;
        }
        if (!$hooks) return;

        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) return;

        foreach ($hooks as $h) {
            if (!self::subscribes($h['events'] ?? '*', $event)) continue;
            self::deliver($h, $event, $body);
        }
    }

    /** Whether a comma-separated subscription string matches an event ('*' = all). */
    private static function subscribes(string $events, string $event): bool
    {
        $events = trim($events);
        if ($events === '' || $events === '*') return true;
        $list = array_map('trim', explode(',', $events));
        return in_array($event, $list, true);
    }

    /** Total delivery attempts (initial + retries) before giving up. */
    private const MAX_ATTEMPTS = 4;
    /** Backoff (seconds) before the next attempt, keyed by attempts made so far. */
    private const BACKOFF = [1 => 60, 2 => 300, 3 => 1800];

    /**
     * Deliver one signed POST and record the outcome (a new delivery row).
     * Returns the HTTP status code (0 on transport failure / blocked). Used by
     * dispatch() and the admin "Test" button. Failures that look transient are
     * scheduled for retry with exponential backoff (see retryDue()).
     */
    public static function deliver(array $hook, string $event, string $body): int
    {
        [$status, $error] = self::send($hook, $event, $body);
        $success   = $status >= 200 && $status < 300;
        $nextRetry = (!$success && self::retriable($status, $error)) ? self::backoffAt(1) : null;
        self::record((int)$hook['id'], $event, $status, $success, $error, $body, 1, $nextRetry);
        self::updateHookCounters((int)$hook['id'], $status, $success);
        return $status;
    }

    /**
     * Re-attempt due, still-failing deliveries with exponential backoff. Safe to
     * call opportunistically on common requests; no-ops when nothing is due.
     * @return int number of deliveries re-attempted this sweep
     */
    public static function retryDue(): int
    {
        try {
            $rows = Database::fetchAll(
                "SELECT d.id, d.webhook_id, d.event, d.payload, d.attempts, w.url, w.secret
                 FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.success = FALSE AND d.next_retry_at IS NOT NULL
                   AND d.next_retry_at <= NOW() AND d.attempts < ? AND w.is_active = TRUE
                 ORDER BY d.next_retry_at LIMIT 25",
                [self::MAX_ATTEMPTS]
            );
        } catch (\Throwable) {
            return 0; // pre-migration (columns absent) — stay silent
        }

        $count = 0;
        foreach ($rows as $r) {
            $attempt = (int)$r['attempts'] + 1;
            if ($r['payload'] === null) {
                // Nothing to replay — close it out.
                Database::query("UPDATE webhook_deliveries SET next_retry_at = NULL WHERE id = ?", [(int)$r['id']]);
                continue;
            }
            $hook = ['id' => (int)$r['webhook_id'], 'url' => $r['url'], 'secret' => $r['secret']];
            [$status, $error] = self::send($hook, (string)$r['event'], (string)$r['payload']);
            $success   = $status >= 200 && $status < 300;
            $nextRetry = (!$success && $attempt < self::MAX_ATTEMPTS && self::retriable($status, $error))
                ? self::backoffAt($attempt) : null;
            Database::query(
                "UPDATE webhook_deliveries
                 SET attempts = ?, success = ?, status_code = ?, error = ?, next_retry_at = ?
                 WHERE id = ?",
                [$attempt, $success ? 't' : 'f', $status ?: null, $error, $nextRetry, (int)$r['id']]
            );
            self::updateHookCounters((int)$r['webhook_id'], $status, $success);
            $count++;
        }
        return $count;
    }

    /**
     * Dead-lettered deliveries: failures that have exhausted their retry budget
     * (no further automatic attempt scheduled) and still have a replayable body.
     * Surfaced in the admin dead-letter console so an operator can manually
     * replay once the downstream endpoint is healthy again.
     * @return array<int,array<string,mixed>>
     */
    public static function deadLettered(int $limit = 200): array
    {
        try {
            return Database::fetchAll(
                "SELECT d.id, d.webhook_id, d.event, d.status_code, d.error, d.attempts,
                        d.created_at, d.payload IS NOT NULL AS replayable,
                        w.name AS hook_name, w.url AS hook_url, w.is_active
                 FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.success = FALSE AND d.next_retry_at IS NULL AND d.attempts >= ?
                 ORDER BY d.created_at DESC
                 LIMIT ?",
                [self::MAX_ATTEMPTS, $limit]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Count of dead-lettered deliveries (for admin badges). */
    public static function deadLetterCount(): int
    {
        try {
            return (int)(Database::fetchOne(
                "SELECT COUNT(*) c FROM webhook_deliveries
                 WHERE success = FALSE AND next_retry_at IS NULL AND attempts >= ?",
                [self::MAX_ATTEMPTS]
            )['c'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Manually replay a single stored delivery, regardless of its retry budget.
     * Re-sends the original payload to its (still-active) webhook and updates the
     * same delivery row in place with the fresh outcome. On a transient failure
     * the delivery re-enters the normal backoff queue; on a permanent one it
     * returns to the dead-letter list. Returns the HTTP status (0 = unreachable),
     * or -1 when the delivery can't be replayed (missing / no payload / inactive).
     */
    public static function replay(int $deliveryId): int
    {
        try {
            $r = Database::fetchOne(
                "SELECT d.id, d.webhook_id, d.event, d.payload, d.attempts, w.url, w.secret, w.is_active
                 FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.id = ?",
                [$deliveryId]
            );
        } catch (\Throwable) {
            return -1;
        }
        if (!$r || $r['payload'] === null) return -1;
        if (!in_array(strtolower((string)($r['is_active'] ?? '')), ['1', 't', 'true'], true)
            && $r['is_active'] !== true) {
            return -1; // don't replay to a paused endpoint
        }

        $attempt = (int)$r['attempts'] + 1;
        $hook = ['id' => (int)$r['webhook_id'], 'url' => $r['url'], 'secret' => $r['secret']];
        [$status, $error] = self::send($hook, (string)$r['event'], (string)$r['payload']);
        $success   = $status >= 200 && $status < 300;
        // Manual replay is operator-driven, not scheduled: on success the row
        // clears; on failure it returns to the dead-letter list (next_retry_at
        // NULL) for another explicit attempt rather than re-entering the auto
        // backoff queue (whose budget is already spent).
        try {
            Database::query(
                "UPDATE webhook_deliveries
                 SET attempts = ?, success = ?, status_code = ?, error = ?, next_retry_at = NULL
                 WHERE id = ?",
                [$attempt, $success ? 't' : 'f', $status ?: null, $error, (int)$r['id']]
            );
        } catch (\Throwable) { /* best effort */ }
        self::updateHookCounters((int)$r['webhook_id'], $status, $success);
        return $status;
    }

    /** Perform the signed HTTP POST. Returns [statusCode, errorOrNull]. */
    private static function send(array $hook, string $event, string $body): array
    {
        $url = trim((string)($hook['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            return [0, 'Invalid URL'];
        }
        // SSRF guard: resolve + validate the target is a public address, and pin
        // the connection to that IP so DNS cannot be rebound to an internal host.
        $pinnedIp = Security::safeOutboundIp($url);
        if ($pinnedIp === null) {
            return [0, 'Blocked: target resolves to a private, reserved or unresolvable address'];
        }
        $parts = parse_url($url);
        $port  = (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        $headers = [
            'Content-Type: application/json',
            'User-Agent: PALADIN-Webhook/1.0',
            'X-Paladin-Event: ' . $event,
        ];
        $secret = (string)($hook['secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-Paladin-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $status = 0; $error = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$parts['host'] . ':' . $port . ':' . $pinnedIp],
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $error = curl_error($ch) ?: 'Request failed';
        } else {
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);
        return [$status, $error];
    }

    /** Whether a failed delivery should be retried (transient errors / 5xx only). */
    private static function retriable(int $status, ?string $error): bool
    {
        if ($error !== null && (str_starts_with($error, 'Blocked') || $error === 'Invalid URL')) {
            return false; // permanent config/SSRF problem — never retry
        }
        if ($status >= 500) { return true; }   // server error
        if ($status === 0)  { return true; }    // transport failure / timeout
        return false;                            // 3xx / 4xx → do not retry
    }

    /** Timestamp for the next attempt given attempts already made (null = give up). */
    private static function backoffAt(int $attemptsMade): ?string
    {
        $delay = self::BACKOFF[$attemptsMade] ?? null;
        return $delay === null ? null : date('Y-m-d H:i:s', time() + $delay);
    }

    private static function updateHookCounters(int $hookId, int $status, bool $success): void
    {
        try {
            if ($success) {
                Database::query(
                    "UPDATE webhooks SET last_status = ?, last_fired_at = NOW(), failure_count = 0, updated_at = NOW() WHERE id = ?",
                    [$status, $hookId]
                );
            } else {
                Database::query(
                    "UPDATE webhooks SET last_status = ?, last_fired_at = NOW(), failure_count = failure_count + 1, updated_at = NOW() WHERE id = ?",
                    [$status, $hookId]
                );
            }
        } catch (\Throwable) { /* counters are best-effort */ }
    }

    private static function record(int $hookId, string $event, int $status, bool $success, ?string $error, ?string $payload = null, int $attempts = 1, ?string $nextRetry = null): void
    {
        try {
            Database::insert('webhook_deliveries', [
                'webhook_id'    => $hookId,
                'event'         => $event,
                'status_code'   => $status ?: null,
                'success'       => $success ? 't' : 'f',
                'error'         => $error,
                'payload'       => $payload,
                'attempts'      => $attempts,
                'next_retry_at' => $nextRetry,
            ]);
        } catch (\Throwable) {
            // Fallback for pre-migration schemas without the retry columns.
            try {
                Database::insert('webhook_deliveries', [
                    'webhook_id'  => $hookId,
                    'event'       => $event,
                    'status_code' => $status ?: null,
                    'success'     => $success ? 't' : 'f',
                    'error'       => $error,
                ]);
            } catch (\Throwable) { /* best effort */ }
        }
    }
}
