# ISMS Document Library — Single Linux Server

Audience: operators serving the ISMS library from **one Linux VM** with
**nginx** (or Apache) over **TLS**. It is a **Type A static website** — no
backend, no database, no app runtime. You copy files and configure the web
server's headers, TLS, and cache.

> Siblings: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
> [AIRGAPPED.md](AIRGAPPED.md)

## 1. Deployment architecture

nginx serves the static files from a web root. The pages pull Bootstrap +
devicon SVGs from jsDelivr (internet) unless vendored. TLS is terminated by
nginx (Let's Encrypt/certbot or a corporate cert). Security headers + CSP are
added by nginx. Optionally gate the whole site with **Basic Auth** or
**oauth2-proxy** (the app has no auth of its own).

Publish the **repo root** to the web root so `../theme.css` etc. resolve; the
library is served at `/isms/`.

## 2. Topology

```
Internet ─HTTPS(443)─► nginx (VM)
                         │  TLS termination (certbot/corp cert)
                         │  security headers + CSP + Cache-Control
                         │  (optional) Basic Auth / oauth2-proxy gate
                         ▼
                  /var/www/jrojas/           ← repo root
                     ├─ isms/*.html, isms.css, branding.js
                     └─ theme.css, script.js, roles.js, users.js
                         │
   browser ◄────────────┘   loads Bootstrap 5.3.3 + devicons ──► jsDelivr CDN
```

## 3. Prerequisites

| Item | Version | Notes |
|------|---------|-------|
| Linux VM | any current | 1 vCPU / 512 MB is ample |
| nginx | 1.24+ | or Apache 2.4 |
| certbot | latest | Let's Encrypt TLS (or bring your own cert) |
| DNS A/AAAA record | — | points to the VM |
| Open ports | 80, 443 | 80 for ACME + redirect |
| git / rsync | — | to place files |

## 4. Identity & credentials

- **Running site:** none — public static files, no app auth, no secrets.
- **Deploy identity:** an SSH key / deploy user for `rsync`/`git pull` to the VM
  (least privilege; key-based, no password login).
- **Optional access gate:** Basic Auth (`htpasswd`) or oauth2-proxy → your IdP.
  These credentials belong to the **web server**, not the app.

## 5. Environment variables

**None** for the app. Web-server relevant values:

| Variable / setting | Example | Purpose |
|--------------------|---------|---------|
| `server_name` | `isms.example.com` | vhost hostname |
| web root | `/var/www/jrojas` | repo root (so `../` assets resolve) |
| TLS cert/key paths | `/etc/letsencrypt/live/…/fullchain.pem` | HTTPS |
| `localStorage['bsTheme']` / `['isms_branding']` | client-side | per-browser theme/branding (informational) |

## 6. Configuration references

Minimal hardened nginx vhost (mirrors [`../nginx.conf`](../nginx.conf)):

```nginx
server {
    listen 80; server_name isms.example.com;
    return 301 https://$host$request_uri;                 # force HTTPS
}
server {
    listen 443 ssl http2; server_name isms.example.com;
    ssl_certificate     /etc/letsencrypt/live/isms.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/isms.example.com/privkey.pem;
    root /var/www/jrojas; index index.html;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    location ~* \.html$ { add_header Cache-Control "no-cache"; }
    location ~* \.(css|js|svg|ico|png|jpg|jpeg|gif|webp)$ { add_header Cache-Control "public, max-age=604800"; }
    location / { try_files $uri $uri/ =404; }

    # Optional gate for the whole site:
    # auth_basic "ISMS"; auth_basic_user_file /etc/nginx/.htpasswd;
}
```

Redirect the bare host to the library if desired: `location = / { return 302 /isms/index.html; }`.

## 7. Verification

No login/DB/upload. Verify serving, assets, headers, TLS, and client behavior:

```bash
# Publish
sudo rsync -a --delete --exclude '.git' ./ /var/www/jrojas/
sudo nginx -t && sudo systemctl reload nginx

# Entry page + assets
curl -I https://isms.example.com/isms/index.html      # 200
curl -I https://isms.example.com/isms/isms.css        # 200
curl -I https://isms.example.com/theme.css            # 200 (shared asset)

# Headers + TLS
curl -sI https://isms.example.com/isms/index.html | grep -iE 'content-security-policy|strict-transport|x-frame-options'
echo | openssl s_client -connect isms.example.com:443 2>/dev/null | openssl x509 -noout -dates
```

In a browser: hub renders, search/filters work, theme + branding persist, CSP
console clean.

## 8. Day-2 operations

- **Update content:** `git pull` (or `rsync`) into the web root; `nginx -t &&
  systemctl reload nginx` if config changed. No restart needed for content.
- **TLS renewal:** `certbot renew` via systemd timer; reload nginx on renew.
- **Backups:** the VM holds no unique state — **git is the source of truth**. Back
  up `/etc/nginx` and certs; content restores from the repo
  ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **Patching:** OS + nginx updates on schedule; no app runtime to patch.
- **Logs:** `/var/log/nginx/access.log` + `error.log`; ship to your SIEM if
  audit trails are required.
- **Harden:** firewall to 80/443, fail2ban on nginx, disable server tokens
  (`server_tokens off;`).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `/theme.css` | Web root is `isms/`, not repo root | Point `root` at the repo root |
| Unstyled pages | CDN blocked by egress firewall | Allow `cdn.jsdelivr.net` or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| Mixed-content warnings | Asset over HTTP | Ensure all origins HTTPS; keep CSP `default-src 'self'` |
| Cert errors | Expired / wrong `server_name` | `certbot renew`; verify vhost hostname |
| CSP blocks Bootstrap | CSP too strict | Use the policy above (allows jsDelivr) |
| Headers missing | `add_header` inside a `location` overrides server-level | Repeat headers in `location` or use `always` at server scope |
