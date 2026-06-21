<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';   // referenced by Auth/Security at call time only
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Auth.php';

// computeLogHash is pure; pass an explicit key so tests don't depend on env.

it('produces a deterministic 64-hex keyed hash', function () {
    $parts = ['genesis', '5', 'risk.create', 'risk', '9', '', '1.2.3.4'];
    $h1 = Auth::computeLogHash($parts, 'key-A');
    $h2 = Auth::computeLogHash($parts, 'key-A');
    expect_eq($h1, $h2, 'not deterministic');
    expect_eq(64, strlen($h1), 'not 64-hex');
});

it('is a KEYED HMAC, not the old forgeable unkeyed SHA-256', function () {
    $parts  = ['genesis', '5', 'risk.create', 'risk', '9', '', '1.2.3.4'];
    $keyed  = Auth::computeLogHash($parts, 'key-A');
    $unkeyed = hash('sha256', implode('|', $parts));
    expect($keyed !== $unkeyed, 'keyed hash equals unkeyed — forgeable by anyone who knows the algorithm');
    // The HMAC must match the documented construction.
    expect_eq(hash_hmac('sha256', implode('|', $parts), 'key-A'), $keyed, 'not HMAC-SHA256');
});

it('changes when the key changes (an attacker without the key cannot forge)', function () {
    $parts = ['genesis', '5', 'risk.create', 'risk', '9', '', '1.2.3.4'];
    expect(Auth::computeLogHash($parts, 'key-A') !== Auth::computeLogHash($parts, 'key-B'), 'key-insensitive');
});

it('changes when any chained field changes (tamper-evident)', function () {
    $base = ['genesis', '5', 'risk.create', 'risk', '9', '', '1.2.3.4'];
    $h    = Auth::computeLogHash($base, 'k');
    foreach ([[1,'6'], [2,'risk.delete'], [4,'10'], [6,'9.9.9.9']] as [$i, $v]) {
        $m = $base; $m[$i] = $v;
        expect(Auth::computeLogHash($m, 'k') !== $h, "field {$i} change not reflected in hash");
    }
});

it('chains: changing prev_hash changes the result (links records)', function () {
    $a = Auth::computeLogHash(['HASH_A', '5', 'x', 'risk', '1', '', 'ip'], 'k');
    $b = Auth::computeLogHash(['HASH_B', '5', 'x', 'risk', '1', '', 'ip'], 'k');
    expect($a !== $b, 'prev_hash not part of the chain');
});

// Verifier parity: the hash MUST be reconstructable from the stored columns
// (prev_hash, user_id, action, entity_type, entity_id, changes, ip) for EVERY
// row type — user, system, and failed-login — so verify_audit_log.php can check
// the whole chain. This mirrors the verifier's reconstruction exactly.
$reconstruct = function (string $prev, array $row) {
    return Auth::computeLogHash([
        $prev,
        (string)($row['user_id'] ?? ''),
        (string)$row['action'],
        (string)($row['entity_type'] ?? ''),
        (string)($row['entity_id'] ?? ''),
        (string)($row['changes'] ?? ''),
        (string)($row['ip_address'] ?? ''),
    ], 'k');
};

it('reconstructs a user row from its stored columns', function () use ($reconstruct) {
    $row = ['user_id' => 5, 'action' => 'risk.create', 'entity_type' => 'risk',
            'entity_id' => 9, 'changes' => '{"x":1}', 'ip_address' => '1.2.3.4'];
    $expected = hash_hmac('sha256', 'PREV|5|risk.create|risk|9|{"x":1}|1.2.3.4', 'k');
    expect_eq($expected, $reconstruct('PREV', $row), 'user-row reconstruction drifted');
});

it('reconstructs a system row (NULL user_id/entity_id/changes → empty)', function () use ($reconstruct) {
    $row = ['user_id' => null, 'action' => 'workflow.run', 'entity_type' => 'workflow',
            'entity_id' => null, 'changes' => null, 'ip_address' => 'system'];
    $expected = hash_hmac('sha256', 'PREV||workflow.run|workflow|||system', 'k');
    expect_eq($expected, $reconstruct('PREV', $row), 'system-row reconstruction drifted');
});

it('reconstructs a failed-login row (email captured in changes)', function () use ($reconstruct) {
    $row = ['user_id' => null, 'action' => 'login_failed', 'entity_type' => 'users',
            'entity_id' => null, 'changes' => '{"email":"a@b.com"}', 'ip_address' => '9.9.9.9'];
    $expected = hash_hmac('sha256', 'PREV||login_failed|users||{"email":"a@b.com"}|9.9.9.9', 'k');
    expect_eq($expected, $reconstruct('PREV', $row), 'failed-login reconstruction drifted');
});

it('auditKey: dedicated AUDIT_HMAC_KEY overrides the JWT_SECRET fallback', function () {
    $_ENV['JWT_SECRET'] = 'jwt-secret-32-characters-long-aaaa';
    unset($_ENV['AUDIT_HMAC_KEY']);
    $fallback = Security::auditKey();
    $_ENV['AUDIT_HMAC_KEY'] = 'dedicated-audit-key';
    $dedicated = Security::auditKey();
    expect($fallback !== $dedicated, 'dedicated key did not override fallback');
    expect_eq(32, strlen($dedicated), 'key is not 32 raw bytes');
    unset($_ENV['AUDIT_HMAC_KEY']);
});
