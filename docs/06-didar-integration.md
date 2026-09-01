# یکپارچگی Didar CRM

> جزئیات رفتار Case همراهان در [docs/21-companion-cases.md](21-companion-cases.md) آمده است.

برای Case همراهان، `PipelineStageId` و `DealId` استفاده می‌شوند؛ `CaseCategoryId` اختیاری است و هنگام خالی بودن در payload ارسال نمی‌شود.

تاریخ تولد و کد ملی فقط در صورت تنظیم شناسه Custom Field در نگاشت پروفایل به Person ارسال می‌شوند. این نگاشت از نگاشت فیلد فرم به Deal مستقل است و شناسه Person موجود را تغییر نمی‌دهد.

`Didar_Api_Client` درخواست‌های POST به `/api/contact/save`، `/api/contact/getbyphonenumber`، `/api/deal/search_v2` و `/api/deal/save_v2` ارسال می‌کند. کلید API در `didar_settings` است؛ آن را در مستندات یا log عمومی قرار ندهید.

## Person

فیلدهای «تاریخ تولد» و «کد ملی» از متاهای `_didar_birth_date` و `_didar_national_id` خوانده می‌شوند و فقط در صورت تنظیم Custom Field به Person ارسال می‌گردند. این نگاشت از نگاشت فیلدهای فرم به Deal مستقل است.

هویت محلی WordPress User است و شناسهٔ خارجی در user meta `_didar_person_id` پایدار می‌ماند. sync به owner پیش‌فرض Didar و موبایل نیاز دارد. lookup دقیق موبایل و بررسی شناسهٔ ذخیره‌شده پیش از create/update انجام می‌شود؛ ابهام Person، ایجاد Deal را متوقف می‌کند.

## Deal

شناسهٔ اصلی `_didar_deal_id` است. در نبود آن، بازیابی تنها از custom field شناسهٔ submission WordPress انجام می‌شود؛ matching با نام Deal، ایمیل، موبایل یا Person به‌عنوان هویت Deal اجرا نشده است. Deal فقط بعد از تأیید صفر match ساخته می‌شود. نگاشت فیلد، owner، pipeline/stage، وضعیت و لینک فایل از تنظیمات و `Didar_Field_Mapper` می‌آید.

یادداشت متقاضی (`_didar_shared_note`) برای فرم‌های `embassy_appointment`، `traveler_evaluation` و `visa_request` می‌تواند مستقل از فیلدهای Person، Case و سایر یادداشت‌ها به یک Deal Custom Field نگاشت شود. برای `consultation` و `complaint_suggestion` نگاشت یا payload جدیدی تولید نمی‌شود. فیلد مورد انتظار در Didar از نوع «متن بلند» است.

محدودیت lifecycle: تغییر به trash یا حذف درخواست در WordPress ثبت می‌شود، اما افزونه برای حذف/archive Deal در Didar درخواست remote نمی‌فرستد؛ مسیر API امن و تأییدشده‌ای برای آن در پیاده‌سازی فعلی استفاده نشده است.
# Nested companion data
Companion documents remain in WordPress and are associated with `companions.<index>.<field>`; binaries are not uploaded to Didar.
