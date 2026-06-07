/* ============================================================
 * Branding module — Teacher Hub (teacher)
 * Implements the project "Settings & Branding Standard":
 *   - logo via URL or uploaded data: URL
 *   - organization / product display name
 *   - primary accent color (overrides --bs-primary)
 * Persisted in localStorage; applied live. No inline handlers.
 * ============================================================ */
(function () {
  'use strict';

  var STORAGE_KEY = 'teacher.branding.v1';
  var DEFAULT_NAME = 'Teacher Hub';
  var DEFAULT_TITLE = 'Teacher Hub | Jessica Rojas';
  var DEFAULT_ACCENT = '#ff5811';
  var DEFAULT_BRAND_TEXT = 'JR';

  /* ---------- helpers ---------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  function sanitizeLogoUrl(u) {
    u = String(u == null ? '' : u).trim();
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    if (/^data:image\/[a-z0-9.+-]+;base64,/i.test(u)) return u;
    if (/^data:image\/[a-z0-9.+-]+,/i.test(u)) return u;
    return '';
  }
  function isHex(c) {
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(c || '').trim());
  }
  function load() {
    try {
      var v = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
      return (v && typeof v === 'object') ? v : {};
    } catch (e) { return {}; }
  }
  function save(b) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(b)); } catch (e) {}
  }

  /* ---------- apply branding live ---------- */
  function apply(b) {
    b = b || {};
    var accent = isHex(b.accent) ? b.accent : DEFAULT_ACCENT;
    document.documentElement.style.setProperty('--bs-primary', accent);

    var hasName = !!(b.name && String(b.name).trim());
    var name = hasName ? String(b.name).trim() : DEFAULT_NAME;
    document.title = hasName ? (name + ' | Jessica Rojas') : DEFAULT_TITLE;

    var brand = document.querySelector('nav.navbar .navbar-brand');
    if (brand) {
      var logo = sanitizeLogoUrl(b.logoUrl);
      var imgEl = brand.querySelector('img.brand-logo');
      var textEl = brand.querySelector('.brand-text');
      if (!textEl) {
        textEl = document.createElement('span');
        textEl.className = 'brand-text';
        textEl.textContent = DEFAULT_BRAND_TEXT;
        brand.innerHTML = '';
        brand.appendChild(textEl);
      }
      // Display name replaces the short text mark when set.
      textEl.textContent = hasName ? name : DEFAULT_BRAND_TEXT;
      if (logo) {
        if (!imgEl) {
          imgEl = document.createElement('img');
          imgEl.className = 'brand-logo';
          imgEl.alt = '';
          imgEl.style.height = '28px';
          imgEl.style.width = 'auto';
          imgEl.style.marginRight = '.4rem';
          imgEl.style.verticalAlign = 'middle';
          imgEl.style.objectFit = 'contain';
          imgEl.addEventListener('error', function () {
            imgEl.style.display = 'none';
            if (textEl) textEl.style.display = '';
          });
          imgEl.addEventListener('load', function () {
            imgEl.style.display = '';
            if (textEl) textEl.style.display = 'none';
          });
          brand.insertBefore(imgEl, brand.firstChild);
        }
        imgEl.style.display = '';
        if (textEl) textEl.style.display = 'none';
        imgEl.src = logo;
      } else if (imgEl) {
        imgEl.removeAttribute('src');
        imgEl.style.display = 'none';
        if (textEl) textEl.style.display = '';
      }
    }
  }

  /* ---------- modal markup (built once, no inline handlers) ---------- */
  function buildModal() {
    if (document.getElementById('brandingModal')) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = [
      '<div class="modal fade" id="brandingModal" tabindex="-1" aria-labelledby="brandingModalLabel" aria-hidden="true">',
      '  <div class="modal-dialog modal-dialog-centered">',
      '    <div class="modal-content">',
      '      <div class="modal-header">',
      '        <h5 class="modal-title" id="brandingModalLabel"><i class="bi bi-palette me-2"></i>Branding</h5>',
      '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
      '      </div>',
      '      <div class="modal-body">',
      '        <div class="mb-3">',
      '          <label class="lp-label" for="brandName">Organization / Product Name</label>',
      '          <input type="text" class="form-control" id="brandName" placeholder="' + esc(DEFAULT_NAME) + '" maxlength="120">',
      '        </div>',
      '        <div class="mb-3">',
      '          <label class="lp-label" for="brandLogoUrl">Logo URL</label>',
      '          <input type="url" class="form-control" id="brandLogoUrl" placeholder="https://example.com/logo.png">',
      '          <div class="form-text">Paste an image URL (http(s):// or data:image/...).</div>',
      '        </div>',
      '        <div class="mb-3">',
      '          <label class="lp-label" for="brandLogoFile">Or upload a logo</label>',
      '          <input type="file" class="form-control" id="brandLogoFile" accept="image/*">',
      '          <div class="form-text">Stored locally as a data: URL (works offline). Field: <code>brandLogoFile</code></div>',
      '        </div>',
      '        <div class="mb-3">',
      '          <label class="lp-label" for="brandAccent">Accent Color</label>',
      '          <input type="color" class="form-control form-control-color" id="brandAccent" value="' + esc(DEFAULT_ACCENT) + '">',
      '        </div>',
      '        <div class="d-flex align-items-center gap-2 small text-secondary">',
      '          <span>Preview:</span>',
      '          <img id="brandPreview" alt="" style="height:30px;max-width:160px;object-fit:contain;display:none;border:1px solid var(--bs-border-color);border-radius:4px;">',
      '          <span id="brandPreviewNone">No logo set — default mark in use.</span>',
      '        </div>',
      '      </div>',
      '      <div class="modal-footer">',
      '        <button type="button" class="btn btn-outline-danger me-auto" id="brandReset">Reset to defaults</button>',
      '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>',
      '        <button type="button" class="btn btn-primary" id="brandSave">Save</button>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join('');
    document.body.appendChild(wrap.firstChild);
    wireModal();
  }

  function wireModal() {
    var nameEl = document.getElementById('brandName');
    var urlEl = document.getElementById('brandLogoUrl');
    var fileEl = document.getElementById('brandLogoFile');
    var accentEl = document.getElementById('brandAccent');
    var preview = document.getElementById('brandPreview');
    var previewNone = document.getElementById('brandPreviewNone');
    var saveBtn = document.getElementById('brandSave');
    var resetBtn = document.getElementById('brandReset');

    function refreshPreview() {
      var u = sanitizeLogoUrl(urlEl.value);
      if (u) {
        preview.src = u;
        preview.style.display = '';
        previewNone.style.display = 'none';
      } else {
        preview.removeAttribute('src');
        preview.style.display = 'none';
        previewNone.style.display = '';
      }
    }
    preview.addEventListener('error', function () {
      preview.style.display = 'none';
      previewNone.textContent = 'Logo URL could not be loaded — default mark will be used.';
      previewNone.style.display = '';
    });

    urlEl.addEventListener('input', refreshPreview);

    fileEl.addEventListener('change', function () {
      var f = fileEl.files && fileEl.files[0];
      if (!f) return;
      if (!/^image\//i.test(f.type)) { alert('Please choose an image file.'); fileEl.value = ''; return; }
      var reader = new FileReader();
      reader.addEventListener('load', function () {
        urlEl.value = String(reader.result || '');
        refreshPreview();
      });
      reader.readAsDataURL(f);
    });

    saveBtn.addEventListener('click', function () {
      var accent = accentEl.value;
      if (!isHex(accent)) accent = DEFAULT_ACCENT;
      var b = {
        name: String(nameEl.value || '').trim(),
        logoUrl: sanitizeLogoUrl(urlEl.value),
        accent: accent
      };
      save(b);
      apply(b);
      var modalEl = document.getElementById('brandingModal');
      if (window.bootstrap && window.bootstrap.Modal) {
        var inst = window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
        inst.hide();
      }
    });

    resetBtn.addEventListener('click', function () {
      localStorage.removeItem(STORAGE_KEY);
      nameEl.value = '';
      urlEl.value = '';
      accentEl.value = DEFAULT_ACCENT;
      refreshPreview();
      apply({});
    });

    var modalEl = document.getElementById('brandingModal');
    modalEl.addEventListener('show.bs.modal', function () {
      var b = load();
      nameEl.value = b.name || '';
      urlEl.value = b.logoUrl || '';
      accentEl.value = isHex(b.accent) ? b.accent : DEFAULT_ACCENT;
      previewNone.textContent = 'No logo set — default mark in use.';
      refreshPreview();
    });
  }

  /* ---------- header button ---------- */
  function injectButton() {
    // Teacher navbar toolbar holds the theme + (existing) settings buttons.
    var toolbar = document.querySelector('nav.navbar .navbar-collapse .d-flex')
      || document.querySelector('nav.navbar .d-flex.gap-2');
    if (!toolbar || document.getElementById('brandingSettingsBtn')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline-secondary';
    btn.id = 'brandingSettingsBtn';
    btn.setAttribute('aria-label', 'Branding');
    btn.title = 'Branding';
    btn.innerHTML = '<i class="bi bi-palette"></i>';
    btn.addEventListener('click', function () {
      buildModal();
      var modalEl = document.getElementById('brandingModal');
      if (window.bootstrap && window.bootstrap.Modal) {
        var inst = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        inst.show();
      }
    });
    toolbar.appendChild(btn);
  }

  function init() {
    apply(load());
    injectButton();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
