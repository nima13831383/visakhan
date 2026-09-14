(function () {
  'use strict';

  var digitMap = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9', '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };

  function normalizeDigits(value) {
    return value.replace(/[۰-۹٠-٩]/g, function (digit) { return digitMap[digit] || digit; });
  }

  function normalizeDigitsSafe(value) {
    return value.replace(/[\u06f0-\u06f9\u0660-\u0669]/g, function (digit) {
      var code = digit.charCodeAt(0);
      return String(code - (code >= 0x06f0 && code <= 0x06f9 ? 0x06f0 : 0x0660));
    });
  }

  function sanitizeNationalId(value, cursor) {
    value = normalizeDigitsSafe(value);
    var before = value.slice(0, cursor || 0).replace(/[^0-9]/g, '').length;
    return { value: value.replace(/[^0-9]/g, ''), cursor: before };
  }

  function sanitizePassport(value, cursor) {
    var result = '', before = 0, position = 0;
    value = value.toUpperCase();
    for (var i = 0; i < value.length && position < 9; i += 1) {
      var char = value.charAt(i), accepted = false;
      if (position === 0 && /[A-Z]/.test(char)) { result += char; position += 1; accepted = true; }
      else if (position > 0 && /[0-9]/.test(char)) { result += char; position += 1; accepted = true; }
      if (accepted && i < (cursor || 0)) { before += 1; }
    }
    return { value: result, cursor: before };
  }

  function sanitize(input) {
    var cursor = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    var semantic = input.getAttribute('data-didar-semantic');
    var cleaned = semantic === 'national_id' ? sanitizeNationalId(input.value, cursor) : sanitizePassport(input.value, cursor);
    if (input.value !== cleaned.value) {
      input.value = cleaned.value;
      try { input.setSelectionRange(cleaned.cursor, cleaned.cursor); } catch (ignore) {}
    }
  }

  function fieldFor(control) {
    return control && control.closest ? control.closest('.didar-field') : null;
  }

  function liveError(control) {
    var field = fieldFor(control);
    return field ? field.querySelector('.didar-live-error') : null;
  }

  function setLiveError(control, message) {
    var field = fieldFor(control), error = liveError(control);
    if (!field || !error) return;
    field.classList.add('didar-field--live-error');
    error.textContent = message;
    error.hidden = false;
    field.querySelectorAll('input,select,textarea').forEach(function (element) { element.setAttribute('aria-invalid', 'true'); });
  }

  function clearLiveError(control) {
    var field = fieldFor(control), error = liveError(control);
    if (!field || !error) return;
    field.classList.remove('didar-field--live-error');
    error.textContent = '';
    error.hidden = true;
    field.querySelectorAll('[aria-invalid="true"]').forEach(function (element) { element.removeAttribute('aria-invalid'); });
  }

  function validateField(control) {
    if (!control || control.disabled || control.type === 'file' || control.type === 'hidden') return true;
    if (control.getAttribute('data-didar-search-control') === '1') {
      var searchableSelect = control.closest('[data-didar-searchable-select]');
      var authoritativeSelect = searchableSelect && searchableSelect.querySelector('select[data-didar-search-enhanced="1"]');
      return authoritativeSelect ? validateField(authoritativeSelect) : true;
    }
    var field = fieldFor(control), value = normalizeDigitsSafe(control.value || ''), message = '';
    if (!field) return true;
    if (control.type === 'radio' || control.type === 'checkbox') {
      var group = field.querySelectorAll('input[type="' + control.type + '"]'), checked = Array.prototype.some.call(group, function (item) { return item.checked; });
      if (control.required && !checked) message = 'انتخاب این گزینه الزامی است.';
    } else if (control.tagName === 'SELECT') {
      if (control.required && !value) message = 'انتخاب این گزینه الزامی است.';
    } else if (control.required && !value) {
      message = 'تکمیل این فیلد الزامی است.';
    }
    if (!message && value) {
      var semantic = control.getAttribute('data-didar-semantic') || '';
      if (semantic === 'national_id' && !/^[0-9]+$/.test(value)) message = 'کد ملی فقط باید شامل عدد باشد.';
      if (semantic === 'passport_number' && !/^[A-Z][0-9]{8}$/.test(value.toUpperCase())) message = 'شماره گذرنامه باید شامل یک حرف انگلیسی و هشت رقم باشد.';
      if (control.type === 'email' && control.validity && control.validity.typeMismatch) message = 'ایمیل واردشده معتبر نیست.';
      if (control.type === 'time' && !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) message = 'زمان واردشده معتبر نیست.';
      if (control.getAttribute('inputmode') === 'tel' && !/^[0-9+()\-\s]+$/.test(value)) message = 'شماره تماس فقط باید شامل اعداد و نویسه‌های مجاز باشد.';
      if (control.type === 'number') {
        if (!/^-?\d+(?:\.\d+)?$/.test(value)) message = 'این مقدار باید عددی باشد.';
        if (!message && control.min !== '' && Number(value) < Number(control.min)) message = 'مقدار کمتر از حد مجاز است.';
        if (!message && control.max !== '' && Number(value) > Number(control.max)) message = 'مقدار بیشتر از حد مجاز است.';
      }
      if (control.getAttribute('data-didar-datepicker') === 'jalali') {
        var target = document.getElementById(control.getAttribute('data-didar-date-target'));
        if (target && !target.value) message = control.required ? 'تاریخ واردشده معتبر نیست.' : '';
        var dateRangeStart = control.getAttribute('data-didar-date-range-start');
        var form = control.closest && control.closest('[data-didar-form],#post');
        var startControl = dateRangeStart && form ? findFieldControl(form, dateRangeStart) : null;
        if (!message && target && startControl && target.value && startControl.value && target.value < startControl.value) {
          message = 'تاریخ «تا» نمی‌تواند پیش از تاریخ «از» باشد.';
        }
      }
    }
    if (message) setLiveError(control, message); else clearLiveError(control);
    return !message;
  }

  var uploadClientSequence = 0;

  function releaseUploadItem(item) {
    if (!item) return;
    item.querySelectorAll('[data-didar-object-url]').forEach(function (image) {
      var url = image.getAttribute('data-didar-object-url');
      if (url) { try { URL.revokeObjectURL(url); } catch (ignore) {} }
      image.removeAttribute('data-didar-object-url');
    });
  }

  function replaceUploadPreview(image, stableUrl) {
    if (!image || !stableUrl) return;
    var temporaryUrl = image.getAttribute('data-didar-object-url');
    if (!temporaryUrl || temporaryUrl === stableUrl) return;
    var settled = false;
    var onLoad = function () {
      if (settled) return;
      settled = true;
      image.removeEventListener('error', onError);
      image.removeAttribute('data-didar-object-url');
      try { URL.revokeObjectURL(temporaryUrl); } catch (ignore) {}
    };
    var onError = function () {
      if (settled) return;
      settled = true;
      image.removeEventListener('load', onLoad);
      image.removeEventListener('error', onError);
      image.src = temporaryUrl;
    };
    image.addEventListener('load', onLoad);
    image.addEventListener('error', onError);
    image.src = stableUrl;
  }

  function clearUploadItemStates(wrapper) {
    if (!wrapper) return;
    wrapper.classList.remove('is-selected', 'is-uploading', 'is-uploaded', 'is-failed', 'is-validation-error');
  }

  function setUploadItemState(item, state, message) {
    if (!item) return;
    ['is-waiting', 'is-uploading', 'is-success', 'is-failed', 'is-invalid'].forEach(function (className) { item.classList.remove(className); });
    item.classList.add('is-' + state);
    item.setAttribute('data-didar-upload-state', state);
    var status = item.querySelector('.didar-upload-item-status');
    if (status) status.textContent = message || '';
    var retry = item.querySelector('.didar-retry-upload');
    if (retry) retry.hidden = state !== 'failed';
    var removePreview = item.querySelector('.didar-remove-preview');
    if (removePreview) removePreview.disabled = state === 'uploading';
  }

  function nextUploadClientId() {
    uploadClientSequence += 1;
    return 'didar-client-file-' + Date.now().toString(36) + '-' + uploadClientSequence.toString(36);
  }

  function uploadFileKey(file) {
    if (!file) return '';
    return [file.name || '', file.size || 0, file.lastModified || 0, file.type || ''].join('\u0001');
  }

  function isUploadableFile(file, maxSize) {
    return !!file && ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type || '') !== -1 && file.size <= maxSize;
  }

  function renderPreviews(wrapper, files) {
    var preview = wrapper.querySelector('.didar-upload-preview');
    if (!preview) return;
    files = files || [];
    var pending = getPendingFileItems(wrapper);
    pending.forEach(function (item) {
      if (files.indexOf(item._didarFile) === -1) {
        releaseUploadItem(item);
        item.remove();
      }
    });
    files.forEach(function (file) {
      var existing = pending.filter(function (candidate) { return candidate._didarFile === file; })[0];
      if (existing) return;
      var item = document.createElement('div'), image = document.createElement('img'), content = document.createElement('div'), name = document.createElement('span'), status = document.createElement('span'), actions = document.createElement('span'), remove = document.createElement('button'), retry = document.createElement('button'), url = URL.createObjectURL(file);
      item.className = 'didar-upload-preview-item didar-upload-item is-waiting';
      item.setAttribute('data-didar-client-file', nextUploadClientId());
      item.setAttribute('data-didar-upload-state', 'waiting');
      item._didarFile = file;
      image.src = url; image.alt = file.name; image.setAttribute('data-didar-object-url', url); image.loading = 'lazy';
      name.className = 'didar-upload-item-name'; name.textContent = file.name;
      status.className = 'didar-upload-item-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.textContent = 'در انتظار بارگذاری';
      content.className = 'didar-upload-item__content'; content.appendChild(name); content.appendChild(status);
      remove.type = 'button'; remove.className = 'didar-remove-preview'; remove.textContent = 'حذف'; remove.setAttribute('data-preview-client-id', item.getAttribute('data-didar-client-file'));
      retry.type = 'button'; retry.className = 'didar-retry-upload'; retry.textContent = 'تلاش دوباره'; retry.hidden = true;
      actions.className = 'didar-file-actions'; actions.appendChild(retry); actions.appendChild(remove);
      item.appendChild(image); item.appendChild(content); item.appendChild(actions); preview.appendChild(item);
    });
  }

  function getUploadCountSummary(wrapper, files, maxSize) {
    var selectedKeys = Object.create(null), storedFileKeys = Object.create(null), activeExistingCount = 0, serverIds = Object.create(null), pendingExistingKeys = Object.create(null);
    (files || []).forEach(function (file) {
      var key = uploadFileKey(file);
      if (key && !selectedKeys[key]) selectedKeys[key] = file;
    });
    wrapper.querySelectorAll('.didar-upload-item').forEach(function (item) {
      var state = item.getAttribute('data-didar-upload-state') || '';
      var serverId = item.getAttribute('data-didar-file') || '';
      var fileKey = uploadFileKey(item._didarFile);
      if (serverId) {
        if (!serverIds[serverId]) {
          serverIds[serverId] = true;
          activeExistingCount += 1;
        }
        if (fileKey) storedFileKeys[fileKey] = true;
        return;
      }
      if (!fileKey || !item.getAttribute('data-didar-client-file') || ['waiting', 'uploading', 'failed'].indexOf(state) === -1) return;
      if (!selectedKeys[fileKey]) pendingExistingKeys[fileKey] = true;
    });
    Object.keys(pendingExistingKeys).forEach(function (key) {
      if (!storedFileKeys[key]) activeExistingCount += 1;
    });
    var selectedCount = Object.keys(selectedKeys).filter(function (key) {
      return !storedFileKeys[key] && isUploadableFile(selectedKeys[key], maxSize);
    }).length;
    return { activeExistingCount: activeExistingCount, newValidUniqueSelectedCount: selectedCount, finalCount: activeExistingCount + selectedCount };
  }

  function fileValidation(wrapper, files) {
    var max = parseInt(wrapper.getAttribute('data-max-files') || '1', 10), maxSize = parseInt(wrapper.getAttribute('data-max-size') || String(5 * 1024 * 1024), 10), valid = true;
    clearUploadItemStates(wrapper);
    renderPreviews(wrapper, files);
    var countSummary = getUploadCountSummary(wrapper, files, maxSize), countInvalid = countSummary.finalCount > max;
    if (countInvalid) valid = false;
    getPendingFileItems(wrapper).forEach(function (item, index) {
      var file = files[index], message = '';
      if (countInvalid) message = 'برای این فیلد حداکثر ' + max + ' فایل مجاز است.';
      else if (!file || ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type || '') === -1) message = 'فقط تصاویر JPG، PNG و WebP مجاز هستند.';
      else if (file.size > maxSize) message = 'حداکثر حجم مجاز هر تصویر ۵ مگابایت است.';
      if (message) { valid = false; setUploadItemState(item, 'invalid', '⚠ ' + message); }
      else setUploadItemState(item, 'waiting', 'در انتظار بارگذاری');
    });
    wrapper.setAttribute('data-client-valid', valid ? '1' : '0');
    return valid;
  }

  function removePreview(button) {
    var wrapper = button.closest('[data-didar-upload]'), input = wrapper && wrapper.querySelector('input[type="file"]'), item = button.closest('.didar-upload-item'), file = item && item._didarFile;
    if (!wrapper || !input || !item) return;
    releaseUploadItem(item);
    if (window.DataTransfer) {
      var transfer = new DataTransfer();
      Array.prototype.forEach.call(input.files, function (candidate) { if (candidate !== file) transfer.items.add(candidate); });
      input.files = transfer.files;
    } else input.value = '';
    fileValidation(wrapper, Array.prototype.slice.call(input.files || []));
  }

  function getPendingFileItems(wrapper) {
    return wrapper ? Array.prototype.filter.call(wrapper.querySelectorAll('.didar-upload-preview .didar-upload-item[data-didar-client-file]'), function (item) {
      return !item.hasAttribute('data-didar-file') && item.getAttribute('data-didar-upload-state') !== 'success';
    }) : [];
  }

  function getFileForUploadItem(item) {
    return item && item._didarFile ? item._didarFile : null;
  }

  function promoteUploadedFile(wrapper, item, data, options) {
    if (!wrapper || !item || !data || !data.file_id) return null;
    options = options || {};
    var image = item.querySelector('img'), content = item.querySelector('.didar-upload-item__content'), name = item.querySelector('.didar-upload-item-name'), status = item.querySelector('.didar-upload-item-status'), actions = item.querySelector('.didar-file-actions');
    item.setAttribute('data-didar-file', data.file_id);
    item.setAttribute('data-didar-upload-state', 'success');
    if (image) {
      replaceUploadPreview(image, options.previewUrl || '');
    }
    if (!content) { content = document.createElement('div'); content.className = 'didar-upload-item__content'; item.appendChild(content); }
    if (!name) { name = document.createElement('span'); name.className = 'didar-upload-item-name'; content.appendChild(name); }
    if (!status) { status = document.createElement('span'); status.className = 'didar-upload-item-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); content.appendChild(status); }
    name.textContent = data.display_name || data.original_name || name.textContent;
    if (!actions) { actions = document.createElement('span'); actions.className = 'didar-file-actions'; item.appendChild(actions); }
    actions.innerHTML = '';
    if (data.download_url) {
      var link = document.createElement('a'); link.className = 'didar-download-file'; link.href = data.download_url; link.textContent = 'دانلود'; actions.appendChild(link);
    }
    if (options.includeHidden !== false && wrapper.getAttribute('data-input-name')) {
      var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = wrapper.getAttribute('data-input-name'); hidden.value = data.file_id; hidden.setAttribute('data-didar-upload-reference', '1'); actions.appendChild(hidden);
    }
    var remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'didar-remove-upload'; remove.setAttribute('data-file-id', data.file_id); remove.textContent = options.removeLabel || 'حذف'; actions.appendChild(remove);
    setUploadItemState(item, 'success', '✓ بارگذاری شد');
    var fileInput = wrapper.querySelector('input[type="file"]');
    if (fileInput) fileInput.required = false;
    return item;
  }

  function validateFiles(wrapper) {
    var input = wrapper && wrapper.querySelector('input[type="file"]');
    return input ? fileValidation(wrapper, Array.prototype.slice.call(input.files || [])) : false;
  }

  function clearFilePreviews(wrapper) {
    if (!wrapper) return;
    var preview = wrapper.querySelector('.didar-upload-preview');
    if (preview) preview.querySelectorAll('.didar-upload-item').forEach(function (item) {
      if (item.hasAttribute('data-didar-file') || item.getAttribute('data-didar-upload-state') === 'success') return;
      releaseUploadItem(item);
      item.remove();
    });
  }

  function findFieldControl(form, key) {
    if (!form || !key) return null;
    var suffix = '[' + key + ']';
    var first = null;
    var controls = form.querySelectorAll('input,select,textarea');
    for (var i = 0; i < controls.length; i += 1) {
      var name = controls[i].name || '';
      if (name === 'didar_fields[' + key + ']' || name === 'didar_fields[' + key + '][]' || name.slice(-suffix.length) === suffix) {
        if (!first) first = controls[i];
        if ((controls[i].type !== 'radio' && controls[i].type !== 'checkbox') || controls[i].checked) return controls[i];
      }
    }
    return first;
  }

  function searchableOptionLabel(select) {
    var selected = select.options[select.selectedIndex];
    return selected && selected.value ? selected.textContent.trim() : '';
  }

  function searchableSelectedOptions(select) {
    return Array.prototype.filter.call(select.options, function (option) { return !!option.selected && !!option.value; });
  }

  function searchableOptions(select, query) {
    var normalized = (query || '').trim().toLocaleLowerCase();
    return Array.prototype.filter.call(select.options, function (option) {
      if (!option.value) return false;
      if (option.disabled || option.hidden) return !!option.selected;
      return !normalized || (option.textContent + ' ' + option.value).toLocaleLowerCase().indexOf(normalized) !== -1 || option.selected;
    });
  }

  function setSearchableActive(wrapper, index) {
    var buttons = wrapper.querySelectorAll('[data-didar-search-option]');
    Array.prototype.forEach.call(buttons, function (button, buttonIndex) {
      var active = buttonIndex === index;
      button.classList.toggle('is-active', active);
    });
    var input = wrapper.querySelector('[data-didar-search-control]');
    var activeButton = index >= 0 && buttons[index] ? buttons[index] : null;
    if (input) {
      if (activeButton) input.setAttribute('aria-activedescendant', activeButton.id);
      else input.removeAttribute('aria-activedescendant');
    }
  }

  function renderSearchableOptions(wrapper, query, activeIndex) {
    var select = wrapper.querySelector('select[data-didar-search-enhanced="1"]');
    var list = wrapper.querySelector('[data-didar-search-options]');
    if (!select || !list) return -1;
    var options = searchableOptions(select, query);
    var multiple = !!select.multiple;
    list.innerHTML = '';
    options.forEach(function (option, optionIndex) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'didar-searchable-select__option';
      button.id = select.id + '-option-' + optionIndex;
      button.setAttribute('role', 'option');
      button.setAttribute('data-didar-search-option', option.value);
      button.setAttribute('aria-selected', option.selected ? 'true' : 'false');
      button.textContent = option.textContent.trim();
      button.addEventListener('mousedown', function (event) { event.preventDefault(); });
      button.disabled = !!option.disabled;
      button.addEventListener('click', function () {
        if (multiple) option.selected = !option.selected;
        else select.value = option.value;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        var input = wrapper.querySelector('[data-didar-search-control]');
        if (multiple) {
          renderSearchableOptions(wrapper, input ? input.value : '', -1);
        } else {
          closeSearchableSelect(wrapper);
        }
        if (input) input.focus();
      });
      list.appendChild(button);
    });
    if (!options.length) {
      var empty = document.createElement('p');
      empty.className = 'didar-searchable-select__empty';
      empty.setAttribute('role', 'status');
      empty.textContent = 'گزینه‌ای پیدا نشد.';
      list.appendChild(empty);
      activeIndex = -1;
    } else if (activeIndex >= options.length) {
      activeIndex = -1;
    }
    setSearchableActive(wrapper, activeIndex);
    return activeIndex;
  }

  function syncSearchableSelect(select) {
    var wrapper = select && select.closest('[data-didar-searchable-select]');
    if (!wrapper) return;
    var input = wrapper.querySelector('[data-didar-search-control]');
    var clear = wrapper.querySelector('[data-didar-search-clear]');
    if (!input) return;
    var multiple = !!select.multiple;
    var selected = searchableSelectedOptions(select);
    input.disabled = !!select.disabled;
    if (!multiple) input.value = searchableOptionLabel(select);
    input.placeholder = multiple ? 'جست‌وجو و انتخاب کنید' : (select.options[0] ? select.options[0].textContent.trim() : 'از فهرست انتخاب کنید');
    wrapper.classList.toggle('is-disabled', !!select.disabled);
    wrapper.classList.toggle('has-value', multiple ? selected.length > 0 : !!select.value);
    if (clear) clear.hidden = (multiple ? !selected.length : !select.value) || !!select.disabled;
    renderSearchableSelection(select);
    if (wrapper.classList.contains('is-open')) renderSearchableOptions(wrapper, input.value, -1);
  }

  function renderSearchableSelection(select) {
    var wrapper = select && select.closest('[data-didar-searchable-select]');
    var selected = wrapper && wrapper.querySelector('[data-didar-search-selected]');
    if (!wrapper || !selected) return;
    selected.innerHTML = '';
    searchableSelectedOptions(select).forEach(function (option) {
      var chip = document.createElement('span');
      var remove = document.createElement('button');
      chip.className = 'didar-searchable-select__chip';
      chip.textContent = option.textContent.trim();
      remove.type = 'button';
      remove.className = 'didar-searchable-select__chip-remove';
      remove.setAttribute('aria-label', 'حذف ' + option.textContent.trim());
      remove.textContent = '×';
      remove.addEventListener('click', function (event) {
        event.preventDefault();
        option.selected = false;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
      chip.appendChild(remove);
      selected.appendChild(chip);
    });
  }

  function clearSearchableSelect(wrapper) {
    var select = wrapper && wrapper.querySelector('select[data-didar-search-enhanced="1"]');
    if (!select) return;
    closeSearchableSelect(wrapper);
    if (select.multiple) {
      Array.prototype.forEach.call(select.options, function (option) { option.selected = false; });
    } else {
      select.value = '';
    }
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function openSearchableSelect(wrapper) {
    if (!wrapper || wrapper.classList.contains('is-disabled')) return;
    var input = wrapper.querySelector('[data-didar-search-control]');
    var list = wrapper.querySelector('[data-didar-search-options]');
    if (!input || !list) return;
    wrapper.classList.add('is-open');
    input.setAttribute('aria-expanded', 'true');
    var select = wrapper.querySelector('select[data-didar-search-enhanced="1"]');
    renderSearchableOptions(wrapper, select && !select.multiple && input.value === searchableOptionLabel(select) ? '' : input.value, -1);
  }

  function closeSearchableSelect(wrapper) {
    if (!wrapper) return;
    var select = wrapper.querySelector('select[data-didar-search-enhanced="1"]');
    var input = wrapper.querySelector('[data-didar-search-control]');
    wrapper.classList.remove('is-open');
    if (input) {
      input.setAttribute('aria-expanded', 'false');
      if (select && !select.multiple) input.value = searchableOptionLabel(select);
      if (select && select.multiple) input.value = '';
      input.removeAttribute('aria-activedescendant');
    }
  }

  function enhanceSelect(select) {
    if (!select || select.getAttribute('data-didar-search-enhanced') === '1') return;
    var parent = select.parentNode;
    if (!parent) return;
    var wrapper = document.createElement('div');
    var multiControl = document.createElement('div');
    var selected = document.createElement('div');
    var input = document.createElement('input');
    var clear = document.createElement('button');
    var list = document.createElement('div');
    var listId = (select.id || 'didar-searchable-select') + '-options';
    var inputId = (select.id || 'didar-searchable-select') + '-search';
    wrapper.className = 'didar-searchable-select';
    if (select.multiple) wrapper.classList.add('is-multiple');
    wrapper.setAttribute('data-didar-searchable-select', '1');
    input.type = 'text';
    input.id = inputId;
    input.className = 'didar-searchable-select__input';
    input.setAttribute('data-didar-search-control', '1');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-haspopup', 'listbox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('dir', 'rtl');
    if (select.getAttribute('aria-describedby')) input.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    clear.type = 'button';
    clear.className = 'didar-searchable-select__clear';
    clear.setAttribute('data-didar-search-clear', '1');
    clear.setAttribute('aria-label', 'پاک کردن انتخاب');
    clear.setAttribute('title', 'پاک کردن انتخاب');
    clear.textContent = '×';
    clear.hidden = true;
    list.id = listId;
    list.className = 'didar-searchable-select__options';
    list.setAttribute('data-didar-search-options', '1');
    list.setAttribute('role', 'listbox');
    if (select.multiple) list.setAttribute('aria-multiselectable', 'true');
    if (select.getAttribute('data-didar-original-tabindex') === null) select.setAttribute('data-didar-original-tabindex', select.getAttribute('tabindex') || '');
    select.classList.add('didar-searchable-select__native');
    select.setAttribute('data-didar-search-enhanced', '1');
    select.setAttribute('aria-hidden', 'true');
    select.setAttribute('tabindex', '-1');
    parent.insertBefore(wrapper, select);
    if (select.multiple) {
      multiControl.className = 'didar-searchable-select__multi-control';
      selected.setAttribute('data-didar-search-selected', '1');
      selected.className = 'didar-searchable-select__selected';
      multiControl.appendChild(selected);
      multiControl.appendChild(input);
      wrapper.appendChild(multiControl);
    } else {
      wrapper.appendChild(input);
    }
    wrapper.appendChild(clear);
    wrapper.appendChild(list);
    wrapper.appendChild(select);
    input.addEventListener('focus', function () { openSearchableSelect(wrapper); input.select(); });
    input.addEventListener('click', function () { openSearchableSelect(wrapper); });
    clear.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); clearSearchableSelect(wrapper); });
    input.addEventListener('input', function () {
      openSearchableSelect(wrapper);
      renderSearchableOptions(wrapper, input.value, -1);
    });
    input.addEventListener('keydown', function (event) {
      var buttons = wrapper.querySelectorAll('[data-didar-search-option]');
      var active = Array.prototype.findIndex.call(buttons, function (button) { return button.classList.contains('is-active'); });
      var currentSelect = wrapper.querySelector('select[data-didar-search-enhanced="1"]');
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        openSearchableSelect(wrapper);
        if (buttons.length) {
          active = event.key === 'ArrowDown' ? Math.min(active + 1, buttons.length - 1) : Math.max(active - 1, 0);
          setSearchableActive(wrapper, active);
        }
        event.preventDefault();
      } else if (event.key === 'Enter') {
        if (!wrapper.classList.contains('is-open')) openSearchableSelect(wrapper);
        else if (buttons[active]) buttons[active].click();
        event.preventDefault();
      } else if (event.key === 'Escape') {
        closeSearchableSelect(wrapper);
        event.preventDefault();
      } else if ((event.key === 'Backspace' || event.key === 'Delete') && !input.value && currentSelect && (currentSelect.multiple ? searchableSelectedOptions(currentSelect).length : currentSelect.value)) {
        clearSearchableSelect(wrapper);
        event.preventDefault();
      } else if (event.key === 'Tab') {
        closeSearchableSelect(wrapper);
      }
    });
    select._didarSearchableSelectChange = function () { syncSearchableSelect(select); };
    select.addEventListener('change', select._didarSearchableSelectChange);
    wrapper._didarSearchableDocumentClick = function (event) {
      if (!wrapper.contains(event.target)) closeSearchableSelect(wrapper);
    };
    document.addEventListener('click', wrapper._didarSearchableDocumentClick);
    syncSearchableSelect(select);
    var label = parent.querySelector('label[for="' + select.id.replace(/"/g, '\\"') + '"]');
    if (label) { label.setAttribute('data-didar-search-original-for', select.id); label.setAttribute('for', inputId); }
  }

  function destroySearchableSelect(select) {
    if (!select || select.getAttribute('data-didar-search-enhanced') !== '1') return;
    var wrapper = select.closest('[data-didar-searchable-select]');
    if (!wrapper || !wrapper.parentNode) return;
    if (select._didarSearchableSelectChange) select.removeEventListener('change', select._didarSearchableSelectChange);
    if (wrapper._didarSearchableDocumentClick) document.removeEventListener('click', wrapper._didarSearchableDocumentClick);
    select.classList.remove('didar-searchable-select__native');
    select.removeAttribute('data-didar-search-enhanced');
    select.removeAttribute('aria-hidden');
    var originalTabindex = select.getAttribute('data-didar-original-tabindex');
    select.removeAttribute('data-didar-original-tabindex');
    if (originalTabindex) select.setAttribute('tabindex', originalTabindex); else select.removeAttribute('tabindex');
    var label = wrapper.parentNode.querySelector('label[data-didar-search-original-for="' + select.id.replace(/"/g, '\\"') + '"]');
    if (label) { label.setAttribute('for', select.id); label.removeAttribute('data-didar-search-original-for'); }
    wrapper.parentNode.insertBefore(select, wrapper);
    wrapper.remove();
  }

  function setFieldActive(control, active) {
    var field = fieldFor(control);
    if (field) {
      field.hidden = !active;
      field.setAttribute('aria-hidden', active ? 'false' : 'true');
    }
    if (control) {
      control.disabled = !active;
      if (control.getAttribute('data-didar-search-enhanced') === '1') syncSearchableSelect(control);
    }
  }

  function filterDependentCity(select, provinceValue) {
    var legacySelected = false;
    Array.prototype.forEach.call(select.options, function (option) {
      if (!option.value) { option.hidden = false; option.disabled = !provinceValue; return; }
      var optionProvince = option.getAttribute('data-didar-province');
      var isLegacy = !optionProvince;
      var matches = !!provinceValue && optionProvince === provinceValue;
      if (!provinceValue && isLegacy && option.selected) { matches = true; legacySelected = true; }
      option.hidden = !matches;
      option.disabled = !matches;
      if (!matches && option.selected && !isLegacy) select.value = '';
    });
    select.disabled = !provinceValue && !legacySelected;
    var placeholder = select.querySelector('option[value=""]');
    if (placeholder) placeholder.textContent = provinceValue ? '— از فهرست انتخاب کنید —' : 'ابتدا استان را انتخاب کنید';
    syncSearchableSelect(select);
  }

  function requestForMode(form) {
    var selected = form && form.querySelector ? form.querySelector('input[data-didar-request-for]:checked') : null;
    if (selected) return selected.value;
    var control = form && form.querySelector ? form.querySelector('select[data-didar-request-for]') : null;
    return control ? control.value : '';
  }

  function clearProfileControl(control) {
    if (!control) return;
    if (control.matches && control.matches('[data-didar-upload]')) {
      control.querySelectorAll('input[type="hidden"][data-didar-profile-origin="1"]').forEach(function (hidden) {
        var row = hidden.closest('[data-didar-file]');
        if (row) row.remove(); else hidden.remove();
      });
      control.removeAttribute('data-didar-profile-origin');
      clearLiveError(control);
      return;
    }
    if (control.type === 'hidden') return;
    if (control.type === 'radio' || control.type === 'checkbox') {
      var group = control.form ? control.form.querySelectorAll('input[name="' + control.name.replace(/"/g, '\\"') + '"]') : [];
      Array.prototype.forEach.call(group, function (item) { item.checked = false; item.removeAttribute('data-didar-profile-origin'); });
    } else {
      control.value = '';
      var targetId = control.getAttribute('data-didar-date-target');
      var target = targetId ? document.getElementById(targetId) : null;
      if (target) { target.value = ''; target.removeAttribute('data-didar-profile-origin'); }
      control.removeAttribute('data-didar-profile-origin');
    }
    clearLiveError(control);
  }

  function syncProfileMode(form, force) {
    if (!form || !form.querySelectorAll) return;
    var mode = requestForMode(form);
    if (mode !== 'self' && mode !== 'other') return;
    var controls = form.querySelectorAll('[data-didar-profile-source]');
    if (mode === 'other') {
      Array.prototype.forEach.call(controls, function (control) {
        if (control.getAttribute('data-didar-profile-origin') === '1') clearProfileControl(control);
      });
      return;
    }
    if (!force) return;
    Array.prototype.forEach.call(controls, function (control) {
      if (control.type === 'hidden') return;
      var profileValue = control.getAttribute('data-didar-profile-value');
      if (profileValue === null || profileValue === '') return;
      var source = control.getAttribute('data-didar-profile-source');
      if (control.matches && control.matches('[data-didar-upload]')) return;
      var candidates = form.querySelectorAll('[data-didar-profile-source="' + source.replace(/"/g, '\\"') + '"]');
      if (control.type === 'radio' || control.type === 'checkbox') {
        var target = null;
        Array.prototype.forEach.call(candidates, function (candidate) {
          if (candidate.type === 'radio' || candidate.type === 'checkbox') {
            candidate.checked = false;
            candidate.removeAttribute('data-didar-profile-origin');
            if (candidate.getAttribute('data-didar-profile-value') === profileValue) target = candidate;
          }
        });
        if (target) { target.checked = true; target.setAttribute('data-didar-profile-origin', '1'); clearLiveError(target); }
      } else {
        control.value = profileValue;
        var dateTargetId = control.getAttribute('data-didar-date-target');
        var dateTarget = dateTargetId ? document.getElementById(dateTargetId) : null;
        if (dateTarget) {
          dateTarget.value = profileValue;
          dateTarget.setAttribute('data-didar-profile-origin', '1');
          var displayValue = control.getAttribute('data-didar-profile-display');
          if (displayValue !== null) control.value = displayValue;
        }
        control.setAttribute('data-didar-profile-origin', '1');
        clearLiveError(control);
      }
    });
  }

  function syncFormState(form, forceProfile, changedFieldName) {
    syncProfileMode(form, !!forceProfile);
    syncLocation(form);
		syncConditionalHistory(form, changedFieldName || '');
  }

	function clearConditionalField(field) {
		if (!field) return;
		field.querySelectorAll('input,select,textarea').forEach(function (control) {
			if (control.type === 'radio' || control.type === 'checkbox') control.checked = false;
			else if (control.tagName === 'SELECT' && control.multiple) Array.prototype.forEach.call(control.options, function (option) { option.selected = false; });
			else control.value = '';
			if (control.getAttribute('data-didar-search-enhanced') === '1') syncSearchableSelect(control);
			clearLiveError(control);
		});
	}

	function toPersianDigits(value) {
		return String(value || '').replace(/[0-9]/g, function (digit) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(digit)); });
	}

	function canonicalTime(value) {
		value = normalizeDigitsSafe(String(value || '').trim());
		return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value) ? value : '';
	}

	function enhanceTimePicker(picker) {
		if (!picker || picker._didarTimePickerReady) return;
		var canonical = picker.querySelector('[data-didar-time-canonical]');
		var trigger = picker.querySelector('[data-didar-time-trigger]');
		var popover = picker.querySelector('[data-didar-time-popover]');
		var options = picker.querySelector('[data-didar-time-options]');
		var title = picker.querySelector('[data-didar-time-title]');
		var display = picker.querySelector('[data-didar-time-display]');
		if (!canonical || !trigger || !popover || !options || !title || !display) return;
		picker._didarTimePickerReady = true;
		picker.setAttribute('data-didar-time-picker-initialized', '1');
		var form = picker.closest && picker.closest('[data-didar-form],#post');

		function selectedParts() { var value = canonicalTime(canonical.value); return value ? value.split(':') : ['', '']; }
		function updateDisplay() {
			var value = canonicalTime(canonical.value);
			display.textContent = value ? toPersianDigits(value) : (picker.getAttribute('data-didar-time-placeholder') || 'انتخاب ساعت');
			display.classList.toggle('is-placeholder', !value);
		}
		function close(restoreFocus) {
			popover.hidden = true; trigger.setAttribute('aria-expanded', 'false'); picker.classList.remove('is-open');
			if (form) form.classList.remove('didar-time-picker-open');
			if (restoreFocus) trigger.focus();
		}
		function optionButton(value, selected, attribute, displayValue) {
			var button = document.createElement('button');
			button.type = 'button'; button.className = 'didar-time-picker__option' + (selected ? ' is-selected' : '');
			button.textContent = toPersianDigits(undefined === displayValue ? value : displayValue); button.setAttribute(attribute, value); button.setAttribute('aria-pressed', selected ? 'true' : 'false');
			return button;
		}
		function renderHours() {
			var selected = selectedParts()[0]; title.textContent = 'انتخاب ساعت'; options.textContent = ''; options.classList.remove('is-minutes');
			for (var hour = 0; hour < 24; hour += 1) { var value = String(hour).padStart(2, '0'); options.appendChild(optionButton(value, value === selected, 'data-didar-time-hour', String(hour))); }
		}
		function renderMinutes(hour) {
			var selected = selectedParts()[1]; title.textContent = 'انتخاب دقیقه'; options.textContent = ''; options.classList.add('is-minutes');
			var back = document.createElement('button'); back.type = 'button'; back.className = 'didar-time-picker__back'; back.textContent = 'بازگشت'; back.setAttribute('data-didar-time-back', '1'); options.appendChild(back);
			var step = Math.max(1, Math.floor(Number(picker.getAttribute('data-didar-time-step') || '60') / 60));
			for (var minute = 0; minute < 60; minute += step) { var value = String(minute).padStart(2, '0'); options.appendChild(optionButton(value, value === selected, 'data-didar-time-minute')); }
			options.setAttribute('data-didar-time-selected-hour', hour);
		}
		function open() {
			document.querySelectorAll('[data-didar-time-picker].is-open').forEach(function (other) { if (other !== picker && other._didarCloseTimePicker) other._didarCloseTimePicker(false); });
			popover.hidden = false; trigger.setAttribute('aria-expanded', 'true'); picker.classList.add('is-open'); if (form) form.classList.add('didar-time-picker-open'); renderHours();
			var selected = options.querySelector('.is-selected') || options.querySelector('[data-didar-time-hour]'); if (selected) selected.focus();
		}
		picker._didarCloseTimePicker = close; updateDisplay();
		trigger.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); if (popover.hidden) open(); else close(false); });
		picker.addEventListener('click', function (event) {
			event.stopPropagation();
			var closeButton = event.target.closest && event.target.closest('[data-didar-time-close]'); if (closeButton) { event.preventDefault(); close(true); return; }
			var back = event.target.closest && event.target.closest('[data-didar-time-back]'); if (back) { event.preventDefault(); renderHours(); var firstHour = options.querySelector('[data-didar-time-hour]'); if (firstHour) firstHour.focus(); return; }
			var hour = event.target.closest && event.target.closest('[data-didar-time-hour]'); if (hour) { event.preventDefault(); renderMinutes(hour.getAttribute('data-didar-time-hour')); var firstMinute = options.querySelector('.is-selected') || options.querySelector('[data-didar-time-minute]'); if (firstMinute) firstMinute.focus(); return; }
			var minute = event.target.closest && event.target.closest('[data-didar-time-minute]');
			if (minute) { event.preventDefault(); canonical.value = (options.getAttribute('data-didar-time-selected-hour') || '00') + ':' + minute.getAttribute('data-didar-time-minute'); updateDisplay(); canonical.dispatchEvent(new Event('input', { bubbles: true })); canonical.dispatchEvent(new Event('change', { bubbles: true })); close(false); }
		});
		picker.addEventListener('keydown', function (event) { if ('Escape' === event.key) { event.preventDefault(); close(true); } });
		document.addEventListener('click', function (event) { if (picker.classList.contains('is-open') && !picker.contains(event.target)) close(false); });
	}

	function syncConditionalHistory(form, changedFieldName) {
		if (!form) return;
		form.querySelectorAll('[data-didar-field][data-didar-conditional-on]').forEach(function (field) {
			var parentName = field.getAttribute('data-didar-conditional-on');
			var parent = findFieldControl(form, parentName);
			var expectedValue = field.getAttribute('data-didar-conditional-value') || 'yes';
			var isChoiceParent = parent && (parent.type === 'radio' || parent.type === 'checkbox');
			var parentValue = parent && (!isChoiceParent || parent.checked) ? parent.value : '';
			var active = parentValue === expectedValue;
			var previousParentValue = field.getAttribute('data-didar-conditional-parent-value');
			if (!active && changedFieldName === parentName && previousParentValue === expectedValue) clearConditionalField(field);
			field.hidden = !active;
			field.classList.toggle('didar-conditional-hidden', !active);
			field.setAttribute('aria-hidden', active ? 'false' : 'true');
			field.setAttribute('data-didar-conditional-parent-value', parentValue);
		});
	}

  function syncLocation(form) {
    if (!form) return;
    form.querySelectorAll('[data-didar-dependent-on]').forEach(function (control) {
      var parentKey = control.getAttribute('data-didar-dependent-on');
      var parent = findFieldControl(form, parentKey);
      var dependentValue = control.getAttribute('data-didar-dependent-value');
      var isCity = control.getAttribute('data-didar-option-source') === 'iran_cities';
      if (parent && dependentValue && !isCity) {
        if (parent.value !== dependentValue && control.value) {
          control.value = '';
          if (control.getAttribute('data-didar-search-enhanced') === '1') syncSearchableSelect(control);
        }
        setFieldActive(control, parent.value === dependentValue);
      }
      if (isCity) {
        var countryKey = control.getAttribute('data-didar-dependent-country');
        var country = countryKey ? findFieldControl(form, countryKey) : null;
        if (country && country.value !== (control.getAttribute('data-didar-dependent-value') || 'iran')) {
          if (control.value) {
            control.value = '';
            if (control.getAttribute('data-didar-search-enhanced') === '1') syncSearchableSelect(control);
          }
          setFieldActive(control, false);
        } else {
          var province = parent ? parent.value : '';
          var field = fieldFor(control);
          if (field) { field.hidden = false; field.setAttribute('aria-hidden', 'false'); }
          filterDependentCity(control, province);
        }
      }
    });
    form.querySelectorAll('[data-didar-foreign-birth-location]').forEach(function (control) {
      var country = findFieldControl(form, 'birth_country');
      setFieldActive(control, !!country && country.value !== 'iran');
    });
  }

  function enhanceFormControls(root) {
    if (!root || !root.querySelectorAll) return;
	if (root.matches && root.matches('[data-didar-time-picker]')) enhanceTimePicker(root);
	root.querySelectorAll('[data-didar-time-picker]').forEach(enhanceTimePicker);
    if (root.matches && root.matches('select[data-didar-searchable="1"]')) enhanceSelect(root);
    root.querySelectorAll('select[data-didar-searchable="1"]').forEach(enhanceSelect);
    root.querySelectorAll('[data-didar-form],#post').forEach(function (form) { syncFormState(form, false, ''); });
    if (root.matches && root.matches('[data-didar-form],#post')) syncFormState(root, false, '');
  }

  window.DidarFormInputRules = {
    normalizeDigits: normalizeDigitsSafe,
    sanitizeNationalId: sanitizeNationalId,
    sanitizePassport: sanitizePassport,
    validateField: validateField,
    validateFiles: validateFiles,
    clearFilePreviews: clearFilePreviews,
    getUploadCountSummary: getUploadCountSummary,
    getPendingFileItems: getPendingFileItems,
    getFileForUploadItem: getFileForUploadItem,
    setUploadItemState: setUploadItemState,
    promoteUploadedFile: promoteUploadedFile,
    releaseUploadItem: releaseUploadItem,
    enhanceFormControls: enhanceFormControls,
    destroySearchableSelect: destroySearchableSelect
  };

  document.addEventListener('input', function (event) {
    var input = event.target.closest && event.target.closest('input,select,textarea');
    if (input) {
      var inputForm = input.closest && input.closest('[data-didar-form],#post');
      if (inputForm && requestForMode(inputForm) === 'other') input.removeAttribute('data-didar-profile-origin');
      if (input.matches('[data-didar-semantic="national_id"],[data-didar-semantic="passport_number"]')) sanitize(input);
      if (input.getAttribute('inputmode') === 'tel') input.value = normalizeDigitsSafe(input.value).replace(/[^0-9+()\-\s]/g, '');
      if (input.type === 'number') input.value = normalizeDigitsSafe(input.value).replace(/[^0-9.\-]/g, '');
    }
    if (event.target.matches && event.target.matches('input,select,textarea')) validateField(event.target);
  });

  document.addEventListener('change', function (event) {
    if (event.target.matches && event.target.matches('input[type="file"]')) validateFiles(event.target.closest('[data-didar-upload]'));
    if (event.target.matches && event.target.matches('input,select,textarea')) validateField(event.target);
    var form = event.target.closest && event.target.closest('[data-didar-form],#post');
    if (form) {
      var changedField = event.target.closest && event.target.closest('[data-didar-field]');
      syncFormState(form, event.target.matches && event.target.matches('[data-didar-request-for]'), changedField ? changedField.getAttribute('data-didar-field') : '');
    }
  });
  document.addEventListener('blur', function (event) { if (event.target.matches && event.target.matches('input,select,textarea')) validateField(event.target); }, true);
  document.addEventListener('click', function (event) { var remove = event.target.closest && event.target.closest('.didar-remove-preview'); if (remove) { event.preventDefault(); removePreview(remove); } });
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.querySelector || !form.matches('[data-didar-form],#post')) return;
    var valid = true;
    form.querySelectorAll('input,select,textarea').forEach(function (control) { if (!validateField(control)) valid = false; });
    form.querySelectorAll('[data-didar-upload]').forEach(function (wrapper) {
      var input = wrapper.querySelector('input[type="file"]');
      if (input && input.files.length && !validateFiles(wrapper)) valid = false;
    });
    if (!valid) {
      event.preventDefault();
      var first = form.querySelector('.didar-field--live-error');
      if (first) { var focusable = first.querySelector('input,select,textarea'); if (focusable) focusable.focus(); }
    }
  }, true);

  document.addEventListener('DOMContentLoaded', function () {
    enhanceFormControls(document);
    window.addEventListener('beforeunload', function () {
      document.querySelectorAll('[data-didar-upload] .didar-upload-item').forEach(releaseUploadItem);
    });
    if (document.body && window.MutationObserver) {
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) enhanceFormControls(node);
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  });
}());
