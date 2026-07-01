# syntax=docker/dockerfile:1
#
# CMMI v2.0 Practice Reference — static nginx image (non-root, healthchecked).
#
# IMPORTANT — BUILD CONTEXT MUST BE THE REPOSITORY ROOT.
# This app depends on parent-relative assets that live one level ABOVE cmmi/:
#   ../cmmidev3.js (~227 KB, REQUIRED), ../theme.css, ../favicon.ico,
#   ../users.js, ../roles.js, ../script.js, ../analytics.js, ../siteSearch.js
# The image reproduces the site's URL layout so `/cmmi/` and its `../` refs resolve:
#   /usr/share/nginx/html/cmmi/...   and   /usr/share/nginx/html/<parent assets>
#
# Build (from the repo root):
#   docker build -f cmmi/Dockerfile -t cmmi-ref .
# Run:
#   docker run --rm -p 8080:8080 cmmi-ref   # → http://localhost:8080/cmmi/

FROM nginxinc/nginx-unprivileged:1.27-alpine

# nginx-unprivileged runs as UID 101 (nginx) and listens on 8080 by default.
USER root

# ---- App files (paths are relative to the repo root build context) ----
# The cmmi/ subfolder itself:
COPY cmmi/index.html      /usr/share/nginx/html/cmmi/index.html
COPY cmmi/branding.js     /usr/share/nginx/html/cmmi/branding.js

# The parent-relative assets the page references with ../ :
COPY cmmidev3.js          /usr/share/nginx/html/cmmidev3.js
COPY theme.css            /usr/share/nginx/html/theme.css
COPY favicon.ico          /usr/share/nginx/html/favicon.ico
COPY users.js             /usr/share/nginx/html/users.js
COPY roles.js             /usr/share/nginx/html/roles.js
COPY script.js            /usr/share/nginx/html/script.js
COPY analytics.js         /usr/share/nginx/html/analytics.js
COPY siteSearch.js        /usr/share/nginx/html/siteSearch.js

# ---- Hardened static server config with edge security headers ----
# CSP is already delivered by the page's <meta> tag; these headers add
# transport/anti-clickjacking/anti-sniffing defense-in-depth at the edge.
RUN printf '%s\n' \
  'server {' \
  '    listen 8080;' \
  '    server_name _;' \
  '    root /usr/share/nginx/html;' \
  '    index index.html;' \
  '    add_header X-Content-Type-Options "nosniff" always;' \
  '    add_header X-Frame-Options "SAMEORIGIN" always;' \
  '    add_header Referrer-Policy "strict-origin-when-cross-origin" always;' \
  '    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;' \
  '    location = /healthz { default_type text/plain; return 200 "ok\n"; }' \
  '    location / { try_files $uri $uri/ =404; }' \
  '    location ~* \.(?:js|css|ico)$ { expires 1h; add_header Cache-Control "public, max-age=3600"; }' \
  '}' > /etc/nginx/conf.d/default.conf

USER 101

EXPOSE 8080

# Healthcheck hits the lightweight /healthz endpoint on the unprivileged port.
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

CMD ["nginx", "-g", "daemon off;"]
