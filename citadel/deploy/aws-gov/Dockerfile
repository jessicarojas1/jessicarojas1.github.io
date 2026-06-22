# =============================================================================
# CITADEL — Production container image (AWS GovCloud (US) deployment)
# Multi-stage, hardened, non-root, read-only-root-FS compatible.
#
# Stage 1 (build): assemble the static CITADEL SPA (HTML/CSS/JS).
# Stage 2 (final): minimal Nginx runtime serving the SPA over a non-priv port.
#
# FIPS NOTE: For CUI / FedRAMP High / IL4-IL5, replace the runtime base with a
# FIPS 140-3 validated image. Recommended GovCloud-friendly options:
#   - Chainguard nginx-fips (cgr.dev/chainguard/nginx-fips)
#   - A RHEL UBI9 + nginx image running with OpenSSL in FIPS mode
#     (build FROM registry.access.redhat.com/ubi9/ubi-minimal and enable FIPS)
# The data-in-transit FIPS boundary is also enforced at the ALB (TLS1.2+ FIPS
# policy) and all AWS SDK calls use FIPS endpoints (AWS_USE_FIPS_ENDPOINT=true).
# =============================================================================

# ---- Stage 1: build / assemble the SPA --------------------------------------
# base image: node:20-alpine
FROM node:20-alpine@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS build
WORKDIR /src

# Copy the public client-side SPA sources. Adjust the build context as needed;
# deploy.sh sets the context to the CITADEL app root.
COPY . /src

# If a build step exists (bundler/minifier), run it here. The demo SPA is plain
# static assets, so this is a no-op guard that still produces /dist.
RUN if [ -f package.json ]; then \
        npm ci --no-audit --no-fund && npm run build || true; \
    fi; \
    mkdir -p /dist; \
    if [ -d dist ]; then cp -r dist/* /dist/; \
    elif [ -d public ]; then cp -r public/* /dist/; \
    else cp -r . /dist/; fi; \
    # never ship VCS metadata, IaC, or secrets into the image
    rm -rf /dist/.git /dist/.github /dist/deploy /dist/node_modules /dist/*.tf /dist/.env*

# ---- Stage 2: hardened runtime ----------------------------------------------
# Pinned to an immutable digest for supply-chain integrity (SI-7, CM-2).
# base image: nginx:1.27-alpine
FROM nginx:1.31-alpine@sha256:20316569d8f81a160065d7d2a5eeffc7ca97d79022462ee255fd23fa103a6b5c AS runtime

LABEL org.opencontainers.image.title="CITADEL" \
      org.opencontainers.image.description="Code Inspection, Threat Analysis & Deployment Evaluation Lab" \
      org.opencontainers.image.vendor="CITADEL" \
      io.citadel.classification="CUI" \
      io.citadel.compliance="FedRAMP-High;CMMC-L2;NIST-800-171;IL4-IL5"

# Drop the bundled default config and add the hardened one.
RUN rm -f /etc/nginx/conf.d/default.conf /etc/nginx/nginx.conf
COPY nginx.conf /etc/nginx/nginx.conf

# Static site assets.
COPY --from=build /dist /usr/share/nginx/html

# Create a non-root user (UID/GID 10001) and the writable temp dirs Nginx needs
# when the container root filesystem is mounted read-only.
RUN addgroup -g 10001 -S citadel \
 && adduser  -u 10001 -S citadel -G citadel \
 && mkdir -p /tmp/nginx/client_body /tmp/nginx/proxy /tmp/nginx/fastcgi \
             /tmp/nginx/uwsgi /tmp/nginx/scgi /var/cache/nginx \
 && chown -R 10001:10001 /tmp/nginx /var/cache/nginx /usr/share/nginx/html \
 && chmod -R go-w /usr/share/nginx/html

# Run as the non-root user on a non-privileged port (AC-6, CM-7).
USER 10001:10001
EXPOSE 8080

# Container-level healthcheck mirrors the ALB target group probe.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

# nginx.conf already sets daemon-off-compatible foreground operation via the
# default entrypoint; run explicitly in the foreground.
CMD ["nginx", "-g", "daemon off;"]
