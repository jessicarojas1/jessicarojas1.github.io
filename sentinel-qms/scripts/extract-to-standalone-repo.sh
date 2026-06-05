#!/usr/bin/env bash
#
# Extract sentinel-qms/ from the portfolio repo into its own standalone
# GitHub repository, preserving only the QMS history.
#
# Prereqs: git, and the GitHub CLI (`gh`) authenticated as the target account
#          (run `gh auth login` first). Run this from the portfolio repo root.
#
# Usage:
#   ./sentinel-qms/scripts/extract-to-standalone-repo.sh [REPO_NAME] [--private]
#
# Defaults: REPO_NAME=sentinel-qms, public.
#
set -euo pipefail

REPO_NAME="${1:-sentinel-qms}"
VISIBILITY="--public"
if [ "${2:-}" = "--private" ]; then VISIBILITY="--private"; fi

PREFIX="sentinel-qms"
SRC_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
EXPORT_BRANCH="_sentinel_export_tmp"

echo "==> Source branch:   $SRC_BRANCH"
echo "==> Subtree prefix:  $PREFIX/"
echo "==> New repository:  $REPO_NAME ($VISIBILITY)"

if [ ! -d "$PREFIX" ]; then
  echo "ERROR: '$PREFIX/' not found. Run this from the portfolio repo root on the"
  echo "       branch that contains the Sentinel QMS project."
  exit 1
fi

# 1. Split sentinel-qms/ into its own history on a temporary branch.
echo "==> Splitting subtree history..."
git branch -D "$EXPORT_BRANCH" 2>/dev/null || true
git subtree split --prefix="$PREFIX" -b "$EXPORT_BRANCH"

# 2. Create the standalone repo on GitHub (no push yet).
OWNER="$(gh api user --jq .login)"
echo "==> Creating $OWNER/$REPO_NAME ..."
gh repo create "$OWNER/$REPO_NAME" $VISIBILITY \
  --description "Sentinel QMS — Enterprise Quality Management System (AS9100D / CMMC L2 / NIST 800-171), deployable to AWS GovCloud and Azure Government." \
  || echo "    (repo may already exist; continuing)"

# 3. Push the split history as the new repo's main branch.
echo "==> Pushing to standalone repo main..."
git push "https://github.com/$OWNER/$REPO_NAME.git" "$EXPORT_BRANCH:main"

# 4. Clean up.
git branch -D "$EXPORT_BRANCH"

echo ""
echo "Done. Standalone repo: https://github.com/$OWNER/$REPO_NAME"
echo "Clone it with: git clone https://github.com/$OWNER/$REPO_NAME.git"
