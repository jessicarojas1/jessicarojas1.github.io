# Air-Gapped / Offline Deployment — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent**
(`cmmc-agent/`) in a **fully offline / air-gapped** enclave — the deployment
model that most directly fits CUI/CMMC Level 2 boundaries, which typically
**cannot egress to `api.anthropic.com`**.

**This guide centers on [Ollama](https://ollama.com) as the on-prem replacement
for the hosted Anthropic API.** Instead of calling the hosted Claude backend,
you run a self-hosted LLM on-prem and repoint the app at it.

> The app is a single synchronous Flask process: a web GUI + an agentic backend
> that assesses/tracks/closes gaps across all 110 NIST 800-171 practices for
> CMMC Level 2. There is **no database, no object storage, no server-side
> document upload, and no login/auth**. Persistent state is two local JSON files
> (`status.json`, `settings.json`).

**Siblings:** [AWS.md](AWS.md) · [AZURE.md](AZURE.md) ·
[KUBERNETES.md](KUBERNETES.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md)
**Canonical guide:** [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

> **Honesty note:** the app is written against the **Anthropic SDK** with the
> model string **hardcoded to `claude-opus-4-5`**. Switching the AI backend to
> Ollama is a **small but real code change**, not a pure env-var swap. See §1
> and the Ollama section for the two supported approaches.

---

## 1. Deployment architecture

- **Compute:** two containers inside the enclave — the `cmmc-agent` Flask app
  and an **Ollama** inference server — on a Docker host (or an offline
  Kubernetes cluster, see [KUBERNETES.md](KUBERNETES.md)).
- **AI backend:** **Ollama** replaces `api.anthropic.com`. There is **no
  outbound internet**. Two supported repoint approaches:
  - **(a) Anthropic-compatible proxy:** stand up a small proxy that speaks the
    Anthropic Messages API in front of Ollama, and set **`ANTHROPIC_BASE_URL`**
    to that proxy. The SDK is used unchanged, but the model string is still
    `claude-opus-4-5` unless the proxy maps it. **Requires a proxy component
    (and usually a model-name mapping).**
  - **(b) Direct code change:** modify `server.py` / `agent.py` to call Ollama's
    **OpenAI-compatible** endpoint `http://ollama:11434/v1` and change the model
    name to a local model (e.g. `llama3.1:8b`). **This is a source change.**
  - Either way it is **not zero-code** — approach (a) needs a proxy + mapping;
    approach (b) edits the two Python files.
- **Secret:** with Ollama, **`ANTHROPIC_API_KEY` may not be needed at all**
  (Ollama requires no key). If your proxy or a partial hosted path still needs a
  key, deliver it via an **internal Vault / sealed secret**, never over the
  internet. The value is placed in the enclave-local `.env` or the orchestrator
  secret store.
- **State:** `status.json` + `settings.json` on a **local persistent volume**
  (bind mount or PVC). No DB, no object store.
- **Registry:** an **internal container registry mirror** holds the
  `cmmc-agent` image and the `ollama/ollama` image; nothing is pulled from the
  public internet.

---

## 2. Topology (fully offline)

```
        ── Air-gapped enclave (no internet egress) ─────────────────────────────

 ┌──────────┐  HTTPS/HTTP (internal)
 │ Operator │ ─────────►┌──────────────────────────────┐
 │ browser  │           │ cmmc-agent (Flask, uid 10001)│  health: GET /api/dashboard
 └──────────┘           │ python server.py :5050        │
                        │                               │
                        │  /app/status.json  ◄──────────┼── local volume (persistent state)
                        │  /app/settings.json           │
                        │                               │
                        │  AI backend call ─────────────┼───────────┐
                        └───────────────────────────────┘           │ internal only
                                                                     ▼
                                                    ┌────────────────────────────┐
                                                    │ Ollama (ollama serve)       │
                                                    │ :11434  /v1 (OpenAI-compat) │
                                                    │ model: llama3.1:8b / 70b /  │
                                                    │        mixtral (GPU or CPU) │
                                                    └────────────────────────────┘

  Registry mirror ── holds cmmc-agent image + ollama image (docker load from bundle)
  Internal Vault ─── (optional) delivers any secret; usually NONE needed with Ollama
  NO connection to api.anthropic.com
```

State is **local JSON on a persistent volume**. There is **no DB and no object
storage** — do not provision or expect them.

---

## 3. Prerequisites

- A Docker host (or offline Kubernetes cluster) inside the enclave, with a
  persistent volume for state.
- An **internal container registry** reachable from the enclave.
- Transfer media (approved for the boundary) to carry image bundles and wheels
  across the air gap.
- **For GPU acceleration:** an NVIDIA GPU host with drivers + the
  **nvidia-container-toolkit** installed (see GPU note below). CPU-only works but
  is slower.
- Sufficient disk/VRAM for the chosen Ollama model (see model/VRAM guidance).

---

## 4. Identity & credentials (offline)

- **No cloud IAM / managed identity** exists in an air-gapped enclave; identity
  is enclave-local.
- **The one secret, `ANTHROPIC_API_KEY`, is frequently *not required*** when the
  AI backend is Ollama (Ollama needs no API key). Prefer removing the hosted
  dependency entirely.
- If a key is still required (e.g. an Anthropic-compatible proxy that
  authenticates), deliver it **only** via an internal secret mechanism:
  - **HashiCorp Vault (internal)**, a Kubernetes **sealed secret**, or an
    enclave-local `.env` mounted read-only (`.env` is gitignored — never commit
    it).
  - Rotate by replacing the value in the internal store and restarting the app
    container.
- No static keys are baked into images.

---

## 5. Environment variables

### With Ollama (approach a — proxy via `ANTHROPIC_BASE_URL`)

| Variable | Example | Purpose |
|---|---|---|
| `ANTHROPIC_BASE_URL` | `http://anthropic-proxy:8080` | Points the Anthropic SDK at the on-prem proxy in front of Ollama |
| `ANTHROPIC_API_KEY` | `local-not-used` / from internal Vault | SDK still constructs a client; the proxy may ignore/validate it. Without *some* value, `POST /api/chat` returns HTTP 500 `{"error":"ANTHROPIC_API_KEY not set"}` |
| `PORT` | `5050` | Container listen port |

### With Ollama (approach b — direct OpenAI-compatible code change)

| Variable | Example | Purpose |
|---|---|---|
| `PORT` | `5050` | Container listen port |
| `OLLAMA_BASE_URL` | `http://ollama:11434/v1` | Consumed by your modified `server.py`/`agent.py` calling the Ollama OpenAI-compatible endpoint |
| `OLLAMA_MODEL` | `llama3.1:8b` | Local model name your modified code passes instead of `claude-opus-4-5` |

> Note: `OLLAMA_BASE_URL` / `OLLAMA_MODEL` are **not** read by the shipped code —
> they only take effect once you make the approach-(b) source change described
> below.

---

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Health probe path | `/api/dashboard` | JSON score endpoint, no API key required (works even before AI backend is wired) |
| App port | `5050` | Matches `EXPOSE 5050` / `PORT` default |
| State volume mount | `/app` (or `/app/state`) | Persists `status.json` + `settings.json` |
| Ollama endpoint | `http://ollama:11434` (`/v1` for OpenAI-compat) | On-prem inference server |
| Ollama model | `llama3.1:8b` \| `llama3.1:70b` \| `mixtral` | Choose by GPU VRAM / context needs |
| Internal registry | `registry.enclave.local/cmmc-agent:<tag>` | Mirror for app + ollama images |
| GPU runtime | `--gpus all` (Docker) / `nvidia` runtime | Enables GPU acceleration for Ollama |

---

## Bringing artifacts across the air gap

**Container images (`docker save` / `docker load`):** on a connected build host,
build/pull the images, export them, transfer, and load inside the enclave.

```bash
# Connected side
docker build -t registry.enclave.local/cmmc-agent:1.0 ./cmmc-agent
docker pull ollama/ollama:latest
docker save registry.enclave.local/cmmc-agent:1.0 ollama/ollama:latest \
  -o cmmc-bundle.tar

# Air-gapped side
docker load -i cmmc-bundle.tar
docker push registry.enclave.local/cmmc-agent:1.0     # into internal mirror
docker push registry.enclave.local/ollama:latest
```

**Offline Python wheels:** the **Dockerfile already builds wheels in a builder
stage** (`pip wheel --wheel-dir /wheels -r requirements.txt`) and installs the
runtime stage with `--no-index --find-links=/wheels`. So once the
`python:3.11.9-slim` base image and the wheels are mirrored/bundled, the image
**builds and installs entirely offline**. If you must build inside the enclave,
pre-populate a wheelhouse on a connected host:

```bash
pip download -d ./wheelhouse -r cmmc-agent/requirements.txt   # connected host
# transfer ./wheelhouse, then build with a local --find-links=./wheelhouse
```

Dependencies to mirror: `anthropic>=0.40.0`, `python-dotenv>=1.0.0`,
`rich>=13.0.0`, `flask>=3.0.0` (plus their transitive wheels).

**Offline OS package feeds:** the runtime stage installs only `curl` (for the
healthcheck) via `apt-get`. Mirror the Debian slim package feed into an internal
APT mirror, or pre-bake `curl` into a local base image so the build needs no
package internet.

**Offline model:** pull the Ollama model on a connected host and copy the model
store (`~/.ollama/models` / the mounted Ollama volume) into the enclave, or run
`ollama pull` against an internal model mirror. No internet pull inside the
enclave.

**Offline CVE / update feeds & manual update bundles:** ingest CVE/vulnerability
data via an offline feed (e.g. mirrored NVD/OS-vendor advisories) on your
scanning host, not from the app. Ship application/base-image updates as new
`docker save` bundles through the same transfer process (§ above); there is no
auto-update path in the app.

---

## Ollama section (the on-prem AI backend)

Run Ollama on-prem and pull a model sized to your hardware:

```bash
ollama serve                     # starts the server on :11434
ollama pull llama3.1:8b          # small/fast; ~8 GB VRAM
# or
ollama pull llama3.1:70b         # higher quality; ~40 GB+ VRAM (or quantized)
ollama pull mixtral              # larger effective context / MoE
ollama list                      # confirm the model is present
```

**Repointing the app (required — not zero-code):** the shipped code uses the
Anthropic SDK with model `claude-opus-4-5`. Choose one:

- **(a) Anthropic-compatible proxy:** deploy a proxy that accepts Anthropic
  Messages API calls and forwards to Ollama, then set `ANTHROPIC_BASE_URL` to
  the proxy. The SDK stays as-is, but you must map `claude-opus-4-5` → your
  Ollama model in the proxy. Minimal app change, but you must run/operate the
  proxy.
- **(b) Direct edit of `server.py` / `agent.py`:** replace the
  `anthropic.Anthropic(...)` client calls with calls to Ollama's
  OpenAI-compatible endpoint `http://ollama:11434/v1`, and swap the model string
  from `claude-opus-4-5` to e.g. `llama3.1:8b`. Tool-use/agentic message
  formatting must be adapted to the target API. This is a small but real source
  change.

### docker-compose (cmmc-agent + Ollama)

```yaml
services:
  ollama:
    image: registry.enclave.local/ollama:latest
    command: ["serve"]
    ports:
      - "11434:11434"
    volumes:
      - ollama-models:/root/.ollama          # persists pulled models offline
    # GPU acceleration (requires nvidia-container-toolkit on the host):
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: ["gpu"]

  cmmc-agent:
    image: registry.enclave.local/cmmc-agent:1.0
    depends_on:
      - ollama
    ports:
      - "5050:5050"
    environment:
      PORT: "5050"
      # Approach (a): ANTHROPIC_BASE_URL: "http://anthropic-proxy:8080"
      #               ANTHROPIC_API_KEY: "local-not-used"
      # Approach (b): OLLAMA_BASE_URL: "http://ollama:11434/v1"
      #               OLLAMA_MODEL: "llama3.1:8b"   (only after code change)
    volumes:
      - cmmc-state:/app                        # persists status.json + settings.json
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://127.0.0.1:5050/api/dashboard"]
      interval: 30s
      timeout: 5s
      retries: 3

volumes:
  ollama-models:
  cmmc-state:
```

> Remove the `deploy.resources` GPU block for CPU-only hosts.

### GPU note

- **Acceleration:** an **NVIDIA GPU** with matching **drivers** and the
  **nvidia-container-toolkit** lets Ollama run on-GPU (`--gpus all` in Docker,
  the `nvidia` device block in compose).
- **CPU fallback:** without a GPU, Ollama still runs on CPU — **noticeably
  slower**, especially for larger models.
- **VRAM guidance:** roughly **8B ≈ 8 GB VRAM**, **70B ≈ 40 GB+ VRAM** (or use
  a quantized build to fit smaller cards). Pick the model that fits your
  hardware; smaller models trade quality for speed/footprint.

---

## 7. Verification

**1. Health / dashboard (no key, works offline immediately):**

```bash
curl -fsS http://<host>:5050/api/dashboard
# → {"overall_score_pct": <N>, "domains": { ... }}
```

**2. Ollama model present:**

```bash
docker exec -it <ollama-container> ollama list
# → lists llama3.1:8b (or your chosen model)
```

**3. Chat after the repoint (approach a or b wired):**

```bash
curl -sS -X POST http://<host>:5050/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"history":[{"role":"user","content":"score my program"}]}'
# Expected: {"reply": "...", "tool_log": [...]}  (served by the local model)
# If HTTP 500 {"error":"ANTHROPIC_API_KEY not set"} → the SDK path still needs a
#   value (approach a) OR the code was not repointed to Ollama (approach b).
```

**4. State write proven (status.json persisted, no DB/S3 involved):**

```bash
curl -sS http://<host>:5050/api/dashboard | grep -o 'overall_score_pct":[0-9]*'
curl -sS -X POST http://<host>:5050/api/mark \
  -H 'Content-Type: application/json' \
  -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"offline verify"}'
curl -sS http://<host>:5050/api/dashboard | grep -o 'overall_score_pct":[0-9]*'
```

A changed score, persisting across a container restart on the mounted volume,
proves `status.json` is written locally.

---

## 8. Day-2 operations

- **Upgrades:** build new images on a connected host, `docker save` a bundle,
  transfer, `docker load`, push to the internal registry, and restart the
  services. Same path for Ollama upgrades and new model bundles.
- **Model updates:** pull new Ollama models on a connected host and carry the
  model store across; `ollama list` to confirm.
- **Scaling caveat:** state is **local JSON** — run a **single app instance**
  (one writer) or a shared/persistent volume with a single writer. Do not run
  multiple app replicas against divergent state. Scale the *inference* tier
  (GPU/host size) independently of the app.
- **Backups:** back up the **state volume** (`status.json` + `settings.json`) —
  the entire recoverable state — plus the **Ollama model volume** so you can
  restore without re-transferring models.
- **Secret rotation:** usually N/A with Ollama (no key). If a key is used,
  rotate it in the internal Vault/sealed secret and restart the app container.
- **Logs:** both containers log to stdout/stderr; collect with your enclave log
  stack. Watch the app for the 500 `ANTHROPIC_API_KEY not set` line.
- **Database migrations:** **none exist** — no database, no schema.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `/api/dashboard` unreachable | App container not up / wrong port | Confirm container running, port 5050 published, path exactly `/api/dashboard` |
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set` | Using the SDK path with no key (approach a) or code not repointed (approach b) | Set a value for the proxy path, or apply the approach-(b) code change to call Ollama |
| Chat hangs / times out | Ollama not reachable or model not pulled | `ollama list` to confirm model; check `http://ollama:11434` connectivity between containers |
| Chat extremely slow | CPU-only inference or oversized model for VRAM | Add GPU + nvidia-container-toolkit, or pick a smaller/quantized model |
| Ollama fails to start on GPU | Missing drivers / nvidia-container-toolkit | Install NVIDIA drivers + toolkit; or drop the GPU block to run CPU-only |
| Image pull fails in enclave | Not mirrored to internal registry | `docker load` from bundle and push to `registry.enclave.local` |
| Offline build fails on `pip` | Wheels/base image not mirrored | Bundle `python:3.11.9-slim` + wheelhouse; build with `--no-index --find-links` (Dockerfile already does this) |
| Score resets on restart | State volume not mounted/persistent | Mount a persistent volume at the app state path and back it up |
| Score inconsistent | Multiple app instances on divergent state | Run a single app writer |

---

*Return to the canonical deployment guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).*
