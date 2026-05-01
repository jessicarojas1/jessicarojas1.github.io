var TIPS = {};

Object.assign(TIPS, {
  'A.2.2': {
    tips: [
      'Align the AI policy with existing information security and data governance policies to create a coherent governance framework.',
      'Define specific commitments on responsible AI principles (fairness, transparency, accountability) in the policy text rather than generic statements.',
      'Include a review cycle of at least annually or after major AI strategy changes.',
      'Ensure top management visibly endorses the policy — a CEO/CTO signature carries audit weight.'
    ],
    pitfalls: [
      'Publishing a policy without communicating it to all relevant staff leaves a gap auditors will find.',
      'Generic "we support responsible AI" language without measurable commitments fails to demonstrate control effectiveness.'
    ]
  },
  'A.2.3': {
    tips: [
      'Create an AI governance committee or council with cross-functional representation (legal, IT, HR, ethics, business).',
      'Define specific accountability for AI risk owners — not just the IT team but also business process owners.',
      'Document the escalation path: when does an AI issue go to the AI owner vs. CISO vs. board?',
      'Review and update roles whenever new AI systems are deployed or organizational structure changes.'
    ],
    pitfalls: [
      'Assigning AI responsibilities only to IT ignores the business accountability aspect that auditors look for.',
      'Leaving role assignments undocumented means relying on tribal knowledge that can disappear with personnel changes.'
    ]
  },
  'A.3.2': {
    tips: [
      'Use a risk taxonomy specific to AI: include bias risk, model drift risk, adversarial attack risk, and societal harm risk alongside traditional IT risks.',
      'Conduct risk assessment before procurement or development begins, not just before go-live.',
      'Risk acceptance should be explicit and signed — implicit acceptance is not acceptable evidence.',
      'Link AI risks to your enterprise risk register to ensure visibility at the board level.'
    ],
    pitfalls: [
      'Copying a generic IT risk assessment template without adapting it for AI-specific risks will not satisfy ISO 42001 auditors.',
      'Failing to reassess risks after significant model updates or data changes is a common nonconformity.'
    ]
  },
  'A.4.2': {
    tips: [
      'Document not only what the system is intended to do, but explicitly what it is NOT intended for — prohibiting misuse is as important as defining use.',
      'Include operational context: who uses the system, in what environment, and what decisions it informs or automates.',
      'Get sign-off from business owners, not just developers, on the intended use specification.',
      'Review the intended use specification whenever the business context changes significantly.'
    ],
    pitfalls: [
      'Vague intended use descriptions like "support decision making" do not provide adequate boundary-setting for audit purposes.',
      'Forgetting to document constraints and limitations (accuracy thresholds, edge cases) is a frequent gap.'
    ]
  },
  'A.4.3': {
    tips: [
      'Treat the AI Impact Assessment as a living document — schedule periodic reassessments, not just a one-off exercise.',
      'Include diverse stakeholder perspectives in the assessment, including potentially affected communities.',
      'Link the AIIA to the Data Protection Impact Assessment (DPIA) where personal data is involved.',
      'Use structured frameworks (e.g., NIST AI RMF, ALTAI self-assessment) to ensure consistency.'
    ],
    pitfalls: [
      'Conducting the AIIA after deployment rather than before is a significant process gap.',
      'Failing to reassess after model retraining or deployment to new use cases leaves residual unassessed risks.'
    ]
  },
  'A.5.2': {
    tips: [
      'Develop AI-specific audit checklists that cover model performance, bias, data quality, documentation completeness, and incident records.',
      'Ensure auditors are independent from the AI teams they are auditing.',
      'Include technical AI auditing skills in your internal audit team or supplement with external expertise.',
      'Audit frequency should be risk-based — high-risk AI systems warrant more frequent audits.'
    ],
    pitfalls: [
      'Reusing a generic IT audit checklist without AI-specific questions will miss critical AI governance gaps.',
      'Scheduling audits too infrequently (e.g., every three years) may not meet "planned intervals" expectations for active AI systems.'
    ]
  },
  'A.5.3': {
    tips: [
      'Prepare structured management review inputs: AI performance dashboard, incident summary, risk register updates, audit findings, and stakeholder feedback.',
      'Record specific decisions and action items from the review — minutes should show what was decided, not just what was discussed.',
      'Top management attendance should be verifiable through sign-in sheets or meeting records.',
      'Use the review to reassess whether the AI management system objectives are still aligned with organizational strategy.'
    ],
    pitfalls: [
      'Conducting reviews without genuine top management participation (delegating entirely to mid-level staff) does not satisfy the intent of this control.',
      'Management review that discusses AI performance but takes no action items demonstrates a lack of continual improvement mindset.'
    ]
  },
  'A.5.4': {
    tips: [
      'Define incident categories specific to AI: model failure, unexpected output, bias event, misuse, data poisoning, and adverse impact on individual.',
      'Establish clear severity levels and response timeframes for each AI incident category.',
      'Include root cause analysis as a mandatory step for significant incidents — this feeds continual improvement.',
      'Test the incident response process with tabletop exercises for realistic AI failure scenarios.'
    ],
    pitfalls: [
      'Treating AI incidents the same as generic IT incidents misses the unique aspects of AI failures.',
      'Incomplete post-incident reviews that document the what but not the why will not prevent recurrence.'
    ]
  }
});

