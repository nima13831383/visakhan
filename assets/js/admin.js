(function () {
  'use strict';

  function initRows(root) {
    root.addEventListener('click', function (event) {
      var add = event.target.closest('.didar-add-row');
      var remove = event.target.closest('.didar-remove-row');
      if (!add && !remove) return;
      event.preventDefault();
      var container = event.target.closest('[data-didar-repeater], [data-didar-times]');
      if (!container) return;
      var rows = container.querySelectorAll('.didar-repeater-row, .didar-repeatable-row');
      if (add) {
        var max = parseInt(container.getAttribute('data-max-items') || '20', 10);
        if (!rows.length || rows.length >= max) return;
        var clone = rows[rows.length - 1].cloneNode(true);
		clone.querySelectorAll('input, select, textarea').forEach(function (control) { control.value = ''; control.checked = false; });
        rows[rows.length - 1].after(clone);
      } else if (rows.length === 1) {
		rows[0].querySelectorAll('input, select, textarea').forEach(function (control) { control.value = ''; control.checked = false; });
      } else {
        remove.closest('.didar-repeater-row, .didar-repeatable-row').remove();
      }
      if (container.hasAttribute('data-didar-repeater')) {
        var field = container.getAttribute('data-field');
        container.querySelectorAll('.didar-repeater-row').forEach(function (row, index) {
		  row.querySelectorAll('input, select, textarea').forEach(function (control) {
			control.name = control.name.replace(new RegExp('didar_fields\\[' + field + '\\]\\[\\d+\\]'), 'didar_fields[' + field + '][' + index + ']');
          });
        });
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var fields = document.getElementById('didar-admin-fields');
    if (fields) initRows(fields);
    var select = document.getElementById('didar-form-type-select');
    if (!select || !fields || !window.didarAdmin) return;
    select.addEventListener('change', function () {
      if (!select.value) {
        fields.innerHTML = '<div class="didar-admin-placeholder"><p>ابتدا نوع فرم را انتخاب کنید.</p></div>';
        return;
      }
      var spinner = document.querySelector('[data-didar-admin-spinner]');
      if (spinner) spinner.classList.add('is-active');
      fields.setAttribute('aria-busy', 'true');
      var data = new URLSearchParams();
      data.append('action', 'didar_get_form_fields');
      data.append('nonce', window.didarAdmin.nonce);
      data.append('form_type', select.value);
      fetch(window.didarAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } })
        .then(function (response) { return response.json(); })
        .then(function (response) {
          if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarAdmin.error);
          fields.innerHTML = response.data.html;
        })
        .catch(function (error) { fields.innerHTML = '<div class="notice notice-error inline"><p>' + error.message.replace(/[<>&]/g, '') + '</p></div>'; })
        .finally(function () { fields.removeAttribute('aria-busy'); if (spinner) spinner.classList.remove('is-active'); });
    });
  });
}());
