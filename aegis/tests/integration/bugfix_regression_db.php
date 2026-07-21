<?php
declare(strict_types=1);

/**
 * Integration: regression guards for the Phase 28 bug-hunt fixes. Each asserts
 * that the exact INSERT/UPDATE column & value shapes the controllers now use are
 * valid against the live schema — i.e. the crashing statements can't silently
 * return. Runs in a transaction and rolls back.
 *
 *  BUG1 QuestionnaireController::submit — questionnaire_responses(assignment_id,
 *       submitted_by), questionnaire_answers(answer_text), assignment status.
 *  BUG2 AssetController — assets.tags / classification are NOT NULL; empty input
 *       must fall back to '[]' / 'internal', never null.
 *  BUG3 RiskController::update — residual_score / target_score persisted.
 *  BUG5 VendorController — vendor_assessments.status accepts 'cancelled'
 *       (previously the code offered 'overdue', which violates the CHECK).
 *
 * Usage: php tests/integration/bugfix_regression_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[bugfix_regression_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[bugfix_regression_db] ok: $m\n"; }

$pdo = Database::getInstance();
$pdo->exec("SET search_path TO aegis");
$pdo->beginTransaction();

try {
    // ── BUG2: asset with empty tags/classification uses NOT-NULL-safe defaults ──
    Database::insert('assets', [
        'name' => 'BR Asset', 'asset_type' => 'server', 'criticality' => 'medium',
        'classification' => 'internal', 'status' => 'active', 'tags' => '[]',
    ]);
    ok('BUG2: asset insert with tags=\'[]\' / classification=\'internal\' succeeds');

    // ── BUG5: vendor assessment accepts 'cancelled' (not the old 'overdue') ─────
    $vid = (int) Database::insert('vendors', ['name' => 'BR Vendor', 'status' => 'active']);
    Database::insert('vendor_assessments', ['vendor_id' => $vid, 'status' => 'cancelled']);
    ok('BUG5: vendor_assessments.status=\'cancelled\' satisfies the CHECK');

    // ── BUG1: questionnaire submit column shapes ───────────────────────────────
    $qid = (int) Database::insert('questionnaires', ['title' => 'BR Q']);
    $qqid = (int) Database::insert('questionnaire_questions', [
        'questionnaire_id' => $qid, 'question_text' => 'Q?', 'question_type' => 'boolean', 'weight' => 3,
    ]);
    $aid = (int) Database::insert('questionnaire_assignments', [
        'questionnaire_id' => $qid, 'assigned_to' => 1, 'status' => 'pending',
    ]);
    $rid = (int) Database::insert('questionnaire_responses', [
        'assignment_id' => $aid, 'submitted_by' => 1, 'total_score' => 0, 'max_score' => 0,
    ]);
    Database::insert('questionnaire_answers', [
        'response_id' => $rid, 'question_id' => $qqid, 'answer_text' => 'yes', 'score' => 3.0,
    ]);
    Database::query("UPDATE questionnaire_assignments SET status='submitted' WHERE id=?", [$aid]);
    ok('BUG1: questionnaire response/answer/assignment column shapes are valid');

    // ── BUG3: risk update persists residual_score / target_score ───────────────
    $kid = (int) Database::insert('risks', ['title' => 'BR Risk']);
    Database::query(
        "UPDATE risks SET residual_likelihood=4, residual_impact=4, residual_score=16,
                          target_likelihood=2, target_impact=2, target_score=4 WHERE id=?",
        [$kid]
    );
    $row = Database::fetchOne("SELECT residual_score, target_score FROM risks WHERE id=?", [$kid]);
    if ((int) $row['residual_score'] !== 16 || (int) $row['target_score'] !== 4) {
        fail("BUG3: residual/target score not persisted (got " . json_encode($row) . ")");
    }
    ok('BUG3: risk residual_score=16 / target_score=4 persisted');

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fail($e->getMessage());
}

echo "[bugfix_regression_db] PASS\n";
