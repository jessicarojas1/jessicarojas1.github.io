# REDOUBT — On-Prem Kubernetes (Production, HA)

Operator guide for running REDOUBT on an **on-prem Kubernetes cluster inside
GMRE's CUI boundary**, connecting to **Microsoft 365 / Azure GCC High**. Use this
for high availability; for a single host see `SINGLE_LINUX_SERVER.md`; for the
M365 side see `AZURE.md`.

> Boundary note: the cluster and its nodes are part of GMRE's customer-owned CUI
> enclave and must meet NIST SP 800-171 / CMMC. See `../docs/SECURITY.md`.

## 1. Deployment architecture

Stateless REDOUBT pods (≥2 replicas) behind an Ingress with TLS. PostgreSQL runs
as an in-cluster StatefulSet with persistent storage or an external enclave DB.
Secrets come from the cluster secret store (or an external secrets operator).
Pods egress to GCC High endpoints for identity and documents.

## 2. Topology

```
              GMRE on-prem CUI enclave — Kubernetes cluster
  ┌───────────────────────────────────────────────────────────┐
  │ [Ingress: TLS/HSTS/WAF] ─> [Service] ─> [REDOUBT pods x N] │
  │                                            │                │
  │                                 ┌──────────┴─────────┐      │
  │                          [PostgreSQL STS/PVC]   [Secrets]   │
  └───────────────────────────┬───────────────────────────────┘
                              │ HTTPS egress (ExpressRoute/Gov)
                              ▼   Microsoft 365 / Azure GCC High
```

## 3. Prerequisites

- On-prem Kubernetes (FIPS-enabled nodes) inside the CUI enclave.
- Ingress controller + trusted TLS certificate; a secret store (native Secrets,
  Sealed Secrets, or an external secrets operator).
- PostgreSQL (in-cluster StatefulSet or external).
- Egress allowlist to `*.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us`.
- Completed Entra GCC High app registration (`AZURE.md`).

## 4. Identity & credentials

- Store `ENTRA_CLIENT_SECRET` (or, preferred, a certificate) as a Kubernetes
  Secret sourced from the enclave secret store — never in the image or ConfigMap.
- Least-privilege Graph app permissions (prefer `Sites.Selected`).
- Rotate credentials via the secret store; pods pick up rotation on restart.

## 5. Environment variables (GCC High)

Same variable surface as `SINGLE_LINUX_SERVER.md` §5, delivered via ConfigMap
(non-secret) + Secret (`ENTRA_CLIENT_SECRET`, `DATABASE_URL`):

| Source | Keys |
|--------|------|
| ConfigMap | `APP_ENV`, `PORT`, `MS_CLOUD=USGovernment`, `ENTRA_AUTHORITY_HOST=https://login.microsoftonline.us`, `GRAPH_BASE_URL=https://graph.microsoft.us`, `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `GRAPH_SCOPES=https://graph.microsoft.us/.default` |
| Secret | `ENTRA_CLIENT_SECRET`, `DATABASE_URL` |

## 6. Configuration references

- Container listens on `:8080`; set readiness/liveness probes to `GET /health`.
- Ingress must forward `X-Forwarded-Proto: https`.
- Run as non-root (the image already sets `USER www-data`); set
  `readOnlyRootFilesystem` where feasible and drop capabilities.

## 7. Verification

```bash
kubectl -n redoubt get pods                        # all Running/Ready
kubectl -n redoubt exec deploy/redoubt -- curl -fsS localhost:8080/health
curl -fsS https://<ingress-host>/health            # via Ingress
```
- Sign in via Entra GCC High (MFA enforced); open a SharePoint document; confirm
  audit rows; confirm secrets are not present in ConfigMaps.

## 8. Day-2 operations

- Rolling updates via image tag bumps; keep ≥2 replicas for zero-downtime.
- PostgreSQL backups + tested restore (`../docs/DISASTER_RECOVERY.md`).
- Node patching + FIPS validation; NetworkPolicies restricting egress to the
  GCC High allowlist; HPA on CPU if load warrants.
- Monitor auth failures and Graph 429s; alert on probe failures.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Pods CrashLoopBackOff | Missing Secret/env | Check Secret mount + required vars |
| Login/Graph auth fails | Commercial endpoints | Use `.us` authority + Graph base URL |
| 403 from Graph | Permission/consent | Least-privilege `Sites.Selected` + admin consent |
| Ingress 502 | Probe/port mismatch | Probe `/health` on `:8080` |
| Egress blocked | NetworkPolicy/firewall | Allowlist `*.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us` |
