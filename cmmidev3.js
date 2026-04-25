/* cmmidev3.js — CMMI v2.0 for Development, Maturity Level 3 */
/* Columns: [pa, paFull, practiceNum, practiceGroup, description, level, category] */
var CMMI_DATA = [

/* ── PLAN — Planning (ML2, Core) ── */
['PLAN','Planning','PLAN 1.1','PG 1 – Establish Context','Establish a shared vision, objectives, and high-level scope for the project','ML2','Core'],
['PLAN','Planning','PLAN 1.2','PG 1 – Establish Context','Define and maintain the work breakdown structure (WBS) to organize and estimate the scope of work','ML2','Core'],
['PLAN','Planning','PLAN 2.1','PG 2 – Develop the Plan','Develop and maintain the project schedule including milestones, dependencies, and critical path','ML2','Core'],
['PLAN','Planning','PLAN 2.2','PG 2 – Develop the Plan','Identify project dependencies and constraints and address them in the plan','ML2','Core'],
['PLAN','Planning','PLAN 2.3','PG 2 – Develop the Plan','Plan stakeholder involvement and communication for the duration of the project','ML2','Core'],
['PLAN','Planning','PLAN 2.4','PG 2 – Develop the Plan','Plan for the management of project data and knowledge artifacts','ML2','Core'],
['PLAN','Planning','PLAN 2.5','PG 2 – Develop the Plan','Plan for skills, knowledge, and resources needed to execute the project','ML2','Core'],
['PLAN','Planning','PLAN 2.6','PG 2 – Develop the Plan','Plan for the project work environment including tools, facilities, and infrastructure','ML2','Core'],
['PLAN','Planning','PLAN 3.1','PG 3 – Obtain Commitment','Review all plans that affect the project with relevant stakeholders','ML2','Core'],
['PLAN','Planning','PLAN 3.2','PG 3 – Obtain Commitment','Reconcile the project plan to reflect available and estimated resources','ML2','Core'],
['PLAN','Planning','PLAN 3.3','PG 3 – Obtain Commitment','Obtain commitment from stakeholders responsible for executing and supporting the plan','ML2','Core'],

/* ── EST — Estimating (ML2, Core) ── */
['EST','Estimating','EST 1.1','PG 1 – Prepare for Estimating','Establish and maintain estimating parameters, measures, and methods to be used','ML2','Core'],
['EST','Estimating','EST 1.2','PG 1 – Prepare for Estimating','Select and document estimating methods and techniques appropriate for the work','ML2','Core'],
['EST','Estimating','EST 2.1','PG 2 – Estimate the Work','Estimate work product and task sizes using defined measures','ML2','Core'],
['EST','Estimating','EST 2.2','PG 2 – Estimate the Work','Estimate effort and duration using established estimating parameters and models','ML2','Core'],
['EST','Estimating','EST 2.3','PG 2 – Estimate the Work','Estimate project costs based on estimated effort, duration, and resource requirements','ML2','Core'],
['EST','Estimating','EST 3.1','PG 3 – Validate Estimates','Review estimates for reasonableness using historical data and expert judgment','ML2','Core'],
['EST','Estimating','EST 3.2','PG 3 – Validate Estimates','Document rationale for estimation assumptions and record actuals for future calibration','ML2','Core'],

/* ── MC — Monitor and Control (ML2, Core) ── */
['MC','Monitor and Control','MC 1.1','PG 1 – Monitor the Work','Monitor actual performance and progress against the project plan','ML2','Core'],
['MC','Monitor and Control','MC 1.2','PG 1 – Monitor the Work','Monitor the involvement of relevant stakeholders against plan commitments','ML2','Core'],
['MC','Monitor and Control','MC 2.1','PG 2 – Analyze and Address Issues','Identify and analyze issues in performance and progress against the plan','ML2','Core'],
['MC','Monitor and Control','MC 2.2','PG 2 – Analyze and Address Issues','Take corrective actions to address identified performance and schedule issues','ML2','Core'],
['MC','Monitor and Control','MC 2.3','PG 2 – Analyze and Address Issues','Manage corrective actions to closure and verify their effectiveness','ML2','Core'],
['MC','Monitor and Control','MC 3.1','PG 3 – Review Status','Review project status with higher-level management as appropriate','ML2','Core'],
['MC','Monitor and Control','MC 3.2','PG 3 – Review Status','Analyze performance data to identify trends and forecast future performance','ML2','Core'],

/* ── CM — Configuration Management (ML2, Core) ── */
['CM','Configuration Management','CM 1.1','PG 1 – Establish Baselines','Identify configuration items and work products to be placed under configuration management','ML2','Core'],
['CM','Configuration Management','CM 1.2','PG 1 – Establish Baselines','Establish and maintain a configuration management system and change management system','ML2','Core'],
['CM','Configuration Management','CM 1.3','PG 1 – Establish Baselines','Create or release baselines for internal use and for delivery to the customer','ML2','Core'],
['CM','Configuration Management','CM 2.1','PG 2 – Track and Control Changes','Track change requests for configuration items from origination to disposition','ML2','Core'],
['CM','Configuration Management','CM 2.2','PG 2 – Track and Control Changes','Control changes to configuration items using an approved change request process','ML2','Core'],
['CM','Configuration Management','CM 2.3','PG 2 – Track and Control Changes','Maintain records describing configuration items and all changes made to them','ML2','Core'],
['CM','Configuration Management','CM 3.1','PG 3 – Establish Integrity','Establish and maintain the integrity of configuration baselines','ML2','Core'],
['CM','Configuration Management','CM 3.2','PG 3 – Establish Integrity','Perform configuration audits to confirm baselines and documentation are accurate and complete','ML2','Core'],

/* ── PPQA — Process Quality Assurance (ML2, Core) ── */
['PPQA','Process Quality Assurance','PPQA 1.1','PG 1 – Evaluate Processes and Products','Objectively evaluate selected performed processes against applicable process descriptions and standards','ML2','Core'],
['PPQA','Process Quality Assurance','PPQA 1.2','PG 1 – Evaluate Processes and Products','Objectively evaluate selected work products and services against applicable standards and requirements','ML2','Core'],
['PPQA','Process Quality Assurance','PPQA 2.1','PG 2 – Provide Objective Insight','Communicate noncompliance issues to staff and management and ensure resolution to closure','ML2','Core'],
['PPQA','Process Quality Assurance','PPQA 2.2','PG 2 – Provide Objective Insight','Establish and maintain records of quality assurance activities and their results','ML2','Core'],
['PPQA','Process Quality Assurance','PPQA 3.1','PG 3 – Manage Quality Assurance','Establish and maintain a process quality assurance approach aligned to organizational standards','ML2','Core'],

/* ── RM — Risk and Opportunity Management (ML2, Core) ── */
['RM','Risk and Opportunity Management','RM 1.1','PG 1 – Identify Risks and Opportunities','Identify and document risks and opportunities using a defined identification approach','ML2','Core'],
['RM','Risk and Opportunity Management','RM 1.2','PG 1 – Identify Risks and Opportunities','Evaluate and categorize risks and opportunities using defined parameters and thresholds','ML2','Core'],
['RM','Risk and Opportunity Management','RM 2.1','PG 2 – Plan Mitigation','Develop risk and opportunity mitigation options, contingency plans, and triggers','ML2','Core'],
['RM','Risk and Opportunity Management','RM 2.2','PG 2 – Plan Mitigation','Prioritize risks for mitigation based on defined criteria and probability/impact assessment','ML2','Core'],
['RM','Risk and Opportunity Management','RM 2.3','PG 2 – Plan Mitigation','Develop and maintain risk and opportunity mitigation plans for priority items','ML2','Core'],
['RM','Risk and Opportunity Management','RM 3.1','PG 3 – Implement Mitigation','Implement risk mitigation plans and monitor for changes in risk status','ML2','Core'],
['RM','Risk and Opportunity Management','RM 3.2','PG 3 – Implement Mitigation','Adjust risk and opportunity mitigation strategies based on monitoring results and triggers','ML2','Core'],

/* ── DAR — Decision Analysis and Resolution (ML2, Core) ── */
['DAR','Decision Analysis and Resolution','DAR 1.1','PG 1 – Prepare for Decision Analysis','Establish and maintain guidelines defining when to apply formal evaluation processes','ML2','Core'],
['DAR','Decision Analysis and Resolution','DAR 1.2','PG 1 – Prepare for Decision Analysis','Establish and maintain evaluation criteria and their relative importance for each decision','ML2','Core'],
['DAR','Decision Analysis and Resolution','DAR 2.1','PG 2 – Analyze Alternatives','Identify alternative solutions to address the issue or decision being analyzed','ML2','Core'],
['DAR','Decision Analysis and Resolution','DAR 2.2','PG 2 – Analyze Alternatives','Select and document evaluation methods and techniques to assess identified alternatives','ML2','Core'],
['DAR','Decision Analysis and Resolution','DAR 3.1','PG 3 – Make and Communicate Decisions','Evaluate alternatives using established criteria and selected evaluation methods','ML2','Core'],
['DAR','Decision Analysis and Resolution','DAR 3.2','PG 3 – Make and Communicate Decisions','Select solutions based on evaluation results and communicate decisions to relevant stakeholders','ML2','Core'],

/* ── SPM — Supplier Performance Management (ML2, Core) ── */
['SPM','Supplier Performance Management','SPM 1.1','PG 1 – Establish Supplier Agreements','Determine acquisition type and select suppliers using defined evaluation criteria','ML2','Core'],
['SPM','Supplier Performance Management','SPM 1.2','PG 1 – Establish Supplier Agreements','Establish and maintain formal supplier agreements specifying requirements and acceptance criteria','ML2','Core'],
['SPM','Supplier Performance Management','SPM 2.1','PG 2 – Satisfy Supplier Agreements','Monitor selected supplier processes and work products for compliance with agreements','ML2','Core'],
['SPM','Supplier Performance Management','SPM 2.2','PG 2 – Satisfy Supplier Agreements','Evaluate selected supplier work products for acceptability using defined criteria','ML2','Core'],
['SPM','Supplier Performance Management','SPM 3.1','PG 3 – Manage Supplier Performance','Analyze and communicate supplier performance data to relevant stakeholders','ML2','Core'],
['SPM','Supplier Performance Management','SPM 3.2','PG 3 – Manage Supplier Performance','Identify and resolve supplier performance shortfalls through corrective action','ML2','Core'],

/* ── RDM — Requirements Development and Management (ML2, Development-Specific) ── */
['RDM','Requirements Development and Management','RDM 1.1','PG 1 – Develop Requirements','Elicit stakeholder needs, expectations, constraints, and operational concepts','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 1.2','PG 1 – Develop Requirements','Establish and maintain customer requirements derived from elicited stakeholder needs','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 2.1','PG 2 – Analyze and Validate Requirements','Establish product and product-component requirements aligned to customer requirements','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 2.2','PG 2 – Analyze and Validate Requirements','Allocate requirements to product components and ensure bidirectional traceability','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 2.3','PG 2 – Analyze and Validate Requirements','Identify and document interface requirements between product components','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 3.1','PG 3 – Manage Requirements','Obtain commitment to requirements from project participants','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 3.2','PG 3 – Manage Requirements','Manage requirements changes and document rationale and impact of each change','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 3.3','PG 3 – Manage Requirements','Ensure alignment between project work products, plans, and requirements','ML2','Development-Specific'],
['RDM','Requirements Development and Management','RDM 3.4','PG 3 – Manage Requirements','Identify and correct inconsistencies between project work and requirements','ML2','Development-Specific'],

/* ── GOV — Governance (ML3, Core) ── */
['GOV','Governance','GOV 1.1','PG 1 – Define Expectations','Define and communicate expected organizational behaviors for performing work and processes','ML3','Core'],
['GOV','Governance','GOV 1.2','PG 1 – Define Expectations','Ensure managers and staff have needed skills and understand expected performance behaviors','ML3','Core'],
['GOV','Governance','GOV 2.1','PG 2 – Manage Accountability','Align responsibilities with authority and accountability for process and business performance','ML3','Core'],
['GOV','Governance','GOV 2.2','PG 2 – Manage Accountability','Monitor organizational and project performance against established policies and standards','ML3','Core'],
['GOV','Governance','GOV 3.1','PG 3 – Establish Governance Infrastructure','Establish and maintain organizational policies, standards, and rules governing process performance','ML3','Core'],
['GOV','Governance','GOV 3.2','PG 3 – Establish Governance Infrastructure','Review and adjust governance mechanisms based on performance data and lessons learned','ML3','Core'],
['GOV','Governance','GOV 3.3','PG 3 – Establish Governance Infrastructure','Establish mechanisms to sustain and manage process performance improvements organization-wide','ML3','Core'],

/* ── II — Implementation Infrastructure (ML3, Core) ── */
['II','Implementation Infrastructure','II 1.1','PG 1 – Identify Infrastructure Needs','Identify resources, tools, methods, and environments needed to implement and support processes','ML3','Core'],
['II','Implementation Infrastructure','II 2.1','PG 2 – Provide Infrastructure','Establish and maintain infrastructure (tools, environments, templates) to support work performance','ML3','Core'],
['II','Implementation Infrastructure','II 2.2','PG 2 – Provide Infrastructure','Make infrastructure available to staff and provide access, training, and support as needed','ML3','Core'],
['II','Implementation Infrastructure','II 3.1','PG 3 – Improve Infrastructure','Evaluate implementation infrastructure effectiveness using performance data and feedback','ML3','Core'],
['II','Implementation Infrastructure','II 3.2','PG 3 – Improve Infrastructure','Improve implementation infrastructure based on evaluation results and identified gaps','ML3','Core'],

/* ── OPD — Organizational Process Definition (ML3, Core) ── */
['OPD','Organizational Process Definition','OPD 1.1','PG 1 – Establish Process Assets','Establish and maintain the organization\'s set of standard processes','ML3','Core'],
['OPD','Organizational Process Definition','OPD 1.2','PG 1 – Establish Process Assets','Establish and maintain lifecycle model descriptions for use across the organization','ML3','Core'],
['OPD','Organizational Process Definition','OPD 1.3','PG 1 – Establish Process Assets','Establish and maintain criteria and guidelines for tailoring standard processes to projects','ML3','Core'],
['OPD','Organizational Process Definition','OPD 2.1','PG 2 – Establish Measurement Assets','Establish and maintain the organization\'s measurement repository for collecting and sharing data','ML3','Core'],
['OPD','Organizational Process Definition','OPD 2.2','PG 2 – Establish Measurement Assets','Establish and maintain the organization\'s process library for sharing process assets','ML3','Core'],
['OPD','Organizational Process Definition','OPD 3.1','PG 3 – Enable IPPD','Establish and maintain rules and guidelines for structuring and forming integrated project teams','ML3','Core'],

/* ── OPF — Organizational Process Focus (ML3, Core) ── */
['OPF','Organizational Process Focus','OPF 1.1','PG 1 – Determine Improvement Opportunities','Establish and maintain the process performance needs and objectives of the organization','ML3','Core'],
['OPF','Organizational Process Focus','OPF 1.2','PG 1 – Determine Improvement Opportunities','Appraise organizational processes periodically using defined appraisal methods','ML3','Core'],
['OPF','Organizational Process Focus','OPF 2.1','PG 2 – Plan and Implement Improvements','Establish and maintain process action plans to address identified improvement opportunities','ML3','Core'],
['OPF','Organizational Process Focus','OPF 2.2','PG 2 – Plan and Implement Improvements','Deploy organizational process assets and changes to projects and organizational entities','ML3','Core'],
['OPF','Organizational Process Focus','OPF 3.1','PG 3 – Sustain Improvements','Incorporate process improvement experiences and lessons learned into organizational process assets','ML3','Core'],
['OPF','Organizational Process Focus','OPF 3.2','PG 3 – Sustain Improvements','Measure the effectiveness of process improvements deployed and adjust strategy accordingly','ML3','Core'],

/* ── OT — Organizational Training (ML3, Core) ── */
['OT','Organizational Training','OT 1.1','PG 1 – Establish Training Capability','Establish and maintain the organization\'s strategic training needs and training plans','ML3','Core'],
['OT','Organizational Training','OT 1.2','PG 1 – Establish Training Capability','Determine which training needs are the organization\'s responsibility to provide','ML3','Core'],
['OT','Organizational Training','OT 2.1','PG 2 – Provide Training','Deliver training following the organization\'s training plans and schedule','ML3','Core'],
['OT','Organizational Training','OT 2.2','PG 2 – Provide Training','Establish and maintain records of organizational training activities and completion','ML3','Core'],
['OT','Organizational Training','OT 3.1','PG 3 – Evaluate Training Effectiveness','Assess the effectiveness of training on capability and organizational performance outcomes','ML3','Core'],

/* ── IPM — Integrated Project Management (ML3, Core) ── */
['IPM','Integrated Project Management','IPM 1.1','PG 1 – Use Organizational Process Assets','Establish the project\'s defined process using the organization\'s set of standard processes','ML3','Core'],
['IPM','Integrated Project Management','IPM 1.2','PG 1 – Use Organizational Process Assets','Use organizational process assets and measurement repository data for planning and managing','ML3','Core'],
['IPM','Integrated Project Management','IPM 2.1','PG 2 – Coordinate and Collaborate','Participate in the organization\'s process improvement activities and contribute project experience','ML3','Core'],
['IPM','Integrated Project Management','IPM 2.2','PG 2 – Coordinate and Collaborate','Establish and maintain teams and team processes to coordinate work across the project','ML3','Core'],
['IPM','Integrated Project Management','IPM 3.1','PG 3 – Apply IPPD Principles','Establish and maintain the project\'s shared vision aligned to customer and organizational objectives','ML3','Core'],
['IPM','Integrated Project Management','IPM 3.2','PG 3 – Apply IPPD Principles','Establish integrated teams for developing, integrating, and maintaining products and processes','ML3','Core'],
['IPM','Integrated Project Management','IPM 3.3','PG 3 – Apply IPPD Principles','Ensure collaboration and information sharing among interfacing teams','ML3','Core'],

/* ── TS — Technical Solution (ML3, Development-Specific) ── */
['TS','Technical Solution','TS 1.1','PG 1 – Select Technical Solutions','Identify and analyze alternative solutions and selection criteria to address technical requirements','ML3','Development-Specific'],
['TS','Technical Solution','TS 1.2','PG 1 – Select Technical Solutions','Select product-component solutions and document selection rationale','ML3','Development-Specific'],
['TS','Technical Solution','TS 2.1','PG 2 – Develop the Design','Develop and maintain a detailed design for the product and product components','ML3','Development-Specific'],
['TS','Technical Solution','TS 2.2','PG 2 – Develop the Design','Establish and maintain the technical data package for each product component','ML3','Development-Specific'],
['TS','Technical Solution','TS 2.3','PG 2 – Develop the Design','Design interfaces using criteria derived from requirements and the product architecture','ML3','Development-Specific'],
['TS','Technical Solution','TS 3.1','PG 3 – Implement the Design','Implement product-component designs using appropriate methods and tools','ML3','Development-Specific'],
['TS','Technical Solution','TS 3.2','PG 3 – Implement the Design','Develop and maintain product and product-component documentation','ML3','Development-Specific'],

/* ── PI — Product Integration (ML3, Development-Specific) ── */
['PI','Product Integration','PI 1.1','PG 1 – Prepare for Integration','Establish and maintain an integration strategy covering sequence and approach for product components','ML3','Development-Specific'],
['PI','Product Integration','PI 1.2','PG 1 – Prepare for Integration','Establish and maintain the product integration environment needed to support integration activities','ML3','Development-Specific'],
['PI','Product Integration','PI 2.1','PG 2 – Ensure Interface Compatibility','Review interface descriptions for completeness and compatibility across product components','ML3','Development-Specific'],
['PI','Product Integration','PI 2.2','PG 2 – Ensure Interface Compatibility','Manage internal and external interfaces of product components throughout integration','ML3','Development-Specific'],
['PI','Product Integration','PI 3.1','PG 3 – Assemble and Deliver','Confirm that product components are ready for integration using defined completion criteria','ML3','Development-Specific'],
['PI','Product Integration','PI 3.2','PG 3 – Assemble and Deliver','Assemble product components according to the integration strategy and sequence','ML3','Development-Specific'],
['PI','Product Integration','PI 3.3','PG 3 – Assemble and Deliver','Package and deliver the product or product components to the customer','ML3','Development-Specific'],

/* ── VV — Verification and Validation (ML3, Development-Specific) ── */
['VV','Verification and Validation','VV 1.1','PG 1 – Prepare for V&V','Select work products and product components to be verified and validated','ML3','Development-Specific'],
['VV','Verification and Validation','VV 1.2','PG 1 – Prepare for V&V','Establish and maintain verification and validation procedures, criteria, and environments','ML3','Development-Specific'],
['VV','Verification and Validation','VV 2.1','PG 2 – Perform Verification','Perform verification activities on selected work products against established criteria','ML3','Development-Specific'],
['VV','Verification and Validation','VV 2.2','PG 2 – Perform Verification','Identify and document defects and corrective actions resulting from verification activities','ML3','Development-Specific'],
['VV','Verification and Validation','VV 3.1','PG 3 – Perform Validation','Perform validation on products and product components in representative environments','ML3','Development-Specific'],
['VV','Verification and Validation','VV 3.2','PG 3 – Perform Validation','Analyze results of verification and validation activities and take corrective action as needed','ML3','Development-Specific'],

/* ── PR — Peer Reviews (ML3, Development-Specific) ── */
['PR','Peer Reviews','PR 1.1','PG 1 – Prepare for Peer Reviews','Establish and maintain peer review plans covering selected work products and review objectives','ML3','Development-Specific'],
['PR','Peer Reviews','PR 1.2','PG 1 – Prepare for Peer Reviews','Establish and maintain peer review procedures and entry and exit criteria','ML3','Development-Specific'],
['PR','Peer Reviews','PR 2.1','PG 2 – Conduct Peer Reviews','Perform peer reviews on selected work products using defined procedures and criteria','ML3','Development-Specific'],
['PR','Peer Reviews','PR 2.2','PG 2 – Conduct Peer Reviews','Analyze peer review defect data to identify root causes and process improvement opportunities','ML3','Development-Specific'],
];


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
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">No practices match the current filters.</td></tr>';
    if (count) count.textContent = '0 practices';
    return;
  }
  tbody.innerHTML = data.map(function(d) {
    var lvlClass = d[5] === 'ML2' ? 'level-badge-ml2' : 'level-badge-ml3';
    var catClass = d[6] === 'Core' ? 'cat-badge-core' : 'cat-badge-dev';
    return '<tr class="ctrl-row">'
      + '<td><span class="pa-badge">' + escH(d[0]) + '</span></td>'
      + '<td><code style="font-size:.8rem">' + escH(d[2]) + '</code><br><span class="sg-text">' + escH(d[3]) + '</span></td>'
      + '<td class="sg-text">' + escH(d[3]) + '</td>'
      + '<td>' + escH(d[4]) + '</td>'
      + '<td><span class="badge-pill ' + lvlClass + '">' + escH(d[5]) + '</span></td>'
      + '<td><span class="badge-pill ' + catClass + '">' + escH(d[6]) + '</span></td>'
      + '</tr>';
  }).join('');
  if (count) count.textContent = 'Showing ' + data.length + ' of ' + CMMI_DATA.length + ' practices';
}

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

