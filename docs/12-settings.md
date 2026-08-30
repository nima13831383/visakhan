# تنظیمات

فیلدهای تاریخ در فرم‌ها از تاریخ‌نگار مرکزی جلالی استفاده می‌کنند؛ تنظیمات و مقدار پیش‌فرض تاریخ همچنان با مقدار canonical میلادی `YYYY-MM-DD` کار می‌کند.

کاتالوگ مرکزی `Didar_User_Profile_Value_Catalog` منابع نام، نام خانوادگی، جنسیت، تاریخ تولد، کد ملی، ایمیل و شماره تلفن را برای گزینه «مقدار پیش‌فرض» ارائه می‌کند. این تنظیم به‌صورت مستقل برای هر فرم و هر فیلد ذخیره می‌شود.

تنظیمات اصلی در option `didar_settings` هستند: `didar_api_key`، secret webhook، owner پیش‌فرض، field mappingهای Person/Deal، `didar_broker_user_map`، workflowهای فرم، ID فیلدهای سیستمی، states پروفایل، mode دانلود فایل، pagination و اجازهٔ مشاهدهٔ history داخلی همکار.

تنظیمات فیلدهای فرم اکنون در یک جدول یکپارچه برای هر فرم نمایش داده می‌شود؛ هر ردیف هم‌زمان وضعیت ضروری/اختیاری، نگاشت Didar و «مقدار پیش‌فرض» را مدیریت می‌کند. کلیدهای ذخیره‌سازی قبلی حفظ شده‌اند.

cacheهای pipeline، custom field و user Didar runtime هستند و منبع پیکربندی قابل‌انتقال نیستند. API key، secret، pipeline/stage و user mapping محیط‌وابسته‌اند؛ پیش از تغییر، metadata دیدار را refresh و mapping را اعتبارسنجی کنید.
# Visa settings
`first_name` and `last_name` are independent rows in unified settings for Didar mapping and profile defaults. Repeater child settings remain unsupported by the current top-level settings table.
