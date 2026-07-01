# ISMS Document Library — static site container
# -----------------------------------------------------------------------------
# Type A (static website). No build step, no backend, no DB, no server-side code.
# Serves the ISO 27001 ISMS document library as static HTML/CSS/JS over nginx.
#
# The library lives at /isms/ and references a few shared assets from the parent
# portfolio via relative paths (../theme.css, ../script.js, ../roles.js,
# ../users.js, ../favicon.ico). To keep those links working, the build context
# is the REPOSITORY ROOT and both the library and the shared assets are copied,
# preserving the ../ layout:
#
#     /usr/share/nginx/html/isms/     ← this library (entry: /isms/index.html)
#     /usr/share/nginx/html/theme.css ← shared portfolio stylesheet
#     /usr/share/nginx/html/*.js      ← shared portfolio scripts
#
# Build (from the repository root):
#     docker build -f isms/Dockerfile -t isms-library:latest .
# Run:
#     docker run --rm -p 8080:8080 isms-library:latest
#     open http://localhost:8080/isms/index.html
#
# Image runs as a NON-ROOT user (nginx-unprivileged, uid 101) and listens on 8080.
# -----------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.27-alpine

# Non-root by default in this image (uid 101). Serve on 8080.
USER 101

# App config: gzip, cache headers, security headers, SPA-friendly rewrite.
COPY --chown=101:101 isms/nginx.conf /etc/nginx/conf.d/default.conf

# The ISMS library itself.
COPY --chown=101:101 isms/ /usr/share/nginx/html/isms/

# Shared parent-portfolio assets the library links to via ../ relative paths.
COPY --chown=101:101 theme.css script.js roles.js users.js favicon.ico /usr/share/nginx/html/

EXPOSE 8080

# Healthcheck: the entry page must return HTTP 200.
HEALTHCHECK --interval=30s --timeout=4s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/isms/index.html || exit 1

# nginx-unprivileged base image already sets the correct CMD/ENTRYPOINT.
