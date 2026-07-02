# Deployment — AI Tool Evaluation Framework (`aitool`)

Deployment guide for the static site. Pick a model, publish the files, set security +
cache headers, verify the client-side behaviors. There is **no build, no database
migration, and no background worker**.

## Contents

- [Deployment models](#deployment-models)
- [Prerequisites](#prerequisites)
- [Configuration & secrets](#configuration--secrets)
- [Database migrations](#database-migrations)
- [Worker / background process](#worker--background-process)
- [Ollama configuration](#ollama-configuration)
- [GPU acceleration](#gpu-acceleration)
- [Production checklist](#production-checklist)
- [Per-target guides](#per-target-guides)

## Deployment models

| Model | When | Guide |
|-------|------|-------|
| Managed PaaS (Render static site) | Fastest hosted path | `../render.yaml` |
| Single Linux server (nginx/Apache) | Self-managed VM, full header control | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static) | Existing cluster, HA | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| Azure (Static Web Apps / Blob `$web` + Front Door) | Azure Commercial/Gov | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| AWS (S3 + CloudFront OAC) | AWS Commercial/GovCloud | [`../deployments/AWS.md`](../deployments/AWS.md) |
| Air-gapped (vendored assets, internal nginx) | No internet | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |
| Local development | Laptop | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |

## Prerequisites

- The static files: this `aitool/` directory **plus** the shared parent-repo assets it
  references (`../theme.css`, `../isms/isms.css`, `../script.js`, `../roles.js`,
  `../users.js`, `../favicon.ico`). Deploy the whole repo or vendor these in.
- Runtime browser access to `cdn.jsdelivr.net` for Bootstrap — unless vendored
  (air-gapped).
- For cloud/CDN targets: a **CI identity** (OIDC role / managed identity) able to
  publish objects and invalidate caches. **No static keys, no app secrets.**

## Configuration & secrets

**There are no application secrets** — no API keys, no DB URL, no session key. The site
consumes **no environment variables at runtime**. The only "config" is:

- Content (the HTML), changed by editing files.
- Hosting-layer config: TLS certs, cache-control, and security/CSP headers (set per
  target — see the guides, `nginx.conf`, and `render.yaml`).
- Deploy-pipeline identity (OIDC role / managed identity) — the only credential
  involved, and it lives in your CI provider, never in the repo.

## Database migrations

**Not applicable.** There is no database. All state is browser `localStorage`
(`bsTheme`, `aitool.branding.v1`, vendor-tracker store) and is created lazily on first
use. No migration commands exist or are needed.

## Worker / background process

**Not applicable.** There is no cron, queue, or background worker. Nothing runs
server-side. Interactive behavior (checklist scoring, tracker, branding) executes in the
browser on demand.

## Ollama configuration

**Not applicable.** This framework has **no AI/LLM feature at runtime** — it is
documentation and evaluation tooling *about* adopting AI tools, not an AI application. It
makes no calls to any hosted or self-hosted model. Ollama is therefore unused. (Kept
here for doc-set uniformity.)

## GPU acceleration

**Not applicable.** No inference, no rendering pipeline, no compute workload. CPU-only
static file serving; no GPU is involved at build or run time.

## Production checklist

### Secrets & identity
- [ ] Deploy via CI **OIDC role / managed identity** (no long-lived keys in CI or repo).
- [ ] Least-privilege deploy policy (write to the bucket/site + invalidate CDN only).
- [ ] Confirm there are no app secrets committed (there are none — verify anyway).

### Transport & exposure
- [ ] HTTPS enforced; HTTP → HTTPS redirect.
- [ ] TLS 1.2+ (prefer 1.3); modern cipher suite; HSTS enabled.
- [ ] Valid certificate (ACM / Key Vault / Let's Encrypt / managed).

### Hardening
- [ ] Security headers set: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
      `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `Referrer-Policy`.
- [ ] CSP scoped to `'self'` + `cdn.jsdelivr.net`; `img-src 'self' data:`.
      (⚠️ `script-src` currently needs `'unsafe-inline'` — see `../OPEN_ITEMS.md`.)
- [ ] SRI hashes present on Bootstrap CSS/JS (already in the HTML — keep in sync).
- [ ] Directory listing disabled; only intended file types served.
- [ ] Optional: identity-aware proxy in front if the content must be access-gated.

### Resilience & operations
- [ ] Cache-Control: `no-cache` on `*.html`, long max-age on static assets.
- [ ] CDN in front for availability + global latency.
- [ ] Post-deploy verification runs (below) — automate as a CI gate.
- [ ] Git is the source of truth; rollback = redeploy a previous commit.

## Verification (all targets)

There is **no login, database, or upload to verify** — state that and verify the real
client-side behavior instead:

```bash
BASE=https://your-host.example
# 1. Entry page returns 200
curl -I "$BASE/aitool/index.html"        # expect: HTTP/2 200

# 2. Security headers present
curl -sI "$BASE/aitool/index.html" | grep -iE 'content-security-policy|x-content-type-options|strict-transport-security'

# 3. Local asset resolves
curl -I "$BASE/aitool/branding.js"       # expect: 200, content-type ...javascript

# 4. Shared parent asset resolves (whole-repo deploy)
curl -I "$BASE/theme.css"                # expect: 200 (or vendored path)
```

In a browser, confirm: page renders styled; **theme toggle** flips dark/light and
persists across reload (`localStorage: bsTheme`); **Settings ⚙️** opens, saving a name/
accent updates the brand and persists (`aitool.branding.v1`); the **tracker** adds a
card and JSON export downloads; no CSP violations in the console.
