/* CITADEL — Evidence Package.
 *
 * Bundles a reviewer-ready, auditor-grade evidence package as a single ZIP:
 * the final review summary + decision, both SBOM formats (CycloneDX + SPDX),
 * dependency inventory, vulnerability/EPSS/KEV report, license report, runtime
 * requirements, remediation plan, risk-acceptance log, dependency-approval
 * record, and the Executive / Developer / Auditor reports.
 *
 * Self-contained: a tiny store-only (no-compression) ZIP writer + CRC-32, so it
 * needs no external library and runs fully in the browser.
 *
 * window.CITADEL.evidence
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  /* ---------- CRC-32 ---------- */
  const CRC_TABLE = (function () {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();
  function crc32(bytes) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  function utf8(str) {
    if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(String(str == null ? '' : str));
    // Fallback for very old runtimes.
    const s = unescape(encodeURIComponent(String(str == null ? '' : str)));
    const out = new Uint8Array(s.length);
    for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i) & 0xFF;
    return out;
  }
  function w16(arr, off, v) { arr[off] = v & 0xFF; arr[off + 1] = (v >>> 8) & 0xFF; }
  function w32(arr, off, v) { arr[off] = v & 0xFF; arr[off + 1] = (v >>> 8) & 0xFF; arr[off + 2] = (v >>> 16) & 0xFF; arr[off + 3] = (v >>> 24) & 0xFF; }

  // Build a store-only ZIP from [{ name, content }]. Returns a Uint8Array.
  function zip(files) {
    const recs = files.map(f => {
      const nameBytes = utf8(f.name);
      const data = (f.content instanceof Uint8Array) ? f.content : utf8(f.content);
      return { nameBytes, data, crc: crc32(data) };
    });
    const FLAG_UTF8 = 0x0800;
    let size = 0;
    recs.forEach(r => { size += 30 + r.nameBytes.length + r.data.length + 46 + r.nameBytes.length; });
    size += 22;
    const buf = new Uint8Array(size);
    let off = 0;
    const central = [];
    recs.forEach(r => {
      const localOff = off;
      w32(buf, off, 0x04034b50); off += 4;
      w16(buf, off, 20); off += 2;            // version needed
      w16(buf, off, FLAG_UTF8); off += 2;     // flags (UTF-8 name)
      w16(buf, off, 0); off += 2;             // method 0 = store
      w16(buf, off, 0); off += 2;             // mod time
      w16(buf, off, 0x21); off += 2;          // mod date (1980-01-01)
      w32(buf, off, r.crc); off += 4;
      w32(buf, off, r.data.length); off += 4; // compressed size
      w32(buf, off, r.data.length); off += 4; // uncompressed size
      w16(buf, off, r.nameBytes.length); off += 2;
      w16(buf, off, 0); off += 2;             // extra len
      buf.set(r.nameBytes, off); off += r.nameBytes.length;
      buf.set(r.data, off); off += r.data.length;
      central.push({ r, localOff });
    });
    const cdStart = off;
    central.forEach(c => {
      const r = c.r;
      w32(buf, off, 0x02014b50); off += 4;
      w16(buf, off, 20); off += 2;            // version made by
      w16(buf, off, 20); off += 2;            // version needed
      w16(buf, off, FLAG_UTF8); off += 2;
      w16(buf, off, 0); off += 2;             // method
      w16(buf, off, 0); off += 2;             // time
      w16(buf, off, 0x21); off += 2;          // date
      w32(buf, off, r.crc); off += 4;
      w32(buf, off, r.data.length); off += 4;
      w32(buf, off, r.data.length); off += 4;
      w16(buf, off, r.nameBytes.length); off += 2;
      w16(buf, off, 0); off += 2;             // extra
      w16(buf, off, 0); off += 2;             // comment
      w16(buf, off, 0); off += 2;             // disk #
      w16(buf, off, 0); off += 2;             // internal attrs
      w32(buf, off, 0); off += 4;             // external attrs
      w32(buf, off, c.localOff); off += 4;    // local header offset
      buf.set(r.nameBytes, off); off += r.nameBytes.length;
    });
    const cdSize = off - cdStart;
    w32(buf, off, 0x06054b50); off += 4;
    w16(buf, off, 0); off += 2;               // disk
    w16(buf, off, 0); off += 2;               // cd disk
    w16(buf, off, recs.length); off += 2;     // entries this disk
    w16(buf, off, recs.length); off += 2;     // total entries
    w32(buf, off, cdSize); off += 4;
    w32(buf, off, cdStart); off += 4;
    w16(buf, off, 0); off += 2;               // comment len
    return buf;
  }

  /* ---------- CSV helper ---------- */
  function csv(rows) {
    return rows.map(r => r.map(c => {
      const s = String(c == null ? '' : c);
      return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }).join(',')).join('\r\n');
  }

  /* ---------- Artifact assembly ---------- */
  function isCve(f) { return /CVE-\d{4}-\d+|GHSA-/i.test((f.ruleId || '') + ' ' + (f.name || '')) || f.source === 'osv' || f.category === 'deps'; }

  function dependencyInventoryCsv(report) {
    const comps = (report.sbom && report.sbom.components) || [];
    const head = ['name', 'version', 'ecosystem', 'scope', 'license', 'source', 'approval'];
    const rows = comps.map(c => {
      const ap = c.approval && c.approval.status ? c.approval.status : '';
      const lic = c.license || '';
      const src = c.source || '';
      return [c.name, c.version, c.ecosystem, c.scope, lic, src, ap];
    });
    return csv([head].concat(rows));
  }
  function vulnerabilitiesCsv(report) {
    const head = ['id', 'package', 'severity', 'confidence', 'epss', 'kev', 'exploitPriority', 'remediation'];
    const rows = (report.findings || []).filter(isCve).map(f => [
      (((f.ruleId || '') + ' ' + (f.name || '')).match(/CVE-\d{4}-\d+|GHSA-[\w-]+/i) || [f.ruleId || ''])[0],
      f.file || '', f.severity || '', f.confidence || '',
      (typeof f.epss === 'number' ? (f.epss * 100).toFixed(1) + '%' : ''),
      (f.kev ? 'yes' : ''), (f.exploitPriority || ''), f.remediation || ''
    ]);
    return csv([head].concat(rows));
  }
  function licenseReportCsv(report) {
    const head = ['component', 'version', 'ecosystem', 'license'];
    const comps = (report.sbom && report.sbom.components) || [];
    const dep = report.depreview && report.depreview.licenses;
    const rows = comps.map(c => [c.name, c.version, c.ecosystem, c.license || '']);
    const unknown = (dep && dep.unknown) || [];
    unknown.forEach(n => rows.push([n, '', '', 'UNKNOWN']));
    return csv([head].concat(rows));
  }
  function riskAcceptanceCsv(report) {
    const head = ['title', 'severity', 'module', 'file', 'note'];
    const acc = (report.readiness && report.readiness.acceptedRisks) || [];
    const rows = acc.map(a => [a.title, a.severity, a.module || '', a.file || '', a.note || '']);
    return csv([head].concat(rows));
  }
  function approvalRecordCsv() {
    const head = ['key', 'status', 'justification', 'approver', 'securityApproved', 'licenseApproved', 'decidedAt'];
    let map = {};
    try { map = (CITADEL.depapproval && CITADEL.depapproval.list && CITADEL.depapproval.list()) || {}; } catch (e) { map = {}; }
    const rows = Object.keys(map).map(k => {
      const d = map[k] || {};
      return [k, d.status || '', d.justification || '', d.approver || '', d.securityApproved ? 'yes' : 'no', d.licenseApproved ? 'yes' : 'no', d.decidedAt || d.updatedAt || ''];
    });
    return csv([head].concat(rows));
  }
  function indexMd(report, names) {
    const rd = report.readiness || {};
    const meta = report.meta || {};
    const lines = [];
    lines.push('# CITADEL — Evidence Package');
    lines.push('');
    lines.push('Generated: ' + (meta.scannedAt || ''));
    lines.push('Gate decision: **' + (rd.decision || 'n/a') + '** · Readiness score: ' + (rd.overall == null ? 'n/a' : rd.overall) + '/100');
    if (rd.riskAcceptanceRequired) lines.push('Risk acceptance required by: ' + ((rd.approverRoles || []).join(', ') || 'risk owner'));
    lines.push('');
    lines.push('## Contents');
    names.forEach(n => lines.push('- `' + n + '`'));
    lines.push('');
    lines.push('## Top blockers');
    const b = (rd.blockers || []);
    lines.push(b.length ? b.map(x => '- ' + x).join('\n') : '_None._');
    lines.push('');
    lines.push('_This package is generated evidence. Compliance mappings are indicative and require compliance-owner verification; they do not constitute certification._');
    return lines.join('\n');
  }

  function buildArtifacts(report) {
    const out = [];
    const add = (name, content) => { if (content != null && content !== '') out.push({ name, content }); };
    const rr = CITADEL.reviewReport || {};
    const safe = (fn) => { try { return fn(); } catch (e) { return ''; } };

    // SBOMs
    if (report.sbom && report.sbom.doc) add('sbom/cyclonedx.json', JSON.stringify(report.sbom.doc, null, 2));
    if (CITADEL.spdx && CITADEL.spdx.document) {
      add('sbom/spdx.json', safe(() => JSON.stringify(CITADEL.spdx.document(
        (report.sbom && report.sbom.components) || [], (report.integrity && report.integrity.hashes) || {},
        { name: (report.meta && report.meta.projectName) || 'CITADEL SBOM', timestamp: (report.meta && report.meta.scannedAt) || '' }), null, 2)));
    }
    // Inventories & reports
    add('dependency-inventory.csv', safe(() => dependencyInventoryCsv(report)));
    add('vulnerability-report.csv', safe(() => vulnerabilitiesCsv(report)));
    add('license-report.csv', safe(() => licenseReportCsv(report)));
    if (CITADEL.depreviewReport && CITADEL.depreviewReport.markdown) add('runtime-requirements.md', safe(() => CITADEL.depreviewReport.markdown(report)));
    if (rr.developer) add('remediation-plan.md', safe(() => rr.developer(report)));
    add('risk-acceptance-log.csv', safe(() => riskAcceptanceCsv(report)));
    add('dependency-approval-record.csv', safe(() => approvalRecordCsv()));
    if (rr.executive) add('executive-summary.md', safe(() => rr.executive(report)));
    if (rr.auditor) add('auditor-evidence.md', safe(() => rr.auditor(report)));
    if (rr.csv) add('findings-register.csv', safe(() => rr.csv(report)));
    if (rr.json) add('release-readiness.json', safe(() => rr.json(report)));

    // Index last so it lists everything.
    out.unshift({ name: '00-final-review-summary.md', content: indexMd(report, out.map(a => a.name)) });
    return out;
  }

  function download(report) {
    try {
      const files = buildArtifacts(report);
      const bytes = zip(files);
      const blob = new Blob([bytes], { type: 'application/zip' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'citadel-evidence-package.zip';
      document.body.appendChild(a); a.click();
      setTimeout(() => { try { URL.revokeObjectURL(url); a.remove(); } catch (e) {} }, 0);
    } catch (e) {}
  }

  CITADEL.evidence = { build: buildArtifacts, zip, crc32, download };
})(window);
