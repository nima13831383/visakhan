# راهنمای توسعه‌دهنده

> جزئیات رفتار Case همراهان در [docs/21-companion-cases.md](21-companion-cases.md) آمده است.

برای افزودن تاریخ جدید، فقط فیلد را با نوع `date` تعریف کنید. renderer و `Didar_Date_Service` نمایش جلالی، تبدیل ورودی و مقدار canonical `YYYY-MM-DD` را به‌صورت مشترک انجام می‌دهند؛ برای فرم جداگانه datepicker نسازید.

برای مقداردهی اولیه فرم از `Didar_User_Profile_Value_Catalog` و تنظیم `didar_form_field_defaults` استفاده کنید. تقدم رندر چنین است: مقدار ارسال‌شده، مقدار ذخیره‌شده در ویرایش، مقدار پروفایل، پیش‌فرض تعریف فرم و در پایان مقدار خالی. کاربر واردنشده مقدار پروفایل دریافت نمی‌کند.

برای فرم جدید، definition را فقط در `Didar_Form_Registry` اضافه کنید؛ `Didar_Field_Renderer`، `Didar_Validator` و `Didar_Submission_Service` همان registry را مصرف می‌کنند. داده‌های مرجع مشترک در `Didar_Reference_Data` قرار می‌گیرند.

در پنل تنظیمات، هر فرم و فیلد فقط یک‌بار در جدول یکپارچهٔ تنظیمات فیلد نمایش داده می‌شود. renderer پنل از کاتالوگ مرکزی مقدارهای پروفایل استفاده می‌کند و ذخیره‌سازی همچنان در کلیدهای مستقل موجود انجام می‌شود.

برای mapping Didar از `Didar_Field_Mapper` و settingهای validate‌شده استفاده کنید؛ برای status/pipeline از `Didar_Workflow_Manager`. diagnostic را با `Didar_Logger::log()` و context شامل `entity_type`، `local_id` و در sync، `trace_id` بنویسید. worker جدید باید schedule، idempotency، bounded batch و cleanup/deactivation روشن داشته باشد.

برای تنظیم portable، allowlist `Didar_Settings_Transfer` را تغییر دهید؛ secret، runtime state، usermeta و option دلخواه را وارد نکنید. Checklist امنیت: capability جدا از nonce، ownership، unslash/sanitize/validate، escaping، عدم اعتماد به ID/مسیر مرورگر، و عدم log کردن secret/PII.
# Semantic fields and repeater files
Use `semantic` metadata for identity validation. Repeater files must retain the row path and never fall back to main-applicant flat keys.
# قوانین ورودی سمت کاربر

فایل `assets/js/form-input-rules.js` روی marker معنایی renderer کار می‌کند و برای paste، mobile input و ردیف‌های dynamic repeater از رویداد `input` استفاده می‌کند. پس از خطای اعتبارسنجی، مقادیر خام ارسال‌شده با escape استاندارد دوباره render می‌شوند؛ مقدار `input[type=file]` قابل بازگردانی نیست و فقط فایل staged نمایش داده می‌شود.
