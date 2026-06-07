# Continuous Integration

This monorepo hosts several heterogeneous apps (a static GitHub Pages site,
Node/Express + static SPAs, a Vite React/TS frontend, a Next.js TS app, PHP apps,
and Python/Streamlit + FastAPI services). CI is defined in
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

It runs on every **pull request** and on **push to `main`**, with one **isolated
job per app** so a failure in one app does not mask the others. Runs are pinned
to specific action versions, cache npm, and use concurrency cancellation so
superseded runs are cancelled automatically.

## Jobs

| Job             | Stack                | What it does                                                                 |
| --------------- | -------------------- | --------------------------------------------------------------------------- |
| `node-citadel`  | Node 20              | `npm ci` in `citadel/server`; `node --check` over server + `citadel/js`; smoke test |
| `ts-sentinel`   | Node 20              | `npm ci`; `tsc --noEmit`; `vitest run`; ESLint (non-blocking, see below)     |
| `ts-compliance` | Node 20              | `npm ci`; `tsc --noEmit`                                                      |
| `php-lint`      | PHP 8.2              | `php -l` over every `.php` in `aegis/` and `apex/`                            |
| `py-lint`       | Python 3.12          | `py_compile` over `business-insight-dashboard`, `sentinel-qms/backend`, `aeromarkup`, `cmmc-agent` |

### Known non-blocking step

- **`ts-sentinel` → Lint** is marked `continue-on-error`. The repo does not yet
  ship an ESLint config, so `npm run lint` (`eslint . --ext ts,tsx`) currently
  errors with *"ESLint couldn't find a configuration file"*. Once an ESLint
  config is added under `sentinel-qms/frontend/`, remove `continue-on-error`
  from that step to make lint blocking.

## Run the checks locally

### CITADEL (Node)
```bash
cd citadel/server && npm ci
bash citadel/scripts/ci-node-check.sh   # node --check server + citadel/js (run from repo root)
cd citadel/server && npm test           # smoke test
```

### Sentinel QMS frontend (Vite React/TS)
```bash
cd sentinel-qms/frontend && npm ci
npm run typecheck    # tsc --noEmit
npm run test         # vitest run
npm run lint         # currently fails: no eslint config yet
```

### Compliance Copilot (Next.js/TS)
```bash
cd compliance-copilot && npm ci
npx tsc --noEmit
```

### PHP apps (aegis, apex)
```bash
find aegis apex -name '*.php' -print0 | xargs -0 -r -n1 -P4 php -l
```

### Python apps (Streamlit dashboard, FastAPI backend, etc.)
```bash
find business-insight-dashboard sentinel-qms/backend aeromarkup cmmc-agent \
  -name '*.py' -not -path '*/__pycache__/*' -print0 \
  | xargs -0 -r python -m py_compile
```

> Note: `py-lint` is a **compile-check only** — it confirms sources parse without
> standing up FastAPI/Streamlit runtime dependencies. The standalone CITADEL
> security-scan workflow example lives at `citadel/.github-workflow-example.yml`
> and is independent of this CI pipeline.
