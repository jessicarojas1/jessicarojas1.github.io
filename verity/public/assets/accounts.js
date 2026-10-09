(function () {
  'use strict';

  var search = document.getElementById('link_search');
  if (!search) {
    return;
  }
  var results = document.getElementById('link_results');
  var personIdInput = document.getElementById('link_person_id');
  var submitBtn = document.getElementById('linkSubmit');
  var timer = null;

  // CSP's style-src has no 'unsafe-inline' and a nonce does not cover the
  // style="" attribute, so visibility toggles use the .hidden class (never
  // elem.style.display) and .iam-user-item already carries cursor:pointer.
  function renderCandidates(list) {
    results.innerHTML = '';
    if (!list.length) {
      var empty = document.createElement('div');
      empty.className = 'empty-state-sm p-10';
      empty.textContent = 'No matches.';
      results.appendChild(empty);
      results.classList.remove('hidden');
      return;
    }
    list.forEach(function (c) {
      var row = document.createElement('div');
      row.className = 'iam-user-item';
      row.textContent = c.display_name + (c.department ? ' — ' + c.department : '') + (c.email ? ' (' + c.email + ')' : '');
      row.addEventListener('click', function () {
        personIdInput.value = String(c.id);
        search.value = c.display_name;
        results.classList.add('hidden');
        submitBtn.disabled = false;
      });
      results.appendChild(row);
    });
    results.classList.remove('hidden');
  }

  search.addEventListener('input', function () {
    submitBtn.disabled = true;
    personIdInput.value = '';
    var q = search.value.trim();
    if (timer) {
      clearTimeout(timer);
    }
    if (q.length < 2) {
      results.classList.add('hidden');
      return;
    }
    timer = setTimeout(function () {
      fetch('/app/accounts/link-candidates?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderCandidates(data.candidates || []); })
        .catch(function () { results.classList.add('hidden'); });
    }, 220);
  });

  document.addEventListener('click', function (e) {
    if (e.target !== search && !results.contains(e.target)) {
      results.classList.add('hidden');
    }
  });
})();
