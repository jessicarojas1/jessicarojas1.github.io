# Teacher Hub — Disaster Recovery

## What holds state

Teacher Hub has **two very different kinds of state**, and confusing them is the
central DR risk.

| State | Where it lives | Backed up by | Recoverable? |
|-------|----------------|--------------|--------------|
| **The site itself** (code, assets) | Git (`teacher/` in the monorepo) + host artifact | Git remote(s) | **Yes** — fully rebuildable |
| **All teacher/student data** | The visitor's browser `localStorage`, **per-device** | **Nothing, by default** | **No** — lost if browser data is cleared |
| Branding | `localStorage['teacher.branding.v1']` | Nothing (per-browser) | No — but trivially re-set |
| Theme choice | `localStorage['bsTheme']` | Nothing | No — trivially re-set |

The application data keys — `teacher_plans`, `teacher_units`, `teacher_settings`,
`gb_assignments`, `gb_grades`, `behavior_data`, `pbis_data`, `pbis_goal`,
`comm_log`, `seating_data`, `iep_notes`, `supply_list`, `cal_events`,
`anecdotal_notes`, `fluency_data`, `fluency_op`, `student_levels`, `std_taught`,
`word_wall`, `tallies` — hold **student names, grades, behavior, and IEP notes**.
Because there is no backend, this data **never leaves the browser** (a privacy
strength) but is **unencrypted, per-browser, per-device, and not synced or
server-backed** (a durability risk). If the classroom device's browser data is
cleared, the profile is deleted, or the machine is re-imaged, **that data is gone
and unrecoverable.**

## RPO / RTO targets

| Component | RPO | RTO | Basis |
|-----------|-----|-----|-------|
| Site (code/assets) | = last git commit | Minutes | redeploy from git to any static host |
| Host config (nginx/CloudFront/etc.) | = IaC/commit | Minutes–1h | recreate from the deployment guide |
| **Teacher/student data (localStorage)** | **= teacher's last manual export** | **N/A — cannot be restored if not exported** | no automatic backup exists |

The honest headline: **the site has a near-zero RPO/RTO; the classroom data has no
automatic backup at all.** Recovery of data depends entirely on manual exports
made *before* the loss.

## Backups

### Site (automatic, reliable)
- Git is the source of truth. Ensure the monorepo has an off-platform remote
  mirror (GitHub + a second remote/offline mirror for air-gapped sites).
- No database dumps, object-store snapshots, or secrets to back up — there are
  none.
- On cloud hosts, enable **object versioning** (S3 versioning / Azure blob
  versioning + soft-delete) so a bad deploy can be rolled back object-by-object.

### Teacher/student data (manual — must be taught to the teacher)
Because there is no backend, the teacher is the backup system. Recommended cadence:

1. **Gradebook → Export CSV** (`gradebook.csv`) weekly or after grading — this is
   the one built-in export path. Store the CSV somewhere backed up (district
   drive, OneDrive/Google Drive).
2. **Browser profile backup:** back up the browser profile directory, or use the
   browser's built-in sync (if district policy allows) so `localStorage` travels
   with the account. Note: browser sync does **not** cover `localStorage` for a
   file:// origin and coverage varies by browser — verify.
3. **Manual key export (power users):** copy the relevant `localStorage` values
   from DevTools → Application → Local Storage → the site origin, into a text file.
   Re-import by pasting them back (`localStorage.setItem('key', '<json>')`).

> **Gap / suggested enhancement:** the app has no first-class "Export all data /
> Import backup (JSON)" button. Adding one is the highest-value DR improvement —
> tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md). Until then, the CSV export +
> profile backup are the only recovery paths for data.

## Restore runbook

### A. Restore the site (host lost / bad deploy)
1. `git clone` (or fetch) the monorepo from the remote.
2. Check out the desired tag/commit: `git checkout <tag>`.
3. Redeploy per the target guide:
   - AWS: `aws s3 sync . s3://$S3_BUCKET --delete` + `create-invalidation`
     ([../deployments/AWS.md](../deployments/AWS.md)).
   - Azure: `az storage blob upload-batch` / `swa deploy` + purge
     ([../deployments/AZURE.md](../deployments/AZURE.md)).
   - VM: `rsync -a --delete ./ deploy@host:/var/www/teacherhub/`
     ([../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)).
   - k8s: redeploy the image tag ([../deployments/KUBERNETES.md](../deployments/KUBERNETES.md)).
4. Verify: entry page 200, assets resolve, CSP/headers present, 10 tabs switch,
   CSV downloads, branding applies ([DEPLOYMENT.md](DEPLOYMENT.md) checklist).

### B. Restore branding/theme on a device
1. Open the Branding modal (palette button) and re-enter logo/name/accent, or
   click **Reset to defaults** to return to the `Teacher Hub` / `#ff5811` defaults.
2. Toggle theme to the desired mode (persists to `bsTheme`).

### C. Restore teacher/student data (only if a backup exists)
1. If you have a **gradebook.csv**: re-enter grades from it (there is no CSV
   *import*; the CSV is a human-readable/portable record, not a re-import format).
2. If you saved **`localStorage` JSON**: on the same origin, in DevTools console,
   run `localStorage.setItem('<key>', '<jsonvalue>')` for each backed-up key, then
   reload.
3. If **no backup exists:** the data is unrecoverable. Re-create rosters/plans
   from paper/district records. Treat this as the reason to adopt weekly exports.

## Verification cadence (restore drills)

| Drill | Frequency | Pass criteria |
|-------|-----------|---------------|
| Rebuild site from git to a scratch host | Quarterly | entry page 200, all tabs/behaviors work |
| CSV export → store → confirm openable | Monthly (teacher) | `gradebook.csv` opens in a spreadsheet |
| `localStorage` re-import from a saved JSON | Semi-annually | keys reload and data reappears |
| Object-version rollback (cloud) | After enabling versioning | prior object restores |

## High availability

- **Site:** static files are trivially replicated — CloudFront/Front Door/Pages
  give global edge HA with no app-tier failover to design. For k8s, run ≥2
  replicas + a PDB ([../deployments/KUBERNETES.md](../deployments/KUBERNETES.md)).
- **Data:** there is **no cross-device sync and no HA for `localStorage`.** Two
  browsers/devices hold independent copies; there is no merge or reconciliation.
  This is a fundamental property of a zero-backend design — communicate it clearly
  to any teacher relying on the tool.
