/* ISO 27001/27002:2022 — Controls Reference Data
   Populated in chunks. Each CONTROLS.push.apply() call adds one family batch.
*/
var CONTROLS = [];

/* ── PEOPLE CONTROLS (6.x) ─────────────────────────────────────────────── */
CONTROLS.push.apply(CONTROLS, [
  {
    id:'6.1', name:'Screening', family:'People',
    type:['Preventive'], cia:['C','I','A'],
    description:'Background verification checks on all candidates for employment shall be carried out prior to joining the organisation and on an ongoing basis, taking into account applicable laws, regulations and business requirements.',
    evidence:[
      'Background screening policy defining checks required per role type',
      'Third-party screening provider confirmation reports (DBS, credit, employment history)',
      'Identity verification records (passport, right-to-work checks)',
      'Reference check records for all new hires',
      'Enhanced screening records for roles with elevated IS access (CISO, admin, finance)'
    ],
    policies:[
      'Pre-Employment Screening Policy',
      'Background Verification Procedure',
      'HR Recruitment Policy'
    ]
  },
  {
    id:'6.2', name:'Terms and conditions of employment', family:'People',
    type:['Preventive'], cia:['C','I','A'],
    description:'Employment contractual agreements shall state the personnel\'s and the organisation\'s responsibilities for information security.',
    evidence:[
      'Signed employment contracts containing explicit IS obligations and confidentiality clauses',
      'Signed Acceptable Use Policy (AUP) acknowledgments from all employees',
      'Contractor agreements referencing IS responsibilities and data handling requirements',
      'Onboarding checklist showing IS terms were issued and signed',
      'Records of annual IS terms re-acknowledgment where required'
    ],
    policies:[
      'Employment Terms and IS Responsibilities Policy',
      'Acceptable Use Policy (AUP)',
      'Confidentiality Agreement Template'
    ]
  },
  {
    id:'6.3', name:'Information security awareness, education and training', family:'People',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Personnel and relevant interested parties shall receive appropriate information security awareness education and training, and regular updates on the organisation\'s information security policies and procedures.',
    evidence:[
      'Security awareness training completion records and LMS reports (per employee)',
      'Phishing simulation campaign results with click-rate metrics over time',
      'Annual IS training calendar with evidence all sessions delivered',
      'Role-specific training records (developers: secure coding; admins: PAM; all: GDPR)',
      'Training materials and assessment results confirming comprehension'
    ],
    policies:[
      'Security Awareness and Training Policy',
      'Annual Security Training Plan',
      'Phishing Simulation Procedure'
    ]
  },
  {
    id:'6.4', name:'Disciplinary process', family:'People',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'A formal and communicated disciplinary process shall exist and be implemented to take action against personnel and other relevant interested parties who have committed an information security policy violation.',
    evidence:[
      'Documented disciplinary policy with IS policy violation as a triggering condition',
      'Anonymised HR records of IS policy violations investigated and actions taken',
      'Escalation matrix for severity of IS violations (warning → suspension → dismissal)',
      'Evidence disciplinary policy is communicated to all staff during onboarding',
      'HR training records on handling IS-related disciplinary cases'
    ],
    policies:[
      'Disciplinary Policy',
      'IS Policy Violation and Sanctions Procedure',
      'HR Conduct Policy'
    ]
  },
  {
    id:'6.5', name:'Responsibilities after termination or change of employment', family:'People',
    type:['Preventive'], cia:['C','I','A'],
    description:'Information security responsibilities and duties that remain valid after termination or change of employment shall be defined, enforced and communicated to relevant personnel.',
    evidence:[
      'Exit interview records referencing ongoing confidentiality obligations',
      'Completed leavers checklist covering asset return and access revocation',
      'Access revocation confirmation records timestamped at or before final day',
      'Post-employment NDA reminder letter issued to departing staff',
      'Role-change access review records showing old rights removed and new rights granted'
    ],
    policies:[
      'HR Offboarding Policy',
      'Access Revocation Procedure',
      'Post-Employment Confidentiality Policy'
    ]
  },
  {
    id:'6.6', name:'Confidentiality or non-disclosure agreements', family:'People',
    type:['Preventive'], cia:['C'],
    description:'Non-disclosure or confidentiality agreements reflecting the organisation\'s needs for the protection of information shall be identified, documented, regularly reviewed and signed by personnel and other relevant interested parties.',
    evidence:[
      'Signed NDAs on file for all employees, contractors, and consultants with information access',
      'NDA register or tracking log with signatory, date, and expiry status',
      'Legal review confirmation of NDA template currency and enforceability',
      'Process records showing NDA signing as part of onboarding for all new starters',
      'Third-party/supplier NDA records for parties accessing confidential information'
    ],
    policies:[
      'Confidentiality and NDA Policy',
      'NDA Template (employee and third-party versions)',
      'Information Sharing Policy'
    ]
  },
  {
    id:'6.7', name:'Remote working', family:'People',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Security measures shall be implemented when personnel are working remotely to protect information accessed, processed or stored outside the organisation\'s premises.',
    evidence:[
      'Remote Working Policy document signed by employees',
      'VPN deployment and usage logs showing encrypted remote access',
      'MDM/EMM enrollment reports confirming remote devices are managed',
      'BYOD agreement records where personal devices are permitted',
      'Remote access risk assessment and home-working environment checklist'
    ],
    policies:[
      'Remote Working Policy',
      'BYOD (Bring Your Own Device) Policy',
      'VPN and Remote Access Procedure',
      'Clear Desk and Screen Policy'
    ]
  },
  {
    id:'6.8', name:'Information security event reporting', family:'People',
    type:['Detective','Corrective'], cia:['C','I','A'],
    description:'The organisation shall provide a mechanism for personnel to report observed or suspected information security events through appropriate channels in a timely manner.',
    evidence:[
      'Security incident reporting mechanism details (helpdesk number, portal URL, email alias)',
      'Security event log showing reports received via reporting channels',
      'Awareness training content covering how and when to report events',
      'Near-miss and no-harm event reports showing staff are using the channel',
      'Feedback loop records showing reporters are updated on outcomes'
    ],
    policies:[
      'Incident Reporting Policy',
      'Security Event Reporting Procedure',
      'Whistleblower and Non-Retaliation Policy'
    ]
  }
]);

