# AEGIS — KMS Envelope Encryption (key management)

AEGIS encrypts sensitive settings at rest (SMTP/S3/AI credentials) with
`APP_ENCRYPTION_KEY`. For higher assurance — **NIST SP 800-53 SC-12 / SC-28**,
FedRAMP / DoD IL4+ — that data key should not live in plaintext in config. With
**envelope encryption** the key-encryption key (KEK) stays inside a KMS/HSM and
AEGIS stores only the **wrapped** data key, unwrapping it into
`APP_ENCRYPTION_KEY` **in-process at boot**. The plaintext key never touches disk
or the persisted settings.

## How it works

1. `Secrets::hydrate()` resolves any `*_FILE` secret mounts first.
2. `Kms::hydrate()` then runs. If `KMS_PROVIDER` is set and
   `APP_ENCRYPTION_KEY_CIPHERTEXT` is present (and no explicit
   `APP_ENCRYPTION_KEY` is set), it asks the provider to unwrap the ciphertext and
   sets the plaintext `APP_ENCRYPTION_KEY` for this process only.
3. `Security::encryptSetting()` / `decryptSetting()` use that key as before.

**Inert by default:** with `KMS_PROVIDER` unset (or `none`), nothing changes —
`APP_ENCRYPTION_KEY` is used exactly as provided. An explicit plaintext
`APP_ENCRYPTION_KEY` always wins over the ciphertext (emergency override).

If a provider is configured but unwrapping fails, boot stops with an
operator-safe configuration error (never a plaintext-key fallback).

## Providers

### `vault` — HashiCorp Vault transit
Unwraps a `vault:v1:…` ciphertext via the transit `decrypt` endpoint over HTTPS
(TLS verified; no curl extension needed).

```
KMS_PROVIDER=vault
VAULT_ADDR=https://vault.internal:8200
VAULT_TOKEN_FILE=/run/secrets/vault_token     # or VAULT_TOKEN=...
VAULT_TRANSIT_KEY=aegis-data-key
APP_ENCRYPTION_KEY_CIPHERTEXT=vault:v1:Base64Ciphertext==
```

Wrap a freshly generated 32-byte data key:
```bash
KEY=$(php -r 'echo base64_encode(random_bytes(32));')
vault write -field=ciphertext transit/encrypt/aegis-data-key plaintext="$KEY"
# → store the vault:v1:… string as APP_ENCRYPTION_KEY_CIPHERTEXT
```

### `exec` — universal (AWS KMS / GCP KMS / Azure Key Vault)
Runs an operator-configured command that reads the ciphertext on **stdin** and
writes the plaintext key to **stdout**. The ciphertext is piped via stdin and is
never interpolated into the command, so it cannot inject; `KMS_DECRYPT_CMD` is
operator-trusted config (like `DATABASE_URL`).

AWS KMS example:
```
KMS_PROVIDER=exec
KMS_DECRYPT_CMD=aws kms decrypt --ciphertext-blob fileb:///dev/stdin --query Plaintext --output text | base64 -d
APP_ENCRYPTION_KEY_CIPHERTEXT=<base64 KMS ciphertext blob>
```
Wrap the data key once:
```bash
aws kms encrypt --key-id alias/aegis --plaintext fileb:///dev/stdin \
  --query CiphertextBlob --output text < your.key   # store as the ciphertext
```

GCP KMS (`gcloud kms decrypt …`) and Azure (`az keyvault key decrypt …`) follow
the same stdin→stdout shape.

## Operational notes

- Prefer delivering `VAULT_TOKEN` / `APP_ENCRYPTION_KEY_CIPHERTEXT` via `*_FILE`
  mounts (Docker/K8s secrets) — both are wired into the `*_FILE` convention.
- **Rotation:** re-wrap a new data key, deploy the new ciphertext, and re-encrypt
  settings. Old ciphertexts still decrypt via the legacy `JWT_SECRET`-derived key
  during migration (see `Security::decryptSetting`).
- The envelope logic and provider selection are unit-tested (`tests/test_kms.php`);
  the `exec` path is exercised end-to-end with a local identity command.
