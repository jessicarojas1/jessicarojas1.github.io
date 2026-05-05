/* cmmidev3.js — CMMI v2.0 DEV Maturity Level 3 — Enriched Practice Reference
 *
 * Data schema (each entry is an object):
 *   pa              — Practice Area abbreviation (e.g. 'CAR')
 *   paFull          — Practice Area full name
 *   paDesc          — Practice Area description (one sentence)
 *   practiceNum     — Practice identifier (e.g. 'CAR 1.1')
 *   practiceGroup   — Practice Group label (e.g. 'PG 1 – Determine Causes')
 *   practiceStatement — The CMMI v2.0 practice statement (imperative)
 *   elaboration     — Additional explanatory information from the standard
 *   examples        — Array of concrete compliance examples
 *   level           — Maturity Level ('ML2' | 'ML3' | 'ML4' | 'ML5')
 *   domain          — 'All' (Core) | 'Development' | 'Services'
 */

var CMMI_DATA = [

/* ═══════════════════════════════════════════════════════════════════════════
   ML3 — CORE PRACTICE AREAS  (domain = 'All')
   Required for CMMI v2.0 Maturity Level 3 — Development appraisal
═══════════════════════════════════════════════════════════════════════════ */

/* ── EST — Estimation ──────────────────────────────────────────────────── */
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 1.1', practiceGroup:'PG 1 – Prepare for Estimating',
  practiceStatement:'Establish and maintain a documented approach for estimating.',
  elaboration:'The estimating approach defines the methods, tools, measures, and procedures used to produce estimates. A documented approach ensures consistency across projects and provides a basis for validating and improving estimates over time.',
  examples:[
    'An Estimation Standards document is stored in the process asset library specifying that Planning Poker with story points is the approved method for new-feature work',
    'A project management SOP section covers when parametric models (e.g., COCOMO II) are required vs. analogy-based estimation',
    'The estimation approach is version-controlled and reviewed annually; the last revision is dated and signed by the process owner',
    'New team members complete an "Estimation Methods" onboarding module that covers the documented approach'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 1.2', practiceGroup:'PG 1 – Prepare for Estimating',
  practiceStatement:'Prepare for estimating by identifying the work products and tasks to be estimated and the estimating parameters to be used.',
  elaboration:'Preparation includes identifying the scope boundary, the units of measure (story points, function points, hours, LOC), and the historical data sources that will inform the estimate. Preparation prevents ad-hoc estimating and ensures the team starts from a common reference point.',
  examples:[
    'Sprint planning meetings begin with a backlog-refinement session that identifies all user stories in scope before estimation begins',
    'An Estimation Kickoff Checklist is completed at project initiation, confirming scope, WBS availability, and the selected estimating unit',
    'The project manager records the estimating parameters (complexity weighting, labor categories, overhead rates) in the project plan before any estimates are produced',
    'Historical velocity data from the last three completed sprints is pulled from Jira and attached to the estimation worksheet'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 2.1', practiceGroup:'PG 2 – Estimate the Work',
  practiceStatement:'Estimate the scope of the work using defined size measures.',
  elaboration:'Size measures quantify the magnitude of the work independent of who performs it or how long it takes. Common size measures include story points, function points, use-case points, or lines of code. Size estimates feed into effort and duration estimates downstream.',
  examples:[
    'Every user story in the backlog is sized in story points using the team\'s reference story scale before sprint commitment',
    'A function-point count is produced from the requirements specification using the IFPUG counting rules and stored in the project repository',
    'The WBS task list includes a "Size (hrs of work)" column populated before effort is calculated, so size and effort are visibly distinct',
    'Relative sizing sessions use a defined reference story; the reference story definition is documented and stable across sprints'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 2.2', practiceGroup:'PG 2 – Estimate the Work',
  practiceStatement:'Estimate the effort and duration of the work using the size estimates and defined estimating parameters.',
  elaboration:'Effort and duration are derived from size by applying historical productivity rates or other defined parameters. Duration accounts for concurrency, resource availability, and dependencies and must not be treated as simply effort divided by headcount.',
  examples:[
    'A velocity-based formula (total story points ÷ average team velocity = sprints) is used to derive duration from the sized backlog',
    'The estimation worksheet maps each WBS element to labor categories with associated hours/unit productivity factors pulled from the historical database',
    'A PERT analysis (optimistic, most likely, pessimistic) is applied to high-uncertainty tasks; all three values and the weighted result are recorded',
    'Duration estimates account for planned leave, holidays, and part-time allocations documented in the resource plan'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 2.3', practiceGroup:'PG 2 – Estimate the Work',
  practiceStatement:'Estimate the project costs based on the effort and duration estimates.',
  elaboration:'Cost estimates translate effort into monetary terms by applying labor rates, material costs, tool licensing, and overhead factors. Cost estimates must be reconciled with available budget and tracked for variance throughout the project.',
  examples:[
    'A project cost model spreadsheet multiplies estimated hours per role by the loaded labor rate for each role, then adds direct non-labor costs from the procurement plan',
    'The project plan contains a cost estimate section with labor costs, software licenses, cloud infrastructure, and a contingency reserve percentage',
    'Cost estimates are reviewed by the finance team before project approval, and the review sign-off is filed with the project record',
    'Monthly actuals are compared against cost estimates; variances exceeding ±10% trigger a documented corrective action'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 3.1', practiceGroup:'PG 3 – Validate Estimates',
  practiceStatement:'Review the estimates and the rationale for the estimates with appropriate stakeholders and revise as necessary.',
  elaboration:'Estimates are reviewed to confirm they are realistic, complete, and consistent with current scope and resource assumptions. Stakeholder review provides an independent check and surfaces hidden assumptions before commitments are made.',
  examples:[
    'Estimation review meetings are held with the project sponsor and technical lead; meeting minutes record any revisions and the rationale',
    'The project schedule and cost estimate are submitted to a Peer Estimation Review board; the board\'s disposition (approved/revised) is recorded in the project file',
    'Before sprint commitment, the product owner reviews the velocity-based timeline and formally acknowledges it meets the release objective or requests re-scoping',
    'Estimation reviews are documented on a standard review form that captures who reviewed, what was changed, and the agreed basis for the final number'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'EST', paFull:'Estimation',
  paDesc:'Establish and maintain estimates of the size, effort, duration, and cost of work.',
  practiceNum:'EST 3.2', practiceGroup:'PG 3 – Validate Estimates',
  practiceStatement:'Record estimating information for use in future estimating and project planning.',
  elaboration:'Historical data from completed estimates — including actual size, effort, duration, cost, and the variance from the estimates — is the foundation for improving future estimates. Recording actuals enables calibration of estimating models and parameters over time.',
  examples:[
    'A project closeout report includes a table comparing estimated vs. actual story points, hours, duration, and cost for each project phase',
    'Sprint velocity data is automatically logged in Jira and exported quarterly to the organizational measurement repository',
    'Lessons learned on estimation accuracy are captured in the retrospective log and used to update the estimation standards document',
    'The organizational estimating database is updated at project close with anonymized estimate-to-actual data; the database owner confirms receipt'
  ],
  level:'ML3', domain:'All'
},

/* ── DAR — Decision Analysis and Resolution ────────────────────────────── */
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 1.1', practiceGroup:'PG 1 – Establish Guidelines',
  practiceStatement:'Establish and maintain guidelines for determining which issues are subject to a formal evaluation process.',
  elaboration:'Not every decision requires formal DAR — the guidelines define the thresholds (by cost, risk, technical complexity, or strategic impact) that trigger formal evaluation. Without clear criteria, teams either over-apply DAR (wasting time) or under-apply it (making poorly justified decisions on critical issues).',
  examples:[
    'The DAR Policy defines that any decision involving a cost impact >$50K, a technology platform choice, or a security architecture change must go through formal DAR',
    'The project management SOP includes a DAR trigger checklist; project managers complete it at key decision points and retain the completed form',
    'The process asset library includes a one-page "DAR Decision Guide" with examples of decisions that do and do not require formal evaluation',
    'At project kickoff, the PM and technical lead review the DAR trigger list and identify anticipated decisions that will require formal DAR during the project'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 1.2', practiceGroup:'PG 1 – Establish Guidelines',
  practiceStatement:'Establish and maintain the criteria and methods for evaluating alternatives.',
  elaboration:'Evaluation criteria must be established before alternatives are identified to avoid biasing the analysis toward a pre-selected solution. Criteria should include both functional requirements and non-functional concerns (cost, risk, maintainability, compliance). Methods include weighted scoring matrices, simulations, prototypes, and expert judgment.',
  examples:[
    'For a cloud provider selection DAR, a weighted evaluation matrix is built with criteria (cost, SLA, security certifications, migration complexity) and weights assigned before vendors are assessed',
    'The DAR evaluation template in the process asset library requires listing and weighting evaluation criteria in Section 1 before recording alternatives in Section 2',
    'A technology selection DAR uses proof-of-concept prototypes as the evaluation method; the PoC acceptance criteria are defined and approved before prototyping begins',
    'Criteria weights are reviewed and agreed upon by all stakeholders before scoring begins; the sign-off is recorded on the evaluation form'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 2.1', practiceGroup:'PG 2 – Analyze Alternatives',
  practiceStatement:'Identify alternative solutions to address the issue being analyzed.',
  elaboration:'A minimum set of alternatives must be identified and documented to ensure the decision is not a rubber-stamp of a foregone conclusion. Alternatives should include at least one non-obvious option (e.g., a build vs. buy alternative, or a "do nothing" baseline). Insufficient alternatives are a frequent appraisal finding.',
  examples:[
    'The database technology DAR identifies four alternatives: PostgreSQL, MySQL, MS SQL Server, and a NoSQL option (MongoDB); each is described with a one-paragraph summary',
    'The "do nothing / status quo" option is always included as a baseline alternative in DAR analyses so any selected option can be compared to the cost of inaction',
    'A brainstorming session is held with the technical team to surface alternatives; the session output is attached to the DAR record as an input artifact',
    'Vendor alternatives are identified through an RFI process; all responding vendors are included in the evaluation regardless of initial preference'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 2.2', practiceGroup:'PG 2 – Analyze Alternatives',
  practiceStatement:'Select solutions from the alternatives based on the evaluation criteria.',
  elaboration:'Selection must be traceable to the evaluation results — the chosen alternative should have the best overall score against the weighted criteria, or the rationale for overriding the scoring must be explicitly documented. Undocumented overrides (selecting an option that did not score highest without recorded justification) will fail an appraisal.',
  examples:[
    'The completed weighted scoring matrix shows that Alternative B scored 87/100 vs. Alternative A\'s 74/100; the DAR record states Alternative B is selected based on this result',
    'Alternative A scored highest on total cost but lowest on security compliance; the decision record explains the override and cites the governing security policy that made compliance non-negotiable',
    'The DAR record includes a summary table showing each alternative\'s score per criterion, total weighted score, rank, and the final selection with rationale',
    'The selected solution is reviewed and approved by the decision authority identified in the DAR guidelines before implementation begins'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 3.1', practiceGroup:'PG 3 – Apply Decisions',
  practiceStatement:'Implement the selected solution.',
  elaboration:'Implementing the selected solution means integrating the decision output into project plans, architecture documents, procurement actions, or other work products. The DAR process is incomplete if the selected solution is documented but not acted upon or not reflected in project baselines.',
  examples:[
    'The selected cloud provider from the DAR is added to the project architecture document within 5 business days; the architecture doc revision history shows the update',
    'The build-vs-buy DAR decision to purchase a commercial COTS component triggers a procurement action in the project plan; the link between the DAR record and the procurement request is maintained',
    'The selected database technology is reflected in the updated Technology Stack document, the network diagram, and the security architecture review — all updated within one sprint of the DAR decision',
    'Implementation progress is reviewed at the next project status meeting; the status report notes the DAR decision and the implementation action taken'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'DAR', paFull:'Decision Analysis and Resolution',
  paDesc:'Analyze possible decisions using a formal evaluation process that identifies alternatives against defined criteria.',
  practiceNum:'DAR 3.2', practiceGroup:'PG 3 – Apply Decisions',
  practiceStatement:'Evaluate the decisions made and record lessons learned for use in future decision-making.',
  elaboration:'Post-decision evaluation determines whether the selected solution performed as expected and whether the DAR process itself was effective. Lessons learned feed back into the evaluation guidelines and criteria methods, improving future DAR analyses.',
  examples:[
    'Six months after a technology selection DAR, the project retrospective includes a section evaluating whether the selected option met the evaluation criteria in practice',
    'Lessons learned from a DAR where the winning alternative later underperformed are captured in the PAD library with a note to include operational performance data as a mandatory criterion going forward',
    'An annual DAR process review collects outcome data on decisions made in the prior year and updates the DAR guidelines if criteria or thresholds need adjustment',
    'The project closeout report includes a table of key DAR decisions made, the selected alternatives, and a brief outcome assessment'
  ],
  level:'ML3', domain:'All'
},

/* ── CAR — Causal Analysis and Resolution ──────────────────────────────── */
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 1.1', practiceGroup:'PG 1 – Determine Causes of Outcomes',
  practiceStatement:'Select outcomes for analysis.',
  elaboration:'Outcomes selected for analysis can be negative (defects, failures, schedule slips) or positive (unexpected efficiency gains, defect-free deliverables). Selection criteria should be defined to focus analysis resources on the highest-impact or most-frequent outcomes rather than attempting to analyze everything.',
  examples:[
    'The QA team reviews the monthly defect report and selects the top three defect categories by volume for CAR analysis each quarter',
    'Any production incident rated Severity 1 or 2 is automatically added to the CAR queue; the CAR policy defines this threshold',
    'Sprint retrospective action items that recur across three or more consecutive sprints are escalated to a formal CAR session',
    'A positive outcome — a sprint completing 120% of committed velocity — is selected for analysis to understand and replicate the contributing factors'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 1.2', practiceGroup:'PG 1 – Determine Causes of Outcomes',
  practiceStatement:'Analyze selected outcomes to determine their root causes.',
  elaboration:'Root cause analysis goes beyond the immediate symptom to identify the underlying systemic cause. Structured techniques such as 5-Why, fishbone (Ishikawa) diagrams, fault tree analysis, or statistical correlation are used to ensure causes rather than symptoms are identified. The goal is to find the cause that, if addressed, prevents recurrence.',
  examples:[
    'A 5-Why analysis on a missed deployment deadline reveals the root cause is the absence of a pre-deployment checklist, not the individual who forgot a step',
    'A fishbone diagram is constructed for a recurring integration defect type, categorizing causes across People, Process, Tools, and Environment; the completed diagram is stored in the CAR record',
    'A Pareto chart of defect injection phases shows 68% of defects originate in design; the CAR analysis concludes that the design review checklist is insufficient',
    'The CAR facilitator is trained in root cause techniques; training records show completion of the "Root Cause Analysis" course in the LMS'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 2.1', practiceGroup:'PG 2 – Address Causes of Outcomes',
  practiceStatement:'Develop action proposals to address identified root causes.',
  elaboration:'Action proposals target the root cause, not the symptom. Each proposal describes the specific action, the expected effect on the root cause, the owner, the target date, and the success measure. Multiple proposals may address the same root cause; proposals are evaluated and prioritized before implementation.',
  examples:[
    'The CAR session output is an Action Proposal Form listing the root cause, three candidate actions, the recommended action, the owner, due date, and how effectiveness will be measured',
    'Action proposals are reviewed in a follow-up meeting with the process owner and project manager before implementation begins; the review is recorded in the CAR log',
    'A proposal to add a mandatory design review checklist to the design phase gate is developed with a draft checklist attached and a pilot plan for the next sprint',
    'Proposals addressing People root causes (e.g., knowledge gaps) result in training requests submitted to OT; the training request ID is cross-referenced in the CAR action log'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 2.2', practiceGroup:'PG 2 – Address Causes of Outcomes',
  practiceStatement:'Implement selected action proposals.',
  elaboration:'Selected action proposals are incorporated into project or organizational work plans and tracked to completion. Implementation without tracking is a common appraisal failure point — the action must be verifiably executed, not just planned.',
  examples:[
    'The approved action proposal to add a design review gate is added as a task in Jira with an owner and target date; the task is closed with a link to the updated process document when complete',
    'The CAR action log is reviewed at every bi-weekly project status meeting; open actions past due date are escalated to the project sponsor',
    'A process improvement action is implemented by updating the standard process in the PAD library; the updated process document version and approval date are recorded in the CAR record',
    'The QA manager confirms implementation of each CAR action by performing a compliance check in the following sprint; the check result is documented'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 3.1', practiceGroup:'PG 3 – Evaluate Effect of Changes',
  practiceStatement:'Evaluate the effect of the implemented actions on process performance.',
  elaboration:'Effectiveness evaluation confirms that the root cause has been addressed and that the outcome no longer recurs (or that positive outcomes are reproducible). Evaluation uses the success measures defined in the action proposal and relies on actual performance data collected after implementation, not just a subjective assessment.',
  examples:[
    'Three sprints after implementing the new design review checklist, defect density in design-related defect categories is compared to the prior baseline; a 40% reduction confirms effectiveness',
    'The CAR record is updated with post-implementation data: number of recurrences, trend chart screenshot, and a disposition of "Effective" or "Requires Further Action"',
    'A control chart shows that the defect category targeted by the CAR action has been statistically stable at a lower rate since implementation, confirming the fix is holding',
    'The CAR closure report is reviewed by the process owner and the quality manager before the CAR is formally closed in the tracking system'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'CAR', paFull:'Causal Analysis and Resolution',
  paDesc:'Identify causes of selected outcomes and take actions to prevent recurrence of undesirable outcomes or to institutionalize positive ones.',
  practiceNum:'CAR 3.2', practiceGroup:'PG 3 – Evaluate Effect of Changes',
  practiceStatement:'Record causal analysis and resolution data and make it available for use across the organization.',
  elaboration:'CAR data is an organizational asset. Recording and sharing analysis results, root causes, actions, and outcomes allows other teams to benefit from lessons learned without repeating the same investigation. This data also feeds the PAD library and OPM performance improvement analysis at higher maturity levels.',
  examples:[
    'All closed CAR records are stored in the process asset library under the "Lessons Learned / CAR Archive" folder with consistent naming and a searchable index',
    'A quarterly CAR Summary Report is published to all project teams, listing top root causes identified organization-wide and the actions taken',
    'New projects are required to search the CAR archive during project planning and document whether any previously identified root causes are relevant to their work',
    'CAR data is imported into the organizational measurement repository and used in annual process performance trend analyses'
  ],
  level:'ML3', domain:'All'
},

/* ── OT — Organizational Training ─────────────────────────────────────── */
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 1.1', practiceGroup:'PG 1 – Establish Training Needs',
  practiceStatement:'Identify the strategic training needs of the organization.',
  elaboration:'Strategic training needs are derived from the organization\'s business objectives, process improvement goals, and long-term capability requirements — not just current project gaps. They are typically identified through workforce planning, skills assessments, and process performance analysis.',
  examples:[
    'An annual Organizational Training Needs Assessment is conducted; the output is a prioritized list of strategic training topics aligned to the 3-year business strategy',
    'Process performance data showing recurring defects in design reviews triggers a strategic need for advanced design review facilitation training',
    'The organizational CMMI appraisal preparation identifies gaps in statistical process control knowledge as a strategic need; a training program is planned and funded',
    'The HR and process group jointly conduct a skills matrix exercise that maps current competencies to required competencies; gaps are recorded as strategic training needs'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 1.2', practiceGroup:'PG 1 – Establish Training Needs',
  practiceStatement:'Identify the training needed to support performing the organization\'s set of standard processes.',
  elaboration:'Standard processes require specific skills and knowledge to execute correctly. For each standard process, the required competencies are identified and compared against existing staff capability, surfacing the process-specific training needs that must be addressed for processes to be performed as defined.',
  examples:[
    'Each standard process definition in the PAD library includes a "Required Competencies" section; gaps between required and current competencies are tracked as process training needs',
    'When a new process is added to the organization\'s process asset library, a training needs analysis is conducted before the process is deployed to projects',
    'The process training needs are documented in the Tactical Training Plan with specific courses, target audience, and delivery schedule',
    'Project kickoff checklists verify that all team members assigned to a process role have completed the required process training before the phase begins'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 2.1', practiceGroup:'PG 2 – Provide Training',
  practiceStatement:'Establish and maintain an organizational training tactical plan.',
  elaboration:'The tactical training plan translates identified training needs into a scheduled, resourced delivery plan for the current period. It specifies what training will be delivered, to whom, by what method, when, and at what cost. The plan is updated as needs evolve and is distinct from the long-term strategic training roadmap.',
  examples:[
    'An annual Tactical Training Plan is published by Q1 each year, listing all planned training courses, target audiences, delivery dates, mode (classroom/LMS/OJT), and budget allocation',
    'The Tactical Training Plan is reviewed quarterly and updated when new process changes, project staffing, or performance data create new training requirements',
    'The plan distinguishes mandatory training (required for process compliance) from developmental training (enhancing individual capability) with separate tracking',
    'Training plans are reviewed with department managers to confirm staff availability for scheduled training before the plan is finalized'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 2.2', practiceGroup:'PG 2 – Provide Training',
  practiceStatement:'Provide the training that is needed per the training plan.',
  elaboration:'Training is delivered using the methods specified in the training plan. Delivery evidence must demonstrate that the training actually occurred — a training plan alone is not sufficient. Delivery methods may include instructor-led courses, e-learning modules, mentoring, on-the-job training, or workshops.',
  examples:[
    'LMS completion records show that 100% of assigned staff completed the "Requirements Management" course before the project requirements phase began',
    'In-person workshops are documented with sign-in sheets, agenda, and materials distributed; all records are stored in the OT folder in SharePoint',
    'On-the-job training for new peer review facilitators is documented with a completed OJT checklist signed by the mentor and trainee',
    'Training delivery is tracked against the Tactical Training Plan; any training not delivered on schedule is documented with a reason and rescheduled date'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 3.1', practiceGroup:'PG 3 – Evaluate Training Effectiveness',
  practiceStatement:'Evaluate the effectiveness of the organization\'s training program.',
  elaboration:'Effectiveness evaluation determines whether training is achieving its intended impact on performance. Evaluation goes beyond satisfaction surveys (Kirkpatrick Level 1) to include knowledge/skills assessment (Level 2) and on-the-job performance change (Level 3). Ineffective training should be revised or replaced.',
  examples:[
    'Post-training assessments (quizzes or practical exercises) with a pass/fail threshold are required for all mandatory process training; results are recorded per trainee',
    'Peer review defect detection rates are tracked before and after peer review facilitator training; a 20% improvement in defect detection rate is used as the effectiveness criterion',
    'Annual training effectiveness surveys ask managers to rate whether trained skills are being applied on the job; survey results inform the next year\'s training plan revision',
    'Training that fails effectiveness thresholds is flagged in the OT effectiveness report with a recommendation to revise content, delivery method, or prerequisites'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'OT', paFull:'Organizational Training',
  paDesc:'Develop the skills and knowledge of people so they can perform their roles effectively and efficiently.',
  practiceNum:'OT 3.2', practiceGroup:'PG 3 – Evaluate Training Effectiveness',
  practiceStatement:'Establish and maintain records of the organizational training.',
  elaboration:'Training records provide evidence that training was planned, delivered, and effective. Records must be maintained for individuals and for the program as a whole. Complete training records are among the most frequently requested artifacts in a CMMI appraisal.',
  examples:[
    'The LMS maintains individual training transcripts showing course name, completion date, score, and expiry date; transcripts are accessible to managers and auditors',
    'Training records include: the Tactical Training Plan, delivery confirmation records (sign-ins, LMS exports), assessment scores, and effectiveness evaluation results — all stored in a single OT folder',
    'Training records are reviewed annually to identify personnel with overdue mandatory training; the review report is submitted to department managers',
    'Project audit packs include training completion evidence for all team members assigned to process-critical roles'
  ],
  level:'ML3', domain:'All'
},

/* ── PAD — Process Asset Development ──────────────────────────────────── */
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 1.1', practiceGroup:'PG 1 – Establish Standard Processes',
  practiceStatement:'Establish and maintain the organization\'s set of standard processes.',
  elaboration:'The standard processes are the organization\'s official way of performing work. They cover all required practice areas and are complete enough for projects to follow. Standard processes are not just descriptions — they are actionable, tailorable, and actually used. They must be reviewed and updated when performance data or process improvement proposals indicate a need.',
  examples:[
    'The Organization\'s Defined Process Set is documented in Confluence, covers all 19 required CMMI DEV ML3 practice areas, and is version-controlled with an owner for each process',
    'Each standard process includes: purpose, entry/exit criteria, inputs, activities with roles, outputs (work products), and references to templates and tools',
    'Process owners review and reapprove their standard process annually; the review date and approver are recorded in the process document header',
    'When a project proposes a process change, it is submitted as a Process Improvement Proposal and goes through a defined review cycle before being incorporated into the standard'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 1.2', practiceGroup:'PG 1 – Establish Standard Processes',
  practiceStatement:'Establish and maintain descriptions of the life cycle models approved for use in the organization.',
  elaboration:'Life cycle models define the phases, milestones, and entry/exit criteria that govern how projects progress from initiation to closure. The organization may maintain multiple approved life cycle models (e.g., waterfall, agile sprint-based, hybrid) suited to different project types. Each project selects and tailors an approved model.',
  examples:[
    'The process asset library contains three approved life cycle models: Scrum-based, iterative, and sequential; each includes a phase diagram, milestone definitions, and exit criteria',
    'The project initiation checklist requires the project manager to select and document which approved life cycle model is being used, and to record any approved tailoring',
    'Life cycle model descriptions include which CMMI practice areas apply at each phase, helping project teams understand process expectations per lifecycle stage',
    'Life cycle models are reviewed when appraisal findings or project retrospectives indicate the models no longer reflect how work is actually performed'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 2.1', practiceGroup:'PG 2 – Develop Process Assets',
  practiceStatement:'Establish and maintain the organization\'s process asset library.',
  elaboration:'The Process Asset Library (PAL) is the central repository of all organizational process assets — standard processes, life cycle models, templates, tools, guidelines, checklists, lessons learned, and measurement data. The PAL must be accessible to all project staff and actively maintained to remain current and useful.',
  examples:[
    'The Process Asset Library is hosted in SharePoint with a structured folder hierarchy, indexed home page, and access granted to all staff; the library manager\'s name and contact are on the home page',
    'A PAL catalog lists all assets with name, version, owner, last-reviewed date, and a description of when each asset should be used',
    'When new templates or tools are approved, the PAL is updated within 10 business days; the update log shows date of addition and the approver',
    'Projects are required to reference the PAL at kickoff; the project plan lists which PAL assets (templates, checklists) are being used for that project'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 2.2', practiceGroup:'PG 2 – Develop Process Assets',
  practiceStatement:'Establish and maintain tailoring guidelines and criteria for adapting the organization\'s set of standard processes for use by projects.',
  elaboration:'Standard processes are designed to be tailored rather than applied rigidly. Tailoring guidelines specify which process elements are mandatory (cannot be changed), which are recommended (default but can be modified with justification), and which are optional. Tailoring must be documented for each project — undocumented deviation from the standard process is a process compliance violation.',
  examples:[
    'The Tailoring Guidelines document specifies that peer review coverage thresholds and configuration baselines are mandatory; estimation method and review format are recommended with documented justification allowed',
    'Every project\'s Project Plan includes a "Process Tailoring" section listing each standard process element and whether it was applied as-is, modified, or waived, with the rationale for any modification',
    'A Tailoring Request Form is required for any deviation from a mandatory process element; the form requires approval from the process owner before the tailoring takes effect',
    'The PQA team audits project tailoring records to confirm that no mandatory elements were bypassed without an approved tailoring request'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 3.1', practiceGroup:'PG 3 – Improve Process Assets',
  practiceStatement:'Collect process improvement proposals and lessons learned from performing the organization\'s set of standard processes.',
  elaboration:'Process improvement is driven by evidence — data from project performance, audit findings, CAR results, and staff suggestions. A defined mechanism for collecting and managing proposals ensures that improvement opportunities are captured, reviewed, and disposed of systematically rather than informally.',
  examples:[
    'A Process Improvement Proposal (PIP) form is available in the PAL; any staff member can submit a PIP at any time; all PIPs are logged in the PIP Register with a status (open/in-review/approved/rejected)',
    'Sprint retrospective action items that have process-wide applicability are automatically converted to PIPs by the Scrum Master and submitted to the process group',
    'The process group conducts a quarterly PIP Review Board meeting; all open PIPs are reviewed, dispositioned, and the decisions recorded',
    'CAR-generated process improvement actions that affect the standard process are submitted as PIPs rather than applied ad-hoc to a single project'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PAD', paFull:'Process Asset Development',
  paDesc:'Establish and maintain usable organizational process assets and work environment standards.',
  practiceNum:'PAD 3.2', practiceGroup:'PG 3 – Improve Process Assets',
  practiceStatement:'Deploy organizational process assets and incorporate process-related experiences into the organization\'s process assets.',
  elaboration:'Approved process improvements must be deployed across the organization and incorporated into the PAL. Deployment includes communicating the change, updating affected templates and checklists, training affected staff, and verifying adoption. Without active deployment, process assets become stale and are not actually used by projects.',
  examples:[
    'Approved process changes are communicated via the Process Change Notification email list; the notification includes a summary of what changed, effective date, and links to updated assets',
    'When the standard process is updated, affected training materials are revised within 30 days and the LMS is updated; affected staff are notified to retake the relevant module',
    'The PAL changelog records every process asset update: what changed, why, who approved, and when it became effective',
    'A deployment compliance check is performed 90 days after a process change to verify projects started after the effective date are using the updated process'
  ],
  level:'ML3', domain:'All'
},

/* ── RSK — Risk and Opportunity Management ────────────────────────────── */
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 1.1', practiceGroup:'PG 1 – Identify Risks and Opportunities',
  practiceStatement:'Identify and document risks and opportunities.',
  elaboration:'Risk identification is an ongoing activity throughout the project — not just a one-time exercise at initiation. Effective identification draws from multiple sources including technical reviews, stakeholder interviews, lessons learned, checklist prompts, and historical project data. Opportunities (positive risks) are identified using the same discipline to ensure beneficial outcomes are actively pursued.',
  examples:[
    'A risk identification workshop is held at project kickoff using a standard Risk Identification Checklist; all identified risks are entered into the Risk Register on the same day',
    'Risk identification is a standing agenda item at weekly project status meetings; any newly identified risks are added to the Risk Register within 24 hours',
    'The project team reviews the lessons-learned archive from similar past projects at kickoff and extracts historically realized risks as candidates for the current register',
    'Risks and opportunities are both captured in the Risk Register with explicit fields distinguishing risk type (threat vs. opportunity)'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 1.2', practiceGroup:'PG 1 – Identify Risks and Opportunities',
  practiceStatement:'Identify and document the sources and categories of risks and opportunities.',
  elaboration:'Categorizing risks by source (technical, schedule, resource, external, organizational) helps ensure comprehensive coverage and makes patterns visible. Defined risk categories and source taxonomies are maintained in the organizational risk management standards and used consistently across all projects.',
  examples:[
    'The Risk Management Standard defines six risk categories (Technical, Schedule, Cost, Resource, External, Compliance) and a source taxonomy; all projects use the same taxonomy',
    'Each risk in the Risk Register is tagged with a category and source, enabling filtered views and trend analysis across the portfolio',
    'A risk source analysis at project close identifies which categories contributed the most realized risks, informing updates to the risk identification checklist',
    'Third-party/supplier risks are tracked as a distinct source category with separate ownership assigned to the procurement manager'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 2.1', practiceGroup:'PG 2 – Plan Mitigation',
  practiceStatement:'Evaluate and prioritize risks and opportunities using defined risk parameters.',
  elaboration:'Risks are evaluated on defined parameters — typically probability of occurrence and impact magnitude — to produce a risk priority score. Priority scores enable the project team to focus mitigation resources on the risks that matter most. Evaluation criteria and scales are defined in the organizational risk management standards and applied consistently.',
  examples:[
    'Each risk is scored using a 5×5 probability/impact matrix; the resulting risk score and priority tier (High/Medium/Low) are recorded in the Risk Register and reviewed at each risk review cycle',
    'The Risk Management Standard specifies that all risks scoring "High" must have a mitigation plan within 5 business days of identification',
    'Risk scores are recalculated at each status meeting to reflect changes in probability or impact; the score history is maintained in the register to show trends',
    'Opportunities are evaluated on probability and benefit magnitude; High-benefit opportunities are assigned owners and actively pursued with documented action plans'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 2.2', practiceGroup:'PG 2 – Plan Mitigation',
  practiceStatement:'Develop a risk mitigation strategy for managing the risks and opportunities.',
  elaboration:'The risk mitigation strategy defines the overall approach for handling risks — which risks to mitigate, transfer, accept, or avoid — and identifies the resources and funding set aside for risk response. The strategy is documented in the project plan and establishes the governance framework within which individual mitigation plans operate.',
  examples:[
    'The Project Management Plan includes a Risk Management section documenting the risk strategy: which risks are mitigated vs. accepted, the contingency reserve percentage, and escalation thresholds',
    'High-priority technical risks are assigned mitigation strategies of "mitigate" with funding from the project contingency reserve; accepted risks are documented with the rationale for acceptance',
    'The risk strategy specifies that schedule risks with impact >2 weeks require escalation to the sponsor; this threshold is recorded in the plan',
    'Opportunity strategies (exploit, enhance, share, accept) are documented for each opportunity in the register alongside the threat strategies'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 2.3', practiceGroup:'PG 2 – Plan Mitigation',
  practiceStatement:'Develop and maintain risk and opportunity mitigation plans.',
  elaboration:'Individual mitigation plans specify the concrete actions taken to reduce probability or impact of a specific risk, the owner responsible for executing the plan, trigger conditions for executing contingency actions, and the schedule for completing mitigation activities. Plans must be tracked and updated as risk status changes.',
  examples:[
    'Each High and Medium risk in the Risk Register has a linked Mitigation Plan Card specifying: mitigation actions, contingency plan, trigger event, owner, and target completion date',
    'Mitigation plans are reviewed and updated at every bi-weekly risk review; the review date and any changes to the plan are recorded in the register',
    'A risk mitigation plan for a key-person dependency risk includes cross-training actions with completion dates; training completion is tracked as the mitigation measure',
    'Contingency triggers are explicitly defined (e.g., "If the vendor delays delivery beyond Day 30, activate contingency supplier"); trigger conditions are monitored at each review'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 3.1', practiceGroup:'PG 3 – Implement Mitigation',
  practiceStatement:'Implement risk mitigation plans as appropriate to reduce the likelihood or impact of risks materializing.',
  elaboration:'Implementing the mitigation plan means the defined mitigation actions are executed and evidence of execution is documented. This is where many organizations fail — they have plans but no execution evidence. The Risk Register must show the actual status of mitigation activities, not just that a plan exists.',
  examples:[
    'The Risk Register includes a "Mitigation Actions Status" column showing completed, in-progress, and planned actions for each risk; completed actions are marked with a completion date',
    'Cross-training scheduled as a key-person risk mitigation action is verified by training completion records; the records are cross-referenced in the risk register',
    'Technical risk mitigations such as spikes or proof-of-concepts are tracked as project tasks in Jira; the Risk Register is updated when the task closes',
    'The PQA team verifies that all High-priority risk mitigation plans have documented execution evidence during monthly process audits'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'RSK', paFull:'Risk and Opportunity Management',
  paDesc:'Identify potential problems and opportunities before they occur so that risk-handling activities can be planned and invoked as needed across the life of the project.',
  practiceNum:'RSK 3.2', practiceGroup:'PG 3 – Implement Mitigation',
  practiceStatement:'Monitor the status of each risk and opportunity periodically and adjust mitigation plans as appropriate.',
  elaboration:'Risk management is a continuous activity. Monitoring tracks changes in risk probability, impact, and mitigation effectiveness and triggers plan adjustments when conditions change. Risks that materialize become issues and transition to the corrective action log. Risks that are no longer relevant are closed with a documented rationale.',
  examples:[
    'Risk status is reviewed at every project status meeting; the Risk Register shows the current status (Open/Monitoring/Closed/Realized) and the date of last update for each entry',
    'When a risk\'s probability rating increases from Low to High between review cycles, the risk owner is required to update the mitigation plan within 48 hours and notify the PM',
    'Realized risks are transferred to the corrective action log; the Risk Register records the date realized and a reference to the corrective action',
    'At project close, all risks are reviewed; open risks and their current status are documented in the project closeout report and transferred to the lessons-learned archive'
  ],
  level:'ML3', domain:'All'
},

/* ── PCM — Process Capability Management ─────────────────────────────── */
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 1.1', practiceGroup:'PG 1 – Establish Process Capability Baselines',
  practiceStatement:'Establish and maintain process performance baselines from process performance data.',
  elaboration:'A process performance baseline is a characterized range of expected performance derived from historical data. Baselines are computed using statistical methods (central tendency, standard deviation, control limits) and represent stable process behavior. They are the organizational reference point against which future performance is assessed.',
  examples:[
    'Control charts for defect density (defects per KLOC) and schedule performance index (SPI) are computed from 24 months of project data and published in the Measurement Repository as the organizational baselines',
    'Process performance baselines are documented in the Process Performance Baseline Report, which includes the data sources, time range, statistical method used, and control limits',
    'New baselines are established when a statistically significant process change occurs; the old baseline is archived with a notation of why it was superseded',
    'Baseline reports are reviewed and approved by the process group and the quality manager before being published to the organization'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 1.2', practiceGroup:'PG 1 – Establish Process Capability Baselines',
  practiceStatement:'Establish and maintain quantitative objectives for quality and process performance for the organization.',
  elaboration:'Quantitative objectives express desired performance levels in measurable terms (e.g., "defect escape rate <0.5 per release", "schedule performance index >0.9"). They are derived from business goals and calibrated against the established baselines to ensure they are ambitious but achievable. Objectives drive both project planning and organizational improvement.',
  examples:[
    'The Organization\'s Quality and Process Performance Objectives document lists five quantitative objectives (defect density, test coverage, on-time delivery, review effectiveness, customer satisfaction) each with a target value and measurement source',
    'Quantitative objectives are reviewed annually; the review compares prior-year actuals against objectives and updates targets as warranted',
    'Projects create Quantitative Project Management Plans that derive project-level objectives from the organizational objectives and document how performance will be monitored against them',
    'Each objective has a defined measurement protocol specifying what data is collected, how, by whom, and at what frequency'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 2.1', practiceGroup:'PG 2 – Establish Process Performance Models',
  practiceStatement:'Select processes and sub-processes to be included in the organization\'s process capability management effort.',
  elaboration:'Not every process is managed quantitatively at ML3 — the organization selects the subset of processes that have the most significant impact on business outcomes and for which sufficient data exists to enable quantitative management. The selection is documented and justified against organizational objectives.',
  examples:[
    'The Process Capability Management Plan identifies five sub-processes for quantitative management: requirements stability, design review defect detection, build success rate, test effectiveness, and deployment cycle time',
    'Process selection criteria include: business impact, data availability, and direct linkage to a quantitative objective; the selection rationale is documented',
    'The selected processes are reviewed annually; processes with insufficient data are replaced with better-instrumented alternatives',
    'Each selected process has a named owner responsible for maintaining baselines and models and for monitoring performance'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 2.2', practiceGroup:'PG 2 – Establish Process Performance Models',
  practiceStatement:'Establish and maintain process performance models for the organization\'s set of standard processes.',
  elaboration:'Process performance models predict future performance based on current measures and process inputs. They can be statistical regression models, simulation models, or empirically derived formulas. Models enable projects to predict whether they are likely to achieve their quality and process performance objectives while there is still time to take action.',
  examples:[
    'A regression model predicting defect escape rate from peer review preparation time and checklist adherence score is developed and validated against two years of project data',
    'Process performance models are documented with: the model purpose, formula/equation, input variables, valid range of applicability, accuracy metrics, and calibration history',
    'Projects use the build-success-rate model during sprint planning to predict integration risk based on the number of components being integrated simultaneously',
    'Models are recalibrated at least annually using the most recent two years of data; calibration results are recorded and old model versions are archived'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 3.1', practiceGroup:'PG 3 – Apply Process Capability Management',
  practiceStatement:'Use process performance baselines and models to quantitatively manage the project\'s defined processes to achieve the project\'s quality and process performance objectives.',
  elaboration:'Using baselines and models for management means that project decisions (go/no-go at gates, sprint adjustments, escalations) are grounded in quantitative data rather than intuition. Project status reporting includes quantitative performance against baselines, and corrective actions are triggered by statistical signals rather than schedule slippage alone.',
  examples:[
    'Phase gate reviews include a quantitative performance dashboard comparing actual defect density, test coverage, and SPI against the organizational baselines and project objectives; the gate decision is documented with the data',
    'Mid-sprint, the test effectiveness model predicts a 15% shortfall in defect detection; the project team decides to add one additional test cycle before release, documented in the sprint plan',
    'A control chart for build success rate shows a data point outside the upper control limit; the project manager opens a corrective action before the next status meeting',
    'Project status reports contain a "Quantitative Performance" section with current values, baseline, trend direction, and "on track / at risk / action required" disposition for each tracked measure'
  ],
  level:'ML3', domain:'All'
},
{
  pa:'PCM', paFull:'Process Capability Management',
  paDesc:'Establish and maintain quantitative understanding of the performance of selected processes to support achieving quality and process performance objectives.',
  practiceNum:'PCM 3.2', practiceGroup:'PG 3 – Apply Process Capability Management',
  practiceStatement:'Use process performance data, baselines, and models to support management decisions and take action to address shortfalls.',
  elaboration:'Baselines and models are only valuable if they are used to make decisions. This practice requires evidence that management acted on quantitative data — not just that the data was collected. Corrective actions tied to specific statistical signals are the strongest appraisal evidence.',
  examples:[
    'When the defect escape rate exceeds the upper control limit, the quality manager triggers a CAR analysis; the CAR record references the control chart data point that triggered the action',
    'An organizational-level Process Performance Review is conducted quarterly; the review uses baseline charts for all selected processes and produces a written disposition (stable/improving/degrading) with management decisions recorded',
    'A project showing a negative SPI trend three periods in a row is escalated to a management review before the trend becomes a milestone miss; the escalation and the decision made are documented',
    'Annual calibration of process performance objectives uses the prior year\'s actuals and baseline charts; objective changes and their rationale are recorded in the revision history of the objectives document'
  ],
  level:'ML3', domain:'All'
},
/* ═══════════════════════════════════════════════════════════════════════════
   ML3 — DEVELOPMENT DOMAIN  (domain = 'Development')
═══════════════════════════════════════════════════════════════════════════ */

/* ── TS — Technical Solution ───────────────────────────────────────────── */
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 1.1', practiceGroup:'PG 1 – Select a Technical Solution',
  practiceStatement:'Select technical solution approaches from alternative solutions.',
  elaboration:'Selecting a technical solution requires evaluating candidate approaches against defined criteria including functionality, performance, cost, risk, schedule, and compliance. The selection must be documented with rationale — a solution chosen without recorded evaluation is indistinguishable from a guess at appraisal time. Technical solution selection should leverage formal DAR where significant trade-offs exist.',
  examples:[
    'An Architecture Decision Record (ADR) documents the three architectural approaches considered for the microservices integration layer, the evaluation criteria applied, and the rationale for the selected event-driven approach',
    'A build-vs-buy analysis evaluating five COTS options against defined functional and non-functional criteria is stored in the design folder with the final selection justified in writing',
    'The technical solution selection meeting minutes include: participants, alternatives discussed, criteria weighed, and the agreed solution — signed off by the lead architect and system engineer',
    'Where solution selection involves a cost or risk threshold that meets the DAR trigger criteria, a formal DAR record is produced and referenced in the design documentation'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 1.2', practiceGroup:'PG 1 – Select a Technical Solution',
  practiceStatement:'Develop conceptual designs to serve as the basis for selecting a technical solution.',
  elaboration:'Conceptual designs provide enough detail to evaluate feasibility, risk, and fit without the full cost of a detailed design. They describe the proposed architecture, major components, key interfaces, and technology choices at a high level. The conceptual design is used to inform and support the solution selection process.',
  examples:[
    'High-level block diagrams and a component responsibility description are produced for each candidate architecture before the solution selection review',
    'Conceptual designs include a risk section identifying the top three technical risks for each candidate, enabling risk-informed solution selection',
    'The conceptual design document is reviewed by the systems engineering team and the security architect before the architecture decision is finalized',
    'Conceptual designs are stored in the version control system as versioned design artifacts with a naming convention traceable to the associated requirement set'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 2.1', practiceGroup:'PG 2 – Develop the Design',
  practiceStatement:'Develop a detailed design for each product component.',
  elaboration:'Detailed design translates the selected technical solution into implementable specifications — data models, class diagrams, sequence diagrams, API contracts, state machines, and algorithm descriptions. Detailed designs must be consistent with the conceptual design, traceable to requirements, and complete enough that a developer can implement from them without ambiguity.',
  examples:[
    'For each major service, a detailed design document is produced covering: data models (ER diagrams), REST API contracts (OpenAPI spec), sequence diagrams for all key flows, and error handling logic',
    'The detailed design is reviewed in a design review session with the checklist item "Is the design traceable to requirements?" — the review record shows all checklist items resolved',
    'Design documents are maintained in the version control system alongside the code; each commit references the design document version it implements',
    'Design-to-requirement traceability is verified by the systems engineer: each detailed design element is linked to at least one requirement ID in the traceability matrix'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 2.2', practiceGroup:'PG 2 – Develop the Design',
  practiceStatement:'Establish and maintain a complete and consistent description of product and product-component interfaces.',
  elaboration:'Interface descriptions define how components communicate and depend on one another — data formats, protocols, calling conventions, error codes, and timing constraints. Complete interface definitions prevent integration failures. Interfaces between internal components and between the product and external systems must both be defined.',
  examples:[
    'An Interface Control Document (ICD) is produced for each service-to-service interface, defining: endpoint URLs, HTTP methods, request/response schemas (JSON), authentication, and error codes',
    'The API contract is managed as an OpenAPI YAML file in the repository; changes to the contract go through a pull request review before merging',
    'A system context diagram identifies all external interfaces; each is documented with a named data exchange, protocol, owner, and version',
    'Interface design reviews are conducted with the teams on both sides of each interface to confirm mutual understanding before implementation begins'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 2.3', practiceGroup:'PG 2 – Develop the Design',
  practiceStatement:'Evaluate whether the product and product components will be developed, purchased, or reused.',
  elaboration:'The make-buy-reuse decision for each product component should be based on explicit evaluation of options against cost, risk, schedule, and technical criteria. This decision influences supplier agreements (SAM), integration planning (PI), and verification requirements (VV). Undocumented make-buy decisions create hidden risk.',
  examples:[
    'A Make-Buy-Reuse Decision Matrix is completed for each major component; options are evaluated against criteria including cost, time-to-deliver, technical risk, support, and license compliance',
    'The decision to reuse an internal library is documented with a compatibility analysis confirming that the library version meets the current requirements',
    'A decision to purchase a COTS authentication component is documented and triggers initiation of a supplier agreement per the SAM process',
    'Components flagged for reuse from a previous project are verified against current requirements before the reuse decision is finalized; the verification result is recorded'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 3.1', practiceGroup:'PG 3 – Implement the Design',
  practiceStatement:'Implement the designs of product components.',
  elaboration:'Implementation of the design means writing code (or fabricating hardware, configuring systems) in accordance with the approved detailed design, using the organization\'s coding standards, and following the approved life cycle model. The implementation must be traceable to the design and ultimately to requirements.',
  examples:[
    'The coding standards document is referenced in every pull request template; reviewers check that submitted code conforms to naming conventions, error handling, and documentation standards before approving',
    'Each code module includes a header comment referencing the design document section it implements; the CI pipeline validates the presence of required header fields',
    'Developers are required to link their commit messages to a Jira story ID; the requirement-to-code traceability report is generated from these links',
    'Static analysis tools (linting, SAST) are integrated in the CI pipeline and must pass before a build is marked successful; failures are logged and tracked to resolution'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'TS', paFull:'Technical Solution',
  paDesc:'Design, develop, and implement solutions to requirements, including design, product code, and documentation.',
  practiceNum:'TS 3.2', practiceGroup:'PG 3 – Implement the Design',
  practiceStatement:'Develop and maintain the end-use documentation necessary to install, operate, maintain, and support the product.',
  elaboration:'End-use documentation is a work product of the development process, not an afterthought. It must be developed alongside the product, reviewed, and maintained under configuration management. Documentation includes user guides, administrator guides, installation instructions, release notes, and maintenance documentation.',
  examples:[
    'The Definition of Done (DoD) for every user story includes "user-facing documentation updated"; stories cannot be closed until the DoD is satisfied',
    'A documentation review is conducted as part of each release gate; the gate checklist includes verification that installation guide, release notes, and user manual are complete and up to date',
    'End-use documentation is stored in the version control repository alongside the code and follows the same branching and release tagging convention',
    'The technical writer participates in sprint reviews to validate that documentation accurately reflects the implemented functionality before a feature is accepted'
  ],
  level:'ML3', domain:'Development'
},

/* ── PI — Product Integration ─────────────────────────────────────────── */
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 1.1', practiceGroup:'PG 1 – Prepare for Product Integration',
  practiceStatement:'Establish and maintain an integration strategy for the product components.',
  elaboration:'The integration strategy defines the sequence in which product components will be assembled, the rationale for that sequence, the integration environment to be used, the procedures to follow, and the criteria for declaring each integration step successful. A defined sequence prevents "big-bang" integration failures where root causes are impossible to isolate.',
  examples:[
    'A Product Integration Strategy document is produced during the design phase, defining the integration build sequence (bottom-up), the continuous integration environment, and the pass/fail criteria for each integration increment',
    'The integration sequence is graphically depicted as a build ladder or dependency diagram; the diagram is reviewed with the test lead before integration begins',
    'The integration strategy is updated when component dependencies change; all changes go through the CM change control process',
    'Risk-based integration sequencing puts the highest-risk interfaces first, with rationale documented in the integration strategy'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 1.2', practiceGroup:'PG 1 – Prepare for Product Integration',
  practiceStatement:'Establish and maintain the product integration environment.',
  elaboration:'The integration environment includes the hardware, software, tools, simulators, test harnesses, and infrastructure needed to assemble and test integrated product components. The environment must be established, validated, and version-controlled before integration activities begin to prevent environment-related failures from contaminating integration test results.',
  examples:[
    'The integration environment is described in an Environment Setup Runbook stored in the repository; a new engineer can reproduce the environment from the runbook alone',
    'The CI/CD pipeline (GitHub Actions / Jenkins) configuration is stored in version control; environment changes require a pull request with a code review before merging',
    'Before integration testing begins, an environment readiness checklist is completed confirming that all required services, test data, and infrastructure are in place',
    'Integration environment versions are recorded in each integration test report so that test results can be reproduced and environment differences can be investigated'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 2.1', practiceGroup:'PG 2 – Manage Interfaces',
  practiceStatement:'Review interface descriptions for coverage and completeness.',
  elaboration:'Interface descriptions are reviewed to confirm that all interfaces are identified, that each interface is described with sufficient detail for implementation, and that there are no gaps or contradictions between the interface definitions of connecting components. Interface coverage reviews should involve teams on both sides of each interface.',
  examples:[
    'An interface coverage review is conducted during design review; the review checklist requires checking that every component boundary in the architecture diagram has a corresponding ICD entry',
    'Interface description completeness is verified using a checklist that checks for: data types, error codes, versioning scheme, authentication, rate limits, and backward compatibility policy',
    'Cross-team interface review meetings are documented with attendees, issues raised, resolutions agreed, and any interface change requests submitted as a result',
    'Interface gaps discovered during implementation are logged as defects against the design, corrected in the ICD, and re-reviewed before the affected components are integrated'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 2.2', practiceGroup:'PG 2 – Manage Interfaces',
  practiceStatement:'Manage internal and external interface definitions, designs, and changes throughout the project.',
  elaboration:'Interface management is an ongoing CM activity. Interface definitions are baselined and all changes processed through change control. External interfaces — to customer systems, third-party services, or supplier-provided components — require additional coordination and impact analysis before changes are accepted.',
  examples:[
    'Interface Control Documents (ICDs) are placed under CM baseline at the same time as the associated detailed design; changes to ICDs require an approved change request',
    'An interface change impact analysis is performed whenever an ICD change is proposed; the analysis identifies all components affected and is reviewed with their owners before the change is approved',
    'External interface changes to APIs consumed by customers go through a formal change notification process with a defined deprecation period; customer notification records are kept',
    'The integration test suite includes interface contract tests (e.g., Pact or schema validation) that run on every build and catch interface regressions automatically'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 3.1', practiceGroup:'PG 3 – Assemble and Deliver the Product',
  practiceStatement:'Confirm that each product component required for integration has been properly identified, satisfies its interface requirements, and meets the criteria for integration.',
  elaboration:'Integration readiness confirmation prevents defective components from entering the integration sequence and contaminating downstream results. Each component must demonstrate that it meets its acceptance criteria — typically through successful unit testing, peer review sign-off, and interface conformance testing — before it is cleared for integration.',
  examples:[
    'An Integration Readiness Checklist is completed for each component before it is assembled into the integration build; the checklist verifies unit test pass rate, review closure, and interface conformance test results',
    'The CI pipeline enforces integration gates: a component branch can only merge to the integration branch if all unit tests pass, code coverage threshold is met, and static analysis shows no critical findings',
    'Integration readiness is formally confirmed in a pre-integration review meeting; the meeting minutes record which components were cleared and which were deferred with the reason',
    'Deferred components are tracked in the integration readiness log; deferral reasons are analyzed for trends to identify systemic causes'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 3.2', practiceGroup:'PG 3 – Assemble and Deliver the Product',
  practiceStatement:'Assemble the product components according to the integration sequence and available procedures.',
  elaboration:'Assembly follows the defined integration sequence and documented integration procedures. Deviations from the planned sequence must be documented with justification. Integration build logs capture what was assembled, when, in what environment, and with what result, providing a complete audit trail of the integration history.',
  examples:[
    'Each integration build is executed by the CI/CD pipeline and produces a build log recording: components integrated, their versions, environment, build number, timestamp, and pass/fail outcome',
    'Integration procedures are documented as runbook scripts in the repository; manual integration steps are minimized and those that remain are documented with screenshots or outputs as execution evidence',
    'When the integration sequence must be deviated from (e.g., a component is unavailable), the deviation is documented in the integration log with the technical justification',
    'Each delivered build artifact is tagged with a version number and the corresponding integration build record is stored in the CM system'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PI', paFull:'Product Integration',
  paDesc:'Assemble product components and deliver the product — ensuring that integrated components function correctly together.',
  practiceNum:'PI 3.3', practiceGroup:'PG 3 – Assemble and Deliver the Product',
  practiceStatement:'Evaluate assembled product components to confirm integration results satisfy their requirements and integration criteria.',
  elaboration:'Post-assembly evaluation verifies that the assembled product (or increment) functions correctly as a whole — not just that the individual components were successfully integrated. Integration tests, regression tests, and interface conformance tests are executed and results are evaluated against defined criteria before the product is accepted for delivery or the next integration increment.',
  examples:[
    'Integration test results are recorded in the test execution report showing test case ID, expected vs. actual result, pass/fail, and tester; the report is reviewed by the test lead before the build is promoted',
    'Regression test suite runs are required after every integration build; a failing test blocks promotion to the next environment until the defect is resolved and verified',
    'The integration acceptance criteria are defined in the integration strategy; the integration completion report confirms each criterion was evaluated and its pass/fail result',
    'Defects found during integration evaluation are logged in the defect tracking system and resolution is verified before the integration is declared complete'
  ],
  level:'ML3', domain:'Development'
},

/* ── VV — Verification and Validation ─────────────────────────────────── */
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 1.1', practiceGroup:'PG 1 – Prepare for Verification and Validation',
  practiceStatement:'Select work products to be verified and the verification methods to be used for each.',
  elaboration:'Not all work products require the same verification approach. Selection identifies which work products will be verified, what method is appropriate for each (inspection, demonstration, test, analysis), and what level of rigor is required. The selection is risk-based and documented in the verification plan. Omitting a work product from verification without documented rationale is a compliance gap.',
  examples:[
    'The Verification and Validation Plan lists every major work product by life cycle phase, the verification method assigned (test/inspection/analysis/demonstration), and the responsible team',
    'High-risk work products (security modules, safety-critical components) receive formal inspections in addition to testing; the risk-based rationale is recorded in the VV Plan',
    'Requirements documents and design documents are verified by inspection; code is verified by automated testing and peer review; integrated product is verified by system test — all explicitly mapped in the V&V plan',
    'The VV plan is version-controlled and updated whenever scope or approach changes; the change history shows what was changed and why'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 1.2', practiceGroup:'PG 1 – Prepare for Verification and Validation',
  practiceStatement:'Establish and maintain the verification and validation environment, criteria, and procedures.',
  elaboration:'The V&V environment must be representative of the deployment environment to the extent required for meaningful results. Entry and exit criteria for each V&V activity define when to start (entry) and what constitutes successful completion (exit). Documented procedures ensure V&V activities are repeatable and results are comparable across teams and projects.',
  examples:[
    'The test environment configuration is documented in the Environment Specification; it specifies hardware specs, OS versions, middleware, test data sets, and network configuration — all matching the production environment within defined tolerances',
    'Test entry criteria (e.g., "integration build passes smoke test", "test data loaded") and exit criteria (e.g., "90% test cases executed, 0 open Severity 1 defects") are documented in the Test Plan',
    'Test procedures are written as step-by-step scripts with expected results; procedures are reviewed by a peer before the first execution',
    'The validation environment for UAT is seeded with production-representative data and approved by the customer representative before UAT begins'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 2.1', practiceGroup:'PG 2 – Perform Verification',
  practiceStatement:'Perform verification of selected work products using the established criteria and procedures.',
  elaboration:'Verification execution must follow the defined procedures and be documented. Results — pass, fail, deviation — are recorded for every test case or inspection item. Incomplete execution (stopping before all test cases are run) must be documented with justification. Verification results are the primary evidence that requirements have been met.',
  examples:[
    'Test execution records in the test management tool (Jira Xray, TestRail, Zephyr) show test case ID, execution date, tester, result, and any linked defects',
    'Inspection records for requirements and design documents include: reviewer name, items inspected, defects found (with IDs), and disposition of each defect',
    'Automated test run results are published as pipeline artifacts; each pipeline run record shows the commit hash, test counts (passed/failed/skipped), and a link to the detailed results',
    'When test execution is incomplete at a milestone, an open items list is produced documenting which test cases were not executed, the reason, and the plan to complete them'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 2.2', practiceGroup:'PG 2 – Perform Verification',
  practiceStatement:'Analyze the verification results and identify corrective actions.',
  elaboration:'Verification results analysis goes beyond counting pass/fail to identifying patterns, root causes of failures, and implications for quality objectives. Defects found during verification are logged in the defect tracking system, triaged, assigned, and tracked to resolution. Analysis determines whether re-verification is required after corrections.',
  examples:[
    'After each test cycle, a Test Summary Report is produced analyzing: test execution rate, defect discovery rate by severity, defect trends, areas of highest defect density, and recommendation for release readiness',
    'All verification-found defects are logged in Jira with severity, priority, component, and description; deferred defects require product owner and QA manager sign-off',
    'Verification exit criteria include a defect aging analysis — no Severity 1 or 2 defects open for >5 days without a documented action plan',
    'When verification failures cluster around a specific component or requirement area, a targeted CAR analysis is triggered to identify systemic causes'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 3.1', practiceGroup:'PG 3 – Perform Validation',
  practiceStatement:'Select work products and validation methods to demonstrate that the product fulfills its intended use.',
  elaboration:'Validation confirms that the right product was built — that it works correctly in its intended operational environment with real or representative users. Validation selection is distinct from verification selection and focuses on end-to-end use cases, operational scenarios, and acceptance criteria defined by the customer or intended users.',
  examples:[
    'The V&V Plan includes a Validation section identifying that the completed system will be validated via User Acceptance Testing (UAT) with defined operational scenarios agreed with the customer',
    'Validation methods for each system capability are matched to the operational environment: field testing, simulation, beta deployment, or live demonstration',
    'Acceptance Test Procedures (ATPs) are written based on the customer\'s acceptance criteria and reviewed with the customer before UAT begins',
    'Validation planning identifies the representative users who will participate in UAT and obtains their formal agreement to participate before the validation phase begins'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'VV', paFull:'Verification and Validation',
  paDesc:'Ensure work products meet their specified requirements (verification) and that a product fulfills its intended use in its target environment (validation).',
  practiceNum:'VV 3.2', practiceGroup:'PG 3 – Perform Validation',
  practiceStatement:'Perform validation of selected work products and analyze the results.',
  elaboration:'Validation execution is performed with the intended users or customer in the target environment using the defined acceptance test procedures. Results are documented and evaluated against acceptance criteria. Failures are analyzed to determine whether they indicate a requirements gap, an implementation defect, or a mismatch between the product and the actual operational environment.',
  examples:[
    'UAT execution records include: scenario ID, tester (named customer representative), execution date, actual result, pass/fail, and any issues raised',
    'Customer sign-off on UAT results is obtained per the acceptance test procedure; the signed acceptance is stored in the project record under CM',
    'Validation failures are triaged in a defect review with the customer to distinguish "product defect" from "requirement misunderstanding"; the triage outcome determines whether a code fix or requirement change is needed',
    'A Validation Summary Report is produced at the end of UAT documenting: scenarios executed, pass/fail counts, open issues, and the customer\'s formal acceptance decision'
  ],
  level:'ML3', domain:'Development'
},

/* ── PR — Peer Reviews ────────────────────────────────────────────────── */
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 1.1', practiceGroup:'PG 1 – Prepare for Peer Reviews',
  practiceStatement:'Select the work products to be peer reviewed.',
  elaboration:'Peer reviews should be performed on work products where early defect detection provides the greatest return on investment — typically requirements, designs, test plans, and critical code modules. The selection is documented and updated as the project evolves. Not selecting all work products for peer review is acceptable as long as the selection rationale is documented and risk-based.',
  examples:[
    'The project\'s Peer Review Plan lists the work products to be reviewed (requirements specification, design documents, test plans, code modules above a complexity threshold) with the selection rationale for each',
    'All work products listed as "mandatory review" in the organization\'s standard process are reviewed without exception; additional work products may be added by project decision',
    'The Peer Review Plan is reviewed at each phase gate to ensure the work product selection remains appropriate given actual project scope and risk',
    'Work products excluded from peer review are listed in the plan with an explicit rationale (e.g., "generated code not requiring peer review per standard tooling exemption")'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 1.2', practiceGroup:'PG 1 – Prepare for Peer Reviews',
  practiceStatement:'Establish and maintain entry and exit criteria for peer reviews.',
  elaboration:'Entry criteria define what must be true before a peer review begins (e.g., the work product is complete and checked into CM, reviewers have been assigned, materials have been distributed with enough lead time). Exit criteria define what must be satisfied before the reviewed work product is approved (e.g., all major defects are dispositioned, action items are assigned). Without entry/exit criteria, "reviews" become rubber stamps.',
  examples:[
    'The Peer Review Procedure defines entry criteria: "Author has completed the work product, placed it in CM, distributed materials to reviewers at least 2 business days before the review"',
    'Exit criteria specify: "All Severity 1 and 2 defects are assigned, all action items have owners and due dates, review moderator signs off on completeness"',
    'Review preparation time is recorded in the peer review log and compared against the entry criterion (min. 2 business days); reviews that fail the entry criterion are postponed',
    'Entry and exit criteria are posted in the team wiki and referenced in every review invitation to ensure reviewers know what is expected before and after the session'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 2.1', practiceGroup:'PG 2 – Conduct Peer Reviews',
  practiceStatement:'Prepare for peer reviews by performing the actions required prior to the review.',
  elaboration:'Effective peer reviews require individual preparation before the group session. Reviewers examine the work product against defined criteria (using checklists) before meeting, so the group session focuses on resolving issues rather than finding them in real time. Evidence of individual preparation (preparation time logged, checklist completed) is required to demonstrate that reviews are substantive.',
  examples:[
    'Reviewers record preparation time on the Peer Review Data Sheet; the data sheet is collected by the moderator before the review session and stored as a project artifact',
    'Review checklists are distributed with the review package; completed checklists showing defects annotated per reviewer are required as entry evidence',
    'Pull request descriptions include a "Self-review checklist" completed by the author and a separate reviewer checklist attached by each code reviewer before approval',
    'Pre-review issue lists are aggregated by the moderator before the session so duplicate findings can be merged and discussion time is used efficiently'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 2.2', practiceGroup:'PG 2 – Conduct Peer Reviews',
  practiceStatement:'Conduct peer reviews on selected work products and record the defects found.',
  elaboration:'During the review session, defects and issues are identified, discussed, and recorded. Every defect must be logged — not just "noted". The review record captures defects with enough detail for the author to reproduce and correct them. Role clarity (moderator, recorder, reviewers, author) ensures the session is efficient and the record is complete.',
  examples:[
    'Peer review meeting minutes include: date, attendees with roles (moderator, recorder, reviewer, author), work product reviewed (name, version), defects list with severity, description, and action owner',
    'Code review defects are documented as GitHub/GitLab comments with a "defect" label; the author cannot merge until all labeled defects are resolved and the reviewer re-approves',
    'A Defect Log spreadsheet is completed during formal design reviews; the log is stored in CM alongside the reviewed work product and the corrected version',
    'The moderator confirms exit criteria are met at the end of the session and records the disposition (Approved / Approved with minor corrections / Re-review required) in the review record'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 3.1', practiceGroup:'PG 3 – Analyze Peer Review Data',
  practiceStatement:'Analyze data about the peer review preparation, conduct, and results.',
  elaboration:'Peer review data — preparation time, review time, defect counts by type and severity, defect injection phase — reveals the effectiveness and efficiency of the review process. Analysis identifies whether reviews are finding enough defects early, whether checklists are effective, and whether specific work product types have higher defect densities warranting additional attention.',
  examples:[
    'Monthly peer review metrics reports track: average preparation time per review, average defects found per review, defect density by work product type, and percentage of defects classified as major',
    'Defect type trend analysis from the review data log shows that "missing error handling" consistently accounts for 30% of code review defects; the finding is used to update the code review checklist',
    'Review effectiveness rate (defects found in review / total defects found) is tracked over time; a declining rate triggers a review of the checklist and entry/exit criteria',
    'Peer review data is reported in the project status report alongside test data to give management a full view of quality indicators across the development process'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'PR', paFull:'Peer Reviews',
  paDesc:'Remove defects from work products early and efficiently using a structured examination by peers.',
  practiceNum:'PR 3.2', practiceGroup:'PG 3 – Analyze Peer Review Data',
  practiceStatement:'Use data about the preparation, conduct, and results of peer reviews to improve the peer review process and the development processes.',
  elaboration:'Peer review data is a process improvement input. Insights from review analysis drive updates to checklists, changes to which work products are reviewed, improvements to development practices (e.g., coding standards updates based on commonly found defect types), and adjustments to review effort standards.',
  examples:[
    'Annually, the peer review data is reviewed by the process group; checklist items with near-zero detection rates are replaced with items targeting defect types found frequently in actual reviews',
    'When code review data shows that 60% of defects are found in files with high cyclomatic complexity, the development standard is updated to require peer review for all functions exceeding a complexity threshold',
    'A PR 3.2 improvement action — adding a security-focused checklist for authentication-related code — is submitted as a Process Improvement Proposal and tracked through the PAD process to deployment',
    'Review process improvements are communicated to all team members via the process change notification; affected checklists in the PAL are updated with the change history noted'
  ],
  level:'ML3', domain:'Development'
},
/* ═══════════════════════════════════════════════════════════════════════════
   ML2 — CORE PRACTICE AREAS  (domain = 'All')
═══════════════════════════════════════════════════════════════════════════ */

/* ── PLAN — Planning ──────────────────────────────────────────────────── */
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 1.1', practiceGroup:'PG 1 – Establish Context',
  practiceStatement:'Establish and maintain the project\'s shared vision, objectives, and high-level scope.',
  elaboration:'A shared vision aligns the project team and stakeholders on why the project exists, what success looks like, and what is within and outside the project boundary. It prevents scope creep and misaligned expectations. The vision is documented, communicated at project kickoff, and revisited when significant changes occur.',
  examples:['A Project Charter signed by the sponsor defines the business objective, high-level scope, key constraints, and success criteria before planning begins','Vision and scope are presented and confirmed at the project kickoff meeting; attendance and agreement are recorded in the meeting minutes','The scope boundary is documented with an explicit "Out of Scope" list; changes to scope require a formal scope change request','The project objectives are SMART (Specific, Measurable, Achievable, Relevant, Time-bound) and linked to the organizational business case'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 1.2', practiceGroup:'PG 1 – Establish Context',
  practiceStatement:'Establish and maintain the top-level work breakdown structure to organize the scope of the project.',
  elaboration:'The WBS decomposes the project scope into manageable work packages that can be estimated, assigned, and tracked. Every deliverable in the project scope should appear in the WBS. The WBS is the foundation for scheduling, resourcing, and cost estimation.',
  examples:['A WBS is produced covering all project deliverables to at least three levels of decomposition; work packages at the lowest level have named owners','The WBS is maintained in the project management tool (MS Project, Jira, etc.) and updated when scope changes are approved','The WBS dictionary defines each work package with a description, responsible party, inputs, outputs, and estimated duration','The PM reviews the WBS with the technical lead to confirm no work has been omitted before the schedule is built'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.1', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Establish and maintain the project schedule, including milestones, dependencies, and critical path.',
  elaboration:'The project schedule translates the WBS into a time-based plan. It must identify the critical path, show task dependencies, and be resource-loaded. Milestones represent meaningful checkpoints (deliverable completion, phase gates) and are used to track progress.',
  examples:['The project schedule in MS Project or Azure DevOps shows tasks with durations, dependencies, milestones, and resource assignments; the critical path is visible','Sprint planning artifacts (backlog, sprint goals, velocity charts) serve as the schedule for Agile teams; release milestones are defined in the product roadmap','The schedule is baselined after stakeholder approval and the baseline is retained for variance tracking','Schedule updates are made at least weekly; the version history shows what changed and when'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.2', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Identify and document project dependencies and constraints and address them in the plan.',
  elaboration:'Dependencies (internal and external) and constraints (resource availability, regulatory deadlines, technology limitations) directly affect the project schedule and risk profile. Documenting them ensures they are visible, owned, and managed rather than discovered as surprises.',
  examples:['A Dependencies and Constraints Register lists each dependency with a type (internal/external), owning party, impact if not resolved, and status','External dependencies on supplier deliverables are tracked in the project plan with the contract delivery date and an internal lead time buffer','Schedule constraints (fixed end date, regulatory filing deadline) are recorded in the project plan header and drive the schedule baseline','The project manager reviews the dependencies register at each status meeting and escalates unresolved external dependencies to the sponsor'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.3', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Plan for stakeholder involvement throughout the project.',
  elaboration:'Stakeholder planning identifies who has an interest in or influence over the project, what role they will play, when they need to be engaged, and how they will be communicated with. Without stakeholder planning, critical stakeholders are forgotten until their absence causes problems.',
  examples:['A Stakeholder Register is produced at project initiation identifying each stakeholder with their role, influence level, engagement approach, and communication preference','A Communication Plan defines communication events (status reports, reviews, demos), frequency, audience, format, and owner for each','The stakeholder plan is reviewed and updated at each phase transition to account for new stakeholders or changed engagement needs','Review and approval sequences (who must review and approve each deliverable) are specified in the project plan'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.4', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Plan for the management of project data and knowledge artifacts.',
  elaboration:'Data management planning addresses how project work products, records, and data will be organized, stored, controlled, and retained. This prevents data loss, unauthorized changes, and inability to retrieve critical records for audits or appraisals.',
  examples:['The Data Management Plan specifies the repository structure, naming conventions, access controls, backup schedule, and retention period for all project work products','Version control policies are documented: all baseline work products are stored in the CM system; working copies may be local but must be committed before a milestone review','The project plan identifies data requiring special handling (personally identifiable information, export-controlled data) and the controls required for each','New team members complete a data management orientation before receiving access to project repositories'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.5', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Plan for the knowledge and skills needed to perform the project.',
  elaboration:'Skills planning identifies the competencies required for each project role and assesses whether current team members possess them. Gaps between required and available skills are addressed through training, hiring, or staffing adjustments. Skills planning inputs into the OT tactical training plan.',
  examples:['A Skills Matrix for the project lists each role, required competencies, current team members, and a gap assessment; training requests are submitted for identified gaps','The project plan includes a staffing section identifying planned headcount by role, onboarding dates, and any required certifications or training before work begins','Skills gaps are communicated to the OT function as training requests with the desired completion date and the project impact if training is delayed','For new technologies or methodologies, training is scheduled before the project phase where those skills are needed, not after it begins'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 2.6', practiceGroup:'PG 2 – Develop the Plan',
  practiceStatement:'Plan for the work environment needed to perform the project.',
  elaboration:'The work environment includes tools, infrastructure, facilities, and access to resources needed to execute the project. Environment planning ensures these are available when needed, avoiding delays caused by missing tools or access not arranged in advance.',
  examples:['An Environment Plan lists all required tools and infrastructure, the expected availability date, the owner responsible for provisioning, and the current status','New development environments are provisioned and verified before the first sprint begins; the verification record is stored with the project plan','Tool licenses are identified in the environment plan and procurement is initiated with enough lead time to prevent work stoppages','Environment readiness is reviewed at the project kickoff meeting; unresolved environment items are tracked as open action items'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 3.1', practiceGroup:'PG 3 – Obtain Commitment',
  practiceStatement:'Review all plans that affect the project with relevant stakeholders and reconcile differences.',
  elaboration:'Plans are reviewed to ensure that all stakeholders understand their commitments and that cross-plan dependencies and conflicts are resolved before work begins. Interface plans (supplier plans, dependent project plans) are reviewed jointly where they affect the project schedule or deliverables.',
  examples:['The project plan review meeting is held with the project sponsor, key stakeholders, and team leads; attendance, issues raised, and resolutions are recorded in meeting minutes','Plan inconsistencies identified during review (e.g., resource double-booked across projects) are escalated to the resource manager and resolved before the plan is baselined','The technical plan, risk plan, and resource plan are reviewed together to confirm they are internally consistent','Supplier plans are reviewed against the project schedule to confirm alignment on delivery dates before the project baseline is approved'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 3.2', practiceGroup:'PG 3 – Obtain Commitment',
  practiceStatement:'Reconcile the project plan to reflect available and estimated resources.',
  elaboration:'When the unconstrained schedule does not fit within available resources or time, scope, staffing, schedule, or objectives must be adjusted and the plan reconciled. The reconciliation process — what was changed, why, and who approved — must be documented.',
  examples:['When the initial schedule shows a 6-week overrun against the required delivery date, the PM negotiates scope reduction with the sponsor; the approved scope changes and revised schedule are documented','Resource leveling analysis is performed in the project management tool to resolve over-allocations; the leveled schedule is compared to the baseline and any milestone impacts are documented','The reconciled plan is formally approved by the sponsor before the project is baselined; the approval email or signed plan cover sheet is retained','If the required delivery date cannot be met with available resources, the conflict is escalated to management with documented options and a recommendation'],
  level:'ML2', domain:'All'
},
{
  pa:'PLAN', paFull:'Planning',
  paDesc:'Establish and maintain plans that define project activities.',
  practiceNum:'PLAN 3.3', practiceGroup:'PG 3 – Obtain Commitment',
  practiceStatement:'Obtain commitment from project participants to support plan execution.',
  elaboration:'Each project participant responsible for executing or supporting the plan must formally acknowledge their commitment — that they understand what is expected, when, and that they have the capacity to deliver. Without explicit commitment, accountability is unclear and delivery surprises are inevitable.',
  examples:['Team members sign off on the project plan or are recorded as "confirmed" in the kickoff meeting minutes, indicating they accept their assigned roles and responsibilities','Resource commitment is obtained from functional managers before team members are assigned to the project; the commitment is documented in writing (email or resource form)','Commitments from external suppliers are documented in the supplier agreement per the SAM process','Sprint commitments are recorded in the sprint backlog; the sprint commitment is agreed upon in the sprint planning meeting with the full team present'],
  level:'ML2', domain:'All'
},

/* ── MC — Monitor and Control ─────────────────────────────────────────── */
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 1.1', practiceGroup:'PG 1 – Monitor the Work',
  practiceStatement:'Monitor actual values of project planning parameters against the project plan.',
  elaboration:'Monitoring compares actual performance (schedule, cost, scope, effort, quality metrics) to the planned values at defined intervals. Monitoring is only meaningful if actual data is collected and compared to a documented baseline — narrative updates without data do not satisfy this practice.',
  examples:['Weekly project status reports include a schedule table showing planned vs. actual completion dates for all tasks in the current reporting period with variance calculated','Sprint velocity charts show planned story points vs. completed story points per sprint; cumulative flow diagrams show work-in-progress trends','An earned value dashboard shows planned value (PV), earned value (EV), actual cost (AC), schedule performance index (SPI), and cost performance index (CPI) at weekly intervals','Monitoring data is recorded in the project management tool and retained for the project record; snapshots are taken at each milestone'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 1.2', practiceGroup:'PG 1 – Monitor the Work',
  practiceStatement:'Monitor stakeholder involvement against the project plan.',
  elaboration:'Stakeholder engagement is as much a project parameter as schedule and cost. Monitoring confirms that planned stakeholder activities (reviews, approvals, input sessions) are occurring as planned and that stakeholder issues or disengagement are detected early.',
  examples:['The Stakeholder Engagement Log tracks planned vs. actual participation in key reviews and approval milestones; missed engagements are noted as risks','Project status reports include a "Stakeholder Engagement" section noting whether key reviews and approvals are on track','When a required stakeholder does not attend a review, the PM follows up within 24 hours to reschedule; the follow-up and outcome are documented','Customer/sponsor disengagement is flagged in the risk register as a risk; the monitoring trigger is two consecutive missed review meetings'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 2.1', practiceGroup:'PG 2 – Analyze and Address Issues',
  practiceStatement:'Analyze issues that arise in performing the project to determine what corrective actions are needed.',
  elaboration:'When monitoring reveals a deviation, the cause must be analyzed before a corrective action is defined. Jumping to a corrective action without analysis risks treating a symptom rather than the cause and wasting resources on ineffective fixes.',
  examples:['When SPI drops below 0.85 two weeks in a row, the PM convenes an issue analysis session; the root cause and proposed corrective actions are documented in the Corrective Action Log','Sprint retrospectives identify impediments and their causes; the retrospective output is linked to corrective actions tracked in the project log','Issue analysis is documented on the Corrective Action Log entry with: issue description, root cause analysis, recommended corrective action, and expected outcome','For significant deviations, a formal problem report is written and presented to the project sponsor with impact assessment and proposed recovery options'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 2.2', practiceGroup:'PG 2 – Analyze and Address Issues',
  practiceStatement:'Take corrective actions on identified issues.',
  elaboration:'Corrective actions adjust the project plan, staffing, scope, or approach to address performance deviations. They must be documented, assigned to an owner, and scheduled. A corrective action that is "discussed but not documented" provides no evidence for an appraisal.',
  examples:['Each corrective action in the Corrective Action Log has: description, owner, target completion date, and the plan element (schedule, cost, scope) being adjusted','Re-planning sessions prompted by schedule slippage produce a revised plan with the rationale for changes documented in the plan revision history','Corrective actions are reviewed at every status meeting; the project dashboard shows open corrective actions with aging','Immediate corrective actions (e.g., adding resource to a critical path task) are documented in the status report before the next reporting period'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 2.3', practiceGroup:'PG 2 – Analyze and Address Issues',
  practiceStatement:'Manage corrective actions to closure and verify their effectiveness.',
  elaboration:'Closing a corrective action requires confirming that the action was executed AND that it resolved the deviation. An action closed without effectiveness verification may allow the deviation to persist or recur.',
  examples:['Corrective actions are marked closed in the log only after the PM verifies that the planned action was executed and the next reporting period shows the deviation reduced or resolved','The corrective action log captures the closure date, evidence of execution, and an effectiveness assessment ("deviation resolved / partially resolved / not resolved")','"Not resolved" corrective actions trigger a new analysis cycle; the pattern is noted and may be escalated to CAR if systemic','Closed corrective actions are reviewed at monthly project retrospectives to confirm the fixes are holding; recurring issues are escalated to the process group'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 3.1', practiceGroup:'PG 3 – Review Status',
  practiceStatement:'Review project status, including accomplishments and results, with higher-level management periodically.',
  elaboration:'Higher-level management reviews provide oversight and escalation support. They are distinct from internal team reviews — they involve people with authority to allocate resources, adjust organizational priorities, or escalate supplier issues. Without documented management reviews, governance is absent.',
  examples:['Monthly project steering committee meetings review project health (schedule, cost, risk, issues); meeting minutes record attendees, status presented, decisions made, and action items','A project status dashboard is published to executive leadership weekly; the dashboard shows RAG (Red/Amber/Green) status with trend arrows for each key parameter','The PM presents a formal project status briefing to the sponsor at each phase gate; the briefing deck and sponsor sign-off are stored in the project record','When a project status is Red, an escalation meeting with the sponsor is scheduled within 48 hours; the meeting and decisions are documented'],
  level:'ML2', domain:'All'
},
{
  pa:'MC', paFull:'Monitor and Control',
  paDesc:'Provide an understanding of project progress so that corrective actions can be taken when performance deviates significantly from plan.',
  practiceNum:'MC 3.2', practiceGroup:'PG 3 – Review Status',
  practiceStatement:'Analyze project performance data to identify trends and determine appropriate adjustments.',
  elaboration:'Trend analysis looks across multiple monitoring periods to detect patterns — consistently slipping velocity, growing defect backlog, increasing cost overrun — before they become crises. Trend data drives proactive management actions rather than reactive responses to milestone misses.',
  examples:['A 6-sprint velocity trend chart shows a downward slope; the PM triggers a staffing analysis and identifies a recently onboarded team member as requiring additional coaching','Monthly EVM trend charts (SPI and CPI over time) are included in the management report; the chart is annotated with the corrective actions taken at key inflection points','Defect trend analysis shows a 20% increase in post-release defects over the last three sprints; the trend prompts a VV and PR process audit','Trend analysis data is recorded in the project performance history and used in project closeout reporting and as inputs to the organizational estimating database'],
  level:'ML2', domain:'All'
},

/* ── CM — Configuration Management ───────────────────────────────────── */
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 1.1', practiceGroup:'PG 1 – Establish Baselines',
  practiceStatement:'Identify the configuration items and other work products to be placed under configuration management.',
  elaboration:'Configuration items (CIs) are the work products whose integrity must be controlled — typically requirements, design documents, source code, test cases, build scripts, and delivered products. The CM plan lists CIs by type, names, location, owner, and the baseline at which each is placed under control.',
  examples:['The CM Plan identifies all CIs with their names, types, responsible parties, storage location, and the lifecycle event that triggers placing them under CM control (e.g., "requirements document — at completion of requirements review")','All source code, build scripts, infrastructure-as-code, and configuration files are in the Git repository; the CM Plan explicitly names the repository as the CM system for these types','A CI inventory is produced at project initiation and updated whenever new CIs are identified or the scope changes','Test cases and test automation scripts are included in the CI list and managed in the same version control system as the source code'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 1.2', practiceGroup:'PG 1 – Establish Baselines',
  practiceStatement:'Establish and maintain a configuration management and change management system.',
  elaboration:'The CM system is the tooling and process infrastructure that stores, controls, and tracks configuration items. The change management system processes change requests to CIs through a defined approval workflow. Both systems must be established before baselines are created.',
  examples:['Git serves as the CM system for code; Confluence with page versioning is the CM system for documents; the CM Plan describes both systems and the CIs stored in each','Branch protection rules require pull request review and approval before merging to main; the merge history provides an immutable audit trail of all changes','A Change Request (CR) form and workflow are defined in the CM Plan; CRs for baselined CIs require impact analysis and approval before implementation','CM system access controls are documented: read access is broad, write access to protected branches is restricted to authorized roles'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 1.3', practiceGroup:'PG 1 – Establish Baselines',
  practiceStatement:'Create or release baselines for internal use and for delivery to the customer.',
  elaboration:'A baseline is a reviewed and approved snapshot of one or more CIs that serves as the official reference point for future work and change control. Baselines are created at defined lifecycle events (end of requirements, end of design, release candidate). Each baseline must be uniquely identified and immutable after creation.',
  examples:['Release tags in Git (e.g., v1.2.0) create immutable code baselines; the CI/CD pipeline builds from tags only and records the tag ID in the deployment record','Requirements baseline is established at the completion of the requirements review; the baselined requirements document is locked in the document management system and changes require a CR','A baseline inventory is maintained listing all baselines created to date: name, version, contents, creation date, approver, and status (active/superseded)','Customer delivery packages are built from the approved baseline tag; the delivery record includes the baseline ID, delivery date, and customer receipt confirmation'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 2.1', practiceGroup:'PG 2 – Track and Control Changes',
  practiceStatement:'Track change requests for configuration items from origination to disposition.',
  elaboration:'Every proposed change to a baselined CI must be captured in a change request and tracked through a defined approval process to a final disposition (approved, rejected, or deferred). Change requests that bypass the formal process undermine baseline integrity and are a primary appraisal failure point.',
  examples:['The CR log tracks each request from submission through impact analysis, review, disposition, and implementation; the log is accessible to all project stakeholders','Change requests are submitted via a standard CR form capturing: CI affected, description of change, requester, impact on schedule/cost/risk, and recommended disposition','GitHub Issues or Jira tickets are used as the change request system for code changes; each issue follows the defined triage and approval workflow before being assigned to a sprint','CR disposition metrics (approval rate, average time to disposition, open vs. closed ratio) are reported in the monthly project status report'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 2.2', practiceGroup:'PG 2 – Track and Control Changes',
  practiceStatement:'Control changes to configuration items under configuration management.',
  elaboration:'Controlling changes means that CIs can only be modified through approved changes — unauthorized modifications are prevented by the CM system access controls and detected by audits. The change control process ensures that each change is reviewed, approved by the appropriate authority, and documented.',
  examples:['Protected branch rules in Git prevent direct pushes to main/master; all changes require an approved pull request with at least one code reviewer sign-off','Document changes to baselined requirements require an approved CR; the updated document version is stored in CM with the CR number in the revision history','The CM process specifies approval authorities by CI type and impact level: low-impact code changes require one reviewer; high-impact security changes require two reviewers and a security lead','Emergency changes follow an expedited approval path (verbal approval from designated authority followed by written confirmation within 24 hours); the emergency change log is separate and audited monthly'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 2.3', practiceGroup:'PG 2 – Track and Control Changes',
  practiceStatement:'Maintain records to describe the status of configuration items.',
  elaboration:'Status accounting provides a current and historical record of the state of each CI — what version it is at, what changes have been made, what baseline it belongs to, and what the disposition of change requests against it has been. Status records enable the team and auditors to answer "what is the current state of X and how did it get there?"',
  examples:['The Git commit history provides a complete status accounting for all code CIs: commit message, author, timestamp, and diff for every change','A Configuration Status Accounting report is produced monthly showing all CIs, their current version, baseline membership, and last approved change','Document version history tables within each document show version, date, author, and description of changes; the table is updated with every approved revision','The CR tracking system links each completed CR to the updated CI version, closing the audit trail loop from request to implementation'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 3.1', practiceGroup:'PG 3 – Establish Integrity',
  practiceStatement:'Establish and maintain the integrity of the baselines.',
  elaboration:'Baseline integrity means that the contents of each baseline are exactly as approved and have not been changed without an authorized change request. Integrity is maintained through access controls that prevent unauthorized modification and through periodic audits that verify the baseline has not been altered.',
  examples:['Immutable tags in Git ensure that once a release baseline is tagged, the tag cannot be moved or deleted without specific admin authority and an audit trail','Hash values (SHA-256) of all baseline deliverables are recorded at the time of baselining; a monthly integrity check recomputes hashes and flags any mismatches','The CM system enforces that document CIs in the baseline folder require explicit checkout/checkin with a CR number before changes can be saved','Baseline integrity monitoring is automated: a nightly script compares current CI hashes against the baseline manifest and emails the CM manager if discrepancies are found'],
  level:'ML2', domain:'All'
},
{
  pa:'CM', paFull:'Configuration Management',
  paDesc:'Establish and maintain the integrity of work products using configuration identification, control, status accounting, and audits.',
  practiceNum:'CM 3.2', practiceGroup:'PG 3 – Establish Integrity',
  practiceStatement:'Perform configuration audits to confirm that the configuration baselines and associated documentation are accurate and complete.',
  elaboration:'CM audits (functional and physical) verify that the actual state of CIs matches their documented state and that the processes governing changes are being followed. Audits detect unauthorized changes, missing documentation, and process non-compliance. Audit findings must be tracked and resolved.',
  examples:['A Functional Configuration Audit (FCA) is performed before each major release, verifying that all test cases pass against the release baseline','A Physical Configuration Audit (PCA) compares the released product contents against the baseline manifest; discrepancies are logged and resolved before delivery','Quarterly CM process audits by the PQA function check that change requests are being processed per the CM procedure; audit findings are tracked to closure','CM audit results are reported to the project sponsor and process group; repeat audit findings trigger a CAR analysis'],
  level:'ML2', domain:'All'
},

/* ── PQA — Process Quality Assurance ──────────────────────────────────── */
{
  pa:'PQA', paFull:'Process Quality Assurance',
  paDesc:'Provide staff and management with objective insight into the processes and associated work products.',
  practiceNum:'PQA 1.1', practiceGroup:'PG 1 – Evaluate Processes and Products',
  practiceStatement:'Objectively evaluate selected performed processes against applicable process descriptions, standards, and procedures.',
  elaboration:'Process evaluations (audits) verify that work is being performed according to the defined process — not just that deliverables exist. Objectivity requires that the evaluator be independent of the work being evaluated. PQA findings are not based on personal judgment but on documented evidence of compliance or non-compliance with the standard.',
  examples:['An independent QA auditor (not a member of the project team) conducts a monthly process audit using a standard checklist covering CMMI-required process areas; the audit report lists compliant and non-compliant items with evidence references','PQA audit schedule is published at the start of each project quarter; audits cover a rotating set of practice areas so all required areas are covered across the project lifecycle','Process audit findings are classified as Major (process step skipped or output missing) or Minor (documentation incomplete) and tracked to resolution in the QA findings log','PQA audit reports are distributed to the project manager and the process owner within 5 business days of the audit'],
  level:'ML2', domain:'All'
},
{
  pa:'PQA', paFull:'Process Quality Assurance',
  paDesc:'Provide staff and management with objective insight into the processes and associated work products.',
  practiceNum:'PQA 1.2', practiceGroup:'PG 1 – Evaluate Processes and Products',
  practiceStatement:'Objectively evaluate selected work products against applicable process descriptions, standards, and procedures.',
  elaboration:'Work product evaluations review the content and quality of deliverables (requirements documents, design documents, test plans, code) against defined acceptance criteria. They complement process audits by verifying that the outputs of processes meet standards, not just that the process steps were followed.',
  examples:['QA reviews the requirements specification against the Requirements Management Standard checklist before the requirements baseline is established; findings are logged in the QA findings log','Code quality audits are performed on a sample of merged code each sprint to check conformance with the coding standard; findings are reported to the development lead within 2 business days','Work product evaluations use a standard checklist that maps each checklist item to the applicable standard or procedure it is checking','QA findings that reveal systemic work product quality issues are escalated to the process group for consideration as CAR inputs'],
  level:'ML2', domain:'All'
},
{
  pa:'PQA', paFull:'Process Quality Assurance',
  paDesc:'Provide staff and management with objective insight into the processes and associated work products.',
  practiceNum:'PQA 2.1', practiceGroup:'PG 2 – Provide Objective Insight',
  practiceStatement:'Communicate quality issues and noncompliance with process descriptions, standards, and procedures and ensure their resolution.',
  elaboration:'Non-compliance identified in audits must be communicated to the relevant staff and management and tracked until resolved. QA is not just a reporting function — it ensures that issues are addressed, not ignored. Escalation to higher management is required when the responsible party does not resolve non-compliance within the agreed timeframe.',
  examples:['Non-compliance findings are entered in the QA findings log within 24 hours of the audit; the responsible party receives an email notification with the finding description, severity, and resolution deadline','Open QA findings are reviewed at the monthly project management meeting; findings overdue by more than 5 business days are escalated to the project sponsor','When repeated non-compliance is found in the same area, the QA manager escalates to the process owner and senior management with a written summary of the pattern','Resolution evidence (updated document version, corrected process execution record) is reviewed by the QA auditor before a finding is closed; QA confirms closure independently'],
  level:'ML2', domain:'All'
},
{
  pa:'PQA', paFull:'Process Quality Assurance',
  paDesc:'Provide staff and management with objective insight into the processes and associated work products.',
  practiceNum:'PQA 2.2', practiceGroup:'PG 2 – Provide Objective Insight',
  practiceStatement:'Establish and maintain records of quality assurance activities.',
  elaboration:'QA records provide the audit trail of quality oversight activities — what was evaluated, when, by whom, what was found, and how findings were resolved. Complete QA records are essential for appraisals and are frequently the first artifacts requested by an appraiser team.',
  examples:['QA records for each project include: audit schedule, completed audit checklists, audit reports, QA findings log, and closure evidence — all stored in the project QA folder in SharePoint','The QA findings log is maintained throughout the project lifecycle and is not purged; it is submitted as part of the project close-out package','QA records are version-controlled and include the auditor name, date of audit, and the process or work product evaluated','Annual QA records are reviewed by the QA manager for completeness; gaps identified prompt corrective action'],
  level:'ML2', domain:'All'
},
{
  pa:'PQA', paFull:'Process Quality Assurance',
  paDesc:'Provide staff and management with objective insight into the processes and associated work products.',
  practiceNum:'PQA 3.1', practiceGroup:'PG 3 – Manage Quality Assurance',
  practiceStatement:'Establish and maintain an organizational quality assurance approach aligned to organizational standards and objectives.',
  elaboration:'The QA approach defines how quality assurance is structured across the organization — the independence model, audit methodology, coverage requirements, escalation paths, and how QA feeds into process improvement. The approach ensures QA is consistent and effective across all projects, not dependent on individual auditor judgment.',
  examples:['The Organizational QA Plan describes the QA function\'s charter, independence requirements, audit coverage model (which processes and work products are audited), frequency, reporting structure, and escalation path','The QA approach is reviewed and updated annually; updates reflect process changes, appraisal findings, and lessons learned from the year\'s QA activities','New QA auditors complete a standardized onboarding program covering the audit methodology, use of checklists, finding classification, and escalation procedures before conducting independent audits','QA effectiveness is measured by defect escape rate (defects found post-delivery vs. total defects) and QA finding closure rate; results are reported to senior management quarterly'],
  level:'ML2', domain:'All'
},

/* ── GOV — Governance ─────────────────────────────────────────────────── */
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 1.1', practiceGroup:'PG 1 – Define Expectations',
  practiceStatement:'Establish and communicate organizational expectations for performing the work and the organization\'s processes.',
  elaboration:'Expectations define the standards and behaviors all staff and projects must meet. They include process compliance requirements, quality standards, escalation paths, and reporting obligations. Communicated expectations that are not documented and accessible are insufficient — staff must be able to find and reference them.',
  examples:['The Organizational Process Expectations document is published on the intranet home page and emailed to all staff at the start of each year with a required acknowledgment','Project kickoff agendas include a "Process and Governance Expectations" agenda item; the PM reviews the relevant standards with the team','New hire onboarding includes a session on organizational process expectations; the completion of this session is recorded in the employee record','Annual process expectations reviews are held with department managers to confirm expectations are current and understood'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 1.2', practiceGroup:'PG 1 – Define Expectations',
  practiceStatement:'Establish and communicate the knowledge and skills needed to fulfill governance responsibilities.',
  elaboration:'Governance requires that the people responsible for enforcing policies and making oversight decisions have the knowledge and skills to do so effectively. This includes managers understanding process requirements, metric interpretation, escalation thresholds, and their own accountability.',
  examples:['Manager onboarding includes a "CMMI Governance for Managers" module covering their responsibilities for process compliance, review, and escalation','An annual manager refresher briefing covers any process changes and their governance implications; attendance is recorded','Role-specific governance competencies are included in job descriptions and performance review criteria for management roles','The process group conducts a quarterly governance Q&A forum for managers to resolve ambiguities in process expectations'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 2.1', practiceGroup:'PG 2 – Manage Accountability',
  practiceStatement:'Establish accountability for achieving organizational process and performance objectives.',
  elaboration:'Accountability means that specific individuals are named as responsible for process performance and business outcomes — not teams or roles in the abstract. Accountability structures (RACI charts, performance agreements, process ownership assignments) define who answers for what.',
  examples:['Each organizational process has a named Process Owner with accountability for maintaining the process definition, ensuring training, and reviewing compliance findings','Project managers sign a Project Accountability Statement at project initiation confirming their accountability for project performance and process compliance','Performance objectives in manager job performance reviews include measurable process and quality performance targets tied to their area of accountability','The governance framework document maps each CMMI practice area to an organizational owner who is accountable for that PA\'s implementation across the organization'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 2.2', practiceGroup:'PG 2 – Manage Accountability',
  practiceStatement:'Review implementation of organizational processes and address identified shortfalls.',
  elaboration:'Governance reviews examine whether processes are being implemented as expected across the organization. They use objective data (audit findings, performance metrics, QA reports) and hold accountable parties responsible for shortfalls. Reviews without documented actions are insufficient.',
  examples:['The monthly Process Governance Review meeting brings together process owners and senior management to review QA audit summaries, process performance metrics, and open findings; meeting minutes record all decisions and actions','Governance review outcomes are tracked in the governance action log; actions overdue by more than 30 days are escalated to the executive sponsor','When PQA audit data shows a persistent non-compliance pattern across multiple projects, the governance review triggers a process improvement initiative','Governance review records are maintained for at least three years and are made available to appraisal teams on request'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 3.1', practiceGroup:'PG 3 – Establish Governance Infrastructure',
  practiceStatement:'Establish and maintain organizational policies, rules, and standards for governance of processes.',
  elaboration:'Policies are the authoritative statements of what must be done; standards define how it must be done; rules set the non-negotiable boundaries. Together they form the governance framework that applies across all projects and organizational units. The framework must be documented, accessible, and maintained.',
  examples:['The Policy and Standards Library in the intranet hosts all organizational policies, each with an effective date, owner, review cycle, and version history','Policies are reviewed and reapproved at least annually by the document owner; the review date and approver are recorded in the document header','New policies are communicated via an all-staff announcement and added to the new-hire onboarding checklist','The governance framework document provides an index of all policies and standards, organized by process area, with links to the full documents'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 3.2', practiceGroup:'PG 3 – Establish Governance Infrastructure',
  practiceStatement:'Review and adjust governance mechanisms based on performance data and lessons learned.',
  elaboration:'Governance is not static — it must evolve as the organization learns what works and what does not. Performance data, audit findings, appraisal results, and lessons learned from projects inform adjustments to governance mechanisms.',
  examples:['The annual governance review produces a written assessment of governance effectiveness including: QA finding trends, governance action closure rates, and process performance trends, with recommended governance updates','Appraisal findings and improvement recommendations are reviewed by the governance body and converted to governance action items with owners and due dates','When a governance mechanism is found to be ineffective (e.g., an annual review is too infrequent to catch issues), the frequency is adjusted and the change is documented in the governance framework','Process improvement proposals that affect governance mechanisms are reviewed and approved by the governance body before implementation'],
  level:'ML2', domain:'All'
},
{
  pa:'GOV', paFull:'Governance',
  paDesc:'Ensure that management has the visibility and oversight needed to direct and sustain organizational performance.',
  practiceNum:'GOV 3.3', practiceGroup:'PG 3 – Establish Governance Infrastructure',
  practiceStatement:'Establish mechanisms to sustain process performance improvements organization-wide.',
  elaboration:'Sustaining improvements requires embedding them in the governance infrastructure — updating policies, training staff, monitoring compliance, and holding people accountable for using the improved processes. Improvements that are not institutionalized typically regress when the people who championed them move on.',
  examples:['Approved process improvements are embedded in standard processes, reflected in updated governance policies, and incorporated into training curricula before they are declared "deployed"','A 90-day post-deployment compliance check is conducted for all significant process improvements; results are reported to the governance body','Process improvement sustainability is tracked as a performance metric (% of projects using updated process); a declining trend triggers a governance action','The governance framework includes a defined mechanism for sustaining improvements: update standard process → update training → communicate → monitor compliance → review effectiveness'],
  level:'ML2', domain:'All'
},

/* ── II — Implementation Infrastructure ───────────────────────────────── */
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 1.1', practiceGroup:'PG 1 – Identify Infrastructure Needs',
  practiceStatement:'Identify the resources, tools, methods, and environments needed to implement and support the organization\'s set of processes.',
  elaboration:'Infrastructure needs are derived from the process definitions — each process step may require specific tools, templates, or environments. Identifying needs before the processes are deployed ensures that infrastructure is available when needed and gaps are addressed proactively.',
  examples:['When a new process is added to the PAL, a corresponding infrastructure needs analysis is completed identifying required tools, templates, and environments','The Infrastructure Needs Register lists each identified need, the process it supports, current availability status, and the owner responsible for provisioning','Annual process reviews include an infrastructure needs assessment to confirm that existing infrastructure still meets current process requirements','New project kickoffs include an environment readiness check against the required infrastructure list; unmet needs are flagged as risks'],
  level:'ML2', domain:'All'
},
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 1.2', practiceGroup:'PG 1 – Identify Infrastructure Needs',
  practiceStatement:'Prioritize infrastructure needs based on process requirements and organizational objectives.',
  elaboration:'Not all infrastructure needs can be addressed simultaneously. Prioritization ensures that the most critical gaps (those that would prevent required processes from being performed) are addressed first. Prioritization decisions are documented with rationale.',
  examples:['The Infrastructure Needs Register includes a priority column (Critical/High/Medium/Low) with documented rationale; Critical items are reviewed by management and have committed resolution dates','Infrastructure investment decisions are reviewed in the annual budgeting cycle; priority needs are backed by budget allocation before the fiscal year begins','Low-priority infrastructure items are re-evaluated each quarter; those remaining low-priority for more than two cycles are formally accepted as known risks or removed from the register','Critical infrastructure gaps identified mid-year are escalated to senior management for emergency budget approval'],
  level:'ML2', domain:'All'
},
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 2.1', practiceGroup:'PG 2 – Provide Infrastructure',
  practiceStatement:'Establish and maintain infrastructure to support implementation and performance of processes.',
  elaboration:'Establishing infrastructure means physically procuring, configuring, and verifying the tools, environments, and resources needed by processes. "Established" infrastructure is available, tested, and documented — not just procured.',
  examples:['CI/CD pipelines are documented with configuration files in the repository; a setup verification test is run after each major configuration change','Templates, checklists, and standard forms are stored in the PAL with clear naming, versioning, and access instructions','New tool implementations include a validation step confirming the tool performs as expected before projects are required to use it','Infrastructure provisioning is tracked in the Infrastructure Register; each item shows provisioning date, configurator, and validation status'],
  level:'ML2', domain:'All'
},
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 2.2', practiceGroup:'PG 2 – Provide Infrastructure',
  practiceStatement:'Make infrastructure available to those who need it and provide support for its use.',
  elaboration:'Infrastructure that is provisioned but not accessible to or usable by the people who need it provides no value. Access provisioning, onboarding documentation, and user support must accompany the infrastructure itself.',
  examples:['All staff are granted access to the PAL during onboarding; access provisioning is confirmed in the onboarding checklist','Infrastructure guides and quick-start documents are provided for each major tool; guides are stored in the PAL alongside the tools they support','A support channel (help desk, Slack channel, or office hours) is available for staff who need assistance using organizational tools and process infrastructure','New tools are introduced with a training session and Q&A; attendance records and the materials presented are retained as II evidence'],
  level:'ML2', domain:'All'
},
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 3.1', practiceGroup:'PG 3 – Improve Infrastructure',
  practiceStatement:'Evaluate the effectiveness of implementation infrastructure using performance data and staff feedback.',
  elaboration:'Infrastructure effectiveness evaluation determines whether the tools, templates, and environments are actually supporting process performance. Evaluation uses usage data, feedback surveys, process performance metrics, and audit observations. Infrastructure that is available but unused is a quality problem.',
  examples:['An annual Infrastructure Effectiveness Survey is conducted with project staff; results are analyzed and low-rated items trigger a review','PAL usage analytics show which templates are most and least used; low-usage items are reviewed to determine whether they are meeting staff needs','Infrastructure evaluation findings are documented in the Infrastructure Effectiveness Report and reviewed by the process group and senior management','QA audit observations about tools not being used per the defined process are reported as II effectiveness issues and tracked to resolution'],
  level:'ML2', domain:'All'
},
{
  pa:'II', paFull:'Implementation Infrastructure',
  paDesc:'Provide infrastructure to support implementation of processes.',
  practiceNum:'II 3.2', practiceGroup:'PG 3 – Improve Infrastructure',
  practiceStatement:'Improve implementation infrastructure based on evaluation results and identified gaps.',
  elaboration:'Evaluation findings are acted upon — outdated templates are revised, unused tools are replaced, access barriers are removed. Improvements are tracked as action items with owners and due dates. The Infrastructure Register is updated to reflect changes.',
  examples:['Infrastructure improvement actions from the annual evaluation are logged in the Infrastructure Improvement Tracker with owner, due date, and status; actions are reviewed monthly','A template rated "confusing" in the staff survey is revised by the process owner within 30 days; the revision is announced to all users and the PAL is updated','Retired infrastructure items are removed from the PAL and the Infrastructure Register is updated; users are notified of the retirement and any replacement','Process performance improvements that result from infrastructure upgrades are noted in the improvement tracker and reported as II effectiveness outcomes'],
  level:'ML2', domain:'All'
},

/* ── MPM — Managing Performance and Measurement ───────────────────────── */
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 1.1', practiceGroup:'PG 1 – Establish Measurement Approach',
  practiceStatement:'Establish and maintain measurement objectives derived from organizational and project information needs.',
  elaboration:'Measurement objectives define why data is being collected — what management decision or performance question each measurement is meant to inform. Without measurement objectives, measures are collected because they are easy, not because they are useful. The GQM (Goal-Question-Metric) approach is commonly used to derive objectives from business goals.',
  examples:['A Measurement Plan is produced at project initiation using the GQM approach; each measurement objective is linked to a project or organizational goal with a stated information need','Measurement objectives are reviewed with the project sponsor at kickoff to confirm they address the sponsor\'s most important management questions','The organizational measurement objectives are reviewed annually; obsolete objectives are retired and new objectives are added when organizational priorities change','Measurement objectives and their associated measures are documented in the Measurement Repository for visibility across projects'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 1.2', practiceGroup:'PG 1 – Establish Measurement Approach',
  practiceStatement:'Specify measures to address the measurement objectives.',
  elaboration:'Measures must be operationally defined — the formula, unit of measure, data source, collection method, and collection frequency must be specified so any team member can collect and compute the measure consistently. Vaguely defined measures produce inconsistent data that cannot be used for management decisions.',
  examples:['Each measure in the Measurement Plan has an operational definition specifying: name, formula, unit, data source, collection frequency, and responsible party','The operational definition for "defect density" specifies: count of unique defects found in formal testing / KLOC (excluding auto-generated code) — data from Jira test cycle results','Measures are reviewed for alignment with measurement objectives; measures without a traceable objective are eliminated from the plan','New measures are piloted for one reporting period before being added to the standard reporting dashboard to confirm they can be collected as defined'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 2.1', practiceGroup:'PG 2 – Collect and Analyze Data',
  practiceStatement:'Obtain and store measurement data according to the defined procedures.',
  elaboration:'Data collection procedures define how, when, and by whom measurement data is gathered. Following the procedures ensures data quality and consistency. Data storage in a defined repository ensures that data is accessible for analysis and is not lost when team members change.',
  examples:['Sprint velocity data is automatically captured from Jira at sprint close; the measurement repository (a shared spreadsheet or database) is updated by the Scrum Master within 24 hours','Defect data from the test management tool is exported to the measurement repository on a weekly schedule; the export is automated and logged','Data collection procedures are documented in the Measurement Plan; any manual collection steps are described with step-by-step instructions to ensure consistency','Data quality checks are built into the collection process: entries with missing required fields are flagged before being accepted into the repository'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 2.2', practiceGroup:'PG 2 – Collect and Analyze Data',
  practiceStatement:'Analyze measurement data and report results to relevant stakeholders.',
  elaboration:'Analysis transforms raw data into actionable insight — comparing actuals to targets, computing trends, identifying outliers, and generating conclusions. Analysis results are communicated to the decision-makers who need them, at the frequency they need them, in a format they can use.',
  examples:['A weekly metrics dashboard is published to all project stakeholders showing KPIs with trend arrows and traffic-light status against targets','Monthly management reports include measurement analysis sections with charts, trend commentary, and management actions taken in response to measurement data','Analysis results that reveal performance at risk are immediately flagged to the project manager and sponsor rather than waiting for the next regular report cycle','Measurement analysis is a standing agenda item on the project status meeting; the analysis drives discussion rather than being presented as background information'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 2.3', practiceGroup:'PG 2 – Collect and Analyze Data',
  practiceStatement:'Store data and results in the measurement repository.',
  elaboration:'The measurement repository retains historical data and analysis results, enabling trend analysis, calibration of estimates and models, and organizational learning over time. Data retained only in project-local files is effectively lost when the project closes.',
  examples:['All measurement data (raw and analyzed) is stored in the organizational measurement repository; projects upload data according to defined procedures at project milestones and at close','The measurement repository is backed up weekly; the backup schedule and last successful backup date are visible to the data steward','Historical data in the repository spans at least three years; data older than the retention policy is archived but not deleted','The repository is searchable by project, measure type, and time period; the data steward publishes a quarterly data catalog update'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 3.1', practiceGroup:'PG 3 – Manage Performance',
  practiceStatement:'Monitor performance against the quality and process performance objectives using measurement data.',
  elaboration:'Performance monitoring compares actual measurement values against the defined quantitative objectives and thresholds. Monitoring must be systematic and documented — management reviewing data in their heads is not evidence. Monitoring results drive management decisions.',
  examples:['The project dashboard includes a performance monitoring section with each objective, its target value, current actual, variance, trend, and status (On Track / At Risk / Action Required)','Performance against organizational objectives is reviewed at the monthly governance review; the review record shows the data reviewed and any decisions made','When a measure crosses a defined alert threshold, an automated notification is sent to the responsible manager within 24 hours','Performance monitoring data is included in project status reports submitted to the steering committee'],
  level:'ML2', domain:'All'
},
{
  pa:'MPM', paFull:'Managing Performance and Measurement',
  paDesc:'Provide the management with quantitative insight into the performance of the project and product.',
  practiceNum:'MPM 3.2', practiceGroup:'PG 3 – Manage Performance',
  practiceStatement:'Use measurement data to support management decisions and identify process improvement opportunities.',
  elaboration:'Measurement is only complete when it influences decisions. Evidence that management decisions were informed by measurement data — rather than just made in parallel with data collection — is the key appraisal test for this practice.',
  examples:['Meeting minutes from project steering committee meetings include a "Measurement Data Reviewed" section listing the specific metrics reviewed and the decisions they informed','When measurement data shows a positive trend in peer review defect detection, management decides to expand the mandatory peer review scope to include test plans — the decision record references the review data','Measurement data from the organizational repository is used in annual process improvement prioritization decisions; the investment case for each process improvement references the supporting metrics','Lessons learned from measurement data analyses are captured in the PAL for use in future measurement planning'],
  level:'ML2', domain:'All'
},
/* ═══════════════════════════════════════════════════════════════════════════
   ML2 — DEVELOPMENT DOMAIN  (domain = 'Development')
═══════════════════════════════════════════════════════════════════════════ */

/* ── RDM — Requirements Development and Management ────────────────────── */
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 1.1', practiceGroup:'PG 1 – Develop Requirements',
  practiceStatement:'Elicit stakeholder needs, expectations, constraints, and interfaces.',
  elaboration:'Elicitation is the active process of drawing out requirements from stakeholders using structured techniques — interviews, workshops, observations, prototypes, and use-case walkthroughs. Passive collection (waiting for stakeholders to volunteer requirements) consistently produces incomplete requirements and late-stage surprises.',
  examples:[
    'Requirements elicitation workshops are conducted with all identified stakeholder groups; each workshop produces a documented output (needs list, use cases, or user story map) that is stored as a project artifact',
    'Stakeholder interviews are recorded with the interviewee\'s name, date, topics covered, and a needs list; interviewees review and confirm the needs list before it is used as a requirements input',
    'Prototypes are used to elicit requirements for complex UI features; stakeholder feedback sessions on the prototype are documented and converted to acceptance criteria',
    'An elicitation plan is included in the project plan, identifying the stakeholders to be engaged, the elicitation techniques for each, and the schedule'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 1.2', practiceGroup:'PG 1 – Develop Requirements',
  practiceStatement:'Transform stakeholder needs into customer requirements.',
  elaboration:'Customer requirements are the formally documented, validated expression of what the customer needs — derived from elicited stakeholder needs but expressed as verifiable, unambiguous statements. The transformation from needs (often informal and conflicting) to requirements (formal, consistent, and measurable) requires analysis and validation.',
  examples:[
    'Stakeholder needs captured during elicitation are reviewed in a requirements analysis session; each need is either converted to a customer requirement, combined with related needs, or recorded as out of scope with rationale',
    'The System Requirements Specification (SRS) documents customer requirements in the form "The system shall [action] [object] [condition]"; each requirement has a unique ID, priority, and source stakeholder',
    'Requirements that are ambiguous, conflicting, or untestable are flagged during analysis and resolved with the relevant stakeholders before being baselined',
    'The customer requirements are reviewed and approved by the customer representative before requirements are allocated to product components'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 2.1', practiceGroup:'PG 2 – Analyze and Validate Requirements',
  practiceStatement:'Establish and maintain product and product-component requirements that are derived from customer requirements.',
  elaboration:'Product requirements translate customer requirements into technical specifications that define what the product must do and how well it must do it. Product-component requirements further decompose product requirements into specifications for individual components. The derivation must be traceable — every product requirement traces to one or more customer requirements.',
  examples:[
    'The Product Requirements Document (PRD) derives technical requirements from the approved SRS; each PRD requirement includes a traceability link to the customer requirement(s) it satisfies',
    'Non-functional requirements (performance, reliability, security, scalability) are explicitly documented in the PRD with measurable acceptance thresholds',
    'Product-component requirements are allocated to individual microservices/modules in a requirements allocation table; each component requirement traces to a product requirement',
    'The PRD is placed under CM control at the completion of the design phase entry review; subsequent changes follow the CM change control process'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 2.2', practiceGroup:'PG 2 – Analyze and Validate Requirements',
  practiceStatement:'Allocate requirements to product components and ensure bidirectional traceability is maintained.',
  elaboration:'Allocation distributes product requirements to the components responsible for satisfying them. Bidirectional traceability means each requirement traces forward to the design and test artifacts that implement and verify it, and each design and test artifact traces back to the requirement it addresses. Bidirectional traceability is the single most-checked item in a CMMI DEV appraisal.',
  examples:[
    'A Requirements Traceability Matrix (RTM) is maintained linking: Customer Requirement → Product Requirement → Component Requirement → Design Element → Test Case; the RTM is updated at each phase transition',
    'The test management tool enforces traceability by requiring every test case to be linked to at least one requirement before it can be executed; orphaned test cases (no requirement link) are reported as a quality defect',
    'Traceability coverage is reported as a metric: % of requirements with at least one linked test case; the target is 100% before system test begins',
    'A traceability gap analysis is performed at each phase gate; any requirements without forward traces to test cases, or test cases without backward traces to requirements, are resolved before the gate is passed'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 2.3', practiceGroup:'PG 2 – Analyze and Validate Requirements',
  practiceStatement:'Identify and document interface requirements between product components and with external systems.',
  elaboration:'Interface requirements define what information must flow between components and between the product and external systems, and under what conditions. Undocumented interfaces are a leading cause of integration failures. Interface requirements are the inputs to the interface design activities in TS and the interface management activities in PI.',
  examples:[
    'An Interface Requirements Document (IRD) identifies every interface between product components and between the product and external systems, specifying: data exchanged, protocol, frequency, format, error handling, and security requirements',
    'Interface requirements are reviewed jointly with the teams responsible for each side of each interface before the IRD is baselined',
    'External interface requirements agreed with the customer are documented in the SRS and referenced in the supplier agreements with external system owners',
    'Interface requirement changes are processed through the CM change control process and the IRD, RTM, and affected ICDs are updated together to maintain consistency'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 3.1', practiceGroup:'PG 3 – Manage Requirements',
  practiceStatement:'Obtain commitment to requirements from project participants.',
  elaboration:'Commitment to requirements means that the technical team, project manager, and customer all formally agree that the documented requirements are correct, complete, feasible, and testable, and that they accept responsibility for implementing and verifying them. Uncommitted requirements lead to silent disagreements that surface as disputes during delivery.',
  examples:[
    'Requirements sign-off is obtained from the customer representative, technical lead, test lead, and project manager; the signed approval form is stored in the project record under CM',
    'Sprint planning sessions begin with the product owner presenting requirements (user stories with acceptance criteria) and the team confirming understanding and commitment before the sprint begins; the committed sprint backlog is the evidence',
    'Requirements commitment is re-confirmed after significant changes; any stakeholder who cannot commit to the revised requirements raises the concern formally through the requirements change process',
    'The requirements baseline is not established until all required sign-offs are obtained; the baseline creation date and approvers are recorded in the CM system'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 3.2', practiceGroup:'PG 3 – Manage Requirements',
  practiceStatement:'Manage changes to requirements and document their rationale and impact.',
  elaboration:'Requirements changes are inevitable. Managing them means every proposed change goes through a defined process: the change is documented, its impact on schedule, cost, design, and tests is assessed, it is approved by the appropriate authority, and the relevant documents are updated. Ad-hoc requirements changes are among the most common causes of project failure and appraisal findings.',
  examples:[
    'A Requirements Change Request (RCR) form is submitted for every proposed requirements change; the form captures: description, requester, reason, impact on schedule/cost/tests/design, and recommended disposition',
    'The Change Control Board (CCB) reviews all requirements change requests weekly; approved changes are communicated to all affected parties and the RTM, SRS, and PRD are updated before implementation begins',
    'Change request rationale is recorded in the SRS revision history alongside the change itself, so future readers understand why the requirement was changed',
    'Requirements change velocity (changes per sprint) is tracked as a stability metric; a sudden increase triggers a requirements review session with the customer'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 3.3', practiceGroup:'PG 3 – Manage Requirements',
  practiceStatement:'Maintain bidirectional traceability among requirements and work products.',
  elaboration:'As the project progresses and work products evolve, traceability must be actively maintained — not just established at the beginning. New requirements must be traced forward, changed requirements must have their traces updated, and orphaned traces (pointing to deleted requirements) must be removed. Stale traceability is worse than no traceability because it creates false confidence.',
  examples:[
    'Traceability maintenance is an explicit task in the Definition of Done: no story is marked complete until the RTM has been updated to link the story to its implemented design element and test cases',
    'The RTM is reviewed by the QA lead at each sprint review; any gaps (requirements without test traces, test cases without requirement traces) are logged as defects and resolved before the sprint is closed',
    'When a requirement is changed or deleted, the PM assigns an RTM update task to the systems engineer within 2 business days; completion of the task is confirmed by QA',
    'An automated traceability report is generated weekly from the requirements management tool showing coverage gaps; the report is sent to the PM and test lead'
  ],
  level:'ML3', domain:'Development'
},
{
  pa:'RDM', paFull:'Requirements Development and Management',
  paDesc:'Elicit, analyze, and establish customer, product, and product-component requirements.',
  practiceNum:'RDM 3.4', practiceGroup:'PG 3 – Manage Requirements',
  practiceStatement:'Identify and correct inconsistencies between project plans, work products, and requirements.',
  elaboration:'As requirements change and work products evolve, inconsistencies develop — design documents that describe features not in the current requirements, test plans testing requirements that have been superseded, schedules not updated to reflect scope additions. Inconsistency detection and correction must be a systematic, periodic activity.',
  examples:[
    'A requirements consistency check is performed at each phase gate using a standard checklist that verifies alignment between: requirements, design, test plans, and project schedule',
    'Automated requirement-to-code traceability reports flag code modules that reference deleted requirement IDs; these are reviewed and resolved each sprint',
    'The design review agenda includes a checklist item confirming that the design is consistent with the current requirements baseline and that no requirements are unaddressed by the design',
    'Inconsistencies found are logged as defects in the issue tracker with "Requirements Inconsistency" as the defect type; they are resolved before the affected work product is approved'
  ],
  level:'ML3', domain:'Development'
},

/* ── SAM — Supplier Agreement Management ──────────────────────────────── */
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 1.1', practiceGroup:'PG 1 – Prepare for Supplier Agreements',
  practiceStatement:'Determine the acquisition type and document the requirements and constraints for each product or product component to be acquired.',
  elaboration:'Acquisition preparation identifies what is being acquired (COTS, custom development, cloud service, open-source component), the technical and contractual requirements the acquisition must satisfy, and the constraints (budget, timeline, compliance) that limit the acquisition options. Thorough preparation prevents poorly defined agreements that leave suppliers room to underdeliver.',
  examples:[
    'An Acquisition Planning document is produced for each significant supplier engagement, specifying: acquisition type, functional/non-functional requirements, compliance requirements (security, data privacy), budget range, and delivery timeline',
    'The make-buy-reuse decision (TS 2.3) outputs feed directly into the SAM acquisition plan, providing the documented rationale for why an external supplier is being engaged',
    'Regulatory and compliance constraints (e.g., FedRAMP authorization required, GDPR data processing requirements) are explicitly documented in the acquisition requirements before the RFP is issued',
    'Acquisition requirements are reviewed and approved by legal, security, and the technical lead before solicitation begins'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 1.2', practiceGroup:'PG 1 – Prepare for Supplier Agreements',
  practiceStatement:'Identify and document criteria for evaluating potential suppliers.',
  elaboration:'Supplier evaluation criteria define the basis for selecting among candidate suppliers. Criteria are established before solicitation responses are received to prevent post-hoc rationalization of a preferred supplier. Criteria typically include technical capability, financial stability, experience, compliance posture, and cost.',
  examples:[
    'A Supplier Evaluation Scorecard is produced before the RFP is issued; criteria include technical capability, relevant experience, financial stability, security certifications, pricing, and support capability with defined weights',
    'Evaluation criteria are reviewed and approved by the procurement team, technical lead, and legal before solicitation; the approved scorecard is stored as a dated artifact',
    'The evaluation criteria are shared with responding vendors in the RFP so they understand how they will be assessed',
    'The completed scored scorecard for each evaluated vendor is retained as a procurement record regardless of the selection outcome'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 2.1', practiceGroup:'PG 2 – Establish Supplier Agreements',
  practiceStatement:'Establish and maintain formal agreements with the selected suppliers.',
  elaboration:'Formal agreements (contracts, SOWs, SLAs) define the obligations of both parties — what the supplier will deliver, when, to what standard, and for what price, and what the acquiring organization will provide in return. The agreement must be specific enough to serve as the basis for monitoring and acceptance decisions.',
  examples:[
    'A Statement of Work (SOW) with measurable acceptance criteria, deliverable schedule, and performance standards is executed with each significant supplier before work begins',
    'Service Level Agreements (SLAs) with cloud service providers specify availability targets, response time, incident resolution timeframes, and remedies for non-performance',
    'Supplier agreements include requirements transition provisions: how the supplier will support handoff of deliverables, documentation, and IP at agreement end',
    'Agreements are reviewed by legal before execution; the executed agreement and review sign-off are stored in the project procurement record'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 2.2', practiceGroup:'PG 2 – Establish Supplier Agreements',
  practiceStatement:'Ensure that supplier agreements address requirements for transition of acquired products or services.',
  elaboration:'Transition requirements address what happens at the end of the supplier relationship — delivery of source code, data, documentation, and knowledge transfer needed for the acquirer to operate, maintain, or re-compete the work. Failing to address transition in the agreement leaves the organization dependent on the supplier\'s goodwill.',
  examples:[
    'The SAM agreement includes a Transition Plan section specifying: deliverables to be transferred, documentation required, knowledge transfer sessions, and timeline for transition activities',
    'Source code escrow provisions are included in custom software development agreements to protect the acquirer if the supplier becomes unavailable',
    'Cloud service agreements specify data export formats and the supplier\'s obligation to support migration to another provider within a defined notice period',
    'Transition readiness is verified at agreement close: a Transition Completion Checklist confirms all required deliverables and knowledge transfer activities were completed'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 3.1', practiceGroup:'PG 3 – Manage Supplier Agreements',
  practiceStatement:'Perform activities with the supplier as specified in the supplier agreement.',
  elaboration:'Managing the supplier relationship means executing the review, monitoring, and coordination activities defined in the agreement — not just waiting for deliverables to arrive. Regular status meetings, progress reviews, and issue resolution sessions are conducted according to the agreed cadence and documented.',
  examples:[
    'Monthly supplier status reviews are conducted per the agreement; meeting minutes record attendance, progress against milestones, open issues, and actions assigned',
    'A Supplier Management Plan documents the review cadence, escalation path, reporting requirements, and the acquirer\'s designated supplier manager',
    'Supplier performance dashboards track on-time delivery, defect rates in supplier deliverables, and SLA compliance — updated monthly from supplier-provided reports',
    'When supplier performance falls below agreed thresholds, a formal notice is issued per the agreement\'s remediation provisions; the notice and supplier response are retained'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 3.2', practiceGroup:'PG 3 – Manage Supplier Agreements',
  practiceStatement:'Select and evaluate work products from the supplier against the criteria in the supplier agreement.',
  elaboration:'Supplier work products must be evaluated against defined criteria before acceptance — not simply received. Evaluation may include technical reviews, testing, inspection, or audit. Accepting deliverables without evaluation transfers undetected defects into the project and undermines the value of the agreement.',
  examples:[
    'Each supplier deliverable is evaluated using an acceptance checklist derived from the SOW acceptance criteria before it is formally accepted into the project baseline',
    'Supplier-developed software components are subjected to the same integration readiness checklist as internally developed components before being integrated',
    'Supplier documentation deliverables are reviewed by the technical lead and systems engineer before acceptance; review findings are communicated to the supplier for correction',
    'Acceptance decisions (accepted / accepted with conditions / rejected) are documented with the reviewer name, date, and evaluation results; rejections include a defect list for supplier remediation'
  ],
  level:'ML2', domain:'Development'
},
{
  pa:'SAM', paFull:'Supplier Agreement Management',
  paDesc:'Manage the acquisition of products and services from suppliers through formal agreements.',
  practiceNum:'SAM 3.3', practiceGroup:'PG 3 – Manage Supplier Agreements',
  practiceStatement:'Accept the acquired product or service per the supplier agreement acceptance criteria.',
  elaboration:'Formal acceptance is the point at which the acquirer confirms that the supplier has met their obligations and the deliverable is accepted into the project. Acceptance must be based on demonstrated satisfaction of the acceptance criteria — not passage of time or invoice receipt. Formal acceptance records close the supplier obligation loop.',
  examples:[
    'A Supplier Acceptance Record is completed and signed by the designated acceptance authority when all acceptance criteria are verified as met; the signed record is stored in the procurement file',
    'For software deliverables, acceptance is conditional on passing all acceptance tests defined in the SOW; test results are attached to the acceptance record',
    'Conditional acceptance (accepted with open minor items) documents the open items, remediation timeline, and the consequences of non-remediation',
    'Final acceptance triggers payment per the agreement terms; the link between acceptance record and payment record is maintained in the procurement file'
  ],
  level:'ML2', domain:'Development'
},

]; /* END CMMI_DATA */

/* ═══════════════════════════════════════════════════════════════════════════
   EVIDENCE (appraiser tips — augmented per-PA artifact lists)
═══════════════════════════════════════════════════════════════════════════ */
var EVIDENCE = {
  PLAN:{ artifacts:['Project Charter with signed sponsor approval','Work Breakdown Structure (WBS) with at least 3 levels','Project schedule with critical path and milestones','Stakeholder register and communication plan','Resource plan with role assignments','Data management plan','Environment/tools plan','Plan review meeting minutes showing stakeholder sign-off','Reconciliation record when plan was adjusted to available resources'],
         tips:'Appraisers check that plans are realistic, traceable to the WBS, and show evidence of stakeholder sign-off. Versioned plans with change history demonstrating active maintenance are strongest. PLAN 3.3 commitment evidence (explicit team/resource commitment, not just plan publication) is frequently missing.' },
  MC:{   artifacts:['Weekly/sprint status reports with planned vs. actual data','Schedule/cost variance tracking (EVM or equivalent)','Corrective action log with closure evidence','Management review meeting minutes','Stakeholder engagement log'],
         tips:'Appraisers want closed-loop corrective actions — not just deviations noted in status reports. Show that issues were analyzed, actions assigned, executed, and confirmed effective. MC 3.2 trend analysis must show data driving decisions, not just data reported.' },
  CM:{   artifacts:['Configuration Management Plan','CI inventory / baseline manifest','Version control repository (Git log, branch protection rules)','Change Request log with disposition records','Configuration Status Accounting report','CM audit report (FCA/PCA) with findings and closures'],
         tips:'Demonstrate that baselines are formally established and changes only enter through approved CRs. Automated audit trails (Git history with PR approvals) are excellent evidence. Unauthorized changes discovered in audits and corrected are actually positive evidence — they show the audit process works.' },
  PQA:{  artifacts:['QA audit schedule and completed checklists','Audit reports with non-compliance findings','QA findings log (open and closed) with closure evidence','QA summary reports to management','QA organizational approach / charter demonstrating auditor independence'],
         tips:'Objectivity/independence is the #1 PQA check — auditors must not audit their own work. Show findings are tracked to closure with management visibility. PQA 3.1 (organizational approach) is often the weakest evidence — document the QA function\'s charter, scope, and escalation path.' },
  GOV:{  artifacts:['Organizational policy and standards library (versioned, with review dates)','Governance framework document with named process owners','Governance review meeting minutes with decisions recorded','Process compliance monitoring results','Accountability documentation (RACI, performance objectives for managers)'],
         tips:'Policies must be communicated AND acknowledged — distribution records are important. Show that governance reviews use actual performance data (not just status discussions) and produce documented decisions. GOV 3.3 sustainability evidence (improvements embedded in training and policies) is frequently absent.' },
  II:{   artifacts:['Infrastructure needs register with priority ratings','Infrastructure inventory (tools, environments, templates, access records)','Tool validation/verification records','Staff access provisioning records and onboarding completion','Infrastructure effectiveness survey results','Infrastructure improvement action log'],
         tips:'Show infrastructure is actively managed: needs identified, prioritized, provisioned, validated, and evaluated. Evidence that staff were supported in using tools (training, guides, helpdesk records) is II 2.2 gold. Evaluation records (II 3.1) are frequently missing — they must show data-based assessment, not just opinions.' },
  MPM:{  artifacts:['Measurement Plan with GQM-derived objectives and operational definitions','Data collection procedures','Measurement repository with historical data','Analysis reports and trend charts','Management meeting minutes referencing specific measurement data as decision basis'],
         tips:'The critical appraisal test: prove that measurement DATA drove a DECISION. Meeting minutes that show managers discussing a specific metric and recording the resulting action are the strongest evidence. Collecting data without acting on it satisfies MPM 2 but fails MPM 3.' },
  EST:{  artifacts:['Estimation approach/standards document','Estimation worksheets with method, inputs, and results','Historical velocity/productivity data used as inputs','Estimate review meeting minutes with stakeholder sign-off','Estimate-to-actual variance records from completed projects','Project closeout estimation actuals stored in organizational repository'],
         tips:'Show the METHOD first — appraisers verify estimates follow a documented approach, not gut feel. Estimate-to-actual comparisons across multiple projects demonstrate calibration (EST 3.2). Preparation records (EST 1.2) — the kickoff checklist confirming scope boundary and parameter selection — are often missing.' },
  DAR:{  artifacts:['DAR trigger guidelines document defining what triggers formal evaluation','Evaluation criteria and weights (established BEFORE alternatives are scored)','Documented alternatives (minimum 3, including a baseline/do-nothing option)','Completed scoring matrix with results','DAR decision record with rationale for selected solution','Post-implementation outcome review'],
         tips:'The #1 DAR failure: criteria weights set after alternatives were already scored. Show criteria were locked before evaluation. A decision overriding the top scorer without written justification will fail. DAR 3.2 post-decision reviews are almost always missing — schedule them at 3–6 months post-implementation.' },
  CAR:{  artifacts:['CAR selection criteria defining which outcomes trigger analysis','Causal analysis session records (5-Why worksheets, fishbone diagrams, Pareto charts)','Action proposal forms with owner, due date, and defined success measure','CAR action tracking log with open/closed status','Post-implementation effectiveness data (trend charts showing change after action)','CAR archive in the PAL accessible org-wide'],
         tips:'Appraisers look for systemic fixes, not one-off patches. The strongest CAR evidence is a trend chart showing the same defect type stopped recurring after the action. CAR 3.1 effectiveness evaluation data (not just "action completed") is the most commonly missing element.' },
  OT:{   artifacts:['Strategic Training Needs Assessment with business objective linkage','Process training needs matrix (required competency vs. current capability by role)','Tactical Training Plan (scheduled, resourced, with mandatory vs. developmental distinction)','Training delivery records (LMS exports, sign-in sheets, OJT checklists)','Post-training assessment results (scores, pass/fail)','Training effectiveness evaluation results (manager ratings, performance change data)'],
         tips:'OT 3.2 training records are the most-requested artifact — make sure LMS transcripts are accessible to auditors and complete. OT 3.1 effectiveness evaluation must go beyond satisfaction surveys: show knowledge assessment scores and on-the-job performance change. Strategic training needs (OT 1.1) must link to business goals, not just skill gaps.' },
  PAD:{  artifacts:['Organizational Process Asset Library (PAL) with indexed, versioned assets','Standard process descriptions (all required PA processes documented)','Life cycle model descriptions with phase, milestone, and entry/exit criteria','Tailoring guidelines and approved project tailoring logs','Process Improvement Proposal (PIP) register with dispositions','Process deployment notifications and compliance verification records'],
         tips:'The PAL must be accessible AND actually used by projects. Appraisers sample project plans to verify they reference PAL assets. PAD 3.2 deployment evidence — communication records, training updates, compliance checks — is the most frequently missing PAD artifact. "Process is updated in the library" is not enough; show that projects started USING the updated process.' },
  RSK:{  artifacts:['Organizational risk taxonomy (categories and source definitions)','Risk Register with probability, impact, score, priority, and update history','Risk mitigation plans with owner, trigger, and contingency plan per risk','Risk review meeting records showing active monitoring','Realized risk records with corrective action references','Project closeout risk archive with lessons learned'],
         tips:'A living risk register updated at every review cycle is essential. Appraisers check that mitigation actions are actually executed (not just planned) by looking for execution evidence linked from the register. RSK 3.2 monitoring must show the register being updated — a static risk register that hasn\'t changed in 3 months will fail.' },
  PCM:{  artifacts:['Process Performance Baseline Report (control charts, histograms, control limits, data sources)','Quantitative performance objectives document (targets, measurement protocol, review frequency)','Process Performance Models document (formula, inputs, valid range, calibration history)','Quantitative Project Management Plans referencing org baselines','Project status reports with quantitative performance section and trend commentary','Corrective action records triggered by statistical signals (not just milestone misses)'],
         tips:'ML3 PCM requires statistical derivation of baselines — control charts showing stable processes and calculated control limits. Appraisers look for evidence that projects USE baselines to set goals and make go/no-go decisions, not just that baselines exist. PCM 2.2 models must have calibration records; a model that has never been recalibrated will be questioned.' },
  TS:{   artifacts:['Architecture Decision Records (ADRs) or trade study documents','Conceptual design documents for evaluated alternatives','Make-buy-reuse analysis for each major component','Detailed design documents (data models, API specs, sequence diagrams, interface definitions)','Coding standards with evidence of adherence (linting/SAST pipeline results)','End-use documentation (installation guide, user guide, release notes) under CM'],
         tips:'Design rationale is critical — show WHY the solution was chosen, not just what it is. TS 2.2 interface completeness and internal consistency between design levels are key appraisal checks. TS 3.2 end-use documentation is frequently treated as an afterthought — appraisers check it was developed concurrent with the product and is under CM.' },
  PI:{   artifacts:['Product Integration Strategy document (sequence, environment, criteria, rationale)','Integration environment specification / runbook','Interface Control Documents (ICDs) under CM','Integration readiness checklists per component','CI/CD pipeline configuration and execution logs (build records with version tags)','Integration test results / test execution reports','Delivery and acceptance records'],
         tips:'Show that components meet interface requirements BEFORE assembly by producing integration readiness checklists. Automated CI/CD pipeline logs with pass/fail results per build are excellent PI 3.2 evidence. PI 2.2 interface change control records (ICDs under CM, changes processed through CRs) are a frequent appraisal gap.' },
  VV:{   artifacts:['V&V Plan listing work products, methods, criteria, environment, entry/exit criteria','Test plans (unit, integration, system, acceptance) with requirements links','Test procedures (step-by-step with expected results)','Test execution records (test case ID, date, tester, result, linked defects)','Test Summary Reports with defect analysis and release readiness assessment','Validation results (UAT execution records, customer sign-off)'],
         tips:'Distinguish verification (built right — meets requirements) from validation (built the right thing — fulfills intended use). Appraisers check every requirement has at least one linked test case. VV 1.2 entry/exit criteria are frequently vague — make them measurable ("0 open Sev-1 defects, 90% test cases executed"). Validation evidence (VV 3.2 customer sign-off) is often filed separately and hard to find at appraisal time.' },
  PR:{   artifacts:['Peer Review Plan listing work products to be reviewed with selection rationale','Entry and exit criteria document','Review preparation records: preparation time logs, completed reviewer checklists','Review records: attendees with roles, work product + version reviewed, defect log with severity','Action item tracking records closed before product proceeds','Peer review metrics (defects found per review, preparation time, defect density trends)','PR process improvement records showing checklist updates from trend analysis'],
         tips:'Preparation evidence (PR 1.2 entry criteria, PR 2.1 preparation time logs) is the most commonly missing PR artifact — "LGTM" culture with no preparation records fails immediately. PR 3.2 metrics must show DATA driving IMPROVEMENTS to checklists or development practices, not just metrics reported. Peer review data trend charts are the strongest PR 3.1/3.2 evidence.' },
  RDM:{  artifacts:['Elicitation plan and elicitation session records (interview notes, workshop outputs, prototype feedback)','System Requirements Specification (SRS) with unique IDs, priority, and source stakeholder','Requirements Traceability Matrix (RTM): Customer Req → Product Req → Component Req → Design → Test Case','Requirements change request log with impact assessments and CCB dispositions','Requirements commitment/sign-off records (stakeholder signatures or formal approvals)','Requirements consistency check results at each phase gate'],
         tips:'Bidirectional traceability (RTM) is the #1 checked item in a DEV appraisal — every requirement must trace to a test case AND every test case must trace to a requirement. RDM 3.4 inconsistency detection records (systematic checks, not just ad-hoc discovery) are almost always missing. Show that requirements elicitation was ACTIVE (workshops, interviews, prototypes) not passive (waiting for specs to arrive).' },
  SAM:{  artifacts:['Acquisition planning document (type, requirements, constraints)','Supplier evaluation scorecard (criteria set before scoring, completed for each evaluated supplier)','Executed supplier agreement / SOW / SLA with measurable acceptance criteria and transition provisions','Supplier status review meeting records','Supplier deliverable evaluation records (checklists, test results)','Formal acceptance records signed by designated acceptance authority'],
         tips:'SAM is optional in scope but frequently included for Development appraisals. Appraisers look for: criteria-based selection (not just cost), agreements with measurable acceptance criteria (not vague "quality work"), and documented monitoring (not just a signed contract filed away). SAM 2.2 transition provisions are the most commonly missing contractual element.' }
};

/* ═══════════════════════════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════════════════════════ */
function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function lvlBadge(lvl){
  var map={ML2:'primary',ML3:'success',ML4:'warning',ML5:'danger'};
  return '<span class="badge bg-'+( map[lvl]||'secondary')+'">'+escH(lvl)+'</span>';
}
function domBadge(dom){
  if(dom==='All')         return '<span class="badge bg-secondary">Core</span>';
  if(dom==='Development') return '<span class="badge bg-primary">Dev</span>';
  return '<span class="badge bg-success">Svc</span>';
}

/* ── Filter/stats ──────────────────────────────────────────────────────── */
function updateStats(rows){
  var pas={};
  rows.forEach(function(d){ pas[d.pa]=true; });
  var paCount=Object.keys(pas).length;
  document.getElementById('stat-total').textContent = rows.length;
  document.getElementById('stat-ml2').textContent   = rows.filter(function(d){return d.level==='ML2';}).length;
  document.getElementById('stat-ml3').textContent   = rows.filter(function(d){return d.level==='ML3';}).length;
  document.getElementById('stat-ml4').textContent   = rows.filter(function(d){return d.level==='ML4';}).length;
  document.getElementById('stat-ml5').textContent   = rows.filter(function(d){return d.level==='ML5';}).length;
  document.getElementById('stat-pas').textContent   = paCount;
  document.getElementById('filter-summary').textContent =
    paCount+' Practice Area'+(paCount!==1?'s':'')+' · '+rows.length+' Practice'+(rows.length!==1?'s':'');
  var lvl=document.getElementById('ctrl-level').value;
  var dom=document.getElementById('ctrl-domain').value;
  var pa =document.getElementById('ctrl-pa').value;
  var srch=document.getElementById('ctrl-search').value.trim();
  var parts=[];
  if(lvl) parts.push(lvl);
  if(dom) parts.push(dom);
  if(pa)  parts.push(pa);
  if(srch)parts.push('"'+srch+'"');
  document.getElementById('filter-label').textContent=
    parts.length?'Filtered by: '+parts.join(' · '):'All practices';
}

/* ── Main table render (expandable rows) ──────────────────────────────── */
function renderTable(rows){
  updateStats(rows);
  var el=document.getElementById('results-table');
  if(!rows.length){
    el.innerHTML='<p class="text-muted p-3">No practices match the current filters.</p>';
    return;
  }
  var html='<table class="table table-bordered table-hover table-sm align-middle mb-0">'
    +'<thead class="table-dark sticky-top"><tr>'
    +'<th style="width:3rem"></th>'
    +'<th>PA</th><th>Practice</th><th>Practice Group</th><th>Practice Statement</th><th>Level</th><th>Domain</th>'
    +'</tr></thead><tbody>';

  rows.forEach(function(d,i){
    var rid='row-'+i;
    var did='det-'+i;
    var exHtml='';
    if(d.examples && d.examples.length){
      exHtml='<ul class="mb-0 ps-3 small">';
      d.examples.forEach(function(ex){ exHtml+='<li class="mb-1">'+escH(ex)+'</li>'; });
      exHtml+='</ul>';
    }
    html+='<tr class="practice-row" data-bs-toggle="collapse" data-bs-target="#'+did+'" style="cursor:pointer" aria-expanded="false">'
      +'<td class="text-center text-muted small"><i class="bi bi-chevron-right expand-icon"></i></td>'
      +'<td><span class="pa-badge">'+escH(d.pa)+'</span></td>'
      +'<td class="text-nowrap fw-semibold small">'+escH(d.practiceNum)+'</td>'
      +'<td class="sg-text">'+escH(d.practiceGroup)+'</td>'
      +'<td class="small">'+escH(d.practiceStatement)+'</td>'
      +'<td>'+lvlBadge(d.level)+'</td>'
      +'<td>'+domBadge(d.domain)+'</td>'
      +'</tr>'
      +'<tr id="'+did+'" class="collapse detail-row">'
      +'<td colspan="7" class="p-0">'
      +'<div class="p-3" style="background:rgba(var(--bs-body-color-rgb),.03);border-top:1px solid var(--bs-border-color)">'

      +'<div class="row g-3">'

      +'<div class="col-md-4">'
      +'<div class="fw-semibold small mb-1 text-primary">Practice Area</div>'
      +'<div class="small mb-1"><span class="pa-badge me-1">'+escH(d.pa)+'</span>'+escH(d.paFull)+'</div>'
      +'<div class="text-secondary" style="font-size:.78rem">'+escH(d.paDesc||'')+'</div>'
      +'</div>'

      +'<div class="col-md-4">'
      +'<div class="fw-semibold small mb-1 text-primary">Additional Information</div>'
      +'<div class="text-secondary small">'+escH(d.elaboration||'')+'</div>'
      +'</div>'

      +'<div class="col-md-4">'
      +'<div class="fw-semibold small mb-1 text-success">Compliance Examples</div>'
      +exHtml
      +'</div>'

      +'</div>'
      +'</div>'
      +'</td>'
      +'</tr>';
  });
  html+='</tbody></table>';
  el.innerHTML=html;

  /* Toggle chevron on expand/collapse */
  el.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(tr){
    tr.addEventListener('shown.bs.collapse',function(){
      tr.querySelector('.expand-icon').classList.replace('bi-chevron-right','bi-chevron-down');
      tr.setAttribute('aria-expanded','true');
    });
    tr.addEventListener('hidden.bs.collapse',function(){
      tr.querySelector('.expand-icon').classList.replace('bi-chevron-down','bi-chevron-right');
      tr.setAttribute('aria-expanded','false');
    });
  });
}

function applyFilters(){
  var lvl =document.getElementById('ctrl-level').value;
  var pa  =document.getElementById('ctrl-pa').value;
  var dom =document.getElementById('ctrl-domain').value;
  var srch=document.getElementById('ctrl-search').value.trim().toLowerCase();
  var rows=CMMI_DATA.filter(function(d){
    if(lvl && d.level!==lvl) return false;
    if(pa  && d.pa!==pa)     return false;
    if(dom && d.domain!=='All' && d.domain!==dom) return false;
    if(srch){
      var hay=(d.pa+d.paFull+d.practiceNum+d.practiceGroup+d.practiceStatement+(d.elaboration||'')).toLowerCase();
      if(hay.indexOf(srch)===-1) return false;
    }
    return true;
  });
  renderTable(rows);
}

function populatePADropdown(){
  var seen={};
  var sel=document.getElementById('ctrl-pa');
  CMMI_DATA.forEach(function(d){
    if(!seen[d.pa]){
      seen[d.pa]=true;
      var opt=document.createElement('option');
      opt.value=d.pa;
      opt.textContent=d.pa+' — '+d.paFull;
      sel.appendChild(opt);
    }
  });
}

/* ── Excel export ─────────────────────────────────────────────────────── */
function makeSheet(rows,cols){
  var ws={};
  var range={s:{c:0,r:0},e:{c:cols.length-1,r:rows.length}};
  cols.forEach(function(c,ci){
    ws[XLSX.utils.encode_cell({r:0,c:ci})]={v:c,t:'s'};
  });
  rows.forEach(function(row,ri){
    row.forEach(function(val,ci){
      ws[XLSX.utils.encode_cell({r:ri+1,c:ci})]={v:val||'',t:'s'};
    });
  });
  ws['!ref']=XLSX.utils.encode_range(range);
  ws['!freeze']={xSplit:0,ySplit:1};
  return ws;
}

function downloadExcel(){
  var lvl=document.getElementById('ctrl-level').value;
  var dom=document.getElementById('ctrl-domain').value;
  var filename='CMMI_v2_DEV_ML3_'+(lvl||'All-Levels')+'_'+(dom||'All-Domains')+'.xlsx';
  var wb=XLSX.utils.book_new();
  var COLS=['PA','Practice Area','Practice','Practice Group','Practice Statement','Elaboration','Compliance Examples','Level','Domain'];
  var toRow=function(d){
    return [d.pa,d.paFull,d.practiceNum,d.practiceGroup,d.practiceStatement,d.elaboration||'',(d.examples||[]).join(' | '),d.level,d.domain];
  };
  var ml3dev=CMMI_DATA.filter(function(d){return d.level==='ML3' && (d.domain==='Development'||d.domain==='All');});
  var ml3core=CMMI_DATA.filter(function(d){return d.level==='ML3' && d.domain==='All';});
  var ml2core=CMMI_DATA.filter(function(d){return d.level==='ML2' && d.domain==='All';});
  var ml2dev=CMMI_DATA.filter(function(d){return d.level==='ML2' && d.domain==='Development';});
  XLSX.utils.book_append_sheet(wb,makeSheet(CMMI_DATA.map(toRow),COLS),'All Practices');
  XLSX.utils.book_append_sheet(wb,makeSheet(ml3core.map(toRow),COLS),'ML3 Core');
  XLSX.utils.book_append_sheet(wb,makeSheet(ml3dev.map(toRow),COLS),'ML3 Development');
  XLSX.utils.book_append_sheet(wb,makeSheet(ml2core.map(toRow),COLS),'ML2 Core');
  XLSX.utils.book_append_sheet(wb,makeSheet(ml2dev.map(toRow),COLS),'ML2 Development');
  var evRows=[];
  Object.keys(EVIDENCE).forEach(function(pa){
    var e=EVIDENCE[pa];
    e.artifacts.forEach(function(art,i){ evRows.push([pa,art,i===0?e.tips:'']); });
    evRows.push(['','','']);
  });
  XLSX.utils.book_append_sheet(wb,makeSheet(evRows,['PA','Artifact / Evidence Item','Appraiser Tips']),'Evidence Guide');
  XLSX.writeFile(wb,filename);
}

/* ── Init ─────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded',function(){
  populatePADropdown();
  renderTable(CMMI_DATA);
  ['ctrl-level','ctrl-pa','ctrl-domain'].forEach(function(id){
    document.getElementById(id).addEventListener('change',applyFilters);
  });
  document.getElementById('ctrl-search').addEventListener('input',applyFilters);
  document.getElementById('btn-reset').addEventListener('click',function(){
    ['ctrl-level','ctrl-pa','ctrl-domain'].forEach(function(id){ document.getElementById(id).value=''; });
    document.getElementById('ctrl-search').value='';
    renderTable(CMMI_DATA);
  });
  document.getElementById('btn-export').addEventListener('click',downloadExcel);
});