/* ── ORGANIZATIONAL CONTROLS (5.x) ─────────────────────────────────────── */
CONTROLS.push.apply(CONTROLS, [
  {
    id:'5.1', name:'Policies for information security', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Information security and topic-specific policies shall be defined, approved by management, published, communicated to relevant personnel and interested parties, and reviewed at planned intervals or when significant changes occur.',
    evidence:[
      'Management-signed information security policy document with approval date',
      'Policy version control log and change history',
      'Annual review sign-off or management review meeting minutes referencing the policy',
      'Evidence of distribution: email broadcast, intranet post, or SharePoint publication record',
      'Staff acknowledgment or e-learning completion records confirming policy was read',
      'Board or senior leadership approval documentation'
    ],
    policies:[
      'Information Security Policy (master)',
      'Policy Management Procedure',
      'Topic-Specific Policies (AUP, Access Control, Classification, Cryptography, etc.)'
    ]
  },
  {
    id:'5.2', name:'Information security roles and responsibilities', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Information security roles and responsibilities shall be defined and allocated according to the needs of the organisation.',
    evidence:[
      'RACI matrix or responsibility assignment chart covering all IS roles',
      'Job descriptions with explicit IS responsibilities for CISO, ISO, system owners, and users',
      'Appointment or nomination letter for the CISO / Information Security Officer',
      'IS steering committee terms of reference and membership list',
      'Organisation chart showing IS function and reporting lines'
    ],
    policies:[
      'Information Security Roles & Responsibilities Policy',
      'IS Governance Charter',
      'Information Security Management System (ISMS) Scope Document'
    ]
  },
  {
    id:'5.3', name:'Segregation of duties', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I'],
    description:'Conflicting duties and areas of responsibility shall be segregated to reduce opportunities for unauthorised or unintentional modification or misuse of the organisation\'s assets.',
    evidence:[
      'Documented segregation of duties (SoD) matrix identifying conflicting roles',
      'Access control reports confirming no single user holds conflicting permissions',
      'Workflow approval screenshots demonstrating dual-control requirements',
      'Quarterly or annual SoD conflict review reports',
      'Exception register with compensating controls where full SoD is not feasible'
    ],
    policies:[
      'Segregation of Duties Policy',
      'Access Control Policy',
      'Privileged Access Management Policy'
    ]
  },
  {
    id:'5.4', name:'Management responsibilities', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Management shall require all personnel to apply information security in accordance with the established information security policy, topic-specific policies and procedures of the organisation.',
    evidence:[
      'Signed management commitment statement referencing IS obligations',
      'IS steering committee or management review meeting minutes with IS agenda items',
      'Budget allocation records demonstrating IS investment approved by management',
      'Performance review records including IS KPIs or objectives for staff',
      'Management communications (memos, all-hands messages) reinforcing security responsibilities'
    ],
    policies:[
      'Information Security Management Responsibilities Policy',
      'IS Governance Charter',
      'Management Review Procedure'
    ]
  },
  {
    id:'5.5', name:'Contact with authorities', family:'Organizational',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'The organisation shall establish and maintain contact with relevant authorities (e.g., law enforcement, regulators, emergency services) and share information as required.',
    evidence:[
      'Maintained contact list of relevant authorities (regulator, national CERT, law enforcement, data protection authority)',
      'Procedure document defining when and how to contact each authority',
      'Records of past interactions or notifications (e.g., data breach notifications to supervisory authority)',
      'Incident response plan referencing authority contacts and escalation thresholds',
      'Regulatory subscription records (alerts, newsletters from authorities)'
    ],
    policies:[
      'Contact with Authorities Procedure',
      'Incident Management Policy',
      'Regulatory Compliance Procedure'
    ]
  },
  {
    id:'5.6', name:'Contact with special interest groups', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'The organisation shall establish and maintain contact with special interest groups (security forums, professional associations, vendor security groups) to stay current on threats and best practices.',
    evidence:[
      'Membership certificates or registration records for ISF, ISACA, CISA, FIRST, or sector ISACs',
      'Attendance or participation records at security conferences or forums',
      'Threat intelligence feed subscription records',
      'Records of security advisories received and acted upon from interest groups',
      'Internal distribution records showing threat intel shared with relevant teams'
    ],
    policies:[
      'External Relationships and Liaison Policy',
      'Threat Intelligence Procedure',
      'Information Sharing Policy'
    ]
  },
  {
    id:'5.7', name:'Threat intelligence', family:'Organizational',
    type:['Detective'], cia:['C','I','A'],
    description:'Information relating to information security threats shall be collected, analysed and applied to prevent or reduce the impact of incidents, in a manner relevant to the organisation\'s threat landscape.',
    evidence:[
      'Threat intelligence platform or feed subscription records (e.g., MISP, FS-ISAC, vendor feeds)',
      'Documented threat assessment or threat landscape report reviewed periodically',
      'IOC (Indicators of Compromise) database or log showing ingestion into SIEM/IDS',
      'Threat intelligence sharing agreements (e.g., TLP-tagged reports shared with peers)',
      'Evidence of threat intel driving control changes (meeting minutes, change requests)'
    ],
    policies:[
      'Threat Intelligence Policy',
      'Vulnerability Management Procedure',
      'Security Operations Procedure'
    ]
  },
  {
    id:'5.8', name:'Information security in project management', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Information security shall be integrated into project management throughout the project lifecycle, regardless of the type of project.',
    evidence:[
      'Project charter templates containing mandatory security section or IS risk gate',
      'Project methodology documentation with defined security checkpoints at each phase',
      'Data Protection Impact Assessment (DPIA) records for projects involving personal data',
      'Security sign-off records at project initiation, development, and go-live gates',
      'Risk register entries for projects with IS risk items raised and resolved'
    ],
    policies:[
      'Information Security in Project Management Policy',
      'Project Management Framework (with security gates)',
      'DPIA Procedure',
      'Secure Development Lifecycle Policy'
    ]
  },
  {
    id:'5.9', name:'Inventory of information and other associated assets', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'An inventory of information and other associated assets, including owners, shall be developed and maintained.',
    evidence:[
      'Asset register or Configuration Management Database (CMDB) listing all information assets',
      'Asset classification and ownership assignments in the register',
      'Periodic asset inventory reconciliation or audit records',
      'Asset discovery scan reports cross-referenced against register',
      'Process documentation for adding/removing assets from the inventory'
    ],
    policies:[
      'Asset Management Policy',
      'Information Classification Policy',
      'CMDB Management Procedure'
    ]
  },
  {
    id:'5.10', name:'Acceptable use of information and other associated assets', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Rules for the acceptable use and handling of information and other associated assets shall be identified, documented and implemented.',
    evidence:[
      'Signed Acceptable Use Policy (AUP) acknowledgment records from all staff',
      'AUP reference in onboarding/induction records',
      'Monitoring or DLP reports evidencing enforcement of AUP restrictions',
      'Disciplinary records (anonymised) for policy violations',
      'Annual AUP review and re-acknowledgment records'
    ],
    policies:[
      'Acceptable Use Policy (AUP)',
      'Information Handling Policy',
      'HR Disciplinary Policy (IS component)'
    ]
  },
  {
    id:'5.11', name:'Return of assets', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Personnel and other interested parties shall return all organisational assets in their possession upon change of employment, contract or agreement.',
    evidence:[
      'Completed exit/leavers checklist with hardware return sign-off',
      'Equipment return receipts for laptops, tokens, access cards, phones',
      'Account and access revocation records tied to departure date',
      'Records of secure data removal from returned devices before re-issue',
      'HR offboarding records referencing asset return confirmation'
    ],
    policies:[
      'Asset Return Procedure',
      'HR Offboarding Policy',
      'Asset Management Policy'
    ]
  },
  {
    id:'5.12', name:'Classification of information', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I'],
    description:'Information shall be classified according to the information security needs of the organisation based on confidentiality, integrity, availability and relevant interested-party requirements.',
    evidence:[
      'Information classification scheme document defining classification levels (e.g., Public, Internal, Confidential, Restricted)',
      'Documented classification criteria and decision tree',
      'Labeled document examples at each classification level',
      'Training records showing staff trained on classification scheme',
      'DLP or IRM tool configuration screenshots enforcing classification-based controls'
    ],
    policies:[
      'Information Classification Policy',
      'Data Handling and Labelling Procedure',
      'DLP Policy'
    ]
  },
  {
    id:'5.13', name:'Labelling of information', family:'Organizational',
    type:['Preventive'], cia:['C','I'],
    description:'An appropriate set of procedures for information labelling shall be developed and implemented in accordance with the information classification scheme.',
    evidence:[
      'Sample labeled documents, emails, and reports at each classification level',
      'Automated labeling tool configuration (e.g., Microsoft Purview, Titus) screenshots',
      'Metadata tagging evidence in document management system',
      'User training records on labelling requirements',
      'Audit results showing labelling compliance rates across document repositories'
    ],
    policies:[
      'Information Labelling Procedure',
      'Information Classification Policy',
      'Email Security Policy'
    ]
  },
  {
    id:'5.14', name:'Information transfer', family:'Organizational',
    type:['Preventive'], cia:['C','I'],
    description:'Information transfer rules, procedures, or agreements shall be in place for all types of transfer facilities used within the organisation and between the organisation and other parties.',
    evidence:[
      'Approved information transfer mechanisms list (secure email, SFTP, encrypted USB, cloud share)',
      'Encryption-in-transit configuration evidence (TLS settings, S/MIME or PGP deployment)',
      'Signed NDAs or confidentiality agreements with third parties receiving information',
      'Data Transfer Agreements (DTAs) or Information Sharing Agreements',
      'Audit logs of outbound data transfers for sensitive information'
    ],
    policies:[
      'Information Transfer Policy',
      'Data Exchange Agreement Template',
      'Encryption Policy',
      'Acceptable Use Policy'
    ]
  },
  {
    id:'5.15', name:'Access control', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Rules to control physical and logical access to information and other associated assets shall be established and implemented based on business and information security requirements.',
    evidence:[
      'Access control policy document defining principles (need-to-know, least privilege)',
      'Role-based access control (RBAC) matrix for key systems',
      'Quarterly or semi-annual user access review (UAR) reports',
      'System screenshots confirming access permissions match approved roles',
      'Joiners/Movers/Leavers (JML) process records showing timely access changes'
    ],
    policies:[
      'Access Control Policy',
      'Privileged Access Management Policy',
      'User Access Review Procedure'
    ]
  },
  {
    id:'5.16', name:'Identity management', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'The full lifecycle of identities shall be managed, including creation, maintenance and deletion of digital identities and their associated authentication information.',
    evidence:[
      'Identity lifecycle process documentation (provisioning, modification, deprovisioning)',
      'Directory service (Active Directory/Azure AD) user account audit reports',
      'Joiners/Movers/Leavers (JML) workflow records and completion logs',
      'Orphaned account detection scan reports showing remediation',
      'Privileged account inventory showing all accounts with justification'
    ],
    policies:[
      'Identity Management Policy',
      'User Lifecycle (JML) Procedure',
      'Access Control Policy'
    ]
  },
  {
    id:'5.17', name:'Authentication information', family:'Organizational',
    type:['Preventive'], cia:['C'],
    description:'Allocation and management of authentication information shall be controlled by a management process, including advising personnel on appropriate handling of authentication information.',
    evidence:[
      'Password policy configuration export (minimum length, complexity, history, expiry)',
      'MFA enrollment and enforcement reports for all user accounts',
      'Password manager deployment evidence (enterprise tool rollout records)',
      'Credential vault or PAM tool access logs for privileged accounts',
      'Phishing simulation results and training records for credential security awareness'
    ],
    policies:[
      'Authentication and Password Policy',
      'Multi-Factor Authentication (MFA) Policy',
      'Privileged Account Management Procedure'
    ]
  },
  {
    id:'5.18', name:'Access rights', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Access rights to information and other associated assets shall be provisioned, reviewed, modified and removed in accordance with the topic-specific access control policy.',
    evidence:[
      'Quarterly access recertification / user access review (UAR) reports signed by managers',
      'Provisioning approval tickets in ITSM tool (e.g., ServiceNow, Jira)',
      'Role assignment records with business justification',
      'Access rights removal confirmation records for leavers',
      'Segregation of duties exception register with compensating controls'
    ],
    policies:[
      'Access Rights Management Policy',
      'Access Review and Recertification Procedure',
      'Joiners/Movers/Leavers (JML) Procedure'
    ]
  },
  {
    id:'5.19', name:'Information security in supplier relationships', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Processes and procedures shall be defined and implemented to manage information security risks associated with the use of supplier\'s products or services.',
    evidence:[
      'Third-party / supplier risk register with IS risk ratings',
      'Completed supplier security assessment questionnaires (e.g., SIG, CAIQ)',
      'Supplier classification tiering by criticality and data access level',
      'Records of due diligence performed before supplier on-boarding',
      'Annual supplier security review schedule and completed reviews'
    ],
    policies:[
      'Supplier Information Security Policy',
      'Third-Party Risk Management Procedure',
      'Supplier On-boarding Checklist'
    ]
  },
  {
    id:'5.20', name:'Addressing information security within supplier agreements', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Relevant information security requirements shall be established and agreed with each supplier based on the type of supplier relationship and the classification of information shared.',
    evidence:[
      'Supplier contracts containing mandatory IS clauses (security requirements, audit rights, incident notification)',
      'Data Processing Agreements (DPAs) with all data processors',
      'Standard contract security schedule or addendum template',
      'Legal review sign-off on IS clauses for key supplier contracts',
      'SLA/ISMS requirements referenced in contracts with monitoring obligations'
    ],
    policies:[
      'Supplier Agreement Security Policy',
      'Contract Review and Approval Procedure',
      'Data Processing Agreement Template'
    ]
  },
  {
    id:'5.21', name:'Managing information security in the ICT supply chain', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Processes and procedures shall be defined and implemented to manage information security risks associated with the ICT products and services supply chain.',
    evidence:[
      'ICT supply chain risk assessment records',
      'Software Bill of Materials (SBOM) for critical applications',
      'Hardware provenance and authenticity verification records',
      'Supplier security questionnaires for ICT component vendors',
      'Contractual requirements for sub-suppliers and chain of trust documentation'
    ],
    policies:[
      'ICT Supply Chain Security Policy',
      'Supplier Due Diligence Procedure',
      'Software Integrity Verification Procedure'
    ]
  },
  {
    id:'5.22', name:'Monitoring, review and change management of supplier services', family:'Organizational',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'The organisation shall regularly monitor, review, evaluate and manage changes to supplier information security practices and service delivery.',
    evidence:[
      'Supplier performance review reports and service review meeting minutes',
      'SLA compliance reports from key ICT suppliers',
      'Supplier change notification records (security patches, service changes)',
      'Third-party audit reports or penetration test summaries provided by suppliers',
      'Corrective action plans raised against supplier deficiencies'
    ],
    policies:[
      'Supplier Review and Monitoring Procedure',
      'Supplier Management Policy',
      'Change Management Policy'
    ]
  },
  {
    id:'5.23', name:'Information security for use of cloud services', family:'Organizational',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Processes for acquisition, use, management and exit from cloud services shall be established in accordance with the organisation\'s information security requirements.',
    evidence:[
      'Cloud security assessment records for each cloud service provider (CSP)',
      'CSP agreements with security SLAs and data residency commitments',
      'CSA STAR certification or SOC 2 report for CSPs in use',
      'Shared Responsibility Matrix defining IS obligations for each cloud service',
      'Cloud Access Security Broker (CASB) configuration and activity logs',
      'Cloud exit plan or data portability assessment'
    ],
    policies:[
      'Cloud Security Policy',
      'Cloud Service Procurement Procedure',
      'Shared Responsibility Framework'
    ]
  },
  {
    id:'5.24', name:'Information security incident management planning and preparation', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'The organisation shall plan and prepare for managing information security incidents by defining, establishing and communicating incident management processes, roles and responsibilities.',
    evidence:[
      'Approved Incident Response Plan (IRP) document with version history',
      'Incident response team roster with contact details and on-call schedules',
      'Tabletop exercise or IR simulation records showing plan was tested',
      'IR tooling inventory (forensic tools, SIEM, ticketing system)',
      'Communication templates for internal and external notification'
    ],
    policies:[
      'Incident Management Policy',
      'Incident Response Plan (IRP)',
      'Crisis Communication Plan'
    ]
  },
  {
    id:'5.25', name:'Assessment and decision on information security events', family:'Organizational',
    type:['Detective'], cia:['C','I','A'],
    description:'The organisation shall assess information security events and decide if they are to be categorised as information security incidents.',
    evidence:[
      'Incident classification matrix defining event vs. incident thresholds',
      'SIEM or ticketing system event triage records showing assessment decisions',
      'Escalation decision logs with rationale for classification',
      'SOC analyst runbooks for event assessment procedures',
      'Metrics reports showing event-to-incident conversion rates'
    ],
    policies:[
      'Incident Classification and Escalation Procedure',
      'Security Event Management Policy',
      'SOC Operating Procedure'
    ]
  },
  {
    id:'5.26', name:'Response to information security incidents', family:'Organizational',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Information security incidents shall be responded to in accordance with documented procedures, including containment, eradication, recovery and communication steps.',
    evidence:[
      'Completed incident report forms for recorded incidents',
      'Incident response playbooks per incident type (ransomware, data breach, DDoS)',
      'Containment and eradication action records with timestamps',
      'Post-Incident Review (PIR) report for significant incidents',
      'Regulatory or law enforcement notification records where required'
    ],
    policies:[
      'Incident Response Policy',
      'Incident Response Playbooks',
      'Data Breach Notification Procedure'
    ]
  },
  {
    id:'5.27', name:'Learning from information security incidents', family:'Organizational',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'Knowledge gained from information security incidents shall be used to strengthen and improve the information security controls.',
    evidence:[
      'Post-Incident Review (PIR) reports with lessons learned section',
      'Lessons learned register or log tracking action items from incidents',
      'Corrective action tracking records showing closure of post-incident improvements',
      'Trend analysis or incident metrics reports presented to management',
      'Evidence of controls updated as a result of incident learning'
    ],
    policies:[
      'Post-Incident Review Procedure',
      'Continual Improvement Policy',
      'Corrective Action Procedure'
    ]
  },
  {
    id:'5.28', name:'Collection of evidence', family:'Organizational',
    type:['Detective','Corrective'], cia:['C','I'],
    description:'The organisation shall establish and implement procedures for the identification, collection, acquisition and preservation of evidence related to information security events.',
    evidence:[
      'Digital forensics procedure document covering chain of custody',
      'Chain of custody forms completed for investigated incidents',
      'Forensic disk imaging and write-blocking tool records',
      'Evidence storage and access log (who accessed evidence, when)',
      'Legal hold notices issued during investigations'
    ],
    policies:[
      'Evidence Collection and Handling Procedure',
      'Digital Forensics Policy',
      'Legal Hold Policy'
    ]
  },
  {
    id:'5.29', name:'Information security during disruption', family:'Organizational',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'The organisation shall plan how to maintain information security at an appropriate level during disruption.',
    evidence:[
      'Business Continuity Plan (BCP) with dedicated IS continuity section',
      'IS continuity test records (tabletop or live test results)',
      'Alternate processing site documentation with IS controls replicated',
      'Recovery Time Objectives (RTOs) and Recovery Point Objectives (RPOs) for critical IS assets',
      'Management sign-off on IS continuity plans'
    ],
    policies:[
      'Business Continuity Policy',
      'Information Security Continuity Plan',
      'Disaster Recovery Policy'
    ]
  },
  {
    id:'5.30', name:'ICT readiness for business continuity', family:'Organizational',
    type:['Preventive','Corrective'], cia:['A'],
    description:'ICT readiness shall be planned, implemented, maintained and tested based on business continuity objectives and ICT continuity requirements.',
    evidence:[
      'ICT Continuity Plan / Disaster Recovery Plan (DRP) document',
      'BCP/DR test results including failover test records with success/failure outcomes',
      'RTO and RPO documentation per critical system, validated through testing',
      'Backup and recovery test records with restoration time measurements',
      'ICT readiness review meeting minutes and management sign-off'
    ],
    policies:[
      'ICT Disaster Recovery Plan',
      'Backup and Recovery Policy',
      'Business Continuity Management Procedure'
    ]
  },
  {
    id:'5.31', name:'Legal, statutory, regulatory and contractual requirements', family:'Organizational',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'Legal, statutory, regulatory and contractual requirements relevant to information security shall be identified, documented and kept up to date.',
    evidence:[
      'Legal and regulatory requirements register (GDPR, DORA, NIS2, sector-specific laws)',
      'Compliance calendar showing regulatory deadlines and review dates',
      'Mapping of regulatory requirements to ISMS controls',
      'Legal counsel or DPO sign-off on compliance obligations',
      'Records of regulatory submissions, notifications, or certifications'
    ],
    policies:[
      'Legal and Regulatory Compliance Policy',
      'Regulatory Requirements Register',
      'Compliance Monitoring Procedure'
    ]
  },
  {
    id:'5.32', name:'Intellectual property rights', family:'Organizational',
    type:['Preventive'], cia:['C','I'],
    description:'The organisation shall implement appropriate procedures to protect intellectual property rights, including software licences and proprietary information.',
    evidence:[
      'Software licence register with product, quantity, expiry, and compliance status',
      'Licence compliance audit records (SAM tool reports)',
      'Legal review or sign-off on IP ownership clauses in contracts',
      'Software Bill of Materials (SBOM) for developed and acquired software',
      'Copyright notices and IP ownership statements in key documents'
    ],
    policies:[
      'Intellectual Property Rights Policy',
      'Software Asset Management (SAM) Procedure',
      'Open Source Software Usage Policy'
    ]
  },
  {
    id:'5.33', name:'Protection of records', family:'Organizational',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Records shall be protected from loss, destruction, falsification, unauthorised access and unauthorised release, in accordance with legal, statutory, regulatory, contractual and business requirements.',
    evidence:[
      'Records retention schedule defining retention periods per record type',
      'Records inventory or classification listing critical business records',
      'Secure storage and access control evidence for records repositories',
      'Disposal/destruction logs for records at end of retention period',
      'Legal hold records and litigation freeze documentation'
    ],
    policies:[
      'Records Management Policy',
      'Records Retention Schedule',
      'Secure Disposal Procedure'
    ]
  },
  {
    id:'5.34', name:'Privacy and protection of personally identifiable information (PII)', family:'Organizational',
    type:['Preventive'], cia:['C'],
    description:'The organisation shall identify and meet the requirements regarding the preservation of privacy and protection of PII, in accordance with applicable laws and regulations.',
    evidence:[
      'Records of Processing Activities (ROPA) maintained and up to date',
      'Data Protection Impact Assessments (DPIAs) for high-risk processing activities',
      'Privacy notices published on website and provided to data subjects',
      'Data Subject Request (DSR) log showing requests received and actioned within SLA',
      'PII inventory mapping data flows across systems and third parties'
    ],
    policies:[
      'Privacy Policy',
      'Data Protection Policy',
      'PII Handling and Retention Procedure',
      'DPIA Procedure'
    ]
  },
  {
    id:'5.35', name:'Independent review of information security', family:'Organizational',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'The organisation\'s approach to managing information security and its implementation shall be reviewed independently at planned intervals or when significant changes occur.',
    evidence:[
      'Internal IS audit reports with findings, risk ratings, and management responses',
      'External IS assessment or ISO 27001 certification audit reports',
      'Management review meeting minutes discussing audit findings',
      'Corrective action plans arising from independent reviews',
      'Third-party penetration test reports (annual minimum)'
    ],
    policies:[
      'Internal Audit Policy',
      'Information Security Review Procedure',
      'Management Review Procedure'
    ]
  },
  {
    id:'5.36', name:'Compliance with policies, rules and standards for information security', family:'Organizational',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Compliance with the organisation\'s information security policy, topic-specific policies, rules and standards shall be regularly reviewed.',
    evidence:[
      'Compliance monitoring reports or self-assessment results against IS policies',
      'Technical compliance scan results (CIS Benchmark, vulnerability scans)',
      'Policy exception register with approved deviations and compensating controls',
      'Management review records addressing non-compliance findings',
      'Corrective action closure records for compliance gaps'
    ],
    policies:[
      'Compliance Monitoring Procedure',
      'Policy Exception Management Procedure',
      'Internal Audit Policy'
    ]
  },
  {
    id:'5.37', name:'Documented operating procedures', family:'Organizational',
    type:['Preventive'], cia:['C','I','A'],
    description:'Operating procedures for information processing facilities shall be documented and made available to personnel who need them.',
    evidence:[
      'Operational procedure library with index of all active SOPs and work instructions',
      'Version control and change history for each documented procedure',
      'Access records showing procedures are available to relevant staff',
      'Procedure review schedule with evidence of periodic review completion',
      'Sign-off records confirming staff have read procedures relevant to their role'
    ],
    policies:[
      'Documentation Management Policy',
      'Operating Procedures Register',
      'Document Control Procedure'
    ]
  }
]);

