/* CMMI DEV Level 3 — data + UI + Excel export */
/* Columns: [pa, paFull, controlNum, sg, description, level, category] */
var CMMI_DATA = [
/* ── REQM ── */
['REQM','Requirements Management','REQM-SP1.1','SG1: Manage Requirements','Obtain an Understanding of Requirements — Develop an understanding with the requirements providers on the meaning of the requirements.','ML2','Core'],
['REQM','Requirements Management','REQM-SP1.2','SG1: Manage Requirements','Obtain Commitment to Requirements — Obtain commitment to the requirements from the project participants.','ML2','Core'],
['REQM','Requirements Management','REQM-SP1.3','SG1: Manage Requirements','Manage Requirements Changes — Manage changes to the requirements as they evolve during the project.','ML2','Core'],
['REQM','Requirements Management','REQM-SP1.4','SG1: Manage Requirements','Maintain Bidirectional Traceability of Requirements — Maintain bidirectional traceability among requirements, project plans, and work products.','ML2','Core'],
['REQM','Requirements Management','REQM-SP1.5','SG1: Manage Requirements','Identify Inconsistencies Between Project Work and Requirements — Identify inconsistencies between project plans, work products, and the requirements.','ML2','Core'],
/* ── PP ── */
['PP','Project Planning','PP-SP1.1','SG1: Establish Estimates','Estimate the Scope of the Project — Establish a WBS to estimate the scope of the project.','ML2','Core'],
['PP','Project Planning','PP-SP1.2','SG1: Establish Estimates','Establish Estimates of Work Product and Task Attributes — Establish and maintain estimates of the attributes of the work products and tasks.','ML2','Core'],
['PP','Project Planning','PP-SP1.3','SG1: Establish Estimates','Define Project Lifecycle — Define the project lifecycle phases upon which to scope the planning effort.','ML2','Core'],
['PP','Project Planning','PP-SP1.4','SG1: Establish Estimates','Determine Estimates of Effort and Cost — Estimate project effort and cost for work products and tasks based on estimation rationale.','ML2','Core'],
['PP','Project Planning','PP-SP2.1','SG2: Develop a Project Plan','Establish the Budget and Schedule — Establish and maintain the project budget and schedule.','ML2','Core'],
['PP','Project Planning','PP-SP2.2','SG2: Develop a Project Plan','Identify Project Risks — Identify and analyze project risks.','ML2','Core'],
['PP','Project Planning','PP-SP2.3','SG2: Develop a Project Plan','Plan for Data Management — Plan for the management of project data.','ML2','Core'],
['PP','Project Planning','PP-SP2.4','SG2: Develop a Project Plan','Plan for Project Resources — Plan for necessary resources to perform the project.','ML2','Core'],
['PP','Project Planning','PP-SP2.5','SG2: Develop a Project Plan','Plan for Needed Knowledge and Skills — Plan for knowledge and skills needed to perform the project.','ML2','Core'],
['PP','Project Planning','PP-SP2.6','SG2: Develop a Project Plan','Plan Stakeholder Involvement — Plan the involvement of identified stakeholders.','ML2','Core'],
['PP','Project Planning','PP-SP2.7','SG2: Develop a Project Plan','Establish the Project Plan — Establish and maintain the overall project plan content.','ML2','Core'],
['PP','Project Planning','PP-SP3.1','SG3: Obtain Commitment to the Plan','Review Plans That Affect the Project — Review all plans that affect the project to understand project commitments.','ML2','Core'],
['PP','Project Planning','PP-SP3.2','SG3: Obtain Commitment to the Plan','Reconcile Work and Resource Levels — Reconcile the project plan to reflect available and estimated resources.','ML2','Core'],
['PP','Project Planning','PP-SP3.3','SG3: Obtain Commitment to the Plan','Obtain Plan Commitment — Obtain commitment from relevant stakeholders responsible for performing and supporting plan execution.','ML2','Core'],
/* ── PMC ── */
['PMC','Project Monitoring and Control','PMC-SP1.1','SG1: Monitor Project Against Plan','Monitor Project Planning Parameters — Monitor actual values of project planning parameters against the project plan.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.2','SG1: Monitor Project Against Plan','Monitor Commitments — Monitor commitments against those identified in the project plan.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.3','SG1: Monitor Project Against Plan','Monitor Project Risks — Monitor risks against those identified in the project plan.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.4','SG1: Monitor Project Against Plan','Monitor Data Management — Monitor the management of project data against the project plan.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.5','SG1: Monitor Project Against Plan','Monitor Stakeholder Involvement — Monitor stakeholder involvement against the project plan.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.6','SG1: Monitor Project Against Plan','Conduct Progress Reviews — Periodically review the project\'s progress, performance, and issues.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP1.7','SG1: Monitor Project Against Plan','Conduct Milestone Reviews — Review the project\'s accomplishments and results at selected project milestones.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP2.1','SG2: Manage Corrective Action to Closure','Analyze Issues — Collect and analyze issues and determine corrective actions to address them.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP2.2','SG2: Manage Corrective Action to Closure','Take Corrective Action — Take corrective action on identified issues.','ML2','Core'],
['PMC','Project Monitoring and Control','PMC-SP2.3','SG2: Manage Corrective Action to Closure','Manage Corrective Action — Manage corrective actions to closure.','ML2','Core'],
/* ── CM ── */
['CM','Configuration Management','CM-SP1.1','SG1: Establish Baselines','Identify Configuration Items — Identify the configuration items, components, and related work products to be placed under configuration management.','ML2','Core'],
['CM','Configuration Management','CM-SP1.2','SG1: Establish Baselines','Establish a Configuration Management System — Establish and maintain a CM and change management system for controlling work products.','ML2','Core'],
['CM','Configuration Management','CM-SP1.3','SG1: Establish Baselines','Create or Release Baselines — Create or release baselines for internal use and for delivery to the customer.','ML2','Core'],
['CM','Configuration Management','CM-SP2.1','SG2: Track and Control Changes','Track Change Requests — Track change requests for the configuration items.','ML2','Core'],
['CM','Configuration Management','CM-SP2.2','SG2: Track and Control Changes','Control Configuration Items — Control changes to the configuration items.','ML2','Core'],
['CM','Configuration Management','CM-SP3.1','SG3: Establish Integrity','Establish Configuration Management Records — Establish and maintain records describing configuration items.','ML2','Core'],
['CM','Configuration Management','CM-SP3.2','SG3: Establish Integrity','Perform Configuration Audits — Perform configuration audits to maintain the integrity of configuration baselines.','ML2','Core'],
/* ── MA ── */
['MA','Measurement and Analysis','MA-SP1.1','SG1: Align Measurement and Analysis Activities','Establish Measurement Objectives — Establish and maintain measurement objectives derived from identified information needs and objectives.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP1.2','SG1: Align Measurement and Analysis Activities','Specify Measures — Specify measures to address the measurement objectives.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP1.3','SG1: Align Measurement and Analysis Activities','Specify Data Collection and Storage Procedures — Specify how measurement data will be obtained and stored.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP1.4','SG1: Align Measurement and Analysis Activities','Specify Analysis Procedures — Specify how measurement data will be analyzed and reported.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP2.1','SG2: Provide Measurement Results','Collect Measurement Data — Obtain the specified measurement data.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP2.2','SG2: Provide Measurement Results','Analyze Measurement Data — Analyze and interpret measurement data.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP2.3','SG2: Provide Measurement Results','Store Data and Results — Manage and store measurement data, measurement specifications, and analysis results.','ML2','Core'],
['MA','Measurement and Analysis','MA-SP2.4','SG2: Provide Measurement Results','Communicate Results — Report results of measurement and analysis activities to all relevant stakeholders.','ML2','Core'],
/* ── PPQA ── */
['PPQA','Process and Product Quality Assurance','PPQA-SP1.1','SG1: Objectively Evaluate Processes and Work Products','Objectively Evaluate Processes — Objectively evaluate selected performed processes against applicable process descriptions, standards, and procedures.','ML2','Core'],
['PPQA','Process and Product Quality Assurance','PPQA-SP1.2','SG1: Objectively Evaluate Processes and Work Products','Objectively Evaluate Work Products — Objectively evaluate selected work products against applicable process descriptions, standards, and procedures.','ML2','Core'],
['PPQA','Process and Product Quality Assurance','PPQA-SP2.1','SG2: Provide Objective Insight','Communicate and Ensure Resolution of Noncompliance Issues — Communicate quality issues and ensure resolution of noncompliance issues with staff and managers.','ML2','Core'],
['PPQA','Process and Product Quality Assurance','PPQA-SP2.2','SG2: Provide Objective Insight','Establish Records — Establish and maintain records of quality assurance activities.','ML2','Core'],
/* ── SAM ── */
['SAM','Supplier Agreement Management','SAM-SP1.1','SG1: Establish Supplier Agreements','Determine Acquisition Type — Determine the type of acquisition for each product or product component to be acquired.','ML2','Core'],
['SAM','Supplier Agreement Management','SAM-SP1.2','SG1: Establish Supplier Agreements','Select Suppliers — Select suppliers based on an evaluation of their ability to meet specified requirements and established criteria.','ML2','Core'],
['SAM','Supplier Agreement Management','SAM-SP1.3','SG1: Establish Supplier Agreements','Establish Supplier Agreements — Establish and maintain formal agreements with the supplier.','ML2','Core'],
['SAM','Supplier Agreement Management','SAM-SP2.1','SG2: Satisfy Supplier Agreements','Execute the Supplier Agreement — Perform activities with the supplier as specified in the supplier agreement.','ML2','Core'],
['SAM','Supplier Agreement Management','SAM-SP2.2','SG2: Satisfy Supplier Agreements','Accept the Acquired Product — Ensure that the supplier agreement is satisfied before accepting the acquired product.','ML2','Core'],
['SAM','Supplier Agreement Management','SAM-SP2.3','SG2: Satisfy Supplier Agreements','Ensure Transition of Products — Ensure the transition of products acquired from the supplier.','ML2','Core'],
/* ── OPF ── */
['OPF','Organizational Process Focus','OPF-SP1.1','SG1: Determine Process Improvement Opportunities','Establish Organizational Process Needs — Establish and maintain the description of the process needs and objectives for the organization.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP1.2','SG1: Determine Process Improvement Opportunities','Appraise the Organization\'s Processes — Appraise the processes of the organization periodically and as needed to maintain an understanding of their strengths and weaknesses.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP1.3','SG1: Determine Process Improvement Opportunities','Identify the Organization\'s Process Improvements — Identify improvements to the organization\'s processes and process assets.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP2.1','SG2: Plan and Implement Process Improvements','Establish Process Action Plans — Establish and maintain process action plans to address improvements to the organization\'s processes.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP2.2','SG2: Plan and Implement Process Improvements','Implement Process Action Plans — Implement process action plans across the organization.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP3.1','SG3: Deploy Organizational Process Assets and Incorporate Experiences','Deploy Organizational Process Assets — Deploy organizational process assets across the organization.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP3.2','SG3: Deploy Organizational Process Assets and Incorporate Experiences','Deploy Standard Processes — Deploy the organization\'s set of standard processes to projects at their startup.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP3.3','SG3: Deploy Organizational Process Assets and Incorporate Experiences','Monitor the Implementation — Monitor the implementation of the organization\'s standard processes and use of process assets on all projects.','ML3','Core'],
['OPF','Organizational Process Focus','OPF-SP3.4','SG3: Deploy Organizational Process Assets and Incorporate Experiences','Incorporate Experiences into Organizational Process Assets — Incorporate process-related experiences into the organizational process assets.','ML3','Core'],
/* ── OPD ── */
['OPD','Organizational Process Definition','OPD-SP1.1','SG1: Establish Organizational Process Assets','Establish Standard Processes — Establish and maintain the organization\'s set of standard processes.','ML3','Core'],
['OPD','Organizational Process Definition','OPD-SP1.2','SG1: Establish Organizational Process Assets','Establish Lifecycle Model Descriptions — Establish and maintain descriptions of lifecycle models approved for use in the organization.','ML3','Core'],
['OPD','Organizational Process Definition','OPD-SP1.3','SG1: Establish Organizational Process Assets','Establish Tailoring Criteria and Guidelines — Establish and maintain tailoring criteria and guidelines for the organization\'s set of standard processes.','ML3','Core'],
['OPD','Organizational Process Definition','OPD-SP1.4','SG1: Establish Organizational Process Assets','Establish the Organization\'s Measurement Repository — Establish and maintain the organization\'s measurement repository.','ML3','Core'],
['OPD','Organizational Process Definition','OPD-SP1.5','SG1: Establish Organizational Process Assets','Establish the Organization\'s Process Asset Library — Establish and maintain the organization\'s process asset library.','ML3','Core'],
['OPD','Organizational Process Definition','OPD-SP1.6','SG1: Establish Organizational Process Assets','Establish Work Environment Standards — Establish and maintain work environment standards.','ML3','Core'],
/* ── OT ── */
['OT','Organizational Training','OT-SP1.1','SG1: Establish an Organizational Training Capability','Establish the Strategic Training Needs — Establish and maintain the strategic training needs of the organization.','ML3','Core'],
['OT','Organizational Training','OT-SP1.2','SG1: Establish an Organizational Training Capability','Determine Which Training Needs Are the Responsibility of the Organization — Determine which training needs are the organization\'s responsibility versus the individual project or support group.','ML3','Core'],
['OT','Organizational Training','OT-SP1.3','SG1: Establish an Organizational Training Capability','Establish an Organizational Training Tactical Plan — Establish and maintain an organizational training tactical plan.','ML3','Core'],
['OT','Organizational Training','OT-SP1.4','SG1: Establish an Organizational Training Capability','Establish Training Capability — Establish and maintain a training capability to address organizational training needs.','ML3','Core'],
['OT','Organizational Training','OT-SP2.1','SG2: Provide Necessary Training','Deliver Training — Deliver the training following the organizational training tactical plan.','ML3','Core'],
['OT','Organizational Training','OT-SP2.2','SG2: Provide Necessary Training','Establish Training Records — Establish and maintain records of the organizational training.','ML3','Core'],
['OT','Organizational Training','OT-SP2.3','SG2: Provide Necessary Training','Assess Training Effectiveness — Assess the effectiveness of the organization\'s training program.','ML3','Core'],
/* ── IPM ── */
['IPM','Integrated Project Management','IPM-SP1.1','SG1: Use the Project\'s Defined Process','Establish the Project\'s Defined Process — Establish and maintain the project\'s defined process from startup through the life of the project.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP1.2','SG1: Use the Project\'s Defined Process','Use Organizational Process Assets for Planning Project Activities — Use organizational process assets and the measurement repository for estimating and planning project activities.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP1.3','SG1: Use the Project\'s Defined Process','Establish the Project\'s Work Environment — Establish and maintain the project\'s work environment based on the organization\'s work environment standards.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP1.4','SG1: Use the Project\'s Defined Process','Integrate Plans — Integrate the project plan and other plans that affect the project to describe the project\'s defined process.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP1.5','SG1: Use the Project\'s Defined Process','Manage the Project Using Integrated Plans — Manage the project using the project plan, other plans that affect the project, and the project\'s defined process.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP1.6','SG1: Use the Project\'s Defined Process','Contribute to Organizational Process Assets — Contribute work products, measures, measurement results, and documented experiences to the organizational process assets.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP2.1','SG2: Coordinate and Collaborate with Relevant Stakeholders','Manage Stakeholder Involvement — Manage the involvement of relevant stakeholders in the project.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP2.2','SG2: Coordinate and Collaborate with Relevant Stakeholders','Manage Dependencies — Participate with relevant stakeholders to identify, negotiate, and track critical dependencies.','ML3','Core'],
['IPM','Integrated Project Management','IPM-SP2.3','SG2: Coordinate and Collaborate with Relevant Stakeholders','Resolve Coordination Issues — Resolve issues with relevant stakeholders.','ML3','Core'],
/* ── RSKM ── */
['RSKM','Risk Management','RSKM-SP1.1','SG1: Prepare for Risk Management','Determine Risk Sources and Categories — Determine risk sources and categories.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP1.2','SG1: Prepare for Risk Management','Define Risk Parameters — Define the parameters used to analyze and categorize risks and to control the risk management effort.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP1.3','SG1: Prepare for Risk Management','Establish a Risk Management Strategy — Establish and maintain the strategy to be used for risk management.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP2.1','SG2: Identify and Analyze Risks','Identify Risks — Identify and document the risks.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP2.2','SG2: Identify and Analyze Risks','Evaluate, Categorize, and Prioritize Risks — Evaluate and categorize each identified risk using defined risk categories and parameters, and determine its relative priority.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP3.1','SG3: Mitigate Risks','Develop Risk Mitigation Plans — Develop a risk mitigation plan in accordance with the risk management strategy.','ML3','Core'],
['RSKM','Risk Management','RSKM-SP3.2','SG3: Mitigate Risks','Implement Risk Mitigation Plans — Monitor the status of each risk periodically and implement the risk mitigation plan as appropriate.','ML3','Core'],
/* ── DAR ── */
['DAR','Decision Analysis and Resolution','DAR-SP1.1','SG1: Evaluate Alternatives','Establish Guidelines for Decision Analysis — Establish and maintain guidelines to determine which issues are subject to a formal evaluation process.','ML3','Core'],
['DAR','Decision Analysis and Resolution','DAR-SP1.2','SG1: Evaluate Alternatives','Establish Evaluation Criteria — Establish and maintain the criteria for evaluating alternatives and the relative ranking of these criteria.','ML3','Core'],
['DAR','Decision Analysis and Resolution','DAR-SP1.3','SG1: Evaluate Alternatives','Identify Alternative Solutions — Identify alternative solutions to address decision issues.','ML3','Core'],
['DAR','Decision Analysis and Resolution','DAR-SP1.4','SG1: Evaluate Alternatives','Select Evaluation Methods — Select evaluation methods based on the established evaluation criteria and the type of decision under analysis.','ML3','Core'],
['DAR','Decision Analysis and Resolution','DAR-SP1.5','SG1: Evaluate Alternatives','Evaluate Alternative Solutions — Evaluate alternative solutions using the established criteria and methods.','ML3','Core'],
['DAR','Decision Analysis and Resolution','DAR-SP1.6','SG1: Evaluate Alternatives','Select Solutions — Select solutions from the alternatives based on the evaluation criteria.','ML3','Core'],
/* ── RD (Development-Specific) ── */
['RD','Requirements Development','RD-SP1.1','SG1: Develop Customer Requirements','Elicit Needs — Elicit stakeholder needs, expectations, constraints, and interfaces for all phases of the product lifecycle.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP1.2','SG1: Develop Customer Requirements','Develop the Customer Requirements — Transform stakeholder needs, expectations, constraints, and interfaces into customer requirements.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP2.1','SG2: Develop Product Requirements','Establish Product and Product-Component Requirements — Establish and maintain product and product-component requirements based on the customer requirements.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP2.2','SG2: Develop Product Requirements','Allocate Product-Component Requirements — Allocate the requirements for each product component.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP2.3','SG2: Develop Product Requirements','Identify Interface Requirements — Identify interface requirements.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP3.1','SG3: Analyze and Validate Requirements','Establish Operational Concepts and Scenarios — Establish and maintain operational concepts and associated scenarios.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP3.2','SG3: Analyze and Validate Requirements','Establish a Definition of Required Functionality and Quality Attributes — Establish and maintain a definition of required functionality and quality attributes.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP3.3','SG3: Analyze and Validate Requirements','Analyze Requirements — Analyze requirements to ensure that they are necessary and sufficient.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP3.4','SG3: Analyze and Validate Requirements','Analyze Requirements to Achieve Balance — Analyze requirements to balance stakeholder needs and constraints.','ML3','Development-Specific'],
['RD','Requirements Development','RD-SP3.5','SG3: Analyze and Validate Requirements','Validate Requirements — Validate requirements to ensure the resulting product will perform as intended in the end-user environment.','ML3','Development-Specific'],
/* ── TS (Development-Specific) ── */
['TS','Technical Solution','TS-SP1.1','SG1: Select Product-Component Solutions','Develop Alternative Solutions and Selection Criteria — Develop alternative solutions and selection criteria.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP1.2','SG1: Select Product-Component Solutions','Select Product-Component Solutions — Select the product-component solutions based on selection criteria.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP2.1','SG2: Develop the Design','Design the Product or Product Component — Develop a design for the product or product component.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP2.2','SG2: Develop the Design','Establish a Technical Data Package — Establish and maintain a technical data package.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP2.3','SG2: Develop the Design','Design Interfaces Using Criteria — Design product component interfaces using established criteria.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP2.4','SG2: Develop the Design','Perform Make, Buy, or Reuse Analyses — Evaluate whether to develop, procure, or reuse product components based on established criteria.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP3.1','SG3: Implement the Product Design','Implement the Design — Implement the designs of the product components.','ML3','Development-Specific'],
['TS','Technical Solution','TS-SP3.2','SG3: Implement the Product Design','Develop Product Support Documentation — Develop and maintain end-use documentation.','ML3','Development-Specific'],
/* ── PI (Development-Specific) ── */
['PI','Product Integration','PI-SP1.1','SG1: Prepare for Product Integration','Determine Integration Sequence — Determine the product-component integration sequence.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP1.2','SG1: Prepare for Product Integration','Establish the Product Integration Environment — Establish and maintain the environment needed to support integration of the product components.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP1.3','SG1: Prepare for Product Integration','Establish Product Integration Procedures and Criteria — Establish and maintain procedures and criteria for integration of the product components.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP2.1','SG2: Ensure Interface Compatibility','Review Interface Descriptions for Completeness — Review interface descriptions for coverage and completeness.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP2.2','SG2: Ensure Interface Compatibility','Manage Interfaces — Manage internal and external interface definitions, designs, and changes for products and product components.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP3.1','SG3: Assemble Product Components and Deliver the Product','Confirm Readiness of Product Components for Integration — Confirm that each product component has been properly identified, functions according to its description, and complies with interface descriptions.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP3.2','SG3: Assemble Product Components and Deliver the Product','Assemble Product Components — Assemble product components according to the product integration sequence and available procedures.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP3.3','SG3: Assemble Product Components and Deliver the Product','Evaluate Assembled Product Components — Evaluate assembled product components for interface compatibility.','ML3','Development-Specific'],
['PI','Product Integration','PI-SP3.4','SG3: Assemble Product Components and Deliver the Product','Package and Deliver the Product or Product Component — Package the assembled product or product component and deliver it to the appropriate customer.','ML3','Development-Specific'],
/* ── VER (Development-Specific) ── */
['VER','Verification','VER-SP1.1','SG1: Prepare for Verification','Select Work Products for Verification — Select the work products to be verified and the verification methods that will be used.','ML3','Development-Specific'],
['VER','Verification','VER-SP1.2','SG1: Prepare for Verification','Establish the Verification Environment — Establish and maintain the environment needed to support verification.','ML3','Development-Specific'],
['VER','Verification','VER-SP1.3','SG1: Prepare for Verification','Establish Verification Procedures and Criteria — Establish and maintain verification procedures and criteria for the selected work products.','ML3','Development-Specific'],
['VER','Verification','VER-SP2.1','SG2: Perform Peer Reviews','Prepare for Peer Reviews — Prepare for peer reviews of selected work products.','ML3','Development-Specific'],
['VER','Verification','VER-SP2.2','SG2: Perform Peer Reviews','Conduct Peer Reviews — Conduct peer reviews on selected work products and identify issues resulting from the peer review.','ML3','Development-Specific'],
['VER','Verification','VER-SP2.3','SG2: Perform Peer Reviews','Analyze Peer Review Data — Analyze data about the preparation, conduct, and results of the peer reviews.','ML3','Development-Specific'],
['VER','Verification','VER-SP3.1','SG3: Verify Selected Work Products','Perform Verification — Perform verification on the selected work products.','ML3','Development-Specific'],
['VER','Verification','VER-SP3.2','SG3: Verify Selected Work Products','Analyze Verification Results — Analyze the results of all verification activities.','ML3','Development-Specific'],
/* ── VAL (Development-Specific) ── */
['VAL','Validation','VAL-SP1.1','SG1: Prepare for Validation','Select Products for Validation — Select products and product components to be validated and the validation methods that will be used.','ML3','Development-Specific'],
['VAL','Validation','VAL-SP1.2','SG1: Prepare for Validation','Establish the Validation Environment — Establish and maintain the environment needed to support validation.','ML3','Development-Specific'],
['VAL','Validation','VAL-SP1.3','SG1: Prepare for Validation','Establish Validation Procedures and Criteria — Establish and maintain procedures and criteria for validation.','ML3','Development-Specific'],
['VAL','Validation','VAL-SP2.1','SG2: Validate Product or Product Components','Perform Validation — Perform validation on the selected products and product components.','ML3','Development-Specific'],
['VAL','Validation','VAL-SP2.2','SG2: Validate Product or Product Components','Analyze Validation Results — Analyze the results of the validation activities.','ML3','Development-Specific'],
];

