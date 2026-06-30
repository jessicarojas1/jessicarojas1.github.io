/* CITADEL — UI primitives: toast notifications + styled confirm/prompt dialogs.
 *
 * Replaces native alert()/confirm()/prompt(), which are unstyled, not
 * dark-mode aware, not brandable, and block the main thread. Everything here is
 * CSP-safe (no inline handlers — listeners attached in JS) and theme-safe (uses
 * Bootstrap CSS custom properties, so it follows light/dark automatically).
 *
 *   CITADEL.ui.toast(message, type)      // 'success' | 'error' | 'warning' | 'info'
 *   await CITADEL.ui.confirm(message, { danger, okLabel, title })  -> boolean
 *   await CITADEL.ui.prompt(message, defaultValue, { okLabel, title }) -> string | null
 *
 * window.CITADEL.ui
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const doc = root.document;
  function el(tag, cls) { const e = doc.createElement(tag); if (cls) e.className = cls; return e; }

  /* ---------------- Toasts ---------------- */
  let wrap = null;
  function container() {
    if (!wrap) {
      wrap = el('div', 'citadel-toasts');
      wrap.setAttribute('aria-live', 'polite');
      wrap.setAttribute('role', 'status');
      doc.body.appendChild(wrap);
    }
    return wrap;
  }
  const ICON = { success: 'bi-check-circle-fill', error: 'bi-exclamation-octagon-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
  function toast(message, type) {
    type = ICON[type] ? type : 'info';
    const t = el('div', 'citadel-toast citadel-toast-' + type);
    const i = el('i', 'bi ' + ICON[type]); i.setAttribute('aria-hidden', 'true');
    const span = el('span', 'citadel-toast-msg'); span.textContent = String(message == null ? '' : message);
    const x = el('button', 'citadel-toast-x'); x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    t.appendChild(i); t.appendChild(span); t.appendChild(x);
    const close = () => { t.classList.add('citadel-toast-out'); setTimeout(() => t.remove(), 200); };
    x.addEventListener('click', close);
    container().appendChild(t);
    setTimeout(close, type === 'error' ? 6500 : 4000);
    return t;
  }

  /* ---------------- Modal (confirm / prompt) ---------------- */
  function modal(opts) {
    return new Promise(resolve => {
      const back = el('div', 'citadel-modal-back');
      const card = el('div', 'citadel-modal-card');
      card.setAttribute('role', 'dialog');
      card.setAttribute('aria-modal', 'true');
      const titleId = 'cm-title-' + ((root.performance && Math.floor(root.performance.now())) || 0);
      const h = el('h3', 'citadel-modal-title'); h.id = titleId; h.textContent = opts.title || 'Confirm';
      card.setAttribute('aria-labelledby', titleId);
      const p = el('p', 'citadel-modal-msg'); p.textContent = opts.message || '';
      card.appendChild(h); card.appendChild(p);
      let field = null;
      if (opts.input) {
        field = el('input', 'form-control citadel-modal-input');
        field.type = 'text'; field.value = opts.defaultValue == null ? '' : opts.defaultValue;
        field.setAttribute('aria-label', opts.title || 'Value');
        card.appendChild(field);
      }
      const actions = el('div', 'citadel-modal-actions');
      const cancel = el('button', 'btn btn-sm btn-outline-secondary'); cancel.textContent = 'Cancel';
      const ok = el('button', 'btn btn-sm ' + (opts.danger ? 'btn-danger' : 'btn-primary')); ok.textContent = opts.okLabel || 'OK';
      actions.appendChild(cancel); actions.appendChild(ok);
      card.appendChild(actions);
      back.appendChild(card);
      doc.body.appendChild(back);
      const prev = doc.activeElement;
      function done(val) {
        doc.removeEventListener('keydown', onKey, true);
        back.remove();
        if (prev && prev.focus) { try { prev.focus(); } catch (e) {} }
        resolve(val);
      }
      const onOk = () => done(opts.input ? field.value : true);
      const onCancel = () => done(opts.input ? null : false);
      ok.addEventListener('click', onOk);
      cancel.addEventListener('click', onCancel);
      back.addEventListener('mousedown', e => { if (e.target === back) onCancel(); });
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); onCancel(); return; }
        if (e.key === 'Enter' && (!opts.input || doc.activeElement === field)) { e.preventDefault(); onOk(); return; }
        if (e.key === 'Tab') {                       // focus trap
          const f = card.querySelectorAll('button, input, [tabindex]');
          if (!f.length) return;
          const first = f[0], last = f[f.length - 1];
          if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      doc.addEventListener('keydown', onKey, true);
      setTimeout(() => { (opts.input ? field : ok).focus(); if (field) field.select(); }, 30);
    });
  }
  function confirm(message, opts) {
    opts = opts || {};
    return modal({ title: opts.title || 'Please confirm', message: message, okLabel: opts.okLabel || 'Confirm', danger: opts.danger });
  }
  function prompt(message, defaultValue, opts) {
    opts = opts || {};
    return modal({ title: opts.title || 'Enter a value', message: message, input: true, defaultValue: defaultValue, okLabel: opts.okLabel || 'Save' });
  }

  CITADEL.ui = { toast: toast, confirm: confirm, prompt: prompt };
})(window);
