/* CITADEL — Branding (logo URL, org name, accent color).
 * Per the project Settings & Branding standard. Applies app-wide: navbar logo,
 * product name (header + document title), and the primary accent CSS variable.
 *
 * Storage: localStorage for static/front-end-only hosting (per-browser); when a
 * backend is present its shared value (GET /api/branding) wins and is cached
 * locally. A broken logo URL degrades gracefully back to the built-in mark.
 * window.CITADEL.branding
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const KEY = 'citadel.branding.v1';
  const DEFAULTS = { logoUrl: '', orgName: 'CITADEL', accent: '' };

  // Per the Branding Standard, a logo URL is only honored when it is an http(s)
  // URL or a data:image. Anything else (javascript:, data:text/html, SVG-by-
  // path tricks, off-allowlist schemes) is dropped to the empty default so it
  // can neither be stored nor assigned to an <img src>.
  function safeLogo(u) {
    u = String(u || '').trim();
    return /^(https?:\/\/|data:image\/)/i.test(u) ? u : '';
  }

  function get() {
    try {
      const b = Object.assign({}, DEFAULTS, JSON.parse(localStorage.getItem(KEY) || '{}'));
      b.logoUrl = safeLogo(b.logoUrl);
      return b;
    } catch (e) { return Object.assign({}, DEFAULTS); }
  }
  function set(patch) {
    const merged = Object.assign(get(), patch || {});
    merged.logoUrl = safeLogo(merged.logoUrl);
    try { localStorage.setItem(KEY, JSON.stringify(merged)); } catch (e) {}
    return merged;
  }

  function apply(b) {
    b = b || get();
    const doc = root.document; if (!doc) return;
    const name = (b.orgName || '').trim() || DEFAULTS.orgName;
    const logo = safeLogo(b.logoUrl);   // re-validate at render time (defense in depth)

    // Product name in the header brand + the document title prefix.
    const nameEl = doc.getElementById('brand-name');
    if (nameEl) nameEl.textContent = name;
    try { doc.title = doc.title.replace(/^[^—|]+/, name + ' '); } catch (e) {}

    // Logo: show the image when a URL is set, fall back to the built-in mark on error.
    const img = doc.getElementById('brand-logo');
    const mark = doc.getElementById('brand-mark');
    if (img) {
      if (logo) {
        img.onerror = function () { img.classList.add('d-none'); if (mark) mark.classList.remove('d-none'); };
        img.onload = function () { img.classList.remove('d-none'); if (mark) mark.classList.add('d-none'); };
        img.alt = name + ' logo';
        img.src = logo;
      } else {
        img.removeAttribute('src'); img.classList.add('d-none');
        if (mark) mark.classList.remove('d-none');
      }
    }

    // Accent color overrides the primary design token.
    if (b.accent && /^#?[0-9a-fA-F]{3,8}$/.test(b.accent)) {
      const c = b.accent[0] === '#' ? b.accent : ('#' + b.accent);
      doc.documentElement.style.setProperty('--citadel-accent', c);
    } else {
      doc.documentElement.style.removeProperty('--citadel-accent');
    }

    // Print-only header (the on-screen navbar is hidden in print/PDF). Built via
    // DOM (not innerHTML) so a logo URL can never inject markup/script.
    const pb = doc.getElementById('print-brand');
    if (pb) {
      pb.textContent = '';
      if (logo) {
        const im = doc.createElement('img');
        im.src = logo; im.alt = '';
        im.style.height = '34px'; im.style.width = 'auto'; im.style.marginRight = '.6rem';
        pb.appendChild(im);
      }
      const sp = doc.createElement('span');
      sp.style.fontWeight = '700'; sp.style.fontSize = '1.15rem';
      sp.textContent = name;
      pb.appendChild(sp);
    }
  }

  // Pull shared branding from the backend (if any), cache + apply it.
  async function syncFromBackend() {
    try {
      const res = await fetch('api/branding', { headers: { Accept: 'application/json' } });
      if (!res.ok) return null;
      const b = await res.json();
      if (b && (b.logoUrl || b.orgName || b.accent)) { const merged = set(b); apply(merged); return merged; }
    } catch (e) {}
    return null;
  }

  // Push branding to the backend (admin). authHeader: { Authorization: 'Bearer ...' }.
  async function saveToBackend(b, authHeader) {
    try {
      const res = await fetch('api/branding', {
        method: 'PATCH',
        headers: Object.assign({ 'Content-Type': 'application/json' }, authHeader || {}),
        body: JSON.stringify(b || get())
      });
      return res.ok;
    } catch (e) { return false; }
  }

  CITADEL.branding = { get, set, apply, syncFromBackend, saveToBackend, DEFAULTS };
})(window);
