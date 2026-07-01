# Architecture — Business Insight Dashboard

> Canonical architecture reference for the Business Insight Dashboard.
> Related: [DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [SECURITY.md](SECURITY.md)
> Operator guides: [../deployments/](../deployments/)

---

## 1. Platform

The Business Insight Dashboard is a single-process **[Streamlit](https://streamlit.io/)** web application. A user uploads a CSV of business data and the app auto-detects columns, computes KPIs, renders interactive charts, and produces plain-language, rule-based insights — all in the browser session, with the data held only in server memory for the life of that request.

| Layer | Technology | Role |
|-------|-----------|------|
| UI / server | Streamlit `>=1.58.0` (Python 3.11.9) | Renders the app, manages session/WebSocket, serves health endpoint |
| Data engine | pandas `>=2.1.0`, numpy `>=1.26.0` | CSV ingestion, column coercion, KPI math, trend/anomaly stats |
| Visualization | Plotly `>=5.20.0` | Interactive figures (trend, bar, donut, scatter, waterfall) |
| Persistence | Local file `branding.json` | The **only** server-side persisted state |

There is **no database**, **no background worker**, **no message queue**, and **no external API/LLM** in the current design. The entry point is `app.py`, which listens on port **8501** (Render binds `$PORT`).

```
streamlit run app.py --server.port $PORT --server.address 0.0.0.0 --server.headless true
```

There are no system or native dependencies beyond a `python:3.11-slim` base image.

---

## 2. Design Principles

1. **Zero-config.** The user provides only a CSV. Column detection (`modules/loader.py`) maps many alias spellings to 8 canonical columns, so no mapping UI or configuration is required to get value on first upload. A bundled `sample_data/sample_business.csv` (82 rows) lets a first-time user explore instantly.

2. **Ephemeral / privacy-first.** Uploaded data is processed entirely **in memory** with pandas and is **never written to disk or transmitted**. The footer states plainly: *"Data processed locally — nothing is stored or transmitted."* When the session ends or the app reruns without a file, the data is gone.

3. **Stateless except branding.** The application is effectively stateless. The single exception is `branding.json` (logo, name, accent color) persisted next to `app.py`. Everything else is the container image + git repo (the code of record) plus transient session state.

4. **Rule-based transparency.** Insights are produced by a deterministic, inspectable rule engine (`modules/insights.py`) using numpy statistics — trend slope via `polyfit`, z-score anomaly detection, Pareto/concentration checks. **No LLM, no opaque model, no network call.** Every headline can be traced to a rule, which makes the output auditable and reproducible.

---

## 3. Component Overview

All modules live under `business-insight-dashboard/`.

| Module | Responsibility |
|--------|----------------|
| `app.py` | Streamlit UI + orchestration. Calls `set_page_config` first, injects CSS, renders a landing screen when no file is uploaded; otherwise runs `load_and_detect` → `compute_kpis` → chart builders → `generate_insights`. Draws the sidebar upload, KPI row, charts, insights, and footer. |
| `modules/loader.py` | CSV ingestion + smart column detection. `ALIASES` maps 8 canonical columns (`date`, `revenue`, `leads`, `conversions`, `product`, `service`, `source`, `customer`) to many alias spellings. `load_and_detect(file_obj)` returns `(df, col_map)`, parses dates, and coerces numeric values (stripping currency symbols). |
| `modules/kpis.py` | KPI computation: `compute_kpis`, `revenue_by_period`, `leads_by_period`, `top_by_column`, `conversions_by_column`. Returns plain Python values; missing metrics are `None`. |
| `modules/charts.py` | Plotly figure builders: `revenue_trend`, `performance_bar`, `source_donut`, `conversions_bar`, `scatter_by_product`, `growth_waterfall`. Each returns `None` when required data is missing. |
| `modules/insights.py` | Rule-based insight engine. Computes revenue trend (numpy `polyfit` slope), best/worst period, top product/service, conversion efficiency, top source, z-score anomaly detection, lead trend, and customer concentration/Pareto. Returns a list of dicts `{icon, headline, detail, severity}` with `severity ∈ {positive, warning, negative, neutral}`. **No API/LLM calls.** |
| `modules/styles.py` | Custom CSS (imports the Inter Google Font via a CSS URL) plus HTML card helpers: `kpi_card`, `insight_card`, `section_header`. |
| `modules/branding.py` | Settings → Branding. Persists `branding.json` next to `app.py` as `{logo, name, accent}`. Sanitizes inputs (see §5). Public API: `load_branding`, `save_branding`, `get_branding`, `apply_accent_css`, `render_sidebar_brand`, `render_settings_ui`. |
| `sample_data/sample_business.csv` | 82-row demo dataset with columns `date,revenue,leads,conversions,product,service,source,customer`. |

### Data flow

```
CSV upload (sidebar)
      │
      ▼
loader.load_and_detect(file_obj) ── (df, col_map)
      │
      ├──► kpis.compute_kpis(df, col_map) ─────► KPI row
      ├──► charts.*(df, col_map) ──────────────► Plotly figures (None-guarded)
      └──► insights.generate_insights(df, col_map) ─► insight cards
      │
      ▼
Streamlit render (in memory only) ──► browser
```

---

## 4. Monorepo Structure

This project is one subdirectory of the monorepo rooted at `/home/user/jessicarojas1.github.io/`.

```
jessicarojas1.github.io/            # monorepo root
└── business-insight-dashboard/     # this project
    ├── app.py                      # entry point (Streamlit UI + orchestration)
    ├── modules/
    │   ├── loader.py               # CSV ingestion + column detection
    │   ├── kpis.py                 # KPI computation
    │   ├── charts.py               # Plotly figure builders
    │   ├── insights.py             # rule-based insight engine
    │   ├── styles.py               # CSS + card HTML helpers
    │   └── branding.py             # Settings → Branding + branding.json I/O
    ├── sample_data/
    │   └── sample_business.csv     # 82-row demo dataset
    ├── branding.json               # persisted branding (created at runtime)
    ├── docs/                       # ARCHITECTURE / DEPLOYMENT / DISASTER_RECOVERY / SECURITY
    ├── deployments/                # per-target operator guides
    ├── Dockerfile                  # python:3.11-slim, non-root, healthcheck
    └── render.yaml                 # Render Blueprint
```

**Internal layout convention:** `app.py` owns orchestration and layout only; all reusable logic lives in `modules/` with a single responsibility per file. Modules never call Streamlit render primitives except `styles.py` and `branding.py` (which produce UI helpers by design).

---

## 5. Configuration Model

Configuration comes from three sources, in order of specificity:

### 5.1 Streamlit runtime config

Set via CLI flags or `.streamlit/config.toml` / environment. Operationally relevant flags:

| Setting | Value | Purpose |
|---------|-------|---------|
| `--server.port` | `$PORT` | Bind port (8501 default; Render injects `$PORT`) |
| `--server.address` | `0.0.0.0` | Bind all interfaces (container) |
| `--server.headless` | `true` | No browser auto-launch, no email prompt |
| `server.enableXsrfProtection` | `true` (default) | XSRF token on forms/uploads |
| `server.maxUploadSize` | e.g. `50` (MB) | Bounds upload size / DoS surface |
| `browser.gatherUsageStats` | `false` | Disable telemetry |

### 5.2 Environment variables

The app itself requires **no secrets**. Env vars are limited to the platform/proxy layer:

| Variable | Example | Purpose |
|----------|---------|---------|
| `PORT` | `8501` | Injected by Render/PaaS; bound by `--server.port` |
| `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` | `50` | Alternate way to cap upload size |
| `STREAMLIT_BROWSER_GATHER_USAGE_STATS` | `false` | Disable usage stats |

Secrets (OIDC client secret, etc.) exist **only** for a reverse-proxy auth layer, not the app — see [SECURITY.md](SECURITY.md).

### 5.3 `branding.json`

The only application-persisted config. Shape: `{logo, name, accent}`.

- `logo`: a URL (`http(s)://` or `data:image/...`) — an uploaded file is stored inline as a base64 `data:` URL.
- `name`: display name, HTML-escaped wherever rendered.
- `accent`: hex color, validated `#RGB` or `#RRGGBB`, applied as a CSS custom property via `apply_accent_css`.

A missing or corrupt `branding.json` degrades silently to built-in defaults (see §6).

---

## 6. Request / Error Contract

### 6.1 Rerun model & WebSocket session

Streamlit uses a **rerun model**: any widget interaction (upload, settings change, chart control) re-executes `app.py` top-to-bottom for that session. The browser holds a persistent **WebSocket** to the server for the life of the session; there is no per-click HTTP request contract to design against. This has two consequences for infrastructure:

- Proxies and load balancers must forward **WebSocket upgrade** headers and provide **session affinity** (sticky sessions) so a client stays pinned to one replica.
- Compute is **synchronous per rerun** — there is no async job or queue.

### 6.2 Error handling

| Condition | Behavior |
|-----------|----------|
| Bad/unparseable CSV | Caught in `load_and_detect`; surfaced via `st.error(...)` — the app stays up |
| Missing canonical column | Dependent KPI is `None` and its chart builder returns `None`; the chart is hidden and an informational message is shown instead of erroring |
| Corrupt / missing `branding.json` | Silently falls back to built-in default logo/name/accent — never breaks the UI |
| Broken logo URL | Degrades gracefully to the default brand mark |

The design contract is **fail-soft**: partial data yields partial (but valid) output; nothing takes the app down.

---

## 7. Security Model

Summarized here; full detail in [SECURITY.md](SECURITY.md).

- **No built-in authentication.** Streamlit provides none. Auth must be added at a reverse proxy (nginx + oauth2-proxy, ALB OIDC / Cognito, Entra ID / App Service Easy Auth, or SSO). Authorization is all-or-nothing at that proxy.
- **In-memory data.** Uploaded CSV never touches disk and is never transmitted — a strong privacy property. TLS terminates at the proxy for data in transit.
- **Input sanitization.** `branding.py` allowlists logo URLs (`http(s)://` / `data:image/...`), validates the accent hex, and HTML-escapes the name before injection into markup.
- **XSRF.** Streamlit's built-in XSRF protection guards form and upload posts (`server.enableXsrfProtection`).

---

## 8. Observability

| Signal | Source |
|--------|--------|
| Application logs | Streamlit stdout/stderr (container logs, platform log stream) |
| Health / readiness | `GET /_stcore/health` returns `ok` |
| Metrics | Not emitted by the app; gather request/latency/error metrics at the reverse proxy or platform LB |
| Session/WebSocket state | Proxy-level connection metrics (WS upgrades, sticky-session distribution) |

Wire the health endpoint into container `HEALTHCHECK`, Kubernetes liveness/readiness probes, and PaaS health checks.

---

## 9. Deployment Topology

```
            ┌────────────────────────────────────────────┐
  Client ── │  Reverse proxy / LB (TLS, auth, WS upgrade, │
   (HTTPS)  │  sticky sessions)                            │
            └───────────────┬────────────────────────────┘
                            │  ws:// + http (health)
              ┌─────────────┴─────────────┐
              ▼                           ▼
     ┌──────────────────┐       ┌──────────────────┐
     │ Streamlit replica │  ...  │ Streamlit replica │
     │  app.py :8501     │       │  app.py :8501     │
     │  branding.json ◄──┼───────┼──► (shared vol or │
     └──────────────────┘       └──   config-as-code)│
```

- **Single instance** (Render / single Linux server): simplest; `branding.json` on local disk.
- **Multi-replica** (Kubernetes / ALB / App Service): requires **session affinity** and a **shared volume** for `branding.json` (or treat branding as config-as-code) so replicas don't diverge — see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) §HA.

Per-target operator guides:

- [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md)
- [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)
- [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md)
- [../deployments/AZURE.md](../deployments/AZURE.md)
- [../deployments/AWS.md](../deployments/AWS.md)
- [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)
