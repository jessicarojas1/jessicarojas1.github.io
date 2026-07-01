# Architecture — Compliance Copilot

Compliance Copilot is a **CMMC Level 2 & NIST SP 800-171 readiness platform**. It
gives an ISSO / assessment team a live compliance score, a controls library with
implementation status, an evidence repository, POA&M tracking, reporting/export,
and an AI copilot that drafts assessor-ready narratives, identifies gaps, and
generates POA&M items.

This document describes the platform, design principles, component layout,
configuration model, the request/error contract, the security model,
observability, and deployment topology.

---

## 1. Platform

| Layer | Technology |
|---|---|
| Framework | **Next.js 16.x**, App Router (React 19, RSC + Client Components) |
| Language | TypeScript 5 (strict) |
| Styling | Tailwind CSS 3, dark enterprise theme (`darkMode: 'class'`) |
| Backend data plane | **Supabase** — PostgreSQL + Row Level Security, Storage, (optional) Auth |
| AI | Anthropic Claude API via a server-side relay (`claude-opus-4-6`) |
| Session auth | Self-contained HMAC-signed cookie (`lib/session.ts` + Edge verifier) |
| Charts / icons | Recharts, lucide-react |
| Uploads | react-dropzone (client), Supabase Storage (server) |

Next.js is the single runtime: it serves the UI (server + client components),
hosts the API route handlers (`app/api/**/route.ts`), and runs the Edge
middleware that gates the app behind login. Supabase is an external managed
service reached over HTTPS with the anon key (browser) or the service-role key
(server routes only).

There is **no separate backend process** — no queue, cron, or worker. All logic
runs inside the Next.js server (Node runtime for route handlers, Edge runtime for
middleware).

---

## 2. Design principles

- **Server holds the secrets.** The browser never sees `SUPABASE_SERVICE_ROLE_KEY`,
  `ANTHROPIC_API_KEY`, or `AI_PROXY_TOKEN`. The AI relay injects the upstream key
  server-side; writes to shared settings use the service-role client in a route
  handler only.
- **Fail closed on cost/abuse.** The AI relay refuses to act as an open,
  unauthenticated proxy in production (returns 503) if no auth is configured
  (OWASP LLM10 / CWE-770). Always-on per-identity rate limiting and hard
  input/output caps apply.
- **RLS is the last line, service-role is the writer.** Reads are granted to the
  `authenticated` role via RLS; all writes go through server routes using the
  service-role key (which bypasses RLS). A stolen anon/user token cannot mutate
  compliance data directly.
- **Degrade gracefully.** No `ANTHROPIC_API_KEY` → AI panel returns demo output.
  No Supabase → the UI runs on seeded data (`lib/data.ts`). No session env →
  middleware stays out of the way for local dev.
- **Single-tenant, single-purpose.** One org per deployment; the login is a
  single env-configured credential pair (`APP_AUTH_USERNAME`/`APP_AUTH_PASSWORD`).
- **Sanitize on the server.** Branding input is re-normalized/sanitized server-side
  even though the client also validates.

---

## 3. Component overview

| Component | Path | Responsibility |
|---|---|---|
| Root layout | `app/layout.tsx` | `<html class="dark">`, wraps app in `BrandingProvider` + `AppShell` |
| App shell | `components/layout/AppShell.tsx` | Sidebar nav (Dashboard/Controls/Evidence/Reports/Settings), logout, logo→home |
| Dashboard | `app/page.tsx` | Compliance score gauge, domain breakdown, needs-attention list |
| Controls list | `app/controls/page.tsx` | Search/filter/status of NIST controls |
| Control detail | `app/controls/[id]/page.tsx` | Implementation statement, evidence links, notes, AI panel |
| Evidence | `app/evidence/page.tsx` | Drag-drop upload, tagging, control linking, expiry |
| Reports | `app/reports/page.tsx` | Domain breakdown, POA&M register, CSV/JSON export |
| Settings | `app/settings/page.tsx` | Branding (logo/name/accent), admin token |
| Login | `app/login/page.tsx` | Username/password → session cookie |
| AI panel | `components/ai/AIAssistantPanel.tsx` | Calls `/api/ai/generate` for 4 actions |
| Branding | `components/branding/*`, `lib/branding.ts` | Provider, brand mark, sanitize, persistence |
| AI relay | `app/api/ai/generate/route.ts` | Authenticated, rate-limited relay to Anthropic |
| Auth route | `app/api/auth/login/route.ts` | GET status / POST login / DELETE logout |
| Branding API | `app/api/settings/branding/route.ts` | GET/PUT shared branding via service-role |
| Session core | `lib/session.ts` | HMAC cookie mint/verify (Node runtime) |
| Session edge | `lib/session-edge.ts` | Web Crypto verify (Edge middleware) |
| Middleware | `middleware.ts` | Gates app pages behind session when configured |
| Data model | `lib/types.ts`, `lib/utils.ts`, `lib/data.ts` | Types, score computation, seed data |
| Supabase clients | `lib/supabase.ts` | Anon client + `createServiceClient()` |
| Schema | `supabase/schema.sql` | Tables, RLS policies, triggers |

