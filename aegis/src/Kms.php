<?php
declare(strict_types=1);

/**
 * KMS — envelope encryption for the application data key (APP_ENCRYPTION_KEY).
 *
 * Default (KMS_PROVIDER unset / "none"): INERT — APP_ENCRYPTION_KEY is used
 * exactly as provided, so existing deployments are unchanged.
 *
 * For higher assurance (NIST SP 800-53 SC-12/SC-28, FedRAMP/IL4): keep the
 * key-encryption key (KEK) in a KMS/HSM and store only the WRAPPED data key in
 * config as APP_ENCRYPTION_KEY_CIPHERTEXT. At bootstrap this unwraps it via the
 * KMS and sets the plaintext APP_ENCRYPTION_KEY in-process only — the plaintext
 * key never touches disk or the persisted settings.
 *
 * Providers (framework-free, no cloud SDK bundled):
 *   - vault: HashiCorp Vault transit `decrypt` over HTTPS.
 *   - exec:  run an operator-configured command (KMS_DECRYPT_CMD) that reads the
 *            ciphertext on stdin and writes the plaintext key to stdout — a
 *            universal escape hatch for AWS KMS / GCP KMS / Azure Key Vault via
 *            their CLIs or a sidecar, without bundling an SDK.
 *
 * Wire Kms::hydrate() right AFTER Secrets::hydrate() in bootstrap (the ciphertext
 * itself may be delivered via a *_FILE mount and resolved by Secrets first).
 */
interface KmsProvider
{
    /** Unwrap an opaque ciphertext into the plaintext key bytes. */
    public function unwrap(string $ciphertext): string;
}

final class Kms
{
    /**
     * Resolve a wrapped data key. Pure + injectable (the unwrapper is passed in)
     * so the envelope logic is unit-testable without a live KMS.
     *
     * Precedence: an explicit plaintext APP_ENCRYPTION_KEY always wins (emergency
     * override / local dev). The unwrapper is called only when a ciphertext is
     * present AND no plaintext key is set.
     *
     * @param array<string,string>     $env
     * @param callable(string):string  $unwrap  ciphertext => plaintext key bytes
     * @return array<string,string>
     */
    public static function resolveEnv(array $env, callable $unwrap): array
    {
        $ct = $env['APP_ENCRYPTION_KEY_CIPHERTEXT'] ?? '';
        if ($ct === '') {
            return $env;                       // nothing wrapped to resolve
        }
        if (!empty($env['APP_ENCRYPTION_KEY'])) {
            return $env;                       // explicit plaintext wins
        }
        $pt = $unwrap($ct);
        if ($pt === '') {
            throw new RuntimeException('KMS unwrap returned an empty key.');
        }
        $env['APP_ENCRYPTION_KEY'] = $pt;
        return $env;
    }

    /** Select the configured provider, or null when KMS is disabled (default). */
    public static function provider(array $env): ?KmsProvider
    {
        $name = strtolower(trim($env['KMS_PROVIDER'] ?? ''));
        return match ($name) {
            '', 'none', 'local' => null,
            'vault'             => new VaultTransitKmsProvider($env),
            'exec'              => new ExecKmsProvider($env),
            default             => throw new RuntimeException("Unknown KMS_PROVIDER: {$name}"),
        };
    }

    /** Apply KMS unwrapping to the live $_ENV at bootstrap (after Secrets::hydrate). */
    public static function hydrate(): void
    {
        $provider = self::provider($_ENV);
        if ($provider === null) {
            return;                            // inert default
        }
        try {
            $_ENV = self::resolveEnv($_ENV, static fn (string $ct): string => $provider->unwrap($ct));
        } catch (Throwable $e) {
            error_log('[AEGIS] KMS unwrap failed: ' . $e->getMessage());
            // Operator-safe message (RuntimeException is rendered as a config error).
            throw new RuntimeException('Could not unwrap APP_ENCRYPTION_KEY via KMS. Check KMS_PROVIDER and its credentials.');
        }
    }
}

