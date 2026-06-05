# CITADEL — Frameworks & Control Cross-Walk

CITADEL maps every finding to the controls of the standards below. The demo engine ships **37 SAST rules** plus entropy secret detection, SBOM/dependency, and binary analyzers, all bucketed into the weakness categories that drive this cross-walk.

## Standards catalog (21)

| Framework | Version | Domain | Summary |
|---|---|---|---|
| [OWASP Top 10](https://owasp.org/Top10/) | 2021 | AppSec | The ten most critical web application security risks. |
| [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/) | 4.0.3 | AppSec | Application Security Verification Standard — testable requirements. |
| [OWASP API Security Top 10](https://owasp.org/API-Security/) | 2023 | AppSec | Top risks for APIs. |
| [CWE Top 25](https://cwe.mitre.org/top25/) | 2023 | Weakness | Most dangerous software weaknesses (MITRE). |
| [NIST SP 800-53](https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final) | Rev 5 | Federal | Security & privacy controls for federal information systems. |
| [NIST SP 800-171](https://csrc.nist.gov/pubs/sp/800/171/r2/upd1/final) | Rev 2 | CUI | Protecting Controlled Unclassified Information (CUI). |
| [NIST SSDF 800-218](https://csrc.nist.gov/pubs/sp/800/218/final) | 1.1 | SDLC | Secure Software Development Framework practices. |
| [NIST CSF](https://www.nist.gov/cyberframework) | 2.0 | Framework | Cybersecurity Framework functions: GV, ID, PR, DE, RS, RC. |
| [CMMC](https://dodcio.defense.gov/cmmc/) | 2.0 (L1–L2) | DoD | Cybersecurity Maturity Model Certification for the DIB. |
| [ISO/IEC 27001](https://www.iso.org/standard/27001) | 2022 | ISMS | Information security management system — Annex A controls. |
| [ISO/IEC 42001](https://www.iso.org/standard/81230.html) | 2023 | AI | AI management system requirements. |
| [SOC 2 (TSC)](https://www.aicpa-cima.com/) | 2017 TSC | Attestation | AICPA Trust Services Criteria (Security, Availability, Confidentiality…). |
| [PCI DSS](https://www.pcisecuritystandards.org/) | 4.0 | Payments | Payment Card Industry Data Security Standard. |
| [HIPAA Security Rule](https://www.hhs.gov/hipaa/) | 45 CFR 164 | Healthcare | Safeguards for electronic PHI. |
| [FedRAMP](https://www.fedramp.gov/) | Rev 5 Moderate | Cloud | Cloud security authorization baseline. |
| [CIS Controls](https://www.cisecurity.org/controls) | v8 | Hardening | 18 prioritized safeguards. |
| [GDPR](https://gdpr-info.eu/) | 2016/679 | Privacy | EU data protection — technical measures (Art. 32). |
| [SLSA](https://slsa.dev/) | v1.0 | Supply Chain | Supply-chain Levels for Software Artifacts. |
| [FIPS 140-3](https://csrc.nist.gov/pubs/fips/140-3/final) | 2019 | Crypto | Security requirements for cryptographic modules. |
| [DFARS 252.204-7012](https://www.acquisition.gov/dfars/252.204-7012) | 2016 | DoD | Safeguarding covered defense information & cyber incident reporting. |
| [DISA ASD STIG](https://public.cyber.mil/stigs/) | V5 | DoD | Application Security & Development Security Technical Implementation Guide. |

## Weakness taxonomy

Findings are classified into these categories; each is pre-mapped to controls in every framework above.

- **injection** — Injection (SQL/NoSQL/OS Command/LDAP)
- **xss** — Cross-Site Scripting (XSS)
- **crypto** — Broken / Weak Cryptography
- **secrets** — Hardcoded Secrets & Credentials
- **authn** — Broken Authentication
- **authz** — Broken Access Control / IDOR
- **deserialization** — Insecure Deserialization
- **ssrf** — Server-Side Request Forgery
- **path-traversal** — Path Traversal / File Inclusion
- **xxe** — XML External Entities (XXE)
- **logging** — Insufficient Logging & Monitoring
- **config** — Security Misconfiguration
- **deps** — Vulnerable & Outdated Components
- **supply-chain** — Software Supply-Chain Integrity
- **input-validation** — Improper Input Validation
- **error-handling** — Information Disclosure / Error Handling
- **session** — Session Management
- **transport** — Insecure Transport (TLS)
- **file-upload** — Unrestricted File Upload
- **random** — Insecure Randomness
- **malware** — Malicious / Suspicious Binary
- **privacy** — Sensitive Data / Privacy Exposure
- **quality** — Code Quality & Maintainability

## Category → control cross-walk

For each weakness category, the specific controls implicated per framework:

### Injection (SQL/NoSQL/OS Command/LDAP) (`injection`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A03:2021 Injection |
| OWASP ASVS | V5.3 Output Encoding & Injection |
| OWASP API Security Top 10 | API8:2023 Security Misconfiguration |
| CWE Top 25 | CWE-89; CWE-78; CWE-77 |
| NIST SP 800-53 | SI-10; SI-15 |
| NIST SP 800-171 | 3.14.1 |
| NIST SSDF 800-218 | PW.5.1; RV.1.1 |
| NIST CSF | PR.PS-06 |
| CMMC | SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.28 Secure coding |
| SOC 2 (TSC) | CC7.1; CC8.1 |
| PCI DSS | 6.2.4 |
| HIPAA Security Rule | 164.312(c)(1) |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |
| DISA ASD STIG | APSC-DV-002510 |
| DFARS 252.204-7012 | (b)(2) |

### Cross-Site Scripting (XSS) (`xss`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A03:2021 Injection |
| OWASP ASVS | V5.3.3 Context-aware encoding |
| CWE Top 25 | CWE-79 |
| NIST SP 800-53 | SI-10 |
| NIST SP 800-171 | 3.14.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.PS-06 |
| CMMC | SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.28 |
| SOC 2 (TSC) | CC7.1 |
| PCI DSS | 6.2.4 |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |
| DISA ASD STIG | APSC-DV-002490 |

### Broken / Weak Cryptography (`crypto`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A02:2021 Cryptographic Failures |
| OWASP ASVS | V6 Stored Cryptography |
| CWE Top 25 | CWE-327; CWE-328; CWE-326 |
| NIST SP 800-53 | SC-13; SC-12 |
| NIST SP 800-171 | 3.13.11 |
| NIST SSDF 800-218 | PW.4.1 |
| NIST CSF | PR.DS-01 |
| CMMC | SC.L2-3.13.11 |
| ISO/IEC 27001 | A.8.24 Use of cryptography |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 4.2.1; 12.3.3 |
| HIPAA Security Rule | 164.312(a)(2)(iv) |
| FedRAMP | SC-13 |
| CIS Controls | 3.11 |
| FIPS 140-3 | FIPS 140-3 §approved algorithms |
| GDPR | Art.32(1)(a) |
| DFARS 252.204-7012 | (b)(2)(ii)(B) |
| DISA ASD STIG | APSC-DV-002010 |

### Hardcoded Secrets & Credentials (`secrets`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A07:2021 Identification & Auth Failures; A05:2021 Security Misconfiguration |
| OWASP ASVS | V2.10 Service Authentication; V6.4 Secret Management |
| CWE Top 25 | CWE-798; CWE-259; CWE-321 |
| NIST SP 800-53 | IA-5; SC-12; SA-15 |
| NIST SP 800-171 | 3.5.10 |
| NIST SSDF 800-218 | PW.4.4; PS.1.1 |
| NIST CSF | PR.AA-01 |
| CMMC | IA.L2-3.5.10 |
| ISO/IEC 27001 | A.8.24; A.5.17 Authentication information |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 8.6.2; 3.7 |
| HIPAA Security Rule | 164.312(d) |
| FedRAMP | IA-5 |
| CIS Controls | 3.11; 5.2 |
| SLSA | Provenance / key handling |
| DFARS 252.204-7012 | (b)(2) |
| DISA ASD STIG | APSC-DV-002330 |

### Broken Authentication (`authn`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A07:2021 Identification & Auth Failures |
| OWASP ASVS | V2 Authentication |
| OWASP API Security Top 10 | API2:2023 Broken Authentication |
| CWE Top 25 | CWE-287; CWE-306; CWE-384 |
| NIST SP 800-53 | IA-2; IA-5; AC-7 |
| NIST SP 800-171 | 3.5.1; 3.5.2 |
| NIST SSDF 800-218 | PW.1.1 |
| NIST CSF | PR.AA-02 |
| CMMC | IA.L1-3.5.1; IA.L1-3.5.2 |
| ISO/IEC 27001 | A.5.17; A.8.5 Secure authentication |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 8.3 |
| HIPAA Security Rule | 164.312(d) |
| FedRAMP | IA-2 |
| CIS Controls | 6.3 |
| DISA ASD STIG | APSC-DV-001520 |

### Broken Access Control / IDOR (`authz`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A01:2021 Broken Access Control |
| OWASP ASVS | V4 Access Control |
| OWASP API Security Top 10 | API1:2023 Broken Object Level Authorization; API5:2023 BFLA |
| CWE Top 25 | CWE-862; CWE-863; CWE-639 |
| NIST SP 800-53 | AC-3; AC-4; AC-6 |
| NIST SP 800-171 | 3.1.1; 3.1.2 |
| NIST SSDF 800-218 | PW.1.1 |
| NIST CSF | PR.AA-05 |
| CMMC | AC.L1-3.1.1; AC.L1-3.1.2 |
| ISO/IEC 27001 | A.8.3 Information access restriction; A.5.15 Access control |
| SOC 2 (TSC) | CC6.3 |
| PCI DSS | 7.2 |
| HIPAA Security Rule | 164.312(a)(1) |
| FedRAMP | AC-3 |
| CIS Controls | 6.8 |
| DISA ASD STIG | APSC-DV-000460 |

### Insecure Deserialization (`deserialization`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A08:2021 Software & Data Integrity Failures |
| OWASP ASVS | V5.5 Deserialization |
| CWE Top 25 | CWE-502 |
| NIST SP 800-53 | SI-10; SA-11 |
| NIST SP 800-171 | 3.14.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.DS-06 |
| CMMC | SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.28 |
| SOC 2 (TSC) | CC8.1 |
| PCI DSS | 6.2.4 |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |
| SLSA | Build integrity |

### Server-Side Request Forgery (`ssrf`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A10:2021 Server-Side Request Forgery |
| OWASP ASVS | V12.6 SSRF Protection |
| OWASP API Security Top 10 | API7:2023 SSRF |
| CWE Top 25 | CWE-918 |
| NIST SP 800-53 | SC-7; AC-4 |
| NIST SP 800-171 | 3.13.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.IR-01 |
| CMMC | SC.L1-3.13.1 |
| ISO/IEC 27001 | A.8.22 Segregation of networks |
| SOC 2 (TSC) | CC6.6 |
| PCI DSS | 1.4 |
| FedRAMP | SC-7 |
| CIS Controls | 12.2 |
| DISA ASD STIG | APSC-DV-002470 |

### Path Traversal / File Inclusion (`path-traversal`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A01:2021 Broken Access Control |
| OWASP ASVS | V12.3 File Execution |
| CWE Top 25 | CWE-22; CWE-23; CWE-98 |
| NIST SP 800-53 | AC-3; SI-10 |
| NIST SP 800-171 | 3.1.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.PS-06 |
| CMMC | AC.L1-3.1.1 |
| ISO/IEC 27001 | A.8.28 |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 6.2.4 |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |
| DISA ASD STIG | APSC-DV-002560 |

### XML External Entities (XXE) (`xxe`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A05:2021 Security Misconfiguration |
| OWASP ASVS | V5.5.2 XML parser hardening |
| CWE Top 25 | CWE-611 |
| NIST SP 800-53 | SI-10; CM-6 |
| NIST SP 800-171 | 3.14.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.PS-01 |
| CMMC | SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.28 |
| SOC 2 (TSC) | CC7.1 |
| PCI DSS | 6.2.4 |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |

### Insufficient Logging & Monitoring (`logging`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A09:2021 Security Logging & Monitoring Failures |
| OWASP ASVS | V7 Error & Logging |
| OWASP API Security Top 10 | API9:2023 Improper Inventory Management |
| CWE Top 25 | CWE-778; CWE-532 |
| NIST SP 800-53 | AU-2; AU-3; AU-12; SI-4 |
| NIST SP 800-171 | 3.3.1; 3.3.2 |
| NIST SSDF 800-218 | RV.1.3 |
| NIST CSF | DE.CM-01; DE.AE-02 |
| CMMC | AU.L2-3.3.1 |
| ISO/IEC 27001 | A.8.15 Logging; A.8.16 Monitoring |
| SOC 2 (TSC) | CC7.2; CC7.3 |
| PCI DSS | 10.2 |
| HIPAA Security Rule | 164.312(b) |
| FedRAMP | AU-2 |
| CIS Controls | 8.2; 8.5 |
| DISA ASD STIG | APSC-DV-000810 |

### Security Misconfiguration (`config`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A05:2021 Security Misconfiguration |
| OWASP ASVS | V14 Configuration |
| OWASP API Security Top 10 | API8:2023 Security Misconfiguration |
| CWE Top 25 | CWE-16; CWE-732 |
| NIST SP 800-53 | CM-6; CM-7; SA-15 |
| NIST SP 800-171 | 3.4.1; 3.4.2 |
| NIST SSDF 800-218 | PO.5.1; PW.9.1 |
| NIST CSF | PR.PS-01; ID.RA-01 |
| CMMC | CM.L2-3.4.1; CM.L2-3.4.2 |
| ISO/IEC 27001 | A.8.9 Configuration management |
| SOC 2 (TSC) | CC7.1 |
| PCI DSS | 2.2 |
| HIPAA Security Rule | 164.308(a)(1) |
| FedRAMP | CM-6 |
| CIS Controls | 4.1 |
| DISA ASD STIG | APSC-DV-001990 |
| DFARS 252.204-7012 | (b)(2)(ii)(A) |

### Vulnerable & Outdated Components (`deps`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A06:2021 Vulnerable & Outdated Components |
| OWASP ASVS | V14.2 Dependency |
| OWASP API Security Top 10 | API8:2023 Security Misconfiguration |
| CWE Top 25 | CWE-1104; CWE-937 |
| NIST SP 800-53 | SA-22; RA-5; SI-2 |
| NIST SP 800-171 | 3.11.2; 3.14.1 |
| NIST SSDF 800-218 | PW.4.1; RV.1.1 |
| NIST CSF | ID.RA-01; PR.PS-02 |
| CMMC | RA.L2-3.11.2; SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.8 Management of technical vulnerabilities |
| SOC 2 (TSC) | CC7.1 |
| PCI DSS | 6.3.3; 11.3 |
| HIPAA Security Rule | 164.308(a)(1)(ii)(A) |
| FedRAMP | RA-5 |
| CIS Controls | 7.3; 16.4 |
| SLSA | Dependencies tracked |
| DISA ASD STIG | APSC-DV-003480 |

### Software Supply-Chain Integrity (`supply-chain`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A08:2021 Software & Data Integrity Failures |
| OWASP ASVS | V10 Malicious Code |
| CWE Top 25 | CWE-1357; CWE-494 |
| NIST SP 800-53 | SR-3; SR-4; SR-11; SA-12 |
| NIST SP 800-171 | 3.4.1 |
| NIST SSDF 800-218 | PS.1.1; PS.2.1; PS.3.1; PW.4.4 |
| NIST CSF | ID.RA-09; GV.SC-01 |
| CMMC | CM.L2-3.4.1 |
| ISO/IEC 27001 | A.5.23 Cloud services; A.8.30 Outsourced development |
| SOC 2 (TSC) | CC9.2 |
| PCI DSS | 6.3.2 |
| FedRAMP | SR-3 |
| CIS Controls | 16.4 |
| SLSA | Build provenance / hermetic build |
| DISA ASD STIG | APSC-DV-003290 |
| DFARS 252.204-7012 | (m) Subcontracts |

### Improper Input Validation (`input-validation`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A03:2021 Injection |
| OWASP ASVS | V5.1 Input Validation |
| CWE Top 25 | CWE-20 |
| NIST SP 800-53 | SI-10 |
| NIST SP 800-171 | 3.14.1 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.PS-06 |
| CMMC | SI.L1-3.14.1 |
| ISO/IEC 27001 | A.8.28 |
| SOC 2 (TSC) | CC7.1 |
| PCI DSS | 6.2.4 |
| FedRAMP | SI-10 |
| CIS Controls | 16.11 |
| DISA ASD STIG | APSC-DV-002560 |

### Information Disclosure / Error Handling (`error-handling`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A04:2021 Insecure Design; A09:2021 Logging Failures |
| OWASP ASVS | V7.4 Error Handling |
| CWE Top 25 | CWE-209; CWE-200 |
| NIST SP 800-53 | SI-11; AU-9 |
| NIST SP 800-171 | 3.3.8 |
| NIST SSDF 800-218 | PW.7.1 |
| NIST CSF | DE.AE-03 |
| CMMC | AU.L2-3.3.8 |
| ISO/IEC 27001 | A.8.15 |
| SOC 2 (TSC) | CC7.2 |
| PCI DSS | 6.5 |
| FedRAMP | SI-11 |
| CIS Controls | 8.2 |
| DISA ASD STIG | APSC-DV-000940 |

### Session Management (`session`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A07:2021 Identification & Auth Failures |
| OWASP ASVS | V3 Session Management |
| CWE Top 25 | CWE-384; CWE-613; CWE-614 |
| NIST SP 800-53 | SC-23; AC-12 |
| NIST SP 800-171 | 3.1.11 |
| NIST SSDF 800-218 | PW.1.1 |
| NIST CSF | PR.AA-03 |
| CMMC | AC.L2-3.1.11 |
| ISO/IEC 27001 | A.8.5 |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 6.4.1 |
| HIPAA Security Rule | 164.312(a)(2)(iii) |
| FedRAMP | SC-23 |
| CIS Controls | 6.3 |
| DISA ASD STIG | APSC-DV-002250 |

### Insecure Transport (TLS) (`transport`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A02:2021 Cryptographic Failures |
| OWASP ASVS | V9 Communications |
| CWE Top 25 | CWE-319; CWE-295 |
| NIST SP 800-53 | SC-8; SC-13 |
| NIST SP 800-171 | 3.13.8 |
| NIST SSDF 800-218 | PW.9.1 |
| NIST CSF | PR.DS-02 |
| CMMC | SC.L2-3.13.8 |
| ISO/IEC 27001 | A.8.24; A.5.14 Information transfer |
| SOC 2 (TSC) | CC6.7 |
| PCI DSS | 4.2.1 |
| HIPAA Security Rule | 164.312(e)(1) |
| FedRAMP | SC-8 |
| CIS Controls | 3.10 |
| FIPS 140-3 | FIPS-validated TLS module |
| DISA ASD STIG | APSC-DV-001950 |

### Unrestricted File Upload (`file-upload`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A04:2021 Insecure Design; A05:2021 Misconfiguration |
| OWASP ASVS | V12 File & Resources |
| CWE Top 25 | CWE-434 |
| NIST SP 800-53 | SI-3; SI-10; SC-18 |
| NIST SP 800-171 | 3.14.2 |
| NIST SSDF 800-218 | PW.5.1 |
| NIST CSF | PR.PS-05 |
| CMMC | SI.L1-3.14.2 |
| ISO/IEC 27001 | A.8.7 Protection against malware |
| SOC 2 (TSC) | CC6.8 |
| PCI DSS | 5.3 |
| FedRAMP | SI-3 |
| CIS Controls | 10.1 |
| DISA ASD STIG | APSC-DV-002560 |

### Insecure Randomness (`random`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A02:2021 Cryptographic Failures |
| OWASP ASVS | V6.3 Random Values |
| CWE Top 25 | CWE-330; CWE-338 |
| NIST SP 800-53 | SC-13 |
| NIST SP 800-171 | 3.13.11 |
| NIST SSDF 800-218 | PW.4.1 |
| NIST CSF | PR.DS-01 |
| CMMC | SC.L2-3.13.11 |
| ISO/IEC 27001 | A.8.24 |
| SOC 2 (TSC) | CC6.1 |
| PCI DSS | 4.2.1 |
| FedRAMP | SC-13 |
| FIPS 140-3 | SP 800-90A DRBG |

### Malicious / Suspicious Binary (`malware`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A08:2021 Software & Data Integrity Failures |
| OWASP ASVS | V10 Malicious Code |
| CWE Top 25 | CWE-506; CWE-507 |
| NIST SP 800-53 | SI-3; SI-7; SR-11 |
| NIST SP 800-171 | 3.14.2; 3.14.5 |
| NIST SSDF 800-218 | PW.4.4; RV.1.1 |
| NIST CSF | DE.CM-09 |
| CMMC | SI.L1-3.14.2 |
| ISO/IEC 27001 | A.8.7 |
| SOC 2 (TSC) | CC6.8 |
| PCI DSS | 5.2 |
| HIPAA Security Rule | 164.308(a)(5)(ii)(B) |
| FedRAMP | SI-3 |
| CIS Controls | 10.1 |
| SLSA | Artifact integrity |
| DISA ASD STIG | APSC-DV-003290 |

### Sensitive Data / Privacy Exposure (`privacy`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A02:2021 Cryptographic Failures; A04:2021 Insecure Design |
| OWASP ASVS | V8 Data Protection |
| CWE Top 25 | CWE-359; CWE-312 |
| NIST SP 800-53 | SC-28; PT-3; MP-6 |
| NIST SP 800-171 | 3.13.16 |
| NIST SSDF 800-218 | PW.9.1 |
| NIST CSF | PR.DS-01 |
| CMMC | SC.L2-3.13.16 |
| ISO/IEC 27001 | A.8.10 Information deletion; A.5.34 Privacy & PII |
| SOC 2 (TSC) | P1.1; C1.1 |
| PCI DSS | 3.5 |
| HIPAA Security Rule | 164.312(a)(2)(iv) |
| FedRAMP | SC-28 |
| GDPR | Art.32 Security of processing; Art.25 Data protection by design |
| ISO/IEC 42001 | A.7 Data for AI |

### Code Quality & Maintainability (`quality`)

| Framework | Control(s) |
|---|---|
| OWASP Top 10 | A04:2021 Insecure Design |
| OWASP ASVS | V1 Architecture & Design |
| CWE Top 25 | CWE-1078; CWE-710 |
| NIST SP 800-53 | SA-11; SA-15 |
| NIST SP 800-171 | 3.13.2 |
| NIST SSDF 800-218 | PW.7.1; PW.8.1 |
| NIST CSF | PR.PS-06 |
| CMMC | SC.L2-3.13.2 |
| ISO/IEC 27001 | A.8.25 Secure development lifecycle |
| SOC 2 (TSC) | CC8.1 |
| PCI DSS | 6.2.1 |
| FedRAMP | SA-11 |
| CIS Controls | 16.1 |
| DISA ASD STIG | APSC-DV-003215 |

---
_Generated from `js/frameworks.js`. Control references are auditor-facing identifiers, not the full control text of each standard._