Object.assign(TIPS, {
  'A.6.1': {
    tips: [
      'Build quality and ethics review gates into the AI development lifecycle, not just technical performance gates.',
      'Establish a model governance board or review committee to approve progression through lifecycle stages.',
      'Define clear entry and exit criteria for each development stage.',
      'Track all AI projects in a centralized AI project register for management visibility.'
    ],
    pitfalls: [
      'Treating AI development like traditional software development without AI-specific checkpoints misses key governance requirements.',
      'Poorly defined responsibilities between data science, IT, and business teams create accountability gaps.'
    ]
  },
  'A.6.2': {
    tips: [
      'Include fairness metrics as formal requirements — specify what disparity thresholds are acceptable.',
      'Define explainability requirements based on stakeholder needs and regulatory context.',
      'Involve end users and affected communities in requirements definition where feasible.',
      'Create a requirements traceability matrix linking requirements to test cases and controls.'
    ],
    pitfalls: [
      'Setting only technical performance requirements (accuracy, F1 score) and omitting fairness, explainability, or safety requirements is a common gap.',
      'Requirements that are too vague to test (e.g., "the system should be fair") cannot be validated.'
    ]
  },
  'A.6.3': {
    tips: [
      'Maintain a data acquisition log documenting source, date, quantity, consent basis, and any preprocessing applied.',
      'Conduct representativeness analysis to ensure training data reflects the deployment population.',
      'Validate data labeling quality with inter-annotator agreement metrics.',
      'Check for sensitive attribute encoding that could introduce proxy discrimination.'
    ],
    pitfalls: [
      'Using publicly scraped data without assessing consent, copyright, or bias implications is a significant risk.',
      'Skipping representativeness analysis leads to models that perform poorly on underrepresented groups.'
    ]
  },
  'A.6.4': {
    tips: [
      'Use experiment tracking tools (MLflow, Weights & Biases) to maintain a complete record of training runs.',
      'Document the rationale for algorithm and architecture choices, not just the configuration.',
      'Perform bias evaluation at multiple points during training, not just on the final model.',
      'Store model artifacts and training code in version-controlled repositories.'
    ],
    pitfalls: [
      'Undocumented training runs make it impossible to reproduce results or explain decisions to auditors.',
      'Only evaluating bias on overall aggregate metrics can mask disparate performance on demographic subgroups.'
    ]
  },
  'A.6.5': {
    tips: [
      'Conduct independent validation — the team that tests should not be the team that trained the model.',
      'Include robustness testing: how does the model perform on noisy, corrupted, or adversarial inputs?',
      'Test on held-out data representative of the actual deployment population, not just the training distribution.',
      'Document all test failures and how they were resolved before proceeding to deployment.'
    ],
    pitfalls: [
      'Testing only on the same data distribution as training gives overly optimistic performance estimates.',
      'Treating validation as a checkbox rather than a genuine quality gate undermines the entire process.'
    ]
  },
  'A.6.6': {
    tips: [
      'Use a canary or staged rollout approach for high-risk AI systems — deploy to a small subset before full release.',
      'Require a deployment authorization sign-off from both technical and business owners.',
      'Test rollback procedures before go-live, not just document them.',
      'Communicate changes to affected users and stakeholders before deployment.'
    ],
    pitfalls: [
      'Deploying without a tested rollback plan leaves the organization exposed if the system behaves unexpectedly.',
      'Skipping deployment authorization gates under time pressure undermines governance controls.'
    ]
  },
  'A.6.7': {
    tips: [
      'Monitor for data drift and concept drift, not just model performance metrics — these are early warning signals.',
      'Set automated alerts for performance degradation beyond defined thresholds.',
      'Schedule regular human review of monitoring dashboards — automated alerts are not sufficient alone.',
      'Log all monitoring decisions and any actions taken in response to alerts.'
    ],
    pitfalls: [
      'Monitoring only aggregate performance metrics can mask degraded performance on specific subgroups or edge cases.',
      'Treating monitoring as a one-time post-deployment task rather than a continuous operational responsibility.'
    ]
  },
  'A.6.8': {
    tips: [
      'Treat model updates and retraining as significant changes requiring impact assessment, not routine maintenance.',
      'Re-run bias and fairness evaluations after any model update, even minor ones.',
      'Use feature flags or model versioning to enable rapid rollback if a change causes issues.',
      'Document the business rationale for each AI system change alongside the technical details.'
    ],
    pitfalls: [
      'Applying IT change management processes to AI systems without adapting for AI-specific risks misses critical considerations.',
      'Treating model retraining as "no change" when the training data or algorithm has changed is a documentation gap.'
    ]
  },
  'A.6.9': {
    tips: [
      'Create a decommissioning checklist specific to AI: model deletion, training data disposition, API retirement, documentation archival.',
      'Consider the impact on downstream users and systems that depend on the AI system.',
      'Retain sufficient documentation for regulatory or audit purposes even after the system is decommissioned.',
      'Delete or anonymize personal data used in the system in accordance with data retention policies.'
    ],
    pitfalls: [
      'Decommissioning AI systems without addressing the underlying data creates data retention and privacy compliance risks.',
      'Failing to communicate decommissioning to users and stakeholders can cause downstream failures.'
    ]
  }
});

