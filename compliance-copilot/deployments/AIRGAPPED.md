# Air-Gapped — Compliance Copilot

Operator guide for deploying **Compliance Copilot** into a fully **air-gapped / offline**
enclave (no internet egress). Compliance Copilot is a **Next.js 14/16** app backed by
**Supabase**; in an air-gap there is **no hosted Supabase and no hosted Anthropic API**, so both
are replaced with **self-hosted** components: **self-hosted Supabase** for data/storage/auth and
**Ollama** for the AI Copilot.

> **CUI note:** This is the recommended posture for CUI at the highest sensitivity — nothing
> leaves the enclave. All state (Postgres, object storage, secrets) and all AI inference stay
> inside the boundary. Never configure `ANTHROPIC_API_KEY` in an air-gap; keep it unset so the
> relay never attempts egress.

> Cross-links: [KUBERNETES.md](./KUBERNETES.md) · [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md)
> · [AWS.md](./AWS.md) · [AZURE.md](./AZURE.md)

---

## 1. Deployment architecture

Everything runs inside the enclave. A private container registry mirror holds all images. The
app container talks only to in-enclave **self-hosted Supabase** and (optionally) an in-enclave
**Ollama** server for AI drafting. No component reaches the public internet.

| Component | Air-gap form |
|---|---|
| Web app | Container from the private registry (`next start` :3000) on the single server, docker-compose, or in-enclave K8s |
| Data/Storage/Auth | Self-hosted Supabase (Postgres + Storage + GoTrue) inside the enclave |
| AI Copilot | **Ollama** server (e.g. `llama3.1`/`mistral`) reachable in-enclave; **not** api.anthropic.com |
| Secrets | Offline secret store (Vault in-enclave) or `chmod 600` env files |
| Updates | Manual transfer bundles (images + npm cache + CVE feeds) via approved media |

> **AI relay + Ollama:** the app's `/api/ai/generate` route is hard-coded to call
> `api.anthropic.com` with the Anthropic message schema. In an air-gap that host is unreachable,
> so the AI Copilot has two supported modes: **(a) demo mode** — leave `ANTHROPIC_API_KEY` unset
> and the panel returns realistic demo output with no egress (fully functional app minus live
> AI); or **(b) Ollama mode** — front the app with an in-enclave reverse proxy / DNS override
> that maps `api.anthropic.com` to an **Anthropic-compatible shim in front of Ollama**, or
> patch the relay's upstream URL to the Ollama endpoint. Either way, inference stays local.

---

## 2. Topology

```
   ┌──────────────────────── Air-gapped enclave (no internet) ───────────────────────┐
   │                                                                                  │
   │  Client ──443──► Ingress/nginx ──► Compliance Copilot container (:3000)          │
   │                                          │                                       │
   │            ┌─────────────────────────────┼──────────────────────────┐           │
   │            ▼                             ▼                           ▼            │
   │   Self-hosted Supabase           Ollama server                Offline Vault      │
   │   Postgres + Storage + Auth      (llama3.1 / mistral)          (secrets)          │
   │                                                                                  │
   │   Private registry mirror  ◄── manual bundle transfer ──►  update workstation    │
   └──────────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Prerequisites

| Item | Detail |
|---|---|
| Private registry | Harbor / Nexus / registry:2 mirror inside the enclave |
| Container runtime | Docker/containerd or in-enclave Kubernetes |
| Self-hosted Supabase | Postgres 15+, Storage, GoTrue (from the Supabase self-host bundle) |
| Ollama | `ollama` server + at least one pulled model (GPU optional) |
| Node build cache | offline npm cache / vendored `node_modules` if building in-enclave |
| Offline feeds | CVE/vuln DB mirror, OS package mirror for patching |
| Approved media | to move transfer bundles across the air-gap |

---

## 4. Identity & credentials

No cloud IAM. Use an in-enclave secret store (Vault) or root-owned `chmod 600` env files.

- Run the container as **non-root**.
- The Supabase **service role key** and any AI shim token stay in the secret store; only the app
  process reads them.
- Set `AI_PROXY_TOKEN`, `APP_SESSION_SECRET`, `APP_AUTH_*`, and `BRANDING_ADMIN_TOKEN` — the
  login gate and relay auth matter even offline (insider threat).
- Generate secrets on the enclave, not outside it:

```bash
openssl rand -base64 48   # APP_SESSION_SECRET
openssl rand -hex 32      # AI_PROXY_TOKEN / BRANDING_ADMIN_TOKEN
```

---

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://supabase.enclave.local` | In-enclave self-hosted Supabase URL |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | Self-hosted anon key |
| `SUPABASE_SERVICE_ROLE_KEY` | *(offline Vault)* | Self-hosted service role key (server-only) |
| `ANTHROPIC_API_KEY` | *(unset)* | Leave unset — no hosted AI egress; demo/Ollama used instead |
| `AI_PROXY_TOKEN` | *(offline Vault)* | Gate `/api/ai/generate` for programmatic callers |
| `APP_SESSION_SECRET` | *(offline Vault)* | HMAC signs `cc_session` (≥16 chars) |
| `APP_AUTH_USERNAME` | `issoadmin` | Login username |
| `APP_AUTH_PASSWORD` | *(offline Vault)* | Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Storage bucket in self-hosted Supabase |
| `BRANDING_ADMIN_TOKEN` | *(offline Vault)* | Gates branding write |
| `NODE_ENV` | `production` | Secure cookies + fail-closed relay |
| `PORT` | `3000` | Listen port |
| `HOSTNAME` | `0.0.0.0` | Bind all interfaces |
| `OLLAMA_HOST` (shim) | `http://ollama.enclave.local:11434` | Ollama endpoint for the AI shim (if using Ollama mode) |

