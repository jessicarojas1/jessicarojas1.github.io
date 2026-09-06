/* MERIDIAN — local persistence (localStorage only, per-browser).
 * Stores settings, engine config (incl. API key), recipient profile, email
 * config, saved source material, and the brief archive. Nothing is transmitted
 * except: the LLM request to the chosen provider, and email via EmailJS — both
 * only on explicit user action. Wrapped in try/catch so private-mode / disabled
 * storage degrades gracefully.
 * window.MERIDIAN.store
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};

  const KEYS = {
    engine:   'meridian.engine.v1',
    profile:  'meridian.profile.v1',
    email:    'meridian.email.v1',
    material: 'meridian.material.v1',
    archive:  'meridian.archive.v1',
    current:  'meridian.current.v1'
  };

  const ENGINE_DEFAULTS = { provider: 'anthropic', model: '', apiKey: '', webSearch: true };
  const PROFILE_DEFAULTS = { role: '', focus: '', orgContext: '' };
  const EMAIL_DEFAULTS = { publicKey: '', serviceId: '', templateId: '', to: '' };

  const DEFAULT_MODELS = { anthropic: 'claude-opus-4-8', openai: 'gpt-4o' };

  function readJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? Object.assign({}, fallback, JSON.parse(raw)) : Object.assign({}, fallback);
    } catch (e) { return Object.assign({}, fallback); }
  }
  function writeJSON(key, val) {
    try { localStorage.setItem(key, JSON.stringify(val)); return true; } catch (e) { return false; }
  }

  // ---- Engine ----
  function getEngine() {
    const e = readJSON(KEYS.engine, ENGINE_DEFAULTS);
    if (!e.model) e.model = DEFAULT_MODELS[e.provider] || '';
    return e;
  }
  function setEngine(patch) { const m = Object.assign(getEngine(), patch || {}); writeJSON(KEYS.engine, m); return m; }

  // ---- Profile ----
  function getProfile() { return readJSON(KEYS.profile, PROFILE_DEFAULTS); }
  function setProfile(patch) { const m = Object.assign(getProfile(), patch || {}); writeJSON(KEYS.profile, m); return m; }

  // ---- Email ----
  function getEmail() { return readJSON(KEYS.email, EMAIL_DEFAULTS); }
  function setEmail(patch) { const m = Object.assign(getEmail(), patch || {}); writeJSON(KEYS.email, m); return m; }

  // ---- Source material ----
  function getMaterial() { try { return localStorage.getItem(KEYS.material) || ''; } catch (e) { return ''; } }
  function setMaterial(text) { try { localStorage.setItem(KEYS.material, text || ''); } catch (e) {} }

  // ---- Archive (map keyed by date id) ----
  function getArchive() { return readJSON(KEYS.archive, {}); }
  function saveBrief(brief) {
    if (!brief || !brief.date) return false;
    const arc = getArchive();
    arc[brief.date] = brief;
    const ok = writeJSON(KEYS.archive, arc);
    if (ok) setCurrentId(brief.date);
    return ok;
  }
  function getBrief(id) { const arc = getArchive(); return arc[id] || null; }
  function deleteBrief(id) { const arc = getArchive(); delete arc[id]; return writeJSON(KEYS.archive, arc); }
  function listBriefs() {
    const arc = getArchive();
    return Object.keys(arc).sort().reverse().map(id => arc[id]);
  }
  function clearArchive() { try { localStorage.removeItem(KEYS.archive); localStorage.removeItem(KEYS.current); return true; } catch (e) { return false; } }

  function getCurrentId() { try { return localStorage.getItem(KEYS.current) || ''; } catch (e) { return ''; } }
  function setCurrentId(id) { try { localStorage.setItem(KEYS.current, id || ''); } catch (e) {} }

  // Merge an imported archive object/array without clobbering existing entries the
  // user did not intend to overwrite; imported ids win on collision (explicit action).
  function importBriefs(payload) {
    let count = 0;
    const arc = getArchive();
    const add = (b) => { if (b && b.date) { arc[b.date] = b; count++; } };
    if (Array.isArray(payload)) payload.forEach(add);
    else if (payload && payload.date) add(payload);
    else if (payload && typeof payload === 'object') Object.values(payload).forEach(add);
    writeJSON(KEYS.archive, arc);
    return count;
  }

  M.store = {
    KEYS, DEFAULT_MODELS,
    getEngine, setEngine,
    getProfile, setProfile,
    getEmail, setEmail,
    getMaterial, setMaterial,
    getArchive, saveBrief, getBrief, deleteBrief, listBriefs, clearArchive,
    getCurrentId, setCurrentId, importBriefs
  };
})(window);