Object.assign(TIPS, {
  'A.7.2': {
    tips: [
      'Establish clear data ownership for every dataset used in AI — the business unit that creates the data should own it.',
      'Implement a data catalog that supports AI use cases, including lineage tracking and quality metrics.',
      'Define data classification levels specific to AI risk (e.g., training data with personal information, synthetic data, public data).',
      'Include AI-specific data governance in your overall data governance framework rather than treating it as separate.'
    ],
    pitfalls: [
      'Relying on IT to own all AI data without business accountability creates gaps in governance.',
      'Undocumented data assets make it impossible to conduct accurate bias or quality assessments.'
    ]
  },
  'A.7.3': {
    tips: [
      'Define minimum quality thresholds before data is eligible for use in AI training.',
      'Automate data quality checks in the data pipeline to catch issues early.',
      'Assess representativeness as a quality dimension — not just accuracy and completeness.',
      'Conduct quality assessments on operational/inference data as well as training data.'
    ],
    pitfalls: [
      'Focusing quality checks only on structured data completeness misses semantic and representativeness quality issues.',
      'Failing to monitor operational data quality can cause model performance degradation without obvious failure signals.'
    ]
  },
  'A.7.4': {
    tips: [
      'Apply privacy by design principles from the start of AI system design.',
      'Use synthetic data or federated learning where possible to reduce privacy exposure.',
      'Ensure training data consent covers the specific AI use purpose.',
      'Link AI privacy controls to your GDPR/CCPA compliance framework for consistency.'
    ],
    pitfalls: [
      'Assuming that anonymized data is fully de-identified without testing for re-identification risk is a privacy gap.',
      'Using data for AI purposes beyond the original consent basis is a frequent compliance failure.'
    ]
  },
  'A.7.5': {
    tips: [
      'Implement data lineage tooling (e.g., Apache Atlas, DataHub, dbt lineage) to automate provenance tracking.',
      'Document all transformations applied to data before use in training.',
      'Maintain provenance records for pre-trained models including the datasets they were trained on.',
      'Use data versioning alongside code versioning to enable full experiment reproducibility.'
    ],
    pitfalls: [
      'Manual lineage documentation quickly becomes outdated and unreliable — automate where possible.',
      'Ignoring provenance for externally sourced training data creates significant audit gaps.'
    ]
  },
  'A.7.6': {
    tips: [
      'Apply strict access controls to training datasets — only authorized roles should be able to read or modify them.',
      'Implement integrity checksums for training datasets to detect tampering.',
      'Assess AI data pipelines for injection and poisoning attack surfaces.',
      'Encrypt sensitive training datasets at rest and in transit.'
    ],
    pitfalls: [
      'Treating training data with less security rigor than production data is a common mistake — training data compromise can be as damaging.',
      'Not monitoring for unauthorized access attempts on AI data stores leaves the door open for data poisoning.'
    ]
  },
  'A.7.7': {
    tips: [
      'Use multiple bias metrics — no single metric captures all forms of bias; use demographic parity, equalized odds, and calibration together.',
      'Involve domain experts and affected community representatives in bias assessment, not just data scientists.',
      'Document accepted residual bias levels and the rationale for acceptance.',
      'Rerun bias assessments after any significant data or model change.'
    ],
    pitfalls: [
      'Declaring data "unbiased" based on aggregate metrics without subgroup analysis misses disparate impacts.',
      'Bias assessment conducted only once at development without ongoing monitoring is insufficient.'
    ]
  }
});

