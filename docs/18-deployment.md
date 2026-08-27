# استقرار

1. از پوشهٔ فعلی افزونه و پایگاه‌داده backup بگیرید.
2. پوشهٔ قدیمی `ns-didar` را rename یا به‌طور کنترل‌شده حذف کنید.
3. بستهٔ تمیز را در `wp-content/plugins/ns-didar/` استخراج کنید.
4. وجود `wp-content/plugins/ns-didar/didar.php` و نبود پوشهٔ تو‌در‌توی `ns-didar/ns-didar/` را تأیید کنید.
5. تنظیمات را بازبینی کنید؛ key و secret را در deployment log چاپ نکنید.
6. پنل، فرم، پروفایل/Person sync، submission/Deal sync، webhook و diagnostics را تست عملیاتی کنید.

جایگزینی روی پوشهٔ کثیف توصیه نمی‌شود. نسخهٔ فعال پس از bootstrap معمول، workerهای لازم را self-heal می‌کند؛ بااین‌حال سلامت WP-Cron را بررسی کنید. rollback: پوشهٔ جدید را کنار بگذارید، نسخهٔ قبلیِ backup را با همان مسیر بازگردانید، سپس schema/settings/workerها را بررسی کنید. دادهٔ پایگاه‌داده را بدون برنامهٔ مهاجرت overwrite نکنید.
