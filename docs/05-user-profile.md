# پروفایل کاربر

`Didar_User_Profile` فرم پروفایل کاربر جاری را ارائه می‌کند. وضعیت هر فیلد (`editable`، `readonly` یا `disabled`) از `profile_field_states` می‌آید: `first_name`، `last_name`، `display_name`، `email`، `gender`، `mobile` و `profile_image`.

`first_name` و `last_name` در WordPress canonical هستند؛ فقط اگر خالی باشند، mapper به‌ترتیب از `billing_first_name` و `billing_last_name` (سازگاری Digits/WooCommerce) می‌خواند. موبایل ابتدا از `digits_phone` و سپس از ترکیب `digt_countrycode` و `digits_phone_no` خوانده می‌شود؛ افزونه این کلیدها را نمی‌نویسد. اگر موبایل readonly باشد، تغییر آن از فرم پروفایل پذیرفته نمی‌شود.

فرم `display_name` را ویرایش می‌کند، نه `nickname`. هنگام `wp_update_user`، `nickname` از مقدار موجود، سپس display name و در نهایت login تعیین می‌شود؛ `user_login` تغییر نمی‌کند. `user_nicename` نامعتبر قدیمی با `user-{id}` ترمیم می‌شود.

تصویر فقط در حالت editable، با فرمت JPG/PNG/GIF/WebP و سقف ۵MB در Media Library ذخیره می‌شود. پس از ذخیرهٔ پروفایل، Person sync فوری تلاش و در خطا برای retry صف می‌شود.