/* Generic Practices — apply to ALL process areas */
var GP_DATA = [
  ['GP1.1','Perform Specific Practices — Perform the specific practices of the process area to develop work products and provide services to achieve the specific goals.','ML2+ML3'],
  ['GP2.1','Establish an Organizational Policy — Establish and maintain an organizational policy for planning and performing the process.','ML2+ML3'],
  ['GP2.2','Plan the Process — Establish and maintain the plan for performing the process.','ML2+ML3'],
  ['GP2.3','Provide Resources — Provide adequate resources for performing the process, developing the work products, and providing the services of the process.','ML2+ML3'],
  ['GP2.4','Assign Responsibility — Assign responsibility and authority for performing the process, developing the work products, and providing the services of the process.','ML2+ML3'],
  ['GP2.5','Train People — Train the people performing or supporting the process as needed.','ML2+ML3'],
  ['GP2.6','Manage Configurations — Place designated work products of the process under appropriate levels of configuration management.','ML2+ML3'],
  ['GP2.7','Identify and Involve Relevant Stakeholders — Identify and involve the relevant stakeholders of the process as planned.','ML2+ML3'],
  ['GP2.8','Monitor and Control the Process — Monitor and control the process against the plan and take appropriate corrective action.','ML2+ML3'],
  ['GP2.9','Objectively Evaluate Adherence — Objectively evaluate adherence of the process and selected work products against the process description, standards, and procedures, and address noncompliance.','ML2+ML3'],
  ['GP2.10','Review Status with Higher Level Management — Review the activities, status, and results of the process with higher-level management and resolve issues.','ML2+ML3'],
  ['GP3.1','Establish a Defined Process — Establish and maintain the description of a defined process. (Requires organizational standard processes — ML3 only)','ML3'],
  ['GP3.2','Collect Improvement Information — Collect work products, measures, measurement results, and improvement information to support future use and improvement of the organization\'s processes and process assets. (ML3 only)','ML3'],
];

