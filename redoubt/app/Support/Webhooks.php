<?php

declare(strict_types=1);

namespace Redoubt\Support;

use Throwable;

/**
 * Outbound webhooks: program-scoped subscriptions receive signed event payloads.
 *
 * Payloads are signed with HMAC-SHA256 over the raw JSON body using the
 * subscription secret; receivers verify the `X-Redoubt-Signature` header. Every
 * attempt is recorded in webhook_delivery.
 *
 * Phase 1 dispatches synchronously with a short timeout and logs failures. A
 * durable retry queue / worker is Phase 2 (see OPEN_ITEMS.md) — dispatch() is
 * written so it can be moved behind a queue without changing callers.
 */
final class Webhooks
{
    /** Emit an event to all active subscriptions for a program. */
    public static function dispatch(string $event, array $payload, ?int $programId = null): void
    {
        if (!Db::isConfigured()) {
            error_log("[WEBHOOK] (no db) event={$event} program=" . ($programId ?? '-'));
            return;
        }
        $sql = "SELECT id, url, secret FROM webhook_subscription
                 WHERE active = TRUE AND events @> :evt::jsonb";
        $params = ['evt' => json_encode([$event])];
        if ($programId !== null) {
            $sql .= ' AND program_id = :pid';
            $params['pid'] = $programId;
        }
        $subs = Db::fetchAll($sql, $params);
        foreach ($subs as $sub) {
            self::deliver((int) $sub['id'], (string) $sub['url'], (string) $sub['secret'], $event, $payload);
        }
    }

    private static function deliver(int $subId, string $url, string $secret, string $event, array $payload): void
    {
        $body = json_encode([
            'event'      => $event,
            'delivered'  => gmdate('c'),
            'data'       => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $status = 'failed';
        $code = 0;
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Redoubt-Event: ' . $event,
                    'X-Redoubt-Signature: ' . $sig,
                ],
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $status = ($code >= 200 && $code < 300) ? 'delivered' : 'failed';
        } catch (Throwable $e) {
            error_log('[WEBHOOK] delivery error: ' . $e->getMessage());
        }

        try {
            Db::insert('webhook_delivery', [
                'subscription_id' => $subId,
                'event'           => $event,
                'status'          => $status,
                'response_code'   => $code,
                'attempts'        => 1,
            ]);
        } catch (Throwable $e) {
            error_log('[WEBHOOK] delivery log failed: ' . $e->getMessage());
        }
    }

    /** Verify an inbound webhook signature (constant time). */
    public static function verifyInbound(string $secret, string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureHeader === null) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // --- Admin: subscription management -------------------------------------

    /** Domain events subscribers can select. */
    public const EVENTS = [
        'announcement.published' => 'Announcement published',
        'taskorder.awarded'      => 'Task order awarded',
        'job.posted'             => 'Job posted',
    ];

    /** Create a subscription; returns the signing secret (shown once). */
    public static function createSubscription(int $programId, string $url, array $events, ?int $actorId): string
    {
        $secret = self::newSecret();
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        Db::insert('webhook_subscription', [
            'program_id' => $programId,
            'url'        => $url,
            'secret'     => $secret,
            'events'     => $events,
            'active'     => true,
        ]);
        Audit::log('webhook.create', $url . ' [' . implode(',', $events) . ']', $programId, $actorId);
        return $secret;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listSubscriptions(int $programId): array
    {
        return array_map(static function (array $r): array {
            return [
                'id'      => (int) $r['id'],
                'url'     => $r['url'],
                'events'  => is_string($r['events']) ? (json_decode($r['events'], true) ?: []) : ($r['events'] ?? []),
                'active'  => (bool) $r['active'],
                'created' => $r['created'],
            ];
        }, Db::fetchAll(
            "SELECT id, url, events, active, to_char(created_at,'YYYY-MM-DD') created
               FROM webhook_subscription WHERE program_id = :p ORDER BY created_at DESC",
            ['p' => $programId]
        ));
    }

    public static function deleteSubscription(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM webhook_subscription WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('webhook.delete', 'subscription#' . $id, $programId, $actorId);
    }

    /** @return array<int,array<string,mixed>> recent delivery attempts */
    public static function listDeliveries(int $subscriptionId, int $limit = 10): array
    {
        return Db::fetchAll(
            "SELECT event, status, response_code, to_char(at,'YYYY-MM-DD HH24:MI:SS') at
               FROM webhook_delivery WHERE subscription_id = :s ORDER BY id DESC LIMIT :l",
            ['s' => $subscriptionId, 'l' => $limit]
        );
    }

    /** Send a test event to one subscription (program-scoped). */
    public static function sendTest(int $subscriptionId, int $programId, ?int $actorId): void
    {
        $sub = Db::fetchOne(
            'SELECT id, url, secret FROM webhook_subscription WHERE id = :id AND program_id = :p',
            ['id' => $subscriptionId, 'p' => $programId]
        );
        if ($sub === null) {
            return;
        }
        Audit::log('webhook.test', 'subscription#' . $subscriptionId, $programId, $actorId);
        self::deliver((int) $sub['id'], (string) $sub['url'], (string) $sub['secret'], 'test.ping', ['message' => 'REDOUBT test event', 'program_id' => $programId]);
    }
}
