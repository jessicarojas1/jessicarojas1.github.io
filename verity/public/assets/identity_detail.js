(function () {
  'use strict';

  // "+ Add entitlement" <details> on the Identity detail page's access
  // editor — lazily fetches the account's requestable entitlements the
  // first time a row is expanded, same shape as campaigns.js's identical
  // picker and access_requests.js's cascading-select JSON response.
  document.querySelectorAll('form[data-add-entitlement]').forEach(function (form) {
    var details = form.closest('details');
    var select = form.querySelector('.entitlement-select');
    var applicationId = form.getAttribute('data-application-id');
    var accountId = form.getAttribute('data-account-id');
    var personId = form.getAttribute('data-person-id');
    var loaded = false;
    if (!details || !select) {
      return;
    }
    details.addEventListener('toggle', function () {
      if (!details.open || loaded) {
        return;
      }
      loaded = true;
      fetch('/app/identities/entitlements?application_id=' + encodeURIComponent(applicationId) + '&system_account_id=' + encodeURIComponent(accountId) + '&person_id=' + encodeURIComponent(personId), { credentials: 'same-origin' })
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
