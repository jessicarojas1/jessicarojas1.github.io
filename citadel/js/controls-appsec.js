window.CITADEL = window.CITADEL || {};
window.CITADEL.controlCatalog = Object.assign(window.CITADEL.controlCatalog || {}, {

  owasp: {
    total: 10,
    note: 'OWASP Top 10:2021',
    families: [
      {
        id: 'top10',
        name: 'Top 10',
        controls: [
          { id: 'A01:2021', title: 'Broken Access Control' },
          { id: 'A02:2021', title: 'Cryptographic Failures' },
          { id: 'A03:2021', title: 'Injection' },
          { id: 'A04:2021', title: 'Insecure Design' },
          { id: 'A05:2021', title: 'Security Misconfiguration' },
          { id: 'A06:2021', title: 'Vulnerable and Outdated Components' },
          { id: 'A07:2021', title: 'Identification and Authentication Failures' },
          { id: 'A08:2021', title: 'Software and Data Integrity Failures' },
          { id: 'A09:2021', title: 'Security Logging and Monitoring Failures' },
          { id: 'A10:2021', title: 'Server-Side Request Forgery (SSRF)' }
        ]
      }
    ]
  },

  apisec: {
    total: 10,
    note: 'OWASP API Security Top 10:2023',
    families: [
      {
        id: 'apitop10',
        name: 'API Security Top 10',
        controls: [
          { id: 'API1:2023', title: 'Broken Object Level Authorization' },
          { id: 'API2:2023', title: 'Broken Authentication' },
          { id: 'API3:2023', title: 'Broken Object Property Level Authorization' },
          { id: 'API4:2023', title: 'Unrestricted Resource Consumption' },
          { id: 'API5:2023', title: 'Broken Function Level Authorization' },
          { id: 'API6:2023', title: 'Unrestricted Access to Sensitive Business Flows' },
          { id: 'API7:2023', title: 'Server Side Request Forgery' },
          { id: 'API8:2023', title: 'Security Misconfiguration' },
          { id: 'API9:2023', title: 'Improper Inventory Management' },
          { id: 'API10:2023', title: 'Unsafe Consumption of APIs' }
        ]
      }
    ]
  },

  asvs: {
    total: 14,
    note: 'OWASP Application Security Verification Standard 4.0.3',
    families: [
      {
        id: 'verification',
        name: 'Verification Requirements',
        controls: [
          { id: 'V1', title: 'Architecture, Design and Threat Modeling' },
          { id: 'V2', title: 'Authentication' },
          { id: 'V3', title: 'Session Management' },
          { id: 'V4', title: 'Access Control' },
          { id: 'V5', title: 'Validation, Sanitization and Encoding' },
          { id: 'V6', title: 'Stored Cryptography' },
          { id: 'V7', title: 'Error Handling and Logging' },
          { id: 'V8', title: 'Data Protection' },
          { id: 'V9', title: 'Communication' },
          { id: 'V10', title: 'Malicious Code' },
          { id: 'V11', title: 'Business Logic' },
          { id: 'V12', title: 'Files and Resources' },
          { id: 'V13', title: 'API and Web Service' },
          { id: 'V14', title: 'Configuration' }
        ]
      }
    ]
  },

  cwe: {
    total: 25,
    note: 'CWE Top 25 Most Dangerous Software Weaknesses (2023)',
    families: [
      {
        id: 'top25',
        name: 'CWE Top 25 (2023)',
        controls: [
          { id: 'CWE-787', title: 'Out-of-bounds Write' },
          { id: 'CWE-79', title: 'Improper Neutralization of Input During Web Page Generation (Cross-site Scripting)' },
          { id: 'CWE-89', title: 'Improper Neutralization of Special Elements used in an SQL Command (SQL Injection)' },
          { id: 'CWE-416', title: 'Use After Free' },
          { id: 'CWE-78', title: 'Improper Neutralization of Special Elements used in an OS Command (OS Command Injection)' },
          { id: 'CWE-20', title: 'Improper Input Validation' },
          { id: 'CWE-125', title: 'Out-of-bounds Read' },
          { id: 'CWE-22', title: 'Improper Limitation of a Pathname to a Restricted Directory (Path Traversal)' },
          { id: 'CWE-352', title: 'Cross-Site Request Forgery (CSRF)' },
          { id: 'CWE-434', title: 'Unrestricted Upload of File with Dangerous Type' },
          { id: 'CWE-862', title: 'Missing Authorization' },
          { id: 'CWE-476', title: 'NULL Pointer Dereference' },
          { id: 'CWE-287', title: 'Improper Authentication' },
          { id: 'CWE-190', title: 'Integer Overflow or Wraparound' },
          { id: 'CWE-502', title: 'Deserialization of Untrusted Data' },
          { id: 'CWE-77', title: 'Improper Neutralization of Special Elements used in a Command (Command Injection)' },
          { id: 'CWE-119', title: 'Improper Restriction of Operations within the Bounds of a Memory Buffer' },
          { id: 'CWE-798', title: 'Use of Hard-coded Credentials' },
          { id: 'CWE-918', title: 'Server-Side Request Forgery (SSRF)' },
          { id: 'CWE-306', title: 'Missing Authentication for Critical Function' },
          { id: 'CWE-362', title: 'Concurrent Execution using Shared Resource with Improper Synchronization (Race Condition)' },
          { id: 'CWE-269', title: 'Improper Privilege Management' },
          { id: 'CWE-94', title: 'Improper Control of Generation of Code (Code Injection)' },
          { id: 'CWE-863', title: 'Incorrect Authorization' },
          { id: 'CWE-276', title: 'Incorrect Default Permissions' }
        ]
      }
    ]
  },

  iso: {
    total: 93,
    note: 'ISO/IEC 27001:2022 Annex A',
    families: [
      {
        id: 'A.5',
        name: 'A.5 Organizational Controls',
        controls: [
          { id: 'A.5.1', title: 'Policies for information security' },
          { id: 'A.5.2', title: 'Information security roles and responsibilities' },
          { id: 'A.5.3', title: 'Segregation of duties' },
          { id: 'A.5.4', title: 'Management responsibilities' },
          { id: 'A.5.5', title: 'Contact with authorities' },
          { id: 'A.5.6', title: 'Contact with special interest groups' },
          { id: 'A.5.7', title: 'Threat intelligence' },
          { id: 'A.5.8', title: 'Information security in project management' },
          { id: 'A.5.9', title: 'Inventory of information and other associated assets' },
          { id: 'A.5.10', title: 'Acceptable use of information and other associated assets' },
          { id: 'A.5.11', title: 'Return of assets' },
          { id: 'A.5.12', title: 'Classification of information' },
          { id: 'A.5.13', title: 'Labelling of information' },
          { id: 'A.5.14', title: 'Information transfer' },
          { id: 'A.5.15', title: 'Access control' },
          { id: 'A.5.16', title: 'Identity management' },
          { id: 'A.5.17', title: 'Authentication information' },
          { id: 'A.5.18', title: 'Access rights' },
          { id: 'A.5.19', title: 'Information security in supplier relationships' },
          { id: 'A.5.20', title: 'Addressing information security within supplier agreements' },
          { id: 'A.5.21', title: 'Managing information security in the ICT supply chain' },
          { id: 'A.5.22', title: 'Monitoring, review and change management of supplier services' },
          { id: 'A.5.23', title: 'Information security for use of cloud services' },
          { id: 'A.5.24', title: 'Information security incident management planning and preparation' },
          { id: 'A.5.25', title: 'Assessment and decision on information security events' },
          { id: 'A.5.26', title: 'Response to information security incidents' },
          { id: 'A.5.27', title: 'Learning from information security incidents' },
          { id: 'A.5.28', title: 'Collection of evidence' },
          { id: 'A.5.29', title: 'Information security during disruption' },
          { id: 'A.5.30', title: 'ICT readiness for business continuity' },
          { id: 'A.5.31', title: 'Legal, statutory, regulatory and contractual requirements' },
          { id: 'A.5.32', title: 'Intellectual property rights' },
          { id: 'A.5.33', title: 'Protection of records' },
          { id: 'A.5.34', title: 'Privacy and protection of personal identifiable information (PII)' },
          { id: 'A.5.35', title: 'Independent review of information security' },
          { id: 'A.5.36', title: 'Compliance with policies, rules and standards for information security' },
          { id: 'A.5.37', title: 'Documented operating procedures' }
        ]
      },
      {
        id: 'A.6',
        name: 'A.6 People Controls',
        controls: [
          { id: 'A.6.1', title: 'Screening' },
          { id: 'A.6.2', title: 'Terms and conditions of employment' },
          { id: 'A.6.3', title: 'Information security awareness, education and training' },
          { id: 'A.6.4', title: 'Disciplinary process' },
          { id: 'A.6.5', title: 'Responsibilities after termination or change of employment' },
          { id: 'A.6.6', title: 'Confidentiality or non-disclosure agreements' },
          { id: 'A.6.7', title: 'Remote working' },
          { id: 'A.6.8', title: 'Information security event reporting' }
        ]
      },
      {
        id: 'A.7',
        name: 'A.7 Physical Controls',
        controls: [
          { id: 'A.7.1', title: 'Physical security perimeters' },
          { id: 'A.7.2', title: 'Physical entry' },
          { id: 'A.7.3', title: 'Securing offices, rooms and facilities' },
          { id: 'A.7.4', title: 'Physical security monitoring' },
          { id: 'A.7.5', title: 'Protecting against physical and environmental threats' },
          { id: 'A.7.6', title: 'Working in secure areas' },
          { id: 'A.7.7', title: 'Clear desk and clear screen' },
          { id: 'A.7.8', title: 'Equipment siting and protection' },
          { id: 'A.7.9', title: 'Security of assets off-premises' },
          { id: 'A.7.10', title: 'Storage media' },
          { id: 'A.7.11', title: 'Supporting utilities' },
          { id: 'A.7.12', title: 'Cabling security' },
          { id: 'A.7.13', title: 'Equipment maintenance' },
          { id: 'A.7.14', title: 'Secure disposal or re-use of equipment' }
        ]
      },
      {
        id: 'A.8',
        name: 'A.8 Technological Controls',
        controls: [
          { id: 'A.8.1', title: 'User endpoint devices' },
          { id: 'A.8.2', title: 'Privileged access rights' },
          { id: 'A.8.3', title: 'Information access restriction' },
          { id: 'A.8.4', title: 'Access to source code' },
          { id: 'A.8.5', title: 'Secure authentication' },
          { id: 'A.8.6', title: 'Capacity management' },
          { id: 'A.8.7', title: 'Protection against malware' },
          { id: 'A.8.8', title: 'Management of technical vulnerabilities' },
          { id: 'A.8.9', title: 'Configuration management' },
          { id: 'A.8.10', title: 'Information deletion' },
          { id: 'A.8.11', title: 'Data masking' },
          { id: 'A.8.12', title: 'Data leakage prevention' },
          { id: 'A.8.13', title: 'Information backup' },
          { id: 'A.8.14', title: 'Redundancy of information processing facilities' },
          { id: 'A.8.15', title: 'Logging' },
          { id: 'A.8.16', title: 'Monitoring activities' },
          { id: 'A.8.17', title: 'Clock synchronization' },
          { id: 'A.8.18', title: 'Use of privileged utility programs' },
          { id: 'A.8.19', title: 'Installation of software on operational systems' },
          { id: 'A.8.20', title: 'Networks security' },
          { id: 'A.8.21', title: 'Security of network services' },
          { id: 'A.8.22', title: 'Segregation of networks' },
          { id: 'A.8.23', title: 'Web filtering' },
          { id: 'A.8.24', title: 'Use of cryptography' },
          { id: 'A.8.25', title: 'Secure development life cycle' },
          { id: 'A.8.26', title: 'Application security requirements' },
          { id: 'A.8.27', title: 'Secure system architecture and engineering principles' },
          { id: 'A.8.28', title: 'Secure coding' },
          { id: 'A.8.29', title: 'Security testing in development and acceptance' },
          { id: 'A.8.30', title: 'Outsourced development' },
          { id: 'A.8.31', title: 'Separation of development, test and production environments' },
          { id: 'A.8.32', title: 'Change management' },
          { id: 'A.8.33', title: 'Test information' },
          { id: 'A.8.34', title: 'Protection of information systems during audit testing' }
        ]
      }
    ]
  },

  iso42: {
    total: 38,
    note: 'ISO/IEC 42001:2023 Annex A (AI management system controls)',
    families: [
      {
        id: 'A.2',
        name: 'A.2 Policies related to AI',
        controls: [
          { id: 'A.2.2', title: 'AI policy' },
          { id: 'A.2.3', title: 'Alignment with other organizational policies' },
          { id: 'A.2.4', title: 'Review of the AI policy' }
        ]
      },
      {
        id: 'A.3',
        name: 'A.3 Internal organization',
        controls: [
          { id: 'A.3.2', title: 'AI roles and responsibilities' },
          { id: 'A.3.3', title: 'Reporting of concerns' }
        ]
      },
      {
        id: 'A.4',
        name: 'A.4 Resources for AI systems',
        controls: [
          { id: 'A.4.2', title: 'Resource documentation' },
          { id: 'A.4.3', title: 'Data resources' },
          { id: 'A.4.4', title: 'Tooling resources' },
          { id: 'A.4.5', title: 'System and computing resources' },
          { id: 'A.4.6', title: 'Human resources' }
        ]
      },
      {
        id: 'A.5',
        name: 'A.5 Assessing impacts of AI systems',
        controls: [
          { id: 'A.5.2', title: 'AI system impact assessment process' },
          { id: 'A.5.3', title: 'Documentation of AI system impact assessments' },
          { id: 'A.5.4', title: 'Assessing AI system impact on individuals or groups of individuals' },
          { id: 'A.5.5', title: 'Assessing societal impacts of AI systems' }
        ]
      },
      {
        id: 'A.6',
        name: 'A.6 AI system life cycle',
        controls: [
          { id: 'A.6.1.2', title: 'Objectives for responsible development of AI systems' },
          { id: 'A.6.1.3', title: 'Processes for responsible AI system design and development' },
          { id: 'A.6.2.2', title: 'AI system requirements and specification' },
          { id: 'A.6.2.3', title: 'Documentation of AI system design and development' },
          { id: 'A.6.2.4', title: 'AI system verification and validation' },
          { id: 'A.6.2.5', title: 'AI system deployment' },
          { id: 'A.6.2.6', title: 'AI system operation and monitoring' },
          { id: 'A.6.2.7', title: 'AI system technical documentation' },
          { id: 'A.6.2.8', title: 'AI system recording of event logs' }
        ]
      },
      {
        id: 'A.7',
        name: 'A.7 Data for AI systems',
        controls: [
          { id: 'A.7.2', title: 'Data for development and enhancement of AI system' },
          { id: 'A.7.3', title: 'Acquisition of data' },
          { id: 'A.7.4', title: 'Quality of data for AI systems' },
          { id: 'A.7.5', title: 'Data provenance' },
          { id: 'A.7.6', title: 'Data preparation' }
        ]
      },
      {
        id: 'A.8',
        name: 'A.8 Information for interested parties of AI systems',
        controls: [
          { id: 'A.8.2', title: 'System documentation and information for users' },
          { id: 'A.8.3', title: 'External reporting' },
          { id: 'A.8.4', title: 'Communication of incidents' },
          { id: 'A.8.5', title: 'Information for interested parties' }
        ]
      },
      {
        id: 'A.9',
        name: 'A.9 Use of AI systems',
        controls: [
          { id: 'A.9.2', title: 'Processes for responsible use of AI systems' },
          { id: 'A.9.3', title: 'Objectives for responsible use of AI systems' },
          { id: 'A.9.4', title: 'Intended use of the AI system' }
        ]
      },
      {
        id: 'A.10',
        name: 'A.10 Third-party and customer relationships',
        controls: [
          { id: 'A.10.2', title: 'Allocating responsibilities' },
          { id: 'A.10.3', title: 'Suppliers' },
          { id: 'A.10.4', title: 'Customers' }
        ]
      }
    ]
  },

  cis: {
    total: 18,
    note: 'CIS Critical Security Controls v8',
    families: [
      {
        id: 'cisv8',
        name: 'CIS Controls v8',
        controls: [
          { id: 'CIS-1', title: 'Inventory and Control of Enterprise Assets' },
          { id: 'CIS-2', title: 'Inventory and Control of Software Assets' },
          { id: 'CIS-3', title: 'Data Protection' },
          { id: 'CIS-4', title: 'Secure Configuration of Enterprise Assets and Software' },
          { id: 'CIS-5', title: 'Account Management' },
          { id: 'CIS-6', title: 'Access Control Management' },
          { id: 'CIS-7', title: 'Continuous Vulnerability Management' },
          { id: 'CIS-8', title: 'Audit Log Management' },
          { id: 'CIS-9', title: 'Email and Web Browser Protections' },
          { id: 'CIS-10', title: 'Malware Defenses' },
          { id: 'CIS-11', title: 'Data Recovery' },
          { id: 'CIS-12', title: 'Network Infrastructure Management' },
          { id: 'CIS-13', title: 'Network Monitoring and Defense' },
          { id: 'CIS-14', title: 'Security Awareness and Skills Training' },
          { id: 'CIS-15', title: 'Service Provider Management' },
          { id: 'CIS-16', title: 'Application Software Security' },
          { id: 'CIS-17', title: 'Incident Response Management' },
          { id: 'CIS-18', title: 'Penetration Testing' }
        ]
      }
    ]
  },

  pci: {
    total: 12,
    note: 'PCI DSS v4.0',
    families: [
      {
        id: 'pcidss',
        name: 'PCI DSS v4.0 Requirements',
        controls: [
          { id: 'Req-1', title: 'Install and Maintain Network Security Controls' },
          { id: 'Req-2', title: 'Apply Secure Configurations to All System Components' },
          { id: 'Req-3', title: 'Protect Stored Account Data' },
          { id: 'Req-4', title: 'Protect Cardholder Data with Strong Cryptography During Transmission Over Open, Public Networks' },
          { id: 'Req-5', title: 'Protect All Systems and Networks from Malicious Software' },
          { id: 'Req-6', title: 'Develop and Maintain Secure Systems and Software' },
          { id: 'Req-7', title: 'Restrict Access to System Components and Cardholder Data by Business Need to Know' },
          { id: 'Req-8', title: 'Identify Users and Authenticate Access to System Components' },
          { id: 'Req-9', title: 'Restrict Physical Access to Cardholder Data' },
          { id: 'Req-10', title: 'Log and Monitor All Access to System Components and Cardholder Data' },
          { id: 'Req-11', title: 'Test Security of Systems and Networks Regularly' },
          { id: 'Req-12', title: 'Support Information Security with Organizational Policies and Programs' }
        ]
      }
    ]
  },

  gdpr: {
    total: 14,
    note: 'GDPR security and data protection relevant articles',
    families: [
      {
        id: 'principles',
        name: 'Principles and Accountability',
        controls: [
          { id: 'Art.5', title: 'Principles relating to processing of personal data' },
          { id: 'Art.25', title: 'Data protection by design and by default' },
          { id: 'Art.30', title: 'Records of processing activities' },
          { id: 'Art.35', title: 'Data protection impact assessment' }
        ]
      },
      {
        id: 'security',
        name: 'Security of Processing',
        controls: [
          { id: 'Art.32', title: 'Security of processing' },
          { id: 'Art.32(1)(a)', title: 'Pseudonymisation and encryption of personal data' },
          { id: 'Art.32(1)(b)', title: 'Ensure ongoing confidentiality, integrity, availability and resilience of processing systems and services' },
          { id: 'Art.32(1)(c)', title: 'Ability to restore availability and access to personal data in a timely manner after an incident' },
          { id: 'Art.32(1)(d)', title: 'Process for regularly testing, assessing and evaluating the effectiveness of security measures' }
        ]
      },
      {
        id: 'breach',
        name: 'Personal Data Breach',
        controls: [
          { id: 'Art.33', title: 'Notification of a personal data breach to the supervisory authority' },
          { id: 'Art.34', title: 'Communication of a personal data breach to the data subject' }
        ]
      },
      {
        id: 'transfers',
        name: 'Related Obligations',
        controls: [
          { id: 'Art.28', title: 'Processor obligations and processing agreements' },
          { id: 'Art.44', title: 'General principle for transfers of personal data' },
          { id: 'Art.17', title: 'Right to erasure (right to be forgotten)' }
        ]
      }
    ]
  },

  slsa: {
    total: 12,
    note: 'SLSA v1.0 Build track',
    families: [
      {
        id: 'buildlevels',
        name: 'Build Track Levels',
        controls: [
          { id: 'Build-L0', title: 'Build L0: No guarantees' },
          { id: 'Build-L1', title: 'Build L1: Provenance exists (build process documented and provenance generated)' },
          { id: 'Build-L2', title: 'Build L2: Hosted build platform with signed provenance' },
          { id: 'Build-L3', title: 'Build L3: Hardened builds with non-falsifiable provenance' }
        ]
      },
      {
        id: 'producer',
        name: 'Producer Requirements',
        controls: [
          { id: 'Producer-Build', title: 'Follow a consistent build process' },
          { id: 'Producer-Provenance', title: 'Distribute provenance to consumers' }
        ]
      },
      {
        id: 'platform',
        name: 'Build Platform Requirements',
        controls: [
          { id: 'Platform-Provenance-Gen', title: 'Provenance generation: complete and authentic provenance' },
          { id: 'Platform-Isolation', title: 'Isolation strength: builds run in isolated environments' },
          { id: 'Platform-Hosted', title: 'Hosted: build runs on a hosted, multi-tenant build service' },
          { id: 'Platform-Signing', title: 'Provenance is unforgeable (signed by the build platform)' }
        ]
      },
      {
        id: 'consumer',
        name: 'Consumer Requirements',
        controls: [
          { id: 'Consumer-Verify', title: 'Verify provenance against expectations' },
          { id: 'Consumer-Policy', title: 'Establish and enforce expectations/policy for artifacts' }
        ]
      }
    ]
  },

  stig: {
    total: 13,
    note: 'Representative subset of the ASD STIG (~280 requirements).',
    families: [
      {
        id: 'authn',
        name: 'Authentication',
        controls: [
          { id: 'APSC-DV-001980', title: 'The application must uniquely identify and authenticate non-organizational users' },
          { id: 'APSC-DV-001950', title: 'The application must implement multifactor authentication for network access to privileged accounts' }
        ]
      },
      {
        id: 'access',
        name: 'Access Control',
        controls: [
          { id: 'APSC-DV-000460', title: 'The application must enforce approved authorizations for logical access to information and system resources' },
          { id: 'APSC-DV-000470', title: 'The application must enforce organization-defined discretionary access control policies' }
        ]
      },
      {
        id: 'session',
        name: 'Session Management',
        controls: [
          { id: 'APSC-DV-002250', title: 'The application must use the Federal Information Processing Standard (FIPS) 140-2 validated cryptographic modules and random number generator if the application implements encryption, key exchange, digital signature, and hash functionality' },
          { id: 'APSC-DV-002230', title: 'The application must invalidate session identifiers upon user logout or other session termination' }
        ]
      },
      {
        id: 'crypto',
        name: 'Cryptography',
        controls: [
          { id: 'APSC-DV-001740', title: 'The application must protect the confidentiality and integrity of transmitted information' }
        ]
      },
      {
        id: 'input',
        name: 'Input Validation',
        controls: [
          { id: 'APSC-DV-002480', title: 'The application must validate all input' },
          { id: 'APSC-DV-002560', title: 'The application must protect from command injection' }
        ]
      },
      {
        id: 'logging',
        name: 'Error Handling and Logging',
        controls: [
          { id: 'APSC-DV-000310', title: 'The application must produce audit records containing information to establish what type of event occurred' },
          { id: 'APSC-DV-001310', title: 'The application must not be vulnerable to overflow attacks' }
        ]
      },
      {
        id: 'config',
        name: 'Configuration Management',
        controls: [
          { id: 'APSC-DV-003110', title: 'The application development team must follow a set of coding standards' }
        ]
      },
      {
        id: 'codeanalysis',
        name: 'Code Analysis and Vulnerability Management',
        controls: [
          { id: 'APSC-DV-003215', title: 'An application code review must be performed on the application' }
        ]
      }
    ]
  }

});
