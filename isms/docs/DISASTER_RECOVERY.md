# ISMS Document Library — Disaster Recovery

**Applicability:** a **Type A static website** with **no backend, no database, and
no server-side state**. The entire application is **rebuildable from git**, so DR
is fast and simple: restore the source, republish the static files. The only
non-source state is **per-browser `localStorage`** (theme + branding), which is
intentionally not centrally recoverable.

## 1. What holds state

| State | Where | Authoritative source | Recoverable? |
|-------|-------|----------------------|--------------|
| Application source (all HTML/CSS/JS) | git repo `jessicarojas1.github.io` (`isms/` + shared `../` assets) | **git** | ✅ fully |
| Deployed artifact | static host / object store / CDN | rebuilt from git | ✅ redeploy |
| Hosting config (headers, TLS, cache) | host/CDN + `nginx.conf`/`render.yaml` in repo | **git** (config-as-code) | ✅ reapply |
| Theme preference | `localStorage['bsTheme']` (per browser) | the user's browser | ❌ per-browser only |
| Branding (name/logo/accent) | `localStorage['isms_branding']` (per browser) | the user's browser | ❌ per-browser only |
| Deploy identity | CI OIDC role / managed identity | cloud IAM (not in repo) | ✅ re-provision from IaC/console |

**There is no database, object of record, secret store, or user data to back
up.** Nothing the user does on the site is transmitted to a server.

### localStorage caveat (important)

Branding and theme live **only in the visitor's browser**. They are **not**
shared between browsers/devices/users and are **lost** if the user clears site
data. This is by design (static, per-browser). Consequences:

- Losing `localStorage` is **not** an incident — defaults render cleanly
  (`JRojas`, accent `#ff5811`, dark theme).
- To make branding **durable/org-wide**, change the defaults in `branding.js`
  (`DEFAULTS`) and/or the brand markup in the HTML, commit, and redeploy — that
  makes it part of the git-recoverable artifact instead of per-browser state.

## 2. RPO / RTO targets

| Metric | Target | Rationale |
|--------|--------|-----------|
| **RPO** (source) | **0** | Every change is a git commit; nothing exists only in production |
| **RPO** (per-browser branding/theme) | n/a | Ephemeral by design; not a recovery objective |
| **RTO** (redeploy to existing host) | **≤ 5 min** | Re-publish static files / re-point CDN |
| **RTO** (rebuild host from scratch) | **≤ 30–60 min** | Recreate bucket+CDN or VM/nginx, reapply headers/TLS, publish |

## 3. Backups

- **Primary backup = the git repository.** Ensure the remote (GitHub) is intact
  and, ideally, mirrored:
  ```bash
  git clone --mirror https://github.com/jessicarojas1/jessicarojas1.github.io.git
  ```
  Keep at least one off-platform mirror (second remote or periodic bundle):
  ```bash
  git bundle create isms-$(date +%F).bundle --all
  ```
- **Artifact backup (optional):** object-store hosting (S3/Blob) with
  **versioning enabled** gives point-in-time copies of the published files;
  enable SSE (SSE-KMS / managed keys). This is convenience, not a requirement —
  git is authoritative.
- **Hosting config backup:** `nginx.conf`, `render.yaml`, and the IaC for the
  CDN/bucket live in git — config is recoverable with the source.
- **Retention/encryption:** follow your org policy for the git remote and any
  object-store versions; encrypt object-store at rest.

## 4. Restore runbook (numbered, copy-pasteable)

### A. Redeploy to an existing, healthy host

```bash
# 1. Get the exact source you want live
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io
git checkout <tag-or-commit>        # or main

# 2a. Static host / object store (example: AWS S3 + CloudFront)
aws s3 sync . s3://<bucket>/ --delete \
  --exclude ".git/*" --exclude "*/deployments/*" --exclude "*/docs/*"
aws cloudfront create-invalidation --distribution-id <id> --paths "/*"

# 2b. OR container host
docker build -f isms/Dockerfile -t isms-library:latest .
docker run -d -p 8080:8080 isms-library:latest

# 3. Verify
curl -I https://<host>/isms/index.html   # expect 200
```

### B. Rebuild the host from scratch (object-store + CDN)

```bash
# 1. Recreate origin (private) + CDN with OAC/OAI, TLS cert, and the
#    security headers/CSP from docs/SECURITY.md (use your IaC if available).
# 2. Publish the files (step A.2a).
# 3. Reapply Cache-Control (HTML no-cache; assets long-cache) and headers.
# 4. Re-point DNS to the new CDN endpoint.
# 5. Verify entry page, asset resolution, headers, and — in a browser —
#    search/filter, theme persistence, and Settings → Branding.
```

### C. CDN outage (Bootstrap/devicon unavailable)

Layout/interactivity degrade if jsDelivr is unreachable. Mitigation = **switch to
vendored assets**: follow [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)
to drop local copies of Bootstrap (and the devicon SVGs), update the `href`/`src`
+ SRI, and redeploy. Keep a vendored branch ready for fast failover.

## 5. Verification cadence (restore drills)

| Drill | Frequency | Pass criteria |
|-------|-----------|---------------|
| Clean-clone rebuild + local serve | per release | `http://localhost:8000/isms/index.html` renders; filters + branding work |
| Republish to staging from a tag | quarterly | 200 on entry page; headers present; CSP clean |
| Full host rebuild (bucket+CDN) | annually | RTO ≤ 60 min; DNS cutover verified |
| CDN-failover to vendored assets | annually | site fully functional offline of jsDelivr |
| Git mirror integrity check | monthly | mirror clones and builds |

## 6. High availability

- **Managed/CDN hosting (Render, S3+CloudFront, Blob+Front Door, Static Web
  Apps)** is inherently multi-edge/HA — no app-tier failover to manage.
- **Kubernetes:** run ≥2 nginx-static replicas across nodes/zones with a
  PodDisruptionBudget and readiness probes on `/isms/index.html`
  ([../deployments/KUBERNETES.md](../deployments/KUBERNETES.md)).
- **Single VM:** the availability floor — pair with a CDN in front and/or a warm
  second VM; nightly config + repo verification.
- Because the artifact is immutable and stateless, HA is purely "serve the same
  files from more than one place" — there is no data replication or failover of
  state.
