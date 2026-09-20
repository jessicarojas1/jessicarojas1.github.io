/* REDOUBT — Settings page: logo upload -> data URL + live previews. No inline handlers. */
(function () {
  'use strict';
  var fileInput = document.getElementById('logoFile');
  var logoUrl = document.getElementById('logoUrl');
  var preview = document.getElementById('logoPreview');
  var accent = document.getElementById('accent');

  function showPreview(src) {
    if (!preview) { return; }
    if (src) { preview.src = src; preview.style.display = ''; }
    else { preview.removeAttribute('src'); preview.style.display = 'none'; }
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) { return; }
      if (f.size > 512 * 1024) { alert('Please choose an image under 512 KB.'); fileInput.value = ''; return; }
      var reader = new FileReader();
      reader.onload = function () {
        if (logoUrl) { logoUrl.value = String(reader.result); }
        showPreview(String(reader.result));
      };
      reader.readAsDataURL(f);
    });
  }

  if (logoUrl) {
    logoUrl.addEventListener('input', function () { showPreview(logoUrl.value.trim()); });
  }

  // Live-apply accent preview to the page while editing.
  if (accent) {
    accent.addEventListener('input', function () {
      try { document.documentElement.style.setProperty('--accent', accent.value); } catch (e) {}
    });
  }
})();
