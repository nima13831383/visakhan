# یکپارچگی Didar CRM

`Didar_Api_Client` درخواست‌های POST به `/api/contact/save`، `/api/contact/getbyphonenumber`، `/api/deal/search_v2` و `/api/deal/save_v2` ارسال می‌کند. کلید API در `didar_settings` است؛ آن را در مستندات یا log عمومی قرار ندهید.

## Person

هویت محلی WordPress User است و شناسهٔ خارجی در user meta `_didar_person_id` پایدار می‌ماند. sync به owner پیش‌فرض Didar و موبایل نیاز دارد. lookup دقیق موبایل و بررسی شناسهٔ ذخیره‌شده پیش از create/update انجام می‌شود؛ ابهام Person، ایجاد Deal را متوقف می‌کند.

## Deal

شناسهٔ اصلی `_didar_deal_id` است. در نبود آن، بازیابی تنها از custom field شناسهٔ submission WordPress انجام می‌شود؛ matching با نام Deal، ایمیل، موبایل یا Person به‌عنوان هویت Deal اجرا نشده است. Deal فقط بعد از تأیید صفر match ساخته می‌شود. نگاشت فیلد، owner، pipeline/stage، وضعیت و لینک فایل از تنظیمات و `Didar_Field_Mapper` می‌آید.

محدودیت lifecycle: تغییر به trash یا حذف درخواست در WordPress ثبت می‌شود، اما افزونه برای حذف/archive Deal در Didar درخواست remote نمی‌فرستد؛ مسیر API امن و تأییدشده‌ای برای آن در پیاده‌سازی فعلی استفاده نشده است.
