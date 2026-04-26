/* cmmidev3.js — CMMI v2.0 Maturity Level 3 (All Domains) */
/* [pa, paFull, practiceNum, practiceGroup, description, level, category, domain] */
/* domain: 'All' = Core (applies to all) | 'Development' | 'Services'            */
var CMMI_DATA = [

/* ═══════════════════════════════════════════════════════════════════
   ML2 — CORE PRACTICE AREAS  (domain = 'All')
═══════════════════════════════════════════════════════════════════ */

/* ── PLAN — Planning ── */
['PLAN','Planning','PLAN 1.1','PG 1 – Establish Context','Establish a shared vision, objectives, and high-level scope for the project','ML2','Core','All'],
['PLAN','Planning','PLAN 1.2','PG 1 – Establish Context','Define and maintain the work breakdown structure (WBS) to organize and estimate the scope of work','ML2','Core','All'],
['PLAN','Planning','PLAN 2.1','PG 2 – Develop the Plan','Develop and maintain the project schedule including milestones, dependencies, and critical path','ML2','Core','All'],
['PLAN','Planning','PLAN 2.2','PG 2 – Develop the Plan','Identify project dependencies and constraints and address them in the plan','ML2','Core','All'],
['PLAN','Planning','PLAN 2.3','PG 2 – Develop the Plan','Plan stakeholder involvement and communication for the duration of the project','ML2','Core','All'],
['PLAN','Planning','PLAN 2.4','PG 2 – Develop the Plan','Plan for the management of project data and knowledge artifacts','ML2','Core','All'],
['PLAN','Planning','PLAN 2.5','PG 2 – Develop the Plan','Plan for skills, knowledge, and resources needed to execute the project','ML2','Core','All'],
['PLAN','Planning','PLAN 2.6','PG 2 – Develop the Plan','Plan for the project work environment including tools, facilities, and infrastructure','ML2','Core','All'],
['PLAN','Planning','PLAN 3.1','PG 3 – Obtain Commitment','Review all plans that affect the project with relevant stakeholders','ML2','Core','All'],
['PLAN','Planning','PLAN 3.2','PG 3 – Obtain Commitment','Reconcile the project plan to reflect available and estimated resources','ML2','Core','All'],
['PLAN','Planning','PLAN 3.3','PG 3 – Obtain Commitment','Obtain commitment from stakeholders responsible for executing and supporting the plan','ML2','Core','All'],

/* ── EST — Estimation ── */
['EST','Estimation','EST 1.1','PG 1 – Prepare for Estimating','Establish and maintain estimating parameters, measures, and methods to be used','ML2','Core','All'],
['EST','Estimation','EST 1.2','PG 1 – Prepare for Estimating','Select and document estimating methods and techniques appropriate for the work','ML2','Core','All'],
['EST','Estimation','EST 2.1','PG 2 – Estimate the Work','Estimate work product and task sizes using defined measures','ML2','Core','All'],
['EST','Estimation','EST 2.2','PG 2 – Estimate the Work','Estimate effort and duration using established estimating parameters and models','ML2','Core','All'],
['EST','Estimation','EST 2.3','PG 2 – Estimate the Work','Estimate project costs based on estimated effort, duration, and resource requirements','ML2','Core','All'],
['EST','Estimation','EST 3.1','PG 3 – Validate Estimates','Review estimates for reasonableness using historical data and expert judgment','ML2','Core','All'],
['EST','Estimation','EST 3.2','PG 3 – Validate Estimates','Document rationale for estimation assumptions and record actuals for future calibration','ML2','Core','All'],

/* ── MC — Monitor and Control ── */
['MC','Monitor and Control','MC 1.1','PG 1 – Monitor the Work','Monitor actual performance and progress against the project plan','ML2','Core','All'],
['MC','Monitor and Control','MC 1.2','PG 1 – Monitor the Work','Monitor the involvement of relevant stakeholders against plan commitments','ML2','Core','All'],
['MC','Monitor and Control','MC 2.1','PG 2 – Analyze and Address Issues','Identify and analyze issues in performance and progress against the plan','ML2','Core','All'],
['MC','Monitor and Control','MC 2.2','PG 2 – Analyze and Address Issues','Take corrective actions to address identified performance and schedule issues','ML2','Core','All'],
['MC','Monitor and Control','MC 2.3','PG 2 – Analyze and Address Issues','Manage corrective actions to closure and verify their effectiveness','ML2','Core','All'],
['MC','Monitor and Control','MC 3.1','PG 3 – Review Status','Review project status with higher-level management as appropriate','ML2','Core','All'],
['MC','Monitor and Control','MC 3.2','PG 3 – Review Status','Analyze performance data to identify trends and forecast future performance','ML2','Core','All'],

/* ── CM — Configuration Management ── */
['CM','Configuration Management','CM 1.1','PG 1 – Establish Baselines','Identify configuration items and work products to be placed under configuration management','ML2','Core','All'],
['CM','Configuration Management','CM 1.2','PG 1 – Establish Baselines','Establish and maintain a configuration management system and change management system','ML2','Core','All'],
['CM','Configuration Management','CM 1.3','PG 1 – Establish Baselines','Create or release baselines for internal use and for delivery to the customer','ML2','Core','All'],
['CM','Configuration Management','CM 2.1','PG 2 – Track and Control Changes','Track change requests for configuration items from origination to disposition','ML2','Core','All'],
['CM','Configuration Management','CM 2.2','PG 2 – Track and Control Changes','Control changes to configuration items using an approved change request process','ML2','Core','All'],
['CM','Configuration Management','CM 2.3','PG 2 – Track and Control Changes','Maintain records describing configuration items and all changes made to them','ML2','Core','All'],
['CM','Configuration Management','CM 3.1','PG 3 – Establish Integrity','Establish and maintain the integrity of configuration baselines','ML2','Core','All'],
['CM','Configuration Management','CM 3.2','PG 3 – Establish Integrity','Perform configuration audits to confirm baselines and documentation are accurate and complete','ML2','Core','All'],

/* ── PQA — Process Quality Assurance ── */
['PQA','Process Quality Assurance','PQA 1.1','PG 1 – Evaluate Processes and Products','Objectively evaluate selected performed processes against applicable descriptions and standards','ML2','Core','All'],
['PQA','Process Quality Assurance','PQA 1.2','PG 1 – Evaluate Processes and Products','Objectively evaluate selected work products and services against applicable standards and requirements','ML2','Core','All'],
['PQA','Process Quality Assurance','PQA 2.1','PG 2 – Provide Objective Insight','Communicate noncompliance issues to staff and management and ensure resolution to closure','ML2','Core','All'],
['PQA','Process Quality Assurance','PQA 2.2','PG 2 – Provide Objective Insight','Establish and maintain records of quality assurance activities and their results','ML2','Core','All'],
['PQA','Process Quality Assurance','PQA 3.1','PG 3 – Manage Quality Assurance','Establish and maintain a process quality assurance approach aligned to organizational standards','ML2','Core','All'],

/* ── RSK — Risk and Opportunity Management ── */
['RSK','Risk and Opportunity Management','RSK 1.1','PG 1 – Identify Risks and Opportunities','Identify and document risks and opportunities using a defined identification approach','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 1.2','PG 1 – Identify Risks and Opportunities','Evaluate and categorize risks and opportunities using defined parameters and thresholds','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 2.1','PG 2 – Plan Mitigation','Develop risk and opportunity mitigation options, contingency plans, and triggers','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 2.2','PG 2 – Plan Mitigation','Prioritize risks for mitigation based on defined criteria and probability/impact assessment','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 2.3','PG 2 – Plan Mitigation','Develop and maintain risk and opportunity mitigation plans for priority items','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 3.1','PG 3 – Implement Mitigation','Implement risk mitigation plans and monitor for changes in risk status','ML2','Core','All'],
['RSK','Risk and Opportunity Management','RSK 3.2','PG 3 – Implement Mitigation','Adjust mitigation strategies based on monitoring results and trigger conditions','ML2','Core','All'],

/* ── GOV — Governance ── */
['GOV','Governance','GOV 1.1','PG 1 – Define Expectations','Define and communicate expected organizational behaviors for performing work and processes','ML2','Core','All'],
['GOV','Governance','GOV 1.2','PG 1 – Define Expectations','Ensure managers and staff have needed skills and understand expected performance behaviors','ML2','Core','All'],
['GOV','Governance','GOV 2.1','PG 2 – Manage Accountability','Align responsibilities with authority and accountability for process and business performance','ML2','Core','All'],
['GOV','Governance','GOV 2.2','PG 2 – Manage Accountability','Monitor organizational and project performance against established policies and standards','ML2','Core','All'],
['GOV','Governance','GOV 3.1','PG 3 – Establish Governance Infrastructure','Establish and maintain organizational policies, standards, and rules governing process performance','ML2','Core','All'],
['GOV','Governance','GOV 3.2','PG 3 – Establish Governance Infrastructure','Review and adjust governance mechanisms based on performance data and lessons learned','ML2','Core','All'],
['GOV','Governance','GOV 3.3','PG 3 – Establish Governance Infrastructure','Establish mechanisms to sustain and manage process performance improvements organization-wide','ML2','Core','All'],

/* ── II — Implementation Infrastructure ── */
['II','Implementation Infrastructure','II 1.1','PG 1 – Identify Infrastructure Needs','Identify resources, tools, methods, and environments needed to implement and support processes','ML2','Core','All'],
['II','Implementation Infrastructure','II 2.1','PG 2 – Provide Infrastructure','Establish and maintain infrastructure (tools, environments, templates) to support work performance','ML2','Core','All'],
['II','Implementation Infrastructure','II 2.2','PG 2 – Provide Infrastructure','Make infrastructure available to staff and provide access, training, and support as needed','ML2','Core','All'],
['II','Implementation Infrastructure','II 3.1','PG 3 – Improve Infrastructure','Evaluate implementation infrastructure effectiveness using performance data and staff feedback','ML2','Core','All'],
['II','Implementation Infrastructure','II 3.2','PG 3 – Improve Infrastructure','Improve implementation infrastructure based on evaluation results and identified gaps','ML2','Core','All'],

/* ── MPM — Managing Performance and Measurement ── */
['MPM','Managing Performance and Measurement','MPM 1.1','PG 1 – Establish Measurement Approach','Establish and maintain measurement objectives derived from organizational and project information needs','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 1.2','PG 1 – Establish Measurement Approach','Specify measures and analytical techniques to address each measurement objective','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 2.1','PG 2 – Collect and Analyze Data','Specify and implement data collection and storage procedures for defined measures','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 2.2','PG 2 – Collect and Analyze Data','Collect and store measurement data according to defined procedures','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 2.3','PG 2 – Collect and Analyze Data','Analyze measurement data and report results to relevant stakeholders','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 3.1','PG 3 – Manage Performance','Monitor performance against established objectives and thresholds using measurement data','ML2','Core','All'],
['MPM','Managing Performance and Measurement','MPM 3.2','PG 3 – Manage Performance','Use performance and measurement data to support management decisions and identify improvement actions','ML2','Core','All'],

/* ═══════════════════════════════════════════════════════════════════
   ML2 — DEVELOPMENT DOMAIN  (domain = 'Development')
═══════════════════════════════════════════════════════════════════ */

/* ── RDM — Requirements Development and Management ── */
['RDM','Requirements Development and Management','RDM 1.1','PG 1 – Develop Requirements','Elicit stakeholder needs, expectations, constraints, and operational concepts','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 1.2','PG 1 – Develop Requirements','Establish and maintain customer requirements derived from elicited stakeholder needs','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 2.1','PG 2 – Analyze and Validate Requirements','Establish product and product-component requirements aligned to customer requirements','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 2.2','PG 2 – Analyze and Validate Requirements','Allocate requirements to product components and ensure bidirectional traceability','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 2.3','PG 2 – Analyze and Validate Requirements','Identify and document interface requirements between product components','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 3.1','PG 3 – Manage Requirements','Obtain commitment to requirements from project participants','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 3.2','PG 3 – Manage Requirements','Manage requirements changes and document rationale and impact of each change','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 3.3','PG 3 – Manage Requirements','Ensure alignment between project work products, plans, and requirements','ML2','Development-Specific','Development'],
['RDM','Requirements Development and Management','RDM 3.4','PG 3 – Manage Requirements','Identify and correct inconsistencies between project work and requirements','ML2','Development-Specific','Development'],

/* ═══════════════════════════════════════════════════════════════════
   ML2 — SERVICES DOMAIN  (domain = 'Services')
═══════════════════════════════════════════════════════════════════ */

/* ── SD — Service Delivery ── */
['SD','Service Delivery','SD 1.1','PG 1 – Establish Service Agreements','Establish and maintain service agreements that define services, service levels, and obligations to customers','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 1.2','PG 1 – Establish Service Agreements','Establish and maintain service level targets and measures aligned to customer expectations','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 2.1','PG 2 – Deliver Services','Prepare for service delivery including resources, infrastructure, and trained staff','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 2.2','PG 2 – Deliver Services','Deliver services per service agreements and maintain agreed service levels','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 2.3','PG 2 – Deliver Services','Review service delivery performance with customers and address identified gaps','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 3.1','PG 3 – Manage Service Operations','Manage service delivery capacity and availability to meet service level commitments','ML2','Services-Specific','Services'],
['SD','Service Delivery','SD 3.2','PG 3 – Manage Service Operations','Ensure service continuity and recovery procedures are in place for adverse conditions','ML2','Services-Specific','Services'],

/* ── IRP — Incident Resolution and Prevention ── */
['IRP','Incident Resolution and Prevention','IRP 1.1','PG 1 – Identify and Classify Incidents','Establish criteria and procedures for identifying and classifying service incidents','ML2','Services-Specific','Services'],
['IRP','Incident Resolution and Prevention','IRP 1.2','PG 1 – Identify and Classify Incidents','Identify and classify service incidents using defined criteria and severity levels','ML2','Services-Specific','Services'],
['IRP','Incident Resolution and Prevention','IRP 2.1','PG 2 – Resolve Incidents','Resolve service incidents per established procedures and within agreed timeframes','ML2','Services-Specific','Services'],
['IRP','Incident Resolution and Prevention','IRP 2.2','PG 2 – Resolve Incidents','Communicate incident status, resolution, and closure to affected stakeholders','ML2','Services-Specific','Services'],
['IRP','Incident Resolution and Prevention','IRP 3.1','PG 3 – Prevent Incidents','Analyze incident data to identify trends and root causes of recurring incidents','ML2','Services-Specific','Services'],
['IRP','Incident Resolution and Prevention','IRP 3.2','PG 3 – Prevent Incidents','Implement preventative actions to reduce incident frequency, severity, and impact','ML2','Services-Specific','Services'],


/* ═══════════════════════════════════════════════════════════════════
   ML3 — CORE PRACTICE AREAS  (domain = 'All')
═══════════════════════════════════════════════════════════════════ */

/* ── DAR — Decision Analysis and Resolution ── */
['DAR','Decision Analysis and Resolution','DAR 1.1','PG 1 – Establish Guidelines','Establish and maintain guidelines for determining which issues are subject to formal decision analysis','ML3','Core','All'],
['DAR','Decision Analysis and Resolution','DAR 1.2','PG 1 – Establish Guidelines','Establish and maintain criteria and methods for evaluating alternatives against defined objectives','ML3','Core','All'],
['DAR','Decision Analysis and Resolution','DAR 2.1','PG 2 – Analyze Alternatives','Identify, evaluate, and select from a set of alternative solutions using defined criteria and methods','ML3','Core','All'],
['DAR','Decision Analysis and Resolution','DAR 2.2','PG 2 – Analyze Alternatives','Document the rationale, alternatives considered, and the basis for the selected solution','ML3','Core','All'],
['DAR','Decision Analysis and Resolution','DAR 3.1','PG 3 – Apply Decisions','Integrate formal decision-making results into work products, plans, and processes','ML3','Core','All'],
['DAR','Decision Analysis and Resolution','DAR 3.2','PG 3 – Apply Decisions','Monitor the effectiveness of decisions made and capture lessons learned for future use','ML3','Core','All'],

/* ── CAR — Causal Analysis and Resolution ── */
['CAR','Causal Analysis and Resolution','CAR 1.1','PG 1 – Determine Causes of Outcomes','Select outcomes to analyze and use defined causal analysis techniques to determine root causes','ML3','Core','All'],
['CAR','Causal Analysis and Resolution','CAR 1.2','PG 1 – Determine Causes of Outcomes','Analyze selected outcomes to identify and document root causes and contributing factors','ML3','Core','All'],
['CAR','Causal Analysis and Resolution','CAR 2.1','PG 2 – Address Causes of Outcomes','Develop action proposals that address identified root causes to prevent recurrence or promote recurrence of positive outcomes','ML3','Core','All'],
['CAR','Causal Analysis and Resolution','CAR 2.2','PG 2 – Address Causes of Outcomes','Implement action proposals selected for addressing identified root causes','ML3','Core','All'],
['CAR','Causal Analysis and Resolution','CAR 3.1','PG 3 – Evaluate Effect of Changes','Evaluate the effect of implemented actions on process performance and product quality','ML3','Core','All'],
['CAR','Causal Analysis and Resolution','CAR 3.2','PG 3 – Evaluate Effect of Changes','Record causal analysis data and lessons learned for use across the organization','ML3','Core','All'],

/* ── OT — Organizational Training ── */
['OT','Organizational Training','OT 1.1','PG 1 – Establish Training Needs','Identify and document organizational strategic training needs aligned to business objectives','ML3','Core','All'],
['OT','Organizational Training','OT 1.2','PG 1 – Establish Training Needs','Identify and document training needed to support performance of defined processes','ML3','Core','All'],
['OT','Organizational Training','OT 2.1','PG 2 – Provide Training','Establish and maintain a tactical training plan that addresses current organizational training needs','ML3','Core','All'],
['OT','Organizational Training','OT 2.2','PG 2 – Provide Training','Deliver required training using appropriate instructional methods and media','ML3','Core','All'],
['OT','Organizational Training','OT 3.1','PG 3 – Evaluate Training Effectiveness','Assess the effectiveness of training by evaluating outcomes against defined criteria','ML3','Core','All'],

/* ── PAD — Process Asset Development ── */
['PAD','Process Asset Development','PAD 1.1','PG 1 – Establish Standard Processes','Establish and maintain the organization\'s set of standard processes covering all required practice areas','ML3','Core','All'],
['PAD','Process Asset Development','PAD 1.2','PG 1 – Establish Standard Processes','Establish and maintain descriptions of life cycle models approved for use in the organization','ML3','Core','All'],
['PAD','Process Asset Development','PAD 2.1','PG 2 – Develop Process Assets','Establish and maintain the organizational process asset library, including process descriptions, templates, and examples','ML3','Core','All'],
['PAD','Process Asset Development','PAD 2.2','PG 2 – Develop Process Assets','Establish and maintain tailoring guidelines and criteria for adapting standard processes to project needs','ML3','Core','All'],
['PAD','Process Asset Development','PAD 3.1','PG 3 – Improve Process Assets','Collect process improvement proposals and lessons learned and incorporate improvements into organizational process assets','ML3','Core','All'],
['PAD','Process Asset Development','PAD 3.2','PG 3 – Improve Process Assets','Maintain historical data and lessons learned in the organizational process asset library','ML3','Core','All'],

/* ── PCM — Process Capability Management ── */
['PCM','Process Capability Management','PCM 1.1','PG 1 – Establish Process Capability Baselines','Collect and analyze process performance data from projects to establish organizational process capability baselines','ML3','Core','All'],
['PCM','Process Capability Management','PCM 1.2','PG 1 – Establish Process Capability Baselines','Establish and maintain organizational process performance baselines for key process measures','ML3','Core','All'],
['PCM','Process Capability Management','PCM 2.1','PG 2 – Plan Process Capability Management','Identify processes and sub-processes to be managed for process capability and define measures to support quantitative management','ML3','Core','All'],
['PCM','Process Capability Management','PCM 2.2','PG 2 – Plan Process Capability Management','Establish and maintain process performance models to characterize expected process performance','ML3','Core','All'],
['PCM','Process Capability Management','PCM 3.1','PG 3 – Apply Process Capability Management','Use process performance baselines and models to support planning, monitoring, and management decisions','ML3','Core','All'],
['PCM','Process Capability Management','PCM 3.2','PG 3 – Apply Process Capability Management','Take action to address process performance shortfalls and sustain process capability improvements','ML3','Core','All'],

/* ═══════════════════════════════════════════════════════════════════
   ML3 — DEVELOPMENT DOMAIN  (domain = 'Development')
═══════════════════════════════════════════════════════════════════ */

/* ── TS — Technical Solution ── */
['TS','Technical Solution','TS 1.1','PG 1 – Select a Technical Solution','Select technical solutions for requirements by evaluating and comparing candidate solutions against established criteria','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 1.2','PG 1 – Select a Technical Solution','Document the design rationale, trade-offs considered, and technical solution selected for each product component','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 2.1','PG 2 – Develop the Design','Develop, document, and maintain product and product-component designs','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 2.2','PG 2 – Develop the Design','Establish and maintain a complete and traceable definition of product and component interfaces','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 2.3','PG 2 – Develop the Design','Analyze designs for build-versus-buy feasibility, risk, and compliance with requirements','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 3.1','PG 3 – Implement the Design','Implement designs using applicable coding standards, design rules, and criteria','ML3','Development-Specific','Development'],
['TS','Technical Solution','TS 3.2','PG 3 – Implement the Design','Develop and maintain documentation needed to install, operate, and maintain the product','ML3','Development-Specific','Development'],

/* ── PI — Product Integration ── */
['PI','Product Integration','PI 1.1','PG 1 – Prepare for Product Integration','Establish and maintain an integration strategy covering sequence, environment, procedures, and criteria','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 1.2','PG 1 – Prepare for Product Integration','Establish and maintain the product integration environment including tools, fixtures, and simulators','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 2.1','PG 2 – Manage Interfaces','Review interface descriptions for completeness and manage interfaces between components throughout integration','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 2.2','PG 2 – Manage Interfaces','Manage interface changes and ensure affected components and documentation are updated accordingly','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 3.1','PG 3 – Assemble and Deliver the Product','Confirm that product components satisfy interface requirements before integration','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 3.2','PG 3 – Assemble and Deliver the Product','Assemble and integrate product components according to the integration strategy and procedures','ML3','Development-Specific','Development'],
['PI','Product Integration','PI 3.3','PG 3 – Assemble and Deliver the Product','Evaluate assembled product components to confirm integration results meet requirements and are ready for delivery','ML3','Development-Specific','Development'],

/* ── VV — Verification and Validation ── */
['VV','Verification and Validation','VV 1.1','PG 1 – Prepare for Verification and Validation','Select work products to be verified and validated and define verification and validation methods','ML3','Development-Specific','Development'],
['VV','Verification and Validation','VV 1.2','PG 1 – Prepare for Verification and Validation','Establish and maintain the verification and validation environment, criteria, and procedures','ML3','Development-Specific','Development'],
['VV','Verification and Validation','VV 2.1','PG 2 – Perform Verification','Perform verification using defined methods and procedures and document results','ML3','Development-Specific','Development'],
['VV','Verification and Validation','VV 2.2','PG 2 – Perform Verification','Analyze verification results and identify corrective actions for detected defects and issues','ML3','Development-Specific','Development'],
['VV','Verification and Validation','VV 3.1','PG 3 – Perform Validation','Perform validation using defined methods and procedures to demonstrate that the product fulfills intended use in its operational environment','ML3','Development-Specific','Development'],
['VV','Verification and Validation','VV 3.2','PG 3 – Perform Validation','Analyze validation results and identify corrective actions needed to address gaps','ML3','Development-Specific','Development'],

/* ── PR — Peer Reviews ── */
['PR','Peer Reviews','PR 1.1','PG 1 – Prepare for Peer Reviews','Select work products to be peer reviewed and define the review approach and objectives','ML3','Development-Specific','Development'],
['PR','Peer Reviews','PR 2.1','PG 2 – Conduct Peer Reviews','Prepare for peer reviews by distributing materials and assigning roles in advance','ML3','Development-Specific','Development'],
['PR','Peer Reviews','PR 2.2','PG 2 – Conduct Peer Reviews','Conduct peer reviews and record identified defects, issues, and action items','ML3','Development-Specific','Development'],
['PR','Peer Reviews','PR 3.1','PG 3 – Analyze Peer Review Data','Analyze peer review data to identify trends, common defect types, and process improvement opportunities','ML3','Development-Specific','Development'],

/* ═══════════════════════════════════════════════════════════════════
   ML3 — SERVICES DOMAIN  (domain = 'Services')
═══════════════════════════════════════════════════════════════════ */

/* ── STSM — Strategic Service Management ── */
['STSM','Strategic Service Management','STSM 1.1','PG 1 – Establish Strategic Needs','Establish and maintain an understanding of strategic service needs and how services support organizational objectives','ML3','Services-Specific','Services'],
['STSM','Strategic Service Management','STSM 1.2','PG 1 – Establish Strategic Needs','Establish and maintain a strategic plan for services aligned to stakeholder and organizational needs','ML3','Services-Specific','Services'],
['STSM','Strategic Service Management','STSM 2.1','PG 2 – Establish Service System Approach','Establish and maintain a standard service approach that covers service delivery, capability, and capacity requirements','ML3','Services-Specific','Services'],
['STSM','Strategic Service Management','STSM 2.2','PG 2 – Establish Service System Approach','Maintain a service catalog that describes available services, features, service levels, and constraints','ML3','Services-Specific','Services'],
['STSM','Strategic Service Management','STSM 3.1','PG 3 – Manage the Service Strategy','Monitor and improve the service strategy based on changes in organizational objectives and service performance data','ML3','Services-Specific','Services'],

/* ── SSD — Service System Development ── */
['SSD','Service System Development','SSD 1.1','PG 1 – Analyze Service Needs','Analyze service needs and constraints to establish and maintain service system requirements','ML3','Services-Specific','Services'],
['SSD','Service System Development','SSD 1.2','PG 1 – Analyze Service Needs','Identify and document interface requirements between service system components and external systems','ML3','Services-Specific','Services'],
['SSD','Service System Development','SSD 2.1','PG 2 – Design the Service System','Develop, document, and maintain service system designs that satisfy service system requirements','ML3','Services-Specific','Services'],
['SSD','Service System Development','SSD 2.2','PG 2 – Design the Service System','Establish and manage the service system integration environment, procedures, and criteria','ML3','Services-Specific','Services'],
['SSD','Service System Development','SSD 3.1','PG 3 – Deliver the Service System','Implement and integrate service system components according to the service system design','ML3','Services-Specific','Services'],
['SSD','Service System Development','SSD 3.2','PG 3 – Deliver the Service System','Verify and validate the service system against requirements and confirm readiness for service delivery','ML3','Services-Specific','Services'],

/* ═══════════════════════════════════════════════════════════════════
   ML4 — CORE PRACTICE AREAS  (domain = 'All')
   Quantitatively Managed: statistical & quantitative management
═══════════════════════════════════════════════════════════════════ */

/* ── OPM — Organizational Performance Management ── */
['OPM','Organizational Performance Management','OPM 1.1','PG 1 – Establish Quantitative Objectives','Establish and maintain quantitative performance objectives for the organization aligned to business objectives and stakeholder needs','ML4','Core','All'],
['OPM','Organizational Performance Management','OPM 1.2','PG 1 – Establish Quantitative Objectives','Identify the sub-processes and measures to be used for quantitative management and monitoring of organizational performance','ML4','Core','All'],
['OPM','Organizational Performance Management','OPM 2.1','PG 2 – Manage Performance Quantitatively','Apply statistical and other quantitative techniques to analyze process performance data and predict future organizational performance','ML4','Core','All'],
['OPM','Organizational Performance Management','OPM 2.2','PG 2 – Manage Performance Quantitatively','Monitor and manage process and product performance against quantitative objectives and take corrective action when objectives are not being achieved','ML4','Core','All'],
['OPM','Organizational Performance Management','OPM 3.1','PG 3 – Evaluate and Adjust','Identify root causes of performance shortfalls against quantitative objectives using statistical analysis and implement targeted corrective actions','ML4','Core','All'],
['OPM','Organizational Performance Management','OPM 3.2','PG 3 – Evaluate and Adjust','Evaluate the effect of implemented changes on process performance using quantitative methods and adjust objectives or processes accordingly','ML4','Core','All'],

/* ═══════════════════════════════════════════════════════════════════
   ML5 — CORE PRACTICE AREAS  (domain = 'All')
   Optimizing: innovation, continuous improvement, defect prevention
═══════════════════════════════════════════════════════════════════ */

/* ── OPM (continued at ML5) — Innovation and Optimization ── */
['OPM','Organizational Performance Management','OPM 4.1','PG 4 – Identify Improvements','Identify and analyze innovative process and technology improvements that could enhance quality, cycle time, and organizational performance','ML5','Core','All'],
['OPM','Organizational Performance Management','OPM 4.2','PG 4 – Identify Improvements','Evaluate candidate improvements using quantitative performance models and data to estimate potential benefit and risk before adoption','ML5','Core','All'],
['OPM','Organizational Performance Management','OPM 5.1','PG 5 – Deploy and Sustain Improvements','Deploy selected process and technology improvements across the organization using a managed deployment plan with defined success criteria','ML5','Core','All'],
['OPM','Organizational Performance Management','OPM 5.2','PG 5 – Deploy and Sustain Improvements','Measure and evaluate the effect of deployed improvements on organizational performance objectives and sustain achieved gains through institutionalization','ML5','Core','All'],

];

