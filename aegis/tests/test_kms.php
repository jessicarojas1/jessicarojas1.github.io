<?php
declare(strict_types=1);

// KMS envelope-encryption logic. resolveEnv() is pure (the unwrapper is injected)
// and provider() selection is pure, so both are unit-testable without a live KMS.
// The exec provider is exercised end-to-end with a harmless local command (`cat`,
// the identity unwrapper) — no cloud required. Vault is validated up to the point
// before any network call.
require_once __DIR__ . '/../src/Kms.php';

it('resolveEnv is a no-op when no ciphertext is present', function () {
    $called = false;
    $out = Kms::resolveEnv(['APP_ENCRYPTION_KEY' => 'k'], function (string $c) use (&$called) {
        $called = true; return 'x';
    });
    expect(!$called, 'unwrapper must not be called without a ciphertext');
    expect_eq('k', $out['APP_ENCRYPTION_KEY']);
});

it('an explicit plaintext key wins over a ciphertext (unwrapper not called)', function () {
    $called = false;
    $out = Kms::resolveEnv(
        ['APP_ENCRYPTION_KEY' => 'plain', 'APP_ENCRYPTION_KEY_CIPHERTEXT' => 'wrapped'],
        function (string $c) use (&$called) { $called = true; return 'unwrapped'; }
    );
    expect(!$called, 'explicit plaintext must short-circuit the unwrapper');
    expect_eq('plain', $out['APP_ENCRYPTION_KEY']);
});

it('resolveEnv unwraps the ciphertext when no plaintext key is set', function () {
    $seen = '';
    $out = Kms::resolveEnv(
        ['APP_ENCRYPTION_KEY_CIPHERTEXT' => 'CIPHER'],
        function (string $c) use (&$seen) { $seen = $c; return 'PLAINKEY'; }
    );
    expect_eq('CIPHER', $seen, 'unwrapper received the ciphertext');
    expect_eq('PLAINKEY', $out['APP_ENCRYPTION_KEY'] ?? null, 'plaintext key was not set');
});

it('resolveEnv rejects an empty unwrap result', function () {
    $threw = false;
    try {
        Kms::resolveEnv(['APP_ENCRYPTION_KEY_CIPHERTEXT' => 'c'], fn (string $c) => '');
    } catch (RuntimeException) { $threw = true; }
    expect($threw, 'an empty unwrapped key must be rejected');
});

it('provider() returns null when KMS is disabled (default/none/local)', function () {
    foreach (['', 'none', 'NONE', 'local', ' '] as $v) {
        expect(Kms::provider(['KMS_PROVIDER' => $v]) === null, "provider for '{$v}' should be null");
    }
    expect(Kms::provider([]) === null, 'missing KMS_PROVIDER should be null');
});

it('provider() maps names to the right implementations', function () {
    expect(Kms::provider(['KMS_PROVIDER' => 'vault']) instanceof VaultTransitKmsProvider, 'vault not mapped');
    expect(Kms::provider(['KMS_PROVIDER' => 'exec'])  instanceof ExecKmsProvider, 'exec not mapped');
});

it('provider() rejects an unknown provider name', function () {
    $threw = false;
    try { Kms::provider(['KMS_PROVIDER' => 'wat']); }
    catch (RuntimeException) { $threw = true; }
    expect($threw, 'unknown KMS_PROVIDER must throw');
});

it('vault provider requires its connection settings before any network call', function () {
    $p = new VaultTransitKmsProvider(['VAULT_ADDR' => '', 'VAULT_TOKEN' => '', 'VAULT_TRANSIT_KEY' => '']);
    $threw = false;
    try { $p->unwrap('vault:v1:abc'); }
    catch (RuntimeException) { $threw = true; }
    expect($threw, 'missing VAULT_* config must throw before connecting');
});

it('exec provider requires KMS_DECRYPT_CMD', function () {
    $threw = false;
    try { (new ExecKmsProvider([]))->unwrap('c'); }
    catch (RuntimeException) { $threw = true; }
    expect($threw, 'missing KMS_DECRYPT_CMD must throw');
});

it('exec provider round-trips via stdin/stdout (identity command)', function () {
    // `cat` echoes the ciphertext on stdin back to stdout — the identity unwrapper.
    $p = new ExecKmsProvider(['KMS_DECRYPT_CMD' => 'cat']);
    expect_eq('the-wrapped-key', $p->unwrap('the-wrapped-key'));
});

it('exec provider base64-decodes when the command does (AWS-style pipeline)', function () {
    $p = new ExecKmsProvider(['KMS_DECRYPT_CMD' => 'base64 -d']);
    $secret = "raw-key-\x01\x02bytes";
    expect_eq($secret, $p->unwrap(base64_encode($secret)));
});

it('exec provider surfaces a non-zero exit as an error', function () {
    $p = new ExecKmsProvider(['KMS_DECRYPT_CMD' => 'sh -c "exit 7"']);
    $threw = false;
    try { $p->unwrap('c'); } catch (RuntimeException) { $threw = true; }
    expect($threw, 'a failing KMS_DECRYPT_CMD must throw');
});