/* ── PHYSICAL CONTROLS (7.x) ────────────────────────────────────────────── */
CONTROLS.push.apply(CONTROLS, [
  {
    id:'7.1', name:'Physical security perimeters', family:'Physical',
    type:['Preventive'], cia:['C','I','A'],
    description:'Security perimeters shall be defined and used to protect areas that contain information and other associated assets.',
    evidence:[
      'Site security plan with defined perimeter zones (outer, inner, secure area)',
      'Physical inspection records confirming perimeter integrity (fencing, walls, barriers)',
      'CCTV coverage map showing perimeter surveillance',
      'Security guard schedules and patrol logs',
      'Building entry control records (reception sign-in, access card logs)'
    ],
    policies:[
      'Physical Security Policy',
      'Perimeter Security Procedure',
      'Facility Security Plan'
    ]
  },
  {
    id:'7.2', name:'Physical entry', family:'Physical',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Secure areas shall be protected by appropriate entry controls to ensure that only authorised personnel are allowed access.',
    evidence:[
      'Access control system logs (badge reader entry/exit records)',
      'Visitor register and escorted-visitor records',
      'Physical access rights review reports (quarterly or semi-annual)',
      'Reception and front-of-house security procedures documentation',
      'Records of unauthorised access attempts and responses'
    ],
    policies:[
      'Physical Access Control Policy',
      'Visitor Management Procedure',
      'Physical Access Review Procedure'
    ]
  },
  {
    id:'7.3', name:'Securing offices, rooms and facilities', family:'Physical',
    type:['Preventive'], cia:['C','I','A'],
    description:'Physical security for offices, rooms and facilities shall be designed and implemented.',
    evidence:[
      'Physical security survey or facility risk assessment report',
      'Lock inspection and key management records',
      'Clean desk audit results for open-plan and private offices',
      'Records showing sensitive conversation areas are shielded (meeting room booking, noise screening)',
      'Secure area designation map with restricted access labels'
    ],
    policies:[
      'Facility Security Policy',
      'Secure Area Access Procedure',
      'Clean Desk Policy'
    ]
  },
  {
    id:'7.4', name:'Physical security monitoring', family:'Physical',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Premises shall be continuously or periodically monitored for unauthorised physical access.',
    evidence:[
      'CCTV system operational records and footage retention logs',
      'Intruder alarm test records and maintenance certificates',
      'Security guard patrol logs with time-stamped check-in records',
      'Physical intrusion detection system (PIDS) configuration and alert logs',
      'Incident records for physical security alerts and responses'
    ],
    policies:[
      'Physical Security Monitoring Policy',
      'CCTV and Surveillance Policy',
      'Intruder Detection System Procedure'
    ]
  },
  {
    id:'7.5', name:'Protecting against physical and environmental threats', family:'Physical',
    type:['Preventive','Detective'], cia:['A'],
    description:'Protection against physical and environmental threats, such as natural disasters, malicious attack or accidents, shall be designed and implemented.',
    evidence:[
      'Environmental and physical risk assessment (flood, fire, storm, seismic)',
      'Fire suppression system installation certificate and maintenance records',
      'Flood barrier or drainage inspection records for at-risk facilities',
      'Environmental monitoring system alerts and logs (temperature, humidity, water)',
      'Business continuity plan addressing environmental threat scenarios'
    ],
    policies:[
      'Environmental Security Policy',
      'Physical Threat and Risk Assessment Procedure',
      'Facilities Management Policy'
    ]
  },
  {
    id:'7.6', name:'Working in secure areas', family:'Physical',
    type:['Preventive'], cia:['C','I','A'],
    description:'Security measures for working in secure areas shall be designed and implemented.',
    evidence:[
      'Secure area access logs confirming only authorised personnel entered',
      'Need-to-know access review records for secure zone personnel',
      'Visitor escort records within secure areas',
      'Prohibition of photography/personal device records (signage and policy acknowledgment)',
      'Clean desk and screen inspection results within secure areas'
    ],
    policies:[
      'Secure Area Working Policy',
      'Restricted Zone Access Procedure',
      'Clean Desk and Screen Policy'
    ]
  },
  {
    id:'7.7', name:'Clear desk and clear screen', family:'Physical',
    type:['Preventive','Detective'], cia:['C'],
    description:'Clear desk rules for papers and removable storage media and clear screen rules for information processing facilities shall be defined and appropriately enforced.',
    evidence:[
      'Clean desk audit results with pass/fail rates per department',
      'Screen lock Group Policy or MDM configuration export (auto-lock timeout)',
      'Spot-check or walkabout inspection records from management or security team',
      'Awareness training records covering clean desk and screen requirements',
      'Signage photos or policy posters displayed at workstations'
    ],
    policies:[
      'Clear Desk and Clear Screen Policy',
      'Physical Security Policy',
      'Acceptable Use Policy'
    ]
  },
  {
    id:'7.8', name:'Equipment siting and protection', family:'Physical',
    type:['Preventive'], cia:['C','I','A'],
    description:'Equipment shall be sited and protected to reduce the risks from physical and environmental threats and from unauthorised access.',
    evidence:[
      'Data centre or server room equipment placement assessment records',
      'Cable management and labelling inspection records',
      'Privacy screen deployment records for workstations in public-facing areas',
      'Equipment room access log showing limited authorised personnel',
      'Environmental sensor placement records (temperature, humidity monitors near hardware)'
    ],
    policies:[
      'Equipment Siting and Protection Policy',
      'Data Centre Physical Security Procedure',
      'Workstation Security Standard'
    ]
  },
  {
    id:'7.9', name:'Security of assets off-premises', family:'Physical',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Off-premises assets shall be protected, taking into account the different risks of working outside the organisation\'s premises.',
    evidence:[
      'Asset register with off-site location tracking for laptops, drives, and mobile devices',
      'Full-disk encryption enforcement evidence for portable devices (BitLocker/FileVault reports)',
      'Authorisation records for removing assets from premises',
      'Insurance certificates covering off-site IT assets',
      'Lost/stolen asset incident records and remote wipe evidence'
    ],
    policies:[
      'Off-Premises and Mobile Asset Security Policy',
      'Mobile Device Management Policy',
      'Asset Register and Tracking Procedure'
    ]
  },
  {
    id:'7.10', name:'Storage media', family:'Physical',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Storage media shall be managed through its lifecycle of acquisition, use, transportation and disposal in accordance with the organisation\'s classification scheme and handling requirements.',
    evidence:[
      'Removable media register and inventory',
      'Encrypted USB or removable media issue/return log',
      'Secure media disposal or shredding certificates from approved vendor',
      'Media handling procedure covering labelling, storage, and transport',
      'Chain of custody records for media sent off-site or to third parties'
    ],
    policies:[
      'Storage Media Management Policy',
      'Removable Media Policy',
      'Secure Media Disposal Procedure'
    ]
  },
  {
    id:'7.11', name:'Supporting utilities', family:'Physical',
    type:['Preventive','Corrective'], cia:['A'],
    description:'Information processing facilities shall be protected from power failures and other disruptions caused by failures in supporting utilities.',
    evidence:[
      'UPS unit test and maintenance records with capacity validation',
      'Generator test logs (monthly or quarterly load tests)',
      'Redundant power supply configuration documentation for critical systems',
      'Utility failure incident records and response times',
      'Power monitoring dashboard reports showing uptime and anomalies'
    ],
    policies:[
      'Supporting Utilities Policy',
      'Data Centre Operations and Facilities Procedure',
      'Business Continuity Policy'
    ]
  },
  {
    id:'7.12', name:'Cabling security', family:'Physical',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'Cables carrying power, data or supporting information services shall be protected from interception, interference or damage.',
    evidence:[
      'Cable routing diagrams for structured cabling and power distribution',
      'Cable inspection and labelling records',
      'Patch panel and communications room access log',
      'Physical penetration test findings related to cabling access',
      'Records of cable routes through non-secure areas being protected (conduit, raised floor)'
    ],
    policies:[
      'Cabling Security Policy',
      'Network Infrastructure Physical Standards',
      'Data Centre Build and Cabling Standard'
    ]
  },
  {
    id:'7.13', name:'Equipment maintenance', family:'Physical',
    type:['Preventive','Corrective'], cia:['A'],
    description:'Equipment shall be maintained correctly to ensure availability, integrity and confidentiality of information.',
    evidence:[
      'Preventive maintenance schedule and completed maintenance logs for all IS equipment',
      'Vendor maintenance agreements and support contracts',
      'Pre-maintenance data backup records confirming data secured before work begins',
      'Maintenance completion sign-off and test records',
      'Records showing only authorised personnel performed maintenance'
    ],
    policies:[
      'Equipment Maintenance Policy',
      'Preventive Maintenance Procedure',
      'Change and Configuration Management Policy'
    ]
  },
  {
    id:'7.14', name:'Secure disposal or re-use of equipment', family:'Physical',
    type:['Preventive','Corrective'], cia:['C'],
    description:'Items of equipment containing storage media shall be verified to ensure that any sensitive data and licensed software has been removed or securely overwritten prior to disposal or re-use.',
    evidence:[
      'Disk sanitisation certificates referencing NIST 800-88 or equivalent standard',
      'Third-party data destruction certificates from approved disposal vendor',
      'Equipment decommission checklist completed for each retired asset',
      'Asset register records updated to reflect disposal with date and method',
      'Physical destruction records (shredding or degaussing certificates) for non-reusable media'
    ],
    policies:[
      'Secure Disposal and Re-use Policy',
      'Equipment Decommissioning Procedure',
      'Asset Management Policy'
    ]
  }
]);