/* ═══════════════════════════════════════════════════════════════════
   EVIDENCE — Artifacts & Appraiser Tips per Practice Area
═══════════════════════════════════════════════════════════════════ */
var EVIDENCE = {
  PLAN: {
    artifacts: ['Project plan (schedule, WBS, milestones)','Resource plan and staffing model','Stakeholder communication plan','Risk and dependency register','Project data management plan','Work environment plan','Plan revision history / version log'],
    tips: 'Appraisers look for plans that are realistic, traceable to WBS, and show evidence of stakeholder sign-off. Versioned plans with change history are strongest.'
  },
  EST: {
    artifacts: ['Estimation worksheets (function points, story points, LOC, or analogous)','Basis-of-estimate (BOE) documents','Historical data repository / estimating database','Estimate review meeting minutes','Estimate-to-actual reconciliation records'],
    tips: 'Show the method used (e.g., PERT, planning poker), the data inputs, and that estimates were reviewed before commitment. Actuals compared to estimates demonstrate calibration.'
  },
  MC: {
    artifacts: ['Status reports (weekly/sprint/monthly)','Earned value or burn-down charts','Corrective action log with closure evidence','Management review meeting minutes','Dashboard or metrics trend charts'],
    tips: 'Appraisers want to see that deviations trigger analysis and documented corrective actions, not just narrative updates. Show closed-loop corrective actions.'
  },
  CM: {
    artifacts: ['Configuration management plan','Baseline inventory / configuration item list','Change request log with disposition records','Configuration audit reports','Version control history (e.g., Git log, SVN log)','Access control policy for CM repository'],
    tips: 'Demonstrate that baselines are formally established and that changes only enter through an approved change request process. Audit results showing no unauthorized changes are strong evidence.'
  },
  PQA: {
    artifacts: ['QA audit schedule and completed audit reports','Non-conformance / finding log with corrective actions','Process evaluation checklists','QA summary reports to management','Evidence of independent QA function'],
    tips: 'Objectivity is key — auditors must be independent of the work being reviewed. Show that noncompliance issues are escalated to management and tracked to closure.'
  },
  RSK: {
    artifacts: ['Risk register with probability, impact, and priority ratings','Risk mitigation plans and contingency plans','Risk review meeting minutes','Risk status trend reports','Closed-risk records with lessons learned'],
    tips: 'A living risk register updated at each review cycle is essential. Appraisers check that mitigation actions are actually executed, not just planned.'
  },
  GOV: {
    artifacts: ['Organizational policies and standards library','Governance framework or charter','Performance review meeting records','Policy compliance audit results','Process improvement governance records'],
    tips: 'Policies must be communicated and acknowledged. Show evidence that managers and staff understand expected behaviors, and that governance reviews actually occur.'
  },
  II: {
    artifacts: ['Infrastructure inventory (tools, environments, templates)','Tool acquisition or license records','Staff access and onboarding records','Infrastructure evaluation results','Improvement action log for infrastructure gaps'],
    tips: 'Demonstrate that infrastructure is actively managed and improved. Evidence of staff training on tools, and documented evaluations of infrastructure effectiveness, are strong indicators.'
  },
  MPM: {
    artifacts: ['Measurement plan with objectives and specified measures','Data collection procedures','Measurement repository / data store','Analysis reports and trend charts','Management decision records referencing measurement data'],
    tips: 'Appraisers verify that measures are actually used for decisions, not just collected. Link measurement outputs to specific management actions or plan adjustments.'
  },
  RDM: {
    artifacts: ['Requirements specification (SRS or equivalent)','Traceability matrix (stakeholder needs → product requirements → test cases)','Requirements change log with impact assessments','Stakeholder review and sign-off records','Interface requirements document'],
    tips: 'Bidirectional traceability is the #1 item appraisers check. Show that every requirement is traceable to a stakeholder need AND to a verification activity.'
  },
  DAR: {
    artifacts: ['Decision analysis log identifying issues subject to formal evaluation','Evaluation criteria and weighting rationale','Alternative analysis documentation','Selected solution rationale record','Post-decision effectiveness review'],
    tips: 'Not every decision needs formal DAR — show that criteria exist for determining which decisions qualify, and that those qualifying decisions are fully documented.'
  },
  CAR: {
    artifacts: ['Defect and problem log with root cause fields','Root cause analysis reports (5-Why, Ishikawa, etc.)','Action proposal records','Action implementation evidence','Process performance trend data showing improvement'],
    tips: 'Appraisers look for systemic fixes, not one-off patches. Evidence that the same defect type stops recurring is the strongest proof of effective CAR.'
  },
  OT: {
    artifacts: ['Organizational training needs assessment','Training plan (tactical and strategic)','Training records / completion certificates','Training effectiveness evaluation results','Curriculum or course materials'],
    tips: 'Training must be tied to organizational process needs, not just individual development. Show that effectiveness is measured (e.g., post-training assessments, performance change).'
  },
  PAD: {
    artifacts: ['Organizational process asset library (OPAL) with indexed assets','Standard process descriptions','Life cycle model descriptions','Tailoring guidelines and approved tailoring log','Process improvement proposals and disposition records'],
    tips: 'The OPAL must be actively maintained and accessible to projects. Show that projects actually use and tailor from the standard process, not create processes from scratch.'
  },
  PCM: {
    artifacts: ['Process performance baselines (control charts, histograms)','Process performance models','Quantitative project management plans referencing baselines','Statistical analysis reports','Corrective action records tied to baseline violations'],
    tips: 'Baselines must be statistically derived. Appraisers look for control charts showing stable processes and evidence that projects use baselines to set quantitative goals.'
  },
  TS: {
    artifacts: ['Architecture and design documents (SDD, HLD, LLD)','Trade study / alternative analysis records','Interface design documents (ICD, API specs)','Build-vs-buy analysis','Coding standards and design review checklists','Product documentation (installation, operation, maintenance guides)'],
    tips: 'Design rationale is critical — show WHY the solution was chosen, not just what it is. Interface completeness and internal consistency between design levels are key appraisal checks.'
  },
  PI: {
    artifacts: ['Integration strategy and sequence plan','Integration environment description','Interface control documents (ICDs)','Integration test procedures and results','Integration build records / CI pipeline logs','Delivery records and acceptance documentation'],
    tips: 'Show that components are confirmed to meet interface requirements BEFORE assembly. Automated CI/CD pipelines with logged results are excellent objective evidence.'
  },
  VV: {
    artifacts: ['Verification and validation plan','Test plans, test cases, and test procedures','Test execution logs and results','Defect reports and regression test records','Validation results against operational scenarios','Peer review and inspection records'],
    tips: 'Distinguish verification (built right) from validation (built the right thing). Appraisers check that each requirement has a corresponding test case and that all tests are executed and recorded.'
  },
  PR: {
    artifacts: ['Peer review plan or schedule','Review preparation records (distributed materials, checklists)','Review meeting minutes or defect logs','Action item tracking records','Defect density trend data across reviews'],
    tips: 'Preparation evidence is often missed — show that reviewers received materials in advance. Data showing defect trends improving over time demonstrates process effectiveness.'
  },
  SD: {
    artifacts: ['Service agreements / SLAs with customers','Service level targets and measurement definitions','Service delivery readiness checklists','Service performance reports','Customer review meeting minutes','Service continuity and recovery plans'],
    tips: 'Show that service agreements are actively monitored against defined targets. Customer review records demonstrating two-way communication on performance gaps are strong evidence.'
  },
  IRP: {
    artifacts: ['Incident classification criteria and severity matrix','Incident log / ticketing system records','Incident resolution records with timelines vs. SLA','Stakeholder notification records','Root cause analysis reports for recurring incidents','Preventative action implementation records'],
    tips: 'Appraisers look for closed-loop incident management: incidents classified consistently, resolved within agreed timeframes, and trend data used to drive prevention.'
  },
  STSM: {
    artifacts: ['Strategic service plan aligned to organizational goals','Service catalog with service descriptions and SLA tiers','Service capability and capacity assessments','Service strategy review meeting records','Improvement actions linked to performance data'],
    tips: 'Show that the service strategy is a living document reviewed against actual performance. Alignment between the service catalog and customer agreements demonstrates strategic coherence.'
  },
  SSD: {
    artifacts: ['Service system requirements document','Service system design and architecture document','Interface requirements between service components','Integration and verification plan for service system','Service system verification and validation results','Service readiness review records'],
    tips: 'Appraisers check that service system requirements are traceable to customer needs and that verification demonstrates the system supports intended service delivery before go-live.'
  },
  OPM: {
    artifacts: ['Quantitative performance objectives aligned to business goals','Process performance baselines (control charts, histograms, capability data)','Statistical analysis reports showing use of SPC or regression techniques','Quantitative project management plans referencing organizational baselines','Performance monitoring dashboards with quantitative thresholds and alerts','Corrective action records tied to quantitative deviations from objectives','Innovation proposals with quantitative benefit/risk evaluation','Deployment plans for selected process or technology improvements','Before-and-after performance measurement records for deployed improvements','Lessons learned from improvement deployments'],
    tips: 'ML4/ML5 appraisers expect statistical rigor — not just data collection but actual use of control charts, capability indices (Cp, Cpk), prediction models, and regression analysis. Show that quantitative objectives drive management decisions. For ML5, the innovation pipeline must show proposals being evaluated quantitatively, selected, piloted, and deployed with measured outcomes.'
  }
};

