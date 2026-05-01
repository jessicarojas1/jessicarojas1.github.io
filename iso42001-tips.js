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
      'Schedule policy reviews triggered by both time (annually) and events (new AI regulation, major incident, new AI deployment).',
      'Document what was reviewed, what changed, and why — a review that results in "no changes" still needs a record.',
      'Involve legal, ethics, and technical stakeholders in the review, not just governance teams.',
      'Cross-reference policy reviews with changes in the regulatory landscape (EU AI Act updates, national AI strategies).'
    ],
    pitfalls: [
      'Treating policy review as a rubber-stamp exercise without genuinely assessing continued adequacy will fail audit.',
      'Failing to update the policy version number and distribution list after changes leaves confusion about which version is current.'
    ]
  },
  'A.3.2': {
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
  'A.3.3': {
    tips: [
      'Make the reporting channel genuinely confidential and anonymous where possible — staff will not report concerns if they fear retaliation.',
      'Communicate the reporting channel proactively at onboarding, in AI training, and in the AI policy.',
      'Define a clear triage process: who receives reports, how quickly they are acknowledged, and how outcomes are communicated back.',
      'Track and report on concern trends to leadership as part of management review — patterns reveal systemic issues.'
    ],
    pitfalls: [
      'A reporting channel that exists on paper but has no clear owner or response process will not be used and will not satisfy auditors.',
      'Failing to protect reporters from retaliation undermines the control entirely and creates legal liability.'
    ]
  }
});

Object.assign(TIPS, {
  'A.4.2': {
    tips: [
      'Maintain a comprehensive inventory of all resources used across AI systems — data, platforms, tools, APIs, and personnel.',
      'Include resource adequacy assessments as part of AI project planning, not just financial budgeting.',
      'Plan for resource scalability — AI workloads can grow significantly during training or high-traffic inference.',
      'Link resource planning to AI risk levels: higher-risk AI systems justify higher resource investment in governance and tooling.'
    ],
    pitfalls: [
      'Underestimating computational resource needs for model training leads to project delays and cost overruns.',
      'Failing to include governance tooling (bias detection, monitoring platforms) in resource planning leaves gaps in control effectiveness.'
    ]
  },
  'A.4.3': {
    tips: [
      'Develop role-specific AI training: executives need AI literacy and ethics awareness, developers need technical ethics and bias mitigation, end users need responsible use training.',
      'Use competency assessments to verify understanding, not just training completion records.',
      'Refresh training when AI technology, regulations, or the organization\'s AI systems change significantly.',
      'Include AI ethics scenarios and case studies in training to make principles concrete and actionable.'
    ],
    pitfalls: [
      'One-size-fits-all AI training that does not address role-specific responsibilities is ineffective and lacks audit evidence of competence.',
      'Training records showing completion but no competency assessment leave open the question of whether learning occurred.'
    ]
  },
  'A.4.4': {
    tips: [
      'Document minimum hardware specifications and capacity thresholds for each AI system environment.',
      'Implement infrastructure monitoring specifically for AI workloads — GPU utilization, memory pressure, latency.',
      'Apply security hardening to computing environments used for AI training — they are high-value targets.',
      'Plan for GPU/TPU resource contention when multiple AI projects are running simultaneously.'
    ],
    pitfalls: [
      'Treating AI computing infrastructure as generic IT infrastructure misses AI-specific security and performance considerations.',
      'Failing to secure training environments is as serious as failing to secure production — training data compromise can poison models.'
    ]
  },
  'A.5.2': {
    tips: [
      'Treat the AI Impact Assessment as a living document — schedule periodic reassessments, not just a one-off exercise.',
      'Include diverse stakeholder perspectives in the assessment, including potentially affected communities.',
      'Link the AIIA to the Data Protection Impact Assessment (DPIA) where personal data is involved.',
      'Use structured frameworks (e.g., NIST AI RMF, ALTAI self-assessment) to ensure consistency across assessments.'
    ],
    pitfalls: [
      'Conducting the AIIA after deployment rather than before is a significant process gap.',
      'Failing to reassess after model retraining or deployment to new use cases leaves residual unassessed risks.'
    ]
  },
  'A.5.3': {
    tips: [
      'Maintain an impact register that records each identified impact, its severity, affected populations, and mitigations applied.',
      'Use version control for impact assessment documents so auditors can trace changes over time.',
      'Document both positive and negative impacts — regulators and auditors look for balanced, honest documentation.',
      'Link each documented impact to the control or mitigation that addresses it for traceability.'
    ],
    pitfalls: [
      'Impact assessments that document only positive outcomes or understate negative impacts will fail independent audit scrutiny.',
      'Undated documentation without version history makes it impossible to demonstrate when assessments were conducted.'
    ]
  },
  'A.5.4': {
    tips: [
      'Identify all categories of individuals who may be directly subject to AI decisions (customers, employees, benefit applicants, etc.).',
      'Conduct subgroup analysis to assess disparate impacts on protected characteristics, not just aggregate population analysis.',
      'Consult with representatives of affected communities where feasible, particularly for high-stakes AI systems.',
      'Document the rights of AI subjects (right to explanation, right to contest) and how the system supports them.'
    ],
    pitfalls: [
      'Assessing only aggregate population impacts without subgroup analysis misses disparate effects on protected groups.',
      'Ignoring indirect subjects who may be affected by decisions about others they interact with.'
    ]
  },
  'A.5.5': {
    tips: [
      'Consider societal impacts across multiple dimensions: economic (job displacement), social (discrimination, accessibility), environmental (energy use), and democratic (misinformation).',
      'Engage external experts — ethicists, social scientists, civil society organisations — in societal impact assessments for high-risk systems.',
      'Monitor media, regulatory, and public discourse about similar AI systems to identify emerging societal concerns.',
      'Feed societal impact findings into the management review process for strategic decision-making.'
    ],
    pitfalls: [
      'Limiting impact assessment to direct operational impacts and ignoring broader societal effects is a common gap.',
      'Conducting societal impact assessment only at deployment and not re-evaluating as scale and use evolve misses emerging harms.'
    ]
  }
});

