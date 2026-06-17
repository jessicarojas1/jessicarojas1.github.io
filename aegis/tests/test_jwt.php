<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/JWT.php';

$secret = 'test-secret-key-at-least-32-chars-long!!';
$b64url = fn(string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

it('encodes and decodes a valid token', function () use ($secret) {
    $tok  = JWT::encode(['sub' => 7, 'role' => 'analyst', 'exp' => time() + 60], $secret);
    $data = JWT::decode($tok, $secret);
    expect($data !== null, 'valid token rejected');
    expect_eq(7, $data['sub'], 'sub');
    expect_eq('analyst', $data['role'], 'role');
});

it('rejects a token with no exp claim', function () use ($secret) {
    $tok = JWT::encode(['sub' => 1], $secret);
    expect(JWT::decode($tok, $secret) === null, 'token without exp accepted');
});

it('rejects an expired token', function () use ($secret) {
    $tok = JWT::encode(['sub' => 1, 'exp' => time() - 5], $secret);
    expect(JWT::decode($tok, $secret) === null, 'expired token accepted');
});

it('rejects a token signed with a different secret', function () use ($secret) {
    $tok = JWT::encode(['sub' => 1, 'exp' => time() + 60], 'a-totally-different-secret-key-32chars');
    expect(JWT::decode($tok, $secret) === null, 'wrong-secret token accepted');
});

it('rejects a tampered payload', function () use ($secret, $b64url) {
    $tok = JWT::encode(['sub' => 1, 'role' => 'viewer', 'exp' => time() + 60], $secret);
    [$h, $p, $s] = explode('.', $tok);
    $forged = $b64url(json_encode(['sub' => 1, 'role' => 'admin', 'exp' => time() + 60]));
    expect(JWT::decode("{$h}.{$forged}.{$s}", $secret) === null, 'tampered payload accepted');
});

it('rejects alg:none (algorithm confusion)', function () use ($secret, $b64url) {
    $h = $b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $p = $b64url(json_encode(['sub' => 1, 'role' => 'admin', 'exp' => time() + 60]));
    expect(JWT::decode("{$h}.{$p}.", $secret) === null, 'alg:none accepted');
});

it('rejects a non-HS256 alg header on the HS256 path', function () use ($secret, $b64url) {
    $h = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $p = $b64url(json_encode(['sub' => 1, 'exp' => time() + 60]));
    $s = $b64url(hash_hmac('sha256', "{$h}.{$p}", $secret, true));
    expect(JWT::decode("{$h}.{$p}.{$s}", $secret) === null, 'RS256 header accepted on HS256 path');
});

it('rejects a token with iat far in the future', function () use ($secret) {
    $tok = JWT::encode(['sub' => 1, 'iat' => time() + 3600, 'exp' => time() + 7200], $secret);
    expect(JWT::decode($tok, $secret) === null, 'future-iat token accepted');
});

it('rejects malformed tokens', function () use ($secret) {
    expect(JWT::decode('not.a.token', $secret) === null, 'garbage accepted');
    expect(JWT::decode('onlyonepart', $secret) === null, 'single segment accepted');
});