> In **demo mode** (no live AI), leave `ANTHROPIC_API_KEY` unset; the app is fully usable and
> the AI panel returns realistic canned narratives/gaps/POA&M. In **Ollama mode**, route the
> relay upstream to the Ollama endpoint via an Anthropic-compatible shim or a patched upstream
> URL, and keep `ANTHROPIC_API_KEY` unset.

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| Registry mirror | `registry.enclave.local` | Source for the app + Supabase + Ollama images |
| `next.config.js` `remotePatterns` | `supabase.enclave.local` | Allow images from the in-enclave Supabase host (patch if not `*.supabase.co`) |
| Ollama model | `llama3.1:8b` / `mistral` | Local inference model for the AI Copilot |
| GPU | optional NVIDIA runtime | Accelerates Ollama; degrades to CPU if absent |
| Health path | `/` | Homepage health surface |

---

## 7. Verification

Transfer + load images from the offline bundle, then start the stack:

```bash
# On the update workstation (online): pull + save
docker pull <registry>/compliance-copilot:<tag>
docker save compliance-copilot:<tag> supabase/postgres:15 ... ollama/ollama:latest -o cc-bundle.tar

# In the enclave: load + push to the mirror
docker load -i cc-bundle.tar
docker tag compliance-copilot:<tag> registry.enclave.local/compliance-copilot:<tag>
docker push registry.enclave.local/compliance-copilot:<tag>

# Prime Ollama model from an offline model bundle
ollama create llama3.1 -f ./Modelfile   # or load a pre-pulled blob

# Apply DB schema to self-hosted Supabase Postgres
psql "$SUPABASE_DB_URL" -f supabase/schema.sql
# Create bucket 'evidence-files' in self-hosted Supabase Studio
```

Checks (all internal hostnames):

```bash
APP=https://grc.enclave.local

# Health / homepage
curl -sI $APP/ | head -1                                        # 200

# Login works (secrets resolved from offline Vault)
curl -s -X POST $APP/api/auth/login -H 'Content-Type: application/json' \
  -d '{"username":"issoadmin","password":"<pw>"}'               # {"ok":true}

# AI relay (Ollama mode) or demo output
curl -s -X POST $APP/api/ai/generate -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $AI_PROXY_TOKEN" \
  -d '{"prompt":"Identify gaps for 3.4.2"}'                     # {"text":"..."}

# DB row + storage object after Evidence upload
psql "$SUPABASE_DB_URL" -c "select count(*) from controls;"
psql "$SUPABASE_DB_URL" -c "select file_name,file_url from evidence order by created_at desc limit 1;"

# Confirm no egress attempted
curl -sI $APP/ | head -1 && echo "no api.anthropic.com traffic expected"
```

---

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade app | Build/pull new image online, transfer via approved media, load + push to mirror, redeploy |
| DB migration | Re-run `supabase/schema.sql` (idempotent) against enclave Postgres |
| Update Ollama model | Bundle new model blob, transfer, `ollama create`/load in-enclave |
| Patch OS/CVE | Sync offline package + CVE feed mirrors from approved bundles |
| Backups | `pg_dump` of Postgres + snapshot of object storage + secret store export, to enclave-internal encrypted storage |
| Secret rotation | Update offline Vault / env file, restart app (rotating `APP_SESSION_SECRET` logs users out) |
| Logs | Local log aggregation only; no external SIEM egress |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| App can't reach Supabase | Wrong internal DNS / self-host down | Verify `NEXT_PUBLIC_SUPABASE_URL` resolves in-enclave; check Supabase containers |
| AI panel returns demo only | Demo mode (no Ollama shim) | Expected; enable Ollama mode via the shim if live AI required |
| Relay tries to egress | `ANTHROPIC_API_KEY` set | Unset it; use demo or Ollama mode |
| Ollama 500 / slow | No model loaded / no GPU | Load a model; add NVIDIA runtime or accept CPU latency |
| Image pull fails | Not mirrored | Push image to `registry.enclave.local` first |
| Supabase images blocked | `remotePatterns` mismatch | Patch `next.config.js` to allow the in-enclave host |
| Evidence upload fails | Bucket missing | Create `evidence-files` in self-hosted Supabase |