/* ── Table rendering ── */
function escH(s) {
  return String(s).replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

function renderTable(data) {
  var tbody = document.getElementById('controls-tbody');
  var count = document.getElementById('ctrl-count');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">No controls match the current filters.</td></tr>';
    if (count) count.textContent = '0 controls';
    return;
  }
  tbody.innerHTML = data.map(function(d) {
    var lvlClass = d[5] === 'ML2' ? 'level-badge-ml2' : 'level-badge-ml3';
    var catClass = d[6] === 'Core' ? 'cat-badge-core' : 'cat-badge-dev';
    var desc = d[4];
    var dashIdx = desc.indexOf(' — ');
    var title = dashIdx > -1 ? '<strong>' + escH(desc.slice(0, dashIdx)) + '</strong><br><span class="text-secondary">' + escH(desc.slice(dashIdx + 3)) + '</span>' : escH(desc);
    return '<tr class="ctrl-row">'
      + '<td><span class="pa-badge">' + escH(d[0]) + '</span></td>'
      + '<td><code style="font-size:.8rem">' + escH(d[2]) + '</code><br><span class="sg-text">' + escH(d[3]) + '</span></td>'
      + '<td class="sg-text">' + escH(d[3]) + '</td>'
      + '<td>' + title + '</td>'
      + '<td><span class="badge-pill ' + lvlClass + '">' + escH(d[5]) + '</span></td>'
      + '<td><span class="badge-pill ' + catClass + '">' + escH(d[6]) + '</span></td>'
      + '</tr>';
  }).join('');
  if (count) count.textContent = 'Showing ' + data.length + ' of ' + CMMI_DATA.length + ' controls';
}

