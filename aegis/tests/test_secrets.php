<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Secrets.php';

// A fake reader maps known paths to (already-trimmed) contents, mirroring the
// production reader in Secrets::hydrate() which trims file contents. No disk I/O.
$reader = function (string $path): ?string {
    return [
        '/run/secrets/jwt'   => 'super-secret-jwt-value',
        '/run/secrets/audit' => 'audit-key-123',
        '/run/secrets/empty' => '',
    ][$path] ?? null;
};

it('resolves a *_FILE mount into the base variable (trimmed)', function () use ($reader) {
    $out = Secrets::resolve(['JWT_SECRET_FILE' => '/run/secrets/jwt'], $reader);
    expect_eq('super-secret-jwt-value', $out['JWT_SECRET'] ?? null, 'JWT_SECRET not hydrated');
});

it('leaves variables without a *_FILE untouched', function () use ($reader) {
    $out = Secrets::resolve(['DB_PASS' => 'literal'], $reader);
    expect_eq('literal', $out['DB_PASS']);
    expect(!isset($out['DB_PASS_FILE']), 'should not invent a _FILE key');
});

it('gives an explicit direct value precedence over the file', function () use ($reader) {
    $out = Secrets::resolve(
        ['JWT_SECRET' => 'direct-wins', 'JWT_SECRET_FILE' => '/run/secrets/jwt'],
        $reader
    );
    expect_eq('direct-wins', $out['JWT_SECRET'], 'file overrode an explicit value');
});

it('ignores an empty/unreadable secret file', function () use ($reader) {
    $out = Secrets::resolve(['AUDIT_HMAC_KEY_FILE' => '/run/secrets/empty'], $reader);
    expect(!isset($out['AUDIT_HMAC_KEY']) || $out['AUDIT_HMAC_KEY'] === '', 'empty file should not set the key');
    $out2 = Secrets::resolve(['DB_PASS_FILE' => '/run/secrets/missing'], $reader);
    expect(!isset($out2['DB_PASS']), 'missing file should not set the key');
});

it('resolves multiple file-backed secrets at once', function () use ($reader) {
    $out = Secrets::resolve([
        'JWT_SECRET_FILE'    => '/run/secrets/jwt',
        'AUDIT_HMAC_KEY_FILE'=> '/run/secrets/audit',
    ], $reader);
    expect_eq('super-secret-jwt-value', $out['JWT_SECRET']);
    expect_eq('audit-key-123', $out['AUDIT_HMAC_KEY']);
});

it('only resolves the documented allowlist of variables', function () use ($reader) {
    // A non-allowlisted *_FILE must NOT be honored (prevents arbitrary env injection).
    $out = Secrets::resolve(['SOME_RANDOM_FILE' => '/run/secrets/jwt'], $reader);
    expect(!isset($out['SOME_RANDOM']), 'non-allowlisted _FILE was honored');
});

it('hydrate() reads and TRIMS a real mounted secret file', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'aegis_secret_');
    file_put_contents($tmp, "  value-with-whitespace\n\n");
    $saved = $_ENV;
    try {
        unset($_ENV['APP_ENCRYPTION_KEY']);
        $_ENV['APP_ENCRYPTION_KEY_FILE'] = $tmp;
        Secrets::hydrate();
        expect_eq('value-with-whitespace', $_ENV['APP_ENCRYPTION_KEY'] ?? null, 'hydrate did not read/trim the file');
    } finally {
        $_ENV = $saved;
        @unlink($tmp);
    }
});
