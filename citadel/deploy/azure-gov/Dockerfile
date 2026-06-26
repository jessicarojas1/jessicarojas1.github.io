# =============================================================================
# CITADEL — production front-end image (hardened, multi-stage)
# Code Inspection, Threat Analysis & Deployment Evaluation Lab
#
# Stage 1 (build): assemble the static SPA build artifacts.
# Stage 2 (final): minimal, non-root Nginx serving the SPA with strict headers.
#
# FIPS 140-3 note:
#   For environments that mandate FIPS-validated crypto in-container, replace the
#   final base image with a FIPS-validated minimal image, e.g.:
#     - Chainguard:  cgr.dev/chainguard/nginx:latest  (FIPS variant)
#     - Red Hat UBI: registry.access.redhat.com/ubi9/nginx-1.22  (FIPS host + openssl-fips)
#   The TLS termination for external traffic is performed by the Azure WAF /
#   Front Door Gov (FIPS-validated platform crypto); in-container TLS is optional.
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1 — build / assemble the static SPA
# ---------------------------------------------------------------------------
# base image: node:20-alpine
FROM node:20-alpine@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS build

WORKDIR /build

# Copy manifests first for layer caching (no-op if the SPA has no build step).
COPY package*.json ./
# Install only if a package.json with a build pipeline is present; otherwise skip.
RUN if [ -f package.json ]; then npm ci --no-audit --no-fund || true; fi

# Copy the static SPA sources (HTML/CSS/JS, JSZip + Chart.js are loaded from jsdelivr).
COPY . .

# Run a build if one is defined; otherwise stage the raw static assets.
RUN set -eux; \
    if [ -f package.json ] && npm run | grep -q ' build'; then \
      npm run build && mkdir -p /artifacts && cp -r dist/* /artifacts/ 2>/dev/null || cp -r build/* /artifacts/; \
    else \
      mkdir -p /artifacts && cp -r public/* /artifacts/ 2>/dev/null || cp -r src/* /artifacts/ 2>/dev/null || cp -r . /artifacts/; \
    fi

# ---------------------------------------------------------------------------
# Stage 2 — hardened runtime (non-root Nginx, FIPS-friendly minimal base)
# ---------------------------------------------------------------------------
# base image: nginx:1.27-alpine
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10 AS final

LABEL org.opencontainers.image.title="CITADEL Front-End" \
      org.opencontainers.image.description="Code Inspection, Threat Analysis & Deployment Evaluation Lab" \
      org.opencontainers.image.vendor="CITADEL" \
      io.citadel.classification="CUI" \
      io.citadel.impact-level="IL4"

# Patch base packages, remove the default config, and add curl for the healthcheck.
RUN set -eux; \
    apk update && apk upgrade --no-cache; \
    apk add --no-cache curl; \
    rm -f /etc/nginx/conf.d/default.conf; \
    rm -rf /var/cache/apk/*

# Hardened server config (security headers, /healthz, size limits, caching).
COPY nginx.conf /etc/nginx/nginx.conf

# Static SPA assets.
COPY --from=build /artifacts/ /usr/share/nginx/html/

# ---- Run as non-root on an unprivileged port -------------------------------
# The stock nginx 'nginx' user (uid 101) is reused; make writable paths it owns,
# and relocate the pid to a non-privileged, writable location.
RUN set -eux; \
    mkdir -p /var/cache/nginx /var/run/nginx /tmp/nginx; \
    chown -R nginx:nginx /var/cache/nginx /var/run/nginx /tmp/nginx /usr/share/nginx/html; \
    chmod -R 0550 /usr/share/nginx/html; \
    touch /var/run/nginx/nginx.pid; \
    chown nginx:nginx /var/run/nginx/nginx.pid

# Drop to the unprivileged user. Combined with Container Apps / Kubernetes
# securityContext (runAsNonRoot, allowPrivilegeEscalation=false, drop ALL caps),
# this satisfies least-privilege runtime requirements (AC-6).
USER nginx

# Non-privileged listen port (matches containerPort=8080 in main.bicep).
EXPOSE 8080

# Liveness/readiness probe target served by nginx.conf.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8080/healthz || exit 1

# 'daemon off' keeps nginx in the foreground as PID 1's child.
CMD ["nginx", "-g", "daemon off;"]
