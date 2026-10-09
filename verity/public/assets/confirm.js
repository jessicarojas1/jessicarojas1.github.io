(function () {
  'use strict';
  // Generic confirm-before-submit for any button carrying data-confirm="<message>".
  // Loaded on every authenticated page (see partials/app_footer.php) so every
  // destructive action gets the same guard without inline onclick handlers.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });
})();
