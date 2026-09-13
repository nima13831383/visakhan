(function () {
  'use strict';

	function reindexRepeater(container) {
    var field = container.getAttribute('data-field');
    if (!field) return;
    container.querySelectorAll('.didar-repeater-row').forEach(function (row, rowIndex) {
		row.querySelectorAll('input, select, textarea').forEach(function (control) {
		if (control.getAttribute('data-didar-search-control') === '1') return;
		control.name = control.name.replace(new RegExp('didar_fields\\[' + field + '\\]\\[\\d+\\]'), 'didar_fields[' + field + '][' + rowIndex + ']');
		control.id = control.id.replace(/-\d+-([^-]+)$/, '-' + rowIndex + '-$1');
		var label = control.closest('label');
		if (label) label.setAttribute('for', control.id);
		var wrapper = control.closest('[data-didar-searchable-select]');
		if (wrapper) {
			var search = wrapper.querySelector('[data-didar-search-control]');
			var options = wrapper.querySelector('[data-didar-search-options]');
			if (search) { search.id = control.id + '-search'; search.setAttribute('aria-controls', control.id + '-options'); }
			if (options) options.id = control.id + '-options';
			wrapper.querySelectorAll('[data-didar-search-option]').forEach(function (option, optionIndex) { option.id = control.id + '-option-' + optionIndex; });
			if (label && search) label.setAttribute('for', search.id);
		}
      });
    });
  }

  function clearClonedUploads(row) {
    row.querySelectorAll('[data-didar-upload]').forEach(function (wrapper) {
      wrapper.querySelectorAll('.didar-upload-item').forEach(function (item) {
        if (window.DidarFormInputRules && window.DidarFormInputRules.releaseUploadItem) window.DidarFormInputRules.releaseUploadItem(item);
        item.remove();
      });
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
    if (window.DidarFormInputRules && typeof window.DidarFormInputRules.destroySearchableSelect === 'function') {
      clone.querySelectorAll('select[data-didar-search-enhanced="1"]').forEach(window.DidarFormInputRules.destroySearchableSelect);
    }
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
    if (window.DidarFormInputRules && typeof window.DidarFormInputRules.enhanceFormControls === 'function') window.DidarFormInputRules.enhanceFormControls(clone);
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
    reindexRepeater(container);
  }

  function companionAgeGroup(age) {
    if (age === '') return '';
    age = Number(String(age).replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); }));
    if (!isFinite(age) || Math.floor(age) !== age || age < 0 || age > 130) return '';
    if (age <= 1) return 'infant';
    if (age <= 12) return 'child';
    if (age <= 17) return 'teenager';
    if (age <= 64) return 'adult';
    return 'elderly';
  }

  function updateDerivedCompanionFields(form) {
    if (!form) return;
    form.querySelectorAll('[data-didar-repeater][data-derived-count-field]').forEach(function (container) {
      var count = 0;
      container.querySelectorAll('.didar-repeater-row').forEach(function (row) {
        var active = !!row.querySelector('[data-didar-file]');
        row.querySelectorAll('input,select,textarea').forEach(function (control) {
          if (control.name.indexOf('[companion_uid]') !== -1 || control.type === 'file' || control.getAttribute('data-didar-derived') === '1') return;
          if (control.value) active = true;
        });
        if (active) count += 1;
        var age = row.querySelector('[data-didar-age-source="1"]');
        var group = row.querySelector('select[data-didar-derived="1"]');
        if (age && group) group.value = companionAgeGroup(age.value);
      });
      var countField = form.querySelector('input[name="didar_fields[' + container.getAttribute('data-derived-count-field') + ']"]');
      if (countField) countField.value = String(count);
    });
  }

  function focusableModalElements(dialog) {
    return Array.prototype.slice.call(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(function (element) { return !element.disabled && element.offsetParent !== null; });
  }

  function openPdfModal(trigger) {
    var modalId = trigger.getAttribute('aria-controls');
    var modal = modalId && document.getElementById(modalId);
    var dialog = modal && modal.querySelector('.didar-pdf-modal__dialog');
    if (!modal || !dialog) return;
    modal._didarPreviousFocus = trigger;
    modal.hidden = false;
    modal.classList.add('is-open');
    document.body.classList.add('didar-pdf-modal-open');
    setPdfStatus(modal, '', false);
    var close = modal.querySelector('.didar-pdf-modal__close');
    if (close) close.focus();
  }

  function closePdfModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    modal.classList.remove('is-open');
    document.body.classList.remove('didar-pdf-modal-open');
    if (modal._didarPreviousFocus && typeof modal._didarPreviousFocus.focus === 'function') modal._didarPreviousFocus.focus();
  }

  function setPdfStatus(modal, message, isError) {
    var status = modal && modal.querySelector('[data-didar-pdf-status]');
    if (!status) return;
    status.hidden = !message;
    status.textContent = message;
    status.classList.toggle('didar-pdf-modal__status--error', !!isError);
  }

  function pdfFilename(response) {
    var fallback = 'didar-request.pdf';
    var disposition = response.headers.get('Content-Disposition') || '';
    var encoded = disposition.match(/filename\*\s*=\s*UTF-8''([^;]+)/i);
    var plain = disposition.match(/filename\s*=\s*(?:"([^"]+)"|([^;\s]+))/i);
    var filename = '';
    try {
      filename = encoded ? decodeURIComponent(encoded[1]) : (plain ? (plain[1] || plain[2]) : '');
    } catch (error) {
      filename = '';
    }
    filename = filename.replace(/[\\/\0-\x1f<>:"|?*]/g, '_').trim();
    return filename && /\.pdf$/i.test(filename) ? filename : fallback;
  }

  function printPdf(trigger, modal, button, mode) {
    var rawUrl = trigger.getAttribute('data-didar-pdf-url');
    if (!rawUrl || !modal || !button || button.disabled || (mode !== '1' && mode !== '0')) return;
    var url;
    try {
      url = new URL(rawUrl, document.baseURI);
      url.searchParams.set('include_files', mode);
    } catch (error) {
      setPdfStatus(modal, 'دریافت فایل PDF ناموفق بود. دوباره تلاش کنید.', true);
      return;
    }
    button.disabled = true;
    button.setAttribute('data-didar-pdf-busy', '1');
    setPdfStatus(modal, 'در حال آماده‌سازی فایل...', false);
    window.fetch(url.toString(), { credentials: 'same-origin' })
      .then(function (response) {
        var contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !/^application\/pdf(?:;|$)/i.test(contentType)) throw new Error('invalid_pdf_response');
        var filename = pdfFilename(response);
        return response.blob().then(function (blob) { return { blob: blob, filename: filename }; });
      })
      .then(function (payload) {
        var objectUrl = window.URL.createObjectURL(payload.blob);
        var download = document.createElement('a');
        download.href = objectUrl;
        download.download = payload.filename;
        download.hidden = true;
        document.body.appendChild(download);
        download.click();
        download.remove();
        window.setTimeout(function () { window.URL.revokeObjectURL(objectUrl); }, 0);
        setPdfStatus(modal, 'فایل PDF آماده و دانلود شد.', false);
      })
      .then(function () {
        button.disabled = false;
        button.removeAttribute('data-didar-pdf-busy');
      }, function () {
        setPdfStatus(modal, 'دریافت فایل PDF ناموفق بود. دوباره تلاش کنید.', true);
        button.disabled = false;
        button.removeAttribute('data-didar-pdf-busy');
      });
  }

  function uploadOne(wrapper, form, file, item) {
    var rules = window.DidarFormInputRules;
    var message = window.didarConfig.messages;
    if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'uploading', message.uploading);
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
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : message.uploadError);
        if (rules && rules.promoteUploadedFile) rules.promoteUploadedFile(wrapper, item, response.data, { removeLabel: message.remove });
      })
      .catch(function (error) {
        if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'failed', '✕ بارگذاری نشد: ' + error.message);
        return false;
      });
  }

  function uploadSelectedFiles(wrapper) {
    var form = wrapper && wrapper.closest('[data-didar-form]');
    var fileInput = wrapper && wrapper.querySelector('input[type="file"]');
    if (!wrapper || !fileInput || !fileInput.files.length || !window.didarConfig || wrapper._didarUploadInFlight) return;
    var files = Array.prototype.slice.call(fileInput.files);
    var rules = window.DidarFormInputRules;
    var items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
    if (rules && wrapper.getAttribute('data-client-valid') !== '1') { fileInput.value = ''; return; }
    items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
    if (items.length !== files.length) return;
    wrapper._didarUploadInFlight = true;
    fileInput.value = '';
    fileInput.disabled = true;
    files.reduce(function (chain, file, index) { return chain.then(function () { return uploadOne(wrapper, form, file, items[index]); }); }, Promise.resolve())
      .finally(function () { wrapper._didarUploadInFlight = false; fileInput.disabled = false; });
  }

  function retryUpload(button) {
    var wrapper = button.closest('[data-didar-upload]'), form = button.closest('[data-didar-form]'), rules = window.DidarFormInputRules, item = button.closest('.didar-upload-item'), file = rules && rules.getFileForUploadItem ? rules.getFileForUploadItem(item) : null;
    if (!wrapper || !item || !file || !window.didarConfig) return;
    button.disabled = true;
    uploadOne(wrapper, form, file, item).finally(function () { button.disabled = false; });
  }

  function removeUploadedFile(button) {
    var wrapper = button.closest('[data-didar-upload]');
    var form = button.closest('[data-didar-form]');
    var item = button.closest('[data-didar-file]');
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
        if (window.DidarFormInputRules && window.DidarFormInputRules.releaseUploadItem) window.DidarFormInputRules.releaseUploadItem(item);
        item.remove();
        var fileInput = wrapper.querySelector('input[type="file"]');
        if (fileInput && wrapper.getAttribute('data-required') === '1' && !wrapper.querySelector('[data-didar-file]')) fileInput.required = true;
      })
      .catch(function () { button.disabled = false; });
  }

  document.addEventListener('click', function (event) {
    var pdfTrigger = event.target.closest('[data-didar-pdf-trigger]');
    if (pdfTrigger) { event.preventDefault(); openPdfModal(pdfTrigger); return; }
    var pdfMode = event.target.closest('[data-didar-pdf-mode]');
    if (pdfMode) { event.preventDefault(); var pdfModal = pdfMode.closest('[data-didar-pdf-modal]'); var pdfTriggerForModal = pdfModal && pdfModal._didarPreviousFocus; if (pdfTriggerForModal) printPdf(pdfTriggerForModal, pdfModal, pdfMode, pdfMode.getAttribute('data-didar-pdf-mode')); return; }
    var pdfClose = event.target.closest('[data-didar-pdf-close]');
    if (pdfClose) { event.preventDefault(); closePdfModal(pdfClose.closest('[data-didar-pdf-modal]')); return; }
    var add = event.target.closest('.didar-add-row');
    if (add) { event.preventDefault(); var addContainer = add.closest('[data-didar-repeater], [data-didar-times]'); addRow(addContainer); updateDerivedCompanionFields(addContainer && addContainer.closest('[data-didar-form],#post')); return; }
    var remove = event.target.closest('.didar-remove-row');
    if (remove) { event.preventDefault(); removeRow(remove); updateDerivedCompanionFields(remove.closest('[data-didar-form],#post')); return; }
    var retry = event.target.closest('[data-didar-upload] .didar-retry-upload');
    if (retry && !retry.closest('[data-didar-profile-upload]')) { event.preventDefault(); retryUpload(retry); return; }
    var removeUpload = event.target.closest('.didar-remove-upload');
    if (removeUpload) { event.preventDefault(); removeUploadedFile(removeUpload); }
  });

  document.addEventListener('input', function (event) {
    var form = event.target.closest && event.target.closest('[data-didar-form],#post');
    if (form) updateDerivedCompanionFields(form);
  });
  document.addEventListener('change', function (event) {
    var fileInput = event.target.matches && event.target.matches('input[type="file"]') ? event.target : null;
    var uploadWrapper = fileInput && fileInput.closest('[data-didar-upload]');
    if (uploadWrapper && !uploadWrapper.hasAttribute('data-didar-profile-upload') && !uploadWrapper._didarUploadChangeQueued) {
      uploadWrapper._didarUploadChangeQueued = true;
      window.setTimeout(function () { uploadWrapper._didarUploadChangeQueued = false; uploadSelectedFiles(uploadWrapper); }, 0);
    }
    var form = event.target.closest && event.target.closest('[data-didar-form],#post');
    if (form) updateDerivedCompanionFields(form);
  });

  document.addEventListener('keydown', function (event) {
    var modal = event.target.closest && event.target.closest('[data-didar-pdf-modal].is-open');
    if (!modal) return;
    if ('Escape' === event.key) { event.preventDefault(); closePdfModal(modal); return; }
    if ('Tab' !== event.key) return;
    var dialog = modal.querySelector('.didar-pdf-modal__dialog');
    var elements = focusableModalElements(dialog);
    if (!elements.length) return;
    var first = elements[0];
    var last = elements[elements.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var summary = document.querySelector('[data-didar-errors]');
    if (summary) summary.focus();

    document.querySelectorAll('[data-didar-form]').forEach(function (form) {
      updateDerivedCompanionFields(form);
      form.addEventListener('submit', function (event) {
        if (form.querySelector('[data-didar-upload-state="uploading"]')) {
          event.preventDefault();
          var status = form.querySelector('[data-didar-upload-state="uploading"] .didar-upload-item-status');
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
