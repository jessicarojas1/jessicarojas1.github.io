# PAL Requirements Traceability Matrix

Spec section → design location → phase → verification. Status is `Designed` for everything until implementation begins. No requirement is implemented or tested yet.

| Req | Requirement (spec §) | Design | Phase | Verification |
|---|---|---|---|---|
| R1 | Identity separate from immutable revisions (§4) | Proposal §8.2, ADR-0007 | 2–3 | DB trigger test; Scenario 8 |
| R2 | Historical accuracy on people/org changes (§4) | §8.3 snapshots, assignment validity ranges | 2–4 | As-of reconstruction test |
| R3 | Server-enforced lifecycle state machine (§5) | §9, ADR-0007 | 3–5 | Transition matrix unit tests (all illegal transitions refused) |
| R4 | Published + draft + history coexist (§5) | §9.4 | 3 | Scenario 2 |
| R5 | Exactly one effective revision per scope (§5) | §11, ADR-0005 exclusion constraint | 5 | Negative constraint test; Scenario 5 |
| R6 | Configurable workflows: single, multi, sequential, parallel (§6) | §10 | 4 | Workflow engine tests per mode |
| R7 | Approval bound to revision + checksum (§6) | §10, §8.2 `approval_decision` | 4–5 | Scenario 8; verify-on-release tamper test |
| R8 | Separation of duties (§6) | §10 | 4 | SoD refusal tests |
| R9 | Approval ≠ publication; scheduled; separate effective date (§7) | §11 | 5 | Scenario 4 |
| R10 | Idempotent, crash-safe publication (§7) | §11 release transaction | 5 | Scenario 9 (fault injection) |
| R11 | Timezone/DST handling (§7) | §11 time handling | 5 | DST boundary tests |
| R12 | Versioning, numbering policies, rollback as new release (§8) | §8.2, C3 | 3 | Rollback history test |
| R13 | Revision comparison (§8) | §3 (metadata v1, text diff Phase 6) | 3/6 | Diff tests |
| R14 | Repository, categories, search, saved views (§9) | §7.3, §8.2 | 2/6 | Search permission tests; Scenario 7 |
| R15 | WCAG 2.2 AA UI (§10) | ADR-0003 gate | 1/6/7 | axe + manual SR tests |
| R16 | Global + document/category-scoped RBAC with group access, backend-enforced (§11, owner D5) | §12, ADR-0010 | 1–4 | Endpoint × role × scope matrix |
| R17 | Append-only, tamper-evident audit (§12) | §13 | 1–7 | Chain verification; privilege test (UPDATE denied) |
| R18 | ASVS L2 security baseline (§13) | §15 | 1–7 | ASVS checklist with evidence |
| R19 | Secure upload: type, size, malware, untrusted content (§13) | §7.2, §7.6, T1 | 2 | EICAR + spoofed-extension tests |
| R20 | Safe preview, isolated conversion (§14) | §7.4, T9 | 6 | Malicious sample containment test |
| R21 | Notifications + durable idempotent jobs (§15) | §7.5 outbox + Symfony Messenger/Scheduler | 4–6 | Retry/dedupe tests |
| R22 | Admin dashboard + CSV/XLSX reports (§16) | §16 Phase 6 | 6 | Report reconciliation test |
| R23 | On-prem Linux deployment, portable later (§17, owner D2) | §14.2, abstractions | 1 | Compose deployment on a clean Linux host from docs alone |
| R24 | Acceptance scenarios 1–10 (§20) | §16 phase exits | 2–5 | Automated E2E/integration suite |
| R25 | Full in-browser editor (owner D3) | §7.7, ADR-0009 | 3 | Editor E2E; XSS corpus; a11y |
| R26 | Upload Word/Excel/PDF (owner D3) | §7.2, ADR-0009 | 2 | Upload pipeline tests |
| R27 | Group-based access (owner D5) | ADR-0010 | 1–2 | Group grant/revoke + search-filter tests |
| R28 | Multi-timezone effective dates, single org scope (owner D6/D7) | §11 | 5 | DST + cross-timezone display tests |
| R29 | Logs to Microsoft Sentinel (owner) | ADR-0011 | 1/8 | Events visible in Sentinel workspace (org-run test) |
| R30 | Migrate from on-prem Confluence (owner) | ADR-0012 | 6b | Pilot reconciliation report |
| R31 | Email via SMTP (+ Graph optional) (owner) | §1a, §14.1 | 4–6 | Notification delivery tests |
