(function () {
  'use strict';

  function reindexRepeater(container) {
    var field = container.getAttribute('data-field');
    if (!field) return;
    container.querySelectorAll('.didar-repeater-row').forEach(function (row, rowIndex) {
		row.querySelectorAll('input, select, textarea').forEach(function (control) {
		control.name = control.name.replace(new RegExp('didar_fields\\[' + field + '\\]\\[\\d+\\]'), 'didar_fields[' + field + '][' + rowIndex + ']');
		control.id = control.id.replace(/-\d+-([^-]+)$/, '-' + rowIndex + '-$1');
		var label = control.closest('label');
		if (label) label.setAttribute('for', control.id);
      });
    });
  }

  function addRow(container) {
    var rows = container.querySelectorAll('.didar-repeater-row, .didar-repeatable-row');
    var max = parseInt(container.getAttribute('data-max-items') || '20', 10);
    if (!rows.length || rows.length >= max) return;
    var clone = rows[rows.length - 1].cloneNode(true);
	clone.querySelectorAll('input, select, textarea').forEach(function (control) { control.value = ''; control.checked = false; });
    rows[rows.length - 1].after(clone);
    if (container.hasAttribute('data-didar-repeater')) reindexRepeater(container);
  }

  function removeRow(button) {
    var row = button.closest('.didar-repeater-row, .didar-repeatable-row');
    var container = button.closest('[data-didar-repeater], [data-didar-times]');
    if (!row || !container) return;
    var rows = container.querySelectorAll('.didar-repeater-row, .didar-repeatable-row');
    if (rows.length === 1) {
	  row.querySelectorAll('input, select, textarea').forEach(function (control) { control.value = ''; control.checked = false; });
    } else {
      row.remove();
      if (container.hasAttribute('data-didar-repeater')) reindexRepeater(container);
    }
  }

  function uploadFile(button) {
    var wrapper = button.closest('[data-didar-upload]');
    var form = button.closest('[data-didar-form]');
    var fileInput = wrapper && wrapper.querySelector('input[type="file"]');
    var hidden = wrapper && wrapper.querySelector('input[type="hidden"]');
    var status = wrapper && wrapper.querySelector('.didar-upload-status');
    if (!wrapper || !fileInput || !fileInput.files.length || !window.didarConfig) return;

    var data = new FormData();
    data.append('action', 'didar_upload_file');
    data.append('nonce', window.didarConfig.uploadNonce);
    data.append('form_type', form ? form.getAttribute('data-form-type') : wrapper.getAttribute('data-form-type'));
    data.append('field', wrapper.getAttribute('data-field'));
    data.append('file', fileInput.files[0]);
    button.disabled = true;
    status.textContent = window.didarConfig.messages.uploading;

    fetch(window.didarConfig.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarConfig.messages.uploadError);
        hidden.value = response.data.attachment_id;
        status.textContent = response.data.message + ' (' + response.data.filename + ')';
      })
      .catch(function (error) { status.textContent = error.message; })
      .finally(function () { button.disabled = false; });
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('.didar-add-row');
    if (add) { event.preventDefault(); addRow(add.closest('[data-didar-repeater], [data-didar-times]')); return; }
    var remove = event.target.closest('.didar-remove-row');
    if (remove) { event.preventDefault(); removeRow(remove); return; }
    var upload = event.target.closest('.didar-upload-button');
    if (upload) { event.preventDefault(); uploadFile(upload); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var summary = document.querySelector('[data-didar-errors]');
    if (summary) summary.focus();

    document.querySelectorAll('[data-didar-form]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          form.reportValidity();
          return;
        }
        var button = form.querySelector('.didar-submit');
        if (button) button.disabled = true;
        form.classList.add('is-submitting');
      });
    });
  });
}());
