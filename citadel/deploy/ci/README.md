# CITADEL — CI reference workflows

This folder holds **reference** GitHub Actions workflows. Like
`citadel/.github-workflow-example.yml`, they live under `deploy/ci/` and are
**not** installed into `.github/workflows` automatically — you copy them in when
you're ready (see "Wiring it up" below).

| Workflow | Purpose |
|---|---|
| `iac-validate.yml` | Lints/validates all Infrastructure-as-Code (see below). |
| `backend-ci.yml` | Backend test suite + ESLint + **line-coverage threshold gate** (`npm run test:coverage:gate`, lines≥80 / funcs≥70 / branches≥60), the curated accuracy benchmark gate, and a **scheduled, non-blocking OWASP Benchmark** run (artifact-uploaded). Copy to `.github/workflows/citadel-backend-ci.yml`. |

## `iac-validate.yml`

`iac-validate.yml` lints and validates all of CITADEL's Infrastructure-as-Code.

## What it checks

It triggers on pull requests (and pushes to `main`) that touch
`citadel/deploy/**`, then runs these jobs in parallel:

| Job                | Tool(s)                                  | Target |
|--------------------|------------------------------------------|--------|
| `terraform`        | `fmt -check` · `init -backend=false` · `validate` | `deploy/aws-gov`, `deploy/aws`, `deploy/gcp` (matrix) |
| `terraform-lint`   | `tflint` · `checkov` · `trivy config`    | all Terraform under `deploy/` |
| `bicep`            | `az bicep build`                         | `deploy/azure-gov/main.bicep` |
| `helm`             | `helm lint` · `helm template \| kubeconform` | `deploy/kubernetes/citadel` |
| `hadolint`         | `hadolint`                               | `citadel/server/Dockerfile`, `deploy/*/Dockerfile` |
| `compose`          | `docker compose config`                  | `deploy/compose/docker-compose.yml` |
| `yamllint`         | `yamllint` (non-blocking)                | manifests under `deploy/` |

**Tolerant of absent targets.** Directories/files that don't exist yet
(`deploy/aws`, `deploy/gcp`, the Helm chart, the Bicep file, …) are detected and
skipped cleanly with a `::notice::` instead of failing the run. Security scanners
(`checkov`, `trivy`) and `yamllint` are **report-only** by default
(`continue-on-error` / `soft_fail`); flip the documented switches in the
workflow to turn them into hard gates once you've agreed a baseline.

## Run the same checks locally

```bash
# --- Terraform (per dir) ---
for d in citadel/deploy/aws-gov citadel/deploy/aws citadel/deploy/gcp; do
  [ -n "$(find "$d" -maxdepth 1 -name '*.tf' 2>/dev/null)" ] || continue
  ( cd "$d" && terraform fmt -check -recursive \
      && terraform init -backend=false -input=false \
      && terraform validate )
done

# --- Terraform lint / security ---
tflint --recursive --chdir citadel/deploy
checkov -d citadel/deploy --framework terraform --quiet --soft-fail
trivy config citadel/deploy --severity HIGH,CRITICAL

# --- Bicep ---
az bicep build --file citadel/deploy/azure-gov/main.bicep --stdout > /dev/null

# --- Helm ---
helm lint citadel/deploy/kubernetes/citadel
helm template citadel citadel/deploy/kubernetes/citadel \
  | kubeconform -strict -summary -ignore-missing-schemas -kubernetes-version 1.30.0

# --- Dockerfiles ---
find citadel/server citadel/deploy -name Dockerfile -print0 \
  | xargs -0 -I{} sh -c 'echo "== {} =="; hadolint "{}"'

# --- Compose (uses placeholder env, NOT real secrets) ---
docker compose -f citadel/deploy/compose/docker-compose.yml \
  --env-file citadel/deploy/compose/.env.example config > /dev/null

# --- YAML hygiene ---
yamllint -d '{extends: relaxed, rules: {line-length: disable}}' citadel/deploy
```

Install the tools as needed: `terraform`, `tflint`, `checkov`, `trivy`,
`az` (Azure CLI) + `bicep`, `helm`, `kubeconform`, `hadolint`, `yamllint`,
plus Docker for `compose` and the dockerized `hadolint`.

## Wiring it up

When you're ready to enforce these checks on real PRs, copy the workflow to the
repository's workflows directory (it must live at the repo **root**, not inside
`citadel/`):

```bash
mkdir -p .github/workflows
cp citadel/deploy/ci/iac-validate.yml .github/workflows/iac-validate.yml
git add .github/workflows/iac-validate.yml
git commit -m "ci: add IaC validation workflow"
```

The workflow needs only `contents: read` (least privilege) — no cloud
credentials, secrets, or write scopes, because every check runs **offline**
(`terraform init -backend=false`, `bicep build`, `helm template`,
`compose config`). Tighten the report-only scanners into hard gates by editing
the marked switches once the baseline is clean.
