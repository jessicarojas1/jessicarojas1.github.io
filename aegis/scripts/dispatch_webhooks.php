#!/usr/bin/env php
<?php
/**
 * AEGIS GRC — Webhook delivery cron script.
 * Picks up pending webhook_deliveries and POSTs them to their endpoints.
 * Implements exponential back-off; gives up after 5 attempts.
 *
 * Add to crontab:
 *   * * * * * php /var/www/aegis/scripts/dispatch_webhooks.php >> /var/log/aegis-webhooks.log 2>&1
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('AEGIS_ROOT', dirname(__DIR__));

// ── Load environment variables ────────────────────────────────────────────────
foreach (['.env.local', '.env'] as $envFile) {
    $envPath = AEGIS_ROOT . '/' . $envFile;
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
        break;
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Webhook.php';

const MAX_ATTEMPTS = 5;
const BATCH_SIZE   = 50;

// ── Fetch pending deliveries that are due for sending ─────────────────────────
$deliveries = Database::fetchAll(
    "SELECT * FROM webhook_deliveries
      WHERE status = 'pending'
        AND next_retry_at <= NOW()
      ORDER BY next_retry_at ASC
      LIMIT " . (int) BATCH_SIZE
);

$totalDelivered = 0;
$totalRetried   = 0;
$totalFailed    = 0;

foreach ($deliveries as $delivery) {
    $endpointId = (int) $delivery['endpoint_id'];

    // Load the target endpoint (may have been deleted between cron runs)
    $endpoint = Database::fetchOne(
        "SELECT * FROM webhook_endpoints WHERE id = ?",
        [$endpointId]
    );

    if (!$endpoint) {
        // Endpoint gone; mark permanently failed so we don't loop forever
        Database::query(
            "UPDATE webhook_deliveries
                SET status = 'failed',
                    response_body = 'Endpoint not found'
              WHERE id = ?",
            [$delivery['id']]
        );
        $totalFailed++;
        continue;
    }

    $responseCode = 0;
    $responseBody = '';

    $success = Webhook::send($delivery, $endpoint, $responseCode, $responseBody);

    $newAttempts = (int) $delivery['attempts'] + 1;

    if ($success) {
        Database::query(
            "UPDATE webhook_deliveries
                SET status        = 'delivered',
                    attempts      = ?,
                    response_code = ?,
                    response_body = ?,
                    delivered_at  = NOW()
              WHERE id = ?",
            [$newAttempts, $responseCode, substr($responseBody, 0, 4000), $delivery['id']]
        );
        $totalDelivered++;
    } else {
        if ($newAttempts >= MAX_ATTEMPTS) {
            // Give up
            Database::query(
                "UPDATE webhook_deliveries
                    SET status        = 'failed',
                        attempts      = ?,
                        response_code = ?,
                        response_body = ?
                  WHERE id = ?",
                [$newAttempts, $responseCode, substr($responseBody, 0, 4000), $delivery['id']]
            );
            $totalFailed++;
        } else {
            // Exponential back-off: 2^attempts minutes (2, 4, 8, 16 minutes)
            $backoffMinutes = pow(2, $newAttempts);
            Database::query(
                "UPDATE webhook_deliveries
                    SET attempts       = ?,
                        response_code  = ?,
                        response_body  = ?,
                        next_retry_at  = NOW() + INTERVAL '1 minute' * ?
                  WHERE id = ?",
                [$newAttempts, $responseCode, substr($responseBody, 0, 4000), $backoffMinutes, $delivery['id']]
            );
            $totalRetried++;
        }
    }
}

$processed = count($deliveries);
$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Processed {$processed} deliveries "
   . "({$totalDelivered} delivered, {$totalRetried} retried, {$totalFailed} failed)\n";