/* ── TECHNOLOGICAL CONTROLS (8.x) ───────────────────────────────────────── */
CONTROLS.push.apply(CONTROLS, [
  {
    id:'8.1', name:'User end point devices', family:'Technological',
    type:['Preventive','Corrective'], cia:['C','I','A'],
    description:'Information stored on, processed by or accessible via user end point devices shall be protected.',
    evidence:[
      'MDM/EMM enrollment reports confirming all endpoints are managed',
      'Endpoint security baseline configuration screenshots (AV, firewall, encryption)',
      'Patch compliance reports showing endpoint OS and application currency',
      'Device inventory reconciled against HR records (all issued devices managed)',
      'Remote wipe capability test records for lost or stolen devices'
    ],
    policies:[
      'Endpoint Security Policy',
      'Mobile Device Management (MDM) Policy',
      'BYOD Policy'
    ]
  },
  {
    id:'8.2', name:'Privileged access rights', family:'Technological',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'The allocation and use of privileged access rights shall be restricted and managed.',
    evidence:[
      'Privileged account inventory listing all admin, service, and root accounts with justification',
      'PAM (Privileged Access Management) tool enrollment and session recording logs',
      'Just-in-time (JIT) access request and approval records',
      'Quarterly privileged access review reports signed by IT management',
      'Privileged session recording samples confirming oversight is operational'
    ],
    policies:[
      'Privileged Access Management (PAM) Policy',
      'Privileged Account Procedure',
      'Access Control Policy'
    ]
  },
  {
    id:'8.3', name:'Information access restriction', family:'Technological',
    type:['Preventive'], cia:['C','I'],
    description:'Access to information and application system functions shall be restricted in accordance with the access control policy.',
    evidence:[
      'Role-based access control (RBAC) configuration screenshots per key system',
      'Application permission matrix showing roles vs. functions available',
      'Data access audit logs demonstrating enforcement of access restrictions',
      'Need-to-know review records for sensitive data repositories',
      'Evidence of least-privilege principle applied during provisioning'
    ],
    policies:[
      'Access Control Policy',
      'Information Access Restriction Procedure',
      'Data Classification and Handling Policy'
    ]
  },
  {
    id:'8.4', name:'Access to source code', family:'Technological',
    type:['Preventive'], cia:['C','I'],
    description:'Read and write access to source code, development tools and software libraries shall be appropriately managed.',
    evidence:[
      'Source code repository access reports (GitHub/GitLab RBAC settings)',
      'Branch protection rules screenshots (required reviews, signed commits)',
      'Code signing certificate records and deployment pipeline configs',
      'Access review logs for repository permissions',
      'Separation of access between developers, testers, and release managers'
    ],
    policies:[
      'Source Code Access Control Policy',
      'Secure Development Policy',
      'Version Control Management Procedure'
    ]
  },
  {
    id:'8.5', name:'Secure authentication', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Secure authentication technologies and procedures shall be implemented based on information access restrictions and the topic-specific policy on access control.',
    evidence:[
      'MFA enrollment and enforcement reports (percentage of accounts protected)',
      'SSO configuration screenshots with supported protocols (SAML, OIDC)',
      'Authentication logs showing successful and failed login tracking',
      'Password policy configuration export (length, complexity, lockout threshold)',
      'Phishing-resistant MFA deployment records for privileged and remote access'
    ],
    policies:[
      'Authentication Policy',
      'Multi-Factor Authentication (MFA) Policy',
      'Password Management Procedure'
    ]
  },
  {
    id:'8.6', name:'Capacity management', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['A'],
    description:'The use of resources shall be monitored and adjusted and projections made of future capacity requirements to ensure the required system performance.',
    evidence:[
      'Capacity monitoring dashboard screenshots with CPU, memory, storage, and network metrics',
      'Performance trend reports identifying growth trajectory',
      'Capacity planning documents with 12-month projections',
      'Alert threshold configuration records for capacity breaches',
      'Records of capacity-related incidents and remediation actions'
    ],
    policies:[
      'Capacity Management Policy',
      'Performance Monitoring and Reporting Procedure',
      'IT Infrastructure Planning Standard'
    ]
  },
  {
    id:'8.7', name:'Protection against malware', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Protection against malware shall be implemented and supported by appropriate user awareness.',
    evidence:[
      'AV/EDR deployment reports confirming coverage across all endpoints and servers',
      'Malware signature update logs confirming currency',
      'Malware scan logs and quarantine/block reports',
      'Malware incident records with containment and remediation evidence',
      'User awareness training records covering malware threats and safe behaviours'
    ],
    policies:[
      'Malware Protection Policy',
      'Anti-Malware Deployment and Management Procedure',
      'Incident Response Policy'
    ]
  },
  {
    id:'8.8', name:'Management of technical vulnerabilities', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Information about technical vulnerabilities of information systems shall be obtained, the organisation\'s exposure to such vulnerabilities evaluated and appropriate measures taken.',
    evidence:[
      'Authenticated vulnerability scan reports (Tenable, Qualys, Rapid7) run at defined frequency',
      'Penetration test reports (minimum annual) with findings and remediation status',
      'Patch deployment logs showing CVE remediation within SLA by severity',
      'Vulnerability risk register with ownership, priority, and target closure dates',
      'Metrics report showing Mean Time to Remediate (MTTR) per vulnerability severity'
    ],
    policies:[
      'Vulnerability Management Policy',
      'Patch Management Procedure',
      'Penetration Testing Policy'
    ]
  },
  {
    id:'8.9', name:'Configuration management', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Configurations, including security configurations, of hardware, software, services and networks shall be established, documented, implemented, monitored and reviewed.',
    evidence:[
      'Security baseline documents (CIS Benchmarks or equivalent) for OS, DB, and network devices',
      'Configuration compliance scan reports (SCCM, Ansible, Chef, or DSC outputs)',
      'CMDB showing current vs. baseline configurations with drift alerts',
      'Change management records authorising configuration changes',
      'Hardening standards documents reviewed and approved by IS'
    ],
    policies:[
      'Configuration Management Policy',
      'System Hardening Standards',
      'Change Management Policy'
    ]
  },
  {
    id:'8.10', name:'Information deletion', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C'],
    description:'Information stored in information systems, devices or in any other storage media shall be deleted when no longer required.',
    evidence:[
      'Data deletion logs from storage systems, databases, and cloud environments',
      'Automated retention policy configuration screenshots (M365 retention labels, S3 lifecycle rules)',
      'Deletion verification records confirming data is unrecoverable after deletion',
      'Data retention schedule aligned to regulatory requirements',
      'DLP alerts on attempts to retain data beyond retention period'
    ],
    policies:[
      'Data Retention and Deletion Policy',
      'Information Lifecycle Management Procedure',
      'Storage Media Disposal Procedure'
    ]
  },
  {
    id:'8.11', name:'Data masking', family:'Technological',
    type:['Preventive'], cia:['C'],
    description:'Data masking shall be used in accordance with the organisation\'s topic-specific policy on access control and other related topic-specific policies, and business requirements.',
    evidence:[
      'Data masking tool configuration records (static and dynamic masking rules)',
      'Masked data samples from non-production environments confirming PII is obfuscated',
      'DPIA referencing data masking as a privacy control for test environments',
      'Test data provisioning approval records confirming production data is masked before use',
      'Access controls on unmasked data restricted to production role holders only'
    ],
    policies:[
      'Data Masking Policy',
      'Test Data Management Policy',
      'Privacy by Design Procedure'
    ]
  },
  {
    id:'8.12', name:'Data leakage prevention', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C'],
    description:'Data leakage prevention measures shall be applied to systems, networks and any other devices that process, store or transmit sensitive information.',
    evidence:[
      'DLP tool deployment and configuration reports (Purview, Forcepoint, Symantec DLP)',
      'DLP policy rule screenshots covering email, web, endpoint, and cloud channels',
      'DLP incident and block/quarantine reports with trend analysis',
      'Email gateway DLP activity logs showing outbound content inspection',
      'Integration evidence between DLP and information classification labels'
    ],
    policies:[
      'Data Loss Prevention (DLP) Policy',
      'DLP Deployment and Tuning Procedure',
      'Information Classification Policy'
    ]
  },
  {
    id:'8.13', name:'Information backup', family:'Technological',
    type:['Preventive','Corrective'], cia:['A'],
    description:'Backup copies of information, software and systems shall be maintained and regularly tested in accordance with the agreed backup policy.',
    evidence:[
      'Backup schedule and automated job completion logs for all critical systems',
      'Backup restoration test records with successful recovery confirmation',
      'Off-site or cloud backup replication confirmation (geographic separation)',
      'RPO and RTO documentation validated through backup testing',
      'Backup monitoring alerts and exception reports'
    ],
    policies:[
      'Backup and Recovery Policy',
      'Backup Management and Testing Procedure',
      'Disaster Recovery Policy'
    ]
  },
  {
    id:'8.14', name:'Redundancy of information processing facilities', family:'Technological',
    type:['Preventive','Corrective'], cia:['A'],
    description:'Information processing facilities shall be implemented with redundancy sufficient to meet availability requirements.',
    evidence:[
      'High-availability (HA) and failover architecture diagrams for critical systems',
      'Disaster recovery failover test results with actual switchover times',
      'Load balancer configuration screenshots showing active-active or active-passive setup',
      'MTTR and MTBF records for critical infrastructure components',
      'SLA compliance reports demonstrating availability targets are met'
    ],
    policies:[
      'Redundancy and High Availability Policy',
      'Disaster Recovery Policy',
      'IT Infrastructure Architecture Standards'
    ]
  },
  {
    id:'8.15', name:'Logging', family:'Technological',
    type:['Detective'], cia:['C','I','A'],
    description:'Logs that record activities, exceptions, faults and other relevant events shall be produced, stored, protected and analysed.',
    evidence:[
      'Log management system configuration showing sources, retention periods, and storage',
      'SIEM ingestion reports confirming all critical systems are logging centrally',
      'Log integrity verification records (hash checking, tamper-evidence controls)',
      'Audit trail examples for privileged actions, access events, and system changes',
      'Log retention compliance records meeting regulatory minimums'
    ],
    policies:[
      'Logging and Monitoring Policy',
      'Audit Trail Management Procedure',
      'Log Retention Standard'
    ]
  },
  {
    id:'8.16', name:'Monitoring activities', family:'Technological',
    type:['Detective'], cia:['C','I','A'],
    description:'Networks, systems and applications shall be monitored for anomalous behaviour and appropriate actions taken to evaluate potential information security incidents.',
    evidence:[
      'SIEM dashboard screenshots with active alert rules and detection logic',
      'SOC daily/weekly monitoring reports and shift handover records',
      'Anomaly detection alert logs and triage records',
      'Threat hunting reports or scheduled monitoring review outputs',
      'Escalation records from monitoring tool to incident response process'
    ],
    policies:[
      'Security Monitoring Policy',
      'SOC Operating Procedure',
      'Anomaly Detection and Response Procedure'
    ]
  },
  {
    id:'8.17', name:'Clock synchronisation', family:'Technological',
    type:['Preventive'], cia:['I'],
    description:'The clocks of information processing systems shall be synchronised to approved time sources.',
    evidence:[
      'NTP server configuration screenshots for all systems (authoritative source defined)',
      'Time synchronisation compliance report confirming all devices are in sync within threshold',
      'Audit log timestamps cross-referenced to NTP source to verify accuracy',
      'Firewall or network rules permitting NTP traffic only from authorised sources',
      'Clock drift monitoring alerts and remediation records'
    ],
    policies:[
      'Clock Synchronisation Policy',
      'NTP Configuration Standard',
      'System Administration Procedure'
    ]
  },
  {
    id:'8.18', name:'Use of privileged utility programs', family:'Technological',
    type:['Preventive','Detective'], cia:['C','I','A'],
    description:'The use of utility programs that might be capable of overriding system and application controls shall be restricted and tightly controlled.',
    evidence:[
      'Approved privileged utility program list with business justification per tool',
      'Access control configurations showing utilities restricted to authorised admins only',
      'Usage audit logs for privileged utilities (database admin tools, forensic tools, sysinternals)',
      'Change management records authorising utility use for specific tasks',
      'Monitoring alerts for unauthorised or unexpected utility execution'
    ],
    policies:[
      'Privileged Utility Management Policy',
      'System Administration and Tooling Procedure',
      'Privileged Access Management Policy'
    ]
  },
  {
    id:'8.19', name:'Installation of software on operational systems', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Procedures and measures shall be implemented to securely manage software installation on operational systems.',
    evidence:[
      'Software installation approval workflow records in ITSM tool',
      'Application allowlist or whitelist configuration (AppLocker, WDAC, or equivalent)',
      'Software inventory versus approved software list comparison report',
      'Change management records authorising software deployments to production',
      'Scan results showing no unauthorised software on production systems'
    ],
    policies:[
      'Software Installation and Control Policy',
      'Application Control Procedure',
      'Change Management Policy'
    ]
  },
  {
    id:'8.20', name:'Networks security', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Networks and network devices shall be secured, managed and controlled to protect information in systems and applications.',
    evidence:[
      'Network architecture diagrams showing security zones and controls',
      'Firewall rule sets with documented business justification and last review date',
      'Network security assessment or pen test report results',
      'IDS/IPS configuration screenshots and alert/block reports',
      'Network access control (NAC) enforcement records'
    ],
    policies:[
      'Network Security Policy',
      'Network Architecture and Design Standard',
      'Firewall Management Procedure'
    ]
  },
  {
    id:'8.21', name:'Security of network services', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Security mechanisms, service levels and service requirements of all network services shall be identified, implemented and monitored.',
    evidence:[
      'Network service SLAs with defined security requirements',
      'ISP or managed service provider agreements referencing security obligations',
      'Network service security audit or assessment records',
      'Encryption-in-transit configuration evidence (TLS version, cipher suites)',
      'Records of security incidents involving network services and resolution'
    ],
    policies:[
      'Network Services Security Policy',
      'Third-Party Network Service Procedure',
      'Encryption Policy'
    ]
  },
  {
    id:'8.22', name:'Segregation of networks', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Groups of information services, users and information systems shall be segregated in networks.',
    evidence:[
      'Network segmentation architecture diagrams (VLANs, DMZ, trust zones)',
      'VLAN and DMZ firewall ACL rule configuration screenshots',
      'Penetration test results verifying network isolation between zones',
      'Inter-segment traffic flow logs confirming only permitted flows traverse boundaries',
      'PCI DSS or equivalent scoping evidence demonstrating cardholder network isolation'
    ],
    policies:[
      'Network Segmentation Policy',
      'Network Zoning Architecture Standard',
      'Firewall Management Procedure'
    ]
  },
  {
    id:'8.23', name:'Web filtering', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Access to external websites shall be managed to reduce exposure to malicious content.',
    evidence:[
      'Web proxy or DNS filtering configuration screenshots with blocked categories defined',
      'Web filtering activity and block reports (category summaries)',
      'User exception request and approval records for blocked sites',
      'Malicious site block records from threat-intel-fed filtering rules',
      'Awareness training records covering safe browsing practices'
    ],
    policies:[
      'Web Filtering and Internet Use Policy',
      'Acceptable Use Policy',
      'DNS Security Procedure'
    ]
  },
  {
    id:'8.24', name:'Use of cryptography', family:'Technological',
    type:['Preventive'], cia:['C','I'],
    description:'Rules for the effective use of cryptography, including cryptographic key management, shall be defined and implemented.',
    evidence:[
      'Cryptography standards document (approved algorithms: AES-256, RSA-2048+, TLS 1.2+)',
      'SSL/TLS certificate inventory with expiry monitoring records',
      'Key management procedure covering generation, distribution, storage, rotation, and destruction',
      'PKI or certificate authority (CA) documentation',
      'Cryptographic scan or configuration audit results confirming weak ciphers are disabled'
    ],
    policies:[
      'Cryptography and Encryption Policy',
      'Key Management Procedure',
      'TLS and Certificate Management Standard'
    ]
  },
  {
    id:'8.25', name:'Secure development life cycle', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Rules for the secure development of software and systems shall be established and applied.',
    evidence:[
      'Secure SDLC policy with security requirements integrated into each phase',
      'Security gate sign-off records at design, development, and pre-release stages',
      'SAST (static analysis) and DAST (dynamic analysis) scan results per release',
      'Security requirement templates or user story acceptance criteria with IS items',
      'Developer security training records (OWASP, secure coding courses)'
    ],
    policies:[
      'Secure Software Development Lifecycle (SDLC) Policy',
      'Secure Coding Guidelines',
      'Security Testing Policy'
    ]
  },
  {
    id:'8.26', name:'Application security requirements', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Information security requirements shall be identified, specified and approved when developing or acquiring applications.',
    evidence:[
      'Security requirements specification documents for each application',
      'Threat model reports (STRIDE or PASTA) produced at design stage',
      'OWASP Top 10 mapping documentation for web applications',
      'Security acceptance criteria in user stories and sprint definition of done',
      'Security review sign-off before application procurement or release'
    ],
    policies:[
      'Application Security Requirements Policy',
      'Threat Modelling Procedure',
      'Secure SDLC Policy'
    ]
  },
  {
    id:'8.27', name:'Secure system architecture and engineering principles', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Principles for engineering secure systems shall be established, documented, maintained and applied to any information system development or integration activity.',
    evidence:[
      'Secure architecture principles document (defence-in-depth, zero-trust, least-privilege, fail-secure)',
      'Architecture review records and security design sign-off for new systems',
      'Architecture Decision Records (ADRs) with security rationale documented',
      'Threat model documents produced at architecture stage',
      'Evidence of security engineering principles applied in system design (e.g., zero-trust design docs)'
    ],
    policies:[
      'Secure Architecture and Engineering Policy',
      'Security Engineering Principles and Standards',
      'Architecture Review Procedure'
    ]
  },
  {
    id:'8.28', name:'Secure coding', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Secure coding principles shall be applied to software development.',
    evidence:[
      'Secure coding guidelines document (OWASP, SANS CWE Top 25 coverage)',
      'SAST tool scan reports with findings categorised by severity',
      'Code review checklist with security items (SQL injection, XSS, auth bypass, etc.)',
      'Developer secure coding training completion records',
      'Defect tracking records showing security findings raised and remediated in sprint'
    ],
    policies:[
      'Secure Coding Policy',
      'Code Review Procedure',
      'Secure SDLC Policy'
    ]
  },
  {
    id:'8.29', name:'Security testing in development and acceptance', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Security testing processes shall be defined and implemented in the development and acceptance lifecycle.',
    evidence:[
      'SAST and DAST scan reports per release with severity ratings and remediation status',
      'Penetration test reports for significant releases (internal or third-party)',
      'Security acceptance test results showing all critical findings resolved before go-live',
      'Defect backlog showing security findings with priority and closure dates',
      'UAT sign-off documentation confirming security acceptance criteria met'
    ],
    policies:[
      'Security Testing Policy',
      'Penetration Testing Procedure',
      'Release Management and Security Sign-off Procedure'
    ]
  },
  {
    id:'8.30', name:'Outsourced development', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'The organisation shall supervise and monitor the activity related to outsourced system development.',
    evidence:[
      'Outsourced development security requirements in supplier contracts',
      'Third-party SAST scan results provided by vendor at agreed intervals',
      'Code handover security checklist and review records',
      'Software Bill of Materials (SBOM) received from outsourced development vendor',
      'Supplier security assessment records for development vendors'
    ],
    policies:[
      'Outsourced Development Security Policy',
      'Third-Party Development Oversight Procedure',
      'Supplier Information Security Policy'
    ]
  },
  {
    id:'8.31', name:'Separation of development, test and production environments', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Development, testing and production environments shall be separated and secured.',
    evidence:[
      'Environment architecture diagrams showing dev/test/prod separation',
      'Access control reports confirming developers cannot access production directly',
      'Change management records showing formal promotion process between environments',
      'Production access approval records for exceptional access (break-glass)',
      'Configuration differences between environments documented (no production secrets in dev)'
    ],
    policies:[
      'Environment Separation Policy',
      'Change Management Policy',
      'Privileged Access Management Policy'
    ]
  },
  {
    id:'8.32', name:'Change management', family:'Technological',
    type:['Preventive'], cia:['C','I','A'],
    description:'Changes to information processing facilities and information systems shall be subject to change management procedures.',
    evidence:[
      'Change Advisory Board (CAB) meeting minutes with approved changes listed',
      'Change request and approval records in ITSM tool (ServiceNow, Jira)',
      'Emergency change procedure records with retrospective CAB approval',
      'Post-implementation review records for significant changes',
      'Failed and back-out change records with rollback evidence'
    ],
    policies:[
      'Change Management Policy',
      'Change Control Procedure',
      'Emergency Change Procedure'
    ]
  },
  {
    id:'8.33', name:'Test information', family:'Technological',
    type:['Preventive'], cia:['C'],
    description:'Test information shall be appropriately selected, protected and managed.',
    evidence:[
      'Test data management procedure requiring anonymisation of production data before use in test',
      'Data masking or synthetic data generation records for test environments',
      'DLP alerts confirming production PII is not present in test or dev environments',
      'Test data approval and provisioning request records',
      'Periodic audit of test environments for presence of real personal data'
    ],
    policies:[
      'Test Data Management Policy',
      'Test Information Handling Procedure',
      'Data Masking Policy'
    ]
  },
  {
    id:'8.34', name:'Protection of information systems during audit testing', family:'Technological',
    type:['Preventive','Detective','Corrective'], cia:['C','I','A'],
    description:'Audit tests and other assurance activities involving assessment of operational systems shall be planned and agreed to minimise disruptions to business processes.',
    evidence:[
      'Audit scope agreement and rules of engagement document signed before audit testing',
      'Read-only or limited access grants issued to auditors for the audit period',
      'Audit scheduling records confirming off-peak testing windows agreed with operations',
      'Post-audit system integrity verification records',
      'Authorisation records for audit tools used on production systems'
    ],
    policies:[
      'IS Audit and Testing Policy',
      'Audit Access and Scope Management Procedure',
      'Penetration Testing Rules of Engagement Template'
    ]
  }
]);
