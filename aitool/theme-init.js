/* theme-init.js — pre-paint theme bootstrap + delegated Print handler.
 *
 * Externalized from a former inline <head> snippet and inline
 * onclick="window.print()" handlers so every page can ship a script CSP
 * WITHOUT 'unsafe-inline' (see OPEN_ITEMS.md / docs/SECURITY.md).
 *
 * Loaded render-blocking in <head> (no defer/async) so the persisted theme is
 * applied before first paint — no light/dark flash. Same-origin, tiny.
 */
(function () {
  // 1) Pre-paint theme from persisted preference (default: dark).
  try {
    document.documentElement.setAttribute(
      'data-bs-theme',
      localStorage.getItem('bsTheme') || 'dark'
    );
  } catch (e) {
    /* localStorage blocked (private mode / disabled) — keep default theme */
  }

  // 2) Delegated "Print / Save PDF" handler. Any element with a [data-print]
  //    attribute triggers the browser print dialog. Replaces inline onclick.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('[data-print]')) {
      e.preventDefault();
      window.print();
    }
  });
})();
