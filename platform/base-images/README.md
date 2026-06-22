# Platform hardened base images

Standard, security-reviewed base container images that every app in this repo
should build **FROM**. They centralize the container hardening that the apps
currently each re-implement by hand, so individual app Dockerfiles shrink to
"FROM the base + COPY the app + USER" and stay consistent.

This directory is the concrete first artifact of the **"shared hardened base
image"** recommendation in the security review. The model / prior art:

- [`citadel/server/Dockerfile`](../../citadel/server/Dockerfile) — non-root
  (UID 10001), read-only-rootfs friendly, digest-pinned upstream.
- [`aegis/docker/Dockerfile.hardened`](../../aegis/docker/Dockerfile.hardened) —
  non-root Apache on port 8080, logs to stdout/stderr, PidFile/locks in `/tmp`.

## Images

| File | Base for | Highlights |
|------|----------|-----------|
| `Dockerfile.php-apache` | PHP-Apache apps (aegis, apex, paladin) | Apache on **:8080**, runs as **www-data** (no root PID 1), prod php.ini + OPcache, logs to stdout/stderr, SUID bits stripped |
| `Dockerfile.node` | Node services (citadel-server style) | Non-root **UID 10001**, `NODE_ENV=production`, curl for healthchecks, SUID bits stripped |

## What "hardened" means here

Every image in this directory enforces the same baseline:

1. **Digest-pinned upstream** — `FROM ...@sha256:<digest>  # human-tag`. The
   immutable digest guarantees the exact bytes; the trailing comment keeps the
   human-readable tag visible. Re-pin on patch cycles (commands are inline in
   each Dockerfile).
2. **Non-root by default** — a `USER` directive so PID 1 is unprivileged.
   Satisfies Kubernetes `runAsNonRoot: true` and lets you `drop: [ALL]` caps.
3. **Unprivileged port** — services listen on **8080**, never on a privileged
   (<1024) port, so no `CAP_NET_BIND_SERVICE` is required.
4. **`no-new-privileges` / SUID-safe** — setuid/setgid bits are stripped from
   the filesystem, closing a common privilege-escalation path. Pair with
   `security_opt: ["no-new-privileges:true"]`.
5. **Read-only-rootfs friendly** — logs go to stdout/stderr and the only
   writable path needed is `/tmp` (mount it as `tmpfs`). Mount app data dirs
   (`uploads/`, `logs/`) as named volumes/tmpfs.

## How an app adopts a base

These bases are referenced **by path** today (build them as named local images,
or push them to your registry and reference by name). Example adoption for a
PHP-Apache app:

```dockerfile
# 1. Build the base once (or pull it from your registry):
#    docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:1 .
#
# 2. App Dockerfile becomes:
FROM platform/php-apache:1
COPY --chown=www-data:www-data . /var/www/html
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -fsS http://localhost:8080/healthz || exit 1
# USER www-data and EXPOSE 8080 are already set by the base.
```

Recommended runtime hardening (compose / Kubernetes) to go with these images:

```yaml
# docker-compose
security_opt:
  - no-new-privileges:true
cap_drop:
  - ALL
read_only: true
tmpfs:
  - /tmp
# plus named volumes for any app-writable dirs (uploads, logs)
ports:
  - "8080:8080"   # container now listens on 8080, not 80
```

```yaml
# Kubernetes securityContext
securityContext:
  runAsNonRoot: true
  runAsUser: 10001          # node base; www-data (33) for the php-apache base
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true
  capabilities:
    drop: ["ALL"]
```

## Maintenance

- **Re-pin digests** whenever you patch the base OS / runtime. Each Dockerfile
  contains the exact registry-API command to fetch the current digest for its
  tag. Bump the `@sha256:` value and the `# human-tag` comment together.
- Keep these in lockstep with the app Dockerfiles that already implement the
  pattern (`aegis/docker/Dockerfile.hardened`, `citadel/server/Dockerfile`) so
  there is a single source of truth for the hardening baseline.
