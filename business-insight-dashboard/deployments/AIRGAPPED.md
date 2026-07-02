# Air-Gapped Deployment — Business Insight Dashboard

Operator guide for running the **Business Insight Dashboard** in a **fully
disconnected (air-gapped)** environment with **no outbound internet**.

The app is a **Streamlit** application (Python 3.11.9). It has **no external
database**, no built-in authentication, and **makes no AI/LLM calls**. Uploaded
CSVs are parsed with pandas **in memory only** and are never persisted or
transmitted. The **only** persisted state is `branding.json` (display name,
accent color, logo) written next to `app.py`.

Dependencies (only four):
`streamlit>=1.58.0`, `pandas>=2.1.0`, `plotly>=5.20.0`, `numpy>=1.26.0`.

> **Outbound assets that must be vendored.** This app assumes the browser and
> host can reach the internet in normal deployments. In an air-gap you must
> handle these explicitly (see [§ Offline asset vendoring](#offline-asset-vendoring)):
> - `modules/styles.py` imports a **Google Fonts** CSS URL. With no egress the
>   font request fails and the UI **degrades to system fonts**. Vendor/self-host
>   the font, or accept the fallback.
> - **Plotly** may fetch CDN assets (e.g. the plotly.js bundle / MathJax) from a
>   CDN. You must self-host these or the charts may fail to render fully.

> Streamlit uses **WebSockets**. Any internal proxy/ingress must keep **session
> affinity**, forward WebSocket upgrades, and use a long idle timeout — same as
> the connected deployments.

Sibling guides: [AWS.md](./AWS.md) · [AZURE.md](./AZURE.md)

---

## 1. Deployment architecture

Air-gap uses a **two-zone transfer** model:

| Zone | Role |
|------|------|
| **Connected staging zone** | Build the image, download wheels/OS packages, vendor web assets. Produces transfer bundles. |
| **Data diode / one-way transfer** | Move signed bundles across the boundary (removable media or approved transfer service). |
| **Air-gapped zone** | Private container registry mirror + private PyPI/OS mirrors. Runs the container on Docker/Podman, or Kubernetes with an internal ingress. No egress. |

Runtime facts are identical to the connected guides: `python:3.11-slim`,
non-root, `EXPOSE 8501`, `HEALTHCHECK /_stcore/health`, CMD
`streamlit run app.py --server.port $PORT --server.address 0.0.0.0 --server.headless true`.
The only durable artifact is `branding.json` on a writable/shared volume
(`BRANDING_FILE`). No database, no external secrets.

---

## 2. Topology

```
   CONNECTED STAGING ZONE                 │  AIR-GAPPED ZONE (no egress)
                                          │
  ┌───────────────────────┐              │  ┌──────────────────────────┐
  │ docker build → image  │              │  │ private registry mirror  │
  │ pip download → wheels │  docker save │  │  (docker load)           │
  │ vendor fonts/plotly   │  *.tar +     │  │ private PyPI (wheelhouse)│
  │ pull OS packages      │  wheelhouse  │  │ private OS pkg mirror    │
  └──────────┬────────────┘  + os-repo   │  └────────────┬─────────────┘
             │  signed bundles           │               │ pull
             ▼        ═══════════════════╪══► one-way ═══►│
       removable media / diode           │               ▼
                                          │  ┌──────────────────────────┐
                                          │  │ Docker / Podman  or  K8s │
                                          │  │  Streamlit app.py :8501  │
                                          │  │  branding.json → volume  │
                                          │  └────────────┬─────────────┘
                                          │               │ WSS (sticky)
                                          │        internal reverse proxy
                                          │        (oauth2-proxy → internal IdP)
```

---

## 3. Prerequisites

**Connected zone:**
- Docker/Podman, Python 3.11.9, and network access to PyPI, the OS package repo,
  and the Google Fonts / Plotly CDN sources you intend to vendor.
- A signing key/process approved for cross-boundary transfer.

**Air-gapped zone:**
- A **private container registry** (Harbor, Nexus, Artifactory, or a plain
  registry) reachable internally.
- A **private PyPI mirror** or a flat **wheelhouse** directory.
- A **private OS package mirror** (apt/yum) matching `python:3.11-slim` (Debian).
- Docker/Podman host **or** a Kubernetes cluster with an internal ingress that
  supports WebSockets + session affinity.
- A writable/shared volume for `branding.json`.
- (Optional auth) an internal IdP (LDAP/OIDC) + **oauth2-proxy**.

---

## 4. Identity & credentials

No cloud identity applies. Use internal mechanisms:

- **Registry auth:** robot/service account on the internal registry, scoped to
  pull the app image only.
- **Reverse-proxy auth (optional):** run **oauth2-proxy** in front of the app,
  federated to the internal IdP (LDAP/OIDC). The app has no auth of its own.
- **No static cloud keys** should ever enter the air-gapped zone. If an internal
  secret is required for oauth2-proxy, store it in the internal secrets manager
  (e.g. HashiCorp Vault deployed on-prem) or a Kubernetes Secret with restricted
  RBAC — never in the image or `render.yaml`.

Least-privilege posture: the app container needs **no credentials at all**. Only
the (optional) proxy holds the IdP client secret, read from the internal secrets
store.

---

## 5. Environment variables

Identical surface to the connected guides — no cloud-specific values apply.

| Variable | Example | Purpose |
|----------|---------|---------|
| `PORT` / `STREAMLIT_SERVER_PORT` | `8501` | Port Streamlit binds. |
| `STREAMLIT_SERVER_ADDRESS` | `0.0.0.0` | Bind all interfaces. |
| `STREAMLIT_SERVER_HEADLESS` | `true` | Container-safe startup. |
| `STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION` | `true` | Keep XSRF on. |
| `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` | `50` | Max CSV upload (MB). |
| `STREAMLIT_BROWSER_GATHER_USAGE_STATS` | `false` | **Mandatory** — no telemetry egress in an air-gap. |
| `BRANDING_FILE` | `/data/branding/branding.json` | Writable, mounted path for persisted branding. |

---

## 6. Configuration references — Streamlit `config.toml`

```toml
[server]
port = 8501
address = "0.0.0.0"
headless = true
enableXsrfProtection = true
enableCORS = false
maxUploadSize = 50

[browser]
gatherUsageStats = false      # REQUIRED: no outbound telemetry in air-gap

[global]
developmentMode = false
```

| Key | Value | Purpose |
|-----|-------|---------|
| `server.port` | `8501` | Container listen port. |
| `server.address` | `0.0.0.0` | Reachable internally. |
| `server.headless` | `true` | Container-safe startup. |
| `server.enableXsrfProtection` | `true` | CSRF protection. |
| `server.enableCORS` | `false` | Internal proxy is the trusted origin. |
| `server.maxUploadSize` | `50` | Guardrail on in-memory CSV size. |
| `browser.gatherUsageStats` | `false` | No outbound analytics — required offline. |

---

## Build & transfer bundles (connected zone)

### Container image

```bash
# Build in the connected zone, then export for transfer
docker build -t bid/business-insight-dashboard:1.0.0 .
docker save bid/business-insight-dashboard:1.0.0 -o bid-image-1.0.0.tar
sha256sum bid-image-1.0.0.tar > bid-image-1.0.0.tar.sha256    # sign for transfer
```

Import in the air-gapped zone and push to the internal registry mirror:

```bash
docker load -i bid-image-1.0.0.tar
docker tag  bid/business-insight-dashboard:1.0.0 registry.internal/bid/business-insight-dashboard:1.0.0
docker push registry.internal/bid/business-insight-dashboard:1.0.0
```

### Python wheelhouse (offline pip)

Only four dependencies. Download platform-matched wheels in the connected zone:

```bash
mkdir wheelhouse
pip download \
  --python-version 3.11 --only-binary=:all: \
  --platform manylinux2014_x86_64 \
  -d wheelhouse \
  "streamlit>=1.58.0" "pandas>=2.1.0" "plotly>=5.20.0" "numpy>=1.26.0"
tar czf bid-wheelhouse.tgz wheelhouse
```

Install offline in the air-gapped zone (this is what the offline Dockerfile
layer or host build runs):

```bash
pip install --no-index --find-links=/opt/wheelhouse \
  "streamlit>=1.58.0" "pandas>=2.1.0" "plotly>=5.20.0" "numpy>=1.26.0"
```

### OS packages

Mirror the Debian `python:3.11-slim` base packages into the internal apt mirror
so image rebuilds (and any `apt-get install` in the Dockerfile) resolve offline.
Prefer prebuilt images so no `apt-get` runs at deploy time.

### Manual update bundles

Ship every upgrade as a signed bundle containing: the new image tar, a refreshed
`bid-wheelhouse.tgz`, and updated vendored assets. Verify checksums/signatures on
arrival before `docker load` / registry push. Track versions in a change log.

---

## Offline asset vendoring

These must be handled or the UI degrades / breaks offline:

- **Google Fonts (from `modules/styles.py`):** the imported Google Fonts CSS URL
  will **fail with no egress**. Options:
  1. **Vendor the font**: download the font files + CSS in the connected zone,
     bake them into the image (e.g. under a static assets path), and change the
     reference in `styles.py` to the local/self-hosted path; **or**
  2. **Accept degradation**: leave it — the browser falls back to system fonts.
     Functionality is unaffected; only typography changes.
- **Plotly CDN assets:** Plotly may pull `plotly.js` / MathJax from a CDN.
  Ensure the offline-capable Plotly build is used (bundled JS) or self-host the
  assets internally so charts render fully. Test charts in the air-gapped zone
  before sign-off.

Explicitly verify both during [Verification](#7-verification): the page must
render without any failed cross-origin requests in the browser network tab.

---

## Self-hosted LLM via Ollama — optional / forward-looking ONLY

The current app **makes no AI or LLM calls** — all insights are **rule-based**.
Therefore **Ollama replaces nothing today** and is **not required** for any
current feature. It is documented here only as a *forward-looking* option: **if**
a future "AI narrative insights" feature is added, an on-prem **Ollama** server
(e.g. `ollama serve` on an internal host, GPU optional) would be the air-gap-safe
way to provide inference without any external API. Until such a feature exists,
do **not** deploy or depend on Ollama.

---

## 7. Verification

```bash
# Health endpoint returns "ok" (internal host)
curl -fsS http://<internal-host>:8501/_stcore/health     # -> ok
```

Manual checklist:

- [ ] `curl -fsS .../_stcore/health` prints `ok`.
- [ ] Container pulls from the **internal registry mirror** (no external pull).
- [ ] `pip install --no-index --find-links` resolves all four deps offline.
- [ ] Login **through the internal oauth2-proxy / IdP** works (if auth enabled).
- [ ] Dashboard opens over WSS with **no failed cross-origin requests** in the
      browser network tab (fonts + Plotly assets vendored or gracefully degraded).
- [ ] Upload a sample CSV from `sample_data/` — parses in memory.
- [ ] **KPIs, charts, and rule-based insights render**.
- [ ] Change branding → `branding.json` **written to the mounted volume**
      (`ls -l $BRANDING_FILE`).

---

## 8. Day-2 operations

- **Upgrades:** apply a signed **manual update bundle** — verify checksums,
  `docker load`, push to the internal registry, roll the deployment. Keep sticky
  sessions during the swap.
- **Scaling:** scale replicas; the app is stateless except `branding.json`.
  **Session affinity is mandatory** for WebSockets. Use a shared internal volume
  (NFS/CSI `ReadWriteMany`) so branding is consistent across replicas.
- **Backups:** snapshot the `branding.json` volume on your internal storage
  schedule. No database; uploaded CSVs are ephemeral.
- **Cert/secret rotation:** rotate the internal TLS cert on the proxy and the
  oauth2-proxy IdP secret from the internal secrets store; no external CA needed.
- **Logs:** ship container logs to the **internal** log stack (e.g. on-prem
  Elastic/Loki/syslog). No cloud log egress.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Dashboard connects then disconnects / stuck spinner | Internal proxy not **sticky** or short idle timeout | Enable session affinity; raise idle timeout; forward WebSocket upgrade headers. |
| Health check 404 | Wrong probe path | Use exactly `/_stcore/health`. |
| Instance/pod **unhealthy** | Wrong port/path or slow boot | Probe port **8501**, path `/_stcore/health`; increase grace period. |
| Branding lost after restart/scale | `branding.json` on **ephemeral** storage | Mount a persistent/shared volume (`ReadWriteMany`); set `BRANDING_FILE`. |
| Plain/wrong fonts, or console shows blocked font request | Google Fonts URL unreachable offline | Vendor the font and repoint `styles.py`, or accept the system-font fallback. |
| Charts blank or partial | Plotly CDN assets blocked | Use the bundled Plotly JS or self-host the assets internally. |
| `pip` tries to reach the internet | Missing `--no-index` / wrong wheelhouse path | Use `pip install --no-index --find-links=/opt/wheelhouse ...`. |
| Image pull fails | Not present in internal registry, or wrong tag | `docker load` the transferred tar and push to the internal registry; use the internal tag. |
| Upload rejected as too large | `maxUploadSize` too low | Raise `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` / `server.maxUploadSize`. |

---

*See also: [AWS.md](./AWS.md), [AZURE.md](./AZURE.md).*
