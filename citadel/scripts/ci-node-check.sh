#!/usr/bin/env bash
# CITADEL CI helper: syntax-check (node --check) every JS file in the backend
# (citadel/server) and the SPA bundle (citadel/js). No execution, no deps needed
# beyond Node itself. Exits non-zero on the first file that fails to parse.
#
# Run from the repo root:  bash citadel/scripts/ci-node-check.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

fail=0
checked=0

check_file() {
  local f="$1"
  if node --check "$f"; then
    checked=$((checked + 1))
  else
    echo "::error file=$f::node --check failed to parse $f"
    fail=1
  fi
}

echo "== node --check: citadel/server (*.js, excluding node_modules) =="
while IFS= read -r -d '' f; do
  check_file "$f"
done < <(find citadel/server -name '*.js' -not -path '*/node_modules/*' -print0)

echo "== node --check: citadel/js/*.js =="
while IFS= read -r -d '' f; do
  check_file "$f"
done < <(find citadel/js -maxdepth 1 -name '*.js' -print0)

echo "Checked $checked file(s)."
if [ "$fail" -ne 0 ]; then
  echo "node --check FAILED"
  exit 1
fi
echo "node --check OK"
