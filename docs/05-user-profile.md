# پروفایل کاربر

تاریخ تولد در رابط کاربری با تاریخ‌نگار جلالی نمایش داده می‌شود، اما مقدار پنهان و ذخیره‌شده همچنان Gregorian ISO است. تغییر ندادن تاریخ، مقدار canonical قبلی را حفظ می‌کند.

فیلدهای «تاریخ تولد» و «کد ملی» به‌ترتیب در متاهای `_didar_birth_date` با قالب canonical `YYYY-MM-DD` و `_didar_national_id` به‌صورت رشته ذخیره می‌شوند. کد ملی فقط رقم می‌پذیرد و صفرهای ابتدایی آن حفظ می‌شود.

`Didar_User_Profile` فرم پروفایل کاربر جاری را ارائه می‌کند. وضعیت هر فیلد (`editable`، `readonly` یا `disabled`) از `profile_field_states` می‌آید: `first_name`، `last_name`، `display_name`، `email`، `gender`، `mobile` و `profile_image`.

تبدیل تاریخ در `Didar_Date_Service` انجام می‌شود؛ `IntlCalendar` در صورت وجود ترجیح دارد و در غیر این صورت fallback داخلی فعال است، بنابراین نصب یا تغییر تنظیمات سرور لازم نیست.

`first_name` و `last_name` در WordPress canonical هستند؛ فقط اگر خالی باشند، mapper به‌ترتیب از `billing_first_name` و `billing_last_name` (سازگاری Digits/WooCommerce) می‌خواند. موبایل ابتدا از `digits_phone` و سپس از ترکیب `digt_countrycode` و `digits_phone_no` خوانده می‌شود؛ افزونه این کلیدها را نمی‌نویسد. اگر موبایل readonly باشد، تغییر آن از فرم پروفایل پذیرفته نمی‌شود.

فرم `display_name` را ویرایش می‌کند، نه `nickname`. هنگام `wp_update_user`، `nickname` از مقدار موجود، سپس display name و در نهایت login تعیین می‌شود؛ `user_login` تغییر نمی‌کند. `user_nicename` نامعتبر قدیمی با `user-{id}` ترمیم می‌شود.

تصویر فقط در حالت editable، با فرمت JPG/PNG/GIF/WebP و سقف ۵MB در Media Library ذخیره می‌شود. پس از ذخیرهٔ پروفایل، Person sync فوری تلاش و در خطا برای retry صف می‌شود.

بخش «مدارک تصویری» در رابط `[didar_profile_form]` پنج کلید canonical دارد: `national_card_front`، `national_card_back`، `passport_main_page`، `personal_photo` و `birth_certificate_first_page`. در بخش مدیریت «نگاشت اطلاعات کاربر به مخاطب دیدار» می‌توان برای هر کلید، Field Key یک Custom Field متنی از Person را تنظیم کرد.

در حالت وجود مدرک نهایی و فعال بودن حالت فایل مستقیم، sync کاربر نشانی URL حل‌شدهٔ سرویس فایل را در فیلد Person می‌فرستد. شناسهٔ فایل، مسیر محلی، metadata و محتوای binary ارسال نمی‌شود. حالت امن که URL آن به نشست WordPress وابسته است برای Person قابل استفادهٔ CRM نیست و در آن حالت این فیلد بدون تضعیف مجوزها ارسال نمی‌شود. جایگزینی مدرک، Person موجود را با نشانی جدید به‌روزرسانی می‌کند؛ حذف صریح مدرک، فیلد نگاشت‌شده را در sync بعدی خالی می‌کند. این مسیر outbound-only است و از Didar به WordPress فایل دانلود نمی‌کند.
