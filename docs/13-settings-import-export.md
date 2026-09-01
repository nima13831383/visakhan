# انتقال تنظیمات

> جزئیات رفتار Case همراهان در [docs/21-companion-cases.md](21-companion-cases.md) آمده است.

نگاشت‌های `didar_form_field_defaults` و نگاشت Custom Field پروفایل قابل انتقال‌اند. خروجی فقط پیکربندی را شامل می‌شود و هیچ مقدار واقعی از `usermeta`، به‌خصوص تاریخ تولد یا کد ملی، صادر نمی‌کند.

`Didar_Settings_Transfer` export/import پیکربندی portable را با allowlist انجام می‌دهد و preview پیش از apply دارد. حالت‌های `merge` و `replace` پشتیبانی می‌شوند و پیش از اعمال نسخهٔ پشتیبان تنظیمات می‌سازند.

| نوع | Export/Import |
|---|---|

نگاشت `didar_form_field_defaults` به‌صورت portable و allowlist‌شده منتقل می‌شود؛ مقدار واقعی پروفایل هیچ کاربری صادر نمی‌شود.
| API key و webhook secret | خیر |
| cacheهای runtime | خیر |
| state همگام‌سازی، Person ID و Deal ID | خیر |
| workflow و mappingهای قابل‌حمل | بله، با اعتبارسنجی |

نگاشت کاربران در export به descriptor شامل login/email و شناسهٔ Didar تبدیل می‌شود و import فقط user موجود را ابتدا با login و سپس email resolve می‌کند؛ user نمی‌سازد. انتقال Administrator تخصیص نمی‌دهد و خارج از allowlist در `wp_options` یا usermeta نمی‌نویسد.
