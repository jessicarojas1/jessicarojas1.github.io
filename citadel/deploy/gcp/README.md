# CITADEL — Google Cloud Platform (GCP) Deployment

Production Infrastructure-as-Code (Terraform) for running CITADEL on **Cloud Run v2**
backed by **Cloud SQL for PostgreSQL**, with secrets in **Secret Manager**, images in
**Artifact Registry**, private connectivity via a **Serverless VPC Access connector**,
and an optional global **external HTTPS Load Balancer + Cloud Armor** front door.

```
                 (optional)
 Internet ──▶ HTTPS LB + Cloud Armor ──▶ Cloud Run v2 (citadel-server :8080)
                                              │  least-privilege runtime SA
                                              │  secret env vars (Secret Manager)
                                              ▼
                                   Serverless VPC Access connector
                                              ▼
                                   VPC ◀─ peering ─▶ Cloud SQL (PostgreSQL)
                                                    private IP · SSL-only · backups
```

## App facts wired in

- Image built from the **repo root**: `docker build -f citadel/server/Dockerfile -t citadel-server .`
- Container listens on `PORT=8080`; health endpoint `GET /api/health` (used for startup + liveness probes).
- Runs non-root (UID 10001), read-only-rootfs friendly; Cloud Run provides ephemeral writable `/tmp` for the app's `/tmp/citadel` scratch.
- Env wired: `NODE_ENV=production`, `PGSSL=1`, `CITADEL_ADMIN_EMAIL`, `CITADEL_MULTITENANT`, `CITADEL_BASE_DOMAIN` (plain env vars), and
  `DATABASE_URL`, `CITADEL_JWT_SECRET`, `CITADEL_ADMIN_PASSWORD`, `CITADEL_SUPERADMIN_TOKEN`, `CITADEL_METRICS_TOKEN` (Secret Manager `secretKeyRef` env vars — never plaintext).

## Files

| File | Purpose |
|------|---------|
| `versions.tf`  | Terraform + `google` / `google-beta` / `random` provider constraints, GCS backend stub |
| `variables.tf` | All inputs with `validation` blocks (project_id, region, db tier, toggles, …) |
| `main.tf`      | Providers, project data, shared locals, **API enablement**, Artifact Registry |
| `network.tf`   | VPC, Private Services Access, Serverless VPC Access connector, firewall |
| `cloudsql.tf`  | Cloud SQL PostgreSQL (private IP, SSL-only, backups+PITR, CMEK toggle), DB+user, assembled `DATABASE_URL` |
| `secrets.tf`   | Secret Manager secrets + versions (generated JWT/admin pw/tokens + DB URL) |
| `iam.tf`       | Dedicated runtime service account + least-privilege bindings, CMEK grant |
| `cloudrun.tf`  | Cloud Run v2 service (probes, secret env vars, autoscaling, VPC egress) + invoker IAM |
| `lb.tf`        | Optional external HTTPS LB + managed SSL + Cloud Armor (gated by `enable_external_lb`) |
| `outputs.tf`   | Service URL, LB IP, registry path, Cloud SQL connection name, secret IDs |
| `deploy.sh`    | gcloud build/push to Artifact Registry → `terraform apply` |

## Prerequisites

- `gcloud` SDK, authenticated: `gcloud auth login` and an account with
  Owner/Editor **plus** Project IAM Admin on the target project.
- `docker` (or a `podman` alias) and `terraform >= 1.5`.
- A GCP **project with billing enabled**.

## Quick start

```bash
export PROJECT_ID="your-gcp-project"
export REGION="us-central1"                 # optional
export LB_DOMAINS="citadel.example.com"      # required if enable_external_lb=true (default)

./deploy.sh
```

`deploy.sh` enables the required APIs, ensures the Artifact Registry repo exists,
builds & pushes `citadel-server`, then runs `terraform init/plan/apply`.

### Plan-only / manual Terraform

```bash
terraform init
terraform plan \
  -var "project_id=your-gcp-project" \
  -var 'lb_domains=["citadel.example.com"]'
terraform apply
```

### After apply

1. Read the outputs: `terraform output`.
2. If `enable_external_lb=true`, create a DNS **A record** for each domain in
   `lb_domains` pointing at `load_balancer_ip`, then wait (can take 15–60 min) for
   the Google-managed SSL certificate to become **ACTIVE**.
3. Retrieve the generated bootstrap admin password:
   ```bash
   gcloud secrets versions access latest \
     --secret="citadel-prod-admin-password" --project="$PROJECT_ID"
   ```

## Key configuration knobs

| Variable | Default | Notes |
|----------|---------|-------|
| `project_id` | — (required) | Target GCP project |
| `region` | `us-central1` | Cloud Run / Cloud SQL / AR / connector region |
| `db_tier` | `db-custom-2-7680` | Cloud SQL machine tier |
| `db_availability_type` | `REGIONAL` | `REGIONAL` = HA standby; `ZONAL` for dev |
| `enable_cmek` / `cmek_key_name` | `false` / `""` | Customer-managed encryption key for Cloud SQL |
| `min_instances` / `max_instances` | `1` / `10` | Cloud Run autoscaling bounds |
| `ingress` | `INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER` | Lock the run.app URL behind the LB |
| `allow_unauthenticated` | `true` | App does its own JWT auth; set false to require IAM callers |
| `enable_external_lb` | `true` | Provision LB + Cloud Armor (needs `lb_domains`) |
| `armor_allowed_cidrs` | `["0.0.0.0/0"]` | Tighten to corporate/VPN ranges for prod |
| `citadel_multitenant` | `false` | Toggles `CITADEL_MULTITENANT` |