Object.assign(TIPS, {
  'A.6.1.2': {
    tips: [
      'Define responsible AI objectives in SMART terms (Specific, Measurable, Achievable, Relevant, Time-bound) rather than as aspirational statements.',
      'Integrate objectives into project charters and sprint goals so developers engage with them throughout development.',
      'Assign ownership of each responsible AI objective to a named person accountable for achievement.',
      'Review objectives at each project stage gate to ensure they remain relevant as the system evolves.'
    ],
    pitfalls: [
      'Setting generic responsible AI objectives that cannot be tested or measured leaves no way to demonstrate compliance.',
      'Treating objectives as a documentation exercise rather than genuine development guidance produces checkbox compliance.'
    ]
  },
  'A.6.1.3': {
    tips: [
      'Embed ethics and fairness review gates into the development methodology alongside technical quality gates.',
      'Establish a model governance board or review committee that approves progression through lifecycle stages.',
      'Define clear entry and exit criteria for each development stage, including responsible AI criteria.',
      'Document process deviations and the rationale for them — auditors expect process adherence or justified exceptions.'
    ],
    pitfalls: [
      'Treating AI development like traditional software development without AI-specific checkpoints misses key governance requirements.',
      'Poorly defined responsibilities between data science, IT, and business teams create accountability gaps at review points.'
    ]
  },
  'A.6.2.2': {
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
  'A.6.2.3': {
    tips: [
      'Use experiment tracking tools (MLflow, Weights & Biases) to maintain a complete record of training runs and design decisions.',
      'Document the rationale for algorithm and architecture choices, not just the configuration.',
      'Store model artifacts and training code in version-controlled repositories linked to documentation.',
      'Design documentation should be updated as the system evolves — stale documentation is as harmful as no documentation.'
    ],
    pitfalls: [
      'Undocumented design decisions make it impossible to explain or reproduce results for audit or incident investigation.',
      'Separating code and documentation creates drift — use documentation-as-code approaches where possible.'
    ]
  },
  'A.6.2.4': {
    tips: [
      'Conduct independent validation — the team that tests should not be the team that built the model.',
      'Include robustness testing: how does the model perform on noisy, corrupted, or adversarial inputs?',
      'Test on held-out data representative of the actual deployment population, not just the training distribution.',
      'Document all test failures and how they were resolved before proceeding to deployment.'
    ],
    pitfalls: [
      'Testing only on the same data distribution as training gives overly optimistic performance estimates.',
      'Treating validation as a checkbox rather than a genuine quality gate undermines the entire process.'
    ]
  },
  'A.6.2.5': {
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
  'A.6.2.6': {
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
  'A.6.2.7': {
    tips: [
      'Use model cards as a standardized format for AI system technical documentation — they are widely recognized and auditor-friendly.',
      'Include performance characteristics broken down by subgroup, not just overall aggregate metrics.',
      'Document known failure modes and edge cases explicitly — auditors look for honest limitation disclosure.',
      'Keep documentation version-controlled and linked to specific model versions and deployment states.'
    ],
    pitfalls: [
      'Documentation that describes what the system should do rather than what it actually does will fail an audit.',
      'Failing to update technical documentation when the model is updated creates a compliance gap.'
    ]
  }
});

Object.assign(TIPS, {
  'A.7.2': {
    tips: [
      'Define data requirements before procurement or development begins — late discovery of data gaps causes costly rework.',
      'Conduct representativeness analysis to ensure the planned data will reflect the deployment population.',
      'Check for sensitive attribute encoding that could introduce proxy discrimination in development data.',
      'Document the link between data requirements and the AI system\'s responsible AI objectives.'
    ],
    pitfalls: [
      'Assuming available data is suitable without explicit assessment of representativeness leads to biased models.',
      'Failing to document data type requirements (labeling standards, annotation guidelines) before acquisition creates inconsistency.'
    ]
  },
  'A.7.3': {
    tips: [
      'Maintain a data acquisition log documenting source, date, quantity, consent basis, and any restrictions on use.',
      'Validate data labeling quality with inter-annotator agreement metrics before accepting labeled datasets.',
      'Assess the legal basis for using personal data in AI training — consent, legitimate interest, or contract.',
      'Check licensing and copyright status of all externally sourced training datasets.'
    ],
    pitfalls: [
      'Using publicly scraped data without assessing consent, copyright, or bias implications is a significant risk.',
      'Accepting labeled datasets from third parties without validating labeling quality creates downstream model quality issues.'
    ]
  },
  'A.7.4': {
    tips: [
      'Define minimum quality thresholds before data is eligible for use in AI training.',
      'Automate data quality checks in the pipeline to catch issues early before they reach training.',
      'Assess representativeness as a quality dimension alongside accuracy, completeness, and consistency.',
      'Monitor operational/inference data quality as well as training data — degradation here causes model drift.'
    ],
    pitfalls: [
      'Focusing quality checks only on structured data completeness misses semantic and representativeness quality issues.',
      'Failing to monitor operational data quality can cause model performance degradation without obvious failure signals.'
    ]
  },
  'A.7.5': {
    tips: [
      'Implement data lineage tooling (Apache Atlas, DataHub, dbt lineage) to automate provenance tracking.',
      'Document all transformations applied to data before use in training — each step should be auditable.',
      'Maintain provenance records for pre-trained models including the datasets they were trained on.',
      'Use data versioning alongside code versioning to enable full experiment reproducibility.'
    ],
    pitfalls: [
      'Manual lineage documentation quickly becomes outdated and unreliable — automate where possible.',
      'Ignoring provenance for externally sourced or pre-trained model components creates significant audit gaps.'
    ]
  },
  'A.7.6': {
    tips: [
      'Document every transformation step applied to data before use — normalization, encoding, filtering, augmentation.',
      'Apply anonymization or pseudonymization before using personal data in training where feasible.',
      'Conduct bias assessment specifically on prepared data, not just raw data — preparation steps can introduce or amplify bias.',
      'Version-control data preparation scripts alongside training code for reproducibility.'
    ],
    pitfalls: [
      'Assuming anonymized data is fully de-identified without testing for re-identification risk is a privacy gap.',
      'Undocumented preparation steps make model outputs impossible to explain or reproduce.'
    ]
  }
});

Object.assign(TIPS, {
  'A.8.2': {
    tips: [
      'Tailor user information to the specific audience — technical users need different guidance than general public users.',
      'Include explicit guidance on what the AI system should NOT be used for.',
      'Test user documentation with actual users before release to ensure comprehension.',
      'Update user information whenever AI capabilities or limitations change significantly.'
    ],
    pitfalls: [
      'Documentation written by developers for developers rather than for the actual user audience is a common gap.',
      'Omitting limitation disclosures to avoid deterring adoption creates both ethical and legal risks.'
    ]
  },
  'A.8.3': {
    tips: [
      'Make the external reporting channel prominently accessible, not buried in settings or footer links.',
      'Define a clear process for triaging, investigating, and responding to externally reported AI issues.',
      'Publish a summary of external reports received and actions taken as part of AI transparency reporting.',
      'Establish SLAs for acknowledging and responding to externally reported adverse impacts.'
    ],
    pitfalls: [
      'A reporting form that collects input but has no documented review or response process does not satisfy this control.',
      'Treating external AI adverse impact reports as generic customer complaints without AI-specific triage misses important signals.'
    ]
  },
  'A.8.4': {
    tips: [
      'Define incident notification SLAs based on severity — significant AI failures affecting users should be communicated promptly.',
      'Prepare communication templates in advance so incident notifications can be sent quickly and accurately.',
      'Communicate what happened, who is affected, what you are doing about it, and what users should do — not just that an issue exists.',
      'Document all incident communications as evidence of transparency obligations fulfilled.'
    ],
    pitfalls: [
      'Delayed or vague incident communications damage trust and may breach regulatory notification requirements.',
      'Communicating technical details without user-actionable guidance leaves users unable to protect themselves.'
    ]
  },
  'A.8.5': {
    tips: [
      'Map all interested parties for each AI system: regulators, auditors, customers, business partners, affected communities.',
      'Create an information obligations register documenting what must be disclosed, to whom, and by when.',
      'Include AI disclosure obligations in regulatory compliance tracking.',
      'Consider proactive transparency reports as a way to meet stakeholder information needs and build trust.'
    ],
    pitfalls: [
      'Assuming disclosure obligations only arise on request misses proactive transparency requirements in some regulations.',
      'Failing to update the interested parties register when new stakeholders or regulatory obligations emerge.'
    ]
  }
});

Object.assign(TIPS, {
  'A.9.2': {
    tips: [
      'Define explicit approval steps required before staff can use AI systems in business processes.',
      'Document scope boundaries for each AI system — what it can and cannot be used for in operational contexts.',
      'Build human oversight requirements into use processes, not as optional add-ons.',
      'Include AI use process compliance in internal audit scope.'
    ],
    pitfalls: [
      'Deploying AI systems without formal use process documentation leaves staff making ad-hoc decisions about appropriate use.',
      'Responsible use processes that exist on paper but are not followed in practice create a compliance gap auditors will find.'
    ]
  },
  'A.9.3': {
    tips: [
      'Set responsible use objectives in SMART terms and track them with measurable KPIs.',
      'Review objectives periodically and after significant incidents or changes in the AI system.',
      'Align responsible use objectives with the AI system\'s impact assessment findings.',
      'Include responsible use objective performance in management review inputs.'
    ],
    pitfalls: [
      'Objectives like "use AI responsibly" without measurable criteria cannot be evaluated or audited.',
      'Setting objectives but not tracking them against actual outcomes reduces them to documentation artefacts.'
    ]
  },
  'A.9.4': {
    tips: [
      'Document not only what the system is intended to do, but explicitly what it is NOT intended for.',
      'Implement technical controls where possible to prevent out-of-scope use, not just policy controls.',
      'Monitor for use outside intended scope through logging and periodic review.',
      'Review the intended use specification whenever the business context or regulatory environment changes significantly.'
    ],
    pitfalls: [
      'Vague intended use descriptions like "support decision making" do not provide adequate boundary-setting.',
      'Failing to communicate intended use constraints to all users creates a gap between documented intent and actual practice.'
    ]
  }
});

Object.assign(TIPS, {
  'A.10.2': {
    tips: [
      'Create explicit responsibility allocation matrices for every AI system involving third parties — ambiguity about who owns what creates accountability gaps.',
      'Address AI-specific responsibilities in contracts: model performance obligations, bias testing, incident notification, and data handling.',
      'Review responsibility allocations whenever the third-party relationship changes or the AI system\'s use expands.',
      'Ensure customer-facing AI responsibilities (disclosures, redress) are clearly owned within the organization, not delegated to vendors.'
    ],
    pitfalls: [
      'Assuming standard IT vendor contracts adequately cover AI-specific responsibilities is a common oversight.',
      'Responsibility gaps at the boundary between your organization and AI vendors are exactly where incidents fall through.'
    ]
  },
  'A.10.3': {
    tips: [
      'Develop an AI-specific supplier questionnaire covering responsible AI practices, bias management, incident handling, and audit rights.',
      'Tier AI suppliers by risk: a critical AI vendor needs deeper due diligence than a low-risk tool.',
      'Reassess suppliers periodically and after significant incidents or changes in vendor AI practices.',
      'Review publicly available information about AI vendors: published AI ethics policies, past incidents, regulatory actions.'
    ],
    pitfalls: [
      'Using a generic IT vendor assessment without AI-specific questions fails to identify AI governance gaps.',
      'Completing due diligence only at onboarding without periodic reassessment is a common oversight as vendor practices evolve.'
    ]
  },
  'A.10.4': {
    tips: [
      'Establish mechanisms for customers to report AI concerns and adverse impacts — this feeds both A.8.3 and this control.',
      'Communicate AI capabilities and limitations to customers proactively, not just when asked.',
      'Consider customers as stakeholders in the AI impact assessment process, particularly for high-risk systems.',
      'Provide customers with the information they need to use your AI systems responsibly within their own contexts.'
    ],
    pitfalls: [
      'Treating customers as passive recipients of AI outputs rather than stakeholders with legitimate interests in AI governance.',
      'Failing to update customer AI communications when the AI system changes significantly leaves customers relying on outdated information.'
    ]
  }
});
