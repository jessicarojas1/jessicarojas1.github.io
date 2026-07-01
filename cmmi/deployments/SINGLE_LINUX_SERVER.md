# Single Linux Server — CMMI v2.0 Practice Reference

Serve the static site from one VM using nginx (Apache notes included) over TLS,
with cache and security headers, and optional access gating.

## 1. Deployment architecture

One Linux host runs nginx as a static file server. The **repository root** is the
document root (so the page's `../cmmidev3.js` and shared `../` assets resolve),
with the app reached at `/cmmi/`. TLS terminates at nginx (Let's Encrypt).
Bootstrap/Icons/SheetJS load from `cdn.jsdelivr.net` at runtime unless vendored.
No app process, database, or secrets run on the host.

## 2. Topology

```
Internet ──HTTPS(443)──▶ nginx (systemd)  ──▶ /var/www/cmmi-site  (repo ROOT)
                          │  TLS: Let's Encrypt        ├─ cmmi/index.html, branding.js
                          │  gzip, cache, sec-headers  ├─ cmmidev3.js  (dataset)
                          │  optional basic-auth        └─ theme.css, *.js, favicon.ico
Browser ──HTTPS──▶ cdn.jsdelivr.net (Bootstrap 5.3.3 / Icons 1.11.3 / SheetJS)
```

## 3. Prerequisites

| Item | Version / note |
|------|----------------|
| Linux VM | Ubuntu 22.04+/RHEL 9+ (any modern distro) |
| nginx | ≥ 1.18 (or Apache ≥ 2.4) |
| certbot | for Let's Encrypt TLS |
| DNS A/AAAA record | → the VM public IP |
| Outbound 443 | to `cdn.jsdelivr.net` (or vendor assets) |
| Git | to deploy the repo |

## 4. Identity & credentials

No application credentials — the site has no backend. The only identity is the
**deploy mechanism** onto the host: prefer an SSH deploy key or a CI runner with
a scoped SSH identity, and (optionally) gate the site with **basic-auth** or an
**oauth2-proxy** in front of nginx.

Optional least-privilege basic-auth:

```bash
sudo apt-get install -y apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd reviewer   # add reviewers as needed
```

## 5. Environment variables

The app uses none. The host-side "variables" are file paths and the domain:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DOCROOT` | `/var/www/cmmi-site` | Repo root published on the host |
| `SERVER_NAME` | `cmmi.example.com` | TLS/vhost hostname |
| `TLS_CERT` / `TLS_KEY` | `/etc/letsencrypt/live/…/fullchain.pem` | certbot-managed |

## 6. Configuration references

nginx server block (repo root as docroot; app at `/cmmi/`):

```nginx
server {
    listen 443 ssl http2;
    server_name cmmi.example.com;

    root /var/www/cmmi-site;          # the REPO ROOT (so ../cmmidev3.js resolves)
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/cmmi.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cmmi.example.com/privkey.pem;

    # Edge security headers (defense-in-depth; CSP is delivered by the page <meta>)
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options    "nosniff" always;
    add_header X-Frame-Options           "SAMEORIGIN" always;
    add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy        "geolocation=(), microphone=(), camera=()" always;

    gzip on;
    gzip_types text/css application/javascript image/svg+xml;

    # Short TTL on HTML; longer on assets. NOTE: cmmidev3.js is unversioned — keep
    # its TTL modest or bust cache on deploy so updates propagate.
    location = /cmmi/ { add_header Cache-Control "no-cache"; try_files /cmmi/index.html =404; }
    location ~* \.(?:js|css|ico)$ { expires 1h; add_header Cache-Control "public, max-age=3600"; }

    # Optional gating:
    # auth_basic "CMMI reference"; auth_basic_user_file /etc/nginx/.htpasswd;

    location / { try_files $uri $uri/ =404; }
}
server { listen 80; server_name cmmi.example.com; return 301 https://$host$request_uri; }
```

| Setting | Example | Purpose |
|---------|---------|---------|
| `root` | `/var/www/cmmi-site` (repo root) | Makes `../` assets resolve |
| HSTS max-age | `31536000` | Force HTTPS |
| Asset `expires` | `1h` | Cache CDN-independent local JS/CSS |
| basic-auth | `.htpasswd` | Optional access gate |

## 7. Verification

```bash
# entry page + parent dataset
curl -I https://cmmi.example.com/cmmi/           # → HTTP/2 200
curl -I https://cmmi.example.com/cmmidev3.js     # → 200 (~227 KB)
curl -I https://cmmi.example.com/theme.css       # → 200

# headers present
curl -sI https://cmmi.example.com/cmmi/ | grep -Ei 'strict-transport|x-content-type|referrer'

# TLS valid
echo | openssl s_client -connect cmmi.example.com:443 -servername cmmi.example.com 2>/dev/null | openssl x509 -noout -dates
```

In-browser: CSP clean, practices render/filter/search, status persists to
`cmmi2_*`, theme persists (`bsTheme`), branding applies, `.xlsx` exports, print
renders. No login/DB/upload exists to verify.

## 8. Day-2 operations

- **Deploy/update:** `git pull` into `/var/www/cmmi-site` (or `rsync` the repo
  root). No build, no restart needed — nginx serves files directly. Bump/verify
  cache-control so `cmmidev3.js` updates reach users.
- **TLS renewal:** `certbot renew` via its systemd timer; `nginx -s reload`.
- **Backups:** none required for data (there is none) — the repo is the source of
  truth. Back up nginx config + `.htpasswd`.
- **Logs:** `/var/log/nginx/access.log` / `error.log`; watch for 404s on
  `../cmmidev3.js` (indicates wrong docroot).
- **Scaling:** put a CDN in front, or add a second VM behind a load balancer —
  the artifact is stateless and identical everywhere.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `/cmmi/` 200 but blank | docroot set to `cmmi/`, `../cmmidev3.js` 404 | Set `root` to the **repo root** |
| 404 on `/cmmidev3.js` | Parent asset not deployed | Ensure the full repo root is published |
| Mixed-content warnings | HTTP asset on an HTTPS page | Serve everything over HTTPS; CDN URLs are HTTPS already |
| Unstyled page | CDN blocked by firewall | Allow `cdn.jsdelivr.net` egress or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| Stale `cmmidev3.js` after deploy | Long cache TTL on unversioned file | Lower TTL / add cache-bust / `nginx -s reload` |
| TLS errors | Cert expired | `certbot renew && nginx -s reload` |
