<?php
declare(strict_types=1);

/**
 * Secrets — load sensitive values from mounted files (the "*_FILE" convention).
 *
 * For any supported variable X, if `X_FILE` points to a readable file, its
 * trimmed contents become X. This is the standard pattern for injecting secrets
 * from Docker/Compose secrets, Kubernetes Secrets (CSI/projected volumes),
 * HashiCorp Vault Agent, and cloud KMS sidecars — WITHOUT placing the secret in
 * the process environment (where it leaks via /proc, crash dumps, child procs,
 * and accidental logging). NIST SP 800-53 SC-12/SC-28, CMMC SC.L2-3.13.10.
 *
 * Wire `Secrets::hydrate()` early in bootstrap, before anything reads the keys.
 * No Composer — pure PHP.
 */
final class Secrets
{
    /** Variables that may be provided via a `*_FILE` mount. */
    private const FILE_BACKED = [
        'JWT_SECRET',
        'AUDIT_HMAC_KEY',
        'APP_ENCRYPTION_KEY',
        'DB_PASS',
        'DB_PASSWORD',
        'SMTP_PASS',
    ];

    /**
     * Resolve `*_FILE` indirection over an environment map. Pure — the file
     * reader is injected so this is unit-testable without touching disk.
     *
     * @param array<string,string>      $env     current environment (e.g. $_ENV)
     * @param callable(string):?string  $reader  path => trimmed contents|null
     * @return array<string,string>     env with file-backed values resolved
     */
    public static function resolve(array $env, callable $reader): array
    {
        foreach (self::FILE_BACKED as $key) {
            $fileKey = $key . '_FILE';
            $path = $env[$fileKey] ?? '';
            if ($path === '') {
                continue;
            }
            // An explicit direct value always wins over the file (predictable
            // precedence; lets an operator override a mount in an emergency).
            if (!empty($env[$key])) {
                continue;
            }
            $val = $reader($path);
            if ($val !== null && $val !== '') {
                $env[$key] = $val;
            }
        }
        return $env;
    }

    /** Apply `*_FILE` resolution to the live $_ENV (called once at bootstrap). */
    public static function hydrate(): void
    {
        $_ENV = self::resolve($_ENV, static function (string $path): ?string {
            if (!is_file($path) || !is_readable($path)) {
                error_log('[AEGIS] secret file not readable: ' . $path);
                return null;
            }
            $contents = @file_get_contents($path);
            return $contents === false ? null : trim($contents);
        });
    }
}
