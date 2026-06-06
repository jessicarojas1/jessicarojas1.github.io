# =============================================================================
# CITADEL — Deep-Scan Backend image
# -----------------------------------------------------------------------------
# Node 20 Express API that runs REAL open-source security scanners over uploaded
# code and serves the CITADEL static SPA. Unlike the front-end-only demo image
# under citadel/deploy/, this image bundles the full scanner toolchain:
#   Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV.
#
# BUILD CONTEXT: the *repository root*, so the image can COPY both the SPA
# (citadel/) and the backend (citadel/server/). Build it with:
#
#     docker build -f citadel/server/Dockerfile -t citadel-server .
#
# (Note the trailing "." — context is the repo root, NOT citadel/server/.)
#
# Debian bookworm-slim is used (not Alpine) because Semgrep/Bandit (Python),
# Trivy/Syft/Grype/Gitleaks (glibc binaries) and ClamAV all expect glibc +
# CPython. Single, well-commented stage: most of the image size IS the scanner
# toolchain, so a multi-stage split buys little here.
# =============================================================================
FROM node:20-bookworm-slim

LABEL org.opencontainers.image.title="CITADEL Deep-Scan Backend" \
      org.opencontainers.image.description="Express API running Semgrep/Bandit/Trivy/Syft/Grype/Gitleaks/ClamAV over uploaded code" \
      org.opencontainers.image.vendor="CITADEL" \
      io.citadel.classification="CUI" \
      io.citadel.compliance="FedRAMP-High;CMMC-L2;NIST-800-171;IL4-IL5"

# -----------------------------------------------------------------------------
# Tool versions — pinned where the upstream installer supports it so the image
# is reproducible (CM-2). Bump deliberately and re-test the adapters.
# -----------------------------------------------------------------------------
ARG SEMGREP_VERSION=1.86.0
ARG BANDIT_VERSION=1.7.10
ARG TRIVY_VERSION=0.58.1
ARG SYFT_VERSION=1.18.1
ARG GRYPE_VERSION=0.87.0
ARG GITLEAKS_VERSION=8.21.2

ENV NODE_ENV=production \
    PORT=8080 \
    # The SPA is copied to /app/citadel in this image; tell the server where it is.
    CITADEL_APP_DIR=/app/citadel \
    # Scratch dir for unpacking and scanning UNTRUSTED uploads. Lives under /tmp
    # so it works on a read-only root filesystem (tmpfs/volume mounted here).
    CITADEL_TMP=/tmp/citadel \
    # Keep Python/pip quiet and cache-free to slim the image.
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    # Put our Python venv on PATH so semgrep/bandit resolve as bare commands.
    PATH="/opt/venv/bin:${PATH}"

# -----------------------------------------------------------------------------
# Base OS packages: glibc toolchain prerequisites for every scanner.
#   python3 / venv  -> Semgrep + Bandit
#   ca-certificates -> TLS for installer scripts & DB updates
#   curl / git / unzip -> fetch release binaries, clone-aware scans, extract zips
# -----------------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        python3-venv \
        ca-certificates \
        curl \
        git \
        gnupg \
        unzip; \
    rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Semgrep + Bandit (Python SAST)
# bookworm marks the system Python as "externally managed" (PEP 668), so we use
# a dedicated venv at /opt/venv instead of --break-system-packages. The venv is
# on PATH (see ENV above), keeping system Python clean and tools isolated.
#   Semgrep -> multi-language data-flow-aware static analysis (the core SAST).
#   Bandit  -> Python-specific AST security linter.
# -----------------------------------------------------------------------------
RUN set -eux; \
    python3 -m venv /opt/venv; \
    /opt/venv/bin/pip install --no-cache-dir --upgrade pip; \
    /opt/venv/bin/pip install --no-cache-dir \
        "semgrep==${SEMGREP_VERSION}" \
        "bandit==${BANDIT_VERSION}"

# -----------------------------------------------------------------------------
# Trivy (Aqua Security) — filesystem vulnerability + secret + IaC misconfig scan
# Installed from Aqua's official APT repository (served from GitHub Pages, NOT
# the GitHub API — so it is not rate-limited on shared CI/build IPs, and we don't
# depend on a specific release shipping a binary asset).
# NON-FATAL: if the repo is unreachable, the build still succeeds and the server
# simply skips Trivy at runtime (Grype provides overlapping CVE coverage).
# The Trivy vuln DB downloads on first run; pre-pull with `trivy --download-db-only`.
# -----------------------------------------------------------------------------
RUN set -eux; \
    ( set -e; \
      curl -sfL https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /usr/share/keyrings/trivy.gpg; \
      echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list; \
      apt-get update; \
      apt-get install -y --no-install-recommends trivy; \
      trivy --version ) \
    || echo "[citadel] Trivy install skipped — Grype still provides CVE coverage"; \
    rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Syft (Anchore) — Software Bill of Materials generation (CycloneDX / SPDX)
