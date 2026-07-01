# Local Development — Compliance Copilot

Operator guide for running **Compliance Copilot** (CMMC Level 2 & NIST SP 800-171 readiness
platform) on a laptop/dev workstation. Compliance Copilot is a **Next.js 14/16 (App Router)**
app styled with **Tailwind CSS**, backed by **Supabase** (PostgreSQL + Storage + Auth) and an
optional **Anthropic Claude** relay for the AI Copilot.

> Cross-links: [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md) ·
> [KUBERNETES.md](./KUBERNETES.md) · [AZURE.md](./AZURE.md) · [AWS.md](./AWS.md) ·
> [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

For local dev there is a single Node process running the Next.js dev server (`next dev`),
talking to a **Supabase project** for data/storage/auth and (optionally) to the hosted
**Anthropic API** for AI drafting. Supabase can be the hosted SaaS project or a local
self-hosted stack via the Supabase CLI (`supabase start`, Docker-based).

| Component | Local form | Notes |
|---|---|---|
| Web app | `next dev` on `http://localhost:3000` | Hot reload; App Router routes under `app/` |
| Database + Storage + Auth | Supabase (hosted project **or** `supabase start`) | Tables from `supabase/schema.sql`; bucket `evidence-files` |
| AI relay | `POST /api/ai/generate` → `api.anthropic.com` | Optional; without `ANTHROPIC_API_KEY` the AI panel returns demo output |
| Session login | HMAC cookie (`cc_session`) | Optional locally; enabled only when `APP_SESSION_SECRET` + `APP_AUTH_USERNAME` + `APP_AUTH_PASSWORD` are set |

---

## 2. Topology

```
┌────────────────────────── Developer laptop ──────────────────────────┐
│                                                                       │
│   Browser  ──http──►  next dev (Node 20+, :3000)                      │
│                          │                                            │
│          ┌───────────────┼──────────────────────────┐                │
│          │               │                          │                 │
│          ▼               ▼                          ▼                 │
│   Supabase Auth    Supabase Postgres         POST /api/ai/generate    │
│   (login)          + Storage(evidence-files)  (server-side)           │
│                                                     │                 │
└─────────────────────────────────────────────────────┼────────────────┘
                                                       ▼
                                        api.anthropic.com  (optional)
   Supabase = hosted SaaS project  OR  local `supabase start` (Docker)
```

Data flow: the browser hits Next.js pages; server API routes (`/api/*`) hold secrets
(`SUPABASE_SERVICE_ROLE_KEY`, `ANTHROPIC_API_KEY`, `AI_PROXY_TOKEN`) and are the real
security boundary. The anon key is the only Supabase credential exposed to the browser.

---

## 3. Prerequisites

| Tool | Version | Purpose |
|---|---|---|
| Node.js | 20 LTS or newer (repo tested on 22) | Runtime for Next.js |
| npm | 10+ (bundled with Node 20) | Dependency install / scripts |
| Git | any | Clone repo |
| Supabase account **or** Supabase CLI | latest | Hosted project or `supabase start` (needs Docker) |
| Docker Desktop | latest (only if self-hosting Supabase locally) | Runs the local Supabase stack |
| Anthropic API key | optional | Enables real AI Copilot output |

Node deps (installed by `npm install`): `next@16.2.9`, `react@19`, `@supabase/supabase-js`,
`@supabase/ssr`, `@anthropic-ai/sdk`, `recharts`, `lucide-react`, `react-dropzone`,
`react-hot-toast`, `clsx`, `tailwind-merge`, `date-fns`, `uuid`. Dev: `typescript`,
`tailwindcss`, `eslint`, `ts-node`.

---

## 4. Identity & credentials

Local development uses **static keys only** (no cloud IAM). Keep them out of git — only
`.env.local.example` is committed; `.env.local` is git-ignored.

- **Supabase anon key** — public, browser-safe, RLS-restricted (read-only for `authenticated`).
- **Supabase service role key** — bypasses RLS; server-only; never expose to the browser.
- **APP_SESSION_SECRET** — HMAC key for the `cc_session` cookie (`openssl rand -base64 48`).
- **ANTHROPIC_API_KEY** — server-side only; injected by the relay, never sent to the browser.

Generate secrets locally:

```bash
openssl rand -base64 48   # APP_SESSION_SECRET
openssl rand -hex 32      # AI_PROXY_TOKEN / BRANDING_ADMIN_TOKEN (optional)
```

---

## 5. Environment variables

Copy the template and fill it in:

```bash
cp .env.local.example .env.local
```

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://abcd.supabase.co` | Supabase project URL (browser + server) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | Public anon key (browser client, RLS-scoped) |
| `SUPABASE_SERVICE_ROLE_KEY` | `eyJhbGci...` | Service role key (server API routes only; bypasses RLS) |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | Enables real AI Copilot; omit for demo output |
| `AI_PROXY_TOKEN` | *(empty in dev)* | Gates `/api/ai/generate` for programmatic callers; optional in dev |
| `APP_SESSION_SECRET` | `<48+ random bytes>` | HMAC signs `cc_session`; must be ≥16 chars or sessions disabled |
| `APP_AUTH_USERNAME` | `admin` | Single-tenant login username |
| `APP_AUTH_PASSWORD` | `<strong password>` | Single-tenant login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Supabase Storage bucket for evidence uploads |
| `BRANDING_ADMIN_TOKEN` | *(empty in dev)* | Gates `PUT /api/settings/branding`; optional single-user |
| `PORT` | `3000` | Dev server port (Next.js default) |

> Leaving `APP_SESSION_SECRET`/`APP_AUTH_*` unset disables the login gate — convenient for
> local dev; the app is fully usable without credentials.

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| `NODE_ENV` | `development` | Set by `next dev`; controls fail-closed AI relay + cookie `secure` flag |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Bucket name; must match the bucket created in Supabase |
| `next.config.js` `images.remotePatterns` | `*.supabase.co` | Allows evidence/logo images served from Supabase Storage |
| Session TTL | 8 hours (code constant `SESSION_TTL_MS`) | Cookie lifetime; not env-configurable |
| AI caps | prompt ≤ 8000 chars, `max_tokens` 1024, 20 req/min/identity | Hard-coded abuse controls in `/api/ai/generate` |

---

## 7. Verification

Run the app:

```bash
npm install
npm run dev
# → http://localhost:3000
```

Set up the database once (hosted Supabase: SQL Editor; local CLI: `psql`):

```bash
# Local self-hosted Supabase example:
psql "$SUPABASE_DB_URL" -f supabase/schema.sql
```

Create the storage bucket `evidence-files` (Supabase Studio → Storage → New bucket).

**Health / homepage** (the dashboard `/` is the health surface — no dedicated `/healthz`):

```bash
curl -sI http://localhost:3000/ | head -1        # expect: HTTP/1.1 200 OK
```

**Login works** (only when session auth is configured):

```bash
curl -s http://localhost:3000/api/auth/login      # {"authenticated":false,"configured":true}
curl -s -X POST http://localhost:3000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"<pw>"}'      # {"ok":true,"user":"admin"}
```

**Secrets resolved** (AI relay reachable — session or token gated):

```bash
curl -s -X POST http://localhost:3000/api/ai/generate \
  -H 'Content-Type: application/json' -H 'x-requested-with: fetch' \
  -d '{"prompt":"Draft a narrative for 3.1.1"}'    # {"text":"..."} or demo output
```

**DB row present** (controls seeded/queryable):

```bash
psql "$SUPABASE_DB_URL" -c "select control_id, status from controls limit 5;"
```

**Storage object written** — in the app, go to **Evidence**, drag-drop a file, then confirm:

```bash
# via Supabase CLI / Studio: object appears under bucket 'evidence-files'
# and a row lands in the evidence table:
psql "$SUPABASE_DB_URL" -c "select title, file_name, file_url from evidence order by created_at desc limit 3;"
```

---

## 8. Day-2 operations

| Task | Command / action |
|---|---|
| Update deps | `npm install` after pulling; review `package-lock.json` diffs |
| Apply schema changes | Re-run `supabase/schema.sql` (idempotent — `IF NOT EXISTS`, drop-then-create policies/triggers) |
| Seed sample data | `npm run db:seed` (runs `scripts/seed.ts` via ts-node) |
| Lint | `npm run lint` |
| Production build test | `npm run build && npm run start` (serves on `:3000`) |
| Rotate session secret | Regenerate `APP_SESSION_SECRET`; all active `cc_session` cookies invalidate |
| Reset local Supabase | `supabase db reset` (CLI) then re-run schema |
| Logs | Dev server prints to terminal; Supabase logs in Studio |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Blank data / Supabase errors | `NEXT_PUBLIC_SUPABASE_*` unset or wrong | Check `.env.local`; restart `next dev` (env is read at startup) |
| AI panel returns demo text only | `ANTHROPIC_API_KEY` unset | Set the key in `.env.local`; restart |
| `/api/ai/generate` → 401 | `AI_PROXY_TOKEN` set but no valid session/token | Log in (session) or send `Authorization: Bearer <AI_PROXY_TOKEN>` |
| `/api/ai/generate` → 503 in prod build | `NODE_ENV=production` + no token + no session (fail closed) | Set `AI_PROXY_TOKEN` or log in |
| Login always 503 | `APP_SESSION_SECRET`/`APP_AUTH_*` incomplete | Set all three; secret must be ≥16 chars |
| Evidence upload fails | Bucket `evidence-files` missing or name mismatch | Create bucket; align `NEXT_PUBLIC_EVIDENCE_BUCKET` |
| Image won't load from Supabase | Host not allowlisted | Confirm `next.config.js` `remotePatterns` includes `*.supabase.co` |
| Port 3000 in use | Another process bound | `PORT=3001 npm run dev` |
