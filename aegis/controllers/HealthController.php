<?php
declare(strict_types=1);

/**
 * HealthController — unauthenticated liveness/readiness probes for load
 * balancers, container orchestrators, and uptime monitors.
 *
 *   GET /healthz  — liveness: process is up and serving (no dependencies).
 *   GET /readyz   — readiness: dependencies (database) are reachable.
 *
 * Both return JSON and never expose internal detail (no versions, hostnames,
 * connection strings, or error specifics) to unauthenticated callers.
 */
final class HealthController
{
    /** Liveness: the PHP process can serve a request. Always 200 unless the app is down. */
    public function live(): void
    {
        $this->json(200, [
            'status'     => 'ok',
            'request_id' => AEGIS_REQUEST_ID,
            'time'       => date('c'),
        ]);
    }

    /** Readiness: the database is reachable. 503 if not, so traffic is held back. */
    public function ready(): void
    {
        $dbOk = false;
        try {
            $row  = Database::fetchOne('SELECT 1 AS ok');
            $dbOk = ($row['ok'] ?? null) == 1;
        } catch (Throwable $e) {
            error_log('[AEGIS][' . AEGIS_REQUEST_ID . '] readiness DB check failed: ' . $e->getMessage());
        }

        $this->json($dbOk ? 200 : 503, [
            'status'     => $dbOk ? 'ready' : 'not_ready',
            'checks'     => ['database' => $dbOk ? 'ok' : 'fail'],
            'request_id' => AEGIS_REQUEST_ID,
            'time'       => date('c'),
        ]);
    }

    private function json(int $code, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
