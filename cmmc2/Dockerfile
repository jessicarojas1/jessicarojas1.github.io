# syntax=docker/dockerfile:1
#
# cmmc2 — CMMC 2.0 Readiness Assessment Platform (static site) served by nginx.
#
# IMPORTANT: build context = the REPO ROOT (the parent of cmmc2/), NOT cmmc2/ itself.
# index.html references parent-relative shared assets (../theme.css, ../favicon.ico,
# ../users.js, ../roles.js, ../script.js, ../analytics.js, ../siteSearch.js) and the
# navbar brand links to ../index.html. Those files live at the repo root, so we copy the
# needed parent assets in alongside cmmc2/ and serve the whole tree; the app is reached at
# the /cmmc2/ subpath — matching how it runs in the portfolio.
#
# Build (from repo root):
#   docker build -f cmmc2/Dockerfile -t cmmc2:local .
# Run:
#   docker run --rm -p 8080:8080 cmmc2:local
#   # then open http://localhost:8080/cmmc2/
#
# NOTE ON CDN: Bootstrap 5.3.3, Bootstrap Icons 1.11.3 and SheetJS are loaded from
# cdn.jsdelivr.net at runtime; this image does NOT vendor them. For a fully offline image
# see deployments/AIRGAPPED.md.

FROM nginxinc/nginx-unprivileged:1.27-alpine

# nginx-unprivileged runs as uid 101 (non-root) and listens on 8080 by default.
USER root

# Hardened server config: adds security headers + a CSP HTTP header mirroring the page's
# <meta> CSP, sets sensible cache-control, and a /healthz endpoint for the healthcheck.
COPY cmmc2/nginx.conf /etc/nginx/conf.d/default.conf

# --- Static content -------------------------------------------------------------------
# The app itself:
COPY cmmc2/index.html   /usr/share/nginx/html/cmmc2/index.html
COPY cmmc2/branding.js  /usr/share/nginx/html/cmmc2/branding.js
# Parent-relative shared assets the page depends on (kept at web root so ../ resolves):
COPY theme.css     /usr/share/nginx/html/theme.css
COPY favicon.ico   /usr/share/nginx/html/favicon.ico
COPY users.js      /usr/share/nginx/html/users.js
COPY roles.js      /usr/share/nginx/html/roles.js
COPY script.js     /usr/share/nginx/html/script.js
COPY analytics.js  /usr/share/nginx/html/analytics.js
COPY siteSearch.js /usr/share/nginx/html/siteSearch.js
# Portfolio home target for the navbar brand / Home link (optional but avoids a 404):
COPY index.html    /usr/share/nginx/html/index.html

RUN chown -R 101:101 /usr/share/nginx/html
USER 101

EXPOSE 8080

# Healthcheck: nginx serves /healthz (see nginx.conf). busybox wget ships in the alpine image.
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

CMD ["nginx", "-g", "daemon off;"]
