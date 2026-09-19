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
        $subs = Db::fetchAll(
            "SELECT id, url, secret FROM webhook_subscription
              WHERE active = TRUE
                AND (program_id = :pid OR :pid IS NULL)
                AND events @> :evt",
            ['pid' => $programId, 'evt' => json_encode([$event])]
        );
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
            curl_close($ch);
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
}