function makeSheet(rows, headers) {
  var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
  ws['!cols'] = [{wch:8},{wch:40},{wch:14},{wch:38},{wch:90},{wch:8},{wch:24},{wch:18},{wch:40}];
  ws['!views'] = [{state:'frozen', ySplit:1}];
  return ws;
}

function downloadExcel() {
  if (typeof XLSX === 'undefined') {
    alert('Excel library still loading — please try again in a moment.');
    return;
  }
  var hdrs = ['Practice Area','PA Full Name','Practice Number','Practice Group','Practice Description','Maturity Level','Category','Compliance Status','Notes'];
  var toRows = function(arr) {
    return arr.map(function(d) { return [d[0],d[1],d[2],d[3],d[4],d[5],d[6],'Not Started','']; });
  };
  var wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA), hdrs), 'All Practices');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[5]==='ML2';})), hdrs), 'ML2 Practice Areas');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[5]==='ML3';})), hdrs), 'ML3 Practice Areas');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[6]==='Core';})), hdrs), 'Core Practices');
  XLSX.utils.book_append_sheet(wb, makeSheet(toRows(CMMI_DATA.filter(function(d){return d[6]==='Development-Specific';})), hdrs), 'Development-Specific');

  var paHdrs = ['Practice Area','Full Name','Maturity Level','Category','Practice Count'];
  var paSeen = {};
  CMMI_DATA.forEach(function(d) {
    if (!paSeen[d[0]]) paSeen[d[0]] = {pa:d[0],full:d[1],level:d[5],cat:d[6],count:0};
    paSeen[d[0]].count++;
  });
  var paRows = Object.keys(paSeen).map(function(k) {
    var p = paSeen[k]; return [p.pa,p.full,p.level,p.cat,p.count];
  });
  var paWs = XLSX.utils.aoa_to_sheet([paHdrs].concat(paRows));
  paWs['!cols'] = [{wch:10},{wch:42},{wch:12},{wch:26},{wch:14}];
  paWs['!views'] = [{state:'frozen', ySplit:1}];
  XLSX.utils.book_append_sheet(wb, paWs, 'Practice Areas Overview');

  var fname = 'CMMI-v2-DEV-Level3-' + new Date().toISOString().split('T')[0] + '.xlsx';
  XLSX.writeFile(wb, fname);
}

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
