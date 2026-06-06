/* ================================================================
   branding.js — Settings → Branding for the ISMS document library
   ----------------------------------------------------------------
   - Static / front-end-only: branding is persisted in localStorage
     (per-browser) under the key `isms_branding`.
   - Applies live across every page that loads this module:
       * accent color  → overrides --bs-primary / --bs-primary-rgb / --brand
       * display name  → replaces the navbar brand mark + document.title
       * logo          → replaces the navbar brand mark with an <img>
   - Built-in defaults are used whenever a value is unset, and a
     broken/empty logo URL degrades gracefully back to the text mark.
   - No inline event handlers: all wiring uses addEventListener.
   - User-supplied strings are escaped before being injected; logo URLs
     are sanitized to allow only http(s):// or data:image/...; the accent
     is validated as a hex color.
   ================================================================ */
(function () {
  'use strict';

  var STORAGE_KEY = 'isms_branding';

  var DEFAULTS = {
    name: 'JRojas',
    logoUrl: '',
    accent: '#ff5811'
  };

  /* ── Helpers ──────────────────────────────────────────────── */

  // Escape a string for safe insertion into HTML markup.
  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Allow only http(s):// or data:image/... URLs. Returns '' if invalid.
  function sanitizeLogoUrl(url) {
    if (typeof url !== 'string') return '';
    var trimmed = url.trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    if (/^data:image\/[a-z0-9.+-]+;/i.test(trimmed)) return trimmed;
    return '';
  }

  // Validate a 3- or 6-digit hex color. Returns normalized #rrggbb or ''.
  function sanitizeHex(hex) {
    if (typeof hex !== 'string') return '';
    var v = hex.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toLowerCase();
    if (/^#[0-9a-fA-F]{3}$/.test(v)) {
      return ('#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]).toLowerCase();
    }
    return '';
  }

  // Convert #rrggbb to "r, g, b" for the *-rgb CSS custom properties.
  function hexToRgbTriple(hex) {
    var v = sanitizeHex(hex);
    if (!v) return '';
    return [
      parseInt(v.slice(1, 3), 16),
      parseInt(v.slice(3, 5), 16),
      parseInt(v.slice(5, 7), 16)
    ].join(', ');
  }

  /* ── Persistence ──────────────────────────────────────────── */

  function load() {
    var stored = {};
    try {
      stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
    } catch (e) {
      stored = {};
    }
    return {
      name: typeof stored.name === 'string' ? stored.name : DEFAULTS.name,
      logoUrl: sanitizeLogoUrl(stored.logoUrl),
      accent: sanitizeHex(stored.accent) || DEFAULTS.accent
    };
  }

  function save(branding) {
    var clean = {
      name: String(branding.name || '').slice(0, 80),
      logoUrl: sanitizeLogoUrl(branding.logoUrl),
      accent: sanitizeHex(branding.accent) || DEFAULTS.accent
    };
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(clean));
    } catch (e) { /* storage may be unavailable; apply still works */ }
    return clean;
  }

  /* ── Apply branding live ──────────────────────────────────── */

  function applyAccent(accent) {
    var hex = sanitizeHex(accent) || DEFAULTS.accent;
    var root = document.documentElement;
    if (hex === DEFAULTS.accent) {
      // Clear overrides so the stylesheet's own values win.
      root.style.removeProperty('--bs-primary');
      root.style.removeProperty('--bs-primary-rgb');
      root.style.removeProperty('--brand');
    } else {
      root.style.setProperty('--bs-primary', hex);
      root.style.setProperty('--bs-primary-rgb', hexToRgbTriple(hex));
      root.style.setProperty('--brand', hex);
    }
  }

  function applyName(name) {
    var display = (name || '').trim() || DEFAULTS.name;
    // document.title: keep the existing suffix after the first " | " if present.
    var current = document.title || '';
    var parts = current.split(' | ');
    if (parts.length > 1) {
      parts[parts.length - 1] = display;
      document.title = parts.join(' | ');
    } else {
      document.title = display;
    }
  }

  function defaultMarkHtml(brandEl) {
    var fromAttr = brandEl && brandEl.getAttribute('data-default-mark');
    if (fromAttr) return fromAttr;
    // Fallback mirrors the built-in text mark.
    return '<span style="color:var(--bs-primary)">J</span>Rojas';
  }

  function applyBrandMark(branding) {
    var brandEl = document.getElementById('ismsBrand') ||
                  document.querySelector('.navbar-brand');
    if (!brandEl) return;

    var name = (branding.name || '').trim() || DEFAULTS.name;
    var logo = sanitizeLogoUrl(branding.logoUrl);

    if (logo) {
      var img = document.createElement('img');
      img.src = logo; // sanitized above
      img.alt = name;
      img.style.maxHeight = '32px';
      img.style.maxWidth = '180px';
      // Graceful fallback: broken/empty logo URL → default text mark.
      img.addEventListener('error', function () {
        renderTextMark(brandEl, branding, name);
      });
      brandEl.textContent = '';
      brandEl.appendChild(img);
    } else {
      renderTextMark(brandEl, branding, name);
    }
  }

  function renderTextMark(brandEl, branding, name) {
    // If the user kept the default name, restore the original colored mark.
    if ((name || DEFAULTS.name) === DEFAULTS.name) {
      brandEl.innerHTML = defaultMarkHtml(brandEl);
    } else {
      // Escape user-supplied name before injecting.
      brandEl.innerHTML =
        '<span style="color:var(--bs-primary)">' +
        escapeHtml(name.charAt(0)) + '</span>' +
        escapeHtml(name.slice(1));
    }
  }

  function applyAll(branding) {
    applyAccent(branding.accent);
    applyName(branding.name);
    applyBrandMark(branding);
  }

  /* ── Settings form wiring (only where the form exists) ────── */

  function wireSettingsForm(getState, setState) {
    var form = document.getElementById('ismsBrandingForm');
    if (!form) return; // page has no Settings UI (e.g. doc pages)

    var nameInput = document.getElementById('brandName');
    var urlInput = document.getElementById('brandLogoUrl');
    var fileInput = document.getElementById('brandLogoFile');
    var accentColor = document.getElementById('brandAccent');
    var accentHex = document.getElementById('brandAccentHex');
    var preview = document.getElementById('brandLogoPreview');
    var previewEmpty = document.getElementById('brandLogoPreviewEmpty');
    var resetBtn = document.getElementById('brandResetBtn');

    function syncPreview(url) {
      var clean = sanitizeLogoUrl(url);
      if (clean) {
        preview.src = clean;
        preview.style.display = '';
        if (previewEmpty) previewEmpty.style.display = 'none';
      } else {
        preview.removeAttribute('src');
        preview.style.display = 'none';
        if (previewEmpty) previewEmpty.style.display = '';
      }
    }

    // If the previewed image fails to load, fall back to the empty state.
    if (preview) {
      preview.addEventListener('error', function () {
        preview.style.display = 'none';
        if (previewEmpty) previewEmpty.style.display = '';
      });
    }

    function fillForm(branding) {
      nameInput.value = branding.name === DEFAULTS.name ? '' : branding.name;
      urlInput.value = branding.logoUrl || '';
      accentColor.value = sanitizeHex(branding.accent) || DEFAULTS.accent;
      accentHex.value = sanitizeHex(branding.accent) || DEFAULTS.accent;
      syncPreview(branding.logoUrl);
    }

    // Live preview / live apply as the user types.
    nameInput.addEventListener('input', function () {
      applyName(nameInput.value);
      applyBrandMark({ name: nameInput.value, logoUrl: urlInput.value });
    });

    urlInput.addEventListener('input', function () {
      syncPreview(urlInput.value);
      applyBrandMark({ name: nameInput.value, logoUrl: urlInput.value });
    });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      if (!/^image\//.test(file.type)) {
        window.alert('Please choose an image file.');
        fileInput.value = '';
        return;
      }
      var reader = new FileReader();
      reader.addEventListener('load', function () {
        var dataUrl = reader.result;
        if (sanitizeLogoUrl(dataUrl)) {
          urlInput.value = dataUrl;
          syncPreview(dataUrl);
          applyBrandMark({ name: nameInput.value, logoUrl: dataUrl });
        }
      });
      reader.readAsDataURL(file);
    });

    accentColor.addEventListener('input', function () {
      accentHex.value = accentColor.value;
      applyAccent(accentColor.value);
    });

    accentHex.addEventListener('input', function () {
      var hex = sanitizeHex(accentHex.value);
      if (hex) {
        accentColor.value = hex;
        applyAccent(hex);
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var next = {
        name: nameInput.value,
        logoUrl: urlInput.value,
        accent: sanitizeHex(accentHex.value) || sanitizeHex(accentColor.value) || DEFAULTS.accent
      };
      var saved = save(next);
      setState(saved);
      applyAll(saved);
      fillForm(saved);
    });

    resetBtn.addEventListener('click', function () {
      try { localStorage.removeItem(STORAGE_KEY); } catch (err) { /* ignore */ }
      var defaults = {
        name: DEFAULTS.name,
        logoUrl: DEFAULTS.logoUrl,
        accent: DEFAULTS.accent
      };
      setState(defaults);
      applyAll(defaults);
      fillForm(defaults);
    });

    // Populate form from current state on open / load.
    fillForm(getState());
  }

  /* ── Init ─────────────────────────────────────────────────── */

  function init() {
    var state = load();
    applyAll(state);
    wireSettingsForm(
      function () { return state; },
      function (next) { state = next; }
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose for debugging / programmatic use.
  window.ISMSBranding = {
    get: load,
    apply: applyAll,
    DEFAULTS: DEFAULTS
  };
})();
