var CONTROLS = [];

// A.2 – Policies for AI (2 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.2.2',
    name: 'Policies for AI',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall establish, document, and communicate an AI policy that sets the direction for responsible AI development and use, aligned with the organization\'s objectives, values, and risk appetite.',
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
    name: 'Review of Policies for AI',
    family: 'Governance',
    type: ['Detective'],
    principles: ['A'],
    description: 'The organization shall review AI policies at planned intervals and whenever significant changes occur, ensuring policies remain suitable, adequate, and effective in light of evolving AI risks and technology.',
    evidence: [
      'Policy review schedule and records',
      'Minutes from AI policy review meetings',
      'Documented changes made during review cycles',
      'Sign-off records from top management on reviewed policies'
    ],
    policies: [
      'Policy Review and Update Procedure',
      'AI Policy Version Control Standard'
    ]
  }
]);

// A.3 – Internal Organization (2 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.3.2',
    name: 'AI Roles and Responsibilities',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['A'],
    description: 'The organization shall define and assign roles, responsibilities, and authorities for AI management, including AI system owners, data stewards, ethics reviewers, and operational oversight roles.',
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
    id: 'A.3.3',
    name: 'Reporting of Concerns Related to AI Systems',
    family: 'Governance',
    type: ['Detective', 'Corrective'],
    principles: ['A','T'],
    description: 'The organization shall establish a confidential process that enables employees, contractors, and other stakeholders to report ethical, safety, fairness, or compliance concerns related to AI systems without fear of retaliation.',
    evidence: [
      'Concerns reporting channel documentation and access records',
      'Whistleblowing and non-retaliation policy',
      'Log of AI-related concerns received and actions taken',
      'Communication to staff about the reporting channel'
    ],
    policies: [
      'AI Concerns Reporting Policy',
      'Whistleblowing and Non-Retaliation Policy',
      'AI Ethics Reporting Procedure'
    ]
  }
]);

// A.4 – Resources for AI Systems (3 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.4.2',
    name: 'Resources for AI Systems',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['A','R'],
    description: 'The organization shall identify and provide the resources needed for the establishment, implementation, maintenance, and continual improvement of the AI management system, including data, tools, infrastructure, and financial resources.',
    evidence: [
      'Resource planning and budget allocation records for AI',
      'Asset inventory of tools and platforms used in AI systems',
      'Resource adequacy assessments',
      'Resource allocation records per AI project'
    ],
    policies: [
      'AI Resource Management Policy',
      'AI Asset Inventory Standard',
      'AI Budget and Investment Policy'
    ]
  },
  {
    id: 'A.4.3',
    name: 'AI Awareness and Training',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['A','T'],
    description: 'The organization shall ensure that persons performing work under its control affecting AI systems are aware of responsible AI principles, their roles and responsibilities, and are competent through appropriate training and education.',
    evidence: [
      'AI awareness training completion records',
      'AI ethics and responsibility training materials',
      'Competency assessments for AI roles',
      'Training needs analysis for AI personnel'
    ],
    policies: [
      'AI Awareness and Training Policy',
      'AI Competence Development Procedure',
      'AI Ethics Training Standard'
    ]
  },
  {
    id: 'A.4.4',
    name: 'System and Computing Resources',
    family: 'Governance',
    type: ['Preventive'],
    principles: ['R','S'],
    description: 'The organization shall identify, provision, and manage system and computing resources required for AI systems, ensuring appropriate capacity, performance, security, and resilience of infrastructure used in AI development and operation.',
    evidence: [
      'Computing resource inventory and capacity plans',
      'Infrastructure specifications for AI workloads',
      'Resource utilization monitoring records',
      'Cloud or on-premises environment configuration records for AI'
    ],
    policies: [
      'AI Computing Infrastructure Policy',
      'AI Infrastructure Capacity Management Procedure',
      'AI Environment Security Standard'
    ]
  }
]);

