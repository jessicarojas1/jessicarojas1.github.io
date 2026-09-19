# REDOUBT — AWS GovCloud (EKS) Hosting

Operator guide for hosting the REDOUBT app on **Amazon EKS in AWS GovCloud (US)**
when AWS GovCloud is the chosen CUI boundary. Identity and documents still live in
**Microsoft 365 / Azure GCC High** — so this is a **cross-cloud** pattern (app in
AWS GovCloud → M365 GCC High over HTTPS). Follow `KUBERNETES.md` for the workload;
the items below are AWS-GovCloud-specific. For the M365 back-end config see
`AZURE.md` (Part A).

> Boundary note: the EKS cluster is part of an authorized CUI boundary and must
> meet NIST SP 800-171 / CMMC. AWS GovCloud provides FedRAMP High / DoD-aligned
> infrastructure; GMRE still owns the app-tier configuration. See `../docs/SECURITY.md`.

## 1. Architecture

Stateless REDOUBT pods on EKS behind an ALB (TLS), with Amazon RDS for PostgreSQL
and AWS Secrets Manager for credentials. Pods egress over HTTPS to the GCC High
endpoints for identity and documents. No controlled document bytes are stored in
AWS — only config, portal-owned content, metadata/references, and audit.

## 2. Topology

```
        AWS GovCloud (US) — authorized CUI boundary
  ┌─────────────────────────────────────────────────────┐
  │ [ALB: TLS/WAF] ─> [EKS: REDOUBT pods x N]            │
  │                        │                             │
  │              ┌─────────┴──────────┐                  │
  │        [RDS PostgreSQL]   [Secrets Manager (IRSA)]   │
  └───────────────────────────┬─────────────────────────┘
                              │ HTTPS egress (cross-cloud)
                              ▼   Microsoft 365 / Azure GCC High
        login.microsoftonline.us · graph.microsoft.us · *.sharepoint.us
```

## 3. Prerequisites

- AWS GovCloud (US) account + EKS cluster with FIPS-enabled nodes (use FIPS
  endpoints for AWS APIs).
- ALB Ingress controller (AWS Load Balancer Controller) + ACM (GovCloud) TLS cert.
- Amazon RDS for PostgreSQL (or in-cluster StatefulSet).
- Egress (NAT/firewall) allowlisting `*.microsoftonline.us`, `graph.microsoft.us`,
  `*.sharepoint.us`.
- Completed Entra GCC High app registration (`AZURE.md` Part A).

## 4. Identity & credentials

- **AWS side:** use **IRSA** (IAM Roles for Service Accounts) so pods read the
  Entra client secret/cert from **AWS Secrets Manager** without static AWS keys.
- **Entra side (preferred, secretless):** register a **federated credential** on
  the app registration that trusts the **EKS cluster OIDC issuer** + the pod's
  service account — pods then obtain Graph tokens with no stored client secret.
  Fallback: client secret/cert stored in Secrets Manager (via IRSA).
- Least-privilege Graph permissions (`Sites.Selected`), admin-consented.

## 5. Environment variables (GCC High)

Same contract as `KUBERNETES.md` §5 — ConfigMap for non-secrets, Secret (from
Secrets Manager via IRSA/CSI) for `ENTRA_CLIENT_SECRET` and `DATABASE_URL`:

`MS_CLOUD=USGovernment`, `ENTRA_AUTHORITY_HOST=https://login.microsoftonline.us`,
`GRAPH_BASE_URL=https://graph.microsoft.us`,
`GRAPH_SCOPES=https://graph.microsoft.us/.default`, plus `ENTRA_TENANT_ID`,
`ENTRA_CLIENT_ID`, `APP_ENV=production`, `PORT=8080`.

## 6. Configuration references

- Container listens on `:8080`; probes `GET /health`; run non-root
  (`USER www-data`), `readOnlyRootFilesystem` where feasible.
- ALB must forward `X-Forwarded-Proto: https` so HSTS is applied.
- NetworkPolicies + security groups restrict egress to the GCC High allowlist.

## 7. Verification

```bash
kubectl -n redoubt get pods                         # Running/Ready
curl -fsS https://<alb-host>/health                 # {"status":"ok",...}
```
- Sign in via Entra GCC High (MFA); open a SharePoint GCC High document
  (confirms cross-cloud egress works); confirm audit rows; confirm no static AWS
  keys and no plaintext client secret in the image/ConfigMap.

## 8. Day-2 operations

- Rolling updates via image tag; ≥2 replicas; HPA if warranted.
- RDS automated backups + tested restore (`../docs/DISASTER_RECOVERY.md`).
- Rotate the Entra secret/cert (or rely on federated credentials); node patching +
  FIPS validation; quarterly access reviews.
- Monitor CloudWatch + app audit; watch Graph 429s (backoff) and cross-cloud
  egress health.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Pod can't read secret | IRSA role/policy missing | Grant the SA's IAM role `secretsmanager:GetSecretValue` |
| Graph auth fails | Commercial endpoint or bad federated cred | Use `.us` endpoints; match EKS OIDC issuer + SA subject in Entra |
| 403 from Graph | Permission/consent | Least-privilege `Sites.Selected` + admin consent |
| ALB 502 | Probe/port mismatch | Probe `/health` on `:8080` |
| Egress blocked | SG/NACL/NAT | Allowlist `*.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us` |