---

## 4. Monorepo placement + internal layout

This project lives at `compliance-copilot/` inside the parent monorepo
(`jessicarojas1.github.io`). It is a self-contained Next.js app — build/deploy
tooling (`Dockerfile`, `render.yaml`) uses `rootDir: compliance-copilot`.

```
compliance-copilot/
├── app/                      # App Router: pages + API route handlers
│   ├── layout.tsx            # Root layout (dark, BrandingProvider, AppShell)
│   ├── page.tsx              # Dashboard
│   ├── globals.css
│   ├── login/page.tsx
│   ├── controls/page.tsx
│   ├── controls/[id]/page.tsx
│   ├── evidence/page.tsx
│   ├── reports/page.tsx
│   ├── settings/page.tsx
│   └── api/
│       ├── ai/generate/route.ts        # AI relay (POST)
│       ├── auth/login/route.ts         # GET/POST/DELETE session
│       └── settings/branding/route.ts  # GET/PUT branding
├── components/
│   ├── layout/AppShell.tsx
│   ├── ai/AIAssistantPanel.tsx
│   ├── branding/{BrandMark,BrandingProvider}.tsx
│   └── controls/{StatusBadge,PriorityBadge}.tsx
├── lib/
│   ├── types.ts   utils.ts   data.ts   supabase.ts
│   ├── branding.ts
│   └── session.ts   session-edge.ts
├── supabase/schema.sql       # DB schema + RLS + triggers
├── middleware.ts             # Edge auth gate
├── next.config.js            # output: 'standalone', image remotePatterns
├── tailwind.config.js  postcss.config.js  tsconfig.json
├── Dockerfile   render.yaml
├── docs/         # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
└── deployments/  # target-specific operator guides
```

Path alias: `@/*` → project root (see `tsconfig.json`).

---

## 5. Configuration model

All configuration is environment-driven (`.env.local` in dev; platform secrets in
prod). See [`.env.local.example`](../.env.local.example). Nothing secret is baked
into the image.

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://xyz.supabase.co` | Supabase project URL (public, inlined into browser bundle) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGciOi...` | Supabase anon key — RLS-scoped, browser-safe |
| `SUPABASE_SERVICE_ROLE_KEY` | `eyJhbGciOi...` | **Secret.** Server-only; bypasses RLS for writes |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | **Secret.** Upstream AI key, used only inside the relay |
| `AI_PROXY_TOKEN` | `<random>` | Shared bearer token for programmatic AI callers; required in prod if no session |
| `APP_SESSION_SECRET` | `openssl rand -base64 48` | **Secret.** HMAC key for session cookies (≥16 chars or sessions disabled) |
| `APP_AUTH_USERNAME` | `isso` | Single-tenant login username |
| `APP_AUTH_PASSWORD` | `<strong>` | **Secret.** Single-tenant login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Storage bucket name for evidence |
| `BRANDING_ADMIN_TOKEN` | `<random>` | Optional. Gates the shared branding write (PUT) |

**Precedence for branding:** server value (Supabase `app_settings`) wins over the
per-browser `localStorage` copy; built-in defaults apply when neither exists.

---

## 6. Request & error contract

**Routing.** Pages are App Router segments; API endpoints are route handlers at
`app/api/**/route.ts`. All three API routes set `export const dynamic =
'force-dynamic'` (never statically cached).

**Response envelope.** JSON, per-route shaped:

| Route | Method | Success | Notable errors |
|---|---|---|---|
| `/api/auth/login` | `GET` | `{ authenticated, user, configured }` | — |
| `/api/auth/login` | `POST` | `{ ok: true, user }` + `Set-Cookie: cc_session` | 400 invalid JSON, 401 bad creds, 429 rate-limited, 503 not configured |
| `/api/auth/login` | `DELETE` | `{ ok: true }` (clears cookie) | — |
| `/api/ai/generate` | `POST` | `{ text }` | 400 missing/too-long prompt, 401 unauthorized, 429 rate-limited, 502 upstream, 503 not configured / no key, 500 internal |
| `/api/settings/branding` | `GET` | `{ branding }` or **204** (no backend/row) | — |
| `/api/settings/branding` | `PUT` | `{ ok, persisted: 'server'\|'local', branding }` | 400 invalid JSON, 401 unauthorized (admin token) |

