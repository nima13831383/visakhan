# گردش‌کار و وضعیت‌ها

`Didar_Workflow_Manager` برای هر `form_type` workflow شامل `pipeline_id` و statusها را می‌خواند. هر status کلید پایدار، label، `stage_id`، ترتیب و امکان `is_default` دارد. mapping معکوس pipeline/stage نیز برای webhook وجود دارد.

وضعیت درخواست، که از وضعیت داخلی پیشین به‌عنوان مقدار canonical استفاده می‌کند، در `_didar_internal_status` نگهداری می‌شود و در `_didar_status` نیز برای سازگاری آینه می‌شود. `_didar_public_status` و `_didar_public_note` فقط دادهٔ قدیمی هستند و دیگر منبع فعال workflow نیستند. یادداشت فعال workflow در `_didar_internal_note` نگهداری می‌شود و از `_didar_admin_note` برای درخواست‌های قدیمی fallback می‌گیرد. مسئول در `_didar_assigned_user_id` است؛ امکان تخصیص به capability نیاز دارد. اگر workflow همان فرم ناقص باشد، sync به fallback قدیمی نمی‌رود و pending/retry می‌ماند.

در رابط کاربری، وضعیت درخواست با برچسب `وضعیت درخواست` به مالک و اپراتور نمایش داده می‌شود و همان مقدار به Pipeline/Stage تنظیم‌شدهٔ همان فرم نگاشت می‌شود. تغییرات جدید با رویدادهای `request_status_changed` و `request_note_changed` ثبت می‌شوند؛ رویدادهای قدیمی برای خواندن تاریخچه حفظ شده‌اند.
