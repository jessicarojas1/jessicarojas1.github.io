<?php
declare(strict_types=1);

/**
 * Integration: POA&M overdue detection (Phase 8) against a live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the item-overdue predicate (status NOT IN closed/cancelled AND
 *       scheduled_completion < today) selects a stale open item and ignores
 *       closed/future ones,
 *   (b) the overdue-milestone count subquery counts unconducted past-due
 *       milestones correctly,
 *   (c) deleting a POA&M item cascades to its milestones.
 *
 * Usage: php tests/integration/poam_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[poam_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[poam_db] ok: $m\n"; }

// Idempotency: clean prior test rows.
Database::query("DELETE FROM poam_items WHERE poam_number IN ('POAM-T1','POAM-T2','POAM-T3')");

// Seed three items: overdue open, overdue-but-closed, future open.
$overdueId = (int) (Database::fetchOne("INSERT INTO poam_items (poam_number,title,status,scheduled_completion) VALUES ('POAM-T1','Overdue open','open', CURRENT_DATE - 5) RETURNING id")['id'] ?? 0);
$closedId  = (int) (Database::fetchOne("INSERT INTO poam_items (poam_number,title,status,scheduled_completion) VALUES ('POAM-T2','Overdue closed','closed', CURRENT_DATE - 5) RETURNING id")['id'] ?? 0);
$futureId  = (int) (Database::fetchOne("INSERT INTO poam_items (poam_number,title,status,scheduled_completion) VALUES ('POAM-T3','Future open','open', CURRENT_DATE + 5) RETURNING id")['id'] ?? 0);
if ($overdueId <= 0 || $closedId <= 0 || $futureId <= 0) fail('could not seed poam items');

// (a) item-overdue predicate selects exactly the overdue open item.
$hits = Database::fetchAll(
    "SELECT poam_number FROM poam_items
      WHERE status NOT IN ('closed','cancelled')
        AND scheduled_completion IS NOT NULL
        AND scheduled_completion < CURRENT_DATE
        AND poam_number IN ('POAM-T1','POAM-T2','POAM-T3')",
    []
);
if (count($hits) !== 1 || $hits[0]['poam_number'] !== 'POAM-T1') {
    fail('item-overdue predicate selected ' . count($hits) . ' rows (expected just POAM-T1)');
}
ok('item-overdue predicate selects only the open, past-due item');

// (b) overdue-milestone count subquery.
Database::insert('poam_milestones', ['poam_id' => $overdueId, 'description' => 'late ms',  'due_date' => date('Y-m-d', strtotime('-3 days'))]);
Database::insert('poam_milestones', ['poam_id' => $overdueId, 'description' => 'done ms',  'due_date' => date('Y-m-d', strtotime('-3 days')), 'is_complete' => true, 'completed_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
Database::insert('poam_milestones', ['poam_id' => $overdueId, 'description' => 'future ms','due_date' => date('Y-m-d', strtotime('+3 days'))]);
$overdueMs = (int) (Database::fetchOne(
    "SELECT COUNT(*) FILTER (WHERE pm.is_complete = FALSE AND pm.due_date < CURRENT_DATE) AS c
       FROM poam_milestones pm WHERE pm.poam_id = ?",
    [$overdueId]
)['c'] ?? -1);
if ($overdueMs !== 1) fail("overdue-milestone count returned {$overdueMs} (expected 1)");
ok('overdue-milestone count subquery counts only past-due, incomplete milestones');

// (c) deleting the item cascades to its milestones.
Database::query("DELETE FROM poam_items WHERE id = ?", [$overdueId]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM poam_milestones WHERE poam_id = ?", [$overdueId])['c'] ?? -1);
if ($after !== 0) fail("deleting item did not cascade to milestones ({$after} remain)");
ok('deleting a POA&M item cascades to its milestones');

// cleanup remaining seeds
Database::query("DELETE FROM poam_items WHERE poam_number IN ('POAM-T2','POAM-T3')");

echo "[poam_db] PASS\n";