// A.5 – Assessing Impacts of AI Systems (4 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.5.2',
    name: 'AI System Impact Assessment Process',
    family: 'Governance',
    type: ['Preventive', 'Detective'],
    principles: ['F','A','R','P'],
    description: 'The organization shall establish and implement a structured process for assessing the potential impacts of AI systems on individuals, groups, and society before deployment and at planned intervals, covering fairness, safety, privacy, and societal effects.',
    evidence: [
      'Completed AI Impact Assessment forms',
      'Bias and fairness evaluation reports',
      'Privacy Impact Assessment linked to AI system',
      'Impact assessment schedule and trigger criteria'
    ],
    policies: [
      'AI Impact Assessment Policy',
      'Algorithmic Fairness Assessment Procedure',
      'Privacy by Design for AI Policy'
    ]
  },
  {
    id: 'A.5.3',
    name: 'Documenting AI System Impacts',
    family: 'Governance',
    type: ['Detective'],
    principles: ['T','A'],
    description: 'The organization shall document the identified impacts of AI systems, including intended and unintended effects, affected populations, risk levels, and the measures taken to mitigate or address identified impacts.',
    evidence: [
      'Impact registers with documented findings per AI system',
      'Records of affected populations and impact severity ratings',
      'Mitigation measures documented against each identified impact',
      'Version history of impact assessment documentation'
    ],
    policies: [
      'AI Impact Documentation Standard',
      'Impact Register Maintenance Procedure'
    ]
  },
  {
    id: 'A.5.4',
    name: 'Assessing Impacts on AI Subjects',
    family: 'Governance',
    type: ['Preventive', 'Detective'],
    principles: ['F','P','A'],
    description: 'The organization shall assess the specific impacts of AI systems on the individuals or groups directly subject to AI-driven decisions or outputs, including impacts on rights, access to services, employment, and quality of life.',
    evidence: [
      'Individual and group impact assessments per AI system',
      'Stakeholder consultation records including affected communities',
      'Records of protected characteristic analysis',
      'AI system subject feedback and complaint records'
    ],
    policies: [
      'AI Subject Impact Assessment Policy',
      'Algorithmic Decision-Making Impact Procedure',
      'Protected Characteristics Assessment Standard'
    ]
  },
  {
    id: 'A.5.5',
    name: 'Assessing Societal Impacts of AI Systems',
    family: 'Governance',
    type: ['Preventive', 'Detective'],
    principles: ['F','T','A','R'],
    description: 'The organization shall assess and document the potential broader societal impacts of its AI systems throughout their life cycle, including effects on social structures, communities, the environment, the economy, and culture.',
    evidence: [
      'Societal impact assessment reports per AI system',
      'Environmental impact considerations for AI infrastructure',
      'Records of community and public interest consultations',
      'Horizon scanning and emerging societal risk reviews'
    ],
    policies: [
      'AI Societal Impact Assessment Policy',
      'Responsible AI Social Impact Procedure',
      'AI Environmental Impact Consideration Standard'
    ]
  }
]);

