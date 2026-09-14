# نگاشت مدارک پروفایل به Person دیدار

## محدوده

پنج مدرک تصویری شورت‌کد `[didar_profile_form]` در تنظیمات موجود «نگاشت اطلاعات کاربر به مخاطب دیدار» قابل نگاشت شدند:

| برچسب | کلید WordPress | مقصد |
| --- | --- | --- |
| تصویر روی کارت ملی | `national_card_front` | Didar Person Custom Field |
| تصویر پشت کارت ملی | `national_card_back` | Didar Person Custom Field |
| تصویر صفحه اصلی پاسپورت | `passport_main_page` | Didar Person Custom Field |
| عکس پرسنلی | `personal_photo` | Didar Person Custom Field |
| تصویر صفحه اول شناسنامه | `birth_certificate_first_page` | Didar Person Custom Field |

تنظیمات فقط Field Key مقصد را نگه می‌دارد و در export/import نیز فقط همین پیکربندی منتقل می‌شود. فایل‌های کاربر، URLها و شناسه‌های User یا Person قابل انتقال نیستند.

## Payload و lifecycle

Mapper مرکزی Person برای هر نگاشت، فایل نهایی فعلی را از catalog کاربر می‌خواند و URL حل‌شدهٔ سرویس فایل را در `Fields` قرار می‌دهد. در حالت direct، این URL همان URL canonical storage است. در حالت secure، URL دانلود به مجوز WordPress وابسته است و برای مصرف CRM مناسب نیست؛ بنابراین مقدار فایل ارسال نمی‌شود تا امنیت مسیر دانلود تغییر نکند.

جایگزینی مدرک، Person موجود را با URL جدید به‌روزرسانی می‌کند. حذف صریح، clear intent همان کلید را تا sync موفق نگه می‌دارد تا فیلد Person قدیمی خالی شود. این رفتار به Person متصل به `_didar_person_id` متکی است و Person تکراری نمی‌سازد. reverse sync از Didar به فایل خصوصی WordPress وجود ندارد و این نگاشت outbound-only است.

مدارک در Deal یا Case قرار نمی‌گیرند و مسیر فایل، شناسهٔ رکورد فایل، metadata یا binary در payload Person استفاده نمی‌شود.
