# امنیت

تاریخ تولد و کد ملی داده حساس هستند؛ تغییر آن‌ها فقط از مسیر پروفایل کاربر جاری، با nonce و سیاست وضعیت فیلد انجام می‌شود. این مقادیر در URL، گزارش فنی، خطای عمومی یا خروجی تنظیمات قرار نمی‌گیرند.

کنترل‌های فعلی برای کاهش ریسک طراحی شده‌اند؛ تضمین «امنیت صددرصد» نیستند.

| تهدید | کنترل |
|---|---|

تاریخ تولد و کد ملی داده حساس هستند: فقط کاربر واردشده و مسیر nonce‌دار پروفایل می‌تواند آن‌ها را تغییر دهد و مقدارشان در log، URL یا export قرار نمی‌گیرد.
| CSRF | nonceهای مشخص در فرم، AJAX و admin |
| دسترسی غیرمجاز | ورود، capability و بررسی مالکیت/نوع پست |
| ورودی جعلی | registry، allowlist، sanitize/validate سمت‌سرور |
| XSS | escaping متناسب هنگام render |
| دانلود فایل | record معتبر، مجوز و nonce در mode امن |
| path traversal/upload | نام و path سرور، MIME/type/size allowlist |
| webhook جعلی/تکراری | secret، rate limit، dedupe و suppression loop |
| Deal تکراری | external ID، lookup دقیق submission ID و lock |
| نشت secret در انتقال | allowlist و حذف key/secret/runtime |
| نشت PII در log Person | ثبت ساختار payload به‌جای مقدار پروفایل |

حالت دانلود `direct` سطح ریسک بیشتری دارد؛ برای درخواست‌های حساس `secure` را نگه دارید. استقرار کثیف می‌تواند فایل قدیمی و رفتار امنیتی ناسازگار باقی بگذارد؛ جایگزینی تمیز الزامی عملیاتی است.
# Companion security
Nonce, ownership, MIME/extension validation, sanitized filenames, private storage, and secure downloads are unchanged and apply to companion files.
