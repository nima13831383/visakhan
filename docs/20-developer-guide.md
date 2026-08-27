# راهنمای توسعه‌دهنده

برای فرم جدید، definition را فقط در `Didar_Form_Registry` اضافه کنید؛ `Didar_Field_Renderer`، `Didar_Validator` و `Didar_Submission_Service` همان registry را مصرف می‌کنند. داده‌های مرجع مشترک در `Didar_Reference_Data` قرار می‌گیرند.

برای mapping Didar از `Didar_Field_Mapper` و settingهای validate‌شده استفاده کنید؛ برای status/pipeline از `Didar_Workflow_Manager`. diagnostic را با `Didar_Logger::log()` و context شامل `entity_type`، `local_id` و در sync، `trace_id` بنویسید. worker جدید باید schedule، idempotency، bounded batch و cleanup/deactivation روشن داشته باشد.

برای تنظیم portable، allowlist `Didar_Settings_Transfer` را تغییر دهید؛ secret، runtime state، usermeta و option دلخواه را وارد نکنید. Checklist امنیت: capability جدا از nonce، ownership، unslash/sanitize/validate، escaping، عدم اعتماد به ID/مسیر مرورگر، و عدم log کردن secret/PII.
