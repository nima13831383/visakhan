# کاربران و کنترل دسترسی

| عملیات | مشتری | `didar_colleague` | `didar_broker` | Administrator |
|---|---:|---:|---:|---:|
| ثبت/دیدن درخواست خود | بله، پس از ورود | بله | بله | بله |
| پنل Didar | خیر | خیر | بله | بله |
| درخواست‌های تیم | خیر | خیر | بله | بله |
| ویرایش فرانت‌اندِ درخواست خود | تا پیش از وضعیت عمومی `completed` | تا پیش از `completed` | با `didar_edit_requests` و `edit_post` | با همان capabilityها |
| وضعیت داخلی، مسئول و یادداشت داخلی | خیر | فقط مشاهدهٔ اختیاری history/workflow خود؛ بدون تغییر | بله | بله |
| تنظیمات | خیر | خیر | بله | بله |

کنترل‌ها capabilityمحورند. `didar_colleague` فقط `read`، `didar_colleague_access`، `didar_view_own_internal_workflow` و `didar_view_own_request_history` می‌گیرد؛ مشاهدهٔ workflow/history داخلیِ درخواست خودش نیز منوط به `colleague_can_view_internal_history` است. `didar_broker` capabilityهای مدیریتی Didar را می‌گیرد. افزونه کاربر WordPress را از webhook Person نمی‌سازد و هیچ کاربری را Administrator نمی‌کند.
