# CMMC 2.0 Level 2 Mapping

**CMMC 2.0 Level 2** requires implementation of the **110 practices of NIST SP 800-171 Rev 2**, assessed
across **14 domains**, and (for prioritized acquisitions) a **C3PAO** third-party assessment. This
document maps the CMMC L2 domains to the Sentinel QMS implementation and provides assessment-readiness
notes. Practice identifiers use the CMMC format **`<DOMAIN>.L2-3.x.y`** (which maps 1:1 to the NIST
800-171 control of the same `3.x.y` number — see [nist-800-171-mapping.md](nist-800-171-mapping.md) for
control-level detail).

> **Shared responsibility.** CMMC certification is awarded to an **organization**, not a product. Sentinel
> QMS implements the technical practices below; the organization owns policy, SSP, POA&M, training,
> physical security, and the assessment itself.

---

## Domain Coverage Summary

| # | Domain | Practices (L2) | Sentinel QMS role |
|---|--------|:--------------:|-------------------|
| AC | Access Control | 22 | RBAC, federation, least privilege, session control |
| AT | Awareness & Training | 3 | Training & Competency module (security courses) — *supports* |
| AU | Audit & Accountability | 9 | Append-only audit log → SIEM |
| CM | Configuration Management | 9 | IaC baselines, signed images, change control |
| IA | Identification & Authentication | 11 | OIDC/SAML/CAC-PIV, MFA at IdP, bcrypt, unique IDs |
| IR | Incident Response | 3 | Runbook + SIEM + DFARS reporting — *supports* |
| MA | Maintenance | 6 | Managed services, patch cadence, scanned tools |
| MP | Media Protection | 9 | Encrypted storage, markings, crypto-shred |
| PS | Personnel Security | 2 | Account lifecycle, revocation — *supports* |
| PE | Physical Protection | 6 | Inherited from GovCloud/Azure Gov data centers |
| RA | Risk Assessment | 3 | Risk module + vuln scanning |
| CA | Security Assessment | 4 | This doc set + checklist — *supports SSP/POA&M* |
| SC | System & Communications Protection | 16 | Segmentation, TLS/FIPS, KMS, deny-by-default |
| SI | System & Information Integrity | 7 | Patching, malware/image scanning, monitoring |

---

## Representative Practice Mapping

| CMMC practice | Title (abbrev.) | Implementation |
|---------------|-----------------|----------------|
| AC.L2-3.1.1 | Authorized access | JWT + RBAC, deny-by-default |
| AC.L2-3.1.2 | Limit transactions/functions | Permission checks per endpoint |
| AC.L2-3.1.3 | Control CUI flow | Tenant scope + RLS, private subnets |
| AC.L2-3.1.5 | Least privilege | Minimal role permissions |
| AC.L2-3.1.8 | Limit logon attempts | Lockout/throttle + WAF |
| AC.L2-3.1.12 | Monitor remote access | TLS ingress → SIEM |
| AT.L2-3.2.1 | Security awareness | Training module (security courses) |
| AU.L2-3.3.1 | System audit logs | Append-only `audit_log` |
| AU.L2-3.3.2 | User accountability | Actor/session/IP + e-signatures |
| AU.L2-3.3.8 | Protect audit info | Append-only trigger + grants + SIEM immutability |
| CM.L2-3.4.1 | Baseline configs | Terraform + Helm |
| CM.L2-3.4.2 | Enforce config settings | Hardened images, validated settings |
| CM.L2-3.4.3 | Track/approve changes | Git PR + CI gates + app change mgmt |
| IA.L2-3.5.1 | Identify users | Unique accounts, workload identities |
| IA.L2-3.5.2 | Authenticate identities | bcrypt + federation |
| IA.L2-3.5.3 | MFA | Enforced at IdP / CAC-PIV |
| IR.L2-3.6.1 | Incident handling | Runbook + SIEM |
| IR.L2-3.6.2 | Track/report incidents | DFARS 72-hr workflow |
| MP.L2-3.8.1 | Protect media | Encrypted object storage |
| MP.L2-3.8.4 | CUI markings | CUI banner + export markings |
| PE.L2-3.10.1 | Limit physical access | Inherited (GovCloud/Azure Gov) |
| RA.L2-3.11.2 | Vulnerability scanning | CI SAST/SCA/container + GuardDuty/Defender |
| RA.L2-3.11.3 | Remediate vulnerabilities | Patch SLA + POA&M |
| CA.L2-3.12.4 | System Security Plan | This documentation feeds the SSP |
| SC.L2-3.13.8 | Encrypt CUI in transit | TLS 1.2+/FIPS |
| SC.L2-3.13.11 | FIPS-validated crypto | KMS/Key Vault FIPS HSMs |
| SC.L2-3.13.16 | Protect CUI at rest | CMK encryption (DB + storage) |
| SI.L2-3.14.1 | Remediate flaws | Patch cadence, dependency scanning |
| SI.L2-3.14.2 | Malicious-code protection | Image scanning + EDR |
| SI.L2-3.14.6 | Monitor for attacks | SIEM + WAF + GuardDuty/Defender |

---

## Assessment-Readiness Notes

1. **Define the CUI boundary.** Document where CUI lives (PostgreSQL, object storage, backups, SIEM) and
   that it remains within GovCloud/Azure Gov. Sentinel QMS keeps the data tier in isolated private subnets
   with no internet route.
2. **Maintain an SSP.** Use this documentation set as input; the SSP must describe each of the 110
   practices with implementation status.
3. **Maintain a POA&M.** Track any practice not fully implemented with milestones. Note: CMMC L2 permits a
   limited POA&M with conditional certification for specified practices; several "MET" practices (e.g.,
   MFA, FIPS crypto, encryption of CUI) are **not** POA&M-eligible — verify these are fully implemented.
4. **Evidence collection.** For each practice gather: configuration evidence (IaC, RBAC matrix), artifacts
   (audit-log samples, e-signature manifests), and procedures. See
   [README.md](README.md) §4 Evidence Catalog.
5. **Scoping of assets.** Categorize assets (CUI assets, Security Protection Assets, Contractor Risk
   Managed Assets, Specialized Assets) per the CMMC Scoping Guide. Sentinel QMS components are CUI assets;
   the SIEM and KMS are Security Protection Assets.
6. **Inherited controls.** Physical protection (PE) and parts of MA/MP are inherited from the
   FedRAMP-authorized cloud (GovCloud / Azure Gov); obtain the provider's customer-responsibility matrix.
7. **Continuous monitoring.** SIEM detections, CI security gates, and patch cadence provide ongoing
   evidence between assessments (annual affirmation required).
8. **Pre-assessment self-check.** Run the [audit-readiness-checklist.md](audit-readiness-checklist.md)
   before engaging a C3PAO.

---

## Domain → Detailed Control Reference

For the full requirement text and implementation per control family, see
[nist-800-171-mapping.md](nist-800-171-mapping.md). CMMC L2 practice `<DOMAIN>.L2-3.x.y` corresponds
directly to NIST 800-171 control `3.x.y`.
