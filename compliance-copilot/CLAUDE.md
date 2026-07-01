# CLAUDE.md — Compliance Copilot

Project guidance for this app. Inherits the parent monorepo rules
(`../CLAUDE.md`); this file records what is specific to Compliance Copilot.

## What it is

A **CMMC Level 2 & NIST SP 800-171 readiness platform**: compliance score
dashboard, controls library, control detail with evidence/notes and an AI copilot,
evidence repository, POA&M tracking, and reports/export.

## Stack

- **Next.js 16 (App Router)**, React 19, TypeScript 5 (strict).
- **Tailwind CSS 3**, dark enterprise theme (`darkMode: 'class'`).
- **Supabase** — Postgres (with RLS), Storage, optional Auth.
- **Anthropic Claude API** via a server-side relay (model `claude-opus-4-6`).
- Recharts, lucide-react, react-dropzone, react-hot-toast.
- Path alias `@/*` → project root.

## Where things live

- Pages + API routes: `app/` (route handlers at `app/api/**/route.ts`).
- UI: `components/` (`layout/`, `ai/`, `branding/`, `controls/`).
- Logic/data: `lib/` (`types.ts`, `utils.ts`, `data.ts`, `supabase.ts`,
  `branding.ts`, `session.ts`, `session-edge.ts`).
- DB: `supabase/schema.sql` (idempotent — tables, RLS, triggers).
- Auth gate: `middleware.ts` (Edge runtime).
- Docs: `docs/` (ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY),
  `deployments/` (×6), `README.md`, `OPEN_ITEMS.md`.
- Container/deploy: `Dockerfile` (standalone, non-root), `render.yaml`.

## Conventions

- **Server holds secrets.** Never expose `SUPABASE_SERVICE_ROLE_KEY`,
  `ANTHROPIC_API_KEY`, or `AI_PROXY_TOKEN` to the browser. Secrets are read only in
  route handlers. Only `NEXT_PUBLIC_*` (anon key, URL) may reach the client.
- **AI relay fails closed** in production (503) when unauthenticated/misconfigured.
  Keep the per-identity rate limit and prompt/output caps; never let clients set
  `max_tokens` or the model.
- **DB writes go through the service-role client** in route handlers; RLS grants
  only `SELECT` to `authenticated` — do not add `authenticated` write policies.
- **Sessions** use HMAC-SHA256 cookies; keep `lib/session.ts` (Node) and
  `lib/session-edge.ts` (Web Crypto) byte-for-byte compatible.
- **Sanitize on the server** — branding logo URL / accent / display name are
  re-validated in the route even though the client validates too.
- **Branding standard:** logo (URL or `data:` upload), display name, accent color;
  server value wins over `localStorage`; sanitize logo URLs to `http(s)`/`data:image`.
- **No inline event handlers / CSP-friendly** per the parent rules; header logo
  links home (`/`).
- **`.env.local` is never committed** — only `.env.local.example` with placeholders.
- **`supabase/schema.sql` stays current** and idempotent as migrations land.

## Build / test / deploy

```bash
npm install
cp .env.local.example .env.local   # fill in values
npm run dev                        # http://localhost:3000
npm run build && npm start         # production build (standalone)
npm run lint                       # next lint (ESLint 9 / eslint-config-next)
```

Apply the schema in Supabase (SQL Editor or `psql -f supabase/schema.sql`); create
the `evidence-files` Storage bucket. Container: `docker build -t compliance-copilot
.` then run with env injected. Render: `render.yaml` blueprint. Health probe:
`GET /api/auth/login`.

## Standing rule

Keep the standard doc set — `docs/` (×4), `deployments/` (×6), `README.md`,
`OPEN_ITEMS.md`, this file, `Dockerfile`, `render.yaml` — **current** whenever a
feature, migration, env var, or config change lands. Update the affected docs in
the same change; treat it as part of "done" alongside the security and UI audits.