**Error shape.** `{ error: string }` (or `{ ok: false, error }` for auth/branding).
Upstream provider error bodies are **never** forwarded — the relay maps them to a
generic `{ error: 'AI request failed' }` with 502/429. Internal exceptions are
caught and returned as generic 500 to avoid leaking secrets/internals.

**Status code conventions.** 400 client input, 401 auth, 429 rate limit (with
`Retry-After`), 502 upstream failure, 503 misconfigured/fail-closed, 500 unexpected.

---

## 7. Security model

- **App gate (UX layer).** `middleware.ts` runs on all app pages (excluding
  `/login`, `/api/*`, Next internals, static assets). When session auth is
  configured (`APP_SESSION_SECRET` + `APP_AUTH_USERNAME` + `APP_AUTH_PASSWORD`),
  unauthenticated requests are redirected to `/login?next=<relative-path>` (only a
  relative path — no open redirect). Runs in the Edge runtime using Web Crypto
  (`lib/session-edge.ts`).
- **API is the real boundary.** Route handlers enforce their own auth regardless
  of middleware. The AI relay accepts **either** a valid session cookie (in-app
  browser, requires an `x-requested-with` header as CSRF defense on top of
  `SameSite=Strict`) **or** the shared `AI_PROXY_TOKEN` (constant-time compared).
- **Sessions.** Stateless HMAC-SHA256 tokens (`base64url(payload).signature`),
  8-hour TTL, HttpOnly + `SameSite=Strict` + `Secure` in production. The token
  carries no server secret — only proof of a completed login.
- **Database.** RLS enabled on all tables; only SELECT granted to `authenticated`.
  Writes are performed by the service-role client inside route handlers.
- **AI cost/abuse controls.** Fail-closed in production, per-identity fixed-window
  rate limit (20/min), `MAX_PROMPT_CHARS=8000`, server-fixed `max_tokens=1024`.
- **Input sanitization.** Branding logo URLs restricted to `http(s)://` or
  `data:image/...`; accent color to `#rgb`/`#rrggbb`; display name control-char
  stripped and length-capped — enforced on both client and server.

See [SECURITY.md](./SECURITY.md) for the full treatment.

---

## 8. Observability

- **Logs.** Next.js server logs to stdout/stderr (captured by the platform:
  Vercel/Render logs, `docker logs`, `kubectl logs`, or journald). No structured
  logging framework is bundled; add one at the platform edge if needed.
- **Health / liveness.** No dedicated `/api/health` route yet. `GET
  /api/auth/login` returns `200` JSON without auth and is used as the container
  `HEALTHCHECK` and Render `healthCheckPath`. Adding a first-class `/api/health`
  that also pings Supabase is an [open item](../OPEN_ITEMS.md).
- **Metrics / traces.** Not instrumented in-app. Use the hosting platform's
  request metrics (Vercel Analytics, Render metrics, or an ingress/APM layer).
- **Rate-limit state.** In-memory, per-process, best-effort — not a metric source
  and not shared across instances; back with Redis + a WAF for production scale.

---

## 9. Deployment topology

```
                         ┌──────────────────────────────┐
   Browser ── HTTPS ───▶ │  Next.js server              │
   (anon key,            │  (Vercel / Render / container │
    session cookie)      │   / k8s pod — Node runtime)   │
                         │                              │
                         │  middleware (Edge) — auth gate│
                         │  route handlers (Node):       │
                         │   /api/ai/generate  ──────────┼──▶ Anthropic API
                         │   /api/auth/login             │    (server key only)
                         │   /api/settings/branding ─────┼──┐
                         └──────────────────────────────┘  │
                                    │ anon (RLS)            │ service-role
                                    ▼                       ▼
                         ┌──────────────────────────────────────┐
                         │  Supabase                              │
                         │  Postgres (RLS) · Storage · Auth       │
                         └──────────────────────────────────────┘
```

- **Managed (recommended):** Vercel or Render running the Next.js app + Supabase
  cloud project.
- **Self-hosted:** container (this repo's `Dockerfile`, standalone output) on a
  single Linux VM, Kubernetes, or an air-gapped enclave with self-hosted Supabase
  and Ollama replacing the hosted AI relay.

See [DEPLOYMENT.md](./DEPLOYMENT.md) and the per-target guides in
[`../deployments/`](../deployments/).
