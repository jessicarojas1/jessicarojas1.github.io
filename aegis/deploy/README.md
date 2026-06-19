# AEGIS — Hardened / Orchestrated Deployment

Artifacts for security-hardened, government (IL4+), and Kubernetes deployments.
The default `Dockerfile` + `render.yaml` remain the simple single-container path;
these add the controls required for FedRAMP/CMMC/STIG-aligned environments.

| Artifact | Purpose |
|----------|---------|
| `docker/Dockerfile.hardened` | Non-root apache on :8080, read-only-rootfs friendly, `install.php` removed |
| `deploy/k8s/aegis.yaml` | Pod Security **restricted** Deployment + Service + NetworkPolicy + PDB + HPA + Secret template |
| `database/roles.sql` | DML-only runtime DB role (least privilege) + optional audit WORM |
| `src/Secrets.php` | `*_FILE` secret mounts (Docker/K8s/Vault/KMS) — no secrets in env |

## Build & deploy (Kubernetes)

```bash
docker build -f aegis/docker/Dockerfile.hardened -t <registry>/aegis:<tag> aegis/
cosign sign --yes <registry>/aegis:<tag>     # SLSA: sign the image (see CI SBOM)

# Provide real secrets via sealed-secrets / SOPS / Vault CSI (never commit them),
# set REGISTRY/image + APP_URL in deploy/k8s/aegis.yaml, then:
kubectl apply -f aegis/deploy/k8s/aegis.yaml
```

The pods run **non-root**, **read-only root filesystem**, **all Linux
capabilities dropped**, `seccompProfile: RuntimeDefault`, with a **default-deny
NetworkPolicy** (egress only to DNS + Postgres) and resource limits. Secrets are
mounted as files and resolved by `Secrets::hydrate()`.

## Security controls summary

- **Container:** non-root (uid 33), `readOnlyRootFilesystem: true` + tmpfs for
  `/tmp` and apache run dir, `allowPrivilegeEscalation: false`, drop `ALL` caps.
- **Network:** default-deny ingress/egress; ingress only from the ingress
  controller to :8080; egress only to DNS + the database.
- **Secrets:** file-mounted (`*_FILE`), least-privilege DB role (`aegis_app`).
- **Supply chain:** CI generates a CycloneDX SBOM and runs a Trivy scan
  (`aegis-supply-chain` job); sign images with cosign before deploy.

## ⚠️ Before scaling past 1 replica — externalize sessions

AEGIS currently uses **PHP file-based sessions**, which are node-local. The
Deployment ships `replicas: 3` + an HPA, so you **must** either:

1. enable **sticky sessions** at the ingress (cookie affinity), **or**
2. move sessions to a shared store (Redis / database handler) — recommended.

Until then, set `replicas: 1` (and `minReplicas: 1` on the HPA) to avoid users
being bounced between pods. This is the top architectural item to resolve for
horizontal scale (tracked in the security review).

## FIPS 140-3 (FedRAMP / DoD)

Argon2id (libsodium) and the HMAC/AES-GCM used here are strong but **not** in a
FIPS-validated module by default. For FIPS environments:

- Build on a base image with the **OpenSSL 3 FIPS provider** enabled (e.g. a
  RHEL/UBI FIPS base) and run the host in FIPS mode.
- Prefer `openssl`-backed primitives where a validated module is required; keep
  key material in a **FIPS-validated KMS/HSM** and inject via `*_FILE` mounts.
- Document the validated module + boundary in your SSP.

## Multi-tenancy

AEGIS is **single-tenant per deployment** today. See `MULTI_TENANCY.md` for the
Row-Level-Security design and phased adoption plan before sharing one instance
across organizations.
