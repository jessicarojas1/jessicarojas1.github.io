# Teacher Hub — static site image (nginx, non-root)
#
# Teacher Hub is a client-side static site. This image bakes in the static files
# and a hardened nginx config that ADDS the security response headers + a
# Content-Security-Policy the HTML itself does not ship.
#
# IMPORTANT — BUILD CONTEXT:
#   The app references ../theme.css and ../favicon.ico (repo root). Build from the
#   REPOSITORY ROOT so those parent files are in the context, e.g.:
#     docker build -f teacher/Dockerfile -t teacherhub:local .
#   The COPY lines below assume context = repo root and reproduce the root/child
#   layout inside the image so ../theme.css resolves for /teacher/index.html.

# nginx-unprivileged runs as uid 101 and listens on 8080 (no root needed).
FROM nginxinc/nginx-unprivileged:1.27-alpine

# Hardened server config: security headers + CSP + cache policy.
# (script-src is strict — NO 'unsafe-inline'; all JS is externalized and every
#  handler is a data-* attribute wired by delegation — see OPEN_ITEMS.md.)
COPY --chown=101:101 teacher/nginx.conf /etc/nginx/conf.d/default.conf

# Static content, reproducing repo-root layout under the web root so ../ resolves.
WORKDIR /usr/share/nginx/html
COPY --chown=101:101 theme.css      ./theme.css
COPY --chown=101:101 favicon.ico    ./favicon.ico
COPY --chown=101:101 teacher/index.html      ./teacher/index.html
COPY --chown=101:101 teacher/theme-init.js   ./teacher/theme-init.js
COPY --chown=101:101 teacher/branding.js     ./teacher/branding.js
COPY --chown=101:101 teacher/app.js          ./teacher/app.js

EXPOSE 8080

# Healthcheck: the entry page must return 200 (no app health endpoint exists).
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/teacher/ || exit 1

# Base image's default entrypoint runs nginx in the foreground.
