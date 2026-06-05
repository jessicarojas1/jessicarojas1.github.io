# Operations Runbook

Operational procedures for Sentinel QMS: backups, disaster recovery (RPO/RTO), monitoring and alerting,
scaling, patching, incident response, and log retention. Applies to both **AWS GovCloud** and **Azure
Government** deployments; cloud-specific commands reference the
[AWS](aws-govcloud-runbook.md) and [Azure](azure-gov-runbook.md) runbooks.

---

## 1. Backups

| Asset | Mechanism | Frequency | Retention | Encryption |
|-------|-----------|-----------|-----------|------------|
| PostgreSQL | Automated snapshots + PITR (transaction logs) | Continuous (PITR) + daily snapshot | 35 days | CMK (KMS/Key Vault) |
| Object storage | Versioning + soft delete | Continuous | Per policy (≥90 days for incident scope) | CMK |
| Secrets | Versioned in Secrets Manager / Key Vault | On change | Provider default | HSM |
| IaC state | Versioned remote backend (locked) | On apply | Indefinite | Encrypted |
| Container images | ECR/ACR immutable tags | On build | ≥1 year | Provider |

**Pre-change rule:** always take a manual DB snapshot before a production migration or risky change.

```bash
# AWS
aws rds create-db-snapshot --db-instance-identifier sentinel-qms-prod \
  --db-snapshot-identifier sentinel-qms-pre-<tag>
# Azure (Flexible Server uses automated backups + on-demand restore points)
az postgres flexible-server backup list -g sentinel-qms-prod -n sentinel-qms-prod
```

---

## 2. Disaster Recovery (RPO / RTO)

| Objective | Target |
|-----------|--------|
| **RPO** (max data loss) | ≤ 15 minutes (PITR / continuous log shipping) |
| **RTO** (max downtime) | ≤ 4 hours (prod) |

**Recovery procedure:**
1. Declare DR; assemble responders; open incident record.
2. Restore database to the latest consistent point (PITR snapshot) in the target AZ/zone.
3. Re-provision compute via Terraform if the cluster is lost (`terraform apply`).
4. Redeploy the last-known-good signed image via Helm.
5. Re-point ingress/DNS; validate TLS.
6. Run the smoke test (health, auth, KPI read, audit row).
7. Reconcile object storage (versioning ensures artifacts intact).
8. Close incident; record actual RPO/RTO; conduct post-mortem.

DR is **exercised at least annually**; results are recorded as evidence (CP-10 / 800-171 contingency).

---

## 3. Monitoring & Alerting

| Signal | Source | Alert threshold |
|--------|--------|-----------------|
| API 5xx rate | LB/ingress + app logs | > 1% over 5 min |
| Latency p95 | App metrics | > 1s sustained |
| DB CPU / connections | RDS/PostgreSQL metrics | CPU > 80% / conns > 80% pool |
| Pod restarts / crashloop | Kubernetes | any crashloop |
| Auth failures / lockouts | Audit log → SIEM | spike / brute-force pattern |
| Audit pipeline failure | SIEM | any gap in audit ingestion |
| Certificate expiry | ACM / Key Vault | < 30 days |
| Vulnerability findings | GuardDuty/Defender + CI | High/Critical |

Dashboards in **CloudWatch/Security Hub** (AWS) or **Azure Monitor/Sentinel** (Azure). On-call is paged
for severity-1/2; runbook links are attached to each alert.

---

## 4. Scaling

- **API/Worker:** stateless; scale via Horizontal Pod Autoscaler on CPU/RPS. Cluster Autoscaler adds
  nodes. No session affinity needed.
- **Database:** scale vertically (instance class) and use read replicas for heavy reporting; connection
  pooling (`pool_size=10, max_overflow=20`) caps connections per replica.
- **Storage:** object storage scales automatically.
- **Capacity planning:** review KPI/report load before audits/management reviews (peak read periods).

---

## 5. Patching

| Layer | Cadence | Process |
|-------|---------|---------|
| Application deps | Continuous via CI SCA; security fixes expedited | PR → scan → sign → deploy |
| Container base images | Monthly + on CVE | Rebuild, scan, sign, roll out |
| Kubernetes | Per provider minor-version cadence | Drain/upgrade node pools (rolling) |
| Managed DB | Provider maintenance window | Apply minor patches; test in stage first |
| OS/node | Managed node-group image refresh | Rolling replacement |

**SLA:** Critical CVEs remediated ≤ 15 days; High ≤ 30 days; tracked in the POA&M. Patches deploy through
stage → smoke test → prod with approval.

---

## 6. Incident Response

Aligned to **NIST 800-171 3.6 / 800-53 IR** and **DFARS 252.204-7012** 72-hour reporting.

1. **Detect** — SIEM alert, user report, or monitoring signal.
2. **Triage & classify** — assign severity; determine if **CUI/CDI** is implicated.
3. **Contain** — isolate affected pods/nodes; rotate credentials/keys; revoke tokens (`jti`).
4. **Eradicate** — patch/remove root cause; redeploy clean signed image.
5. **Recover** — restore service; validate via smoke test; monitor for recurrence.
6. **Report (DFARS)** — if CDI is affected, report to **DIBNet within 72 hours** of discovery; submit
   isolated malware to **DC3**.
7. **Preserve** — retain system images and relevant logs **≥ 90 days** for forensics.
8. **Post-incident** — root-cause analysis; open a CAPA; update controls and this runbook.

Evidence sources: immutable audit log, SIEM, snapshots, container images.

---

## 7. Log Retention

| Log type | Store | Retention |
|----------|-------|-----------|
| Application (structured JSON) | CloudWatch / Log Analytics | ≥ 1 year (hot 90 days) |
| Immutable audit trail | PostgreSQL + SIEM export | ≥ 1 year (or per contract/AS9100 record retention) |
| Cloud control plane (CloudTrail / Activity Log) | SIEM | ≥ 1 year |
| Incident-related media/logs | Encrypted store | ≥ 90 days from report (DFARS) |
| Quality records (NCR, CAPA, FAI, etc.) | PostgreSQL + backups | Per organization's retention policy (often life-of-program + years) |

Retention is configurable per organizational policy and contractual/record-retention requirements; audit
data is never purged below the regulatory minimum.

---

## 8. Routine Operational Tasks

| Task | Cadence |
|------|---------|
| Verify backups restorable (test restore) | Quarterly |
| DR exercise | Annually |
| Access review (RBAC, accounts) | Quarterly |
| Certificate renewal check | Monthly |
| Patch review | Monthly + on CVE |
| Audit-log spot check | Monthly |
| Capacity review | Before peak periods |
| Cost/region-residency review | Quarterly |

---

## 9. Health & Readiness

- Liveness/readiness: `GET /health` (and `/healthz`). Kubernetes probes gate traffic.
- Background worker health: job last-success metrics (calibration scan, training expiry, KPI rollup,
  retention sweep, notifications). Alert if a scheduled job misses its window.
