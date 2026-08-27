# پایگاه‌داده و ذخیره‌سازی

| کلید/محل | موجودیت | کاربرد |
|---|---|---|
| `didar_submission` | `wp_posts` | درخواست |
| `_didar_form_type` | post meta | نوع فرم |
| `_didar_fields` | post meta | دادهٔ فرم |
| `_didar_deal_id` | post meta | شناسهٔ Deal |
| `_didar_sync_state` | post meta | state، trace، attempts و زمان‌ها |
| `_didar_assigned_user_id` | post meta | مسئول |
| `_didar_internal_status` / `_didar_public_status` | post meta | وضعیت‌ها |
| `_didar_person_id` | user meta | شناسهٔ Person |
| `_didar_person_sync_state` | user meta | وضعیت Person sync |
| `didar_settings` | option | تنظیمات |
| `didar_seen_webhooks` | option | dedupe webhook |

جدول event log و جدول فایل توسط schema manager ایجاد/verify می‌شوند. منبع authoritative برای هویت کاربر، WordPress User است؛ Person/Deal صرفاً شناسهٔ خارجی پایدار دارند. قفل sync و cacheهای Didar optionهای runtime‌اند.
