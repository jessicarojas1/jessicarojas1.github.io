(function () {
  'use strict';
  var toggle = document.getElementById('saveViewToggle');
  var form = document.getElementById('saveViewForm');
  if (!toggle || !form) {
    return;
  }
  toggle.addEventListener('click', function () {
    form.classList.toggle('hidden');
  });
})();
