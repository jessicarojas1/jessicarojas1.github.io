/* CITADEL — editable threat model (per-project overlay).
 *
 * The STRIDE threat model is generated at scan time (review-threatmodel.js). This
 * store lets a reviewer ADD, EDIT, and REMOVE threats; the edits are kept as a
 * per-project OVERLAY and layered over the generated model at render/export time
 * via apply() — so they survive a re-scan and never mutate the generated base.
 *
 * Storage mirrors dispositions: per-browser localStorage by default, and the
 * shared server (Postgres) when a durable backend is present.
 *
 *   overlay = { custom: [Threat], edits: { [id]: patch }, hidden: [id] }
 *   CITADEL.threatmodel
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const TKEY = 'citadel.threatmodel.v1';
  const STRIDE = ['Spoofing', 'Tampering', 'Repudiation', 'InformationDisclosure', 'DenialOfService', 'ElevationOfPrivilege'];
  const RISK = ['high', 'medium', 'low'];

  function clean(s, n) { return String(s == null ? '' : s).trim().slice(0, n || 2000); }
  function uid() { return 'ct' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function readAll() { try { return JSON.parse(localStorage.getItem(TKEY) || '{}'); } catch (e) { return {}; } }
  function writeAll(v) { try { localStorage.setItem(TKEY, JSON.stringify(v)); } catch (e) {} }
  function blank() { return { custom: [], edits: {}, hidden: [] }; }
  function pkey(pid) { return pid || '_'; }

  // Shared server overlays (when a backend is present). null => local-only mode.
  let _server = null;        // { [projectId]: overlay }
  function serverEnabled() { return !!_server; }

  function overlayOf(pid) {
    const k = pkey(pid);
    const o = (_server && _server[k]) || readAll()[k] || blank();
    return { custom: Array.isArray(o.custom) ? o.custom : [], edits: o.edits || {}, hidden: Array.isArray(o.hidden) ? o.hidden : [] };
  }
  function save(pid, ov) {
    const k = pkey(pid);
    const all = readAll(); all[k] = ov; writeAll(all);
    if (_server) { _server[k] = ov; if (CITADEL.api && CITADEL.api.threatmodelSet) CITADEL.api.threatmodelSet(pid || '', ov); }
  }
  // Fetch the shared overlay for a project once a backend is detected.
  async function load(pid) {
    if (CITADEL.api && CITADEL.api.threatmodelGet) {
      try {
        const res = await CITADEL.api.threatmodelGet(pid || '');
        if (res && res.enabled) { _server = _server || {}; _server[pkey(pid)] = res.overlay || blank(); return; }
      } catch (e) { /* fall through to local */ }
    }
  }

  function normThreat(t) {
    return {
      id: t.id || uid(), custom: true,
      stride: STRIDE.indexOf(t.stride) >= 0 ? t.stride : 'Tampering',
      title: clean(t.title, 200) || 'Custom threat', surface: clean(t.surface, 200),
      description: clean(t.description, 2000),
      existingMitigations: Array.isArray(t.existingMitigations) ? t.existingMitigations.map(x => clean(x, 200)) : [],
      missingMitigations: Array.isArray(t.missingMitigations) ? t.missingMitigations.map(x => clean(x, 200)) : [],
      residualRisk: RISK.indexOf(t.residualRisk) >= 0 ? t.residualRisk : 'medium'
    };
  }

  function addThreat(pid, t) {
    const ov = overlayOf(pid); ov.custom.push(normThreat(t || {})); save(pid, ov); return ov;
  }
  // patch: { title?, surface?, description?, residualRisk?, missingMitigations?[] }
  function updateThreat(pid, id, patch) {
    const ov = overlayOf(pid);
    const c = ov.custom.find(x => x.id === id);
    if (c) { Object.assign(c, sanitizePatch(patch)); }
    else { ov.edits[id] = Object.assign({}, ov.edits[id], sanitizePatch(patch)); }
    save(pid, ov); return ov;
  }
  function removeThreat(pid, id) {
    const ov = overlayOf(pid);
    const i = ov.custom.findIndex(x => x.id === id);
    if (i >= 0) ov.custom.splice(i, 1);
    else if (ov.hidden.indexOf(id) < 0) ov.hidden.push(id);
    save(pid, ov); return ov;
  }
  function restoreThreat(pid, id) {
    const ov = overlayOf(pid); ov.hidden = ov.hidden.filter(x => x !== id); delete ov.edits[id]; save(pid, ov); return ov;
  }
  function sanitizePatch(p) {
    p = p || {}; const out = {};
    if (p.title != null) out.title = clean(p.title, 200);
    if (p.surface != null) out.surface = clean(p.surface, 200);
    if (p.description != null) out.description = clean(p.description, 2000);
    if (p.residualRisk != null && RISK.indexOf(p.residualRisk) >= 0) out.residualRisk = p.residualRisk;
    if (p.missingMitigations != null) {
      const list = Array.isArray(p.missingMitigations) ? p.missingMitigations
        : String(p.missingMitigations).split(/[;\n]/);
      out.missingMitigations = list.map(x => clean(x, 200)).filter(Boolean);
    }
    return out;
  }

  // Layer the overlay over a generated model; recomputes the STRIDE summary.
  function apply(model, pid) {
    try {
      if (!model) return model;
      const ov = overlayOf(pid);
      const base = (Array.isArray(model.threats) ? model.threats : [])
        .filter(t => ov.hidden.indexOf(t.id) < 0)
        .map(t => (ov.edits[t.id] ? Object.assign({}, t, ov.edits[t.id]) : t));
      const threats = base.concat(ov.custom.map(t => Object.assign({}, t)));
      const byStride = {}; STRIDE.forEach(s => { byStride[s] = 0; });
      threats.forEach(t => { if (byStride[t.stride] != null) byStride[t.stride]++; });
      return Object.assign({}, model, { threats, summary: { total: threats.length, byStride }, edited: (ov.custom.length + ov.hidden.length + Object.keys(ov.edits).length) > 0 });
    } catch (e) { return model; }
  }

  CITADEL.threatmodel = { apply, overlayOf, addThreat, updateThreat, removeThreat, restoreThreat, load, serverEnabled, STRIDE, RISK };
})(window);
