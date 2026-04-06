# Compliance Copilot

**CMMC Level 2 & NIST SP 800-171 Readiness Platform**

A production-ready compliance management application built with Next.js 14, Tailwind CSS, and Supabase.

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

- **Framework**: Next.js 14 (App Router)
- **Styling**: Tailwind CSS — dark enterprise theme
- **Database**: Supabase (PostgreSQL + Storage)
- **AI**: Anthropic Claude API (`claude-opus-4-6`)
- **Charts**: Recharts
- **Icons**: Lucide React
- **File Upload**: react-dropzone

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

Edit `.env.local`:
```env
NEXT_PUBLIC_SUPABASE_URL=https://your-project.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=your-anon-key
SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
ANTHROPIC_API_KEY=sk-ant-...
```

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
│   ├── page.tsx                  # Dashboard
│   ├── controls/
│   │   ├── page.tsx              # Controls list with search/filter
│   │   └── [id]/page.tsx         # Control detail + AI panel
│   ├── evidence/page.tsx         # Evidence upload and management
│   ├── reports/page.tsx          # Reports + CSV/JSON export
│   └── api/ai/generate/route.ts  # AI generation API route
├── components/
│   ├── layout/AppShell.tsx       # Sidebar + responsive shell
│   ├── controls/StatusBadge.tsx
│   ├── controls/PriorityBadge.tsx
│   └── ai/AIAssistantPanel.tsx   # Claude-powered assistant
├── lib/
│   ├── types.ts                  # TypeScript interfaces
│   ├── utils.ts                  # Helpers (cn, formatDate, computeSummary)
│   ├── supabase.ts               # Supabase client
│   └── data.ts                   # Seed data (20 controls, 8 evidence items)
└── supabase/
    └── schema.sql                # DB schema + RLS policies
```

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