# Direct pinned release tarball (avoids the API-driven install.sh). NON-FATAL.
# -----------------------------------------------------------------------------
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    ( set -e; \
      curl -sSfL -o /tmp/syft.tar.gz \
        "https://github.com/anchore/syft/releases/download/v${SYFT_VERSION}/syft_${SYFT_VERSION}_linux_${arch}.tar.gz"; \
      tar -xzf /tmp/syft.tar.gz -C /usr/local/bin syft; \
      chmod +x /usr/local/bin/syft; \
      syft version ) \
    || echo "[citadel] Syft install skipped — SBOM falls back to manifest parsing"; \
    rm -f /tmp/syft.tar.gz

# -----------------------------------------------------------------------------
# Grype (Anchore) — vulnerability matching against an SBOM / directory
# Direct pinned release tarball. NON-FATAL. Grype's vuln DB downloads on first
# run; pre-pull with `grype db update`.
# -----------------------------------------------------------------------------
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    ( set -e; \
      curl -sSfL -o /tmp/grype.tar.gz \
        "https://github.com/anchore/grype/releases/download/v${GRYPE_VERSION}/grype_${GRYPE_VERSION}_linux_${arch}.tar.gz"; \
      tar -xzf /tmp/grype.tar.gz -C /usr/local/bin grype; \
      chmod +x /usr/local/bin/grype; \
      grype version ) \
    || echo "[citadel] Grype install skipped — OSV/Trivy still provide CVE coverage"; \
    rm -f /tmp/grype.tar.gz

# -----------------------------------------------------------------------------
# Gitleaks (GitHub release binary) — secrets / credential detection
# Direct pinned release tarball; resolve host arch to the upstream naming. NON-FATAL.
# -----------------------------------------------------------------------------
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
        amd64) gl_arch="x64" ;; \
        arm64) gl_arch="arm64" ;; \
        *) gl_arch="x64" ;; \
    esac; \
    ( set -e; \
      curl -sSfL -o /tmp/gitleaks.tar.gz \
        "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${gl_arch}.tar.gz"; \
      tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks; \
      chmod +x /usr/local/bin/gitleaks; \
      gitleaks version ) \
    || echo "[citadel] Gitleaks install skipped — entropy/Trivy secret scan still runs"; \
    rm -f /tmp/gitleaks.tar.gz

# -----------------------------------------------------------------------------
# ClamAV — malware / signature scanning of uploaded files
# We install the engine + freshclam updater and seed the signature DB at build
# time so the image is usable immediately. The DB ages quickly: run `freshclam`
# at container startup (or on a schedule / sidecar) to refresh — see README.
# `|| true` keeps the build resilient to transient mirror failures; a missing DB
# degrades gracefully (the server skips ClamAV).
# -----------------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends clamav clamav-freshclam; \
    rm -rf /var/lib/apt/lists/*; \
    ( freshclam || true )

# -----------------------------------------------------------------------------
# Application: install backend deps, then copy the SPA + server sources.
# package.json / package-lock.json are owned by another process; we just use
# them. `npm ci --omit=dev` gives a clean, lockfile-exact production install.
# -----------------------------------------------------------------------------
WORKDIR /app

# Copy the backend manifest first to maximize Docker layer caching on deps.
COPY citadel/server/package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund

# The SPA the server serves (one level "up" from server/ in the source tree).
# It lands at /app/citadel so server.js can serve it as the static root.
COPY citadel/ /app/citadel/
# The backend sources (server.js, lib/, etc.) at the app root.
COPY citadel/server/ /app/

# -----------------------------------------------------------------------------
# Hardening: non-root user + writable scratch dir for untrusted uploads.
# Uploaded code is NEVER executed — scanners only READ it — so the container is
# safe to run read-only with just /tmp/citadel writable (mounted as tmpfs or a
# volume in compose / IaC).
# -----------------------------------------------------------------------------
RUN set -eux; \
    groupadd -g 10001 citadel; \
    useradd  -u 10001 -g 10001 -M -s /usr/sbin/nologin citadel; \
    mkdir -p "${CITADEL_TMP}"; \
    # ClamAV DB dir must be writable for runtime freshclam updates.
    chown -R 10001:10001 "${CITADEL_TMP}" /app /var/lib/clamav 2>/dev/null || true

USER 10001:10001
EXPOSE 8080

# Container healthcheck mirrors the orchestrator/ALB probe on /api/health.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT}/api/health" || exit 1

# Run the API in the foreground as the non-root user.
CMD ["node", "server.js"]
