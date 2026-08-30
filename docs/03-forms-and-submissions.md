# فرم‌ها و درخواست‌ها

تمام فیلدهای معنایی نوع `date` به‌صورت خودکار تاریخ‌نگار جلالی می‌گیرند. مقدار انتخاب‌شده قبل از اعتبارسنجی به `YYYY-MM-DD` میلادی تبدیل می‌شود و نگاشت Deal همین مقدار canonical را دریافت می‌کند.

هر فیلد فرم می‌تواند به‌صورت مستقل از گزینه «مقدار پیش‌فرض» استفاده کند. مقدار پروفایل فقط هنگام نمایش اولیه فرم وارد می‌شود؛ مقدار ارسال‌شده کاربر پس از خطا یا ویرایش جایگزین آن است.

فرم‌ها در `Didar_Form_Registry` تعریف شده‌اند؛ renderer و validator ورودی مرورگر را مرجع نمی‌دانند. نوع‌های فعلی `consultation`، `embassy_appointment`، `traveler_evaluation`، `complaint_suggestion` و `visa_request` هستند.

ثبت در `Didar_Submission_Service` یک پست `didar_submission` با `post_author` کاربر جاری می‌سازد. `_didar_form_type` نوع و `_didar_fields` مقادیر اعتبارسنجی‌شده را نگه می‌دارد. ویرایش فرانت‌اند فقط برای درخواست قابل‌دسترسی و قابل‌ویرایش انجام می‌شود؛ درخواست تکمیل‌شده قابل‌ویرایش نیست.

`Didar_Request_Search` عبارت جست‌وجو را تا ۱۰۰ نویسه پاک‌سازی می‌کند. فهرست فرانت‌اند مالکیت را در query اعمال و تعداد هر صفحه را از تنظیمات، بین ۱ تا ۱۰۰، می‌گیرد.
# Global identity validation

National ID fields use the central semantic validator and preserve leading zeroes as strings. Passport numbers use the canonical format `[A-Za-z][0-9]{8}`. Visa Request uses separate `first_name` and `last_name`; legacy `full_name` remains readable.
# حفظ وضعیت فرم و قوانین ورودی

renderer مقدارهای ارسال‌شده در درخواست ناموفق را پیش از مقدار ذخیره‌شده و مقدار پیش‌فرض پروفایل بازنمایی می‌کند. ورودی‌های معنایی `national_id` و `passport_number` با JavaScript مشترک هنگام input پاک‌سازی می‌شوند، اما اعتبارسنجی سمت سرور مرجع نهایی است. مقدار نمایش Jalali و مقدار canonical مخفی نیز در خطا حفظ می‌شوند.
