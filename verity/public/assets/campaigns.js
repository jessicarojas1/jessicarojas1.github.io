(function () {
  'use strict';

  // "Select all" header checkbox toggles every pending row's checkbox, and
  // unchecking any row checkbox clears "select all" again — same pattern as
  // a native OS file-list header checkbox.
  var selectAll = document.getElementById('selectAll');
  var selectedCount = document.getElementById('selectedCount');

  function rowBoxes() {
    return document.querySelectorAll('.row-select');
  }

  function updateCount() {
    if (!selectedCount) {
      return;
    }
    var checked = document.querySelectorAll('.row-select:checked').length;
    selectedCount.textContent = checked > 0 ? checked + ' selected' : '';
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowBoxes().forEach(function (cb) { cb.checked = selectAll.checked; });
      updateCount();
    });
    rowBoxes().forEach(function (cb) {
      cb.addEventListener('change', function () {
        if (!cb.checked) {
          selectAll.checked = false;
        }
        updateCount();
      });
    });
    updateCount();
  }

  // "+ Add entitlement" <details> — lazily fetches the account's
  // requestable entitlements (same JSON shape access_requests.js already
  // consumes) the first time a row is expanded, so collapsed rows never
  // cost a query.
  document.querySelectorAll('form[data-add-entitlement]').forEach(function (form) {
    var details = form.closest('details');
    var select = form.querySelector('.entitlement-select');
    var applicationId = form.getAttribute('data-application-id');
    var accountId = form.getAttribute('data-account-id');
    var loaded = false;
    if (!details || !select) {
      return;
    }
    details.addEventListener('toggle', function () {
      if (!details.open || loaded) {
        return;
      }
      loaded = true;
      fetch('/app/campaigns/entitlements?application_id=' + encodeURIComponent(applicationId) + '&system_account_id=' + encodeURIComponent(accountId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var entitlements = data.entitlements || [];
          select.innerHTML = '';
          if (entitlements.length === 0) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'This account already has every entitlement in this application';
            select.appendChild(opt);
            select.disabled = true;
            return;
          }
          var ph = document.createElement('option');
          ph.value = '';
          ph.textContent = 'Select an entitlement';
          select.appendChild(ph);
          entitlements.forEach(function (e) {
            var opt = document.createElement('option');
            opt.value = String(e.id);
            opt.textContent = e.name + (e.is_privileged ? ' (privileged)' : '');
            select.appendChild(opt);
          });
          select.disabled = false;
        })
        .catch(function () {
          select.innerHTML = '<option value="">Could not load entitlements</option>';
          select.disabled = true;
        });
    });
  });
})();
