  /* ── ISMS Filter & Search ─────────────────────────────────────── */
  (function () {
    'use strict';

    var activeFilter = 'all';
    var activeSearch = '';

    function getAllCards() {
      return Array.from(document.querySelectorAll('[data-type]'));
    }

    function getSections() {
      return {
        policy:    document.getElementById('section-policies'),
        procedure: document.getElementById('section-procedures'),
        template:  document.getElementById('section-templates')
      };
    }

    function applyFilters() {
      var cards = getAllCards();
      var q = activeSearch.toLowerCase().trim();
      var visibleBySection = { policy: 0, procedure: 0, template: 0 };

      cards.forEach(function (card) {
        var type  = card.getAttribute('data-type');
        var title = (card.getAttribute('data-title') || '').toLowerCase();

        var matchesFilter = activeFilter === 'all' || type === activeFilter;

        // Normalize filter vs data-type
        if (activeFilter === 'policies')   matchesFilter = type === 'policy';
        if (activeFilter === 'procedures') matchesFilter = type === 'procedure';
        if (activeFilter === 'templates')  matchesFilter = type === 'template';

        var matchesSearch = !q || title.includes(q);

        var visible = matchesFilter && matchesSearch;
        card.style.display = visible ? '' : 'none';

        if (visible) {
          if (type === 'policy')    visibleBySection.policy++;
          if (type === 'procedure') visibleBySection.procedure++;
          if (type === 'template')  visibleBySection.template++;
        }
      });

      // Show/hide entire sections based on whether they have visible cards
      var sections = getSections();
      sections.policy.style.display    = visibleBySection.policy    > 0 ? '' : 'none';
      sections.procedure.style.display = visibleBySection.procedure > 0 ? '' : 'none';
      sections.template.style.display  = visibleBySection.template  > 0 ? '' : 'none';

      var totalVisible = visibleBySection.policy + visibleBySection.procedure + visibleBySection.template;
      var noResults = document.getElementById('isms-no-results');
      if (noResults) noResults.style.display = totalVisible === 0 ? '' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
      // Filter buttons
      document.querySelectorAll('.isms-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.querySelectorAll('.isms-filter-btn').forEach(function (b) {
            b.classList.remove('btn-primary', 'btn-warning', 'btn-success', 'active');
            b.classList.add('btn-outline-primary');
            // Restore individual outline colors
            var f = b.getAttribute('data-filter');
            if (f === 'procedure') {
              b.classList.remove('btn-outline-primary');
              b.classList.add('btn-outline-warning');
            } else if (f === 'template') {
              b.classList.remove('btn-outline-primary');
              b.classList.add('btn-outline-success');
            }
          });

          btn.classList.add('active');
          var filter = btn.getAttribute('data-filter');

          // Set active solid color
          btn.classList.remove('btn-outline-primary', 'btn-outline-warning', 'btn-outline-success');
          if (filter === 'all' || filter === 'policy') {
            btn.classList.add('btn-primary');
          } else if (filter === 'procedure') {
            btn.classList.add('btn-warning');
          } else if (filter === 'template') {
            btn.classList.add('btn-success');
          }

          activeFilter = filter;
          applyFilters();
        });
      });

      // Search input
      var searchInput = document.getElementById('isms-search');
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          activeSearch = searchInput.value;
          applyFilters();
        });
      }

      // Initial render
      applyFilters();
    });
  })();
