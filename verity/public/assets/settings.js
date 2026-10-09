(function () {
  'use strict';
  var nameInput = document.getElementById('displayName');
  var previewName = document.getElementById('previewName');
  var accentInput = document.getElementById('accent');
  var logoFile = document.getElementById('logoFile');
  var logoUrl = document.getElementById('logoUrl');

  if (nameInput && previewName) {
    nameInput.addEventListener('input', function () {
      previewName.textContent = nameInput.value || 'Verity';
    });
  }
  var livePreviewStyle = document.getElementById('livePreviewStyle');
  if (accentInput && livePreviewStyle) {
    // CSP's style-src has no 'unsafe-inline', and a nonce does not cover the
    // style="" attribute — so the live preview updates a nonce'd <style>
    // element's text instead of elem.style.setProperty(), which CSP also
    // treats as an inline-style mutation.
    accentInput.addEventListener('input', function () {
      livePreviewStyle.textContent = ':root{--accent:' + accentInput.value.replace(/[^#0-9a-fA-F]/g, '') + '}';
    });
  }
  if (logoFile && logoUrl) {
    logoFile.addEventListener('change', function () {
      var file = logoFile.files && logoFile.files[0];
      if (!file || !file.type.match(/^image\//)) {
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        logoUrl.value = String(reader.result);
      };
      reader.readAsDataURL(file);
    });
  }
})();
