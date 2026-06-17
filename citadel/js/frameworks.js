/* CITADEL — Compliance Mapping Engine
 * A catalog of security/compliance standards and a mapping from finding
 * categories -> the specific controls each finding implicates.
 * window.CITADEL.frameworks
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  /* The taxonomy of weakness categories the rules engine emits. */
  const CATEGORIES = {
    injection:        'Injection (SQL/NoSQL/OS Command/LDAP)',
    xss:              'Cross-Site Scripting (XSS)',
    crypto:           'Broken / Weak Cryptography',
    secrets:          'Hardcoded Secrets & Credentials',
    authn:            'Broken Authentication',
    authz:            'Broken Access Control / IDOR',
    deserialization:  'Insecure Deserialization',
    ssrf:             'Server-Side Request Forgery',
    'path-traversal': 'Path Traversal / File Inclusion',
    xxe:              'XML External Entities (XXE)',
    logging:          'Insufficient Logging & Monitoring',
    config:           'Security Misconfiguration',
    deps:             'Vulnerable & Outdated Components',
    'supply-chain':   'Software Supply-Chain Integrity',
    'input-validation':'Improper Input Validation',
    'error-handling': 'Information Disclosure / Error Handling',
    session:          'Session Management',
    transport:        'Insecure Transport (TLS)',
    'file-upload':    'Unrestricted File Upload',
    random:           'Insecure Randomness',
    malware:          'Malicious / Suspicious Binary',
    privacy:          'Sensitive Data / Privacy Exposure',
    quality:          'Code Quality & Maintainability'
  };

  /* Why a weakness of each category implicates the mapped control families —
   * a short, audit-friendly rationale shown on every finding so the mapping is
   * explainable, not asserted. */
  const RATIONALE = {
    injection:        'Untrusted input reaches an interpreter without neutralization — violates input-validation / secure-coding controls (e.g. NIST SI-10, OWASP A03, CWE-77/89).',
    xss:              'Untrusted data is rendered without output encoding — violates output-encoding / web-app protection controls (OWASP A03, CWE-79).',
    crypto:           'Weak or broken cryptography fails the data-protection controls for confidentiality/integrity (NIST SC-13, PCI 3/4, CWE-327).',
    secrets:          'Hardcoded credentials defeat identification & authentication and key-management controls (NIST IA-5, OWASP A07, CWE-798).',
    authn:            'Broken authentication weakens the identification & authentication control family (NIST IA, OWASP A07).',
    authz:            'Missing/!improper access control violates least-privilege and access-enforcement controls (NIST AC-3/AC-6, OWASP A01, CWE-862).',
    deserialization:  'Untrusted deserialization enables integrity/code-execution failures (NIST SI, OWASP A08, CWE-502).',
    ssrf:             'Server-side request forgery violates boundary-protection / access-control controls (NIST SC-7, OWASP A10, CWE-918).',
    'path-traversal': 'Path traversal breaks file-access confinement and access-enforcement controls (NIST AC-3, CWE-22).',
    xxe:              'XML external-entity processing exposes data/SSRF — violates input-validation controls (NIST SI-10, CWE-611).',
    logging:          'Insufficient logging undermines audit & accountability controls (NIST AU family, OWASP A09).',
    config:           'Security misconfiguration violates configuration-management & secure-baseline controls (NIST CM-6, OWASP A05).',
    deps:             'Known-vulnerable components violate flaw-remediation / vulnerability-management controls (NIST RA-5/SI-2, OWASP A06).',
    'supply-chain':   'Unverified components/provenance violate supply-chain integrity controls (NIST SR family, OWASP A08).',
    'input-validation':'Improper input validation violates the SI-10 input-validation control objective.',
    'error-handling': 'Information disclosure via errors violates error-handling / least-information controls (NIST SI-11, CWE-209).',
    session:          'Weak session handling violates session-management & authenticator controls (NIST IA/AC, CWE-384/614).',
    transport:        'Cleartext / weak TLS violates transmission-confidentiality controls (NIST SC-8, PCI 4, CWE-319).',
    'file-upload':    'Unrestricted upload violates input-validation & malicious-code controls (NIST SI-3/SI-10, CWE-434).',
    random:           'Predictable randomness weakens cryptographic & session controls (NIST SC-13, CWE-330).',
    malware:          'Malicious/suspicious binary triggers malicious-code-protection controls (NIST SI-3, CWE-506).',
    privacy:          'Exposed regulated data violates privacy & media-protection controls (GDPR/HIPAA/PCI, NIST MP/PT, CWE-359).',
    quality:          'Poor maintainability weakens the secure-development and change-management posture (NIST SA family).'
  };
  function rationale(cat) { return RATIONALE[cat] || 'Maps to the control families governing this weakness category.'; }

  /* The standards catalog. `families` is a lightweight set of control IDs
   * used for posture rendering; not the full control text of each standard. */
  const CATALOG = [
    { id:'owasp',  name:'OWASP Top 10', version:'2021', tag:'AppSec',
      url:'https://owasp.org/Top10/',
      desc:'The ten most critical web application security risks.' },
    { id:'asvs',   name:'OWASP ASVS', version:'4.0.3', tag:'AppSec',
      url:'https://owasp.org/www-project-application-security-verification-standard/',
      desc:'Application Security Verification Standard — testable requirements.' },
    { id:'apisec', name:'OWASP API Security Top 10', version:'2023', tag:'AppSec',
      url:'https://owasp.org/API-Security/', desc:'Top risks for APIs.' },
    { id:'cwe',    name:'CWE Top 25', version:'2023', tag:'Weakness',
      url:'https://cwe.mitre.org/top25/', desc:'Most dangerous software weaknesses (MITRE).' },
    { id:'nist53', name:'NIST SP 800-53', version:'Rev 5', tag:'Federal',
      url:'https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final',
      desc:'Security & privacy controls for federal information systems.' },
    { id:'nist171',name:'NIST SP 800-171', version:'Rev 2', tag:'CUI',
      url:'https://csrc.nist.gov/pubs/sp/800/171/r2/upd1/final',
      desc:'Protecting Controlled Unclassified Information (CUI).' },
    { id:'ssdf',   name:'NIST SSDF 800-218', version:'1.1', tag:'SDLC',
      url:'https://csrc.nist.gov/pubs/sp/800/218/final',
      desc:'Secure Software Development Framework practices.' },
    { id:'csf',    name:'NIST CSF', version:'2.0', tag:'Framework',
      url:'https://www.nist.gov/cyberframework',
      desc:'Cybersecurity Framework functions: GV, ID, PR, DE, RS, RC.' },
    { id:'cmmc',   name:'CMMC', version:'2.0 (L1–L2)', tag:'DoD',
      url:'https://dodcio.defense.gov/cmmc/',
      desc:'Cybersecurity Maturity Model Certification for the DIB.' },
    { id:'cmmi',   name:'CMMI-DEV', version:'v2.0', tag:'Process',
      url:'https://cmmiinstitute.com/',
      desc:'Capability Maturity Model Integration — development process maturity (practice areas).' },
    { id:'iso',    name:'ISO/IEC 27001', version:'2022', tag:'ISMS',
      url:'https://www.iso.org/standard/27001',
      desc:'Information security management system — Annex A controls.' },
    { id:'iso42',  name:'ISO/IEC 42001', version:'2023', tag:'AI',
      url:'https://www.iso.org/standard/81230.html',
      desc:'AI management system requirements.' },
    { id:'soc2',   name:'SOC 2 (TSC)', version:'2017 TSC', tag:'Attestation',
      url:'https://www.aicpa-cima.com/',
      desc:'AICPA Trust Services Criteria (Security, Availability, Confidentiality…).' },
    { id:'pci',    name:'PCI DSS', version:'4.0', tag:'Payments',
      url:'https://www.pcisecuritystandards.org/',
      desc:'Payment Card Industry Data Security Standard.' },
    { id:'hipaa',  name:'HIPAA Security Rule', version:'45 CFR 164', tag:'Healthcare',
      url:'https://www.hhs.gov/hipaa/', desc:'Safeguards for electronic PHI.' },
    { id:'fedramp',name:'FedRAMP', version:'Rev 5 Moderate', tag:'Cloud',
      url:'https://www.fedramp.gov/', desc:'Cloud security authorization baseline.' },
    { id:'cis',    name:'CIS Controls', version:'v8', tag:'Hardening',
      url:'https://www.cisecurity.org/controls', desc:'18 prioritized safeguards.' },
    { id:'gdpr',   name:'GDPR', version:'2016/679', tag:'Privacy',
      url:'https://gdpr-info.eu/', desc:'EU data protection — technical measures (Art. 32).' },
    { id:'slsa',   name:'SLSA', version:'v1.0', tag:'Supply Chain',
      url:'https://slsa.dev/', desc:'Supply-chain Levels for Software Artifacts.' },
    { id:'fips',   name:'FIPS 140-3', version:'2019', tag:'Crypto',
      url:'https://csrc.nist.gov/pubs/fips/140-3/final',
      desc:'Security requirements for cryptographic modules.' },
    { id:'dfars',  name:'DFARS 252.204-7012', version:'2016', tag:'DoD',
      url:'https://www.acquisition.gov/dfars/252.204-7012',
      desc:'Safeguarding covered defense information & cyber incident reporting.' },
    { id:'stig',   name:'DISA ASD STIG', version:'V5', tag:'DoD',
      url:'https://public.cyber.mil/stigs/',
      desc:'Application Security & Development Security Technical Implementation Guide.' }
  ];

  /* category -> { frameworkId: [control references] }
   * References are the concrete control identifiers an auditor would cite. */
  const MAP = {
    injection: {
      owasp:['A03:2021 Injection'], asvs:['V5.3 Output Encoding & Injection'],
      apisec:['API8:2023 Security Misconfiguration'], cwe:['CWE-89','CWE-78','CWE-77'],
      nist53:['SI-10','SI-15'], nist171:['3.14.1'], ssdf:['PW.5.1','RV.1.1'],
      csf:['PR.PS-06'], cmmc:['SI.L1-3.14.1'], iso:['A.8.28 Secure coding'],
      soc2:['CC7.1','CC8.1'], pci:['6.2.4'], hipaa:['164.312(c)(1)'],
      fedramp:['SI-10'], cis:['16.11'], stig:['APSC-DV-002510'], dfars:['(b)(2)']
    },
    xss: {
      owasp:['A03:2021 Injection'], asvs:['V5.3.3 Context-aware encoding'],
      cwe:['CWE-79'], nist53:['SI-10'], nist171:['3.14.1'], ssdf:['PW.5.1'],
      csf:['PR.PS-06'], cmmc:['SI.L1-3.14.1'], iso:['A.8.28'], soc2:['CC7.1'],
      pci:['6.2.4'], fedramp:['SI-10'], cis:['16.11'], stig:['APSC-DV-002490']
    },
    crypto: {
      owasp:['A02:2021 Cryptographic Failures'], asvs:['V6 Stored Cryptography'],
      cwe:['CWE-327','CWE-328','CWE-326'], nist53:['SC-13','SC-12'], nist171:['3.13.11'],
      ssdf:['PW.4.1'], csf:['PR.DS-01'], cmmc:['SC.L2-3.13.11'], iso:['A.8.24 Use of cryptography'],
      soc2:['CC6.1'], pci:['4.2.1','12.3.3'], hipaa:['164.312(a)(2)(iv)'], fedramp:['SC-13'],
      cis:['3.11'], fips:['FIPS 140-3 §approved algorithms'], gdpr:['Art.32(1)(a)'],
      dfars:['(b)(2)(ii)(B)'], stig:['APSC-DV-002010']
    },
    secrets: {
      owasp:['A07:2021 Identification & Auth Failures','A05:2021 Security Misconfiguration'],
      asvs:['V2.10 Service Authentication','V6.4 Secret Management'], cwe:['CWE-798','CWE-259','CWE-321'],
      nist53:['IA-5','SC-12','SA-15'], nist171:['3.5.10'], ssdf:['PW.4.4','PS.1.1'],
      csf:['PR.AA-01'], cmmc:['IA.L2-3.5.10'], iso:['A.8.24','A.5.17 Authentication information'],
      soc2:['CC6.1'], pci:['8.6.2','3.7'], hipaa:['164.312(d)'], fedramp:['IA-5'],
      cis:['3.11','5.2'], slsa:['Provenance / key handling'], dfars:['(b)(2)'], stig:['APSC-DV-002330']
    },
    authn: {
      owasp:['A07:2021 Identification & Auth Failures'], asvs:['V2 Authentication'],
      apisec:['API2:2023 Broken Authentication'], cwe:['CWE-287','CWE-306','CWE-384'],
      nist53:['IA-2','IA-5','AC-7'], nist171:['3.5.1','3.5.2'], ssdf:['PW.1.1'],
      csf:['PR.AA-02'], cmmc:['IA.L1-3.5.1','IA.L1-3.5.2'], iso:['A.5.17','A.8.5 Secure authentication'],
      soc2:['CC6.1'], pci:['8.3'], hipaa:['164.312(d)'], fedramp:['IA-2'], cis:['6.3'],
      stig:['APSC-DV-001520']
    },
    authz: {
      owasp:['A01:2021 Broken Access Control'], asvs:['V4 Access Control'],
      apisec:['API1:2023 Broken Object Level Authorization','API5:2023 BFLA'],
      cwe:['CWE-862','CWE-863','CWE-639'], nist53:['AC-3','AC-4','AC-6'], nist171:['3.1.1','3.1.2'],
      ssdf:['PW.1.1'], csf:['PR.AA-05'], cmmc:['AC.L1-3.1.1','AC.L1-3.1.2'],
      iso:['A.8.3 Information access restriction','A.5.15 Access control'], soc2:['CC6.3'],
      pci:['7.2'], hipaa:['164.312(a)(1)'], fedramp:['AC-3'], cis:['6.8'], stig:['APSC-DV-000460']
    },
    deserialization: {
      owasp:['A08:2021 Software & Data Integrity Failures'], asvs:['V5.5 Deserialization'],
      cwe:['CWE-502'], nist53:['SI-10','SA-11'], nist171:['3.14.1'], ssdf:['PW.5.1'],
      csf:['PR.DS-06'], cmmc:['SI.L1-3.14.1'], iso:['A.8.28'], soc2:['CC8.1'],
      pci:['6.2.4'], fedramp:['SI-10'], cis:['16.11'], slsa:['Build integrity']
    },
    ssrf: {
      owasp:['A10:2021 Server-Side Request Forgery'], asvs:['V12.6 SSRF Protection'],
      apisec:['API7:2023 SSRF'], cwe:['CWE-918'], nist53:['SC-7','AC-4'], nist171:['3.13.1'],
      ssdf:['PW.5.1'], csf:['PR.IR-01'], cmmc:['SC.L1-3.13.1'], iso:['A.8.22 Segregation of networks'],
      soc2:['CC6.6'], pci:['1.4'], fedramp:['SC-7'], cis:['12.2'], stig:['APSC-DV-002470']
    },
    'path-traversal': {
      owasp:['A01:2021 Broken Access Control'], asvs:['V12.3 File Execution'],
      cwe:['CWE-22','CWE-23','CWE-98'], nist53:['AC-3','SI-10'], nist171:['3.1.1'],
      ssdf:['PW.5.1'], csf:['PR.PS-06'], cmmc:['AC.L1-3.1.1'], iso:['A.8.28'],
      soc2:['CC6.1'], pci:['6.2.4'], fedramp:['SI-10'], cis:['16.11'], stig:['APSC-DV-002560']
    },
    xxe: {
      owasp:['A05:2021 Security Misconfiguration'], asvs:['V5.5.2 XML parser hardening'],
      cwe:['CWE-611'], nist53:['SI-10','CM-6'], nist171:['3.14.1'], ssdf:['PW.5.1'],
      csf:['PR.PS-01'], cmmc:['SI.L1-3.14.1'], iso:['A.8.28'], soc2:['CC7.1'],
      pci:['6.2.4'], fedramp:['SI-10'], cis:['16.11']
    },
    logging: {
      owasp:['A09:2021 Security Logging & Monitoring Failures'], asvs:['V7 Error & Logging'],
      apisec:['API9:2023 Improper Inventory Management'], cwe:['CWE-778','CWE-532'],
      nist53:['AU-2','AU-3','AU-12','SI-4'], nist171:['3.3.1','3.3.2'], ssdf:['RV.1.3'],
      csf:['DE.CM-01','DE.AE-02'], cmmc:['AU.L2-3.3.1'], iso:['A.8.15 Logging','A.8.16 Monitoring'],
      soc2:['CC7.2','CC7.3'], pci:['10.2'], hipaa:['164.312(b)'], fedramp:['AU-2'],
      cis:['8.2','8.5'], stig:['APSC-DV-000810']
    },
    config: {
      owasp:['A05:2021 Security Misconfiguration'], asvs:['V14 Configuration'],
      apisec:['API8:2023 Security Misconfiguration'], cwe:['CWE-16','CWE-732'],
      nist53:['CM-6','CM-7','SA-15'], nist171:['3.4.1','3.4.2'], ssdf:['PO.5.1','PW.9.1'],
      csf:['PR.PS-01','ID.RA-01'], cmmc:['CM.L2-3.4.1','CM.L2-3.4.2'],
      iso:['A.8.9 Configuration management'], soc2:['CC7.1'], pci:['2.2'], hipaa:['164.308(a)(1)'],
      fedramp:['CM-6'], cis:['4.1'], stig:['APSC-DV-001990'], dfars:['(b)(2)(ii)(A)']
    },
    deps: {
      owasp:['A06:2021 Vulnerable & Outdated Components'], asvs:['V14.2 Dependency'],
      apisec:['API8:2023 Security Misconfiguration'], cwe:['CWE-1104','CWE-937'],
      nist53:['SA-22','RA-5','SI-2'], nist171:['3.11.2','3.14.1'], ssdf:['PW.4.1','RV.1.1'],
      csf:['ID.RA-01','PR.PS-02'], cmmc:['RA.L2-3.11.2','SI.L1-3.14.1'],
      iso:['A.8.8 Management of technical vulnerabilities'], soc2:['CC7.1'], pci:['6.3.3','11.3'],
      hipaa:['164.308(a)(1)(ii)(A)'], fedramp:['RA-5'], cis:['7.3','16.4'],
      slsa:['Dependencies tracked'], stig:['APSC-DV-003480']
    },
    'supply-chain': {
      owasp:['A08:2021 Software & Data Integrity Failures'], asvs:['V10 Malicious Code'],
      cwe:['CWE-1357','CWE-494'], nist53:['SR-3','SR-4','SR-11','SA-12'], nist171:['3.4.1'],
      ssdf:['PS.1.1','PS.2.1','PS.3.1','PW.4.4'], csf:['ID.RA-09','GV.SC-01'],
      cmmc:['CM.L2-3.4.1'], iso:['A.5.23 Cloud services','A.8.30 Outsourced development'],
      soc2:['CC9.2'], pci:['6.3.2'], fedramp:['SR-3'], cis:['16.4'],
      slsa:['Build provenance / hermetic build'], stig:['APSC-DV-003290'], dfars:['(m) Subcontracts']
    },
    'input-validation': {
      owasp:['A03:2021 Injection'], asvs:['V5.1 Input Validation'], cwe:['CWE-20'],
      nist53:['SI-10'], nist171:['3.14.1'], ssdf:['PW.5.1'], csf:['PR.PS-06'],
      cmmc:['SI.L1-3.14.1'], iso:['A.8.28'], soc2:['CC7.1'], pci:['6.2.4'],
      fedramp:['SI-10'], cis:['16.11'], stig:['APSC-DV-002560']
    },
    'error-handling': {
      owasp:['A04:2021 Insecure Design','A09:2021 Logging Failures'], asvs:['V7.4 Error Handling'],
      cwe:['CWE-209','CWE-200'], nist53:['SI-11','AU-9'], nist171:['3.3.8'], ssdf:['PW.7.1'],
      csf:['DE.AE-03'], cmmc:['AU.L2-3.3.8'], iso:['A.8.15'], soc2:['CC7.2'], pci:['6.5'],
      fedramp:['SI-11'], cis:['8.2'], stig:['APSC-DV-000940']
    },
    session: {
      owasp:['A07:2021 Identification & Auth Failures'], asvs:['V3 Session Management'],
      cwe:['CWE-384','CWE-613','CWE-614'], nist53:['SC-23','AC-12'], nist171:['3.1.11'],
      ssdf:['PW.1.1'], csf:['PR.AA-03'], cmmc:['AC.L2-3.1.11'], iso:['A.8.5'], soc2:['CC6.1'],
      pci:['6.4.1'], hipaa:['164.312(a)(2)(iii)'], fedramp:['SC-23'], cis:['6.3'], stig:['APSC-DV-002250']
    },
    transport: {
      owasp:['A02:2021 Cryptographic Failures'], asvs:['V9 Communications'],
      cwe:['CWE-319','CWE-295'], nist53:['SC-8','SC-13'], nist171:['3.13.8'], ssdf:['PW.9.1'],
      csf:['PR.DS-02'], cmmc:['SC.L2-3.13.8'], iso:['A.8.24','A.5.14 Information transfer'],
      soc2:['CC6.7'], pci:['4.2.1'], hipaa:['164.312(e)(1)'], fedramp:['SC-8'], cis:['3.10'],
      fips:['FIPS-validated TLS module'], stig:['APSC-DV-001950']
    },
    'file-upload': {
      owasp:['A04:2021 Insecure Design','A05:2021 Misconfiguration'], asvs:['V12 File & Resources'],
      cwe:['CWE-434'], nist53:['SI-3','SI-10','SC-18'], nist171:['3.14.2'], ssdf:['PW.5.1'],
      csf:['PR.PS-05'], cmmc:['SI.L1-3.14.2'], iso:['A.8.7 Protection against malware'], soc2:['CC6.8'],
      pci:['5.3'], fedramp:['SI-3'], cis:['10.1'], stig:['APSC-DV-002560']
    },
    random: {
      owasp:['A02:2021 Cryptographic Failures'], asvs:['V6.3 Random Values'], cwe:['CWE-330','CWE-338'],
      nist53:['SC-13'], nist171:['3.13.11'], ssdf:['PW.4.1'], csf:['PR.DS-01'], cmmc:['SC.L2-3.13.11'],
      iso:['A.8.24'], soc2:['CC6.1'], pci:['4.2.1'], fedramp:['SC-13'], fips:['SP 800-90A DRBG']
    },
    malware: {
      owasp:['A08:2021 Software & Data Integrity Failures'], asvs:['V10 Malicious Code'],
      cwe:['CWE-506','CWE-507'], nist53:['SI-3','SI-7','SR-11'], nist171:['3.14.2','3.14.5'],
      ssdf:['PW.4.4','RV.1.1'], csf:['DE.CM-09'], cmmc:['SI.L1-3.14.2'], iso:['A.8.7'],
      soc2:['CC6.8'], pci:['5.2'], hipaa:['164.308(a)(5)(ii)(B)'], fedramp:['SI-3'],
      cis:['10.1'], slsa:['Artifact integrity'], stig:['APSC-DV-003290']
    },
    privacy: {
      owasp:['A02:2021 Cryptographic Failures','A04:2021 Insecure Design'], asvs:['V8 Data Protection'],
      cwe:['CWE-359','CWE-312'], nist53:['SC-28','PT-3','MP-6'], nist171:['3.13.16'], ssdf:['PW.9.1'],
      csf:['PR.DS-01'], cmmc:['SC.L2-3.13.16'], iso:['A.8.10 Information deletion','A.5.34 Privacy & PII'],
      soc2:['P1.1','C1.1'], pci:['3.5'], hipaa:['164.312(a)(2)(iv)'], fedramp:['SC-28'],
      gdpr:['Art.32 Security of processing','Art.25 Data protection by design'], iso42:['A.7 Data for AI']
    },
    quality: {
      owasp:['A04:2021 Insecure Design'], asvs:['V1 Architecture & Design'], cwe:['CWE-1078','CWE-710'],
      nist53:['SA-11','SA-15'], nist171:['3.13.2'], ssdf:['PW.7.1','PW.8.1'], csf:['PR.PS-06'],
      cmmc:['SC.L2-3.13.2'], iso:['A.8.25 Secure development lifecycle'], soc2:['CC8.1'],
      pci:['6.2.1'], fedramp:['SA-11'], cis:['16.1'], stig:['APSC-DV-003215']
    }
  };

  /* CMMI-DEV v2.0 practice areas implicated per weakness category. CMMI is a
   * process-maturity model (not a security control set), so findings map to the
   * engineering/management practice areas responsible for catching them. */
  const CODE_PA = ['TS Technical Solution', 'VV Verification & Validation', 'PR Peer Reviews'];
  const CMMI_PA = {
    injection: CODE_PA, xss: CODE_PA, crypto: CODE_PA, secrets: CODE_PA, authn: CODE_PA,
    authz: CODE_PA, deserialization: CODE_PA, ssrf: CODE_PA, 'path-traversal': CODE_PA,
    xxe: CODE_PA, 'input-validation': CODE_PA, session: CODE_PA, transport: CODE_PA,
    'file-upload': CODE_PA, random: CODE_PA,
    'error-handling': ['VV Verification & Validation', 'CAR Causal Analysis & Resolution'],
    logging: ['MC Monitor and Control', 'MPM Managing Performance & Measurement'],
    config: ['CM Configuration Management'],
    deps: ['CM Configuration Management', 'RSK Risk & Opportunity Management'],
    'supply-chain': ['SAM Supplier Agreement Management', 'RSK Risk & Opportunity Management'],
    malware: ['VV Verification & Validation', 'RSK Risk & Opportunity Management'],
    privacy: ['RDM Requirements Development & Management', 'TS Technical Solution'],
    quality: ['PQA Process Quality Assurance', 'PR Peer Reviews', 'VV Verification & Validation']
  };
  Object.keys(MAP).forEach(cat => { if (CMMI_PA[cat]) MAP[cat].cmmi = CMMI_PA[cat]; });

  /* Compute per-framework posture from a list of findings.
   * Returns array sorted by impact. */
  function posture(findings) {
    const byFw = {};
    CATALOG.forEach(f => { byFw[f.id] = { fw: f, controls: {}, sev: { critical:0, high:0, medium:0, low:0, info:0 } }; });

    findings.forEach(fn => {
      const cat = fn.category;
      const m = MAP[cat] || {};
      Object.keys(m).forEach(fwId => {
        if (!byFw[fwId]) return;
        m[fwId].forEach(ctrl => {
          byFw[fwId].controls[ctrl] = (byFw[fwId].controls[ctrl] || 0) + 1;
        });
        const s = fn.severity in byFw[fwId].sev ? fn.severity : 'info';
        byFw[fwId].sev[s]++;
      });
    });

    return CATALOG.map(f => {
      const b = byFw[f.id];
      const ctrlList = Object.keys(b.controls).map(c => ({ id: c, count: b.controls[c] }))
        .sort((a, z) => z.count - a.count);
      const total = b.sev.critical + b.sev.high + b.sev.medium + b.sev.low + b.sev.info;
      // posture grade for this framework based on weighted findings
      const weighted = b.sev.critical * 10 + b.sev.high * 6 + b.sev.medium * 3 + b.sev.low * 1;
      let status = 'pass';
      if (b.sev.critical > 0 || weighted >= 30) status = 'fail';
      else if (b.sev.high > 0 || weighted >= 10) status = 'partial';
      else if (total > 0) status = 'partial';
      const cat = (CITADEL.controlCatalog || {})[f.id];
      const totalControls = cat ? cat.families.reduce((a, fam) => a + (fam.controls ? fam.controls.length : 0), 0) : 0;
      return {
        id: f.id, name: f.name, version: f.version, tag: f.tag, url: f.url, desc: f.desc,
        controls: ctrlList, controlCount: ctrlList.length, findings: total,
        totalControls, catalog: cat || null,
        severity: b.sev, status
      };
    }).sort((a, z) => z.findings - a.findings || z.controlCount - a.controlCount);
  }

  // Full control catalog accessor + flat control count across all frameworks.
  function catalog(fwId) { return (CITADEL.controlCatalog || {})[fwId] || null; }
  function catalogTotal() {
    const c = CITADEL.controlCatalog || {};
    return Object.keys(c).reduce((a, k) => a + c[k].families.reduce((s, f) => s + (f.controls ? f.controls.length : 0), 0), 0);
  }

  CITADEL.frameworks = { CATALOG, CATEGORIES, MAP, RATIONALE, rationale, posture, catalog, catalogTotal };
})(window);