### Custom domain without the LB

Set `enable_external_lb=false` and `ingress=INGRESS_TRAFFIC_ALL`, then map a custom
domain directly to the Cloud Run service:

```bash
gcloud beta run domain-mappings create \
  --service citadel-prod --domain citadel.example.com --region "$REGION"
```

## Security model (highlights)

- **No plaintext secrets**: JWT secret, admin password, super-admin & metrics tokens,
  and the assembled `DATABASE_URL` are generated with `random_password` and stored in
  Secret Manager; Cloud Run reads them as `secretKeyRef` env vars. Nothing secret is
  emitted as a Terraform output.
- **Least-privilege runtime SA**: only `roles/secretmanager.secretAccessor`
  (scoped per-secret) and `roles/cloudsql.client`.
- **Private database**: Cloud SQL has **no public IP**, **no authorized networks**,
  and rejects non-TLS connections (`ssl_mode = ENCRYPTED_ONLY`). Cloud Run reaches it
  only through the VPC connector over the peered private range.
- **Edge protection** (when LB enabled): Cloud Armor IP allowlist, per-IP rate limit,
  OWASP SQLi/XSS preconfigured rules; HTTP→HTTPS redirect; MODERN TLS 1.2+ policy.
- **Encryption at rest**: Google-managed by default; flip `enable_cmek` for CMEK.
- **Backups / HA**: daily automated backups + PITR; `REGIONAL` synchronous standby.

> ⚠️ Terraform **state contains the generated secrets** (it created them). Use the GCS
> backend stub in `versions.tf` with a CMEK-encrypted, versioned, access-restricted
> bucket — never commit local state.

## NIST SP 800-53 control cross-walk

| Control | Family | How this stack addresses it |
|---------|--------|-----------------------------|
| **AC-6** | Least Privilege | Dedicated runtime SA with only `secretAccessor` (per-secret) + `cloudsql.client`; no broad project roles |
| **IA-2 / IA-5** | Identification & Authentication / Authenticator Mgmt | Workload identity via service account; generated JWT/admin/token authenticators stored & rotated in Secret Manager |
| **SC-5** | Denial of Service Protection | Cloud Armor per-IP rate limiting; Cloud Run max-instance ceiling |
| **SC-7** | Boundary Protection | Cloud SQL private IP only; Cloud Run ingress locked to internal LB; VPC connector `PRIVATE_RANGES_ONLY` egress; firewall scoped to 5432; Cloud Armor IP allowlist |
| **SC-8** | Transmission Confidentiality | HTTPS LB (MODERN TLS 1.2+), HTTP→HTTPS redirect, `PGSSL=1` + `sslmode=require`, Cloud SQL `ENCRYPTED_ONLY` |
| **SC-12 / SC-13** | Key Establishment / Cryptographic Protection | Secret Manager + optional Cloud SQL CMEK (Cloud KMS); managed TLS certificate |
| **SC-28** | Protection of Information at Rest | Encrypted Cloud SQL disks (Google-managed or CMEK); Secret Manager encryption |
| **SI-2 / RA-5** | Flaw Remediation / Vuln Scanning | Artifact Registry image store; enable AR vulnerability scanning on the repo |
| **SI-4** | Information System Monitoring | Cloud Armor OWASP SQLi/XSS rules; LB request logging; Cloud Run + Cloud SQL logs |
| **AU-2 / AU-12** | Audit Events / Audit Generation | Cloud SQL `log_connections`/`log_disconnections`; Cloud Run request logs; LB access logs |
| **CP-9 / CP-10** | System Backup / Recovery | Cloud SQL automated backups + PITR (≥7-day retention); `REGIONAL` HA standby |
| **CA-3** | System Interconnections | Private Services Access VPC peering documented & scoped to Service Networking |

> This is an engineering control mapping to accelerate your SSP, not an authorization.
> Tailor controls and complete your assessment before an ATO.

## Teardown

```bash
# If the DB is protected, drop deletion protection first:
terraform apply -var 'db_deletion_protection=false' -var "project_id=$PROJECT_ID" \
  -var 'lb_domains=["citadel.example.com"]'

terraform destroy -var "project_id=$PROJECT_ID" -var 'lb_domains=["citadel.example.com"]'
```

Notes:
- `disable_services_on_destroy` defaults to **false** so destroying CITADEL does not
  yank shared project APIs out from under other workloads.
- Cloud SQL reserves a deleted instance **name** for ~7 days; the config uses a random
  suffix so re-creation is not blocked.
- Secret Manager secrets are destroyed with the stack; their values live in state.
```