Object.assign(TIPS, {
  'A.8.2': {
    tips: [
      'Use model cards as a standardized format for AI system documentation — they are widely recognized and auditor-friendly.',
      'Include performance characteristics broken down by subgroup, not just overall aggregate metrics.',
      'Document known failure modes and edge cases explicitly — auditors look for honest limitation disclosure.',
      'Keep documentation version-controlled and linked to specific model versions.'
    ],
    pitfalls: [
      'Documentation that describes what the system should do rather than what it actually does will fail an audit.',
      'Undocumented or poorly documented limitations create liability and audit nonconformities.'
    ]
  },
  'A.8.3': {
    tips: [
      'Match the level of explainability to the stakes of the decision — high-stakes decisions require richer explanations.',
      'Distinguish between global explainability (how the model generally works) and local explainability (why this specific decision).',
      'Evaluate whether explanations are actually useful to the target audience — technical explanations may not serve end users.',
      'Document the explainability methods used and their limitations.'
    ],
    pitfalls: [
      'Providing post-hoc explanations that don\'t accurately reflect the model\'s actual reasoning process is both misleading and legally risky.',
      'Assuming black-box models are unexplainable without exploring available explainability techniques is a missed opportunity.'
    ]
  },
  'A.8.4': {
    tips: [
      'Use clear, plain-language disclosures that users can understand — avoid technical jargon.',
      'For automated decision-making, inform users at the point of decision, not buried in terms and conditions.',
      'Ensure chatbots and virtual assistants identify themselves as AI at the start of every interaction.',
      'Maintain records of disclosure implementations to demonstrate compliance.'
    ],
    pitfalls: [
      'Burying AI disclosure in long privacy policies or terms of service does not constitute meaningful transparency.',
      'Failing to update disclosures when AI capabilities change significantly leaves users misinformed.'
    ]
  },
  'A.8.5': {
    tips: [
      'Define explicit thresholds at which human review is mandatory, not just optional.',
      'Ensure human reviewers have sufficient information and time to make meaningful oversight decisions.',
      'Test override procedures regularly — they must work in practice, not just on paper.',
      'Document when human oversight is bypassed and require justification for each bypass.'
    ],
    pitfalls: [
      'Nominal human oversight where humans rubber-stamp AI decisions without genuine review does not meet the intent of this control.',
      'Failing to train human overseers on what to look for in AI outputs renders oversight ineffective.'
    ]
  },
  'A.9.2': {
    tips: [
      'Tailor user information to the specific audience — technical users need different guidance than general public users.',
      'Include explicit guidance on what the AI system should NOT be used for.',
      'Test user documentation with actual users before release to ensure comprehension.',
      'Update user information whenever AI capabilities or limitations change.'
    ],
    pitfalls: [
      'Documentation written by developers for developers rather than for the actual user audience is a common gap.',
      'Omitting limitation disclosures to avoid deterring adoption creates both ethical and legal risks.'
    ]
  },
  'A.9.3': {
    tips: [
      'Make feedback channels prominently accessible, not buried in settings menus.',
      'Establish clear SLAs for responding to AI-related complaints and appeals.',
      'Track feedback trends to identify systemic issues for continual improvement.',
      'For significant automated decisions, provide a meaningful right to explanation and human review.'
    ],
    pitfalls: [
      'A feedback form that collects input but has no documented review or response process does not satisfy this control.',
      'Treating AI complaints as generic customer service issues without AI-specific triage misses important signals.'
    ]
  },
  'A.9.4': {
    tips: [
      'Determine what inputs and outputs need to be logged based on the risk level and the need for future audit.',
      'Balance logging completeness against privacy — avoid logging unnecessary personal data.',
      'Ensure logs are tamper-evident and access-controlled.',
      'Define and enforce a log retention period aligned with regulatory requirements and expected audit cycles.'
    ],
    pitfalls: [
      'Logging outputs without logging inputs makes it impossible to reconstruct or explain decisions.',
      'Storing logs in formats or systems that make retrieval impractical for audit purposes renders them effectively useless.'
    ]
  }
});

