# انتقال تنظیمات

`Didar_Settings_Transfer` export/import پیکربندی portable را با allowlist انجام می‌دهد و preview پیش از apply دارد. حالت‌های `merge` و `replace` پشتیبانی می‌شوند و پیش از اعمال نسخهٔ پشتیبان تنظیمات می‌سازند.

| نوع | Export/Import |
|---|---|
| API key و webhook secret | خیر |
| cacheهای runtime | خیر |
| state همگام‌سازی، Person ID و Deal ID | خیر |
| workflow و mappingهای قابل‌حمل | بله، با اعتبارسنجی |

نگاشت کاربران در export به descriptor شامل login/email و شناسهٔ Didar تبدیل می‌شود و import فقط user موجود را ابتدا با login و سپس email resolve می‌کند؛ user نمی‌سازد. انتقال Administrator تخصیص نمی‌دهد و خارج از allowlist در `wp_options` یا usermeta نمی‌نویسد.
