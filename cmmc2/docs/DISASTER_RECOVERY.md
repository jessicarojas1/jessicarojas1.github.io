# Disaster Recovery — `cmmc2`

Disaster-recovery plan for the CMMC 2.0 Readiness Assessment Platform. Because `cmmc2` is a
**static, client-side** site, DR is unusually simple: **git is the source of truth and the
artifact is fully rebuildable** from source with no build step. There is **no server
state, no database, and no server-side user data** to lose. The one nuance is that each
visitor's assessment and branding live **only in their browser's `localStorage`** — that is
per-browser and **not backed up server-side**.

## 1. What holds state

| State | Where | Backed up by | Criticality |
|---|---|---|---|
| Application source (`index.html`, `branding.js`, docs, deploy config) | Git repo (`jessicarojas1.github.io`) | Git remote(s) (GitHub) | **Critical** — this is everything |
| Parent shared assets (`../theme.css`, `../*.js`, `../favicon.ico`) | Same git repo (repo root) | Git remote(s) | Critical (app depends on them) |
| Published artifact | Static host / object store (S3, Blob `$web`, nginx docroot) | Rebuildable from git | Low — regenerable |
| CDN dependencies (Bootstrap, Icons, SheetJS) | `cdn.jsdelivr.net` (or vendored copy) | jsDelivr / your mirror | Low (external) / Medium (air-gapped mirror) |
| **Visitor assessment + branding** | The visitor's browser `localStorage` | **Not backed up server-side** | User-owned; see §6 |
| Deploy pipeline identity | OIDC role / managed identity (cloud IAM) | IaC / cloud config | Medium |

There is **no database, object-storage user data, or server secret** that constitutes
recoverable application state.

## 2. RPO / RTO targets

| Scenario | RPO | RTO | Basis |
|---|---|---|---|
| Host/CDN outage | 0 (source intact) | Minutes | Redeploy from git to a healthy host |
| Accidental bad deploy | 0 | Minutes | Roll back to previous commit/artifact |
| Git remote loss | = last local/mirror clone | Hours | Restore from a mirror/clone; re-push |
| Region loss (single-region) | 0 | Minutes–hours | Re-point DNS / deploy to alternate region |
| Visitor clears their browser | n/a (user-owned) | n/a | Not recoverable server-side (by design) — see §6 |

RPO is effectively **zero for application state** because nothing mutable is stored
server-side; the only "data" is source-controlled.

## 3. Backups

- **Primary backup = the git remote.** Ensure at least one durable remote (GitHub) plus,
  ideally, a periodic mirror clone (`git clone --mirror`) stored off-platform.
- **Artifact/object-store versioning:** enable versioning on the S3 bucket / Blob container
  so prior published versions are retained and any object can be restored.
- **CDN vendored copies (air-gapped):** the mirrored Bootstrap/Icons/SheetJS bundle should
  be checked into the offline artifact repo / stored with the offline media so it is
  reproducible without internet — see [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md).
- **Encryption:** repo access controlled by GitHub auth; object stores use SSE (SSE-S3/KMS,
  Azure SSE). No app secrets to encrypt.

## 4. Restore runbook (copy-pasteable)

### A. Redeploy after a host/CDN failure or bad deploy
```bash
# 1. Get a clean tree at a known-good commit
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io
git checkout <known-good-commit-or-tag>

# 2a. AWS: sync repo root to the bucket, then invalidate CloudFront
aws s3 sync . s3://<bucket> --delete \
  --exclude ".git/*" --exclude "*/deployments/*" --exclude "*/docs/*"
aws cloudfront create-invalidation --distribution-id <DIST_ID> --paths "/*"

# 2b. Azure: upload to the $web container, then purge Front Door
az storage blob upload-batch -s . -d '$web' --account-name <acct> --overwrite
az afd endpoint purge -g <rg> --profile-name <profile> --endpoint-name <ep> --content-paths '/*'

# 3. Verify (see §7)
curl -sI https://<domain>/cmmc2/ | head -n1     # expect HTTP/2 200
```

### B. Roll back one release
```bash
# Object-store versioning path (fastest): restore previous object versions,
# or simply re-run step A with the previous commit/tag:
git checkout <previous-tag>
# ...repeat the sync + invalidation for your target...
```

### C. Restore from lost git remote
```bash
# From any existing clone / mirror:
git clone --mirror /path/to/local/mirror.git recovered.git
cd recovered.git
git remote add origin https://github.com/jessicarojas1/jessicarojas1.github.io.git
git push --mirror origin
```

## 5. Verification cadence (restore drills)

| Drill | Frequency | Pass criteria |
|---|---|---|
| Redeploy-from-git dry run to a staging host | Quarterly | Entry `200`, assets resolve, CSP clean, branding applies, theme persists, control mark updates SPRS, `.xlsx` export downloads |
| Object-store version rollback | Semi-annually | Previous artifact restored and served |
| Git mirror restore | Semi-annually | Mirror clones, pushes, and builds an identical site |

## 6. The `localStorage` caveat (important)

Each visitor's **assessment data (control status/notes/flags) and branding** are stored
**only** in their own browser's `localStorage` (keys include `cmmc2.branding.v1`, `bsTheme`,
and the assessment state). This is **intentional** — it keeps CUI-adjacent data on the
user's device and out of any server. Consequences:

- The operator **cannot back up or recover** a user's assessment. There is no server copy.
- Clearing site data, using a different browser/device/profile, or private-browsing loses it.
- **User guidance:** export the `.xlsx` (and the JSON Blob backup) regularly and store it per
  your CUI handling requirements; that export **is** the user's disaster-recovery copy.
- **Enhancement tracked in [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md):** add full JSON
  export/import so users can round-trip and back up the complete assessment, not just the
  report.

## 7. High availability

- **Static + CDN is inherently HA:** S3/CloudFront and Azure Blob/Front Door replicate
  across AZs/edges automatically; no single app server to fail over.
- **Multi-region (optional):** replicate the bucket/container to a second region and use
  Route 53 / Front Door / Traffic Manager for failover. Given zero server state, failover is
  DNS/edge-config only.
- **No stateful failover** is needed — there is no database or session store to replicate.
