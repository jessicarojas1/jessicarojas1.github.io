#!/usr/bin/env bash
# =============================================================================
# CITADEL — unified deploy entrypoint for AWS GovCloud / Azure Government
# -----------------------------------------------------------------------------
# Builds & ships the deep-scan backend to the chosen cloud using that target's
# Infrastructure-as-Code, then prints how to run the post-deploy initialization
# (scanner database hydration — see scripts/init.sh).
#
# Usage:
#   ./deploy.sh aws    [-- extra args forwarded to deploy/aws-gov/deploy.sh]
#   ./deploy.sh azure  [-- extra args forwarded to deploy/azure-gov/deploy.sh]
#
# Prerequisites: Docker, plus the target CLI/IaC (aws+terraform | az+bicep).
# =============================================================================
set -euo pipefail

CITADEL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-}"; [ $# -gt 0 ] && shift || true
[ "${1:-}" = "--" ] && shift || true

case "$TARGET" in
  aws|aws-gov|awsgov)        SUBDIR="deploy/aws-gov";   LABEL="AWS GovCloud (US)";   IAC="Terraform" ;;
  azure|azure-gov|azuregov)  SUBDIR="deploy/azure-gov"; LABEL="Azure Government";    IAC="Bicep" ;;
  *)
    echo "Usage: $0 <aws|azure> [-- extra args]" >&2
    echo "  aws    -> ${CITADEL_DIR}/deploy/aws-gov/deploy.sh   (Terraform)" >&2
    echo "  azure  -> ${CITADEL_DIR}/deploy/azure-gov/deploy.sh (Bicep)" >&2
    exit 2 ;;
esac

SCRIPT="${CITADEL_DIR}/${SUBDIR}/deploy.sh"
[ -x "$SCRIPT" ] || { echo "[citadel] Missing or non-executable: $SCRIPT" >&2; chmod +x "$SCRIPT" 2>/dev/null || true; }

echo "=============================================================="
echo "[citadel] Target : ${LABEL}  (${IAC})"
echo "[citadel] IaC dir: ${SUBDIR}"
echo "=============================================================="

# 1) Provision + deploy via the target's own runbook script.
( cd "${CITADEL_DIR}/${SUBDIR}" && bash ./deploy.sh "$@" )

# 2) Post-deploy initialization. The scanner DBs must be hydrated INSIDE the
#    running container/instance (the deploy host usually lacks the scanners),
#    so we print the exact command rather than running it on the host.
cat <<EOF

[citadel] Deployment submitted to ${LABEL}.

Next — hydrate the scanner databases ("the queries required") inside the running
service, then verify readiness:

  # AWS GovCloud (ECS exec into the task):
  aws ecs execute-command --cluster citadel --task <task-id> \\
      --container citadel --interactive --command "/app/scripts/init.sh"

  # Azure Government (Container Apps exec):
  az containerapp exec -g <rg> -n citadel --command "/app/scripts/init.sh"

  # Or locally / any Docker host:
  docker exec -it <container> /app/scripts/init.sh

Health & live scanner status:  curl https://<service-url>/api/health
EOF
