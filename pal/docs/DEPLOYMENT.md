# PAL Platform — Deployment & Government Cloud Guidance

PAL is **cloud-agnostic** and **offline-friendly**: a single container image plus a
PostgreSQL database, with no hardcoded dependency on any commercial cloud service. All
configuration is via environment variables, and file storage is abstracted (local volume
or any S3-compatible endpoint). It runs behind a reverse proxy with TLS termination and
supports private-network / restricted-egress deployments.

## Required configuration

| Variable | Notes |
|---|---|
| `JWT_SECRET` | ≥ 64 hex chars. Signs tokens and keys at-rest encryption. **Required.** |
| `DATABASE_URL` *or* `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` | PostgreSQL. |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | First-run admin (only used on an empty database). |
| `APP_URL` | Public URL (for links/emails). |
| `TRUSTED_PROXY_IPS` | Reverse-proxy IP(s) trusted for `X-Real-IP` / `X-Forwarded-Proto`. |
| `STORAGE_DRIVER` | `local` (default) or `s3`. S3 details set in **Admin → Settings** or env. |
| `PORT` | Optional; honored by `startup.sh` (e.g. Azure App Service). |

Secrets should come from your platform's secret store (Azure Key Vault, AWS Secrets
Manager, Kubernetes Secrets) rather than a committed `.env`.

## Health probes

| Path | Use |
|---|---|
| `/health` | Full check (DB + disk) — load balancers, `HEALTHCHECK`. 200 healthy / 503 degraded. |
| `/healthz` | Liveness (process up). |
| `/readyz` | Readiness. |

---

## Azure Government

**Azure Container Apps**
1. Push the image to Azure Container Registry (or ACR in the Gov cloud).
2. Provision **Azure Database for PostgreSQL — Flexible Server** (Gov region); set
   `DATABASE_URL`.
3. Create a Container App from the image; set env vars / Key Vault references; target
   port 80; enable ingress with managed TLS.
4. Mount Azure Files for `/var/www/html/uploads` *or* set `STORAGE_DRIVER=s3` against an
   S3-compatible gateway. Configure liveness `/healthz`, readiness `/readyz`.

**Azure Kubernetes Service (AKS)** — apply `docker/k8s.yaml` (Deployment + Service +
Secret + PVC), point the Ingress at your Gov-cloud controller, and use a PostgreSQL
Flexible Server.

**App Service for Containers** — deploy the image; App Service injects `PORT` (handled by
`startup.sh`); use a Gov-region PostgreSQL and Key Vault references for secrets.

## AWS GovCloud

- **ECS (Fargate/EC2):** task definition from the image; **RDS for PostgreSQL** in
  GovCloud for `DATABASE_URL`; EFS for `/uploads` or `STORAGE_DRIVER=s3` with a GovCloud
  S3 bucket (`S3_REGION=us-gov-west-1`); ALB health check `/health`.
- **EKS:** apply `docker/k8s.yaml`; use IRSA for S3 access; RDS for the database.
- **EC2:** run via `docker compose` or systemd-managed container behind nginx/ALB.

GovCloud S3 settings (Admin → Settings or env): `S3_BUCKET`, `S3_REGION=us-gov-west-1`,
`S3_ACCESS_KEY`, `S3_SECRET_KEY` (encrypted at rest), optional `S3_ENDPOINT` for
FIPS/VPC endpoints.

## Kubernetes (any distribution) / Docker Swarm

- **Kubernetes:** `kubectl apply -f docker/k8s.yaml`. The Deployment sets liveness
  (`/healthz`) and readiness (`/readyz`) probes; scale `replicas` freely (externalize
  uploads to S3 and sessions to a shared store for >1 replica). Mount secrets from a
  `Secret`; uploads from a `PersistentVolumeClaim`.
- **Docker Swarm:** `docker stack deploy -c docker-compose.yml pal` (move secrets to
  Swarm secrets in production).

## On-prem / air-gapped

- Build the image where you have egress, then `docker save | docker load` into the
  isolated network. No outbound calls are required at runtime except the optional CDN
  assets in views — for fully offline use, vendor Bootstrap & fonts locally (Bootstrap
  Icons & Chart.js are already vendored under `public/vendor/`).
- Run behind nginx/Apache/HAProxy with TLS termination; set `TRUSTED_PROXY_IPS` to the
  proxy address so client IPs are attributed correctly in the audit log.

## Environment profiles

Set `APP_ENV` to `development` | `test` | `staging` | `production`. Keep
`display_errors` off in non-dev (the image already enforces this); ship logs from
`/var/www/html/logs` and the container stdout to your log aggregator.

## Upgrades

`install.php` is idempotent and runs on every container start: it re-applies
`schema.sql` and any new `database/migrations/*.sql`, then exits. Rolling deployments are
safe — migrations use `IF NOT EXISTS` / `ON CONFLICT DO NOTHING`.
