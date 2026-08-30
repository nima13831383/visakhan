# پایگاه‌داده و ذخیره‌سازی

`_didar_birth_date` مقدار تاریخ را به‌شکل `YYYY-MM-DD` و `_didar_national_id` کد ملی را به‌شکل رشته نگه می‌دارد. برای کاربران موجود مهاجرت اجباری لازم نیست؛ مقدار غایب خالی در نظر گرفته می‌شود.

| کلید/محل | موجودیت | کاربرد |
|---|---|---|

`_didar_birth_date` با قالب canonical `YYYY-MM-DD` و `_didar_national_id` به‌صورت رشته ذخیره می‌شود؛ مهاجرت اجباری لازم نیست.
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
# Repeater storage
File IDs are stored inside each companion row and file records use `companions.<index>.<field>` as the logical field key. No migration is required.
