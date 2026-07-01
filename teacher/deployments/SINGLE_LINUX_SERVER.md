# Teacher Hub — Single Linux Server

**Target:** one Linux VM serving Teacher Hub as static files over HTTPS with
nginx (Apache alternative noted). Suitable for a school/district that wants to
self-host the classroom hub on a small server behind TLS.

> **Applicability:** Fully applicable. Teacher Hub is static HTML/CSS/JS with no
> backend. The server's only job is to hand back files and set good cache +
> **security response headers** — including a **Content-Security-Policy, which the
> HTML does not currently ship** (see [../docs/SECURITY.md](../docs/SECURITY.md)
> and [../OPEN_ITEMS.md](../OPEN_ITEMS.md)). The edge is the right place to add it.

Related: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
[KUBERNETES.md](KUBERNETES.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

A single VM runs nginx serving a document root that contains the built site.
Because Teacher Hub references `../theme.css` and `../favicon.ico`, the document
root must reproduce that parent/child layout: publish the **whole portfolio root**
(so `/teacher/` resolves `../theme.css`) or, if publishing only the hub, copy
`theme.css`/`favicon.ico` up one level so the relative paths still resolve.

```
/var/www/teacherhub/            <- nginx root (mirrors repo root layout)
├── theme.css                   <- from repo root
├── favicon.ico                 <- from repo root
└── teacher/
    ├── index.html
    └── branding.js
```

Bootstrap + Bootstrap Icons still load from jsDelivr unless you vendor them (see
[AIRGAPPED.md](AIRGAPPED.md)). No app process, no DB, no login, no upload endpoint.

## 2. Topology

```
        Internet / School LAN
                │  443 (TLS)
                ▼
        ┌───────────────────────┐        outbound 443
        │   Linux VM            │────────────────────────► cdn.jsdelivr.net
        │   nginx (static)      │        (Bootstrap + Icons; browser fetches)
        │   /var/www/teacherhub │
        │   Let's Encrypt cert  │
        └───────────────────────┘
                │  serves /teacher/index.html, /theme.css, /branding.js
                ▼
          Teacher/Student browser  ──► localStorage (all app data, per device)
```

## 3. Prerequisites

| Item | Version / note |
|------|----------------|
| Linux VM | Ubuntu 22.04 LTS / RHEL 9 / Debian 12, 1 vCPU / 512 MB is plenty |
| nginx | 1.18+ (`nginx -v`) — or Apache 2.4 with `mod_headers` |
| certbot | for Let's Encrypt TLS (or bring a district-issued cert) |
| DNS | an A/AAAA record pointing at the VM |
| rsync / scp / git | to place files on the host |
| Outbound 443 | so browsers can reach jsDelivr (unless vendored) |

## 4. Identity & credentials

The **running site has no identity or secrets** — nothing to authenticate, no API
keys. The only credentials involved are operational:

- **Deploy identity:** an SSH deploy key or CI OIDC → short-lived SSH cert used by
  the pipeline that `rsync`s files to `/var/www/teacherhub`. Prefer a dedicated,
  least-privilege deploy user that can only write the web root:

```bash
# One-time host setup: a deploy user restricted to the web root
sudo useradd -m -s /bin/bash deploy
sudo mkdir -p /var/www/teacherhub && sudo chown -R deploy:www-data /var/www/teacherhub
sudo chmod -R 0755 /var/www/teacherhub
# deploy user's authorized_keys holds the CI/public key only
```

- **TLS:** certbot-managed Let's Encrypt private key on the host (root-owned,
  `0600`), auto-renewed by the certbot systemd timer.

Do **not** put any application secret on this box — there is no app tier to use it.

## 5. Environment variables

Teacher Hub reads **no environment variables**. The table below is the nginx/host
configuration surface, not app env:

| Variable / knob | Example | Purpose |
|-----------------|---------|---------|
| `server_name` | `teacherhub.school.k12.us` | vhost hostname |
| `root` | `/var/www/teacherhub` | document root (mirrors repo layout) |
| `ssl_certificate` | `/etc/letsencrypt/live/…/fullchain.pem` | TLS chain |
| `ssl_certificate_key` | `/etc/letsencrypt/live/…/privkey.pem` | TLS key |

No AWS/Azure/GovCloud endpoint split applies on a single VM (that split is covered
in [AWS.md](AWS.md) / [AZURE.md](AZURE.md)).

## 6. Configuration references

Recommended nginx server block — sets cache headers and **adds the security
headers the HTML lacks, including a CSP** scoped to this site + jsDelivr:

