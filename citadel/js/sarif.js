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
      // Stable identity so code-scanning can track a finding across runs.
      r.partialFingerprints = { citadel: fingerprint(f) };
      // Attach a concrete auto-fix when one is available and we have an exact
      // line region (full source line) to replace — GitHub renders these as
      // one-click suggested changes.
      const fx = remediateFix(f);
      if (fx && fx.exact && file && f.line) {
        r.fixes = [{
          description: { text: fx.title },
          artifactChanges: [{
            artifactLocation: { uri: file },
            replacements: [{
              deletedRegion: { startLine: Math.max(1, f.line | 0), startColumn: 1, endColumn: fx.original.length + 1 },
              insertedContent: { text: fx.replacement }
            }]
          }]
        }];
      } else if (fx) {
        r.properties.suggestedFix = { title: fx.title, replacement: fx.replacement };
      }
      // Reflect the user's triage as a SARIF suppression so code-scanning shows
      // dismissed findings as dismissed (and counts only OPEN ones).
      const dispo = dispositionOf(f);
      if (SUPP[dispo]) {
        r.suppressions = [{ kind: 'external', status: SUPP[dispo].status, justification: SUPP[dispo].justification }];
        r.properties.disposition = dispo;
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

  // Map a CITADEL disposition to a SARIF suppression. accepted/remediated/na are
  // "accepted" (a real but dismissed finding); false-positive is "rejected".
  const SUPP = {
    accepted: { status: 'accepted', justification: 'Accepted risk (CITADEL triage)' },
    'false-positive': { status: 'rejected', justification: 'Marked false positive (CITADEL triage)' },
    remediated: { status: 'accepted', justification: 'Marked remediated (CITADEL triage)' },
    na: { status: 'accepted', justification: 'Not applicable (CITADEL triage)' }
  };
  // Live disposition (browser store) or the finding's own field; default 'open'.
  function dispositionOf(f) {
    try { if (CITADEL.disposition && CITADEL.disposition.of) return CITADEL.disposition.of(f); } catch (e) {}
    return f.disposition || 'open';
  }
  // Use the remediation module if it's loaded (it may not be in minimal Node
  // contexts); never let a missing dependency break SARIF generation.
  function remediateFix(f) {
    try { return CITADEL.remediate && CITADEL.remediate.fix ? CITADEL.remediate.fix(f) : null; }
    catch (e) { return null; }
  }
  // A small, stable hash of rule + location + code, independent of line drift in
  // unrelated parts of the file (uses the trimmed snippet, not the line number).
  function fingerprint(f) {
    // Prefer the canonical cross-tier fingerprint when present so SARIF identity
    // matches the in-app fingerprint (disposition, baseline diff, merge).
    if (f.fingerprint) return f.fingerprint;
    if (CITADEL.fingerprint && CITADEL.fingerprint.of) return CITADEL.fingerprint.of(f);
    const s = [f.ruleId || f.category || '', (f.file || '').replace(/^.*!\//, ''), (f.snippet || '').trim()].join('|');
    let h = 5381;
    for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) >>> 0;
    return h.toString(16);
  }

  CITADEL.sarif = { fromReport };
  if (typeof module !== 'undefined' && module.exports) module.exports = CITADEL.sarif;
})(typeof window !== 'undefined' ? window : globalThis);
