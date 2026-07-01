# Deployment — Compliance Copilot

Operator guide for deploying and configuring Compliance Copilot (Next.js 16 App
Router + Supabase). For per-target runbooks see [`../deployments/`](../deployments/).

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Database migrations](#4-database-migrations)
5. [Storage bucket](#5-storage-bucket)
6. [Worker / background process](#6-worker--background-process)
7. [Ollama configuration (self-hosted / air-gapped AI)](#7-ollama-configuration-self-hosted--air-gapped-ai)
8. [GPU acceleration](#8-gpu-acceleration)
9. [Production checklist](#9-production-checklist)

---

## 1. Deployment models

| Model | How | Guide |
|---|---|---|
| Managed PaaS (Vercel) | Import repo, root `compliance-copilot`, set env, deploy | [LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) |
| Managed PaaS (Render) | `render.yaml` blueprint (Node web service) | — |
| Single Linux server | Docker image or `next start` behind nginx/TLS | [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) |
| Container | Multi-stage `Dockerfile` (standalone output) | — |
| Kubernetes | Deployment + Service + Ingress, secrets via CSI/ExternalSecrets | [KUBERNETES](../deployments/KUBERNETES.md) |
| Azure | App Service / AKS + Azure DB for Postgres (or Supabase) | [AZURE](../deployments/AZURE.md) |
| AWS (Commercial + GovCloud) | ECS/Fargate or EKS + RDS/S3 or Supabase | [AWS](../deployments/AWS.md) |
| Air-gapped | Bundled image, self-hosted Supabase, Ollama for AI | [AIRGAPPED](../deployments/AIRGAPPED.md) |

The app is a single stateless Node process; all state lives in Supabase.

---

## 2. Prerequisites

- **Node 20+** and **npm** (build/run). React 19 / Next 16.
- **Supabase project** (cloud) or a self-hosted Supabase stack (Postgres + Storage
  + GoTrue) for air-gapped.
- **Anthropic API key** for live AI (optional — omit for demo output), or Ollama
  for self-hosted AI.
- **Docker** (for the container image) and/or **kubectl/Helm** (k8s).
- TLS termination in front of the app in any shared/production deployment.

---

## 3. Configuration & secrets

Set via `.env.local` (dev) or platform secrets (prod). Full reference in
[ARCHITECTURE.md §5](./ARCHITECTURE.md#5-configuration-model) and
[`.env.local.example`](../.env.local.example).

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://xyz.supabase.co` | Supabase URL (public, inlined at build) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJ...` | Anon key (RLS-scoped, browser-safe) |
| `SUPABASE_SERVICE_ROLE_KEY` | `eyJ...` | **Secret.** Server-only writes; bypasses RLS |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | **Secret.** AI relay upstream key (Anthropic provider) |
| `AI_PROVIDER` | `anthropic` | AI upstream: `anthropic` (default) or `ollama` (self-hosted) |
| `AI_MODEL` | `claude-opus-4-6` | Anthropic model id (configurable; default kept) |
| `OLLAMA_BASE_URL` | `http://127.0.0.1:11434` | Self-hosted Ollama endpoint (when `AI_PROVIDER=ollama`) |
| `OLLAMA_MODEL` | `llama3.1` | Self-hosted model (when `AI_PROVIDER=ollama`) |
| `AI_PROXY_TOKEN` | `<random>` | Bearer for programmatic AI callers; required in prod w/o session |
| `APP_SESSION_SECRET` | `openssl rand -base64 48` | **Secret.** Session cookie HMAC key (≥16 chars) |
| `APP_AUTH_USERNAME` | `isso` | Login username |
| `APP_AUTH_PASSWORD` | `<strong>` | **Secret.** Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | **Private** Storage bucket for evidence (signed URLs only) |
| `BRANDING_ADMIN_TOKEN` | `<random>` | Optional. Gates the shared branding write |
| `LOG_LEVEL` | `info` | Structured-log verbosity (`debug`\|`info`\|`warn`\|`error`) |

**Never commit `.env` / `.env.local`** — only `.env.local.example` with
placeholders. Prefer a secret manager (Vault, AWS Secrets Manager, Azure Key
Vault, k8s Secret via CSI/ExternalSecrets) and workload identity over static keys.

> NEXT_PUBLIC_* are compiled into the browser bundle. Provide them at **build**
> time (Docker build args / Vercel/Render build env). Server-only secrets are
> read at **runtime** and must not be prefixed `NEXT_PUBLIC_`.

---

## 4. Database migrations

State lives in Supabase Postgres. The full schema (tables, RLS policies, triggers)
is `supabase/schema.sql` — **idempotent** (uses `create table if not exists`,
`drop policy if exists` before create, `drop trigger if exists`).

**Apply via the Supabase SQL Editor:** paste the contents of `supabase/schema.sql`
and run.

**Apply via psql:**

```bash
# Connection string from Supabase → Project Settings → Database.
psql "postgresql://postgres:<password>@db.<ref>.supabase.co:5432/postgres" \
  -f supabase/schema.sql
```

**Apply via the Supabase CLI (if you manage migrations there):**

```bash
supabase db push                 # apply local migrations to the linked project
# or run the raw file:
supabase db execute --file supabase/schema.sql
```

Tables created: `controls`, `evidence`, `poam_items`, `app_settings`. RLS is
enabled on all four; only `SELECT` is granted to the `authenticated` role. Writes
go through server route handlers using the service-role key.

> The UI currently runs on seeded data (`lib/data.ts`) for controls/evidence.
> `app_settings` (branding) is the one table actively read/written at runtime.
> Wiring the pages to live DB reads is an [open item](../OPEN_ITEMS.md).

---

## 5. Storage bucket

Create a Storage bucket for evidence files (default name `evidence-files`, override
via `NEXT_PUBLIC_EVIDENCE_BUCKET`). **Create it PRIVATE** (uncheck "Public bucket").
In Supabase: Storage → New bucket → `evidence-files` → keep private.

Evidence is uploaded server-side by `POST /api/evidence/upload` using the
service-role key (extension + MIME allowlist, 25 MB cap, randomized stored object
name), which writes an `evidence` metadata row and returns a short-lived **signed
URL** (1 h). The bucket is never public — objects are read back only through signed
URLs, so a leaked object path is not directly retrievable.

---

## 6. Worker / background process

**None.** There is no queue, cron, or background worker. All logic runs inside the
Next.js request lifecycle (route handlers + Edge middleware). Scheduled features on
the roadmap (evidence-expiry notifications) would introduce a scheduler — none is
deployed today, so no separate process, sidecar, or cron entry is required.

---

## 7. Ollama configuration (self-hosted / air-gapped AI)

For air-gapped or no-hosted-API deployments, the relay in
`app/api/ai/generate/route.ts` supports a self-hosted **Ollama** backend directly —
set `AI_PROVIDER=ollama` and the relay posts to the Ollama chat API
(`${OLLAMA_BASE_URL}/api/chat`, `stream:false`) instead of Anthropic. No code change
is required; no traffic leaves the enclave.

```bash
# Install + run Ollama on the app host or an adjacent GPU node
ollama pull llama3.1          # or a larger model if GPU allows
ollama serve                  # serves http://127.0.0.1:11434
```

Env for the self-hosted path:

| Variable | Example | Purpose |
|---|---|---|
| `AI_PROVIDER` | `ollama` | Select the Ollama backend (`anthropic` is the default) |
| `OLLAMA_BASE_URL` | `http://127.0.0.1:11434` | Local Ollama endpoint |
| `OLLAMA_MODEL` | `llama3.1` | Model to use for narratives/gaps/POA&M |

The same fail-closed auth, per-identity rate limits, and prompt/output caps apply to
the Ollama path (the server-fixed output ceiling maps to Ollama's `num_predict`). See
[AIRGAPPED.md](../deployments/AIRGAPPED.md).

---

## 8. GPU acceleration

Only relevant when running Ollama (the hosted Anthropic path needs no local GPU).

- **When:** models larger than ~8B, or high throughput of AI narrative/gap requests.
- **NVIDIA/CUDA:** install the driver + CUDA runtime; Ollama auto-detects the GPU.
  Verify with `ollama ps` (shows GPU/CPU placement) and `nvidia-smi`.
- **Kubernetes:** install the NVIDIA device plugin, request
  `resources.limits.nvidia.com/gpu: 1` on the Ollama pod (the Next.js app pod
  needs no GPU), and schedule it to a GPU node pool.
- **Degrade to CPU:** Ollama runs CPU-only if no GPU is present — slower but
  functional; size the model to fit RAM.

---

## 9. Production checklist

### Secrets & identity
- [ ] `APP_SESSION_SECRET` set to a long random value (≥16 chars); `APP_AUTH_USERNAME`/`APP_AUTH_PASSWORD` set (login gate enabled).
- [ ] `SUPABASE_SERVICE_ROLE_KEY`, `ANTHROPIC_API_KEY`, `AI_PROXY_TOKEN`, `BRANDING_ADMIN_TOKEN` stored in a secret manager, never in the image or repo.
- [ ] `AI_PROXY_TOKEN` set (so programmatic callers work and the relay is not left open); confirm the relay returns 503, not open access, when misconfigured.
- [ ] NEXT_PUBLIC_* provided at build; no server secret is `NEXT_PUBLIC_`-prefixed.

### Transport & exposure
- [ ] TLS enforced end-to-end (platform or nginx/ingress); HSTS on.
- [ ] Session cookie `Secure` (automatic when `NODE_ENV=production`), HttpOnly, `SameSite=Strict`.
- [ ] Only ports 443 (and 80→443 redirect) exposed; app port 3000 not public.
- [ ] Supabase bucket private; evidence served via signed URLs.

### Hardening
- [ ] `supabase/schema.sql` applied; RLS enabled on all tables; no write policies granted to `authenticated`.
- [ ] Container runs as non-root (`nextjs` uid 1001 — already in the Dockerfile).
- [ ] `BRANDING_ADMIN_TOKEN` set so branding cannot be changed anonymously (defacement).
- [ ] Rate limits backed by a shared store (Redis) and/or a WAF for multi-instance deployments.

### Resilience & operations
- [ ] Health check wired (`/api/health`) in the platform/orchestrator.
- [ ] Supabase PITR / backups enabled (see [DISASTER_RECOVERY.md](./DISASTER_RECOVERY.md)).
- [ ] Logs shipped off-host; log retention meets CUI requirements.
- [ ] Restore drill scheduled; RPO/RTO documented and tested.
- [ ] Autoscaling / replicas configured for the stateless app tier.
