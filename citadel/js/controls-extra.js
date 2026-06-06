/* CITADEL — control catalog: CMMI-DEV v2.0 practice areas.
 * Augments window.CITADEL.controlCatalog. CMMI is a process-maturity model;
 * its "controls" are Practice Areas (PAs), grouped here by capability area. */
window.CITADEL = window.CITADEL || {};
window.CITADEL.controlCatalog = Object.assign(window.CITADEL.controlCatalog || {}, {
  cmmi: {
    total: 22,
    note: 'CMMI-DEV v2.0 Practice Areas (development view).',
    families: [
      { id: 'Doing', name: 'Engineering & Developing Products', controls: [
        { id: 'RDM', title: 'Requirements Development & Management' },
        { id: 'TS',  title: 'Technical Solution' },
        { id: 'PI',  title: 'Product Integration' },
        { id: 'VV',  title: 'Verification & Validation' },
        { id: 'PR',  title: 'Peer Reviews' }
      ]},
      { id: 'Managing', name: 'Planning & Managing Work', controls: [
        { id: 'EST',  title: 'Estimating' },
        { id: 'PLAN', title: 'Planning' },
        { id: 'MC',   title: 'Monitor & Control' },
        { id: 'RSK',  title: 'Risk & Opportunity Management' },
        { id: 'SAM',  title: 'Supplier Agreement Management' }
      ]},
      { id: 'Enabling', name: 'Supporting Implementation', controls: [
        { id: 'CM',  title: 'Configuration Management' },
        { id: 'DAR', title: 'Decision Analysis & Resolution' },
        { id: 'II',  title: 'Implementation Infrastructure' }
      ]},
      { id: 'Improving', name: 'Sustaining & Improving', controls: [
        { id: 'PCM', title: 'Process Management' },
        { id: 'PAD', title: 'Process Asset Development' },
        { id: 'MPM', title: 'Managing Performance & Measurement' },
        { id: 'GOV', title: 'Governance' },
        { id: 'OT',  title: 'Organizational Training' },
        { id: 'CAR', title: 'Causal Analysis & Resolution' }
      ]},
      { id: 'Safety', name: 'Safety & Security (optional views)', controls: [
        { id: 'ESEC', title: 'Enabling Security' },
        { id: 'MSEC', title: 'Managing Security Threats & Vulnerabilities' }
      ]}
    ]
  }
});
