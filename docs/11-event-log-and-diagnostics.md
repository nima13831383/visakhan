# رویدادها و diagnostics

`Didar_Event_Log` تاریخچهٔ افزایشی کسب‌وکاری هر درخواست را در جدول اختصاصی نگه می‌دارد؛ نمونه‌ها `didar_sync_failed`، `request_trashed` و `request_deleted` هستند. `_didar_last_updated_at` برای مرتب‌سازی/نمایش به‌روز می‌شود.

`Didar_Logger` diagnostic عملیاتی و correlation/`trace_id` ثبت می‌کند. رویدادهای sync شامل `sync_queue_persisted`، `sync_immediate_started`، `sync_worker_started`، `sync_execute`، `api_request`، `api_response`، `sync_retry_scheduled`، `sync_retry_exhausted` و `sync_permanent_failure` هستند. endpointهای Person payload شخصی را ساختاریافته و بدون مقادیر پروفایل log می‌کنند.

برای «درخواست ذخیره شد اما Deal نیست»، ابتدا state/meta، سپس صف، worker، خطای mapping/Person و پاسخ API را با trace یکسان بررسی کنید.
