<?php
/**
 * AEGIS GRC — Scanner / SIEM Ingestion API
 * Endpoint: POST /api/ingest/{scanner}
 * Supported scanners: tenable | qualys | wiz | generic
 *
 * Auth: X-API-Key header (same key store as main API)
 * Rate limit: shared with main API (60 req/min per IP)
 */
declare(strict_types=1);

header('Content-Type: application/json');
header_remove('X-Powered-By');

function ingestResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $code < 400, 'data' => $data, 'ts' => date('c')]);
    exit;
}
function ingestError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg, 'ts' => date('c')]);
    exit;
}

// Determine scanner from path: /api/ingest/{scanner}
$uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scanner = strtolower(trim(substr($uri, strrpos($uri, '/') + 1)));
if (!in_array($scanner, ['tenable', 'qualys', 'wiz', 'generic'], true)) {
    ingestError("Unknown scanner '{$scanner}'. Supported: tenable, qualys, wiz, generic.", 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ingestError('Only POST is accepted on this endpoint.', 405);
}

// API key auth
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$apiKey) ingestError('X-API-Key header required.', 401);

$hash = hash('sha256', $apiKey);
$key  = Database::fetchOne(
    "SELECT ak.*, u.id AS uid, u.role FROM api_keys ak JOIN users u ON u.id = ak.user_id
     WHERE ak.key_hash = ? AND ak.is_active = TRUE AND (ak.expires_at IS NULL OR ak.expires_at > NOW())",
    [$hash]
);
if (!$key) ingestError('Invalid or expired API key.', 401);
Database::query("UPDATE api_keys SET last_used=NOW() WHERE key_hash=?", [$hash]);

$actorId = (int)$key['uid'];
$canWrite = in_array('write', json_decode($key['permissions'] ?? '["read"]', true)) || $key['role'] === 'admin';
if (!$canWrite) ingestError('API key does not have write permission.', 403);

$body = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!is_array($payload)) ingestError('Request body must be valid JSON.', 400);

// Normalise findings into a common format:
// [{ id, title, severity, description, asset, plugin_id }]
$findings = match ($scanner) {
    'tenable'  => normaliseTenableFindings($payload),
    'qualys'   => normaliseQualysFindings($payload),
    'wiz'      => normaliseWizFindings($payload),
    'generic'  => normaliseGenericFindings($payload),
};

if (empty($findings)) ingestResponse(['scanner' => $scanner, 'created' => 0, 'skipped' => 0], 200);

$created = 0;
$skipped = 0;

foreach ($findings as $f) {
    $extId = Security::sanitizeInput(substr($f['id'] ?? '', 0, 255));
    $title = Security::sanitizeInput(substr($f['title'] ?? 'Untitled Finding', 0, 500));
    $sev   = mapSeverity($f['severity'] ?? 'medium');
    $desc  = Security::sanitizeInput(substr($f['description'] ?? '', 0, 5000));
    $asset = Security::sanitizeInput(substr($f['asset'] ?? '', 0, 500));
    $score = computeRiskScore($sev);

    // Deduplicate by external ID within the last 30 days
    if ($extId) {
        $dup = Database::fetchOne(
            "SELECT id FROM risks WHERE source_external_id = ? AND created_at >= NOW() - INTERVAL '30 days'",
            [$extId]
        );
        if ($dup) { $skipped++; continue; }
    }

    $riskId = 'RSK-' . strtoupper(substr($scanner, 0, 3)) . '-' . strtoupper(substr(md5($extId ?: $title . time()), 0, 6));

    Database::insert('risks', [
        'risk_id'            => $riskId,
        'title'              => $title,
        'description'        => $desc . ($asset ? "\n\nAsset: {$asset}" : ''),
        'likelihood'         => $score['likelihood'],
        'impact'             => $score['impact'],
        'status'             => 'open',
        'source'             => "scanner:{$scanner}",
        'source_external_id' => $extId ?: null,
        'created_by'         => $actorId,
        'owner_id'           => $actorId,
    ]);
    $created++;
}

// Dispatch webhook for bulk ingest summary
if (class_exists('Webhook')) {
    Webhook::dispatch('scanner.ingest', [
        'scanner' => $scanner,
        'created' => $created,
        'skipped' => $skipped,
        'actor_id' => $actorId,
    ]);
}

