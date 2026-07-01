# AI Tool Evaluation Framework (aitool) — static site container
# Serves the client-side Bootstrap site from nginx. No backend, no DB, no build.
# Non-root (nginx-unprivileged), listens on 8080, healthcheck on /.
#
# NOTE: the pages reference shared parent-repo assets (../theme.css, ../script.js,
# ../roles.js, ../users.js, ../isms/isms.css, ../favicon.ico). This image ships only
# the aitool/ directory, so those "../" assets are absent unless you either:
#   (a) build from the repo root and COPY the whole site, or
#   (b) vendor the shared files into aitool/ before building (see deployments/AIRGAPPED.md).
# The build context here is the aitool/ directory.

FROM nginxinc/nginx-unprivileged:1.27-alpine

# Static content
COPY --chown=nginx:nginx . /usr/share/nginx/html/

# Security + cache headers and SPA-free static config
COPY --chown=nginx:nginx nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/index.html || exit 1

# nginx-unprivileged already runs as non-root (uid 101) and defaults to port 8080.
