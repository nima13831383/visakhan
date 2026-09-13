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

  function uploadFiles(wrapper) {
    var input = wrapper && wrapper.querySelector('input[type="file"]');
    if (!wrapper || !input || !input.files.length || !window.didarAdmin || wrapper._didarUploadInFlight) return;
    var files = Array.prototype.slice.call(input.files);
    var rules = window.DidarFormInputRules;
    var items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
    if (rules && wrapper.getAttribute('data-client-valid') !== '1') { input.value = ''; return; }
    items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
    if (items.length !== files.length) return;
    wrapper._didarUploadInFlight = true;
    input.value = '';
    input.disabled = true;
    files.reduce(function (chain, file, index) {
      return chain.then(function () {
        var data = new FormData();
        var item = items[index];
        if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'uploading', window.didarAdmin.messages.uploading);
        data.append('action', 'didar_upload_file');
        data.append('nonce', window.didarAdmin.uploadNonce);
        data.append('form_type', wrapper.getAttribute('data-form-type'));
        data.append('submission_id', wrapper.getAttribute('data-submission-id') || '0');
        data.append('field', wrapper.getAttribute('data-field'));
        data.append('file', file);
        return fetch(window.didarAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (response) { return response.json(); })
          .then(function (response) {
            if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarAdmin.messages.uploadError);
            if (rules && rules.promoteUploadedFile) rules.promoteUploadedFile(wrapper, item, response.data, { removeLabel: window.didarAdmin.messages.remove });
          })
          .catch(function (error) {
            if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'failed', '✕ بارگذاری نشد: ' + error.message);
            return false;
          });
      });
    }, Promise.resolve()).finally(function () {
      wrapper._didarUploadInFlight = false;
      input.disabled = false;
    });
  }

  function retryUpload(button) {
    var wrapper = button.closest('[data-didar-upload]'), rules = window.DidarFormInputRules, item = button.closest('.didar-upload-item'), file = rules && rules.getFileForUploadItem ? rules.getFileForUploadItem(item) : null;
    if (!wrapper || !item || !file || !window.didarAdmin) return;
    var data = new FormData();
    data.append('action', 'didar_upload_file'); data.append('nonce', window.didarAdmin.uploadNonce); data.append('form_type', wrapper.getAttribute('data-form-type')); data.append('submission_id', wrapper.getAttribute('data-submission-id') || '0'); data.append('field', wrapper.getAttribute('data-field')); data.append('file', file);
    button.disabled = true;
    if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'uploading', window.didarAdmin.messages.uploading);
    fetch(window.didarAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (response) {
      if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarAdmin.messages.uploadError);
      if (rules && rules.promoteUploadedFile) rules.promoteUploadedFile(wrapper, item, response.data, { removeLabel: window.didarAdmin.messages.remove });
    }).catch(function (error) { if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'failed', '✕ بارگذاری نشد: ' + error.message); }).finally(function () { button.disabled = false; });
  }

  function removeFile(button) {
    var wrapper = button.closest('[data-didar-upload]');
    var item = button.closest('[data-didar-file]');
    if (!wrapper || !item || !window.didarAdmin) return;
    var data = new URLSearchParams();
    data.append('action', 'didar_remove_file');
    data.append('nonce', window.didarAdmin.removeNonce);
    data.append('form_type', wrapper.getAttribute('data-form-type'));
    data.append('submission_id', wrapper.getAttribute('data-submission-id') || '0');
    data.append('field', wrapper.getAttribute('data-field'));
    data.append('file_id', button.getAttribute('data-file-id'));
    button.disabled = true;
    fetch(window.didarAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : window.didarAdmin.messages.removeError);
        if (window.DidarFormInputRules && window.DidarFormInputRules.releaseUploadItem) window.DidarFormInputRules.releaseUploadItem(item);
        item.remove();
        var fileInput = wrapper.querySelector('input[type="file"]');
        if (fileInput && wrapper.getAttribute('data-required') === '1' && !wrapper.querySelector('[data-didar-file]')) fileInput.required = true;
      })
      .catch(function () { button.disabled = false; });
  }

  document.addEventListener('DOMContentLoaded', function () {
	  document.querySelectorAll('.didar-form-access-row').forEach(function (row) {
		var selectButton = row.querySelector('.didar-form-access-select');
		var removeButton = row.querySelector('.didar-form-access-remove');
		var urlInput = row.querySelector('[data-didar-form-access-barcode]');
		var attachmentInput = row.querySelector('[data-didar-form-access-attachment]');
		var preview = row.querySelector('[data-didar-form-access-preview]');
		if (selectButton) selectButton.addEventListener('click', function () {
			if (!window.wp || !wp.media) return;
			var frame = wp.media({ title: 'انتخاب تصویر QR / بارکد', button: { text: 'استفاده از این تصویر' }, multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var allowed = ['image/jpeg', 'image/png', 'image/webp'];
				if (attachment.mime && allowed.indexOf(attachment.mime) === -1) return;
				if (attachment.filesizeInBytes && attachment.filesizeInBytes > 5242880) return;
				if (!attachment.url) return;
				urlInput.value = attachment.url;
				attachmentInput.value = attachment.id || '';
				preview.src = attachment.url;
				preview.hidden = false;
			});
			frame.open();
		});
		if (removeButton) removeButton.addEventListener('click', function () {
			urlInput.value = '';
			attachmentInput.value = '';
			preview.removeAttribute('src');
			preview.hidden = true;
		});
	  });
	  var catalogNode = document.getElementById('didar-custom-field-catalog');
	  var catalog = { fields: [], pipelines: [] };
	  try { if (catalogNode) catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (error) {}
	  var casePipelineNode = document.getElementById('didar-case-pipeline-data');
	  if (casePipelineNode) {
		var casePipelines = []; try { casePipelines = JSON.parse(casePipelineNode.textContent || '[]'); } catch (error) { casePipelines = []; }
		document.querySelectorAll('select[data-didar-case-pipeline]').forEach(function (casePipelineSelect) {
			var caseForm = casePipelineSelect.getAttribute('data-didar-case-form');
			var caseStageSelect = document.querySelector('select[data-didar-case-stage="' + caseForm + '"]');
			if (!caseStageSelect) return;
			function rebuildCaseStages(preserve) { var pipeline = casePipelines.filter(function (item) { return item.id === casePipelineSelect.value; })[0]; var old = preserve ? caseStageSelect.value : ''; caseStageSelect.innerHTML = ''; caseStageSelect.appendChild(new Option(pipeline ? '— انتخاب مرحله —' : '— ابتدا کاریز را انتخاب کنید —', '')); caseStageSelect.disabled = !pipeline; if (pipeline) (pipeline.stages || []).forEach(function (stage) { caseStageSelect.appendChild(new Option(stage.title, stage.id, false, stage.id === old)); }); }
			casePipelineSelect.addEventListener('change', function () { rebuildCaseStages(false); }); rebuildCaseStages(true);
		});
	  }
	  function fieldLabel(field) {
		var available = (catalog.pipelines || []).filter(function (pipeline) { return (field.excluded_pipeline_ids || []).indexOf(pipeline.id) === -1; });
		var scope = available.length === (catalog.pipelines || []).length && available.length ? 'همه کاریزها' : (available.length <= 2 ? available.map(function (pipeline) { return pipeline.title; }).join('، ') : available.length + ' کاریز');
		var duplicates = (catalog.fields || []).filter(function (item) { return item.title === field.title; }).length;
		return field.title + (field.control_type ? ' — ' + field.control_type : '') + ' (' + scope + ')' + (duplicates > 1 ? ' — ' + field.key : '');
	  }
	  function fieldDetails(field) {
		if (!field) return '';
		var available = (catalog.pipelines || []).filter(function (pipeline) { return (field.excluded_pipeline_ids || []).indexOf(pipeline.id) === -1; }).map(function (pipeline) { return pipeline.title; });
		return '<strong>فیلد انتخاب‌شده:</strong> ' + escapeHtml(field.title || field.key) + '<br><code>' + escapeHtml(field.key) + '</code>' + (field.control_type ? ' — ' + escapeHtml(field.control_type) : '') + '<br>کاریزها: ' + escapeHtml(available.join('، ') || '—');
	  }
	  function escapeHtml(value) { return String(value || '').replace(/[&<>"']/g, function (character) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]; }); }
	  function updateCustomFieldDetails(select) {
		var value = select.value || select.getAttribute('data-selected') || '';
		var field = (catalog.fields || []).filter(function (item) { return item.key === value; })[0];
		var details = select.parentNode.querySelector('.didar-custom-field-details');
		if (!details) { details = document.createElement('div'); details.className = 'didar-custom-field-details'; select.after(details); }
		details.hidden = !field;
		details.innerHTML = fieldDetails(field);
		select.title = field ? fieldDetails(field).replace(/<br>/g, '\n').replace(/<[^>]+>/g, '') : '';
	  }
	  function rebuildCustomFields(formType, pipelineId) {
		var container = document.querySelector('[data-didar-field-mapping="' + formType + '"]');
		if (!container) return;
		container.querySelectorAll('.didar-custom-field').forEach(function (select) {
		  var oldValue = select.value || select.getAttribute('data-selected') || '';
		  select.innerHTML = '';
		  if (!pipelineId) { select.disabled = true; select.appendChild(new Option('ابتدا کاریز دیدار این فرم را انتخاب کنید', '')); if (oldValue) { var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.className = 'didar-disabled-custom-field'; hidden.name = select.name; hidden.value = oldValue; select.after(hidden); } return; }
		  container.querySelectorAll('.didar-disabled-custom-field').forEach(function (hidden) { hidden.remove(); });
		  select.disabled = false; select.appendChild(new Option('— بدون نگاشت —', ''));
		  (catalog.fields || []).filter(function (field) { return field.field_type && field.field_type.toLowerCase() === 'deal' && !field.is_deleted && (field.excluded_pipeline_ids || []).indexOf(pipelineId) === -1; }).forEach(function (field) { var option = new Option(fieldLabel(field), field.key, false, field.key === oldValue); option.title = fieldDetails(field).replace(/<br>/g, '\n').replace(/<[^>]+>/g, ''); select.appendChild(option); });
		  if (oldValue && !Array.prototype.some.call(select.options, function (option) { return option.value === oldValue; })) { var stale = (catalog.fields || []).filter(function (field) { return field.key === oldValue; })[0]; var message = !stale ? '⚠ ' + oldValue + ' — در اطلاعات فعلی دیدار یافت نشد' : (stale.is_deleted ? '⚠ ' + stale.title + ' — فیلد در دیدار حذف شده است' : '⚠ ' + stale.title + ' — در کاریز فعلی در دسترس نیست'); select.appendChild(new Option(message, oldValue, false, true)); }
		  select.setAttribute('data-selected', oldValue); updateCustomFieldDetails(select);
		});
	  }
	  document.addEventListener('change', function (event) { if (event.target.classList.contains('didar-custom-field')) updateCustomFieldDetails(event.target); });
	  document.querySelectorAll('.didar-custom-field').forEach(updateCustomFieldDetails);
	  document.querySelectorAll('.didar-form-workflow').forEach(function (workflow) {
		var pipelineData;
		try { pipelineData = JSON.parse(workflow.getAttribute('data-pipelines') || '[]'); } catch (error) { pipelineData = []; }
		function selectedPipeline() { var id = workflow.querySelector('.didar-workflow-pipeline').value; return pipelineData.filter(function (pipeline) { return pipeline.id === id; })[0] || null; }
		function rebuildStage(stage, preserve) {
		  var pipeline = selectedPipeline(); var oldValue = preserve ? stage.value : ''; stage.innerHTML = '';
		  if (!pipeline) { stage.disabled = true; stage.appendChild(new Option('ابتدا کاریز را انتخاب کنید', '')); return; }
		  stage.disabled = false; stage.appendChild(new Option('— انتخاب مرحله کاریز دیدار —', ''));
		  (pipeline.stages || []).forEach(function (item) { stage.appendChild(new Option(item.title + ' (' + pipeline.title + ')', item.id, false, item.id === oldValue)); });
		  if (oldValue && !Array.prototype.some.call(stage.options, function (option) { return option.value === oldValue; })) { stage.appendChild(new Option('⚠ مرحله ذخیره‌شده در کاریز فعلی وجود ندارد', oldValue, false, true)); }
		}
		function refreshStages(preserve) { workflow.querySelectorAll('.didar-workflow-stage').forEach(function (stage) { rebuildStage(stage, preserve); }); }
		function defaults() { workflow.querySelectorAll('.didar-workflow-row').forEach(function (row) { row.querySelector('.didar-workflow-default-value').value = row.querySelector('.didar-workflow-default').checked ? '1' : '0'; }); }
		workflow.addEventListener('change', function (event) { if (event.target.classList.contains('didar-workflow-pipeline')) { refreshStages(false); rebuildCustomFields(workflow.getAttribute('data-didar-workflow'), event.target.value); } if (event.target.classList.contains('didar-workflow-default')) defaults(); });
		workflow.addEventListener('click', function (event) { var remove = event.target.closest('.didar-remove-workflow-status'); var add = event.target.closest('.didar-add-workflow-status'); if (remove) { event.preventDefault(); var rows = workflow.querySelectorAll('.didar-workflow-row'); if (rows.length > 1) remove.closest('tr').remove(); defaults(); } if (add) { event.preventDefault(); var body = workflow.querySelector('.didar-workflow-rows'); var rows = body.querySelectorAll('.didar-workflow-row'); var clone = rows[rows.length - 1].cloneNode(true); var index = rows.length; clone.querySelectorAll('input, select').forEach(function (control) { control.name = control.name.replace(/\[statuses\]\[\d+\]/, '[statuses][' + index + ']'); if (control.type === 'radio') control.checked = false; else if (control.type !== 'hidden') control.value = ''; if (control.classList.contains('didar-workflow-default-value')) control.value = '0'; }); body.appendChild(clone); rebuildStage(clone.querySelector('.didar-workflow-stage'), false); } });
		refreshStages(true); defaults();
	  });
    var fields = document.getElementById('didar-admin-fields');
    if (fields) initRows(fields);
    var select = document.getElementById('didar-form-type-select');
		var postForm = document.getElementById('post');
		if (postForm) {
			postForm.addEventListener('submit', function (event) {
			if (postForm.querySelector('[data-didar-upload-state="uploading"]')) {
					event.preventDefault();
					var status = postForm.querySelector('[data-didar-upload-state="uploading"] .didar-upload-item-status');
					if (status) status.textContent = window.didarAdmin.messages.uploadInProgress;
				}
			});
		}
		document.addEventListener('click', function (event) {
    var remove = event.target.closest('.didar-remove-upload');
    var retry = event.target.closest('[data-didar-upload] .didar-retry-upload');
      if (retry) { event.preventDefault(); retryUpload(retry); }
    if (remove) { event.preventDefault(); removeFile(remove); }
  });
  document.addEventListener('change', function (event) {
    var input = event.target.matches && event.target.matches('input[type="file"]') ? event.target : null;
    var wrapper = input && input.closest('[data-didar-upload]');
    if (wrapper && !wrapper.hasAttribute('data-didar-profile-upload') && !wrapper._didarUploadChangeQueued) {
      wrapper._didarUploadChangeQueued = true;
      window.setTimeout(function () { wrapper._didarUploadChangeQueued = false; uploadFiles(wrapper); }, 0);
    }
  });
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
