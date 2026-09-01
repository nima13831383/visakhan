# رفع اشکال

> جزئیات رفتار Case همراهان در [docs/21-companion-cases.md](21-companion-cases.md) آمده است.

## درخواست ثبت شد اما Deal ساخته نشد

`_didar_sync_state` و سپس `sync_queue_persisted`، `sync_worker_started`/`sync_execute`، `settings_check`/mapping، `person_conflict` و `api_request`/`api_response` را با trace بررسی کنید. API key، owner پیش‌فرض و pipeline/stage فرم را تأیید کنید؛ DB را مستقیم ویرایش نکنید.

## Person همگام نمی‌شود

وجود موبایل canonical (از جمله meta Digits)، owner پیش‌فرض، `_didar_person_sync_state` و خطاهای `didar_mobile_missing` یا conflict را بررسی کنید. state pending با worker بازیابی می‌شود.

## Deal تکراری، webhook ردشده یا دانلود ممنوع

`_didar_deal_id` و custom field شناسهٔ submission، URL/secret webhook یا (فقط legacy فعال) header `x-didar-webhook-token`، format JSON، rate limit، event ID تکراری، ورود، مالکیت درخواست، record فایل و nonce را بررسی کنید. secret واقعی را در ticket عمومی نگذارید.

## Worker یا import

WP-Cron، scheduleهای دو hook و diagnostic `sync_schedule_failed` را بررسی کنید. برای import ابتدا preview، version و allowlist را کنترل کنید.
