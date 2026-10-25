# PAL — Open Items

Honest readiness register. **Nothing is implemented. Phase 0 (architecture) is in draft.**

## Decisions (blocking Phase 1)
| Item | Impact | Action |
|---|---|---|
| D18 identity provider ("not Microsoft" vs. Entra ID SSO in spec) | Sign-in, group sync, email via Graph | Owner confirms whether Entra ID stays for sign-in |
| D13 PHP framework | Whole backend | Owner approves Symfony (or picks Laravel) |
| D6a default timezone | Scheduling defaults | Owner names the organisation's default timezone |
| D10 RPO/RTO (provisional 15 min / 4 h) | Backup, HA, cost | IT / business owner confirms before Phase 7 |
| D16 directory permission | Group access | Admin consent for Graph, or an LDAP read-only account |
| D17 Sentinel path + uptime monitoring | Observability | SOC confirms the collector; name an uptime monitor |
| D19 Confluence inventory | Migration size and urgency | Edition/version, spaces/pages, history required?, approvals app? |
| D14 / D15 Office editing, co-authoring | Scope | Recommend defer to Phase 9 |

## Standard doc & deployment set (deferred to Phase 1)
| Item | Impact | Action |
|---|---|---|
| `deployments/` ×6, `docs/` ARCHITECTURE/DEPLOYMENT/DISASTER_RECOVERY/SECURITY | Required by repo standard | Write in Phase 1 against real code |
| `Dockerfile`, `render.yaml`, `database/schema.sql` | Required by repo standard | Phase 1 |

## Risks
| Risk | Impact | Action |
|---|---|---|
| Quasar accessibility gaps | WCAG 2.2 AA at risk | Phase 1 a11y spike (ADR-0003) |
| Confluence migration unscoped (macros, history, permissions) | Day-one adoption | D19 inventory; pilot space first |
| Audit tamper-evidence is not tamper-proof against a DBA | Auditor expectations | External anchoring; state the limitation openly |
| Full in-browser editor is the largest scope item | Schedule | Structured editor v1; defer co-authoring and Office editing |
| Handover to private repo | Confidentiality | Follow proposal §18.3 checklist |
