# Sentinel QMS — Frontend

Enterprise Quality Management System frontend for aerospace, manufacturing, and
U.S. DoD operations. Built as a single-page application deployable to AWS GovCloud
and Azure Government behind nginx.

## Stack

- **React 18 + TypeScript + Vite**
- **react-router-dom v6** — routing, protected + role-gated routes
- **TanStack Query (react-query)** — server state / data fetching
- **react-hook-form + zod** — forms and validation
- **recharts** — dashboard charts
- **lucide-react** — icons
- Plain CSS design system with CSS custom properties (light/dark themes)

## Getting started

```bash
npm install
npm run dev          # http://localhost:5173 (proxies /api -> http://localhost:8000)
```

Other scripts:

```bash
npm run build        # type-check + production build to dist/
npm run preview      # preview the production build
npm run test         # run unit tests (vitest)
npm run lint         # eslint
npm run typecheck    # tsc --noEmit
```

## Configuration

Copy `.env.example` to `.env` and adjust:

| Variable | Default | Purpose |
| --- | --- | --- |
| `VITE_API_BASE_URL` | `/api/v1` | REST API base path |
| `VITE_DEPLOYMENT_LABEL` | — | Label shown in the CUI footer (e.g. `AWS GovCloud (US)`) |

## Architecture

```
src/
  lib/         api (axios + JWT refresh), auth context, rbac, theme, toast, formatters, chart tokens
  components/  Layout shell, DataTable, Modal, SignatureModal (21 CFR Part 11), StatusBadge, KpiCard, …
  hooks/       react-query resource hooks (one per domain) via a generic CRUD factory
  pages/       one folder per QMS module (list + detail)
  types/       TS interfaces mirroring backend schemas
  router.tsx   route table with ProtectedRoute + capability gating
```

### Authentication

`lib/api.ts` holds an axios instance that injects the JWT bearer token and
transparently refreshes on `401` using the refresh token. On refresh failure it
broadcasts `sentinel:session-expired`, which `AuthProvider` listens for to clear
state and route the user back to login.

### Role-based access control

`lib/rbac.ts` maps the seven roles (Admin, Quality Manager, Quality Engineer,
Auditor, Supplier Quality, Operator, Read-Only) to capabilities. The side nav,
action buttons, and routes are gated with `can()` / `<ProtectedRoute capability>`.
The backend remains the authoritative enforcement point.

### Electronic signatures (21 CFR Part 11)

`SignatureModal` captures a signature meaning, a reason/comment, and re-authenticates
the user's password before committing approvals and dispositions. Used by NCR
dispositions, CAPA closure, document approval, and change approval.

### Compliance / DoD handling

- CUI (Controlled Unclassified Information) banner at the top and bottom of every screen.
- nginx serves on port `8080` as a non-root user with a strict CSP and security headers.

## Docker

```bash
docker build -t sentinel-qms-frontend .
docker run -p 8080:8080 sentinel-qms-frontend
```

The multi-stage build compiles the SPA and serves the static bundle via
`nginx:alpine` with SPA fallback, gzip, and security headers (see `nginx.conf`).
```
