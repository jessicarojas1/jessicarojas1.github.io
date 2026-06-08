/* CITADEL — Federal / compliance control catalog (pure data) */
window.CITADEL = window.CITADEL || {};
window.CITADEL.controlCatalog = Object.assign(window.CITADEL.controlCatalog || {}, {

  /* ============================================================
   * NIST SP 800-171 Rev 2 — 110 controls across 14 families
   * ============================================================ */
  nist171: {
    total: 110,
    families: [
      { id: '3.1', name: 'Access Control', controls: [
        { id: '3.1.1', title: 'Limit system access to authorized users, processes, and devices' },
        { id: '3.1.2', title: 'Limit system access to the types of transactions and functions authorized users may execute' },
        { id: '3.1.3', title: 'Control the flow of CUI in accordance with approved authorizations' },
        { id: '3.1.4', title: 'Separate the duties of individuals to reduce risk of malevolent activity' },
        { id: '3.1.5', title: 'Employ the principle of least privilege' },
        { id: '3.1.6', title: 'Use non-privileged accounts when accessing non-security functions' },
        { id: '3.1.7', title: 'Prevent non-privileged users from executing privileged functions; capture in audit logs' },
        { id: '3.1.8', title: 'Limit unsuccessful logon attempts' },
        { id: '3.1.9', title: 'Provide privacy and security notices consistent with applicable CUI rules' },
        { id: '3.1.10', title: 'Use session lock with pattern-hiding displays after inactivity' },
        { id: '3.1.11', title: 'Terminate (automatically) a user session after a defined condition' },
        { id: '3.1.12', title: 'Monitor and control remote access sessions' },
        { id: '3.1.13', title: 'Employ cryptographic mechanisms to protect confidentiality of remote access sessions' },
        { id: '3.1.14', title: 'Route remote access via managed access control points' },
        { id: '3.1.15', title: 'Authorize remote execution of privileged commands and remote access to security-relevant information' },
        { id: '3.1.16', title: 'Authorize wireless access prior to allowing such connections' },
        { id: '3.1.17', title: 'Protect wireless access using authentication and encryption' },
        { id: '3.1.18', title: 'Control connection of mobile devices' },
        { id: '3.1.19', title: 'Encrypt CUI on mobile devices and mobile computing platforms' },
        { id: '3.1.20', title: 'Verify and control/limit connections to and use of external systems' },
        { id: '3.1.21', title: 'Limit use of portable storage devices on external systems' },
        { id: '3.1.22', title: 'Control CUI posted or processed on publicly accessible systems' }
      ]},
      { id: '3.2', name: 'Awareness and Training', controls: [
        { id: '3.2.1', title: 'Ensure managers, administrators, and users are aware of security risks' },
        { id: '3.2.2', title: 'Ensure personnel are trained to carry out assigned security-related duties' },
        { id: '3.2.3', title: 'Provide security awareness training on recognizing and reporting insider threats' }
      ]},
      { id: '3.3', name: 'Audit and Accountability', controls: [
        { id: '3.3.1', title: 'Create and retain system audit logs and records' },
        { id: '3.3.2', title: 'Ensure actions of individual users can be uniquely traced (accountability)' },
        { id: '3.3.3', title: 'Review and update logged events' },
        { id: '3.3.4', title: 'Alert in the event of an audit logging process failure' },
        { id: '3.3.5', title: 'Correlate audit record review, analysis, and reporting processes' },
        { id: '3.3.6', title: 'Provide audit record reduction and report generation' },
        { id: '3.3.7', title: 'Provide a system capability that synchronizes to an authoritative time source' },
        { id: '3.3.8', title: 'Protect audit information and audit logging tools from unauthorized access' },
        { id: '3.3.9', title: 'Limit management of audit logging functionality to a privileged subset of users' }
      ]},
      { id: '3.4', name: 'Configuration Management', controls: [
        { id: '3.4.1', title: 'Establish and maintain baseline configurations and inventories' },
        { id: '3.4.2', title: 'Establish and enforce security configuration settings' },
        { id: '3.4.3', title: 'Track, review, approve/disapprove, and log changes to systems' },
        { id: '3.4.4', title: 'Analyze the security impact of changes prior to implementation' },
        { id: '3.4.5', title: 'Define, document, approve, and enforce physical/logical access restrictions for changes' },
        { id: '3.4.6', title: 'Employ the principle of least functionality' },
        { id: '3.4.7', title: 'Restrict, disable, or prevent the use of nonessential programs, ports, and services' },
        { id: '3.4.8', title: 'Apply deny-by-exception (blacklist) or permit-by-exception (whitelist) policy' },
        { id: '3.4.9', title: 'Control and monitor user-installed software' }
      ]},
      { id: '3.5', name: 'Identification and Authentication', controls: [
        { id: '3.5.1', title: 'Identify system users, processes acting on behalf of users, and devices' },
        { id: '3.5.2', title: 'Authenticate identities of users, processes, or devices' },
        { id: '3.5.3', title: 'Use multifactor authentication for local and network access to privileged and non-privileged accounts' },
        { id: '3.5.4', title: 'Employ replay-resistant authentication mechanisms for network access' },
        { id: '3.5.5', title: 'Prevent reuse of identifiers for a defined period' },
        { id: '3.5.6', title: 'Disable identifiers after a defined period of inactivity' },
        { id: '3.5.7', title: 'Enforce a minimum password complexity and change of characters' },
        { id: '3.5.8', title: 'Prohibit password reuse for a specified number of generations' },
        { id: '3.5.9', title: 'Allow temporary password use for system logons with immediate change to a permanent password' },
        { id: '3.5.10', title: 'Store and transmit only cryptographically-protected passwords' },
        { id: '3.5.11', title: 'Obscure feedback of authentication information' }
      ]},
      { id: '3.6', name: 'Incident Response', controls: [
        { id: '3.6.1', title: 'Establish an operational incident-handling capability' },
        { id: '3.6.2', title: 'Track, document, and report incidents to designated officials/authorities' },
        { id: '3.6.3', title: 'Test the organizational incident response capability' }
      ]},
      { id: '3.7', name: 'Maintenance', controls: [
        { id: '3.7.1', title: 'Perform maintenance on organizational systems' },
        { id: '3.7.2', title: 'Provide controls on tools, techniques, mechanisms, and personnel used for maintenance' },
        { id: '3.7.3', title: 'Sanitize equipment removed for off-site maintenance of any CUI' },
        { id: '3.7.4', title: 'Check media containing diagnostic and test programs for malicious code' },
        { id: '3.7.5', title: 'Require MFA to establish nonlocal maintenance sessions and terminate connections when complete' },
        { id: '3.7.6', title: 'Supervise maintenance activities of personnel without required access authorization' }
      ]},
      { id: '3.8', name: 'Media Protection', controls: [
        { id: '3.8.1', title: 'Protect system media containing CUI, both paper and digital' },
        { id: '3.8.2', title: 'Limit access to CUI on system media to authorized users' },
        { id: '3.8.3', title: 'Sanitize or destroy system media containing CUI before disposal or reuse' },
        { id: '3.8.4', title: 'Mark media with necessary CUI markings and distribution limitations' },
        { id: '3.8.5', title: 'Control access to media and maintain accountability during transport' },
        { id: '3.8.6', title: 'Implement cryptographic mechanisms to protect CUI on digital media during transport' },
        { id: '3.8.7', title: 'Control the use of removable media on system components' },
        { id: '3.8.8', title: 'Prohibit the use of portable storage devices with no identifiable owner' },
        { id: '3.8.9', title: 'Protect the confidentiality of backup CUI at storage locations' }
      ]},
      { id: '3.9', name: 'Personnel Security', controls: [
        { id: '3.9.1', title: 'Screen individuals prior to authorizing access to systems containing CUI' },
        { id: '3.9.2', title: 'Ensure CUI and systems are protected during and after personnel actions (terminations/transfers)' }
      ]},
      { id: '3.10', name: 'Physical Protection', controls: [
        { id: '3.10.1', title: 'Limit physical access to systems, equipment, and operating environments to authorized individuals' },
        { id: '3.10.2', title: 'Protect and monitor the physical facility and support infrastructure' },
        { id: '3.10.3', title: 'Escort visitors and monitor visitor activity' },
        { id: '3.10.4', title: 'Maintain audit logs of physical access' },
        { id: '3.10.5', title: 'Control and manage physical access devices' },
        { id: '3.10.6', title: 'Enforce safeguarding measures for CUI at alternate work sites' }
      ]},
      { id: '3.11', name: 'Risk Assessment', controls: [
        { id: '3.11.1', title: 'Periodically assess risk to operations, assets, and individuals from operating systems with CUI' },
        { id: '3.11.2', title: 'Scan for vulnerabilities in systems and applications periodically and when new ones are identified' },
        { id: '3.11.3', title: 'Remediate vulnerabilities in accordance with risk assessments' }
      ]},
      { id: '3.12', name: 'Security Assessment', controls: [
        { id: '3.12.1', title: 'Periodically assess the security controls to determine effectiveness' },
        { id: '3.12.2', title: 'Develop and implement plans of action to correct deficiencies and reduce vulnerabilities' },
        { id: '3.12.3', title: 'Monitor security controls on an ongoing basis to ensure continued effectiveness' },
        { id: '3.12.4', title: 'Develop, document, and periodically update system security plans' }
      ]},
      { id: '3.13', name: 'System and Communications Protection', controls: [
        { id: '3.13.1', title: 'Monitor, control, and protect communications at external and key internal boundaries' },
        { id: '3.13.2', title: 'Employ architectural designs, software development techniques, and systems engineering principles' },
        { id: '3.13.3', title: 'Separate user functionality from system management functionality' },
        { id: '3.13.4', title: 'Prevent unauthorized and unintended information transfer via shared system resources' },
        { id: '3.13.5', title: 'Implement subnetworks for publicly accessible system components (separate from internal networks)' },
        { id: '3.13.6', title: 'Deny network communications traffic by default and allow by exception' },
        { id: '3.13.7', title: 'Prevent remote devices from simultaneously connecting and communicating with external networks (split tunneling)' },
        { id: '3.13.8', title: 'Implement cryptographic mechanisms to prevent unauthorized disclosure of CUI during transmission' },
        { id: '3.13.9', title: 'Terminate network connections associated with communications sessions at the end of the session or inactivity' },
        { id: '3.13.10', title: 'Establish and manage cryptographic keys for cryptography employed in systems' },
        { id: '3.13.11', title: 'Employ FIPS-validated cryptography to protect the confidentiality of CUI' },
        { id: '3.13.12', title: 'Prohibit remote activation of collaborative computing devices and provide indication of use' },
        { id: '3.13.13', title: 'Control and monitor the use of mobile code' },
        { id: '3.13.14', title: 'Control and monitor the use of Voice over Internet Protocol (VoIP) technologies' },
        { id: '3.13.15', title: 'Protect the authenticity of communications sessions' },
        { id: '3.13.16', title: 'Protect the confidentiality of CUI at rest' }
      ]},
      { id: '3.14', name: 'System and Information Integrity', controls: [
        { id: '3.14.1', title: 'Identify, report, and correct system flaws in a timely manner' },
        { id: '3.14.2', title: 'Provide protection from malicious code at designated locations within systems' },
        { id: '3.14.3', title: 'Monitor system security alerts and advisories and take action in response' },
        { id: '3.14.4', title: 'Update malicious code protection mechanisms when new releases are available' },
        { id: '3.14.5', title: 'Perform periodic scans of systems and real-time scans of files from external sources' },
        { id: '3.14.6', title: 'Monitor systems to detect attacks and indicators of potential attacks' },
        { id: '3.14.7', title: 'Identify unauthorized use of organizational systems' }
      ]}
    ]
  },

  /* ============================================================
   * CMMC 2.0 — Level 1 (17) + Level 2 (110) = 127 practices
   * ============================================================ */
  cmmc: {
    total: 127,
    families: [
      /* ---- LEVEL 1 (17 practices) ---- */
      { id: 'AC-L1', name: 'Access Control (Level 1)', controls: [
        { id: 'AC.L1-3.1.1', title: 'Limit system access to authorized users, processes, and devices' },
        { id: 'AC.L1-3.1.2', title: 'Limit system access to the types of transactions and functions authorized users may execute' },
        { id: 'AC.L1-3.1.20', title: 'Verify and control/limit connections to and use of external systems' },
        { id: 'AC.L1-3.1.22', title: 'Control CUI posted or processed on publicly accessible systems' }
      ]},
      { id: 'IA-L1', name: 'Identification and Authentication (Level 1)', controls: [
        { id: 'IA.L1-3.5.1', title: 'Identify system users, processes, and devices' },
        { id: 'IA.L1-3.5.2', title: 'Authenticate identities of users, processes, or devices' }
      ]},
      { id: 'MP-L1', name: 'Media Protection (Level 1)', controls: [
        { id: 'MP.L1-3.8.3', title: 'Sanitize or destroy system media containing FCI before disposal or reuse' }
      ]},
      { id: 'PE-L1', name: 'Physical Protection (Level 1)', controls: [
        { id: 'PE.L1-3.10.1', title: 'Limit physical access to systems, equipment, and operating environments' },
        { id: 'PE.L1-3.10.3', title: 'Escort visitors and monitor visitor activity' },
        { id: 'PE.L1-3.10.4', title: 'Maintain audit logs of physical access' },
        { id: 'PE.L1-3.10.5', title: 'Control and manage physical access devices' }
      ]},
      { id: 'SC-L1', name: 'System and Communications Protection (Level 1)', controls: [
        { id: 'SC.L1-3.13.1', title: 'Monitor, control, and protect communications at external and key internal boundaries' },
        { id: 'SC.L1-3.13.5', title: 'Implement subnetworks for publicly accessible system components' }
      ]},
      { id: 'SI-L1', name: 'System and Information Integrity (Level 1)', controls: [
        { id: 'SI.L1-3.14.1', title: 'Identify, report, and correct system flaws in a timely manner' },
        { id: 'SI.L1-3.14.2', title: 'Provide protection from malicious code at designated locations' },
        { id: 'SI.L1-3.14.4', title: 'Update malicious code protection mechanisms when new releases are available' },
        { id: 'SI.L1-3.14.5', title: 'Perform periodic scans and real-time scans of files from external sources' }
      ]},

      /* ---- LEVEL 2 (110 practices = 800-171 Rev 2) ---- */
      { id: 'AC-L2', name: 'Access Control (Level 2)', controls: [
        { id: 'AC.L2-3.1.1', title: 'Limit system access to authorized users, processes, and devices' },
        { id: 'AC.L2-3.1.2', title: 'Limit system access to the types of transactions and functions authorized users may execute' },
        { id: 'AC.L2-3.1.3', title: 'Control the flow of CUI in accordance with approved authorizations' },
        { id: 'AC.L2-3.1.4', title: 'Separate the duties of individuals to reduce risk of malevolent activity' },
        { id: 'AC.L2-3.1.5', title: 'Employ the principle of least privilege' },
        { id: 'AC.L2-3.1.6', title: 'Use non-privileged accounts when accessing non-security functions' },
        { id: 'AC.L2-3.1.7', title: 'Prevent non-privileged users from executing privileged functions' },
        { id: 'AC.L2-3.1.8', title: 'Limit unsuccessful logon attempts' },
        { id: 'AC.L2-3.1.9', title: 'Provide privacy and security notices consistent with CUI rules' },
        { id: 'AC.L2-3.1.10', title: 'Use session lock with pattern-hiding displays' },
        { id: 'AC.L2-3.1.11', title: 'Terminate user sessions after a defined condition' },
        { id: 'AC.L2-3.1.12', title: 'Monitor and control remote access sessions' },
        { id: 'AC.L2-3.1.13', title: 'Employ cryptographic mechanisms to protect remote access sessions' },
        { id: 'AC.L2-3.1.14', title: 'Route remote access via managed access control points' },
        { id: 'AC.L2-3.1.15', title: 'Authorize remote execution of privileged commands' },
        { id: 'AC.L2-3.1.16', title: 'Authorize wireless access prior to allowing connections' },
        { id: 'AC.L2-3.1.17', title: 'Protect wireless access using authentication and encryption' },
        { id: 'AC.L2-3.1.18', title: 'Control connection of mobile devices' },
        { id: 'AC.L2-3.1.19', title: 'Encrypt CUI on mobile devices and mobile computing platforms' },
        { id: 'AC.L2-3.1.20', title: 'Verify and control/limit connections to and use of external systems' },
        { id: 'AC.L2-3.1.21', title: 'Limit use of portable storage devices on external systems' },
        { id: 'AC.L2-3.1.22', title: 'Control CUI posted or processed on publicly accessible systems' }
      ]},
      { id: 'AT-L2', name: 'Awareness and Training (Level 2)', controls: [
        { id: 'AT.L2-3.2.1', title: 'Ensure personnel are aware of security risks' },
        { id: 'AT.L2-3.2.2', title: 'Ensure personnel are trained to carry out security-related duties' },
        { id: 'AT.L2-3.2.3', title: 'Provide security awareness training on insider threat' }
      ]},
      { id: 'AU-L2', name: 'Audit and Accountability (Level 2)', controls: [
        { id: 'AU.L2-3.3.1', title: 'Create and retain system audit logs and records' },
        { id: 'AU.L2-3.3.2', title: 'Ensure actions of individual users can be uniquely traced' },
        { id: 'AU.L2-3.3.3', title: 'Review and update logged events' },
        { id: 'AU.L2-3.3.4', title: 'Alert in the event of an audit logging process failure' },
        { id: 'AU.L2-3.3.5', title: 'Correlate audit record review, analysis, and reporting' },
        { id: 'AU.L2-3.3.6', title: 'Provide audit record reduction and report generation' },
        { id: 'AU.L2-3.3.7', title: 'Synchronize system clocks to an authoritative time source' },
        { id: 'AU.L2-3.3.8', title: 'Protect audit information and audit logging tools' },
        { id: 'AU.L2-3.3.9', title: 'Limit management of audit logging functionality to privileged users' }
      ]},
      { id: 'CM-L2', name: 'Configuration Management (Level 2)', controls: [
        { id: 'CM.L2-3.4.1', title: 'Establish and maintain baseline configurations and inventories' },
        { id: 'CM.L2-3.4.2', title: 'Establish and enforce security configuration settings' },
        { id: 'CM.L2-3.4.3', title: 'Track, review, approve/disapprove, and log changes' },
        { id: 'CM.L2-3.4.4', title: 'Analyze the security impact of changes prior to implementation' },
        { id: 'CM.L2-3.4.5', title: 'Define and enforce access restrictions for changes' },
        { id: 'CM.L2-3.4.6', title: 'Employ the principle of least functionality' },
        { id: 'CM.L2-3.4.7', title: 'Restrict, disable, or prevent nonessential programs, ports, and services' },
        { id: 'CM.L2-3.4.8', title: 'Apply deny-by-exception or permit-by-exception policy for software' },
        { id: 'CM.L2-3.4.9', title: 'Control and monitor user-installed software' }
      ]},
      { id: 'IA-L2', name: 'Identification and Authentication (Level 2)', controls: [
        { id: 'IA.L2-3.5.1', title: 'Identify system users, processes, and devices' },
        { id: 'IA.L2-3.5.2', title: 'Authenticate identities of users, processes, or devices' },
        { id: 'IA.L2-3.5.3', title: 'Use multifactor authentication for privileged and non-privileged accounts' },
        { id: 'IA.L2-3.5.4', title: 'Employ replay-resistant authentication mechanisms' },
        { id: 'IA.L2-3.5.5', title: 'Prevent reuse of identifiers for a defined period' },
        { id: 'IA.L2-3.5.6', title: 'Disable identifiers after a defined period of inactivity' },
        { id: 'IA.L2-3.5.7', title: 'Enforce minimum password complexity and change of characters' },
        { id: 'IA.L2-3.5.8', title: 'Prohibit password reuse for a specified number of generations' },
        { id: 'IA.L2-3.5.9', title: 'Allow temporary password use with immediate change to a permanent password' },
        { id: 'IA.L2-3.5.10', title: 'Store and transmit only cryptographically-protected passwords' },
        { id: 'IA.L2-3.5.11', title: 'Obscure feedback of authentication information' }
      ]},
      { id: 'IR-L2', name: 'Incident Response (Level 2)', controls: [
        { id: 'IR.L2-3.6.1', title: 'Establish an operational incident-handling capability' },
        { id: 'IR.L2-3.6.2', title: 'Track, document, and report incidents to designated officials' },
        { id: 'IR.L2-3.6.3', title: 'Test the organizational incident response capability' }
      ]},
      { id: 'MA-L2', name: 'Maintenance (Level 2)', controls: [
        { id: 'MA.L2-3.7.1', title: 'Perform maintenance on organizational systems' },
        { id: 'MA.L2-3.7.2', title: 'Provide controls on tools, techniques, mechanisms, and personnel for maintenance' },
        { id: 'MA.L2-3.7.3', title: 'Sanitize equipment removed for off-site maintenance' },
        { id: 'MA.L2-3.7.4', title: 'Check media with diagnostic and test programs for malicious code' },
        { id: 'MA.L2-3.7.5', title: 'Require MFA for nonlocal maintenance sessions' },
        { id: 'MA.L2-3.7.6', title: 'Supervise maintenance activities of personnel without required access' }
      ]},
      { id: 'MP-L2', name: 'Media Protection (Level 2)', controls: [
        { id: 'MP.L2-3.8.1', title: 'Protect system media containing CUI (paper and digital)' },
        { id: 'MP.L2-3.8.2', title: 'Limit access to CUI on system media to authorized users' },
        { id: 'MP.L2-3.8.3', title: 'Sanitize or destroy system media containing CUI before disposal or reuse' },
        { id: 'MP.L2-3.8.4', title: 'Mark media with necessary CUI markings and distribution limitations' },
        { id: 'MP.L2-3.8.5', title: 'Control access to media and maintain accountability during transport' },
        { id: 'MP.L2-3.8.6', title: 'Implement cryptographic mechanisms to protect CUI on digital media during transport' },
        { id: 'MP.L2-3.8.7', title: 'Control the use of removable media on system components' },
        { id: 'MP.L2-3.8.8', title: 'Prohibit use of portable storage devices with no identifiable owner' },
        { id: 'MP.L2-3.8.9', title: 'Protect the confidentiality of backup CUI at storage locations' }
      ]},
      { id: 'PS-L2', name: 'Personnel Security (Level 2)', controls: [
        { id: 'PS.L2-3.9.1', title: 'Screen individuals prior to authorizing access to CUI' },
        { id: 'PS.L2-3.9.2', title: 'Protect CUI and systems during and after personnel actions' }
      ]},
      { id: 'PE-L2', name: 'Physical Protection (Level 2)', controls: [
        { id: 'PE.L2-3.10.1', title: 'Limit physical access to systems, equipment, and operating environments' },
        { id: 'PE.L2-3.10.2', title: 'Protect and monitor the physical facility and support infrastructure' },
        { id: 'PE.L2-3.10.3', title: 'Escort visitors and monitor visitor activity' },
        { id: 'PE.L2-3.10.4', title: 'Maintain audit logs of physical access' },
        { id: 'PE.L2-3.10.5', title: 'Control and manage physical access devices' },
        { id: 'PE.L2-3.10.6', title: 'Enforce safeguarding measures for CUI at alternate work sites' }
      ]},
      { id: 'RA-L2', name: 'Risk Assessment (Level 2)', controls: [
        { id: 'RA.L2-3.11.1', title: 'Periodically assess risk from operating systems processing CUI' },
        { id: 'RA.L2-3.11.2', title: 'Scan for vulnerabilities periodically and when new ones are identified' },
        { id: 'RA.L2-3.11.3', title: 'Remediate vulnerabilities in accordance with risk assessments' }
      ]},
      { id: 'CA-L2', name: 'Security Assessment (Level 2)', controls: [
        { id: 'CA.L2-3.12.1', title: 'Periodically assess security controls for effectiveness' },
        { id: 'CA.L2-3.12.2', title: 'Develop and implement plans of action to correct deficiencies' },
        { id: 'CA.L2-3.12.3', title: 'Monitor security controls on an ongoing basis' },
        { id: 'CA.L2-3.12.4', title: 'Develop, document, and periodically update system security plans' }
      ]},
      { id: 'SC-L2', name: 'System and Communications Protection (Level 2)', controls: [
        { id: 'SC.L2-3.13.1', title: 'Monitor, control, and protect communications at external and key internal boundaries' },
        { id: 'SC.L2-3.13.2', title: 'Employ architectural designs and systems engineering principles' },
        { id: 'SC.L2-3.13.3', title: 'Separate user functionality from system management functionality' },
        { id: 'SC.L2-3.13.4', title: 'Prevent unauthorized information transfer via shared system resources' },
        { id: 'SC.L2-3.13.5', title: 'Implement subnetworks for publicly accessible system components' },
        { id: 'SC.L2-3.13.6', title: 'Deny network traffic by default and allow by exception' },
        { id: 'SC.L2-3.13.7', title: 'Prevent split tunneling for remote devices' },
        { id: 'SC.L2-3.13.8', title: 'Implement cryptographic mechanisms to prevent disclosure of CUI in transmission' },
        { id: 'SC.L2-3.13.9', title: 'Terminate network connections at the end of sessions or after inactivity' },
        { id: 'SC.L2-3.13.10', title: 'Establish and manage cryptographic keys' },
        { id: 'SC.L2-3.13.11', title: 'Employ FIPS-validated cryptography to protect CUI' },
        { id: 'SC.L2-3.13.12', title: 'Prohibit remote activation of collaborative computing devices' },
        { id: 'SC.L2-3.13.13', title: 'Control and monitor the use of mobile code' },
        { id: 'SC.L2-3.13.14', title: 'Control and monitor the use of VoIP technologies' },
        { id: 'SC.L2-3.13.15', title: 'Protect the authenticity of communications sessions' },
        { id: 'SC.L2-3.13.16', title: 'Protect the confidentiality of CUI at rest' }
      ]},
      { id: 'SI-L2', name: 'System and Information Integrity (Level 2)', controls: [
        { id: 'SI.L2-3.14.1', title: 'Identify, report, and correct system flaws in a timely manner' },
        { id: 'SI.L2-3.14.2', title: 'Provide protection from malicious code at designated locations' },
        { id: 'SI.L2-3.14.3', title: 'Monitor system security alerts and advisories and take action' },
        { id: 'SI.L2-3.14.4', title: 'Update malicious code protection mechanisms when new releases are available' },
        { id: 'SI.L2-3.14.5', title: 'Perform periodic scans and real-time scans of files from external sources' },
        { id: 'SI.L2-3.14.6', title: 'Monitor systems to detect attacks and indicators of potential attacks' },
        { id: 'SI.L2-3.14.7', title: 'Identify unauthorized use of organizational systems' }
      ]}
    ]
  },

  /* ============================================================
   * NIST SP 800-53 Rev 5 — base controls, 20 families
   * ============================================================ */
  nist53: {
    total: 298,
    note: 'Base controls (Rev 5); ~1000+ including enhancements.',
    families: [
      { id: 'AC', name: 'Access Control', controls: [
        { id: 'AC-1', title: 'Policy and Procedures' },
        { id: 'AC-2', title: 'Account Management' },
        { id: 'AC-3', title: 'Access Enforcement' },
        { id: 'AC-4', title: 'Information Flow Enforcement' },
        { id: 'AC-5', title: 'Separation of Duties' },
        { id: 'AC-6', title: 'Least Privilege' },
        { id: 'AC-7', title: 'Unsuccessful Logon Attempts' },
        { id: 'AC-8', title: 'System Use Notification' },
        { id: 'AC-9', title: 'Previous Logon Notification' },
        { id: 'AC-10', title: 'Concurrent Session Control' },
        { id: 'AC-11', title: 'Device Lock' },
        { id: 'AC-12', title: 'Session Termination' },
        { id: 'AC-14', title: 'Permitted Actions Without Identification or Authentication' },
        { id: 'AC-16', title: 'Security and Privacy Attributes' },
        { id: 'AC-17', title: 'Remote Access' },
        { id: 'AC-18', title: 'Wireless Access' },
        { id: 'AC-19', title: 'Access Control for Mobile Devices' },
        { id: 'AC-20', title: 'Use of External Systems' },
        { id: 'AC-21', title: 'Information Sharing' },
        { id: 'AC-22', title: 'Publicly Accessible Content' },
        { id: 'AC-23', title: 'Data Mining Protection' },
        { id: 'AC-24', title: 'Access Control Decisions' },
        { id: 'AC-25', title: 'Reference Monitor' }
      ]},
      { id: 'AT', name: 'Awareness and Training', controls: [
        { id: 'AT-1', title: 'Policy and Procedures' },
        { id: 'AT-2', title: 'Literacy Training and Awareness' },
        { id: 'AT-3', title: 'Role-Based Training' },
        { id: 'AT-4', title: 'Training Records' },
        { id: 'AT-6', title: 'Training Feedback' }
      ]},
      { id: 'AU', name: 'Audit and Accountability', controls: [
        { id: 'AU-1', title: 'Policy and Procedures' },
        { id: 'AU-2', title: 'Event Logging' },
        { id: 'AU-3', title: 'Content of Audit Records' },
        { id: 'AU-4', title: 'Audit Log Storage Capacity' },
        { id: 'AU-5', title: 'Response to Audit Logging Process Failures' },
        { id: 'AU-6', title: 'Audit Record Review, Analysis, and Reporting' },
        { id: 'AU-7', title: 'Audit Record Reduction and Report Generation' },
        { id: 'AU-8', title: 'Time Stamps' },
        { id: 'AU-9', title: 'Protection of Audit Information' },
        { id: 'AU-10', title: 'Non-repudiation' },
        { id: 'AU-11', title: 'Audit Record Retention' },
        { id: 'AU-12', title: 'Audit Record Generation' },
        { id: 'AU-13', title: 'Monitoring for Information Disclosure' },
        { id: 'AU-14', title: 'Session Audit' },
        { id: 'AU-16', title: 'Cross-Organizational Audit Logging' }
      ]},
      { id: 'CA', name: 'Assessment, Authorization, and Monitoring', controls: [
        { id: 'CA-1', title: 'Policy and Procedures' },
        { id: 'CA-2', title: 'Control Assessments' },
        { id: 'CA-3', title: 'Information Exchange' },
        { id: 'CA-5', title: 'Plan of Action and Milestones' },
        { id: 'CA-6', title: 'Authorization' },
        { id: 'CA-7', title: 'Continuous Monitoring' },
        { id: 'CA-8', title: 'Penetration Testing' },
        { id: 'CA-9', title: 'Internal System Connections' }
      ]},
      { id: 'CM', name: 'Configuration Management', controls: [
        { id: 'CM-1', title: 'Policy and Procedures' },
        { id: 'CM-2', title: 'Baseline Configuration' },
        { id: 'CM-3', title: 'Configuration Change Control' },
        { id: 'CM-4', title: 'Impact Analyses' },
        { id: 'CM-5', title: 'Access Restrictions for Change' },
        { id: 'CM-6', title: 'Configuration Settings' },
        { id: 'CM-7', title: 'Least Functionality' },
        { id: 'CM-8', title: 'System Component Inventory' },
        { id: 'CM-9', title: 'Configuration Management Plan' },
        { id: 'CM-10', title: 'Software Usage Restrictions' },
        { id: 'CM-11', title: 'User-Installed Software' },
        { id: 'CM-12', title: 'Information Location' },
        { id: 'CM-13', title: 'Data Action Mapping' },
        { id: 'CM-14', title: 'Signed Components' }
      ]},
      { id: 'CP', name: 'Contingency Planning', controls: [
        { id: 'CP-1', title: 'Policy and Procedures' },
        { id: 'CP-2', title: 'Contingency Plan' },
        { id: 'CP-3', title: 'Contingency Training' },
        { id: 'CP-4', title: 'Contingency Plan Testing' },
        { id: 'CP-6', title: 'Alternate Storage Site' },
        { id: 'CP-7', title: 'Alternate Processing Site' },
        { id: 'CP-8', title: 'Telecommunications Services' },
        { id: 'CP-9', title: 'System Backup' },
        { id: 'CP-10', title: 'System Recovery and Reconstitution' },
        { id: 'CP-11', title: 'Alternate Communications Protocols' },
        { id: 'CP-12', title: 'Safe Mode' },
        { id: 'CP-13', title: 'Alternative Security Mechanisms' }
      ]},
      { id: 'IA', name: 'Identification and Authentication', controls: [
        { id: 'IA-1', title: 'Policy and Procedures' },
        { id: 'IA-2', title: 'Identification and Authentication (Organizational Users)' },
        { id: 'IA-3', title: 'Device Identification and Authentication' },
        { id: 'IA-4', title: 'Identifier Management' },
        { id: 'IA-5', title: 'Authenticator Management' },
        { id: 'IA-6', title: 'Authentication Feedback' },
        { id: 'IA-7', title: 'Cryptographic Module Authentication' },
        { id: 'IA-8', title: 'Identification and Authentication (Non-Organizational Users)' },
        { id: 'IA-9', title: 'Service Identification and Authentication' },
        { id: 'IA-10', title: 'Adaptive Authentication' },
        { id: 'IA-11', title: 'Re-authentication' },
        { id: 'IA-12', title: 'Identity Proofing' }
      ]},
      { id: 'IR', name: 'Incident Response', controls: [
        { id: 'IR-1', title: 'Policy and Procedures' },
        { id: 'IR-2', title: 'Incident Response Training' },
        { id: 'IR-3', title: 'Incident Response Testing' },
        { id: 'IR-4', title: 'Incident Handling' },
        { id: 'IR-5', title: 'Incident Monitoring' },
        { id: 'IR-6', title: 'Incident Reporting' },
        { id: 'IR-7', title: 'Incident Response Assistance' },
        { id: 'IR-8', title: 'Incident Response Plan' },
        { id: 'IR-9', title: 'Information Spillage Response' }
      ]},
      { id: 'MA', name: 'Maintenance', controls: [
        { id: 'MA-1', title: 'Policy and Procedures' },
        { id: 'MA-2', title: 'Controlled Maintenance' },
        { id: 'MA-3', title: 'Maintenance Tools' },
        { id: 'MA-4', title: 'Nonlocal Maintenance' },
        { id: 'MA-5', title: 'Maintenance Personnel' },
        { id: 'MA-6', title: 'Timely Maintenance' },
        { id: 'MA-7', title: 'Field Maintenance' }
      ]},
      { id: 'MP', name: 'Media Protection', controls: [
        { id: 'MP-1', title: 'Policy and Procedures' },
        { id: 'MP-2', title: 'Media Access' },
        { id: 'MP-3', title: 'Media Marking' },
        { id: 'MP-4', title: 'Media Storage' },
        { id: 'MP-5', title: 'Media Transport' },
        { id: 'MP-6', title: 'Media Sanitization' },
        { id: 'MP-7', title: 'Media Use' },
        { id: 'MP-8', title: 'Media Downgrading' }
      ]},
      { id: 'PE', name: 'Physical and Environmental Protection', controls: [
        { id: 'PE-1', title: 'Policy and Procedures' },
        { id: 'PE-2', title: 'Physical Access Authorizations' },
        { id: 'PE-3', title: 'Physical Access Control' },
        { id: 'PE-4', title: 'Access Control for Transmission' },
        { id: 'PE-5', title: 'Access Control for Output Devices' },
        { id: 'PE-6', title: 'Monitoring Physical Access' },
        { id: 'PE-8', title: 'Visitor Access Records' },
        { id: 'PE-9', title: 'Power Equipment and Cabling' },
        { id: 'PE-10', title: 'Emergency Shutoff' },
        { id: 'PE-11', title: 'Emergency Power' },
        { id: 'PE-12', title: 'Emergency Lighting' },
        { id: 'PE-13', title: 'Fire Protection' },
        { id: 'PE-14', title: 'Environmental Controls' },
        { id: 'PE-15', title: 'Water Damage Protection' },
        { id: 'PE-16', title: 'Delivery and Removal' },
        { id: 'PE-17', title: 'Alternate Work Site' },
        { id: 'PE-18', title: 'Location of System Components' },
        { id: 'PE-19', title: 'Information Leakage' },
        { id: 'PE-20', title: 'Asset Monitoring and Tracking' },
        { id: 'PE-21', title: 'Electromagnetic Pulse Protection' },
        { id: 'PE-22', title: 'Component Marking' },
        { id: 'PE-23', title: 'Facility Location' }
      ]},
      { id: 'PL', name: 'Planning', controls: [
        { id: 'PL-1', title: 'Policy and Procedures' },
        { id: 'PL-2', title: 'System Security and Privacy Plans' },
        { id: 'PL-4', title: 'Rules of Behavior' },
        { id: 'PL-7', title: 'Concept of Operations' },
        { id: 'PL-8', title: 'Security and Privacy Architectures' },
        { id: 'PL-9', title: 'Central Management' },
        { id: 'PL-10', title: 'Baseline Selection' },
        { id: 'PL-11', title: 'Baseline Tailoring' }
      ]},
      { id: 'PM', name: 'Program Management', controls: [
        { id: 'PM-1', title: 'Information Security Program Plan' },
        { id: 'PM-2', title: 'Information Security Program Leadership Role' },
        { id: 'PM-3', title: 'Information Security and Privacy Resources' },
        { id: 'PM-4', title: 'Plan of Action and Milestones Process' },
        { id: 'PM-5', title: 'System Inventory' },
        { id: 'PM-6', title: 'Measures of Performance' },
        { id: 'PM-7', title: 'Enterprise Architecture' },
        { id: 'PM-8', title: 'Critical Infrastructure Plan' },
        { id: 'PM-9', title: 'Risk Management Strategy' },
        { id: 'PM-10', title: 'Authorization Process' },
        { id: 'PM-11', title: 'Mission and Business Process Definition' },
        { id: 'PM-12', title: 'Insider Threat Program' },
        { id: 'PM-13', title: 'Security and Privacy Workforce' },
        { id: 'PM-14', title: 'Testing, Training, and Monitoring' },
        { id: 'PM-15', title: 'Security and Privacy Groups and Associations' },
        { id: 'PM-16', title: 'Threat Awareness Program' },
        { id: 'PM-17', title: 'Protecting CUI on External Systems' },
        { id: 'PM-18', title: 'Privacy Program Plan' },
        { id: 'PM-19', title: 'Privacy Program Leadership Role' },
        { id: 'PM-20', title: 'Dissemination of Privacy Program Information' },
        { id: 'PM-21', title: 'Accounting of Disclosures' },
        { id: 'PM-22', title: 'Personally Identifiable Information Quality Management' },
        { id: 'PM-23', title: 'Data Governance Body' },
        { id: 'PM-24', title: 'Data Integrity Board' },
        { id: 'PM-25', title: 'Minimization of PII Used in Testing, Training, and Research' },
        { id: 'PM-26', title: 'Complaint Management' },
        { id: 'PM-27', title: 'Privacy Reporting' },
        { id: 'PM-28', title: 'Risk Framing' },
        { id: 'PM-29', title: 'Risk Management Program Leadership Roles' },
        { id: 'PM-30', title: 'Supply Chain Risk Management Strategy' },
        { id: 'PM-31', title: 'Continuous Monitoring Strategy' },
        { id: 'PM-32', title: 'Purposing' }
      ]},
      { id: 'PS', name: 'Personnel Security', controls: [
        { id: 'PS-1', title: 'Policy and Procedures' },
        { id: 'PS-2', title: 'Position Risk Designation' },
        { id: 'PS-3', title: 'Personnel Screening' },
        { id: 'PS-4', title: 'Personnel Termination' },
        { id: 'PS-5', title: 'Personnel Transfer' },
        { id: 'PS-6', title: 'Access Agreements' },
        { id: 'PS-7', title: 'External Personnel Security' },
        { id: 'PS-8', title: 'Personnel Sanctions' },
        { id: 'PS-9', title: 'Position Descriptions' }
      ]},
      { id: 'PT', name: 'Personally Identifiable Information Processing and Transparency', controls: [
        { id: 'PT-1', title: 'Policy and Procedures' },
        { id: 'PT-2', title: 'Authority to Process Personally Identifiable Information' },
        { id: 'PT-3', title: 'Personally Identifiable Information Processing Purposes' },
        { id: 'PT-4', title: 'Consent' },
        { id: 'PT-5', title: 'Privacy Notice' },
        { id: 'PT-6', title: 'System of Records Notice' },
        { id: 'PT-7', title: 'Specific Categories of Personally Identifiable Information' },
        { id: 'PT-8', title: 'Computer Matching Requirements' }
      ]},
      { id: 'RA', name: 'Risk Assessment', controls: [
        { id: 'RA-1', title: 'Policy and Procedures' },
        { id: 'RA-2', title: 'Security Categorization' },
        { id: 'RA-3', title: 'Risk Assessment' },
        { id: 'RA-5', title: 'Vulnerability Monitoring and Scanning' },
        { id: 'RA-6', title: 'Technical Surveillance Countermeasures Survey' },
        { id: 'RA-7', title: 'Risk Response' },
        { id: 'RA-8', title: 'Privacy Impact Assessments' },
        { id: 'RA-9', title: 'Criticality Analysis' },
        { id: 'RA-10', title: 'Threat Hunting' }
      ]},
      { id: 'SA', name: 'System and Services Acquisition', controls: [
        { id: 'SA-1', title: 'Policy and Procedures' },
        { id: 'SA-2', title: 'Allocation of Resources' },
        { id: 'SA-3', title: 'System Development Life Cycle' },
        { id: 'SA-4', title: 'Acquisition Process' },
        { id: 'SA-5', title: 'System Documentation' },
        { id: 'SA-8', title: 'Security and Privacy Engineering Principles' },
        { id: 'SA-9', title: 'External System Services' },
        { id: 'SA-10', title: 'Developer Configuration Management' },
        { id: 'SA-11', title: 'Developer Testing and Evaluation' },
        { id: 'SA-15', title: 'Development Process, Standards, and Tools' },
        { id: 'SA-16', title: 'Developer-Provided Training' },
        { id: 'SA-17', title: 'Developer Security and Privacy Architecture and Design' },
        { id: 'SA-20', title: 'Customized Development of Critical Components' },
        { id: 'SA-21', title: 'Developer Screening' },
        { id: 'SA-22', title: 'Unsupported System Components' },
        { id: 'SA-23', title: 'Specialization' }
      ]},
      { id: 'SC', name: 'System and Communications Protection', controls: [
        { id: 'SC-1', title: 'Policy and Procedures' },
        { id: 'SC-2', title: 'Separation of System and User Functionality' },
        { id: 'SC-3', title: 'Security Function Isolation' },
        { id: 'SC-4', title: 'Information in Shared System Resources' },
        { id: 'SC-5', title: 'Denial-of-Service Protection' },
        { id: 'SC-6', title: 'Resource Availability' },
        { id: 'SC-7', title: 'Boundary Protection' },
        { id: 'SC-8', title: 'Transmission Confidentiality and Integrity' },
        { id: 'SC-10', title: 'Network Disconnect' },
        { id: 'SC-11', title: 'Trusted Path' },
        { id: 'SC-12', title: 'Cryptographic Key Establishment and Management' },
        { id: 'SC-13', title: 'Cryptographic Protection' },
        { id: 'SC-15', title: 'Collaborative Computing Devices and Applications' },
        { id: 'SC-16', title: 'Transmission of Security and Privacy Attributes' },
        { id: 'SC-17', title: 'Public Key Infrastructure Certificates' },
        { id: 'SC-18', title: 'Mobile Code' },
        { id: 'SC-20', title: 'Secure Name/Address Resolution Service (Authoritative Source)' },
        { id: 'SC-21', title: 'Secure Name/Address Resolution Service (Recursive or Caching Resolver)' },
        { id: 'SC-22', title: 'Architecture and Provisioning for Name/Address Resolution Service' },
        { id: 'SC-23', title: 'Session Authenticity' },
        { id: 'SC-24', title: 'Fail in Known State' },
        { id: 'SC-25', title: 'Thin Nodes' },
        { id: 'SC-26', title: 'Decoys' },
        { id: 'SC-27', title: 'Platform-Independent Applications' },
        { id: 'SC-28', title: 'Protection of Information at Rest' },
        { id: 'SC-29', title: 'Heterogeneity' },
        { id: 'SC-30', title: 'Concealment and Misdirection' },
        { id: 'SC-31', title: 'Covert Channel Analysis' },
        { id: 'SC-32', title: 'System Partitioning' },
        { id: 'SC-34', title: 'Non-Modifiable Executable Programs' },
        { id: 'SC-35', title: 'External Malicious Code Identification' },
        { id: 'SC-36', title: 'Distributed Processing and Storage' },
        { id: 'SC-37', title: 'Out-of-Band Channels' },
        { id: 'SC-38', title: 'Operations Security' },
        { id: 'SC-39', title: 'Process Isolation' },
        { id: 'SC-40', title: 'Wireless Link Protection' },
        { id: 'SC-41', title: 'Port and I/O Device Access' },
        { id: 'SC-42', title: 'Sensor Capability and Data' },
        { id: 'SC-43', title: 'Usage Restrictions' },
        { id: 'SC-44', title: 'Detonation Chambers' },
        { id: 'SC-45', title: 'System Time Synchronization' },
        { id: 'SC-46', title: 'Cross Domain Policy Enforcement' },
        { id: 'SC-47', title: 'Alternate Communications Paths' },
        { id: 'SC-48', title: 'Sensor Relocation' },
        { id: 'SC-49', title: 'Hardware-Enforced Separation and Policy Enforcement' },
        { id: 'SC-50', title: 'Software-Enforced Separation and Policy Enforcement' },
        { id: 'SC-51', title: 'Hardware-Based Protection' }
      ]},
      { id: 'SI', name: 'System and Information Integrity', controls: [
        { id: 'SI-1', title: 'Policy and Procedures' },
        { id: 'SI-2', title: 'Flaw Remediation' },
        { id: 'SI-3', title: 'Malicious Code Protection' },
        { id: 'SI-4', title: 'System Monitoring' },
        { id: 'SI-5', title: 'Security Alerts, Advisories, and Directives' },
        { id: 'SI-6', title: 'Security and Privacy Function Verification' },
        { id: 'SI-7', title: 'Software, Firmware, and Information Integrity' },
        { id: 'SI-8', title: 'Spam Protection' },
        { id: 'SI-10', title: 'Information Input Validation' },
        { id: 'SI-11', title: 'Error Handling' },
        { id: 'SI-12', title: 'Information Management and Retention' },
        { id: 'SI-13', title: 'Predictable Failure Prevention' },
        { id: 'SI-14', title: 'Non-Persistence' },
        { id: 'SI-15', title: 'Information Output Filtering' },
        { id: 'SI-16', title: 'Memory Protection' },
        { id: 'SI-17', title: 'Fail-Safe Procedures' },
        { id: 'SI-18', title: 'Personally Identifiable Information Quality Operations' },
        { id: 'SI-19', title: 'De-identification' },
        { id: 'SI-20', title: 'Tainting' },
        { id: 'SI-21', title: 'Information Refresh' },
        { id: 'SI-22', title: 'Information Diversity' },
        { id: 'SI-23', title: 'Information Fragmentation' }
      ]},
      { id: 'SR', name: 'Supply Chain Risk Management', controls: [
        { id: 'SR-1', title: 'Policy and Procedures' },
        { id: 'SR-2', title: 'Supply Chain Risk Management Plan' },
        { id: 'SR-3', title: 'Supply Chain Controls and Processes' },
        { id: 'SR-4', title: 'Provenance' },
        { id: 'SR-5', title: 'Acquisition Strategies, Tools, and Methods' },
        { id: 'SR-6', title: 'Supplier Assessments and Reviews' },
        { id: 'SR-7', title: 'Supply Chain Operations Security' },
        { id: 'SR-8', title: 'Notification Agreements' },
        { id: 'SR-9', title: 'Tamper Resistance and Detection' },
        { id: 'SR-10', title: 'Inspection of Systems or Components' },
        { id: 'SR-11', title: 'Component Authenticity' },
        { id: 'SR-12', title: 'Component Disposal' }
      ]}
    ]
  },

  /* ============================================================
   * NIST SP 800-218 — SSDF v1.1
   * ============================================================ */
  ssdf: {
    total: 42,
    families: [
      { id: 'PO', name: 'Prepare the Organization', controls: [
        { id: 'PO.1.1', title: 'Identify and document security requirements for organizational software development infrastructures and processes' },
        { id: 'PO.1.2', title: 'Identify and document security requirements for the organization’s software' },
        { id: 'PO.1.3', title: 'Communicate requirements to third parties who provide commercial software components' },
        { id: 'PO.2.1', title: 'Create new roles and alter responsibilities for personnel in the SDLC' },
        { id: 'PO.2.2', title: 'Provide role-based training; periodically review and update' },
        { id: 'PO.2.3', title: 'Obtain upper management or authorizing official commitment to secure development' },
        { id: 'PO.3.1', title: 'Specify which tools or tool types must or should be included in toolchains' },
        { id: 'PO.3.2', title: 'Follow recommended security practices to deploy, operate, and maintain tools and toolchains' },
        { id: 'PO.3.3', title: 'Configure tools to collect evidence and artifacts of their support of secure practices' },
        { id: 'PO.4.1', title: 'Define criteria for software security checks and track throughout the SDLC' },
        { id: 'PO.4.2', title: 'Implement processes, mechanisms, etc. to gather and safeguard the necessary information' },
        { id: 'PO.5.1', title: 'Separate and protect each environment involved in software development' },
        { id: 'PO.5.2', title: 'Secure and harden development endpoints to perform development-related tasks securely' }
      ]},
      { id: 'PS', name: 'Protect the Software', controls: [
        { id: 'PS.1.1', title: 'Store all forms of code based on the principle of least privilege' },
        { id: 'PS.2.1', title: 'Make software integrity verification information available to software acquirers' },
        { id: 'PS.3.1', title: 'Securely archive the necessary files and data to be retained for each software release' },
        { id: 'PS.3.2', title: 'Collect, safeguard, maintain, and share provenance data for all components of each release' }
      ]},
      { id: 'PW', name: 'Produce Well-Secured Software', controls: [
        { id: 'PW.1.1', title: 'Use forms of risk modeling to help assess the security risk for the software' },
        { id: 'PW.1.2', title: 'Track and maintain the software’s security requirements, risks, and design decisions' },
        { id: 'PW.1.3', title: 'Where appropriate, build in support for using standardized security features and services' },
        { id: 'PW.2.1', title: 'Have a qualified person independently review compliance of the design with security requirements' },
        { id: 'PW.4.1', title: 'Acquire and maintain well-secured software components from commercial, open-source, and other sources' },
        { id: 'PW.4.2', title: 'Create and maintain well-secured software components in-house following SDLC processes' },
        { id: 'PW.4.4', title: 'Verify that acquired components comply with the requirements throughout their life cycles' },
        { id: 'PW.5.1', title: 'Follow all secure coding practices appropriate to the development languages and environment' },
        { id: 'PW.6.1', title: 'Use compiler, interpreter, and build tools that offer features to improve executable security' },
        { id: 'PW.6.2', title: 'Determine which compiler, interpreter, and build tool features should be used and how each should be configured' },
        { id: 'PW.7.1', title: 'Determine whether code review and/or analysis should be performed; document this information' },
        { id: 'PW.7.2', title: 'Perform the code review and/or code analysis based on the organization’s secure coding standards' },
        { id: 'PW.8.1', title: 'Determine whether executable code testing should be performed and the scope of that testing' },
        { id: 'PW.8.2', title: 'Scope, design, execute, and document the necessary testing' },
        { id: 'PW.9.1', title: 'Define a secure baseline by determining how to configure each setting' },
        { id: 'PW.9.2', title: 'Implement the default settings (or groups of default settings, if applicable)' }
      ]},
      { id: 'RV', name: 'Respond to Vulnerabilities', controls: [
        { id: 'RV.1.1', title: 'Gather information from software acquirers, users, and public sources on potential vulnerabilities' },
        { id: 'RV.1.2', title: 'Review, analyze, and/or test the software’s code to identify or confirm undiscovered vulnerabilities' },
        { id: 'RV.1.3', title: 'Have a policy that addresses vulnerability disclosure and remediation; implement roles, responsibilities, processes' },
        { id: 'RV.2.1', title: 'Analyze each vulnerability to gather sufficient information to plan its remediation' },
        { id: 'RV.2.2', title: 'Develop and implement a remediation plan for each vulnerability' },
        { id: 'RV.3.1', title: 'Analyze identified vulnerabilities to determine their root causes' },
        { id: 'RV.3.2', title: 'Analyze the root causes over time to identify patterns, such as a vulnerability recurring' },
        { id: 'RV.3.3', title: 'Review the software for similar vulnerabilities to eradicate a class of vulnerabilities' },
        { id: 'RV.3.4', title: 'Review the SDLC process and update it if appropriate to prevent recurrences' }
      ]}
    ]
  },

  /* ============================================================
   * NIST CSF 2.0 — 6 Functions, all Categories
   * ============================================================ */
  csf: {
    total: 22,
    families: [
      { id: 'GV', name: 'Govern', controls: [
        { id: 'GV.OC', title: 'Organizational Context' },
        { id: 'GV.RM', title: 'Risk Management Strategy' },
        { id: 'GV.RR', title: 'Roles, Responsibilities, and Authorities' },
        { id: 'GV.PO', title: 'Policy' },
        { id: 'GV.OV', title: 'Oversight' },
        { id: 'GV.SC', title: 'Cybersecurity Supply Chain Risk Management' }
      ]},
      { id: 'ID', name: 'Identify', controls: [
        { id: 'ID.AM', title: 'Asset Management' },
        { id: 'ID.RA', title: 'Risk Assessment' },
        { id: 'ID.IM', title: 'Improvement' }
      ]},
      { id: 'PR', name: 'Protect', controls: [
        { id: 'PR.AA', title: 'Identity Management, Authentication, and Access Control' },
        { id: 'PR.AT', title: 'Awareness and Training' },
        { id: 'PR.DS', title: 'Data Security' },
        { id: 'PR.PS', title: 'Platform Security' },
        { id: 'PR.IR', title: 'Technology Infrastructure Resilience' }
      ]},
      { id: 'DE', name: 'Detect', controls: [
        { id: 'DE.CM', title: 'Continuous Monitoring' },
        { id: 'DE.AE', title: 'Adverse Event Analysis' }
      ]},
      { id: 'RS', name: 'Respond', controls: [
        { id: 'RS.MA', title: 'Incident Management' },
        { id: 'RS.AN', title: 'Incident Analysis' },
        { id: 'RS.CO', title: 'Incident Response Reporting and Communication' },
        { id: 'RS.MI', title: 'Incident Mitigation' }
      ]},
      { id: 'RC', name: 'Recover', controls: [
        { id: 'RC.RP', title: 'Incident Recovery Plan Execution' },
        { id: 'RC.CO', title: 'Incident Recovery Communication' }
      ]}
    ]
  },

  /* ============================================================
   * FedRAMP (Rev 5) — tailored 800-53 baselines
   * ============================================================ */
  fedramp: {
    total: 15,
    note: 'FedRAMP baselines are tailored NIST SP 800-53 Rev 5 control sets; Low ~156, Moderate ~323, High ~410 controls. Representative controls listed.',
    families: [
      { id: 'LOW', name: 'Low Baseline (~156 controls)', controls: [
        { id: 'AC-2', title: 'Account Management' },
        { id: 'IA-2', title: 'Identification and Authentication (Organizational Users)' },
        { id: 'AU-2', title: 'Event Logging' },
        { id: 'CP-9', title: 'System Backup' },
        { id: 'SC-7', title: 'Boundary Protection' }
      ]},
      { id: 'MODERATE', name: 'Moderate Baseline (~323 controls)', controls: [
        { id: 'AC-2(1)', title: 'Account Management | Automated System Account Management' },
        { id: 'IA-2(1)', title: 'MFA to Privileged Accounts' },
        { id: 'AU-6', title: 'Audit Record Review, Analysis, and Reporting' },
        { id: 'SC-8', title: 'Transmission Confidentiality and Integrity' },
        { id: 'SI-4', title: 'System Monitoring' }
      ]},
      { id: 'HIGH', name: 'High Baseline (~410 controls)', controls: [
        { id: 'AC-2(12)', title: 'Account Management | Account Monitoring for Atypical Usage' },
        { id: 'IA-2(2)', title: 'MFA to Non-Privileged Accounts' },
        { id: 'CP-7', title: 'Alternate Processing Site' },
        { id: 'SC-7(8)', title: 'Boundary Protection | Route Traffic to Authenticated Proxy Servers' },
        { id: 'CA-8', title: 'Penetration Testing' }
      ]}
    ]
  },

  /* ============================================================
   * DFARS 252.204-7012 — key clause paragraphs
   * ============================================================ */
  dfars: {
    total: 7,
    families: [
      { id: 'CLAUSE', name: 'DFARS 252.204-7012 — Safeguarding Covered Defense Information and Cyber Incident Reporting', controls: [
        { id: '(b)', title: 'Adequate Security — implement NIST SP 800-171 on covered contractor information systems' },
        { id: '(c)', title: 'Cyber Incident Reporting — rapidly report (within 72 hours) discovered cyber incidents to DoD' },
        { id: '(d)', title: 'Malicious Software — submit discovered malicious software to the DoD Cyber Crime Center (DC3)' },
        { id: '(e)', title: 'Media Preservation and Protection — preserve and protect affected images/media for at least 90 days' },
        { id: '(f)', title: 'Access to Additional Information or Equipment — provide DoD access for forensic analysis' },
        { id: '(g)', title: 'Cyber Incident Damage Assessment Activities — provide media/information to support damage assessment' },
        { id: '(m)', title: 'Subcontracts — flow down the clause to subcontractors handling covered defense information' }
      ]}
    ]
  },

  /* ============================================================
   * FIPS 140-3 — security requirement areas + security levels
   * ============================================================ */
  fips: {
    total: 15,
    families: [
      { id: 'AREAS', name: 'Security Requirement Areas', controls: [
        { id: 'Area-1', title: 'Cryptographic Module Specification' },
        { id: 'Area-2', title: 'Cryptographic Module Interfaces' },
        { id: 'Area-3', title: 'Roles, Services, and Authentication' },
        { id: 'Area-4', title: 'Software/Firmware Security' },
        { id: 'Area-5', title: 'Operational Environment' },
        { id: 'Area-6', title: 'Physical Security' },
        { id: 'Area-7', title: 'Non-Invasive Security' },
        { id: 'Area-8', title: 'Sensitive Security Parameter Management' },
        { id: 'Area-9', title: 'Self-Tests' },
        { id: 'Area-10', title: 'Life-Cycle Assurance' },
        { id: 'Area-11', title: 'Mitigation of Other Attacks' }
      ]},
      { id: 'LEVELS', name: 'Security Levels', controls: [
        { id: 'Level-1', title: 'Security Level 1 — basic security requirements; production-grade components' },
        { id: 'Level-2', title: 'Security Level 2 — role-based authentication and tamper-evidence' },
        { id: 'Level-3', title: 'Security Level 3 — identity-based authentication and tamper-detection/response' },
        { id: 'Level-4', title: 'Security Level 4 — robust protection against environmental and physical attacks' }
      ]}
    ]
  },

  /* ============================================================
   * HIPAA Security Rule — 45 CFR Part 164 Subpart C
   * ============================================================ */
  hipaa: {
    total: 22,
    families: [
      { id: '164.308', name: 'Administrative Safeguards', controls: [
        { id: '164.308(a)(1)', title: 'Security Management Process (risk analysis, risk management, sanction policy, info system activity review)' },
        { id: '164.308(a)(2)', title: 'Assigned Security Responsibility' },
        { id: '164.308(a)(3)', title: 'Workforce Security (authorization, clearance, termination procedures)' },
        { id: '164.308(a)(4)', title: 'Information Access Management (access authorization, establishment, and modification)' },
        { id: '164.308(a)(5)', title: 'Security Awareness and Training' },
        { id: '164.308(a)(6)', title: 'Security Incident Procedures' },
        { id: '164.308(a)(7)', title: 'Contingency Plan (backup, disaster recovery, emergency mode operation)' },
        { id: '164.308(a)(8)', title: 'Evaluation' },
        { id: '164.308(b)(1)', title: 'Business Associate Contracts and Other Arrangements' }
      ]},
      { id: '164.310', name: 'Physical Safeguards', controls: [
        { id: '164.310(a)(1)', title: 'Facility Access Controls' },
        { id: '164.310(b)', title: 'Workstation Use' },
        { id: '164.310(c)', title: 'Workstation Security' },
        { id: '164.310(d)(1)', title: 'Device and Media Controls (disposal, media re-use, accountability, data backup and storage)' }
      ]},
      { id: '164.312', name: 'Technical Safeguards', controls: [
        { id: '164.312(a)(1)', title: 'Access Control (unique user ID, emergency access, automatic logoff, encryption/decryption)' },
        { id: '164.312(b)', title: 'Audit Controls' },
        { id: '164.312(c)(1)', title: 'Integrity (mechanism to authenticate ePHI)' },
        { id: '164.312(d)', title: 'Person or Entity Authentication' },
        { id: '164.312(e)(1)', title: 'Transmission Security (integrity controls and encryption)' }
      ]},
      { id: '164.314', name: 'Organizational Requirements', controls: [
        { id: '164.314(a)(1)', title: 'Business Associate Contracts or Other Arrangements' },
        { id: '164.314(b)(1)', title: 'Requirements for Group Health Plans' }
      ]},
      { id: '164.316', name: 'Policies and Procedures and Documentation Requirements', controls: [
        { id: '164.316(a)', title: 'Policies and Procedures' },
        { id: '164.316(b)(1)', title: 'Documentation (time limit, availability, updates)' }
      ]}
    ]
  },

  /* ============================================================
   * SOC 2 — AICPA Trust Services Criteria (2017, rev 2022)
   * ============================================================ */
  soc2: {
    total: 61,
    families: [
      { id: 'CC1', name: 'CC1 — Control Environment', controls: [
        { id: 'CC1.1', title: 'Commitment to integrity and ethical values' },
        { id: 'CC1.2', title: 'Board independence and oversight responsibility' },
        { id: 'CC1.3', title: 'Management establishes structures, reporting lines, authorities, and responsibilities' },
        { id: 'CC1.4', title: 'Commitment to attract, develop, and retain competent individuals' },
        { id: 'CC1.5', title: 'Accountability for internal control responsibilities' }
      ]},
      { id: 'CC2', name: 'CC2 — Communication and Information', controls: [
        { id: 'CC2.1', title: 'Uses relevant, quality information to support internal control' },
        { id: 'CC2.2', title: 'Internally communicates information, including objectives and responsibilities' },
        { id: 'CC2.3', title: 'Communicates with external parties regarding internal control matters' }
      ]},
      { id: 'CC3', name: 'CC3 — Risk Assessment', controls: [
        { id: 'CC3.1', title: 'Specifies objectives with sufficient clarity to identify and assess risks' },
        { id: 'CC3.2', title: 'Identifies and analyzes risks to the achievement of objectives' },
        { id: 'CC3.3', title: 'Considers the potential for fraud in assessing risks' },
        { id: 'CC3.4', title: 'Identifies and assesses changes that could impact the system of internal control' }
      ]},
      { id: 'CC4', name: 'CC4 — Monitoring Activities', controls: [
        { id: 'CC4.1', title: 'Selects, develops, and performs ongoing and/or separate evaluations' },
        { id: 'CC4.2', title: 'Evaluates and communicates internal control deficiencies in a timely manner' }
      ]},
      { id: 'CC5', name: 'CC5 — Control Activities', controls: [
        { id: 'CC5.1', title: 'Selects and develops control activities that mitigate risks' },
        { id: 'CC5.2', title: 'Selects and develops general control activities over technology' },
        { id: 'CC5.3', title: 'Deploys control activities through policies and procedures' }
      ]},
      { id: 'CC6', name: 'CC6 — Logical and Physical Access Controls', controls: [
        { id: 'CC6.1', title: 'Implements logical access security software, infrastructure, and architectures' },
        { id: 'CC6.2', title: 'Registers and authorizes new users; manages credentials' },
        { id: 'CC6.3', title: 'Authorizes, modifies, or removes access based on roles and responsibilities' },
        { id: 'CC6.4', title: 'Restricts physical access to facilities and protected information assets' },
        { id: 'CC6.5', title: 'Discontinues logical and physical protections over assets when no longer required' },
        { id: 'CC6.6', title: 'Implements logical access security measures against threats from outside boundaries' },
        { id: 'CC6.7', title: 'Restricts the transmission, movement, and removal of information' },
        { id: 'CC6.8', title: 'Implements controls to prevent or detect and act upon unauthorized/malicious software' }
      ]},
      { id: 'CC7', name: 'CC7 — System Operations', controls: [
        { id: 'CC7.1', title: 'Uses detection and monitoring procedures to identify new vulnerabilities' },
        { id: 'CC7.2', title: 'Monitors system components for anomalies indicative of malicious acts or errors' },
        { id: 'CC7.3', title: 'Evaluates security events to determine whether they constitute incidents' },
        { id: 'CC7.4', title: 'Responds to identified security incidents with a defined incident-response program' },
        { id: 'CC7.5', title: 'Identifies, develops, and implements activities to recover from security incidents' }
      ]},
      { id: 'CC8', name: 'CC8 — Change Management', controls: [
        { id: 'CC8.1', title: 'Authorizes, designs, develops, tests, approves, and implements changes' }
      ]},
      { id: 'CC9', name: 'CC9 — Risk Mitigation', controls: [
        { id: 'CC9.1', title: 'Identifies, selects, and develops risk mitigation activities for business disruptions' },
        { id: 'CC9.2', title: 'Assesses and manages risks associated with vendors and business partners' }
      ]},
      { id: 'A1', name: 'Availability', controls: [
        { id: 'A1.1', title: 'Maintains, monitors, and evaluates current processing capacity and use' },
        { id: 'A1.2', title: 'Authorizes, designs, and maintains environmental protections, backups, and recovery infrastructure' },
        { id: 'A1.3', title: 'Tests recovery plan procedures supporting system recovery' }
      ]},
      { id: 'C1', name: 'Confidentiality', controls: [
        { id: 'C1.1', title: 'Identifies and maintains confidential information to meet objectives' },
        { id: 'C1.2', title: 'Disposes of confidential information to meet objectives' }
      ]},
      { id: 'PI1', name: 'Processing Integrity', controls: [
        { id: 'PI1.1', title: 'Obtains/generates and uses relevant, quality information about processing objectives' },
        { id: 'PI1.2', title: 'Implements policies and procedures over system inputs' },
        { id: 'PI1.3', title: 'Implements policies and procedures over system processing' },
        { id: 'PI1.4', title: 'Implements policies and procedures to make outputs complete, accurate, and timely' },
        { id: 'PI1.5', title: 'Implements policies and procedures to store inputs, items in process, and outputs completely and accurately' }
      ]},
      { id: 'P', name: 'Privacy', controls: [
        { id: 'P1.1', title: 'Notice — provides notice about privacy practices to data subjects' },
        { id: 'P2.1', title: 'Choice and Consent — communicates choices and obtains consent for collection, use, retention, and disclosure' },
        { id: 'P3.1', title: 'Collection — collects personal information consistent with objectives' },
        { id: 'P3.2', title: 'Collection — obtains explicit consent for sensitive personal information' },
        { id: 'P4.1', title: 'Use, Retention, and Disposal — limits use of personal information to identified purposes' },
        { id: 'P4.2', title: 'Use, Retention, and Disposal — retains personal information consistent with objectives' },
        { id: 'P4.3', title: 'Use, Retention, and Disposal — securely disposes of personal information' },
        { id: 'P5.1', title: 'Access — grants data subjects access to their personal information for review and update' },
        { id: 'P5.2', title: 'Access — corrects, amends, or appends personal information based on data subject requests' },
        { id: 'P6.1', title: 'Disclosure and Notification — discloses personal information only with consent' },
        { id: 'P6.2', title: 'Disclosure and Notification — creates and retains a record of authorized disclosures' },
        { id: 'P6.3', title: 'Disclosure and Notification — creates and retains a record of unauthorized disclosures' },
        { id: 'P6.4', title: 'Disclosure and Notification — obtains commitments from third parties to protect personal information' },
        { id: 'P6.5', title: 'Disclosure and Notification — obtains commitments from third parties to notify of unauthorized disclosures' },
        { id: 'P6.6', title: 'Disclosure and Notification — provides notification of breaches and incidents' },
        { id: 'P6.7', title: 'Disclosure and Notification — provides an accounting of personal information held and disclosed' },
        { id: 'P7.1', title: 'Quality — collects and maintains accurate, up-to-date, complete, and relevant personal information' },
        { id: 'P8.1', title: 'Monitoring and Enforcement — implements a process for receiving, addressing, and resolving complaints' }
      ]}
    ]
  }

});
