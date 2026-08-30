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

  function clearClonedUploads(row) {
    row.querySelectorAll('[data-didar-upload]').forEach(function (wrapper) {
      wrapper.querySelectorAll('[data-didar-file]').forEach(function (item) { item.remove(); });
      wrapper.querySelectorAll('input[type="hidden"]').forEach(function (input) { input.remove(); });
      var input = wrapper.querySelector('input[type="file"]');
      if (input) { input.value = ''; input.required = wrapper.getAttribute('data-required') === '1'; }
    });
  }

  function addRow(container) {
    var field = container.getAttribute('data-field');
    var rows = container.querySelectorAll('.didar-repeater-row, .didar-repeatable-row');
    var max = parseInt(container.getAttribute('data-max-items') || '20', 10);
    if (!rows.length || rows.length >= max) return;
    var clone = rows[rows.length - 1].cloneNode(true);
    var highestIndex = -1;
    rows.forEach(function (row) { highestIndex = Math.max(highestIndex, parseInt(row.getAttribute('data-row-index') || '-1', 10)); });
    var newIndex = highestIndex + 1;
    var oldIndex = parseInt(clone.getAttribute('data-row-index') || String(rows.length - 1), 10);
    clone.setAttribute('data-row-index', newIndex);
	clone.querySelectorAll('input, select, textarea').forEach(function (control) {
      control.value = ''; control.checked = false;
      control.name = control.name.replace(new RegExp('didar_fields\\[' + field + '\\]\\[' + oldIndex + '\\]'), 'didar_fields[' + field + '][' + newIndex + ']');
      control.id = control.id.replace(new RegExp('-' + oldIndex + '-'), '-' + newIndex + '-');
    });
    clone.querySelectorAll('label[for]').forEach(function (label) { label.setAttribute('for', label.getAttribute('for').replace(new RegExp('-' + oldIndex + '-'), '-' + newIndex + '-')); });
    clone.querySelectorAll('[data-didar-upload]').forEach(function (wrapper) {
      wrapper.setAttribute('data-field', wrapper.getAttribute('data-field').replace('companions.' + oldIndex + '.', 'companions.' + newIndex + '.'));
      wrapper.setAttribute('data-input-name', wrapper.getAttribute('data-input-name').replace('[' + oldIndex + ']', '[' + newIndex + ']'));
    });
    clearClonedUploads(clone);
    rows[rows.length - 1].after(clone);
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
    }
  }

  function appendUploadedFile(wrapper, fileId, filename) {
    var list = wrapper.querySelector('.didar-uploaded-files');
    var field = wrapper.getAttribute('data-field');
    var item = document.createElement('li');
    var label = document.createElement('span');
    var hidden = document.createElement('input');
    var remove = document.createElement('button');
    item.setAttribute('data-didar-file', fileId);
    label.textContent = filename;
    hidden.type = 'hidden';
    hidden.name = wrapper.getAttribute('data-input-name') || ('didar_fields[' + field + '][]');
    hidden.value = fileId;
    remove.type = 'button';
    remove.className = 'didar-remove-upload';
    remove.setAttribute('data-file-id', fileId);
    remove.textContent = window.didarConfig.messages.remove;
    item.appendChild(label);
    item.appendChild(hidden);
    item.appendChild(remove);
    list.appendChild(item);
    var fileInput = wrapper.querySelector('input[type="file"]');
    if (fileInput) fileInput.required = false;
  }

  function uploadOne(wrapper, form, file) {
    var status = wrapper.querySelector('.didar-upload-status');
    var data = new FormData();
    data.append('action', 'didar_upload_file');
    data.append('nonce', window.didarConfig.uploadNonce);
    data.append('form_type', form ? form.getAttribute('data-form-type') : wrapper.getAttribute('data-form-type'));
    data.append('submission_id', form ? (form.getAttribute('data-submission-id') || '0') : (wrapper.getAttribute('data-submission-id') || '0'));
    data.append('field', wrapper.getAttribute('data-field'));
    data.append('file', file);

    return fetch(window.didarConfig.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarConfig.messages.uploadError);
        appendUploadedFile(wrapper, response.data.file_id, response.data.display_name);
        status.textContent = response.data.message;
      });
  }

  function uploadFile(button) {
    var wrapper = button.closest('[data-didar-upload]');
    var form = button.closest('[data-didar-form]');
    var fileInput = wrapper && wrapper.querySelector('input[type="file"]');
    var status = wrapper && wrapper.querySelector('.didar-upload-status');
    if (!wrapper || !fileInput || !fileInput.files.length || !window.didarConfig) return;
    var max = parseInt(wrapper.getAttribute('data-max-files') || '1', 10);
    var current = wrapper.querySelectorAll('[data-didar-file]').length;
    var files = Array.prototype.slice.call(fileInput.files);
    if (current + files.length > max) {
      status.textContent = window.didarConfig.messages.fileLimit.replace('%d', max);
      return;
    }
    button.disabled = true;
    wrapper.classList.add('is-uploading');
    status.textContent = window.didarConfig.messages.uploading;

    files.reduce(function (chain, file) { return chain.then(function () { return uploadOne(wrapper, form, file); }); }, Promise.resolve())
      .catch(function (error) { status.textContent = error.message; })
      .finally(function () { button.disabled = false; wrapper.classList.remove('is-uploading'); fileInput.value = ''; });
  }

  function removeUploadedFile(button) {
    var wrapper = button.closest('[data-didar-upload]');
    var form = button.closest('[data-didar-form]');
    var item = button.closest('[data-didar-file]');
    var status = wrapper && wrapper.querySelector('.didar-upload-status');
    if (!wrapper || !item || !window.didarConfig) return;
    var data = new URLSearchParams();
    data.append('action', 'didar_remove_file');
    data.append('nonce', window.didarConfig.removeNonce);
    data.append('form_type', form ? form.getAttribute('data-form-type') : wrapper.getAttribute('data-form-type'));
    data.append('submission_id', form ? (form.getAttribute('data-submission-id') || '0') : (wrapper.getAttribute('data-submission-id') || '0'));
    data.append('field', wrapper.getAttribute('data-field'));
    data.append('file_id', button.getAttribute('data-file-id'));
    button.disabled = true;
    fetch(window.didarConfig.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarConfig.messages.removeError);
        item.remove();
        var fileInput = wrapper.querySelector('input[type="file"]');
        if (fileInput && wrapper.getAttribute('data-required') === '1' && !wrapper.querySelector('[data-didar-file]')) fileInput.required = true;
        status.textContent = response.data.message;
      })
      .catch(function (error) { status.textContent = error.message; button.disabled = false; });
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('.didar-add-row');
    if (add) { event.preventDefault(); addRow(add.closest('[data-didar-repeater], [data-didar-times]')); return; }
    var remove = event.target.closest('.didar-remove-row');
    if (remove) { event.preventDefault(); removeRow(remove); return; }
    var upload = event.target.closest('.didar-upload-button');
    if (upload) { event.preventDefault(); uploadFile(upload); return; }
    var removeUpload = event.target.closest('.didar-remove-upload');
    if (removeUpload) { event.preventDefault(); removeUploadedFile(removeUpload); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var summary = document.querySelector('[data-didar-errors]');
    if (summary) summary.focus();

    document.querySelectorAll('[data-didar-form]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (form.querySelector('.didar-file-upload.is-uploading')) {
          event.preventDefault();
          var status = form.querySelector('.didar-file-upload.is-uploading .didar-upload-status');
          if (status) status.textContent = window.didarConfig.messages.uploadInProgress;
          return;
        }
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
