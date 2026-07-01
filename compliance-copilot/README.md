# Compliance Copilot

**CMMC Level 2 & NIST SP 800-171 Readiness Platform**

![build](https://img.shields.io/badge/build-manual-lightgrey)
![next](https://img.shields.io/badge/Next.js-16-black)
![node](https://img.shields.io/badge/Node-20%2B-brightgreen)
![license](https://img.shields.io/badge/license-MIT-blue)

A compliance management application built with **Next.js (App Router)**, **Tailwind CSS**, and **Supabase**.

> Build badges are placeholders until CI is wired — see [OPEN_ITEMS.md](./OPEN_ITEMS.md).

## Why it exists

Preparing for a CMMC Level 2 assessment against NIST SP 800-171 means tracking 110
controls, their implementation status and evidence, and a POA&M for every gap —
work that usually lives in spreadsheets. Compliance Copilot centralizes that into a
live readiness score, a controls library with evidence linkage, POA&M tracking, and
an AI copilot that drafts assessor-ready narratives and gap analyses — with secrets
kept server-side and the AI relay hardened against cost abuse.

## Documentation

- [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md) — platform, components, config, request/error contract, security model, topology
- [`docs/DEPLOYMENT.md`](./docs/DEPLOYMENT.md) — deployment models, migrations, Ollama/GPU, production checklist
- [`docs/DISASTER_RECOVERY.md`](./docs/DISASTER_RECOVERY.md) — state, RPO/RTO, backups, restore runbook, HA
- [`docs/SECURITY.md`](./docs/SECURITY.md) — auth, RLS authz, data protection, CUI/DLP, FIPS, rotation
- [`OPEN_ITEMS.md`](./OPEN_ITEMS.md) — production-readiness register
- Target guides: [`deployments/`](./deployments/) — Local, Single Linux Server, Kubernetes, Azure, AWS, Air-gapped

## Supported deployment models

Managed PaaS (Vercel / Render via [`render.yaml`](./render.yaml)) · single Linux
server (Docker or `next start` behind nginx/TLS) · container ([`Dockerfile`](./Dockerfile),
standalone output, non-root) · Kubernetes · Azure · AWS (Commercial + GovCloud) ·
air-gapped (self-hosted Supabase + Ollama). See [`docs/DEPLOYMENT.md`](./docs/DEPLOYMENT.md).

---

## Features

| Feature | Description |
|---|---|
| 📊 Dashboard | Live compliance score, domain breakdown, needs-attention list |
| 🛡️ Controls Library | All NIST 800-171 controls with filter, search, status tracking |
| 📝 Control Detail | Implementation statement, evidence links, policy refs, notes, AI panel |
| 🤖 AI Copilot | Drafts narratives, identifies gaps, suggests improvements, generates POA&M items |
| 📁 Evidence Repository | Drag-and-drop upload, tagging, control linking, expiry tracking |
| 📈 Reports | Domain breakdown, POA&M register, CSV/JSON export |

---

## Tech Stack

- **Framework**: Next.js 16 (App Router), React 19, TypeScript 5 (strict)
- **Styling**: Tailwind CSS 3 — dark enterprise theme
- **Database**: Supabase (PostgreSQL + Row Level Security + Storage)
- **AI**: Anthropic Claude API (`claude-opus-4-6`) via a hardened server-side relay
- **Charts**: Recharts
- **Icons**: Lucide React
- **File Upload**: react-dropzone

### Dependencies (from `package.json`)

**Runtime:** `next@16`, `react@19`, `react-dom@19`, `@supabase/supabase-js`,
`@supabase/ssr`, `@anthropic-ai/sdk`, `recharts`, `lucide-react`, `react-dropzone`,
`react-hot-toast`, `clsx`, `tailwind-merge`, `date-fns`, `uuid`.

**Dev/build:** `typescript@5`, `@types/*`, `eslint@9`, `eslint-config-next`,
`tailwindcss@3`, `postcss`, `autoprefixer`, `ts-node`.

**External services:** a Supabase project (Postgres + Storage) and, for live AI, an
Anthropic API key (or a self-hosted Ollama endpoint for air-gapped/CUI).

## Prerequisites

- **Node 20+** and npm.
- A **Supabase** project (URL + anon key + service-role key).
- (Optional) an **Anthropic API key** for live AI — omit for demo output.
- (Optional) **Docker** to build/run the container image.

---

## Quick Start

### 1. Clone and install

```bash
git clone <repo>
cd compliance-copilot
npm install
```

### 2. Configure environment

```bash
cp .env.local.example .env.local
```

Edit `.env.local` (see [`.env.local.example`](./.env.local.example) for the full,
documented list):
```env
NEXT_PUBLIC_SUPABASE_URL=https://your-project.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=your-anon-key
SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
ANTHROPIC_API_KEY=sk-ant-...

# Optional but recommended for any shared deployment: enables the login gate.
APP_SESSION_SECRET=          # openssl rand -base64 48  (>=16 chars)
APP_AUTH_USERNAME=isso
APP_AUTH_PASSWORD=           # strong password
AI_PROXY_TOKEN=              # required in production so the AI relay isn't open
```

> In local dev you can omit the session vars entirely — the middleware stays out
> of the way and the app runs on seed data. In production, set them plus
> `AI_PROXY_TOKEN` (the relay fails closed otherwise). Full details in
> [`docs/SECURITY.md`](./docs/SECURITY.md).

### 3. Set up Supabase

1. Create a project at [supabase.com](https://supabase.com)
2. Run `supabase/schema.sql` in the SQL Editor
3. Create a Storage bucket named `evidence-files` (public or private)

### 4. Run development server

```bash
npm run dev
```

Open [http://localhost:3000](http://localhost:3000)

---

## Project Structure

```
compliance-copilot/
├── app/
│   ├── layout.tsx                       # Root layout (dark, BrandingProvider, AppShell)
│   ├── page.tsx                         # Dashboard
│   ├── login/page.tsx                   # Session login
│   ├── controls/page.tsx                # Controls list with search/filter
│   ├── controls/[id]/page.tsx           # Control detail + AI panel
│   ├── evidence/page.tsx                # Evidence upload and management
│   ├── reports/page.tsx                 # Reports + CSV/JSON export
│   ├── settings/page.tsx                # Branding settings
│   └── api/
│       ├── ai/generate/route.ts         # Hardened AI relay (POST)
│       ├── auth/login/route.ts          # GET status / POST login / DELETE logout
│       └── settings/branding/route.ts   # GET/PUT shared branding (service-role)
├── components/
│   ├── layout/AppShell.tsx              # Sidebar + responsive shell (logo → home)
│   ├── ai/AIAssistantPanel.tsx          # Claude-powered assistant
│   ├── branding/{BrandMark,BrandingProvider}.tsx
│   └── controls/{StatusBadge,PriorityBadge}.tsx
├── lib/
│   ├── types.ts   utils.ts   data.ts    # Types, score computation, seed data
│   ├── supabase.ts                      # Anon client + createServiceClient()
│   ├── branding.ts                      # Branding sanitize + persistence
│   └── session.ts   session-edge.ts     # HMAC cookie session (Node + Edge)
├── middleware.ts                        # Edge auth gate
├── supabase/schema.sql                  # DB schema + RLS + triggers
├── next.config.js                       # output: 'standalone'
├── Dockerfile   render.yaml
├── docs/                                # ARCHITECTURE · DEPLOYMENT · DR · SECURITY
└── deployments/                         # Local · Linux · k8s · Azure · AWS · Airgapped
```

---

## Common Commands

| Command | Purpose |
|---|---|
| `npm install` | Install dependencies |
| `npm run dev` | Start dev server on http://localhost:3000 |
| `npm run build` | Production build (standalone output) |
| `npm start` | Serve the production build (`next start`) |
| `npm run lint` | Lint with ESLint / eslint-config-next |
| `psql "$DB_URL" -f supabase/schema.sql` | Apply the DB schema (idempotent) |
| `docker build -t compliance-copilot .` | Build the container image |

---

## Seeded Controls

The app ships with 20 seeded NIST 800-171 controls across 6 domains:

| Domain | Controls |
|---|---|
| AC — Access Control | 3.1.1, 3.1.2, 3.1.3, 3.1.5, 3.1.6, 3.1.12 |
| IA — Identification & Authentication | 3.5.1, 3.5.2, 3.5.3, 3.5.4 |
| AU — Audit & Accountability | 3.3.1, 3.3.2, 3.3.5 |
| CM — Configuration Management | 3.4.1, 3.4.2, 3.4.6 |
| IR — Incident Response | 3.6.1, 3.6.2 |
| SI — System & Info Integrity | 3.14.1, 3.14.2, 3.14.3, 3.14.6 |

---

## AI Copilot

The AI panel (powered by Claude) supports 4 actions per control:

- **Draft Narrative** — Assessor-ready implementation statement
- **Identify Gaps** — Missing evidence and coverage gaps
- **Suggest Improvements** — Actionable technical improvements
- **Generate POA&M** — Draft POA&M item with milestones

Without `ANTHROPIC_API_KEY`, the panel returns realistic demo output.

---

## v2 Enhancements

- [ ] Supabase Auth (email/SSO) + role-based access (ISSO, assessor, read-only)
- [ ] Full Supabase persistence (replace seed data with live DB)
- [ ] Evidence file upload to Supabase Storage with virus scanning
- [ ] All 110 NIST SP 800-171 controls
- [ ] Assessment workflow (create assessment, assign controls, track findings)
- [ ] Multi-tenant org support
- [ ] CMMC L3 / NIST 800-172 controls
- [ ] Automated evidence expiry notifications
- [ ] Audit trail / change history per control
- [ ] PDF report generation (readiness letter, SSP summary)
- [ ] Calendar view for upcoming reviews and expiring evidence
- [ ] Jira/ServiceNow integration for POA&M items

---

## License

MIT — Use freely for internal compliance programs.