// A.6 – AI System Life Cycle (8 controls, three-level IDs per standard)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.6.1.2',
    name: 'Objectives for Responsible Development of AI Systems',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R','T'],
    description: 'The organization shall establish and document measurable objectives for the responsible development of AI systems, covering fairness, safety, explainability, privacy, and security, and shall integrate these objectives into the development process.',
    evidence: [
      'Documented responsible AI objectives per AI system or project',
      'Traceability matrix linking objectives to development activities',
      'Objective review records at project milestones',
      'Sign-off records from AI system owners on defined objectives'
    ],
    policies: [
      'Responsible AI Development Objectives Policy',
      'AI System Development Charter',
      'AI Ethics Objectives Setting Procedure'
    ]
  },
  {
    id: 'A.6.1.3',
    name: 'Processes for Responsible AI System Design and Development',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R','F'],
    description: 'The organization shall define and document processes for the responsible design and development of AI systems, incorporating ethics review gates, bias assessment checkpoints, and responsible AI principles at each stage of development.',
    evidence: [
      'Documented AI development process and methodology',
      'Ethics and fairness review gate checklists',
      'Development stage approval records',
      'Responsible AI design principles integrated in process documentation'
    ],
    policies: [
      'AI Development Process Policy',
      'Responsible AI Design Procedure',
      'AI Ethics Review Gate Standard'
    ]
  },
  {
    id: 'A.6.2.2',
    name: 'AI System Requirements and Specification',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['T','A','R'],
    description: 'The organization shall establish and document functional and non-functional requirements for AI systems, including performance metrics, fairness criteria, explainability needs, safety boundaries, and constraints on intended use.',
    evidence: [
      'AI system requirements specification documents',
      'Fairness and explainability requirements documentation',
      'Requirements traceability matrix',
      'Signed-off requirements from business and technical stakeholders'
    ],
    policies: [
      'AI Requirements Management Policy',
      'AI Acceptance Criteria Procedure',
      'AI Non-Functional Requirements Standard'
    ]
  },
  {
    id: 'A.6.2.3',
    name: 'Documentation of AI System Design and Development',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall create and maintain documentation of AI system design and development activities, including architecture decisions, algorithm choices, training procedures, and the rationale for design choices relevant to responsible AI objectives.',
    evidence: [
      'AI system architecture and design documents',
      'Algorithm selection rationale records',
      'Training procedure documentation',
      'Design decision logs with responsible AI rationale'
    ],
    policies: [
      'AI Design Documentation Policy',
      'AI System Design Record Standard',
      'Architecture Decision Record Procedure'
    ]
  },
  {
    id: 'A.6.2.4',
    name: 'AI System Verification and Validation',
    family: 'Lifecycle',
    type: ['Detective'],
    principles: ['R','F','A'],
    description: 'The organization shall test and validate AI systems against requirements, including functional correctness, performance benchmarks, fairness metrics, robustness to adversarial inputs, and safety behaviors prior to deployment.',
    evidence: [
      'Test plans and test case documentation',
      'Validation reports with pass/fail against acceptance criteria',
      'Adversarial and edge-case testing records',
      'Independent validation records separate from development team'
    ],
    policies: [
      'AI Testing and Validation Policy',
      'Model Validation Standard',
      'AI Acceptance Testing Procedure'
    ]
  },
  {
    id: 'A.6.2.5',
    name: 'AI System Deployment',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['A','R'],
    description: 'The organization shall control the deployment of AI systems through authorization gates, staged rollout plans, rollback procedures, and stakeholder communication, ensuring deployment requirements are met before release.',
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
    id: 'A.6.2.6',
    name: 'AI System Operation and Monitoring',
    family: 'Lifecycle',
    type: ['Detective'],
    principles: ['R','A','T'],
    description: 'The organization shall monitor AI systems in production for performance degradation, data drift, unexpected behaviors, and adverse impacts, with defined thresholds that trigger review or intervention by accountable parties.',
    evidence: [
      'Model monitoring dashboards and alert configurations',
      'Performance and data drift monitoring reports',
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
    id: 'A.6.2.7',
    name: 'AI System Technical Documentation',
    family: 'Lifecycle',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall maintain comprehensive technical documentation for each AI system throughout its lifecycle, including model cards, system specifications, known limitations, performance characteristics, and version history.',
    evidence: [
      'Model cards or AI system datasheets per deployed system',
      'Version-controlled technical documentation repository',
      'Known limitations and failure mode records',
      'Documentation update records aligned to system changes'
    ],
    policies: [
      'AI Technical Documentation Policy',
      'Model Card Standard',
      'AI System Datasheet Procedure'
    ]
  }
]);

// A.7 – Data for AI Systems (5 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.7.2',
    name: 'Data for Development of AI Systems',
    family: 'Data',
    type: ['Preventive'],
    principles: ['F','R','P'],
    description: 'The organization shall define the categories, sources, and characteristics of data required for AI system development, ensuring data is fit for purpose, representative of the deployment context, and compliant with applicable legal and ethical requirements.',
    evidence: [
      'Data requirements specification for each AI system',
      'Data source inventory with characteristics and provenance',
      'Representativeness analysis for training datasets',
      'Data compliance checklist (copyright, consent, licensing)'
    ],
    policies: [
      'AI Training Data Policy',
      'Data Requirements Specification Standard',
      'Training Data Governance Policy'
    ]
  },
  {
    id: 'A.7.3',
    name: 'Acquisition of Data for AI Systems',
    family: 'Data',
    type: ['Preventive'],
    principles: ['P','A','F'],
    description: 'The organization shall manage the acquisition of data for AI systems, including consent management, data licensing, collection procedures, and ensuring lawful basis for use of personal data in training and operation.',
    evidence: [
      'Data acquisition procedures and records',
      'Consent and licensing records for acquired datasets',
      'Data acquisition approval records',
      'Data labeling guidelines and quality assurance records'
    ],
    policies: [
      'AI Data Acquisition Policy',
      'Data Consent and Licensing Procedure',
      'Personal Data in AI Training Standard'
    ]
  },
  {
    id: 'A.7.4',
    name: 'Quality of Data for AI Systems',
    family: 'Data',
    type: ['Preventive', 'Detective'],
    principles: ['R','F'],
    description: 'The organization shall define and monitor data quality criteria relevant to AI systems, including accuracy, completeness, timeliness, consistency, and representativeness, and shall implement measures to address quality deficiencies.',
    evidence: [
      'Data quality metrics and KPIs for AI datasets',
      'Data profiling and quality assessment reports',
      'Data quality remediation records',
      'Automated data quality check logs in pipelines'
    ],
    policies: [
      'AI Data Quality Policy',
      'Data Quality Assessment Procedure',
      'Training Data Quality Standard'
    ]
  },
  {
    id: 'A.7.5',
    name: 'Data Provenance',
    family: 'Data',
    type: ['Preventive', 'Detective'],
    principles: ['T','A'],
    description: 'The organization shall track and document the provenance of data used in AI systems, enabling traceability from the original source through all transformations to model training and operation, supporting auditability and accountability.',
    evidence: [
      'Data lineage diagrams for AI pipelines',
      'Metadata records showing data origin and transformations',
      'Data provenance tracking tool outputs',
      'Audit trails for data pipeline modifications'
    ],
    policies: [
      'Data Lineage and Provenance Policy',
      'AI Data Traceability Standard',
      'Metadata Management Procedure'
    ]
  },
  {
    id: 'A.7.6',
    name: 'Data Preparation',
    family: 'Data',
    type: ['Preventive'],
    principles: ['F','R','P'],
    description: 'The organization shall implement controlled processes for preparing data for AI systems, including cleaning, transformation, labeling, anonymization, and bias mitigation, with documentation of all steps applied.',
    evidence: [
      'Data preparation pipeline documentation',
      'Anonymization and pseudonymization records',
      'Data labeling procedures and inter-annotator reliability records',
      'Bias assessment and mitigation records applied during preparation'
    ],
    policies: [
      'AI Data Preparation Policy',
      'Data Anonymization Standard',
      'Training Data Bias Mitigation Procedure'
    ]
  }
]);

