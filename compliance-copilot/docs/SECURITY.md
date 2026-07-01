# Security — Compliance Copilot

Security guide for Compliance Copilot, a CMMC L2 / NIST SP 800-171 readiness
platform. Because the app manages **CUI-adjacent compliance data** (assessment
status, evidence, POA&M), the security model is deliberately conservative:
secrets stay server-side, the AI relay fails closed, and the database grants only
reads to authenticated callers.

---

## 1. Identity & authentication

- **In-app login (single-tenant).** Username/password checked server-side with a
  constant-time comparison (`verifyCredentials` in `lib/session.ts`) against
  `APP_AUTH_USERNAME` / `APP_AUTH_PASSWORD`. On success the server mints a
  stateless **HMAC-SHA256 session cookie** (`cc_session`), 8-hour TTL, signed with
  `APP_SESSION_SECRET`. The token carries no secret — only proof of login.
- **Cookie hardening:** `HttpOnly`, `SameSite=Strict`, `Secure` in production,
  `Path=/`, `Max-Age` = 8h.
- **Middleware gate:** `middleware.ts` (Edge runtime, Web Crypto verifier) redirects
  unauthenticated page requests to `/login?next=<relative-path>` when session auth
  is configured. It is a UX gate; API routes are the real boundary. Login page,
  API routes, and static assets are excluded from the gate.
- **Brute-force resistance:** `POST /api/auth/login` applies a per-IP in-memory
  rate limit (10 attempts/min → 429). Pair with a WAF for production.
- **Supabase Auth** may additionally back the `authenticated` RLS role for direct
  DB access. Login credentials are env vars, not stored in the database.

---

## 2. Authorization

- **Database (RLS).** All tables (`controls`, `evidence`, `poam_items`,
  `app_settings`) have Row Level Security **enabled**. Only `SELECT` is granted to
  the `authenticated` role. **No write policies** exist for `authenticated`, so a
  stolen anon/user token cannot mutate compliance data directly.
- **Writes go through the server.** Every insert/update/delete is performed by a
  route handler using the **service-role** client (`createServiceClient()` /
  `serviceClient()`), which bypasses RLS. The service-role key is never exposed to
  the browser.
- **AI relay authorization.** `POST /api/ai/generate` requires **one of**:
  - a valid session cookie **plus** an `x-requested-with` header (CSRF defense on
    top of `SameSite=Strict`), or
  - the shared `AI_PROXY_TOKEN` via `Authorization: Bearer` / `x-api-key`
    (constant-time compared).

  **Fail-closed:** in production, if no token is configured and there is no valid
  session, every request is rejected with 503 — the relay never becomes an open,
  unauthenticated AI proxy (OWASP LLM10 / CWE-770).
- **Branding write.** `PUT /api/settings/branding` is gated by `BRANDING_ADMIN_TOKEN`
  when set (prevents anonymous branding defacement in shared deployments).

---

## 3. Data protection

- **In transit:** HTTPS/TLS end-to-end (platform or nginx/ingress). Supabase and
  Anthropic are reached over TLS. The session cookie is `Secure` in production.
- **At rest:** Supabase Postgres and Storage are encrypted at rest by the provider
  (or by disk encryption / KMS in self-hosted). Secrets live in a secret manager,
  not in the image or repo.
- **Key management:** the service-role key and AI key are held only in server env /
  secret manager and injected at runtime. NEXT_PUBLIC_* values (anon key, URL) are
  non-secret and browser-safe by design.
- **Input sanitization:** branding logo URLs are restricted to `http(s)://` or
  `data:image/...` (blocks `javascript:`, `file:`, etc.); accent color to
  `#rgb`/`#rrggbb`; display name has control chars stripped and length capped —
  enforced on **both** client and server (`lib/branding.ts`).
- **AI cost/DoS controls:** per-identity fixed-window rate limit (20/min), prompt
  cap `MAX_PROMPT_CHARS=8000`, server-fixed `max_tokens=1024` (never client-set).
  Upstream error bodies are never forwarded to clients. The provider and model are
  server config (`AI_PROVIDER`/`AI_MODEL`/`OLLAMA_*`), never client input.
- **Evidence uploads:** `POST /api/evidence/upload` runs server-side with the
  service-role key. It requires a valid session (+ `x-requested-with` CSRF header,
  fail-closed in production), enforces an extension **and** MIME allowlist, a 25 MB
  size cap, and stores objects under a **randomized** name (never the client
  filename) in a **private** bucket. Files are returned only via short-lived (1 h)
  **signed URLs** — the bucket is never public.

---

## 4. Auditability

