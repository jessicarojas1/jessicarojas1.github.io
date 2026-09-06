/* MERIDIAN — Branding (logo URL/upload, org name, accent color).
 * Per the project Settings & Branding standard. Applies app-wide: navbar logo,
 * product name (header + document title), the accent CSS variable, the print/PDF
 * header, and email export. localStorage only (static hosting, per-browser).
 * Logo URLs are sanitized to http(s) / data:image; user strings are injected via
 * textContent or DOM nodes, never innerHTML, so a URL/name cannot inject markup.
 * window.MERIDIAN.branding
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const KEY = 'meridian.branding.v1';
  const DEFAULTS = { logoUrl: '', orgName: 'MERIDIAN', accent: '#3d6fe0' };

  // Allow only http(s) image URLs or data:image payloads. Anything else -> ''.
  function sanitizeLogo(url) {
    const u = (url || '').trim();
    if (!u) return '';
    if (/^data:image\/(png|jpe?g|gif|webp|svg\+xml|avif);/i.test(u)) return u;
    if (/^https?:\/\/[^\s"'<>]+$/i.test(u)) return u;
    return '';
  }

  function get() {
    try { return Object.assign({}, DEFAULTS, JSON.parse(localStorage.getItem(KEY) || '{}')); }
    catch (e) { return Object.assign({}, DEFAULTS); }
  }
  function set(patch) {
    const merged = Object.assign(get(), patch || {});
    merged.logoUrl = sanitizeLogo(merged.logoUrl);
    try { localStorage.setItem(KEY, JSON.stringify(merged)); } catch (e) {}
    return merged;
  }

  function apply(b) {
    b = b || get();
    const doc = root.document; if (!doc) return;
    const name = (b.orgName || '').trim() || DEFAULTS.orgName;
    const logo = sanitizeLogo(b.logoUrl);

    const nameEl = doc.getElementById('brand-name');
    if (nameEl) nameEl.textContent = name;
    try { doc.title = doc.title.replace(/^[^—|]+/, name + ' '); } catch (e) {}

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

    // Accent color -> the MERIDIAN accent token (validated hex).
    if (b.accent && /^#?[0-9a-fA-F]{3,8}$/.test(b.accent)) {
      const c = b.accent[0] === '#' ? b.accent : ('#' + b.accent);
      doc.documentElement.style.setProperty('--mer-accent', c);
    } else {
      doc.documentElement.style.setProperty('--mer-accent', DEFAULTS.accent);
    }

    // Print-only header, built via DOM (no innerHTML) so a logo URL is inert markup.
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
      sp.textContent = name + ' — Daily Executive Intelligence Brief';
      pb.appendChild(sp);
    }
  }

  M.branding = { get, set, apply, sanitizeLogo, DEFAULTS };
})(window);
