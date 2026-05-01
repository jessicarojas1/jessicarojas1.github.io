var CONTROLS = [];

CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.2.2',
    name: 'AI Policy',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall establish, document, and communicate an AI policy that sets direction for responsible AI development and use, aligned with the organization\'s objectives and risk appetite.',
    evidence: [
      'Approved and signed AI policy document',
      'Distribution records showing policy communicated to relevant staff',
      'Policy review logs with dates and approver signatures',
      'Alignment documentation linking AI policy to organizational strategy'
    ],
    policies: [
      'AI Governance Policy',
      'Responsible AI Use Policy',
      'AI Ethics Statement'
    ]
  },
  {
    id: 'A.2.3',
    name: 'AI Roles and Responsibilities',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['A'],
    description: 'The organization shall define and assign roles, responsibilities, and authorities for AI management, including AI owners, data stewards, and ethics reviewers.',
    evidence: [
      'RACI matrix for AI systems',
      'Job descriptions referencing AI responsibilities',
      'Org chart showing AI governance structure',
      'Appointment letters or role assignment records for AI roles'
    ],
    policies: [
      'AI Roles and Responsibilities Policy',
      'AI Governance Charter',
      'RACI Framework Document'
    ]
  },
  {
    id: 'A.3.2',
    name: 'AI Risk Assessment',
    family: 'Governance',
    type: ['Preventive', 'Detective'],
    principles: ['A','R'],
    description: 'The organization shall conduct AI-specific risk assessments covering bias, safety, privacy, security, and societal harms. Risks shall be identified, analyzed, and evaluated against acceptance criteria.',
    evidence: [
      'Completed AI risk register with likelihood and impact ratings',
      'AI Impact Assessment (AIIA) records',
      'Risk acceptance decisions signed by risk owners',
      'Minutes from risk review meetings'
    ],
    policies: [
      'AI Risk Assessment Policy',
      'AI Impact Assessment Procedure',
      'Risk Acceptance Criteria Document'
    ]
  },
  {
    id: 'A.4.2',
    name: 'Intended Use and Constraints',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall define and document the intended purpose, use cases, operational context, and known constraints or limitations of each AI system before deployment.',
    evidence: [
      'AI system specification documents defining intended use',
      'Out-of-scope use cases document',
      'Technical constraint logs per AI system',
      'Sign-off records from business owners on intended use'
    ],
    policies: [
      'AI System Specification Policy',
      'AI Use Case Authorization Procedure',
      'Acceptable Use Policy for AI'
    ]
  },
  {
    id: 'A.4.3',
    name: 'AI System Impact Assessment',
    family: 'Governance',
    type: ['Preventive', 'Detective'],
    principles: ['F','A','R','P'],
    description: 'The organization shall assess the potential impacts of AI systems on individuals, groups, and society, including fairness, discrimination, privacy, and safety impacts, prior to deployment and periodically thereafter.',
    evidence: [
      'Completed AI Impact Assessment forms',
      'Bias and fairness evaluation reports',
      'Privacy Impact Assessment linked to AI system',
      'Stakeholder consultation records',
      'Periodic reassessment records post-deployment'
    ],
    policies: [
      'AI Impact Assessment Policy',
      'Algorithmic Fairness Assessment Procedure',
      'Privacy by Design Policy'
    ]
  },
  {
    id: 'A.5.2',
    name: 'Internal Audit of AI Systems',
    family: 'Governance',
    type: ['Detective'],
    principles: ['A','T'],
    description: 'The organization shall conduct internal audits of AI systems and the AI management system at planned intervals to determine conformance with requirements and identify areas for improvement.',
    evidence: [
      'Internal audit schedule and plan',
      'Completed audit checklists for AI systems',
      'Audit reports with findings and nonconformities',
      'Evidence of auditor independence from audited areas'
    ],
    policies: [
      'AI Internal Audit Policy',
      'Audit Programme Procedure',
      'AI Audit Checklist'
    ]
  },
  {
    id: 'A.5.3',
    name: 'AI Management Review',
    family: 'Governance',
    type: ['Detective'],
    principles: ['A'],
    description: 'Top management shall review the organization\'s AI management system at planned intervals to ensure its continuing suitability, adequacy, effectiveness, and alignment with strategic direction.',
    evidence: [
      'Management review meeting minutes',
      'Review input reports (audit results, performance metrics, incidents)',
      'Action items logged and tracked from reviews',
      'Attendance records of top management'
    ],
    policies: [
      'AI Management Review Procedure',
      'AI Performance Reporting Policy'
    ]
  },
  {
    id: 'A.5.4',
    name: 'AI Incident Management',
    family: 'Governance',
    type: ['Corrective', 'Detective'],
    principles: ['A','R','T'],
    description: 'The organization shall establish processes to detect, report, investigate, and respond to AI-related incidents including unexpected outputs, system failures, misuse, and adverse impacts on individuals.',
    evidence: [
      'AI incident register and classification scheme',
      'Incident response runbooks for AI systems',
      'Post-incident review reports',
      'Escalation matrix for AI incidents',
      'Root cause analysis records'
    ],
    policies: [
      'AI Incident Management Policy',
      'AI Incident Response Procedure',
      'AI Incident Classification Guide'
    ]
  }
]);

CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.6.1',
    name: 'AI System Design and Development Planning',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R'],
    description: 'The organization shall plan and control the design and development of AI systems, including stages, reviews, responsibilities, resources, and interfaces between development groups.',
    evidence: [
      'AI project plans with milestones and review gates',
      'Design documents and architecture diagrams',
      'Resource allocation records for AI projects',
      'Development team RACI for each AI system'
    ],
    policies: [
      'AI Development Lifecycle Policy',
      'AI System Design Standards',
      'AI Project Governance Procedure'
    ]
  },
  {
    id: 'A.6.2',
    name: 'AI System Requirements',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['T','A','R'],
    description: 'The organization shall establish functional and non-functional requirements for AI systems, including performance metrics, fairness criteria, explainability needs, and safety boundaries.',
    evidence: [
      'AI system requirements specification documents',
      'Traceability matrix linking requirements to controls',
      'Signed-off requirements from business and technical stakeholders',
      'Fairness and explainability requirements documentation'
    ],
    policies: [
      'AI Requirements Management Policy',
      'AI Acceptance Criteria Procedure',
      'AI Non-Functional Requirements Standard'
    ]
  },
  {
    id: 'A.6.3',
    name: 'Data Acquisition and Preparation',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['F','P','R'],
    description: 'The organization shall manage the acquisition, selection, and preparation of data used for AI system training and operation, ensuring quality, representativeness, and compliance with legal requirements.',
    evidence: [
      'Data acquisition procedures and records',
      'Data quality assessment reports',
      'Consent and provenance records for training data',
      'Data labeling guidelines and inter-rater reliability records'
    ],
    policies: [
      'AI Data Acquisition Policy',
      'Data Quality Management Procedure',
      'Training Data Governance Policy'
    ]
  },
  {
    id: 'A.6.4',
    name: 'AI System Training',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['F','R','A'],
    description: 'The organization shall document and control the training of AI models, including algorithm selection, hyperparameter choices, training environment, and measures taken to address bias and overfitting.',
    evidence: [
      'Model training logs and experiment tracking records',
      'Hyperparameter and architecture decision records',
      'Bias evaluation results pre- and post-training',
      'Model version control records'
    ],
    policies: [
      'AI Model Training Policy',
      'Experiment Tracking Standard',
      'Model Bias Mitigation Procedure'
    ]
  },
  {
    id: 'A.6.5',
    name: 'AI System Testing and Validation',
    family: 'Lifecycle',
    type: ['Detective'],
    principles: ['R','F','A'],
    description: 'The organization shall test and validate AI systems against requirements, including functional correctness, performance benchmarks, fairness metrics, robustness to adversarial inputs, and safety behaviors.',
    evidence: [
      'Test plans and test case documentation',
      'Validation reports with pass/fail against acceptance criteria',
      'Adversarial and edge-case testing records',
      'Independent validation records separate from developers'
    ],
    policies: [
      'AI Testing and Validation Policy',
      'Model Validation Standard',
      'AI Acceptance Testing Procedure'
    ]
  },
  {
    id: 'A.6.6',
    name: 'AI System Deployment',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R','T'],
    description: 'The organization shall control the deployment of AI systems, including deployment authorization gates, staged rollout plans, rollback procedures, and communication to affected stakeholders.',
    evidence: [
      'Deployment authorization records and approvals',
      'Staged rollout plans and monitoring criteria',
      'Rollback and failover procedures',
      'Stakeholder communication records at deployment'
    ],
    policies: [
      'AI Deployment Policy',
      'AI Release Management Procedure',
      'AI Rollback and Contingency Plan'
    ]
  },
  {
    id: 'A.6.7',
    name: 'AI System Operation and Monitoring',
    family: 'Lifecycle',
    type: ['Detective'],
    principles: ['R','A','T'],
    description: 'The organization shall monitor AI systems in production for performance degradation, data drift, unexpected behaviors, and adverse impacts, with defined thresholds that trigger review or intervention.',
    evidence: [
      'Model monitoring dashboards and alert configurations',
      'Performance and drift monitoring reports',
      'Incident logs triggered by monitoring thresholds',
      'Periodic performance review records'
    ],
    policies: [
      'AI Operational Monitoring Policy',
      'Model Performance Review Procedure',
      'AI Alerting and Escalation Standard'
    ]
  },
  {
    id: 'A.6.8',
    name: 'AI System Change Management',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R'],
    description: 'The organization shall manage changes to AI systems including model updates, data pipeline changes, and infrastructure modifications, ensuring impact assessments are performed and changes are authorized before implementation.',
    evidence: [
      'Change request forms for AI system changes',
      'Impact assessments for model updates',
      'Change approval records',
      'Pre/post change testing results'
    ],
    policies: [
      'AI Change Management Policy',
      'AI Change Control Procedure',
      'Model Update Authorization Standard'
    ]
  },
  {
    id: 'A.6.9',
    name: 'AI System Decommissioning',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','P'],
    description: 'The organization shall manage the decommissioning of AI systems, including data retention and deletion, documentation archiving, stakeholder communication, and knowledge transfer.',
    evidence: [
      'Decommissioning authorization records',
      'Data deletion/archival records per retention policy',
      'Stakeholder notification records',
      'Final system documentation archived'
    ],
    policies: [
      'AI Decommissioning Policy',
      'Data Retention and Deletion Policy',
      'AI Knowledge Retention Procedure'
    ]
  }
]);

CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.7.2',
    name: 'Data Governance for AI',
    family: 'Data',
    type: ['Preventive'],
    principles: ['A','P','F'],
    description: 'The organization shall establish data governance practices for AI, defining data ownership, stewardship, classification, and lifecycle management for all data used in AI systems.',
    evidence: [
      'Data governance framework document',
      'Data catalog with ownership and classification records',
      'Data stewardship responsibilities matrix',
      'Data lifecycle management records'
    ],
    policies: [
      'AI Data Governance Policy',
      'Data Ownership and Stewardship Procedure',
      'AI Data Classification Standard'
    ]
  },
  {
    id: 'A.7.3',
    name: 'Data Quality for AI',
    family: 'Data',
    type: ['Preventive', 'Detective'],
    principles: ['R','F'],
    description: 'The organization shall define and monitor data quality criteria relevant to AI systems, including accuracy, completeness, timeliness, consistency, and representativeness of training and operational data.',
    evidence: [
      'Data quality metrics and KPIs for AI datasets',
      'Data profiling and quality assessment reports',
      'Data quality remediation records',
      'Representative sampling analysis for training data'
    ],
    policies: [
      'AI Data Quality Policy',
      'Data Quality Assessment Procedure',
      'Training Data Quality Standard'
    ]
  },
  {
    id: 'A.7.4',
    name: 'Data Privacy for AI',
    family: 'Data',
    type: ['Preventive'],
    principles: ['P','F'],
    description: 'The organization shall implement privacy controls for data used in AI systems, including purpose limitation, data minimization, anonymization/pseudonymization, and compliance with applicable privacy regulations.',
    evidence: [
      'Privacy Impact Assessment for AI systems',
      'Data minimization and anonymization records',
      'Consent management records for personal data in training sets',
      'Privacy by design evidence in AI development'
    ],
    policies: [
      'AI Data Privacy Policy',
      'Privacy by Design for AI Procedure',
      'Personal Data in AI Training Data Standard'
    ]
  },
  {
    id: 'A.7.5',
    name: 'Data Provenance and Lineage',
    family: 'Data',
    type: ['Preventive', 'Detective'],
    principles: ['T','A'],
    description: 'The organization shall track and document the provenance and lineage of data used in AI systems, enabling traceability from source through transformation to model training and operation.',
    evidence: [
      'Data lineage diagrams for AI pipelines',
      'Metadata records showing data origin and transformations',
      'Data provenance tracking tool outputs',
      'Audit trails for data pipeline changes'
    ],
    policies: [
      'Data Lineage and Provenance Policy',
      'AI Data Traceability Standard',
      'Metadata Management Procedure'
    ]
  },
  {
    id: 'A.7.6',
    name: 'Data Security for AI',
    family: 'Data',
    type: ['Preventive'],
    principles: ['S','P'],
    description: 'The organization shall implement security controls to protect data used in AI systems from unauthorized access, exfiltration, poisoning attacks, and integrity compromise throughout the data lifecycle.',
    evidence: [
      'Access control lists for AI training datasets',
      'Data encryption records (at rest and in transit)',
      'Data poisoning detection controls and logs',
      'Security testing results for AI data pipelines'
    ],
    policies: [
      'AI Data Security Policy',
      'Training Data Protection Standard',
      'Data Poisoning Prevention Procedure'
    ]
  },
  {
    id: 'A.7.7',
    name: 'Data Bias Assessment',
    family: 'Data',
    type: ['Detective'],
    principles: ['F','T'],
    description: 'The organization shall assess datasets for biases that could lead to unfair, discriminatory, or harmful AI outcomes, and implement measures to mitigate identified biases before use in AI training.',
    evidence: [
      'Bias assessment reports for training datasets',
      'Demographic parity and disparate impact analysis records',
      'Bias mitigation measures implemented and documented',
      'Post-mitigation bias re-assessment records'
    ],
    policies: [
      'AI Bias Assessment Policy',
      'Algorithmic Fairness Procedure',
      'Training Data Bias Mitigation Standard'
    ]
  }
]);

CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.8.2',
    name: 'AI System Documentation',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall maintain comprehensive documentation for each AI system, including purpose, capabilities, limitations, training data descriptions, model architecture, and performance characteristics.',
    evidence: [
      'Model cards or AI system datasheets',
      'Architecture and design documentation',
      'Known limitations and failure mode documentation',
      'Version-controlled documentation repository'
    ],
    policies: [
      'AI Documentation Policy',
      'Model Card Standard',
      'AI System Datasheet Procedure'
    ]
  },
  {
    id: 'A.8.3',
    name: 'Explainability of AI Systems',
    family: 'Transparency',
    type: ['Preventive', 'Detective'],
    principles: ['T','F','A'],
    description: 'The organization shall implement explainability measures appropriate to the AI system\'s risk level and use case, enabling stakeholders to understand how the system produces outputs.',
    evidence: [
      'Explainability method documentation (SHAP, LIME, attention maps)',
      'Explainability evaluation results',
      'User-facing explanation interface screenshots or records',
      'Stakeholder comprehension testing records'
    ],
    policies: [
      'AI Explainability Policy',
      'Explainability Requirements Standard',
      'Right to Explanation Procedure'
    ]
  },
  {
    id: 'A.8.4',
    name: 'Communication of AI Use',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T'],
    description: 'The organization shall communicate clearly to users and affected individuals when they are interacting with or subject to decisions made by an AI system, including the nature and capabilities of the system.',
    evidence: [
      'AI disclosure notices in user interfaces',
      'Privacy notices mentioning AI decision-making',
      'AI bot disclosure records for conversational AI',
      'Marketing and communications review records for AI accuracy'
    ],
    policies: [
      'AI Transparency and Disclosure Policy',
      'AI User Communication Standard',
      'AI Labeling and Notification Procedure'
    ]
  },
  {
    id: 'A.8.5',
    name: 'Human Oversight of AI Systems',
    family: 'Transparency',
    type: ['Preventive', 'Detective'],
    principles: ['A','R','T'],
    description: 'The organization shall implement meaningful human oversight mechanisms for AI systems, ensuring humans can monitor, intervene, override, or shut down AI systems where appropriate to the risk level.',
    evidence: [
      'Human-in-the-loop process documentation',
      'Override and intervention procedure records',
      'Operator training records for AI oversight',
      'Escalation triggers and human review thresholds'
    ],
    policies: [
      'AI Human Oversight Policy',
      'Human-in-the-Loop Standard',
      'AI Override and Intervention Procedure'
    ]
  },
  {
    id: 'A.9.2',
    name: 'Intended Audience and User Information',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall provide appropriate information to users of AI systems, including capabilities, limitations, appropriate use contexts, and instructions for safe and effective use.',
    evidence: [
      'User guides and documentation for AI systems',
      'Training materials for AI system users',
      'Warnings and limitation disclosures provided to users',
      'User comprehension assessments'
    ],
    policies: [
      'AI User Information Policy',
      'AI User Training Standard',
      'AI Limitations Disclosure Procedure'
    ]
  },
  {
    id: 'A.9.3',
    name: 'Feedback and Redress Mechanisms',
    family: 'Transparency',
    type: ['Corrective'],
    principles: ['A','F','T'],
    description: 'The organization shall provide mechanisms for users and affected individuals to submit feedback, report concerns, contest AI decisions, and seek redress for adverse impacts from AI systems.',
    evidence: [
      'Feedback channel descriptions and access records',
      'AI complaint and appeal register',
      'Records of reviewed and acted-upon feedback',
      'Redress outcomes and resolution records'
    ],
    policies: [
      'AI Feedback and Redress Policy',
      'AI Complaint Handling Procedure',
      'AI Decision Contest Mechanism'
    ]
  },
  {
    id: 'A.9.4',
    name: 'Record Keeping for AI Decisions',
    family: 'Transparency',
    type: ['Detective'],
    principles: ['A','T'],
    description: 'The organization shall maintain records of significant AI system decisions and the data inputs used, enabling audit, review, and explanation of AI-driven outcomes for accountability purposes.',
    evidence: [
      'AI decision logs with input data and output records',
      'Log retention policy and retention schedule',
      'Audit access controls for AI decision records',
      'Sample reviews of AI decisions against outcomes'
    ],
    policies: [
      'AI Decision Record-Keeping Policy',
      'AI Audit Trail Standard',
      'AI Log Retention Procedure'
    ]
  }
]);

CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.10.2',
    name: 'Third-Party AI Supplier Assessment',
    family: 'ThirdParty',
    type: ['Preventive'],
    principles: ['A','R','S'],
    description: 'The organization shall assess AI suppliers and vendors prior to engagement, evaluating their responsible AI practices, data handling, security posture, and compliance with applicable AI governance requirements.',
    evidence: [
      'Third-party AI supplier assessment questionnaires',
      'Supplier due diligence reports',
      'AI vendor risk ratings and approval records',
      'Supplier AI ethics and governance documentation reviewed'
    ],
    policies: [
      'AI Supplier Assessment Policy',
      'Third-Party AI Due Diligence Procedure',
      'AI Vendor Risk Rating Standard'
    ]
  },
  {
    id: 'A.10.3',
    name: 'Contractual Requirements for AI Suppliers',
    family: 'ThirdParty',
    type: ['Preventive'],
    principles: ['A','T'],
    description: 'The organization shall include appropriate AI governance, ethics, transparency, and compliance requirements in contracts with AI suppliers, including provisions for audit rights, incident notification, and data handling.',
    evidence: [
      'Contracts with AI-specific clauses reviewed and signed',
      'AI addenda or data processing agreements with vendors',
      'Audit rights provisions in AI supplier contracts',
      'Incident notification SLA records with AI vendors'
    ],
    policies: [
      'AI Supplier Contract Policy',
      'AI Procurement Standard',
      'Third-Party AI Contractual Requirements Checklist'
    ]
  },
  {
    id: 'A.10.4',
    name: 'Monitoring of Third-Party AI Systems',
    family: 'ThirdParty',
    type: ['Detective'],
    principles: ['A','R'],
    description: 'The organization shall monitor the performance, behavior, and compliance of third-party AI systems used in its operations, including periodic reviews and performance assessments against agreed criteria.',
    evidence: [
      'Third-party AI system monitoring reports',
      'Periodic supplier performance review records',
      'SLA compliance tracking for AI services',
      'Escalation records for third-party AI issues'
    ],
    policies: [
      'Third-Party AI Monitoring Policy',
      'AI Supplier Performance Review Procedure',
      'External AI System Oversight Standard'
    ]
  },
  {
    id: 'A.10.5',
    name: 'AI Supply Chain Transparency',
    family: 'ThirdParty',
    type: ['Preventive', 'Detective'],
    principles: ['T','A'],
    description: 'The organization shall maintain visibility into its AI supply chain, including pre-trained models, datasets, APIs, and components from external sources, and assess the risks and provenance of these components.',
    evidence: [
      'AI system component inventory including third-party components',
      'Pre-trained model provenance records',
      'Open source AI component risk assessments',
      'AI bill of materials (AI-BOM) for deployed systems'
    ],
    policies: [
      'AI Supply Chain Transparency Policy',
      'AI Component Inventory Standard',
      'Pre-trained Model Usage Policy'
    ]
  }
]);