```nginx
server {
    listen 443 ssl http2;
    server_name teacherhub.school.k12.us;

    root /var/www/teacherhub;
    index teacher/index.html;

    ssl_certificate     /etc/letsencrypt/live/teacherhub.school.k12.us/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/teacherhub.school.k12.us/privkey.pem;

    # --- Security response headers (site ships none of these itself) ---
    add_header Content-Security-Policy
      "default-src 'self'; \
       style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; \
       script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; \
       font-src  'self' https://cdn.jsdelivr.net; \
       img-src   'self' data:; \
       connect-src 'self'; base-uri 'self'; frame-ancestors 'none'; \
       object-src 'none'" always;
    add_header X-Content-Type-Options    "nosniff" always;
    add_header X-Frame-Options           "DENY" always;
    add_header Referrer-Policy           "no-referrer" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Permissions-Policy        "geolocation=(), microphone=(), camera=()" always;

    # --- Caching: immutable vendored assets long, HTML short ---
    location = /teacher/index.html { add_header Cache-Control "no-cache"; }
    location ~* \.(css|js|ico|png|svg|woff2?)$ {
        add_header Cache-Control "public, max-age=604800";
    }

    location / { try_files $uri $uri/ =404; }
}

# Redirect 80 -> 443
server { listen 80; server_name teacherhub.school.k12.us; return 301 https://$host$request_uri; }
```

> **Honest note on the CSP:** the app currently uses ~109 inline `onclick`, 16
> `onchange`, and 3 `oninput` handlers plus inline `<script>`/`<style>`, so a
> *strict* CSP would break it. The policy above keeps `'unsafe-inline'` to remain
> functional. The correct long-term fix is to externalize handlers to `data-*`
> attributes + `addEventListener`, then drop `'unsafe-inline'`. Tracked in
> [../OPEN_ITEMS.md](../OPEN_ITEMS.md).
>
> **Optional gating:** to restrict to staff, front the vhost with HTTP basic-auth
> (`auth_basic` + `htpasswd`) or an `oauth2-proxy` sidecar bound to the school IdP.

## 7. Verification

No health endpoint / login / secret resolution / server upload / object write —
verify HTTP + assets + client behavior:

```bash
# Entry page 200 over TLS
curl -sSI https://teacherhub.school.k12.us/teacher/ | head -1        # HTTP/2 200

# Parent-relative assets resolve at the served layout
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.school.k12.us/theme.css        # 200
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.school.k12.us/teacher/branding.js # 200

# Security headers present (CSP added at the edge)
curl -sSI https://teacherhub.school.k12.us/teacher/ | grep -iE 'content-security-policy|strict-transport|x-frame|x-content-type'

# TLS chain valid
echo | openssl s_client -connect teacherhub.school.k12.us:443 -servername teacherhub.school.k12.us 2>/dev/null | openssl x509 -noout -dates
```

Then in a browser: theme persists, all 10 tabs switch, a plan and a gradebook
entry save and survive reload, the Gradebook **CSV downloads**, a template prints,
branding applies. See [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) §7.

## 8. Day-2 operations

| Task | Command / note |
|------|----------------|
| Deploy new version | `rsync -a --delete ./ deploy@host:/var/www/teacherhub/` (from repo root; exclude `.git`) |
| Reload nginx | `sudo nginx -t && sudo systemctl reload nginx` |
| Renew TLS | certbot systemd timer auto-renews; test `sudo certbot renew --dry-run` |
| Rotate deploy key | replace CI public key in `deploy`'s `authorized_keys` |
| Logs | `/var/log/nginx/access.log`, `error.log`; ship to syslog/SIEM if required |
| Backups | the site is rebuildable from git — back up **nginx config + TLS certs** only; there is no app database. Student data lives in browsers, not on this server (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)). |
| Scaling | vertical is unnecessary; static files are trivially cacheable — put a CDN in front if load grows |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `/theme.css` | doc root missing parent files | publish repo-root layout, or copy `theme.css`/`favicon.ico` into `root` |
| Unstyled / no icons | jsDelivr blocked by school firewall | allowlist `cdn.jsdelivr.net`, or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| CSP blocks the page | strict CSP without `'unsafe-inline'` | keep `'unsafe-inline'` until handlers are externalized (see §6 note) |
| Mixed-content warnings | referencing http assets over https | ensure all resources are https (they are, via jsDelivr) |
| Cert renewal fails | port 80 closed for HTTP-01 | open 80 for the ACME challenge or use DNS-01 |
| Old version cached | long `Cache-Control` on HTML | keep `no-cache` on `index.html`; hard-reload to confirm |