/* ── Filtering ── */
function applyFilters() {
  var q   = (document.getElementById('ctrl-search').value || '').toLowerCase().trim();
  var pa  = document.getElementById('ctrl-pa').value;
  var lv  = document.getElementById('ctrl-level').value;
  var cat = document.getElementById('ctrl-cat').value;
  var filtered = CMMI_DATA.filter(function(d) {
    if (pa  && d[0] !== pa)  return false;
    if (lv  && d[5] !== lv)  return false;
    if (cat && d[6] !== cat) return false;
    if (q) {
      var hay = (d[0]+' '+d[1]+' '+d[2]+' '+d[3]+' '+d[4]).toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });
  renderTable(filtered);
}

/* ── Populate PA dropdown ── */
function populatePADropdown() {
  var sel = document.getElementById('ctrl-pa');
  if (!sel) return;
  var seen = {};
  CMMI_DATA.forEach(function(d) {
    if (!seen[d[0]]) { seen[d[0]] = d[1]; }
  });
  Object.keys(seen).forEach(function(pa) {
    var opt = document.createElement('option');
    opt.value = pa;
    opt.textContent = pa + ' — ' + seen[pa];
    sel.appendChild(opt);
  });
}

/* ── Excel export ── */
function makeSheet(rows, headers) {
  var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
  ws['!cols'] = [{wch:8},{wch:36},{wch:16},{wch:44},{wch:85},{wch:14},{wch:22},{wch:20},{wch:40}];
  ws['!views'] = [{state:'frozen', ySplit:1}];
  return ws;
}

function downloadExcel() {
  if (typeof XLSX === 'undefined') {
    alert('Excel library still loading — please try again in a moment.');
    return;
  }
  var hdrs = ['Process Area','PA Full Name','Control Number','Specific Goal','Control Description','Maturity Level','Category','Compliance Status','Notes'];
  var toRows = function(arr) {
    return arr.map(function(d) { return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],'Not Started','']; });
  };
  var wb = XLSX.utils.book_new();

  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA), hdrs), 'All Controls');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[5]==='ML2';})), hdrs), 'ML2 Process Areas');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[5]==='ML3';})), hdrs), 'ML3 Process Areas');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[6]==='Core';})), hdrs), 'Core Controls');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[6]==='Development-Specific';})), hdrs), 'Development-Specific');

  var gpHdrs = ['Practice #','Description','Applies To'];
  var gpWs = XLSX.utils.aoa_to_sheet([gpHdrs].concat(GP_DATA));
  gpWs['!cols'] = [{wch:10},{wch:110},{wch:12}];
  gpWs['!views'] = [{state:'frozen', ySplit:1}];
  XLSX.utils.book_append_sheet(wb, gpWs, 'Generic Practices');

  var fname = 'CMMI-DEV-Level3-' + new Date().toISOString().split('T')[0] + '.xlsx';
  XLSX.writeFile(wb, fname);
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', function() {
  populatePADropdown();
  renderTable(CMMI_DATA);

  ['ctrl-search','ctrl-pa','ctrl-level','ctrl-cat'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', applyFilters);
  });

  document.getElementById('ctrl-reset').addEventListener('click', function() {
    document.getElementById('ctrl-search').value = '';
    document.getElementById('ctrl-pa').value = '';
    document.getElementById('ctrl-level').value = '';
    document.getElementById('ctrl-cat').value = '';
    renderTable(CMMI_DATA);
  });

  ['download-btn','download-btn2'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', downloadExcel);
  });
});