Object.assign(TIPS, {
  'A.10.2': {
    tips: [
      'Develop an AI-specific supplier questionnaire covering responsible AI practices, bias management, model documentation, and incident handling.',
      'Tier your AI suppliers by risk: a critical AI vendor in a high-stakes process needs deeper due diligence than a low-risk tool.',
      'Review publicly available information about AI vendors: published AI ethics policies, past incidents, regulatory actions.',
      'Reassess suppliers periodically and after any significant incidents or changes in vendor practices.'
    ],
    pitfalls: [
      'Using a generic IT vendor assessment without AI-specific questions fails to identify AI governance gaps.',
      'Completing due diligence only at initial onboarding without periodic reassessment is a common oversight.'
    ]
  },
  'A.10.3': {
    tips: [
      'Work with legal to develop standard AI contract clauses covering bias testing, explainability, incident notification, and audit rights.',
      'Include data processing agreements for any personal data handled by AI suppliers.',
      'Define performance SLAs for AI systems including fairness and accuracy thresholds.',
      'Ensure contracts cover what happens to data when the relationship ends — deletion and certification requirements.'
    ],
    pitfalls: [
      'Relying on vendor standard contracts without negotiating AI-specific terms leaves critical governance gaps.',
      'Omitting audit rights from AI vendor contracts makes it impossible to verify compliance claims.'
    ]
  },
  'A.10.4': {
    tips: [
      'Monitor third-party AI APIs and services for performance changes — vendors can update their models without notice.',
      'Establish clear escalation paths when third-party AI performance degrades below thresholds.',
      'Conduct periodic review meetings with significant AI suppliers to discuss performance and upcoming changes.',
      'Test third-party AI systems for bias and fairness, not just technical performance, on a regular basis.'
    ],
    pitfalls: [
      'Assuming third-party AI systems continue to perform as validated without ongoing monitoring is a significant governance gap.',
      'Receiving vendor model update notifications without triggering internal impact assessment and re-validation is a process gap.'
    ]
  },
  'A.10.5': {
    tips: [
      'Maintain an AI Bill of Materials (AI-BOM) for each deployed system: list all pre-trained models, libraries, datasets, and APIs used.',
      'Assess the provenance and licensing of open-source AI models before use.',
      'Monitor for security vulnerabilities in AI components and dependencies.',
      'When acquiring pre-trained models, request documentation about training data, known biases, and intended use.'
    ],
    pitfalls: [
      'Using foundation models or pre-trained components without understanding their training data provenance creates unassessed bias and legal risks.',
      'Failing to maintain an up-to-date component inventory makes it impossible to respond effectively to supply chain vulnerabilities.'
    ]
  }
});