/**
 * HashiCorp Vault transit secrets engine. Unwraps a `vault:v1:...` ciphertext via
 * POST {VAULT_ADDR}/v1/transit/decrypt/{VAULT_TRANSIT_KEY} with an X-Vault-Token
 * header. Uses the streams HTTP wrapper (TLS verified by default) — no curl ext.
 * The Vault address is operator config, not user input (no SSRF surface).
 */
final class VaultTransitKmsProvider implements KmsProvider
{
    /** @param array<string,string> $env */
    public function __construct(private array $env) {}

    public function unwrap(string $ciphertext): string
    {
        $addr  = rtrim($this->env['VAULT_ADDR'] ?? '', '/');
        $token = $this->env['VAULT_TOKEN'] ?? '';
        $key   = $this->env['VAULT_TRANSIT_KEY'] ?? '';
        if ($addr === '' || $token === '' || $key === '') {
            throw new RuntimeException('vault KMS requires VAULT_ADDR, VAULT_TOKEN and VAULT_TRANSIT_KEY.');
        }

        $url  = $addr . '/v1/transit/decrypt/' . rawurlencode($key);
        $body = json_encode(['ciphertext' => $ciphertext], JSON_THROW_ON_ERROR);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Vault-Token: {$token}\r\n",
            'content'       => $body,
            'timeout'       => 5,
            'ignore_errors' => true, // read body even on 4xx/5xx so we can report it
        ], 'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]]);

        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new RuntimeException('vault transit decrypt: request failed (network/TLS).');
        }
        $status = self::statusFromHeaders($http_response_header ?? []);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("vault transit decrypt: HTTP {$status}.");
        }

        $data = json_decode($resp, true);
        $b64  = $data['data']['plaintext'] ?? null;
        if (!is_string($b64)) {
            throw new RuntimeException('vault transit decrypt: no plaintext in response.');
        }
        $pt = base64_decode($b64, true);
        if ($pt === false) {
            throw new RuntimeException('vault transit decrypt: invalid base64 plaintext.');
        }
        return $pt;
    }

    /** @param array<int,string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                return (int) $m[1]; // last status line wins (redirects)
            }
        }
        return 0;
    }
}

/**
 * Exec provider — run an operator-configured command that unwraps the key. The
 * ciphertext is written to the command's STDIN (never interpolated into the
 * command string, so the ciphertext can't inject), and the plaintext key is read
 * from STDOUT. KMS_DECRYPT_CMD is operator-trusted config, exactly like
 * DATABASE_URL or a secret-file path.
 *
 *   KMS_PROVIDER=exec
 *   KMS_DECRYPT_CMD="aws kms decrypt --ciphertext-blob fileb:///dev/stdin \
 *                    --query Plaintext --output text | base64 -d"
 */
final class ExecKmsProvider implements KmsProvider
{
    /** @param array<string,string> $env */
    public function __construct(private array $env) {}

    public function unwrap(string $ciphertext): string
    {
        $cmd = $this->env['KMS_DECRYPT_CMD'] ?? '';
        if ($cmd === '') {
            throw new RuntimeException('exec KMS requires KMS_DECRYPT_CMD.');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('exec KMS: could not start KMS_DECRYPT_CMD.');
        }

        fwrite($pipes[0], $ciphertext);
        fclose($pipes[0]);
        $out    = stream_get_contents($pipes[1]);
        $errOut = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            throw new RuntimeException('exec KMS: KMS_DECRYPT_CMD exited ' . $code . ' — ' . trim((string) $errOut));
        }
        // Strip a single trailing newline that CLIs commonly append.
        $pt = (string) $out;
        if (str_ends_with($pt, "\n")) {
            $pt = substr($pt, 0, -1);
        }
        if ($pt === '') {
            throw new RuntimeException('exec KMS: KMS_DECRYPT_CMD produced an empty key.');
        }
        return $pt;
    }
}
