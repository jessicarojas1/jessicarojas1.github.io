#!/usr/bin/env bash
# =============================================================================
# CITADEL — post-deploy initialization ("run all required queries")
# -----------------------------------------------------------------------------
# CITADEL is stateless (no SQL database), so there is nothing to migrate. What
# every scanner DOES need before its first real scan is its data set hydrated:
#   - Trivy   : vulnerability + Java DBs
#   - Grype   : vulnerability DB
#   - ClamAV  : malware signature DB (freshclam)
# This script pulls them all, idempotently and non-fatally, then prints a
# readiness summary. Run it INSIDE the running container/instance after deploy
# (e.g. as a one-off job, a startup hook, or a scheduled refresh).
#
# Usage:  ./init.sh            # hydrate everything that is installed
#         ./init.sh --check    # only report which scanners + DBs are ready
# =============================================================================
set -uo pipefail

CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

have() { command -v "$1" >/dev/null 2>&1; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
skip() { printf '  \033[33m–\033[0m %s\n' "$1"; }

echo "[citadel] Scanner initialization $( [ $CHECK_ONLY -eq 1 ] && echo '(check only)' )"

# --- Semgrep / Bandit (no DB; registry rules fetched per-run) ----------------
have semgrep && ok "Semgrep $(semgrep --version 2>/dev/null | head -1)" || skip "Semgrep not installed"
have bandit  && ok "Bandit $(bandit --version 2>&1 | head -1)"          || skip "Bandit not installed"
have gitleaks && ok "Gitleaks $(gitleaks version 2>/dev/null)"          || skip "Gitleaks not installed"

# --- Trivy vulnerability DBs --------------------------------------------------
if have trivy; then
  if [ $CHECK_ONLY -eq 0 ]; then
    echo "[citadel] Pulling Trivy databases…"
    trivy --cache-dir "${TRIVY_CACHE_DIR:-/tmp/citadel/trivy}" image --download-db-only      2>/dev/null \
      || trivy fs --download-db-only "${TRIVY_CACHE_DIR:+--cache-dir $TRIVY_CACHE_DIR}" /tmp 2>/dev/null || true
    trivy --cache-dir "${TRIVY_CACHE_DIR:-/tmp/citadel/trivy}" image --download-java-db-only  2>/dev/null || true
  fi
  ok "Trivy $(trivy --version 2>/dev/null | head -1)"
else skip "Trivy not installed"; fi

# --- Grype vulnerability DB ---------------------------------------------------
if have grype; then
  [ $CHECK_ONLY -eq 0 ] && { echo "[citadel] Updating Grype DB…"; grype db update 2>/dev/null || true; }
  ok "Grype $(grype version 2>/dev/null | grep -i version | head -1)"
else skip "Grype not installed"; fi

# --- Syft (no DB) -------------------------------------------------------------
have syft && ok "Syft $(syft version 2>/dev/null | grep -i version | head -1)" || skip "Syft not installed"

# --- ClamAV signature DB ------------------------------------------------------
if have clamscan; then
  if [ $CHECK_ONLY -eq 0 ]; then
    echo "[citadel] Updating ClamAV signatures (freshclam)…"
    freshclam --quiet 2>/dev/null || true
  fi
  ok "ClamAV $(clamscan --version 2>/dev/null | head -1)"
else skip "ClamAV not installed"; fi

echo "[citadel] Initialization complete. The API reports live status at /api/health."