- **Current state:** API route handlers emit **structured JSON logs** (`lib/logger.ts`)
  with a per-request `req_id` (honoring an inbound `x-request-id`/`x-correlation-id`
  header) — auth outcomes, AI-relay decisions (auth path, rate-limit, upstream
  status), evidence uploads, and health checks. Verbosity via `LOG_LEVEL`. Secrets
  and prompt/response bodies are never logged. These sit alongside Supabase Postgres
  logs, Storage access logs, and the platform's request logs. `updated_at` triggers
  record each row's last-modified time.
- **Gaps:** there is **no in-app, per-control change-history / audit trail** yet
  (tracked in [OPEN_ITEMS.md](../OPEN_ITEMS.md) and README v2). For a system of
  record, add an append-only audit table capturing who/what/when for control and
  POA&M mutations, and ship logs to a WORM/SIEM store.
- Login attempts and AI-relay rejections are visible in server logs; centralize
  them for monitoring.

---

## 5. Classification & DLP (CUI)

- Compliance Copilot handles **CUI-adjacent** data: control implementation
  statements, evidence, and POA&M items describing your security posture.
  Treat the entire deployment as CUChandling scope.
- **Storage:** use a **private** evidence bucket; serve files only via short-lived
  signed URLs. Do not make the bucket public.
- **AI boundary:** prompts sent to the AI relay may include control text /
  implementation details. For CUI or air-gapped environments, **do not** send data
  to the hosted Anthropic API — switch the relay to a self-hosted **Ollama** model
  (see [DEPLOYMENT.md §7](./DEPLOYMENT.md#7-ollama-configuration-self-hosted--air-gapped-ai)).
- **Egress control:** in restricted enclaves, block outbound internet from the app
  tier except to the sanctioned Supabase / AI endpoints.

---

## 6. FIPS readiness

- Session signing uses **HMAC-SHA256** (FIPS-approved algorithm) via Node `crypto`
  (route handlers) and Web Crypto (`crypto.subtle`, Edge middleware). No custom or
  non-approved crypto is used.
- For a FIPS-validated posture: run on a platform with a FIPS 140-2/3 validated
  crypto module (FIPS-mode OS / OpenSSL), terminate TLS with FIPS-approved ciphers,
  and on AWS GovCloud / Azure Government use **FIPS regional endpoints** for any
  KMS/Secrets/Storage integrations. See the AWS/Azure deployment guides for
  partition and endpoint specifics.

---

## 7. Operator responsibilities

- Set `APP_SESSION_SECRET` to a long random value; enable the login gate
  (`APP_AUTH_USERNAME`/`APP_AUTH_PASSWORD`) for any shared deployment.
- Set `AI_PROXY_TOKEN` so the relay is not left open, and `BRANDING_ADMIN_TOKEN`
  to prevent anonymous branding changes.
- Keep secrets in a manager; never commit `.env.local`; never `NEXT_PUBLIC_`-prefix
  a secret.
- Enforce TLS + HSTS; keep the evidence bucket private; enable Supabase PITR.
- Front the app with a WAF / gateway rate limit (the in-app limiter is per-instance,
  best-effort).
- Patch dependencies (`npm audit`), rebuild the image, and redeploy on a cadence.

---

## 8. Secrets rotation

| Secret | Rotate when / cadence | How |
|---|---|---|
| `SUPABASE_SERVICE_ROLE_KEY` | Compromise, offboarding, ≤ annually | Supabase → Project Settings → API → roll key; update secret manager; redeploy |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | With service-role roll | Rebuild (value is inlined at build) + redeploy |
| `ANTHROPIC_API_KEY` | Compromise, ≤ annually | Rotate in Anthropic console; update env; redeploy |
| `AI_PROXY_TOKEN` | Any suspected leak | Set new random value; update programmatic callers |
| `APP_SESSION_SECRET` | Compromise (invalidates all sessions), periodically | New random value; users must re-login |
| `APP_AUTH_PASSWORD` | Personnel change, policy interval | Update env; redeploy |
| `BRANDING_ADMIN_TOKEN` | As needed | New random value; update admins |

Rotating a secret requires updating the secret store and redeploying (NEXT_PUBLIC_*
also require a rebuild). Rotating `APP_SESSION_SECRET` immediately invalidates all
existing sessions — expected during incident response.

---

## 9. Reporting

- **Vulnerability disclosure:** report suspected vulnerabilities privately to the
  project maintainer / security contact for this deployment (do not open a public
  issue with exploit details). Include affected version/commit, reproduction, and
  impact.
- **Target SLA:** acknowledge ≤ 3 business days; triage + remediation plan ≤ 10
  business days for high/critical; coordinated disclosure thereafter.
- **Operator incidents** (leaked key, unauthorized access): rotate the affected
  secret(s) per §8 immediately, review Supabase + platform logs, and if data
  exposure is suspected follow your organization's CUI incident-response and
  breach-notification process.