// A.8 – Information for Interested Parties of AI Systems (4 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.8.2',
    name: 'Information for Users of AI Systems',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall provide users of AI systems with clear, accessible documentation and information covering the system\'s capabilities, limitations, intended use, instructions for safe use, and how to obtain support or raise concerns.',
    evidence: [
      'User guides and help documentation for AI systems',
      'AI system capability and limitation disclosures',
      'Warnings for out-of-scope use cases',
      'User comprehension testing records'
    ],
    policies: [
      'AI User Information Policy',
      'AI User Documentation Standard',
      'AI Limitations Disclosure Procedure'
    ]
  },
  {
    id: 'A.8.3',
    name: 'External Reporting on AI Systems',
    family: 'Transparency',
    type: ['Detective', 'Corrective'],
    principles: ['T','A'],
    description: 'The organization shall establish mechanisms for external parties to report adverse impacts, safety concerns, or other issues related to AI systems, and shall define processes for receiving, reviewing, and acting on such reports.',
    evidence: [
      'External reporting channel documentation and accessibility records',
      'External concern and adverse impact register',
      'Records of reviewed and acted-upon external reports',
      'Response time SLA records for external AI reports'
    ],
    policies: [
      'AI External Reporting Policy',
      'AI Adverse Impact Reporting Procedure',
      'External Feedback Handling Standard'
    ]
  },
  {
    id: 'A.8.4',
    name: 'Communication of AI System Incidents',
    family: 'Transparency',
    type: ['Corrective'],
    principles: ['T','A'],
    description: 'The organization shall communicate AI system incidents, failures, and significant performance issues to affected users and relevant stakeholders in a timely, transparent, and clear manner, including the nature of the issue and actions taken.',
    evidence: [
      'AI incident communication templates and procedures',
      'Records of incident notifications sent to users/stakeholders',
      'Post-incident communication review records',
      'Incident notification SLA tracking records'
    ],
    policies: [
      'AI Incident Communication Policy',
      'AI Incident Notification Procedure',
      'Stakeholder Communication Standard for AI Incidents'
    ]
  },
  {
    id: 'A.8.5',
    name: 'Information for Interested Parties',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall determine and document its obligations to provide information about AI systems to interested parties such as regulators, auditors, partners, and the public, and shall fulfil those obligations in a timely manner.',
    evidence: [
      'Stakeholder register identifying interested parties for AI',
      'Information disclosure obligations matrix',
      'Records of regulatory and compliance disclosures',
      'Published transparency reports or AI use disclosures'
    ],
    policies: [
      'AI Transparency Disclosure Policy',
      'Interested Parties Information Obligations Register',
      'AI Regulatory Reporting Procedure'
    ]
  }
]);

