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
}());
