# فایل‌ها

فایل فرم در جدول اختصاصی `Didar_File_Service::table_name()` و زیرشاخهٔ `didar-private` از `wp_upload_dir()` ذخیره می‌شود؛ attachment Media Library برای فایل‌های درخواست ساخته نمی‌شود. فایل موقت حداکثر یک روز عمر دارد و worker روزانه پاک‌سازی می‌کند. حذف submission فایل‌های وابسته را پاک می‌کند.

AJAXهای `didar_upload_file` و `didar_remove_file` ورود، nonce، فرم/فیلد معتبر، مالکیت، نوع/اندازه و record را کنترل می‌کنند. دانلود امن از `admin-post.php?action=didar_download_file` و مجوز دسترسی انجام می‌شود. حالت `direct` URL مستقیم می‌دهد؛ بنابراین دسترسی فایل به حفاظت وب‌سرور وابسته است. حالت پیش‌فرض `secure` است و برای دادهٔ حساس توصیه می‌شود.
