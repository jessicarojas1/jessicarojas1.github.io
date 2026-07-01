# Teacher Hub — Air-Gapped / Offline

**Target:** run Teacher Hub with **no internet access** — e.g. a school with
locked-down or no outbound connectivity. The blocker is that `index.html` loads
Bootstrap and Bootstrap Icons from the jsDelivr CDN; everything else is already
local. Air-gapping = **vendor those assets, drop the CDN, add a strict self-only
CSP, bundle the directory, and serve it from an internal nginx.**

> **Applicability:** Fully applicable and arguably the best-fit model for a
> classroom on a filtered network. **Ollama / LLM inference: N/A** — Teacher Hub
> has **no AI feature** and makes no model/API calls, so there is nothing to
> replace with a self-hosted model.

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES.md](KUBERNETES.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

Convert the two CDN dependencies to local files, then serve the whole bundle from
an internal nginx with **zero external calls**. Once vendored, the site works with
the network fully severed. Because inline handlers are gone from the *dependency*
perspective (they remain in the app), the CSP can be **self-only** (`default-src
'self'`) with **no jsDelivr origin** — but it must still allow `'unsafe-inline'`
for the app's inline handlers/`<script>`/`<style>` until those are externalized
(see [../OPEN_ITEMS.md](../OPEN_ITEMS.md)).

## 2. Topology

```
   Build host (has internet, one-time)          Air-gapped network
   ┌───────────────────────────────┐            ┌───────────────────────────┐
   │ download Bootstrap 5.3.3 CSS+JS│            │  Internal nginx           │
   │ download Bootstrap Icons 1.11.3│  tar +     │  /usr/share/nginx/html    │
   │ + fonts; rewrite index.html    │  transfer  │   ├ theme.css             │
   │ to local paths; add self CSP   │ ─────────► │   ├ favicon.ico           │
   │ produce teacherhub-offline.tgz │  (media)   │   ├ vendor/bootstrap*     │
   └───────────────────────────────┘            │   └ teacher/index.html…   │
                                                 └────────────┬──────────────┘
                                                              │ 443 (internal)
                                                              ▼
                                                 Classroom browser (no egress)
                                                   └ localStorage (all data)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Build host w/ internet | one-time, to fetch vendor assets |
| Transfer media | approved USB / one-way diode / internal mirror |
| Internal nginx host | air-gapped; `nginx:alpine` or a package install |
| No LLM/GPU | not needed — no AI feature |

## 4. Identity & credentials

**None at runtime** — no auth, no secrets, no external endpoints. The only trust
control is **integrity of the transferred bundle**: sign/checksum the tarball on
the build host and verify on the air-gapped host.

```bash
sha256sum teacherhub-offline.tgz > teacherhub-offline.tgz.sha256   # build host
sha256sum -c teacherhub-offline.tgz.sha256                          # airgap host
```

## 5. Environment variables

**None.** Static site, no runtime env. Build-side knobs only:

| Variable / knob | Example | Purpose |
|-----------------|---------|---------|
| Bootstrap version | `5.3.3` | vendored CSS/JS pin |
| Bootstrap Icons version | `1.11.3` | vendored icon font + CSS |
| bundle path | `/usr/share/nginx/html` | internal nginx root |

## 6. Configuration references

**Step A — vendor the CDN assets on the build host:**

```bash
mkdir -p vendor/bootstrap/css vendor/bootstrap/js vendor/bootstrap-icons/font/fonts
BS=https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist
BI=https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font
curl -sSL $BS/css/bootstrap.min.css      -o vendor/bootstrap/css/bootstrap.min.css
curl -sSL $BS/js/bootstrap.bundle.min.js -o vendor/bootstrap/js/bootstrap.bundle.min.js
curl -sSL $BI/bootstrap-icons.min.css    -o vendor/bootstrap-icons/font/bootstrap-icons.min.css
# icon webfonts referenced by that CSS:
curl -sSL $BI/fonts/bootstrap-icons.woff2 -o vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2
curl -sSL $BI/fonts/bootstrap-icons.woff  -o vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff
```

**Step B — repoint `index.html`** (edit the three CDN references + drop SRI/crossorigin,
which don't apply to same-origin files):

```html
<!-- was: https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css -->
<link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
<!-- was: bootstrap-icons@1.11.3/font/bootstrap-icons.min.css -->
<link rel="stylesheet" href="../vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<!-- was: bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js -->
<script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
```

Place `vendor/` at the **repo root** so `../vendor/...` resolves the same way
`../theme.css` does.

**Step C — add a self-only CSP** as an nginx response header (no jsDelivr origin):

```nginx
add_header Content-Security-Policy
  "default-src 'self'; style-src 'self' 'unsafe-inline'; \
   script-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; \
   connect-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer" always;
```

**Step D — bundle + serve:**

```bash
tar czf teacherhub-offline.tgz theme.css favicon.ico vendor teacher
# on the air-gapped host:
tar xzf teacherhub-offline.tgz -C /usr/share/nginx/html
```

## 7. Verification

No internet, no health/login/secret/upload/DB — verify **zero egress** + working
client behavior:

```bash
# Entry page 200 from the internal server
curl -sSI https://teacherhub.internal/teacher/ | head -1               # 200
# Vendored assets resolve locally (NOT jsDelivr)
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.internal/vendor/bootstrap/css/bootstrap.min.css  # 200
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.internal/vendor/bootstrap-icons/font/bootstrap-icons.min.css  # 200
# Confirm index.html no longer references the CDN
grep -c 'cdn.jsdelivr.net' /usr/share/nginx/html/teacher/index.html    # 0
```

In the browser with the network physically disconnected: styles + icons render,
theme persists, all 10 tabs switch, a plan and gradebook entry save and survive
reload, the **CSV downloads**, a template prints, branding applies. If anything
still tries jsDelivr, DevTools Network will show a failed external request.

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update the app | rebuild the bundle from a new git tag on the build host, re-transfer, re-extract |
| Update Bootstrap/Icons | re-run Step A with a new pin, re-transfer; verify offline |
| Integrity | keep `.sha256` alongside each bundle; verify on arrival |
| Backups | git (offline mirror) is source of truth; keep the last known-good `.tgz` |
| Student data | still lives only in classroom browsers — export Gradebook CSV to approved media for backup ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| No icons offline | icon **webfonts** not vendored | copy `fonts/bootstrap-icons.woff2`/`.woff` next to the icons CSS |
| Styles missing | `../vendor/...` path wrong | put `vendor/` at repo root; verify relative path from `/teacher/` |
| Page still calls out | a CDN reference remained | `grep cdn.jsdelivr.net index.html` → 0; remove leftover tags |
| CSP blocks app JS | dropped `'unsafe-inline'` | keep it until inline handlers are externalized |
| Bundle rejected | checksum mismatch | re-transfer; verify `sha256sum -c` |
| SRI error | left `integrity=`/`crossorigin` on now-local files | remove those attributes for same-origin assets |
