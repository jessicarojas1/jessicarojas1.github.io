# Disaster Recovery — AI Tool Evaluation Framework (`aitool`)

## Applicability

This is a **stateless static site**. The deployed artifact is a rebuildable copy of
files under version control. There is no database, no server state, and no
application-managed persistence to lose. DR therefore centers on **git as the source of
truth** and on redeploying to a hosting target — not on data restores.

## What holds state

| State | Where it lives | Authoritative? | DR concern |
|-------|----------------|----------------|------------|
| Site content (HTML/CSS/JS) | Git repo (`jessicarojas1.github.io`) | ✅ Yes | Protect the repo; the deployed copy is disposable |
| Shared parent assets (`../theme.css`, `../script.js`, etc.) | Same git repo | ✅ Yes | Same |
| Bootstrap library | `cdn.jsdelivr.net` (external) | ❌ No (third party) | Vendor a pinned copy for resilience/air-gap |
| Theme preference (`bsTheme`) | Browser `localStorage` | Per-browser only | Not backed up; regenerates on use |
| Branding (`aitool.branding.v1`) | Browser `localStorage` | Per-browser only | Not backed up; user re-enters if lost |
| Tracker data (vendor-tracker store) | Browser `localStorage` | Per-browser only | **Not server-persisted** — use the tracker's JSON export to back up |
| Deploy identity | CI provider (OIDC) | ✅ Yes | Re-createable IAM role/managed identity |

> **Important — client-side data is per-browser and not centrally backed up.** Anything
> a user enters in the tracker or Settings lives only in *that browser profile*. Clearing
> site data, switching browsers, or a different device loses it. The tracker's **Export
> JSON** is the only backup mechanism; treat exported files as the user's own records.

## RPO / RTO targets

| Metric | Target | Basis |
|--------|--------|-------|
| **RPO (content)** | = last git commit (effectively 0 for pushed work) | Content recreated from the repo |
| **RPO (client data)** | Last manual JSON export | No server persistence by design |
| **RTO (site)** | Minutes | Redeploy static files to a healthy target |
| **RTO (CDN/edge outage)** | Minutes–low tens | Repoint DNS or redeploy to an alternate static host |

## Backups

| Item | What / How | Where | Retention | Encryption |
|------|-----------|-------|-----------|------------|
| Source | Git history + at least one remote (GitHub) | GitHub + optional mirror | Full history | Provider-side |
| Vendored Bootstrap (if used) | Committed to repo or stored in artifact bucket | Repo / object store | With release | At rest per store |
| Tracker data (optional) | User-initiated JSON export | User's chosen location | User-managed | User-managed |
| Deploy config | `Dockerfile`, `nginx.conf`, `render.yaml`, `deployments/*` in git | Repo | Full history | Provider-side |

There is **no server database to snapshot**. "Backup" = keep the repo safe and mirrored.

## Restore runbook

### A. Rebuild and redeploy the site (primary scenario)

```bash
# 1. Clone the source of truth
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io

# 2. (Optional) check out a known-good commit/tag to roll back
git checkout <commit-or-tag>

# 3. Publish. Pick the target's real command:
#    Render:        push to the connected branch (auto-deploy) — see ../render.yaml
#    AWS:           aws s3 sync ./ s3://<bucket> --delete   (then CloudFront invalidate)
#    Azure Blob:    az storage blob upload-batch -d '$web' -s .
#    Container:     docker build -t aitool ./aitool && docker run -p 8080:8080 aitool
#    k8s:           kubectl rollout restart deploy/aitool   (or re-apply manifests)

# 4. Verify (see docs/DEPLOYMENT.md → Verification): entry page 200, headers,
#    assets resolve, theme+branding persist.
```

### B. CDN / edge provider outage

1. Confirm origin (bucket/site) is intact.
2. Fail over: repoint DNS to an alternate static host, or deploy the same files to a
   secondary target (any in `../deployments/`).
3. Invalidate/warm the new edge; verify entry page `200` + headers.

### C. Loss of the CDN library (jsDelivr unreachable)

1. Switch to the **vendored Bootstrap** build (see `../deployments/AIRGAPPED.md`):
   update the `<link>`/`<script>` to local paths (keep SRI), redeploy.
2. Verify pages render without external requests.

### D. Restore a user's tracker data

1. Open `vendor-tracker.html` in the user's browser.
2. Import from a previously exported JSON file (or manually re-enter). There is no
   central store to restore from — reinforce the export habit.

## Verification cadence (restore drills)

| Drill | Frequency |
|-------|-----------|
| Redeploy from a clean clone to a scratch host; verify entry page + headers | Quarterly |
| Roll back to a previous commit and confirm the site serves | Quarterly |
| Validate vendored-Bootstrap fallback renders offline | Semi-annually |
| Confirm at least two git remotes are current | Monthly |

## High availability

- **Front with a CDN** (CloudFront / Front Door / Render's edge) for multi-PoP
  availability and caching; the origin can be a single bucket/site.
- **Multi-region:** replicate the bucket (S3 CRR / Azure GRS) or deploy to two static
  hosts behind health-checked DNS.
- **Statelessness = trivial scaling:** any number of edge nodes or replicas serve
  identical files; there is no shared server state to coordinate.
- **k8s:** run ≥2 replicas with a PodDisruptionBudget and readiness probe on `/`
  (see `../deployments/KUBERNETES.md`).
