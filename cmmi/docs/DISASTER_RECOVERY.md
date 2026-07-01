# Disaster Recovery — CMMI v2.0 Practice Reference

## What holds state

This is a static, client-side site. There is **no server-side state** — no
database, no object storage, no secrets store. State exists in exactly two
places:

| State | Location | Owner | Backed up by |
|-------|----------|-------|--------------|
| The application itself (HTML/JS/CSS + `../cmmidev3.js` + shared assets) | Git repository | The maintainer | Git remote (source of truth) |
| Deployed artifact (published files) | Static host / CDN (S3/Blob/nginx) | Deploy pipeline | Fully rebuildable from Git — treat as disposable cache |
| User practice annotations (`cmmi2_*`), branding (`cmmi.branding.v1`), theme (`bsTheme`) | The **visitor's browser `localStorage`** | The end user | **Per-browser only** — not server-backed (see below) |

> **Key point:** the only "user data" is in each visitor's own browser. It is
> **not** replicated to any server and cannot be recovered by the operator. If a
> user clears site data, switches browsers/devices, or uses private browsing,
> their annotations are gone unless they exported a JSON snapshot.

## RPO / RTO targets

| Scenario | RPO | RTO | Basis |
|----------|-----|-----|-------|
| Host/CDN loss | **0** (no unique data) | Minutes | Re-publish from Git to a new host |
| Repo loss | Last push to remote | Minutes–hours | Restore from Git remote/mirror |
| User loses their `localStorage` | Last user-taken JSON export | Immediate (re-import) | Client-side export/import feature |

Because the served artifact carries no unique data, the operator RPO is
effectively **zero** — everything is reproducible from source control.

## Backups

### The application (operator responsibility)
- **What:** the Git repository (this monorepo), including `cmmi/` and the
  repo-root `cmmidev3.js` + shared assets.
- **How/where:** the Git remote (e.g. GitHub) plus any mirror. No separate data
  backup is required — the artifact has no runtime state.
- **Retention/encryption:** per the Git host; enable branch protection and, if
  desired, a periodic `git bundle` archived to encrypted storage.

### User annotations (end-user responsibility)
- The app walks every `cmmi2_*` key (status/notes/owner/target-date/flags/
  evidence) into a JSON **snapshot** that the user can export and later import,
  restoring their self-assessment on any browser. This is the **only** backup
  path for user data — document it to users.

## Restore runbook

### A. Rebuild and re-publish the site (operator)

```bash
# 1. Clone the source of truth
git clone <repo-url> jessicarojas1.github.io
cd jessicarojas1.github.io

# 2. (Optional) sanity-check locally from the repo ROOT so ../ assets resolve
python3 -m http.server 8000
#    open http://localhost:8000/cmmi/  and confirm practices render + export

# 3a. Publish to S3 (example) — repo ROOT is the publish source
aws s3 sync . s3://<bucket>/ --exclude ".git/*" --delete
aws cloudfront create-invalidation --distribution-id <ID> --paths "/*"

# 3b. …or rebuild the container (context = repo ROOT) and roll it out
docker build -f cmmi/Dockerfile -t cmmi-ref .
docker run --rm -p 8080:8080 cmmi-ref   # verify → http://localhost:8080/cmmi/
```

Verify with the checklist in [DEPLOYMENT.md](DEPLOYMENT.md#verification-all-targets):
entry page 200, `../cmmidev3.js` 200, CSP clean, practices render/filter,
status persists, `.xlsx` export downloads, print renders.

### B. Restore user annotations (end user)

1. Open `/cmmi/` in the browser.
2. Use the in-app **Import** control and select the previously exported JSON
   snapshot.
3. The `cmmi2_*` keys are written back to `localStorage`; reload — status,
   notes, evidence, flags reappear.

## Verification cadence (restore drills)

| Drill | Frequency | Pass criteria |
|-------|-----------|---------------|
| Rebuild from clean clone + local serve | Each release / quarterly | `/cmmi/` renders, filters work, `.xlsx` exports |
| Re-publish to a scratch bucket/host | Quarterly | Entry page 200; assets (incl. `../cmmidev3.js`) resolve; CSP clean |
| User export → clear `localStorage` → import | On feature change to persistence | Annotations fully restored |

## High availability

- **Static = inherently HA.** Serve behind a CDN (CloudFront / Front Door) with
  multi-edge caching; the origin (S3/Blob/nginx) can be multi-AZ or replaced
  without data loss.
- **No failover of state** is needed — there is none server-side. HA is purely
  about serving the (identical, rebuildable) files close to users.
- **Dependency risk:** the runtime pulls Bootstrap/Icons/SheetJS from
  `cdn.jsdelivr.net`. For maximum resilience/offline, vendor those assets (see
  [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)) so availability does
  not depend on a third-party CDN.
