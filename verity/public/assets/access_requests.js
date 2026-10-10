(function () {
  'use strict';

  var appSelect = document.getElementById('ar_application_id');
  if (!appSelect) {
    return;
  }
  var accountSelect = document.getElementById('ar_system_account_id');
  var entitlementSelect = document.getElementById('ar_entitlement_id');

  function setOptions(select, items, placeholder, labelFn) {
    select.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = placeholder;
    select.appendChild(ph);
    items.forEach(function (item) {
      var opt = document.createElement('option');
      opt.value = String(item.id);
      opt.textContent = labelFn(item);
      select.appendChild(opt);
    });
  }

  function resetSelect(select, placeholder) {
    setOptions(select, [], placeholder, function () { return ''; });
    select.disabled = true;
  }

  appSelect.addEventListener('change', function () {
    resetSelect(accountSelect, 'Select an application first');
    resetSelect(entitlementSelect, 'Select an account first');
    var applicationId = appSelect.value;
    if (!applicationId) {
      return;
    }
    fetch('/app/access-requests/accounts?application_id=' + encodeURIComponent(applicationId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var accounts = data.accounts || [];
        setOptions(accountSelect, accounts, 'Select an account', function (a) {
          return (a.person_name ? a.person_name + ' — ' : '') + (a.username || a.external_account_id);
        });
        accountSelect.disabled = accounts.length === 0;
      })
      .catch(function () { resetSelect(accountSelect, 'Could not load accounts'); });
  });

  accountSelect.addEventListener('change', function () {
    resetSelect(entitlementSelect, 'Select an account first');
    var applicationId = appSelect.value;
    var accountId = accountSelect.value;
    if (!applicationId || !accountId) {
      return;
    }
    fetch('/app/access-requests/entitlements?application_id=' + encodeURIComponent(applicationId) + '&system_account_id=' + encodeURIComponent(accountId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var entitlements = data.entitlements || [];
        setOptions(entitlementSelect, entitlements, entitlements.length ? 'Select an entitlement' : 'This account already has every entitlement in this application', function (e) {
          return e.name + (e.is_privileged ? ' (privileged)' : '');
        });
        entitlementSelect.disabled = entitlements.length === 0;
      })
      .catch(function () { resetSelect(entitlementSelect, 'Could not load entitlements'); });
  });
})();
