# Single Linux Server — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable. Serve the static files from one VM with
nginx (or Apache) over TLS, with cache + security headers.

## 1. Deployment architecture

One Linux VM runs nginx as a static web server. The site files are copied to a web root;
nginx terminates TLS, sets security/cache headers, and serves the files. No app runtime,
no database, no worker. Bootstrap loads from the CDN in the client (or vendor it).

## 2. Topology

```
        Internet
           │  HTTPS (443)
           ▼
   ┌───────────────────────────┐
   │  Linux VM                  │
   │  nginx (TLS, headers, cache)│──► /var/www/site/  (whole repo or aitool/ + vendored assets)
   │  certbot (Let's Encrypt)   │
   └───────────────────────────┘
   Client browser ──HTTPS──► cdn.jsdelivr.net (Bootstrap, SRI)
```

## 3. Prerequisites

| Item | Version/Note |
|------|--------------|
| Linux VM | Ubuntu 22.04 / RHEL 9 or similar |
| nginx | 1.24+ (or Apache 2.4+) |
| certbot | for Let's Encrypt TLS (or bring your own cert) |
| DNS A/AAAA record | pointing at the VM |
| Firewall | 80/443 inbound open; 22 restricted |

## 4. Identity & credentials

- **The running site needs no credentials** (no backend, no secrets).
- **Deploy identity:** prefer pushing content via CI over SSH using a short-lived,
  least-privilege deploy key or an OIDC-brokered SSH cert — not a shared root key.
  Restrict the deploy user to writing the web root only.
- TLS private key stored `root:root 0600` under `/etc/letsencrypt` (or your cert store).

## 5. Environment variables

**None consumed by the site.** Server-side "config" is nginx directives, not env vars.

| Variable | Example | Purpose |
|----------|---------|---------|
| (none for the app) | — | Site reads no env vars |
| `DEPLOY_HOST` (CI, optional) | `aitool.example.com` | Target host for `rsync`/`scp` in CI |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Web root | `/var/www/site` | Directory nginx serves |
| Entry | `/aitool/index.html` | Landing page (or set as index for a subpath) |
| TLS cert / key | `/etc/letsencrypt/live/<host>/{fullchain,privkey}.pem` | HTTPS |
| Security headers / CSP | see `../nginx.conf` | Hardening |
| Cache-Control | `no-cache` on `*.html`, `max-age=604800` on assets | Freshness vs caching |

Example nginx server block (adapt from the repo's [`../nginx.conf`](../nginx.conf)):

```nginx
server {
    listen 443 ssl http2;
    server_name aitool.example.com;
    root /var/www/site;              # deploy the whole repo so ../ assets resolve
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/aitool.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/aitool.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;

    location ~* \.html$ { add_header Cache-Control "no-cache"; }
    location ~* \.(js|css|png|jpg|jpeg|gif|svg|ico|woff2?)$ { add_header Cache-Control "public, max-age=604800"; }
    location / { try_files $uri $uri/ =404; }
    autoindex off;
}
server { listen 80; server_name aitool.example.com; return 301 https://$host$request_uri; }
```

> `script-src` needs no `'unsafe-inline'`: the theme snippet, per-page scripts, and
> Print handlers are all external (`theme-init.js` + `data-print`). `style-src` keeps
> it for inline `style=`/branding accent styles — see `../OPEN_ITEMS.md`.

Optional access gating: add HTTP basic-auth (`auth_basic`) or front with **oauth2-proxy**
if the content must be restricted (the app has no auth of its own).

## 7. Verification

No login/DB/upload to verify. Verify serving + headers + client behavior:

```bash
curl -I https://aitool.example.com/aitool/index.html         # 200
curl -sI https://aitool.example.com/aitool/index.html | grep -i 'strict-transport-security\|content-security-policy'
curl -I https://aitool.example.com/theme.css                 # 200 (shared asset present)
curl -I http://aitool.example.com/aitool/index.html          # 301 → https
```
Browser: page styled, no console CSP/SRI errors; theme toggle + Settings branding persist
(`localStorage`); tracker JSON export downloads.

## 8. Day-2 operations

- **Deploy/update:** `rsync -a --delete ./ deploy@host:/var/www/site/` from CI (deploy
  the whole repo so `../` assets resolve), then `nginx -t && systemctl reload nginx`.
- **TLS renewal:** `certbot renew` via timer; reload nginx on renewal.
- **Backups:** the content is in git — the VM is disposable. Back up nginx config + certs
  (or re-issue certs). See `../docs/DISASTER_RECOVERY.md`.
- **Scaling:** put a CDN in front, or clone the VM behind a load balancer (stateless).
- **Logs:** nginx `access.log`/`error.log`; ship to your SIEM if consumption auditing is
  needed.
- **Bootstrap bump:** update version + SRI hashes in the HTML, redeploy.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `../theme.css`, unstyled | Only `aitool/` deployed | Deploy the whole repo, or vendor shared assets |
| CSP/SRI console errors | Header mismatch or stale SRI | Fix CSP; update SRI hashes on version bump |
| Mixed-content warning | HTTP asset on an HTTPS page | Ensure all local links are relative/HTTPS |
| Cert errors | Expired/missing cert | `certbot renew`; check `ssl_certificate*` paths |
| `nginx -t` fails | Config typo | Fix directive; reload |
| Content not access-controlled | App has no auth by design | Add basic-auth/oauth2-proxy |
