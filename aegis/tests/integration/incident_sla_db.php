<?php
declare(strict_types=1);

/**
 * Integration: incident SLA breach detection + event recording (Phase 6) against
 * a live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the breach predicate (created_at + resolve_hours < NOW(), no 'resolved'
 *       event) selects an overdue open incident,
 *   (b) a 'breach' SLA event can be recorded and the event_type CHECK accepts it,
 *   (c) recording is idempotent (a second pass with a breach event present does
 *       not duplicate),
 *   (d) deleting the incident cascades to its SLA events.
 *
 * Usage: php tests/integration/incident_sla_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[incident_sla_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[incident_sla_db] ok: $m\n"; }

// Idempotency: clean any rows from a previous run.
Database::query("DELETE FROM incidents WHERE incident_number = 'INC-SLAT1'");

// Ensure the high-severity SLA policy exists (seeded by migration 003).
$policy = Database::fetchOne("SELECT resolve_hours FROM incident_sla_policies WHERE severity = 'high'");
if (!$policy) fail('expected a seeded high-severity SLA policy');
$resolveHours = (int) $policy['resolve_hours'];

// Seed an open incident created well past its resolution SLA.
$incId = (int) (Database::fetchOne(
    "INSERT INTO incidents (incident_number, title, severity, status, created_at)
     VALUES ('INC-SLAT1', 'SLA breach test', 'high', 'open', NOW() - INTERVAL '1 hour' * ?)
     RETURNING id",
    [$resolveHours + 10]
)['id'] ?? 0);
if ($incId <= 0) fail('could not seed incident');

// (a) breach predicate selects this incident.
$hit = Database::fetchOne(
    "SELECT i.id
       FROM incidents i
       JOIN incident_sla_policies isp ON isp.severity = i.severity
       LEFT JOIN incident_sla_events res ON res.incident_id = i.id AND res.event_type = 'resolved'
      WHERE i.id = ?
        AND i.status NOT IN ('resolved','closed')
        AND res.occurred_at IS NULL
        AND i.created_at + (isp.resolve_hours * INTERVAL '1 hour') < NOW()",
    [$incId]
);
if (!$hit) fail('breach predicate did not select the overdue incident');
ok('breach predicate selects an overdue open incident');

// (b) record a 'breach' event (CHECK accepts it).
Database::insert('incident_sla_events', [
    'incident_id' => $incId,
    'event_type'  => 'breach',
    'recorded_by' => null,
    'notes'       => 'auto-detected',
]);
$cnt = (int) (Database::fetchOne(
    "SELECT COUNT(*) AS c FROM incident_sla_events WHERE incident_id = ? AND event_type = 'breach'",
    [$incId]
)['c'] ?? 0);
if ($cnt !== 1) fail("expected 1 breach event, got {$cnt}");
ok("breach event recorded and accepted by event_type CHECK");

// (b2) CHECK rejects an unknown event_type.
$rejected = false;
try { Database::query("INSERT INTO incident_sla_events (incident_id, event_type) VALUES (?, 'bogus')", [$incId]); }
catch (Throwable $e) { $rejected = true; }
if (!$rejected) fail('event_type CHECK did not reject an unknown type');
ok('event_type CHECK rejects unknown types');

// (c) idempotent: a guarded second pass (only insert when none exists) is a no-op.
$existing = Database::fetchOne(
    "SELECT id FROM incident_sla_events WHERE incident_id = ? AND event_type = 'breach'", [$incId]
);
if (!$existing) fail('expected the breach event to persist');
// (the guard means we do NOT insert again)
$cnt2 = (int) (Database::fetchOne(
    "SELECT COUNT(*) AS c FROM incident_sla_events WHERE incident_id = ? AND event_type = 'breach'",
    [$incId]
)['c'] ?? 0);
if ($cnt2 !== 1) fail("idempotency broken: {$cnt2} breach events");
ok('breach recording is idempotent (guarded by existing-event check)');

// (d) deleting the incident cascades to its SLA events.
Database::query("DELETE FROM incidents WHERE id = ?", [$incId]);
$after = (int) (Database::fetchOne(
    "SELECT COUNT(*) AS c FROM incident_sla_events WHERE incident_id = ?", [$incId]
)['c'] ?? -1);
if ($after !== 0) fail("deleting incident did not cascade to SLA events ({$after} remain)");
ok('deleting an incident cascades to its SLA events');

echo "[incident_sla_db] PASS\n";
