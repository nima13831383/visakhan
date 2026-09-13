(function () {
    'use strict';

    document.querySelectorAll('.didar-profile-form').forEach(function (profile) {
        var input = profile.querySelector('#didar-profile-image');
        var avatar = profile.querySelector('.didar-profile-avatar');
        var image = avatar ? avatar.querySelector('img') : null;
        var objectUrl = '';

        if (!input || !avatar) {
            return;
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
                return;
            }

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }

            objectUrl = URL.createObjectURL(file);

            if (!image) {
                image = document.createElement('img');
                image.alt = 'تصویر پروفایل';
                image.width = 120;
                image.height = 120;
                image.className = 'didar-profile-avatar__image';
                avatar.textContent = '';
                avatar.appendChild(image);
            }

            image.src = objectUrl;
        });

        window.addEventListener('beforeunload', function () {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        });
    });

    function profileFileRow(wrapper, data, previewItem) {
        var list = wrapper.querySelector('.didar-uploaded-files');
        var rules = window.DidarFormInputRules;
        if (!list || !rules || !rules.promoteUploadedFile) return;
        list.querySelectorAll('[data-didar-file]').forEach(function (item) { if (rules.releaseUploadItem) rules.releaseUploadItem(item); item.remove(); });
        rules.promoteUploadedFile(wrapper, previewItem, data, { includeHidden: false, previewUrl: data.download_url, removeLabel: 'حذف' });
    }

    function uploadProfileItem(wrapper, item, input, button, config) {
        var rules = window.DidarFormInputRules;
        var file = rules && rules.getFileForUploadItem ? rules.getFileForUploadItem(item) : null;
        if (!file) return Promise.resolve(false);
        if (input) input.value = '';
        var formData = new FormData();
        formData.append('action', 'didar_upload_profile_document');
        formData.append('nonce', config.uploadNonce || '');
        formData.append('field', wrapper.getAttribute('data-field') || '');
        formData.append('file', file);
        if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'uploading', (config.messages && config.messages.uploading) || 'در حال بارگذاری…');
        button.disabled = true;
        return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData }).then(function (response) { return response.json(); }).then(function (json) {
            if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'بارگذاری فایل انجام نشد.');
            profileFileRow(wrapper, json.data, item);
            return true;
        }).catch(function (error) {
            if (rules && rules.setUploadItemState) rules.setUploadItemState(item, 'failed', '✕ بارگذاری نشد: ' + error.message);
            return false;
        }).finally(function () { button.disabled = false; });
    }

    document.addEventListener('change', function (event) {
        var input = event.target.matches && event.target.matches('[data-didar-profile-upload] input[type="file"]') ? event.target : null;
        if (!input) return;
        var wrapper = input.closest('[data-didar-profile-upload]');
        var config = window.didarProfileConfig || {};
        if (!wrapper || !input.files || !input.files.length) return;
        if (wrapper._didarUploadChangeQueued) return;
        wrapper._didarUploadChangeQueued = true;
        window.setTimeout(function () {
            wrapper._didarUploadChangeQueued = false;
            var rules = window.DidarFormInputRules;
            var items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
            if (rules && wrapper.getAttribute('data-client-valid') !== '1') { input.value = ''; return; }
            items = rules && rules.getPendingFileItems ? rules.getPendingFileItems(wrapper) : [];
            if (items.length) uploadProfileItem(wrapper, items[0], input, input, config);
        }, 0);
    });

    document.addEventListener('click', function (event) {
        var removeButton = event.target.closest && event.target.closest('[data-didar-profile-upload] .didar-remove-upload');
        var config = window.didarProfileConfig || {};
        var retryButton = event.target.closest && event.target.closest('[data-didar-profile-upload] .didar-retry-upload');
        if (retryButton) {
            event.preventDefault();
            var retryWrapper = retryButton.closest('[data-didar-profile-upload]');
            var retryInput = retryWrapper && retryWrapper.querySelector('input[type="file"]');
            uploadProfileItem(retryWrapper, retryButton.closest('.didar-upload-item'), retryInput, retryButton, config);
            return;
        }
        if (removeButton) {
            event.preventDefault();
            var removeWrapper = removeButton.closest('[data-didar-profile-upload]');
            var removeData = new FormData();
            removeData.append('action', 'didar_remove_profile_document');
            removeData.append('nonce', config.removeNonce || '');
            removeData.append('field', removeWrapper.getAttribute('data-field') || '');
            removeButton.disabled = true;
            fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: removeData }).then(function (response) { return response.json(); }).then(function (json) {
                if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'حذف فایل انجام نشد.');
                var item = removeButton.closest('[data-didar-file]');
                if (item) { if (window.DidarFormInputRules && window.DidarFormInputRules.releaseUploadItem) window.DidarFormInputRules.releaseUploadItem(item); item.remove(); }
            }).catch(function () { removeButton.disabled = false; }).finally(function () { removeButton.disabled = false; });
        }
    });
}());
