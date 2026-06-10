/* CITADEL — coverage page logic (externalized for a strict CSP). */
    (function () {
      'use strict';
      var C = window.CITADEL || {};
      if (C.branding) C.branding.apply();

      // Quick stats.
      var hs = document.getElementById('hero-stats');
      if (hs && C.lang && C.rules && C.frameworks) {
        var cwe = (function () { var s = {}; C.rules.forEach(function (r) { if (r.cwe) s[r.cwe] = 1; }); return Object.keys(s).length; })();
        var stats = [
          [C.lang.count, 'languages'],
          [C.rules.length, 'SAST rules'],
          [C.frameworks.CATALOG.length, 'frameworks'],
          [C.frameworks.catalogTotal(), 'controls'],
          [cwe, 'CWEs']
        ];
        hs.innerHTML = stats.map(function (s) {
          return '<div class="hero-stat"><span class="hs-num">' + s[0] + '</span><span class="hs-lbl">' + s[1] + '</span></div>';
        }).join('');
      }

      // Frameworks grid.
      var fgrid = document.getElementById('frameworks-grid');
      if (fgrid && C.frameworks) {
        fgrid.innerHTML = C.frameworks.CATALOG.map(function (f) {
          return '<div class="col-sm-6 col-lg-4 col-xl-3">' +
            '<a class="fw-tile" href="' + f.url + '" target="_blank" rel="noopener">' +
              '<div class="d-flex justify-content-between align-items-start gap-2">' +
                '<span class="fw-tile-name">' + f.name + '</span>' +
                '<span class="badge framework-tag">' + f.tag + '</span>' +
              '</div>' +
              '<div class="fw-tile-ver">' + f.version + '</div>' +
              '<div class="fw-tile-desc">' + f.desc.replace(/"/g, '') + '</div>' +
            '</a></div>';
        }).join('');
      }

      // Theme toggle (this page doesn't load app.js).
      var tb = document.getElementById('themeToggleBtn');
      function icon() { var ic = document.querySelector('#themeToggleBtn .theme-icon'); if (ic) ic.textContent = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '☀️' : '🌙'; }
      if (tb) tb.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('bsTheme', next); } catch (e) {}
        icon();
      });
      icon();

      // Collapse the mobile menu after picking a nav item.
      document.addEventListener('click', function (e) {
        if (e.target.closest('.navbar-collapse .nav-link:not(.dropdown-toggle), .navbar-collapse .dropdown-item')) {
          var col = document.getElementById('citadelNav');
          if (col && col.classList.contains('show') && window.bootstrap) window.bootstrap.Collapse.getOrCreateInstance(col).hide();
        }
      });
    })();
