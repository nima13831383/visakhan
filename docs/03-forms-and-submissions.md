# فرم‌ها و درخواست‌ها

فرم‌ها در `Didar_Form_Registry` تعریف شده‌اند؛ renderer و validator ورودی مرورگر را مرجع نمی‌دانند. نوع‌های فعلی `consultation`، `embassy_appointment`، `traveler_evaluation`، `complaint_suggestion` و `visa_request` هستند.

ثبت در `Didar_Submission_Service` یک پست `didar_submission` با `post_author` کاربر جاری می‌سازد. `_didar_form_type` نوع و `_didar_fields` مقادیر اعتبارسنجی‌شده را نگه می‌دارد. ویرایش فرانت‌اند فقط برای درخواست قابل‌دسترسی و قابل‌ویرایش انجام می‌شود؛ درخواست تکمیل‌شده قابل‌ویرایش نیست.

`Didar_Request_Search` عبارت جست‌وجو را تا ۱۰۰ نویسه پاک‌سازی می‌کند. فهرست فرانت‌اند مالکیت را در query اعمال و تعداد هر صفحه را از تنظیمات، بین ۱ تا ۱۰۰، می‌گیرد.
