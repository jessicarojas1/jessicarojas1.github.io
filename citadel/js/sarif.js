/* CITADEL — SARIF 2.1.0 exporter (browser + Node).
 * Converts a CITADEL report into a SARIF log so findings can be uploaded to
 * GitHub Code Scanning or ingested by any SARIF-aware tool.
 * Browser: window.CITADEL.sarif.  Node: also module.exports.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const LEVEL = { critical: 'error', high: 'error', medium: 'warning', low: 'note', info: 'note' };
  const SECSEV = { critical: '9.5', high: '8.0', medium: '5.0', low: '3.0', info: '1.0' };

  function fromReport(report) {
    const findings = (report && report.findings) || [];
    const ruleIndex = {};
    const rules = [];
    findings.forEach(f => {
      const id = f.ruleId || f.category || 'finding';
      if (!(id in ruleIndex)) {
        ruleIndex[id] = rules.length;
        rules.push({
          id,
          name: (f.name || id).replace(/\s+/g, ''),
          shortDescription: { text: (f.name || id).slice(0, 120) },
          fullDescription: { text: f.remediation || f.name || id },
          helpUri: cweUri(f.cwe),
          properties: {
            category: f.category || 'unknown',
            cwe: f.cwe || null,
            tags: ['security', f.category || 'unknown'].concat(f.cwe ? [f.cwe] : [])
          },
          defaultConfiguration: { level: LEVEL[f.severity] || 'warning' }
        });
      }
    });

    const results = findings.map(f => {
      const id = f.ruleId || f.category || 'finding';
      const r = {
        ruleId: id,
        ruleIndex: ruleIndex[id],
        level: LEVEL[f.severity] || 'warning',
        message: { text: (f.name || id) + (f.remediation ? ' — ' + f.remediation : '') },
        properties: {
          severity: f.severity,
          category: f.category,
          confidence: f.confidence || 'medium',
          source: f.source || 'heuristic',
          'security-severity': SECSEV[f.severity] || '1.0'
        }
      };
      const file = (f.file || '').replace(/^.*!\//, '');   // strip archive prefix
      if (file) {
        r.locations = [{
          physicalLocation: {
            artifactLocation: { uri: file },
            region: f.line ? { startLine: Math.max(1, f.line | 0) } : undefined
          }
        }];
      }
      return r;
    });

    return {
      $schema: 'https://json.schemastore.org/sarif-2.1.0.json',
      version: '2.1.0',
      runs: [{
        tool: {
          driver: {
            name: 'CITADEL',
            informationUri: 'https://jessicarojas1.github.io/citadel/',
            version: (report && report.meta && report.meta.engine === 'deep') ? '1.0-deep' : '1.0',
            rules
          }
        },
        results,
        properties: report && report.scoring ? {
          grade: report.scoring.grade,
          security: report.scoring.security,
          quality: report.scoring.quality,
          engine: report.meta && report.meta.engine
        } : {}
      }]
    };
  }

  function cweUri(cwe) {
    if (!cwe) return 'https://cwe.mitre.org/';
    const m = String(cwe).match(/\d+/);
    return m ? `https://cwe.mitre.org/data/definitions/${m[0]}.html` : 'https://cwe.mitre.org/';
  }

  CITADEL.sarif = { fromReport };
  if (typeof module !== 'undefined' && module.exports) module.exports = CITADEL.sarif;
})(typeof window !== 'undefined' ? window : globalThis);
