# CLAUDE.md — Business Insight Dashboard

Project guidance for AI coding agents (and humans) working in this subproject.
This project lives in the `business-insight-dashboard/` subdirectory of the
monorepo at the repo root.

## What this app is

A single-page **Streamlit** analytics app: a user uploads a CSV in the browser
and instantly gets KPI cards, Plotly charts, and plain-English, rule-based
business insights. No account, no setup, no code. Uploaded data is processed
**in memory** and is never persisted or transmitted.

## Stack

| Layer | Technology |
|-------|-----------|
| Runtime | Python 3.11.9 (see `.python-version`) |
| UI framework | Streamlit (`>=1.58.0`) |
| Data | pandas (`>=2.1.0`), numpy (`>=1.26.0`) |
| Charts | Plotly (`>=5.20.0`) |
| Persistence | none for data; `branding.json` on local disk for branding only |
| Auth | none built in — must be fronted by a reverse-proxy / SSO |
| Health | `GET /_stcore/health` (Streamlit core) |

## Where things live

```
business-insight-dashboard/
├── app.py                 # Streamlit UI + orchestration (entry point)
├── modules/
│   ├── loader.py          # CSV ingestion + smart column detection (ALIASES)
│   ├── kpis.py            # KPI + aggregation computation
│   ├── charts.py         # Plotly figure builders
│   ├── insights.py       # Rule-based insight engine (no LLM/API calls)
│   ├── styles.py         # Custom CSS + HTML card helpers
│   └── branding.py       # Settings → Branding; persists branding.json
├── sample_data/sample_business.csv   # 82-row demo dataset
├── requirements.txt
├── Dockerfile             # python:3.11-slim, non-root, healthchecked
├── render.yaml            # Render Blueprint
├── docs/                  # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
└── deployments/           # LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES,
                           # AZURE, AWS, AIRGAPPED
```

## Data model (important)

- **No database.** The uploaded CSV is parsed with pandas into a DataFrame that
  lives only for the session; nothing is written server-side.
- The **only** persisted server-side state is `branding.json` (display name,
  accent color, logo URL/data URL), written next to `app.py`. On multi-replica
  or read-only-filesystem deployments this needs a writable/shared volume, or
  branding will not persist / will diverge per replica.
- The canonical CSV schema is defined by `ALIASES` in `modules/loader.py`:
  `date, revenue, leads, conversions, product, service, source, customer`
  (each with many recognised alias spellings). `date`+`revenue` are the
  practical minimum for meaningful output.

## Conventions

- Keep compute in `modules/` (loader → kpis/charts → insights); keep `app.py`
  as UI/orchestration only.
- KPI/chart/insight functions return `None` (or `None` metrics) when required
  columns are missing — the UI must degrade gracefully (show an info hint), not
  error.
- Insights are **rule-based** (pandas/numpy). Do **not** add hidden network/LLM
  calls. Any future AI narrative feature must be opt-in and clearly documented.

## Security / branding rules that apply here

- **No auth is built in.** Never claim otherwise. Any deployment exposing
  sensitive data must add authentication at a reverse proxy (oauth2-proxy, SSO,
  ALB OIDC / Cognito, Entra ID Easy Auth). See `docs/SECURITY.md`.
- **Sanitize all branding inputs** (already enforced in `modules/branding.py`):
  logo must be `http(s)://` or `data:image/...`; accent must be `#RGB`/`#RRGGBB`;
  the display name is HTML-escaped everywhere it is rendered. Keep it that way.
- Every place that injects a user-supplied string into markup (`unsafe_allow_html`)
  must escape it first.
- The **Settings → Branding** area (logo via URL or upload, org name, accent
  color) is required by the org standard and must remain; keep the field
  reference caption under the upload control.
- The sidebar brand mark must remain the app's home affordance.

## Build / run / test / deploy

```bash
# Local (native)
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
streamlit run app.py                       # http://localhost:8501

# Local (docker)
docker build -t business-insight-dashboard .
docker run -p 8501:8501 business-insight-dashboard
curl -fsS http://localhost:8501/_stcore/health   # -> ok

# Deploy: Render Blueprint (render.yaml) or the container image to any target.
```

There is **no database migration** step and **no worker/background process** —
all computation is synchronous within the Streamlit rerun.

## Standing rule — keep the doc set current

This project ships the standardized doc + deployment set and it must be kept
accurate to the code:

- `deployments/` ×6: `LOCAL_DEVELOPMENT`, `SINGLE_LINUX_SERVER`, `KUBERNETES`,
  `AZURE`, `AWS`, `AIRGAPPED`
- `docs/` ×4: `ARCHITECTURE`, `DEPLOYMENT`, `DISASTER_RECOVERY`, `SECURITY`
- Root: `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`

Whenever a feature, dependency, config, or deployment detail changes, update the
affected files in the **same** change. Treat this doc set as part of "done".
Do not invent env vars, ports, commands, or paths — verify against the real code.