// A.9 – Use of AI Systems (3 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.9.2',
    name: 'Processes for Responsible Use of AI Systems',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['A','T','R'],
    description: 'The organization shall establish and document processes governing the responsible use of AI systems, including required approvals before use, defined use case boundaries, human oversight requirements, and procedures for identifying and escalating misuse.',
    evidence: [
      'AI use authorization and approval records',
      'Use case scope documentation per AI system',
      'Human oversight process documentation',
      'AI misuse detection and escalation procedures'
    ],
    policies: [
      'AI Responsible Use Policy',
      'AI Use Authorization Procedure',
      'AI Acceptable Use Standard'
    ]
  },
  {
    id: 'A.9.3',
    name: 'Objectives for Responsible Use of AI Systems',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['A','T','F'],
    description: 'The organization shall establish measurable objectives for the responsible use of AI systems, encompassing fairness, accountability, transparency, explainability, reliability, safety, robustness, privacy, security, and accessibility.',
    evidence: [
      'Documented responsible use objectives per AI system',
      'KPIs and metrics tracking responsible use objective achievement',
      'Responsible use objective review records',
      'Alignment evidence between use objectives and organizational AI policy'
    ],
    policies: [
      'Responsible AI Use Objectives Policy',
      'AI KPI and Metrics Framework',
      'Responsible Use Objectives Review Procedure'
    ]
  },
  {
    id: 'A.9.4',
    name: 'Intended Use of AI Systems',
    family: 'Transparency',
    type: ['Preventive'],
    principles: ['T','A'],
    description: 'The organization shall ensure that AI systems are used only in accordance with their documented intended purpose, and shall implement controls to prevent or detect use outside the defined scope, including misuse, abuse, or unanticipated applications.',
    evidence: [
      'Intended use specification documents per AI system',
      'Prohibited use cases documentation',
      'Access controls limiting use to authorized contexts',
      'Monitoring records for out-of-scope use detection'
    ],
    policies: [
      'AI Intended Use Policy',
      'AI Use Case Authorization Standard',
      'AI Misuse Prevention Procedure'
    ]
  }
]);

// A.10 – Third-Party and Customer Relationships (3 controls)
CONTROLS.push.apply(CONTROLS, [
  {
    id: 'A.10.2',
    name: 'Allocating AI Responsibilities with Third Parties',
    family: 'ThirdParty',
    type: ['Preventive'],
    principles: ['A'],
    description: 'The organization shall clearly define and document the allocation of responsibilities for responsible AI between itself and third parties — including suppliers, partners, and customers — covering model performance, data rights, bias management, and incident response.',
    evidence: [
      'Responsibility allocation matrices for AI systems involving third parties',
      'Contracts with explicit AI responsibility clauses',
      'Third-party AI responsibility sign-off records',
      'Escalation and accountability documentation for shared AI systems'
    ],
    policies: [
      'AI Third-Party Responsibility Policy',
      'AI Responsibility Allocation Framework',
      'Shared AI System Governance Procedure'
    ]
  },
  {
    id: 'A.10.3',
    name: 'Suppliers of AI Systems and Components',
    family: 'ThirdParty',
    type: ['Preventive', 'Detective'],
    principles: ['A','R','S'],
    description: 'The organization shall establish processes for selecting, assessing, and monitoring AI system suppliers and vendors, evaluating their responsible AI practices, security posture, data handling, and contractual commitments to AI governance requirements.',
    evidence: [
      'Third-party AI supplier assessment questionnaires and results',
      'Supplier due diligence reports',
      'AI vendor contracts with responsible AI clauses and audit rights',
      'Periodic supplier performance review records'
    ],
    policies: [
      'AI Supplier Assessment and Management Policy',
      'Third-Party AI Due Diligence Procedure',
      'AI Supplier Contract Requirements Standard'
    ]
  },
  {
    id: 'A.10.4',
    name: 'Customers of AI Systems',
    family: 'ThirdParty',
    type: ['Preventive'],
    principles: ['T','A','F'],
    description: 'The organization shall ensure that its responsible approach to the development and use of AI systems considers customer expectations and needs, including transparency about AI capabilities and limitations, and providing customers with the information they need to use AI systems safely and responsibly.',
    evidence: [
      'Customer communication records on AI system capabilities and limitations',
      'Customer feedback mechanisms for AI systems',
      'Customer-facing AI documentation and disclosures',
      'Records of customer AI concerns addressed'
    ],
    policies: [
      'AI Customer Communication Policy',
      'Customer AI Transparency Procedure',
      'AI Customer Support and Feedback Standard'
    ]
  }
]);