ingestResponse(['scanner' => $scanner, 'created' => $created, 'skipped' => $skipped]);

// ── Normaliser functions ──────────────────────────────────────────────────────

function normaliseTenableFindings(array $payload): array {
    // Tenable.io export: { vulnerabilities: [ { plugin_name, severity, description, asset.hostname } ] }
    $raw = $payload['vulnerabilities'] ?? $payload;
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        if (!is_array($v)) continue;
        $out[] = [
            'id'          => (string)($v['plugin_id'] ?? ''),
            'title'       => $v['plugin_name'] ?? $v['name'] ?? 'Tenable Finding',
            'severity'    => strtolower($v['severity'] ?? 'medium'),
            'description' => $v['synopsis'] ?? $v['description'] ?? '',
            'asset'       => $v['asset']['hostname'] ?? $v['asset']['ipv4'] ?? $v['hostname'] ?? '',
        ];
    }
    return $out;
}

function normaliseQualysFindings(array $payload): array {
    // Qualys VMDR: { HOST_LIST_VM_DETECTION_OUTPUT: { RESPONSE: { HOST_LIST: { HOST: [...] } } } }
    // Simplified: expect array of { QID, TITLE, SEVERITY, RESULTS, IP }
    $hosts = $payload['HOST_LIST']['HOST'] ?? $payload;
    if (!is_array($hosts)) return [];
    // If single host (not array of arrays), wrap
    if (isset($hosts['IP'])) $hosts = [$hosts];
    $out = [];
    foreach ($hosts as $host) {
        if (!is_array($host)) continue;
        $detections = $host['DETECTION_LIST']['DETECTION'] ?? [];
        if (isset($detections['QID'])) $detections = [$detections];
        foreach ($detections as $d) {
            $out[] = [
                'id'          => 'QUAL-' . ($d['QID'] ?? '') . '-' . ($host['IP'] ?? ''),
                'title'       => $d['TITLE'] ?? 'Qualys Finding QID:' . ($d['QID'] ?? ''),
                'severity'    => qualysSeverityMap((int)($d['SEVERITY'] ?? 3)),
                'description' => strip_tags($d['RESULTS'] ?? ''),
                'asset'       => $host['IP'] ?? $host['DNS'] ?? '',
            ];
        }
    }
    return $out;
}

function qualysSeverityMap(int $sev): string {
    return match ($sev) { 5 => 'critical', 4 => 'high', 3 => 'medium', 2 => 'low', default => 'informational' };
}

function normaliseWizFindings(array $payload): array {
    // Wiz: { issues: [ { id, control: { name, description }, severity, entitySnapshot: { name, type } } ] }
    $raw = $payload['issues'] ?? $payload;
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $issue) {
        if (!is_array($issue)) continue;
        $out[] = [
            'id'          => $issue['id'] ?? '',
            'title'       => $issue['control']['name'] ?? $issue['name'] ?? 'Wiz Issue',
            'severity'    => strtolower($issue['severity'] ?? 'medium'),
            'description' => $issue['control']['description'] ?? $issue['description'] ?? '',
            'asset'       => ($issue['entitySnapshot']['name'] ?? '') . ' (' . ($issue['entitySnapshot']['type'] ?? '') . ')',
        ];
    }
    return $out;
}

function normaliseGenericFindings(array $payload): array {
    // Generic: array of { id?, title, severity, description?, asset? }
    $raw = $payload['findings'] ?? $payload;
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item) || empty($item['title'])) continue;
        $out[] = [
            'id'          => $item['id'] ?? '',
            'title'       => $item['title'],
            'severity'    => strtolower($item['severity'] ?? 'medium'),
            'description' => $item['description'] ?? '',
            'asset'       => $item['asset'] ?? $item['hostname'] ?? '',
        ];
    }
    return $out;
}

function mapSeverity(string $sev): string {
    return match ($sev) {
        'critical', '5', 'p1' => 'critical',
        'high', '4', 'p2'     => 'high',
        'medium', '3', 'p3'   => 'medium',
        default                => 'low',
    };
}

function computeRiskScore(string $sev): array {
    return match ($sev) {
        'critical' => ['likelihood' => 5, 'impact' => 5],
        'high'     => ['likelihood' => 4, 'impact' => 4],
        'medium'   => ['likelihood' => 3, 'impact' => 3],
        default    => ['likelihood' => 2, 'impact' => 2],
    };
}