/* ═══════════════════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════════════════ */
function escH(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderTable(rows) {
  if (!rows.length) {
    document.getElementById('results-table').innerHTML = '<p class="text-muted p-3">No practices match the current filters.</p>';
    document.getElementById('result-count').textContent = '0';
    return;
  }
  var html = '<table class="table table-bordered table-hover table-sm align-middle mb-0"><thead class="table-dark sticky-top"><tr>'
    + '<th>PA</th><th>Practice #</th><th>Practice Group</th><th>Description</th><th>Level</th><th>Category</th><th>Domain</th>'
    + '</tr></thead><tbody>';
  rows.forEach(function(d) {
    var domBadge = d[7] === 'All' ? '<span class="badge bg-secondary">Core</span>'
      : d[7] === 'Development' ? '<span class="badge bg-primary">Dev</span>'
      : '<span class="badge bg-success">Svc</span>';
    html += '<tr>'
      + '<td><abbr title="' + escH(d[1]) + '">' + escH(d[0]) + '</abbr></td>'
      + '<td class="text-nowrap">' + escH(d[2]) + '</td>'
      + '<td>' + escH(d[3]) + '</td>'
      + '<td>' + escH(d[4]) + '</td>'
      + '<td><span class="badge bg-dark">' + escH(d[5]) + '</span></td>'
      + '<td>' + escH(d[6]) + '</td>'
      + '<td>' + domBadge + '</td>'
      + '</tr>';
  });
  html += '</tbody></table>';
  document.getElementById('results-table').innerHTML = html;
  document.getElementById('result-count').textContent = rows.length;
}

function applyFilters() {
  var lvl  = document.getElementById('ctrl-level').value;
  var pa   = document.getElementById('ctrl-pa').value;
  var cat  = document.getElementById('ctrl-cat').value;
  var dom  = document.getElementById('ctrl-domain').value;
  var srch = document.getElementById('ctrl-search').value.trim().toLowerCase();
  var rows = CMMI_DATA.filter(function(d) {
    if (lvl  && d[5] !== lvl)  return false;
    if (pa   && d[0] !== pa)   return false;
    if (cat  && d[6] !== cat)  return false;
    if (dom  && d[7] !== 'All' && d[7] !== dom) return false;
    if (srch && (d[0]+d[1]+d[2]+d[3]+d[4]).toLowerCase().indexOf(srch) === -1) return false;
    return true;
  });
  renderTable(rows);
}

function populatePADropdown() {
  var seen = {};
  var sel = document.getElementById('ctrl-pa');
  CMMI_DATA.forEach(function(d) {
    if (!seen[d[0]]) {
      seen[d[0]] = true;
      var opt = document.createElement('option');
      opt.value = d[0];
      opt.textContent = d[0] + ' — ' + d[1];
      sel.appendChild(opt);
    }
  });
}

/* ═══════════════════════════════════════════════════════════════════
   EXCEL EXPORT
═══════════════════════════════════════════════════════════════════ */
function makeSheet(rows, cols) {
  var ws = {};
  var range = { s: { c: 0, r: 0 }, e: { c: cols.length - 1, r: rows.length } };
  cols.forEach(function(c, ci) {
    ws[XLSX.utils.encode_cell({ r: 0, c: ci })] = { v: c, t: 's', s: { font: { bold: true } } };
  });
  rows.forEach(function(row, ri) {
    row.forEach(function(val, ci) {
      ws[XLSX.utils.encode_cell({ r: ri + 1, c: ci })] = { v: val, t: 's' };
    });
  });
  ws['!ref'] = XLSX.utils.encode_range(range);
  ws['!freeze'] = { xSplit: 0, ySplit: 1 };
  return ws;
}

function downloadExcel() {
  var wb = XLSX.utils.book_new();

  /* Sheet 1 — All Practices */
  var allRows = CMMI_DATA.map(function(d) {
    return [d[0], d[1], d[2], d[3], d[4], d[5], d[6], d[7]];
  });
  XLSX.utils.book_append_sheet(wb, makeSheet(allRows,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'All Practices');

  /* Sheet 2 — ML2 Core */
  var ml2core = CMMI_DATA.filter(function(d){ return d[5]==='ML2' && d[7]==='All'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml2core,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML2 Core');

  /* Sheet 3 — ML2 Development */
  var ml2dev = CMMI_DATA.filter(function(d){ return d[5]==='ML2' && d[7]==='Development'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml2dev,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML2 Development');

  /* Sheet 4 — ML2 Services */
  var ml2svc = CMMI_DATA.filter(function(d){ return d[5]==='ML2' && d[7]==='Services'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml2svc,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML2 Services');

  /* Sheet 5 — ML3 Core */
  var ml3core = CMMI_DATA.filter(function(d){ return d[5]==='ML3' && d[7]==='All'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml3core,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML3 Core');

  /* Sheet 6 — ML3 Development */
  var ml3dev = CMMI_DATA.filter(function(d){ return d[5]==='ML3' && d[7]==='Development'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml3dev,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML3 Development');

  /* Sheet 7 — ML3 Services */
  var ml3svc = CMMI_DATA.filter(function(d){ return d[5]==='ML3' && d[7]==='Services'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml3svc,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML3 Services');

  /* Sheet 8 — ML4 (OPM) */
  var ml4 = CMMI_DATA.filter(function(d){ return d[5]==='ML4'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml4,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML4 Quantitative Mgmt');

  /* Sheet 9 — ML5 (OPM Optimization) */
  var ml5 = CMMI_DATA.filter(function(d){ return d[5]==='ML5'; })
    .map(function(d){ return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7]]; });
  XLSX.utils.book_append_sheet(wb, makeSheet(ml5,
    ['PA','PA Full Name','Practice #','Practice Group','Description','Level','Category','Domain']),
    'ML5 Optimizing');

  /* Sheet 10 — Evidence Guide */
  var evRows = [];
  Object.keys(EVIDENCE).forEach(function(pa) {
    var e = EVIDENCE[pa];
    e.artifacts.forEach(function(art, i) {
      evRows.push([pa, i === 0 ? art : art, i === 0 ? e.tips : '']);
    });
    evRows.push(['','','']);
  });
  XLSX.utils.book_append_sheet(wb, makeSheet(evRows,
    ['PA','Artifact / Evidence Item','Appraiser Tips']),
    'Evidence Guide');

  XLSX.writeFile(wb, 'CMMI_v2_ML5_All_Domains.xlsx');
}

/* ═══════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
  populatePADropdown();
  renderTable(CMMI_DATA);

  ['ctrl-level','ctrl-pa','ctrl-cat','ctrl-domain'].forEach(function(id) {
    document.getElementById(id).addEventListener('change', applyFilters);
  });
  document.getElementById('ctrl-search').addEventListener('input', applyFilters);

  document.getElementById('btn-reset').addEventListener('click', function() {
    ['ctrl-level','ctrl-pa','ctrl-cat','ctrl-domain'].forEach(function(id) {
      document.getElementById(id).value = '';
    });
    document.getElementById('ctrl-search').value = '';
    renderTable(CMMI_DATA);
  });

  document.getElementById('btn-export').addEventListener('click', downloadExcel);

  /* Stats */
  document.getElementById('stat-total').textContent  = CMMI_DATA.length;
  document.getElementById('stat-ml2').textContent    = CMMI_DATA.filter(function(d){ return d[5]==='ML2'; }).length;
  document.getElementById('stat-ml3').textContent    = CMMI_DATA.filter(function(d){ return d[5]==='ML3'; }).length;
  document.getElementById('stat-ml4').textContent    = CMMI_DATA.filter(function(d){ return d[5]==='ML4'; }).length;
  document.getElementById('stat-ml5').textContent    = CMMI_DATA.filter(function(d){ return d[5]==='ML5'; }).length;
  var pas = {};
  CMMI_DATA.forEach(function(d){ pas[d[0]] = true; });
  document.getElementById('stat-pas').textContent    = Object.keys(pas).length;
});
