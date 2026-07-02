# Single Linux Server — `cmmc2`

Serve the CMMC 2.0 Readiness Assessment Platform from one Linux VM using **nginx** over
**TLS**, with security headers, a CSP HTTP header, and sane cache-control. No app server,
database, or worker is involved — nginx serves static files.

## 1. Deployment architecture

nginx serves the portfolio tree from a docroot; `cmmc2` is reached at `/cmmc2/`. TLS is
terminated by nginx (Let's Encrypt via certbot, or an uploaded cert). nginx adds the
security-header block (including a CSP mirroring the page's `<meta>` CSP) and cache-control.
The browser fetches Bootstrap/Icons/SheetJS from jsDelivr; the VM needs no outbound access.

## 2. Topology

```
            Internet
               │ 443 (TLS)
        ┌──────▼───────────────┐        ┌── cdn.jsdelivr.net ──┐
        │   Linux VM (nginx)   │        │ Bootstrap/Icons/xlsx │ (fetched by the browser)
        │  docroot: /var/www/  │        └──────────────────────┘
        │   ├── cmmc2/index.html
        │   ├── cmmc2/branding.js
        │   ├── theme.css, favicon.ico
        │   └── *.js (users/roles/script/analytics/siteSearch), index.html
        │  certbot → /etc/letsencrypt
        └──────────────────────┘
```

## 3. Prerequisites

| Item | Version / note |
|---|---|
| Linux VM | Ubuntu 22.04+/RHEL 9+ (1 vCPU / 1 GB is plenty) |
| nginx | ≥ 1.18 |
| certbot | for Let's Encrypt (or bring your own cert/CA) |
| DNS A/AAAA record | pointing at the VM |
| Open ports | 80 (redirect) + 443 |
| git / rsync | to place files |

## 4. Identity & credentials

- **Running site:** no identity, no secrets.
- **Deploy path:** push over SSH (key-based) or pull via a deploy key / read-only git token.
  Prefer a dedicated, least-privilege deploy user; do not store cloud static keys on the box.
- **Optional gating** (private deployments): HTTP basic-auth or an `oauth2-proxy` sidecar in
  front of nginx — the app itself performs no auth.

## 5. Environment variables

**None for the app.** Server-side "config" is nginx directives, not env vars.

| Variable | Example | Purpose |
|---|---|---|
| _(none — app)_ | — | No runtime env vars |
| `DEPLOY_HOST` (your CI, optional) | `cmmc.example.com` | rsync/ssh target |
| `WEBROOT` (deploy script) | `/var/www/portfolio` | Docroot on the VM |

## 6. Configuration references

Place files so `../` resolves (publish the repo root as the docroot):

```bash
sudo mkdir -p /var/www/portfolio
sudo rsync -a --delete \
  --exclude '.git' \
  /path/to/jessicarojas1.github.io/ /var/www/portfolio/
sudo chown -R www-data:www-data /var/www/portfolio
```

`/etc/nginx/sites-available/cmmc2.conf`:

```nginx
server {
    listen 80;
    server_name cmmc.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name cmmc.example.com;
    root /var/www/portfolio;
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/cmmc.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cmmc.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Security headers (CSP mirrors the page <meta>; adds frame-ancestors + HSTS)
    add_header Content-Security-Policy "default-src 'self' blob:; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net blob:; worker-src blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header X-Frame-Options "DENY" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    location ~* \.html$ { add_header Cache-Control "no-cache" always; }
    location ~* \.(css|js|ico|png|jpg|jpeg|svg|woff2?)$ { add_header Cache-Control "public, max-age=86400" always; }

    location / { try_files $uri $uri/ =404; }
}
```

| Setting | Example | Purpose |
|---|---|---|
| `root` | `/var/www/portfolio` | Repo-root docroot (parent assets resolve) |
| Entry | `https://cmmc.example.com/cmmc2/` | The app |
| CSP header | see block | Edge CSP + `frame-ancestors` |
| Cache-Control | `no-cache` html / `max-age=86400` assets | Prompt updates, cached statics |

```bash
sudo ln -s /etc/nginx/sites-available/cmmc2.conf /etc/nginx/sites-enabled/
sudo certbot --nginx -d cmmc.example.com     # or install your own cert
sudo nginx -t && sudo systemctl reload nginx
```

## 7. Verification

No login/DB/upload/object-store — verify static delivery + client behavior:

```bash
# Entry page 200 over TLS
curl -sI https://cmmc.example.com/cmmc2/ | head -n1                  # HTTP/2 200

# Security headers present
curl -sI https://cmmc.example.com/cmmc2/ | grep -iE 'content-security-policy|strict-transport|x-content-type'

# Parent assets resolve
for u in theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js; do
  printf '%-14s ' "$u"; curl -so /dev/null -w '%{http_code}\n' "https://cmmc.example.com/$u"
done

# HTTP → HTTPS redirect
curl -sI http://cmmc.example.com/cmmc2/ | grep -i location            # 301 → https
```

In a browser: no console CSP violations; branding applies; theme toggle persists; marking a
control updates the SPRS score; `.xlsx` export downloads.

## 8. Day-2 operations

| Task | Command / note |
|---|---|
| Deploy update | `rsync -a --delete --exclude '.git' repo/ /var/www/portfolio/ && sudo systemctl reload nginx` |
| Rollback | Re-sync the previous git checkout (`git checkout <tag>` then rsync) |
| Cert renewal | certbot auto-renew timer; verify `certbot renew --dry-run` |
| Logs | `/var/log/nginx/access.log`, `error.log`; rotate via logrotate |
| Backups | The docroot is regenerable from git — back up **git**, not the docroot |
| Hardening | Keep OS patched; UFW allow 80/443 only; `fail2ban`; optional basic-auth for private use |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| 404 on `/cmmc2/` | Wrong `root` / files not synced | Point `root` at the repo-root copy; re-rsync |
| Unstyled page | `../theme.css` 404 (docroot not repo root) | Publish repo root, not just `cmmc2/` |
| CSP header missing | `add_header` in wrong context / no `always` | Put headers in the `server`/`location` block with `always`; `nginx -t` |
| TLS errors | Cert path/renewal | `certbot renew`; check `ssl_certificate*` paths |
| Icons/export broken | Client can't reach jsDelivr | Client network issue, or use [`AIRGAPPED.md`](AIRGAPPED.md) |
| Stale content after deploy | Aggressive caching | Ensure `no-cache` on `*.html`; purge browser cache |

See also: [`AWS.md`](AWS.md) · [`AZURE.md`](AZURE.md) · [`../docs/DEPLOYMENT.md`](../docs/DEPLOYMENT.md).
