# VERITY — Air-Gapped / Offline Deployment

Operator guide for running Verity with no path to the public internet. This
build has very little that depends on internet connectivity at runtime — it
is a plain PHP + PostgreSQL application with zero Composer dependencies and
no frontend build step — so the air-gapped path is mostly about transferring
the Docker image and the Postgres data store offline, not reworking the app.

## 1. What this build actually depends on today

Checked against the real code rather than assumed:

- **No AI/LLM features exist in this build.** There is nothing to route to
  Ollama or any other inference service — §6 below is a placeholder for a
  *hypothetical* future need, not a present dependency. Do not provision
  Ollama for this deployment unless/until an AI feature actually ships.
- **No live external API calls anywhere in the code, with exactly one
  exception you must disable here.** The GCC High connector
  (`connector_type='entra_gcc_high_mock'`) is a catalog/config entry with a
  capability manifest — there is no Microsoft Graph HTTP call in `app/`.
  Nothing needs outbound access to `graph.microsoft.us` or
  `login.microsoftonline.us` for this build to function. The one real
  exception: `Auth::isPasswordBreached()` calls the public Have I Been
  Pwned range API (`api.pwnedpasswords.com`) on every password set
  (self-service change, admin create, admin reset) unless disabled. It
  fails open (never blocks a password change) if that host is unreachable,
  but an enclave with no outbound internet should set
  **`PASSWORD_BREACH_CHECK_ENABLED=false`** explicitly rather than rely on
  the fail-open behavior — the fail-open path exists for an *unexpected*
  outage, not as the intended way to run this check off permanently.
- **The CSP allows, but nothing actually loads, Google Fonts.**
  `public/index.php` sets `font-src 'self' https://fonts.gstatic.com` and
  `style-src ... https://fonts.googleapis.com` in its Content-Security-Policy,
  but no view (`app/Views/partials/app_header.php`, `app/Views/auth_login.php`)
  contains a `<link>` to Google Fonts — the CSS (`public/assets/app.css`)
  uses only the system font stack (`-apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, ...`). **This CSP allowance can be safely dropped** for
  an air-gapped deployment (or left in place — it's inert either way, since
  no outbound font request is ever made).
- **No CDN, no Node build step.** `public/assets/*` is hand-rolled, checked
  into the repo, and served as-is; there is nothing to vendor.

## 2. Deployment architecture

Identical to `SINGLE_LINUX_SERVER.md` or `KUBERNETES.md` inside the enclave —
the only difference is how the image and the Postgres instance get there.

## 3. Prerequisites

- An in-enclave container runtime (Docker/Podman or a Kubernetes cluster) and
  an in-enclave private registry (e.g., Harbor) to hold the Verity image.
- PostgreSQL 14+ running inside the enclave (no managed cloud service is
  reachable, by definition).
- A one-way transfer mechanism (data diode, removable media, or an
  equivalent approved process) for moving the built image and the schema
  file into the enclave.
- Set `PASSWORD_BREACH_CHECK_ENABLED=false` in the enclave's environment
  before go-live — see §1 above.

## 4. Offline image transfer

```bash
# On a connected build host:
docker build -t verity:<tag> .
docker save verity:<tag> -o verity-<tag>.tar
# Transfer verity-<tag>.tar into the enclave via your approved process, then:

# Inside the enclave:
docker load -i verity-<tag>.tar
docker tag verity:<tag> <in-enclave-registry>/verity:<tag>
docker push <in-enclave-registry>/verity:<tag>
```
Because the app has **zero Composer dependencies** (`composer.json` only
declares the autoload mapping; `app/bootstrap.php`'s fallback PSR-4
autoloader runs with no `vendor/` directory), there is no `composer install
--no-dev` vendoring step needed before the transfer — the image already
contains everything the app needs to run.

## 5. Offline PostgreSQL

- Stand up PostgreSQL 14+ inside the enclave from your own offline package
  mirror or a pre-pulled `postgres` image.
- Apply the schema the same way as any other target:
  ```bash
  psql "$DATABASE_URL" -f database/schema.sql
  ```
  (idempotent — `CREATE TABLE IF NOT EXISTS` throughout, so re-running it is
  safe if you re-apply after a partial transfer).
- `database/seed.php --force` works offline exactly as it does locally (it
  only talks to the configured `DATABASE_URL`) if synthetic demo data is
  wanted inside the enclave; it still refuses to run when `APP_ENV=production`.

## 6. Self-hosted LLM (Ollama) — not applicable today

Verity has no AI assistant, summarization, search-ranking, or any other
LLM-backed feature in this build. If a future phase adds one, the standing
project rule is that it must run as a **self-hosted model via Ollama**
inside the enclave rather than calling a hosted AI API — recorded here only
so this file does not need a structural rewrite when that day comes. There
is nothing to install or configure for this today.

## 7. Verification

```bash
curl -fsS https://<in-enclave-host>/health
# {"status":"ok","app":"verity","db":true}
```
- Confirm the container starts and serves `/health` with **no outbound
  network access at all** — the app should function identically with egress
  fully denied, since nothing in this build calls out to the internet (see
  §1).
- Sign in at `/auth/login` with a seeded or provisioned account; confirm
  dashboard KPIs render.
- Confirm the browser console shows no failed requests to
  `fonts.googleapis.com`/`fonts.gstatic.com` (there shouldn't be any attempt
  to begin with — see §1).

## 8. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Page looks unstyled/fails to load CSS | nginx/ingress inside the enclave misconfigured for `/assets/*`, not a missing external font | Confirm `/assets/app.css` is served from the same origin — nothing here depends on an external font load |
| Image won't load into the in-enclave registry | Transfer corrupted or registry auth misconfigured | Re-run `docker save`/`docker load`; verify checksums across the transfer boundary |
| `/health` returns `"db":false` | `DATABASE_URL` not set for the in-enclave Postgres instance | Same check as any other target — this is unrelated to network isolation |
| Someone asks about the AI assistant | Feature does not exist in this build | Point to `OPEN_ITEMS.md`/the phase roadmap — Phase 5+ work, not implemented |
