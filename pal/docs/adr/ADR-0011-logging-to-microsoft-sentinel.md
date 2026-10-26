# ADR-0011: Security and audit logging to the organisation's SIEM (Microsoft Sentinel)

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed (owner fact, 2026-10-25: logs go to Microsoft Sentinel)
- **Date:** 2026-10-25

## Decision
- PAL emits **structured JSON logs** (Monolog) with `correlation_id`, actor, event type and outcome, never document content or secrets. Two streams:
  - **Operational** (errors, job failures, performance)
  - **Security/audit** (sign-ins, authorization denials, approvals, publications, emergency publications, grant changes, admin actions, audit-chain verification results, malware detections)
- Forwarding is **adapter-based and outside the app code.** On an on-prem Linux host the usual Sentinel paths are the **Azure Monitor Agent** (host onboarded through Azure Arc, collection defined by a Data Collection Rule) reading syslog or JSON log files, or an existing **syslog/CEF forwarder**. Use whichever the organisation already runs (D17). Do **not** build on the legacy Log Analytics HTTP Data Collector API, which Microsoft has scheduled for retirement. If direct ingestion is needed, use the Logs Ingestion API.
- The database audit trail remains the **system of record**. Sentinel holds a forwarded copy for detection and correlation. It does not replace the hash-chained audit table.
- Starter detection ideas for the SOC: mass downloads by one user, repeated authorization denials, emergency publications, separation-of-duties violations, audit-chain verification failures, malware detections, and admin role grants.

## Consequences
- Sentinel is a SIEM, not uptime or health monitoring. Health checks still need a monitoring target (D17).
- Ingestion cost depends on the organisation's Sentinel pricing. PAL's volume should be small (structured events, no content). Estimate it in Phase 1 from real event rates.
