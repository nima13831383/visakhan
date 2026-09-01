# FINAL DIDAR AUDIT — SOURCE OF TRUTH

> وضعیت گزارش: استخراج‌شده از source و runtime محلی پروژه در 2026-09-01. این سند برای ممیزی production است و هیچ تغییر production انجام نداده است.

## دامنه و قواعد تغییر

- WordPress منبع ساختار فرم، کلیدها، validation و lifecycle محلی است؛ Didar منبع عنوان/نوع/ارتباط pipelineهای واقعی است.
- هیچ API key، secret یا مقدار PII در این سند درج نشده است.
- در Didar فقط `متن کوتاه` و `متن بلند` برای Business Custom Fieldهای جدید/اصلاح‌شده مجاز است. نوع‌های دیگر ایجاد یا پیشنهاد نشوند.
- پاک‌سازی Didar فقط با اصلاح association همان Pipeline انجام شود؛ Custom Field حذف/rename/type-change نشود.
- companionها فقط در Didar به Caseهای جدا تبدیل می‌شوند؛ WordPress همچنان یک Submission والد دارد.

## خلاصه عددی

- فرم فعال: **5**
- فیلد فعال: **134** (بدون honeypot: 133)
- فیلد legacy-only: **6**
- mapping Deal ذخیره‌شده: **64**
- فیلدهای فعال بدون mapping صریح: **71** (بخشی اختیاری، native، internal یا repeater است)
- mappingهای Deal مشکوک: **8**
- Deal pipelineهای cache‌شده: **16**؛ pipelineهای workflow فعال فرم‌ها: **5**
- Case pipelineهای cache‌شده: **6**
- mapping فیلدهای companion Case: **11**؛ system mapping: **3**

## 1. فرم‌های فعال

| نام فارسی | Form Type | Deal | Workflow/Pipeline | Repeater | Case | Active | Legacy-only | File | Default required |
|---|---|---:|---|---|---|---:|---:|---:|---:|
| درخواست مشاوره | `consultation` | بله | درخواست مشاوره<br>`8aa0d6fd-3e82-484c-b82f-0d7ade16df74` | خیر | خیر | 6 | 5 | 0 | 4 |
| درخواست وقت سفارت | `embassy_appointment` | بله | وقت سفارت<br>`fc0d4e4b-f529-4b42-918c-0ea5f4d7538c` | `personal_details` | خیر | 20 | 0 | 0 | 10 |
| فرم ارزیابی اطلاعات مسافران ویزاخان | `traveler_evaluation` | بله | فرم ارزیابی اطلاعات مسافران ویزاخان<br>`7a26a059-80f9-4d67-bfe0-20ad18a4956c` | خیر | خیر | 59 | 0 | 0 | 0 |
| ثبت شکایات و پیشنهادات ویزاخان | `complaint_suggestion` | بله | ثبت شکایات و پیشنهادات ویزاخان<br>`75092f87-279b-4c32-84b6-cf054bb8ff7e` | خیر | خیر | 6 | 0 | 0 | 0 |
| درخواست ویزا | `visa_request` | بله | درخواست ویزا<br>`654a4f93-f12f-4a8c-9d56-c6bb0e87d8b3` | `companions` | بله؛ companions | 43 | 1 | 4 | 0 |

## 2. موجودی کامل فیلدهای فعال

نوع Didar پیشنهادی در این جدول از نوع WordPress و طول معنایی مقدار تعیین شده است: text/email/date/number/select/radio → `متن کوتاه`؛ textarea/checkbox/repeater/file یا مقدار چندخطی/ساختاری → `متن بلند`.

| # | Label | Field Key | Form Type | ns-didar Type | Semantic | Default Required | Didar storage | Deal mapping | Person mapping | Case mapping | Notes |
|---:|---|---|---|---|---|---:|---|---|---|---|---|
| 1 | نام | `first_name` | `consultation` | `text` | `—` | بله | متن کوتاه | CONFIGURED → `Field_8783_0_147` | PROFILE/USER | — |  |
| 2 | نام خانوادگی | `last_name` | `consultation` | `text` | `—` | بله | متن کوتاه | CONFIGURED → `Field_8783_0_148` | PROFILE/USER | — |  |
| 3 | شماره همراه | `input_3` | `consultation` | `text` | `—` | بله | متن کوتاه | CONFIGURED → `Field_8783_0_149` | PROFILE/USER | — |  |
| 4 | ایمیل | `email` | `consultation` | `email` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_150` | PROFILE/USER | — |  |
| 5 | موضوع مشاوره | `input_5` | `consultation` | `text` | `—` | بله | متن کوتاه | CONFIGURED → `Field_8783_0_151` | — | — |  |
| 6 | توضیحات | `description` | `consultation` | `textarea` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_1_152` | — | — |  |
| 7 | X/Twitter | `twitter` | `embassy_appointment` | `honeypot` | `—` | خیر | متن کوتاه | MISSING | — | — | internal؛ هرگز به Didar map نشود |
| 8 | ثبت درخواست برای خود یا شخص دیگر | `request_for` | `embassy_appointment` | `radio` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 9 | انتخاب کشور | `country` | `embassy_appointment` | `select` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 10 | نوع خدمات | `service_type` | `embassy_appointment` | `select` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 11 | شغل (حرفه و تخصص) | `profession` | `embassy_appointment` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 12 | تاریخ | `appointment_date` | `embassy_appointment` | `date` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 13 | نوع وقت سفارت | `urgency` | `embassy_appointment` | `radio` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 14 | نام | `first_name` | `embassy_appointment` | `text` | `—` | بله | متن کوتاه | SUSPECT → `FirstName` | — | — |  |
| 15 | نام خانوادگی | `last_name` | `embassy_appointment` | `text` | `—` | بله | متن کوتاه | SUSPECT → `LastName` | — | — |  |
| 16 | شماره موبایل | `mobile` | `embassy_appointment` | `text` | `—` | بله | متن کوتاه | SUSPECT → `MobilePhone` | — | — |  |
| 17 | ایمیل | `email` | `embassy_appointment` | `email` | `—` | خیر | متن کوتاه | SUSPECT → `Email` | — | — |  |
| 18 | ملیت فعلی | `current_nationality` | `embassy_appointment` | `text` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 19 | شماره گذرنامه | `passport_number` | `embassy_appointment` | `text` | `passport_number` | خیر | متن کوتاه | MISSING | — | — |  |
| 20 | کشور محل تولد | `birth_country` | `embassy_appointment` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 21 | جنسیت | `gender` | `embassy_appointment` | `radio` | `—` | بله | متن کوتاه | MISSING | PROFILE/USER | — |  |
| 22 | محل صدور گذرنامه | `passport_issue_place` | `embassy_appointment` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 23 | نام پدر | `father_name` | `embassy_appointment` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 24 | نام مادر | `mother_name` | `embassy_appointment` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 25 | تاریخ تولد | `birth_date` | `embassy_appointment` | `date` | `—` | بله | متن کوتاه | MISSING | — | — |  |
| 26 | مشخصات شخصی | `personal_details` | `embassy_appointment` | `repeater` | `—` | خیر | متن بلند | MISSING | — | — | single WordPress value; companion Case behavior documented below |
| 27 | تاریخ | `evaluation_date` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 28 | نام | `first_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | SUSPECT → `FirstName` | — | — |  |
| 29 | نام خانوادگی | `last_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | SUSPECT → `LastName` | — | — |  |
| 30 | تلفن همراه | `mobile` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | SUSPECT → `MobilePhone` | — | — |  |
| 31 | ایمیل | `email` | `traveler_evaluation` | `email` | `—` | خیر | متن کوتاه | SUSPECT → `Email` | — | — |  |
| 32 | نام مادر | `mother_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 33 | نام پدر | `father_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 34 | هرگونه نام سابق | `former_names` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 35 | ملیت | `nationality` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 36 | تاریخ تولد | `birth_date` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 37 | محل تولد | `birth_place` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 38 | جنسیت | `gender` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | PROFILE/USER | — |  |
| 39 | وضعیت تاهل | `marital_status` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 40 | تعداد فرزندان | `children_count` | `traveler_evaluation` | `number` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 41 | سن فرزندان | `children_ages` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 42 | کد ملی | `national_id` | `traveler_evaluation` | `text` | `national_id` | خیر | متن کوتاه | MISSING | — | — |  |
| 43 | نوع پاسپورت | `passport_type` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 44 | شماره گذرنامه | `passport_number` | `traveler_evaluation` | `text` | `passport_number` | خیر | متن کوتاه | MISSING | — | — |  |
| 45 | تاریخ صدور پاسپورت | `passport_issue_date` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 46 | تاریخ انقضا پاسپورت | `passport_expiry_date` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 47 | کشور صادر کننده | `passport_issuer_country` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 48 | روابط خانوادگی با یک شهروند اتحادیه اروپا، سوئیس یا بریتانیا | `eu_family_relation` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 49 | آدرس محل سکونت | `home_address` | `traveler_evaluation` | `textarea` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 50 | کدپستی | `postal_code` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 51 | شماره منزل | `home_phone` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 52 | ایمیل | `secondary_email` | `traveler_evaluation` | `email` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 53 | اقامت و پاسپورت کشور دیگر | `other_residency_passport` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 54 | شغل کنونی | `current_job` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 55 | نشانی محل کار | `work_address` | `traveler_evaluation` | `textarea` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 56 | نام کارفرما/شرکت/ مدرسه | `employer_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 57 | کدپستی محل کار | `work_postal_code` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 58 | تلفن کارفرما/شرکت/ مدرسه | `employer_phone` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 59 | ایمیل کارفرما/شرکت/ مدرسه | `employer_email` | `traveler_evaluation` | `email` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 60 | هدف از سفر | `travel_purpose` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 61 | اطلاعات تکمیلی در مورد هدف از سفر | `travel_purpose_details` | `traveler_evaluation` | `textarea` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 62 | کشور مقصد اصلی | `main_destination_country` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 63 | کشور اولین ورود | `first_entry_country` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 64 | تعداد ورودهای درخواستی | `requested_entries` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 65 | تاریخ اولین ورود به حوزه شنگن | `schengen_first_entry` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 66 | تاریخ خروج از اولین ورود به حوزه شنگن | `schengen_first_exit` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 67 | اثر انگشت‌های قبلی برای ورود به حوزه شنگن | `previous_fingerprints` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 68 | شماره ویزای صادره قبلی | `previous_visa_number` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 69 | ناریخ شروع اعتبار آخرین ویزای صادره | `last_visa_valid_from` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 70 | ناریخ پایان اعتبار آخرین ویزای صادره | `last_visa_valid_to` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 71 | مجوز ورود به کشور نهایی (در صورت وجود) - صادره از | `final_country_entry_permit_issuer` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 72 | شروع اعتبار مجوز | `entry_permit_valid_from` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 73 | پایان اعتبار مجوز | `entry_permit_valid_to` | `traveler_evaluation` | `date` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 74 | آیا برای ورود به کشور مقصد دعوتنامه دارید؟ | `has_invitation` | `traveler_evaluation` | `select` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 75 | نام و نام خانوادگی دعوت کننده/نام هتل یا محل اقامت در کشور مقصد | `host_or_hotel_name` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 76 | آدرس شخص دعوت کننده/هتل یا محل اقامت | `host_or_hotel_address` | `traveler_evaluation` | `textarea` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 77 | شماره تماس دعوت کننده/هتل یا محل اقامت | `host_or_hotel_phone` | `traveler_evaluation` | `text` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 78 | ایمیل دعوت کننده/هتل یا محل اقامت | `host_or_hotel_email` | `traveler_evaluation` | `email` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 79 | نحوه تامین هزینه‌های سفر | `travel_funding` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 80 | توسط خود شخص | `self_funding_methods` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 81 | توسط شخص حمایت کننده | `sponsor_funding_methods` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 82 | حساب‌های مالی قابل ارایه | `financial_accounts` | `traveler_evaluation` | `checkbox` | `—` | خیر | متن بلند | MISSING | — | — |  |
| 83 | میزان موجودی قابل ارایه ریالی | `rial_balance` | `traveler_evaluation` | `number` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 84 | میزان موجودی قابل ارایه ارزی (یورو/دلار) | `foreign_currency_balance` | `traveler_evaluation` | `number` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 85 | تعداد اسناد ملکی قابل ارایه به نام متقاضی/همسر | `property_deeds_count` | `traveler_evaluation` | `number` | `—` | خیر | متن کوتاه | MISSING | — | — |  |
| 86 | تاریخ | `date` | `complaint_suggestion` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_160` | — | — |  |
| 87 | نام | `first_name` | `complaint_suggestion` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_147` | — | — |  |
| 88 | نام خانوادگی | `last_name` | `complaint_suggestion` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_148` | — | — |  |
| 89 | تلفن همراه | `mobile` | `complaint_suggestion` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_149` | — | — |  |
| 90 | موضوع شکایت / پیشنهاد | `subject` | `complaint_suggestion` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_210` | — | — |  |
| 91 | پیام شکایت / پیشنهاد | `message` | `complaint_suggestion` | `textarea` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_1_211` | — | — |  |
| 92 | نام | `first_name` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_147` | — | — |  |
| 93 | نام خانوادگی | `last_name` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_148` | — | — |  |
| 94 | نام خانوادگی زمان تولد | `birth_surname` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_170` | — | — |  |
| 95 | تاریخ تولد | `birth_date` | `visa_request` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_169` | — | — |  |
| 96 | شهر محل تولد | `birth_city` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_171` | — | — |  |
| 97 | کشور محل تولد | `birth_country` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_164` | — | — |  |
| 98 | تابعیت فعلی | `current_nationality` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_172` | — | — |  |
| 99 | تابعیت زمان تولد | `birth_nationality` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_173` | — | — |  |
| 100 | کد ملی | `national_id` | `visa_request` | `text` | `national_id` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_174` | — | — |  |
| 101 | وضعیت تاهل | `marital_status` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_175` | — | — |  |
| 102 | موبایل | `mobile` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_149` | — | — |  |
| 103 | ایمیل | `email` | `visa_request` | `email` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_150` | — | — |  |
| 104 | آدرس کامل مسکونی | `residential_address` | `visa_request` | `textarea` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_176` | — | — |  |
| 105 | کد پستی | `postal_code` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_177` | — | — |  |
| 106 | وضعیت تحصیلی | `academic_level` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_178` | — | — |  |
| 107 | نوع دعوت‌نامه | `invitation_type` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_179` | — | — |  |
| 108 | شماره گذرنامه | `passport_number` | `visa_request` | `text` | `passport_number` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_163` | — | — |  |
| 109 | تاریخ انقضای گذرنامه | `passport_expiry` | `visa_request` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_180` | — | — |  |
| 110 | کشور صادرکننده پاسپورت | `passport_issuer_country` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_181` | — | — |  |
| 111 | مقصد سفر | `travel_destination` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_182` | — | — |  |
| 112 | عکس شخصی | `personal_photo` | `visa_request` | `file` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_183` | — | — |  |
| 113 | صفحه اصلی گذرنامه | `passport_main_page` | `visa_request` | `file` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_184` | — | — |  |
| 114 | بلیط رفت و برگشت | `round_trip_ticket` | `visa_request` | `file` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_185` | — | — |  |
| 115 | سایر مدارک | `other_documents` | `visa_request` | `file` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_186` | — | — |  |
| 116 | مانده حساب (تومان) | `account_balance` | `visa_request` | `number` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_187` | — | — |  |
| 117 | مجموع گردش ۶ ماهه | `six_month_turnover` | `visa_request` | `number` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_188` | — | — |  |
| 118 | دارای حساب ارزی هستید؟ | `has_foreign_currency_account` | `visa_request` | `radio` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_189` | — | — |  |
| 119 | سند ملکی به نام متقاضی | `has_property_deed` | `visa_request` | `radio` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_190` | — | — |  |
| 120 | درآمد غیرفعال | `passive_income` | `visa_request` | `checkbox` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_191` | — | — |  |
| 121 | نوع شغل | `employment_type` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_192` | — | — |  |
| 122 | عنوان شغلی | `occupation` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_193` | — | — |  |
| 123 | نام محل کار | `workplace_name` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_194` | — | — |  |
| 124 | سمت شغلی | `job_title` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_195` | — | — |  |
| 125 | چک‌لیست مدارک شغلی | `employment_documents` | `visa_request` | `checkbox` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_0_196` | — | — |  |
| 126 | سابقه ریجکتی | `has_rejection` | `visa_request` | `radio` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_197` | — | — |  |
| 127 | نام سفارت ریجکت‌کننده | `rejection_embassy` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_198` | — | — |  |
| 128 | تاریخ ریجکتی | `rejection_date` | `visa_request` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_199` | — | — |  |
| 129 | سابقه ویزای شنگن قبلی | `has_previous_schengen` | `visa_request` | `radio` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_200` | — | — |  |
| 130 | نام کشور ویزای شنگن قبلی | `previous_schengen_country` | `visa_request` | `select` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_201` | — | — |  |
| 131 | تاریخ ویزای شنگن قبلی | `previous_schengen_date` | `visa_request` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_202` | — | — |  |
| 132 | تاریخ حدودی سفر | `estimated_travel_date` | `visa_request` | `date` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_203` | — | — |  |
| 133 | محل خروج از شنگن (کشور/شهر) | `schengen_exit_place` | `visa_request` | `text` | `—` | خیر | متن کوتاه | CONFIGURED → `Field_8783_0_204` | — | — |  |
| 134 | لیست همراهان | `companions` | `visa_request` | `repeater` | `—` | خیر | متن بلند | CONFIGURED → `Field_8783_1_212` | — | Repeater parent; child fields below | single WordPress value; companion Case behavior documented below |

### Legacy-only fields

- `consultation`: `input_1` (نام و نام خانوادگی; text)، `input_4` (وضعیت تاهل; radio)، `input_6` (نوع مشاوره; radio)، `input_7` (تاریخ مشاوره; date)، `input_8` (زمان مشاوره; time)
- `visa_request`: `full_name` (نام و نام خانوادگی (قدیمی); text)
- Legacy fields در active renderer/validation/save مشارکت ندارند و نباید به‌عنوان فیلد فعال production mapping شوند.

## 3. Catalog گزینه‌ها

تمام catalogهای زیر مستقیماً از `Didar_Reference_Data`/Registry استخراج شده‌اند. گزینه‌های ذخیره‌شده canonical هستند؛ label فقط نمایش است.

### Catalog 1 — مصرف‌کننده: `embassy_appointment.request_for`

| Stored canonical value | Display label |
|---|---|
| `self` | برای خودم |
| `other` | برای شخص دیگر |

### Catalog 2 — مصرف‌کننده: `embassy_appointment.country`، `embassy_appointment.birth_country`، `traveler_evaluation.passport_issuer_country`، `traveler_evaluation.main_destination_country`، `traveler_evaluation.first_entry_country`، `visa_request.birth_country`، `visa_request.passport_issuer_country`، `visa_request.travel_destination`، `visa_request.previous_schengen_country`

| Stored canonical value | Display label |
|---|---|
| `china` | چین |
| `thailand` | تایلند |
| `japan` | ژاپن |
| `south_korea` | کره جنوبی |
| `uae` | امارات |
| `oman` | عمان |
| `india` | هند |
| `indonesia` | اندونزی |
| `malaysia` | مالزی |
| `hong_kong` | هنگ‌کنگ |
| `singapore` | سنگاپور |
| `qatar` | قطر |
| `kuwait` | کویت |
| `jordan` | اردن |
| `uzbekistan` | ازبکستان |
| `sri_lanka` | سریلانکا |
| `philippines` | فیلیپین |
| `vietnam` | ویتنام |
| `pakistan` | پاکستان |
| `iraq` | عراق |
| `saudi_arabia` | عربستان |
| `kyrgyzstan` | قرقیزستان |
| `kazakhstan` | قزاقستان |
| `mongolia` | مغولستان |
| `tajikistan` | تاجیکستان |
| `greece` | یونان |
| `france` | فرانسه |
| `belgium` | بلژیک |
| `switzerland` | سوئیس |
| `germany` | آلمان |
| `sweden` | سوئد |
| `austria` | اتریش |
| `hungary` | مجارستان |
| `poland` | لهستان |
| `finland` | فنلاند |
| `croatia` | کرواسی |
| `spain` | اسپانیا |
| `denmark` | دانمارک |
| `norway` | نروژ |
| `italy` | ایتالیا |
| `portugal` | پرتغال |
| `romania` | رومانی |
| `netherlands` | هلند |
| `united_kingdom` | انگلیس |
| `slovenia` | اسلوونی |
| `estonia` | استونی |
| `czech_republic` | چک |
| `lithuania` | لیتوانی |
| `cyprus` | قبرس |
| `ireland` | ایرلند |
| `turkey` | ترکیه |
| `georgia` | گرجستان |
| `canada` | کانادا |
| `united_states` | آمریکا |
| `mexico` | مکزیک |
| `brazil` | برزیل |
| `argentina` | آرژانتین |
| `dominica` | دومینیکا |
| `south_africa` | آفریقای جنوبی |
| `tunisia` | تونس |
| `egypt` | مصر |
| `morocco` | مراکش |
| `ivory_coast` | ساحل عاج |
| `australia` | استرالیا |

### Catalog 3 — مصرف‌کننده: `embassy_appointment.service_type`

| Stored canonical value | Display label |
|---|---|
| `study_immigration` | مهاجرت تحصیلی |
| `tourist_visa` | ویزای توریستی |
| `work_visa` | ویزای کاری |
| `study_visa` | ویزای تحصیلی |

### Catalog 4 — مصرف‌کننده: `embassy_appointment.profession`، `visa_request.occupation`

| Stored canonical value | Display label |
|---|---|
| `occupation_001` | صاحب کسب و کار — Business Owner |
| `occupation_002` | کارآفرین — Entrepreneur |
| `occupation_003` | مدیر شرکت — Company Director |
| `occupation_004` | مدیرعامل / مدیر کل — General Manager |
| `occupation_005` | مدیر عملیات — Operations Manager |
| `occupation_006` | مدیر پروژه — Project Manager |
| `occupation_007` | مدیر فروش — Sales Manager |
| `occupation_008` | مدیر بازاریابی — Marketing Manager |
| `occupation_009` | مدیر اداری — Office Manager |
| `occupation_010` | مدیر منابع انسانی — HR Manager |
| `occupation_011` | مشاور کسب و کار — Business Consultant |
| `occupation_012` | مشاور مدیریت — Management Consultant |
| `occupation_013` | مدیر اجرایی — Executive |
| `occupation_014` | سرپرست — Supervisor |
| `occupation_015` | سرپرست تیم — Team Leader |
| `occupation_016` | حسابدار — Accountant |
| `occupation_017` | حسابرس — Auditor |
| `occupation_018` | تحلیلگر مالی — Financial Analyst |
| `occupation_019` | مدیر مالی — Financial Manager |
| `occupation_020` | بانکدار — Banker |
| `occupation_021` | مدیر بانک — Bank Manager |
| `occupation_022` | تحلیلگر سرمایه‌گذاری — Investment Analyst |
| `occupation_023` | کارگزار بیمه — Insurance Agent |
| `occupation_024` | مشاور مالیاتی — Tax Consultant |
| `occupation_025` | اقتصاددان — Economist |
| `occupation_026` | مهندس نرم‌افزار — Software Engineer |
| `occupation_027` | توسعه دهنده نرم‌افزار — Software Developer |
| `occupation_028` | توسعه دهنده وب — Web Developer |
| `occupation_029` | طراح وب — Web Designer |
| `occupation_030` | مهندس کامپیوتر — Computer Engineer |
| `occupation_031` | متخصص فناوری اطلاعات — IT Specialist |
| `occupation_032` | مدیر فناوری اطلاعات — IT Manager |
| `occupation_033` | تحلیلگر سیستم — Systems Analyst |
| `occupation_034` | تحلیلگر داده — Data Analyst |
| `occupation_035` | دانشمند داده — Data Scientist |
| `occupation_036` | مدیر پایگاه داده — Database Administrator |
| `occupation_037` | مهندس شبکه — Network Engineer |
| `occupation_038` | مدیر شبکه — Network Administrator |
| `occupation_039` | متخصص امنیت سایبری — Cybersecurity Specialist |
| `occupation_040` | برنامه‌نویس — Programmer |
| `occupation_041` | توسعه دهنده اپلیکیشن موبایل — Mobile App Developer |
| `occupation_042` | طراح UX/UI — UX/UI Designer |
| `occupation_043` | مهندس هوش مصنوعی — Artificial Intelligence Engineer |
| `occupation_044` | مهندس عمران — Civil Engineer |
| `occupation_045` | مهندس مکانیک — Mechanical Engineer |
| `occupation_046` | مهندس برق — Electrical Engineer |
| `occupation_047` | مهندس الکترونیک — Electronics Engineer |
| `occupation_048` | مهندس شیمی — Chemical Engineer |
| `occupation_049` | مهندس صنایع — Industrial Engineer |
| `occupation_050` | مهندس معماری — Architectural Engineer |
| `occupation_051` | مهندس نفت — Petroleum Engineer |
| `occupation_052` | مهندس معدن — Mining Engineer |
| `occupation_053` | مهندس محیط زیست — Environmental Engineer |
| `occupation_054` | مهندس پزشکی — Biomedical Engineer |
| `occupation_055` | مهندس مخابرات — Telecommunications Engineer |
| `occupation_056` | مهندس سازه — Structural Engineer |
| `occupation_057` | مهندس اتوماسیون — Automation Engineer |
| `occupation_058` | پزشک — Doctor / Physician |
| `occupation_059` | جراح — Surgeon |
| `occupation_060` | دندانپزشک — Dentist |
| `occupation_061` | داروساز — Pharmacist |
| `occupation_062` | پرستار — Nurse |
| `occupation_063` | ماما — Midwife |
| `occupation_064` | فیزیوتراپیست — Physiotherapist |
| `occupation_065` | روان شناس — Psychologist |
| `occupation_066` | روان پزشک — Psychiatrist |
| `occupation_067` | تکنسین پزشکی — Medical Technician |
| `occupation_068` | تکنسین آزمایشگاه — Laboratory Technician |
| `occupation_069` | رادیولوژیست — Radiologist |
| `occupation_070` | دامپزشک — Veterinarian |
| `occupation_071` | دستیار پزشکی — Medical Assistant |
| `occupation_072` | تکنسین فوریت‌های پزشکی — Paramedic |
| `occupation_073` | متخصص تغذیه — Nutritionist |
| `occupation_074` | معلم — Teacher |
| `occupation_075` | معلم مدرسه — School Teacher |
| `occupation_076` | استاد دانشگاه — University Professor |
| `occupation_077` | مدرس / استاد — Lecturer |
| `occupation_078` | پژوهشگر — Researcher |
| `occupation_079` | دانشمند — Scientist |
| `occupation_080` | دانشجوی دانشگاه — University Student |
| `occupation_081` | دانش‌آموز / دانشجو — Student |
| `occupation_082` | دانشجوی دکترا — PhD Student |
| `occupation_083` | دستیار پژوهشی — Research Assistant |
| `occupation_084` | عضو هیئت علمی / دانشگاهی — Academic |
| `occupation_085` | مدیر مدرسه — School Principal |
| `occupation_086` | وکیل — Lawyer |
| `occupation_087` | وکیل دادگستری — Attorney |
| `occupation_088` | مشاور حقوقی — Legal Consultant |
| `occupation_089` | قاضی — Judge |
| `occupation_090` | دادستان — Prosecutor |
| `occupation_091` | سردفتر / دفتر اسناد رسمی — Notary |
| `occupation_092` | کارمند دولت — Government Employee |
| `occupation_093` | کارمند خدمات دولتی — Civil Servant |
| `occupation_094` | دیپلمات — Diplomat |
| `occupation_095` | مقام دولتی — Public Official |
| `occupation_096` | فروشنده — Salesperson |
| `occupation_097` | نماینده فروش — Sales Representative |
| `occupation_098` | خرده فروش — Retailer |
| `occupation_099` | مغازه دار — Shopkeeper |
| `occupation_100` | صندوقدار — Cashier |
| `occupation_101` | کارشناس خدمات مشتریان — Customer Service Representative |
| `occupation_102` | اپراتور مرکز تماس — Call Center Agent |
| `occupation_103` | مشاور املاک — Real Estate Agent |
| `occupation_104` | آژانس دار / کارگزار سفر — Travel Agent |
| `occupation_105` | برگزارکننده تور — Tour Operator |
| `occupation_106` | کارگزار بیمه — Insurance Broker |
| `occupation_107` | معمار — Architect |
| `occupation_108` | مدیر ساخت و ساز — Construction Manager |
| `occupation_109` | پیمانکار — Contractor |
| `occupation_110` | سازنده — Builder |
| `occupation_111` | برق کار — Electrician |
| `occupation_112` | لوله کش — Plumber |
| `occupation_113` | نجار — Carpenter |
| `occupation_114` | جوشکار — Welder |
| `occupation_115` | بنا — Mason |
| `occupation_116` | نقاش ساختمان — Painter |
| `occupation_117` | مکانیک — Mechanic |
| `occupation_118` | مکانیک خودرو — Auto Mechanic |
| `occupation_119` | تکنسین — Technician |
| `occupation_120` | اپراتور ماشین‌آلات — Machine Operator |
| `occupation_121` | کارگر ساختمانی — Construction Worker |
| `occupation_122` | راننده — Driver |
| `occupation_123` | راننده تاکسی — Taxi Driver |
| `occupation_124` | راننده کامیون — Truck Driver |
| `occupation_125` | راننده اتوبوس — Bus Driver |
| `occupation_126` | راننده تحویل کالا — Delivery Driver |
| `occupation_127` | خلبان — Pilot |
| `occupation_128` | مهماندار هواپیما — Flight Attendant |
| `occupation_129` | مهندس هواپیما — Aircraft Engineer |
| `occupation_130` | ناخدای کشتی — Ship Captain |
| `occupation_131` | دریانورد — Seafarer |
| `occupation_132` | ملوان — Sailor |
| `occupation_133` | مدیر لجستیک — Logistics Manager |
| `occupation_134` | مدیر انبار — Warehouse Manager |
| `occupation_135` | مدیر هتل — Hotel Manager |
| `occupation_136` | پذیرشگر — Receptionist |
| `occupation_137` | کارمند هتل — Hotel Staff |
| `occupation_138` | سرآشپز — Chef |
| `occupation_139` | آشپز — Cook |
| `occupation_140` | پیشخدمت — Waiter |
| `occupation_141` | متصدی بار — Bartender |
| `occupation_142` | نانوا — Baker |
| `occupation_143` | شیرینی پز — Pastry Chef |
| `occupation_144` | مدیر رستوران — Restaurant Manager |
| `occupation_145` | راهنمای گردشگری — Tour Guide |
| `occupation_146` | مشاور سفر — Travel Consultant |
| `occupation_147` | روزنامه نگار — Journalist |
| `occupation_148` | خبرنگار — Reporter |
| `occupation_149` | عکاس — Photographer |
| `occupation_150` | فیلم بردار — Videographer |
| `occupation_151` | فیلم ساز — Filmmaker |
| `occupation_152` | کارگردان — Director |
| `occupation_153` | تهیه کننده — Producer |
| `occupation_154` | بازیگر — Actor |
| `occupation_155` | موسیقیدان — Musician |
| `occupation_156` | خواننده — Singer |
| `occupation_157` | هنرمند — Artist |
| `occupation_158` | طراح گرافیک — Graphic Designer |
| `occupation_159` | تصویرگر — Illustrator |
| `occupation_160` | نویسنده — Writer |
| `occupation_161` | مترجم — Translator |
| `occupation_162` | مترجم شفاهی — Interpreter |
| `occupation_163` | کشاورز — Farmer |
| `occupation_164` | مهندس کشاورزی — Agricultural Engineer |
| `occupation_165` | کارگر مزرعه — Farm Worker |
| `occupation_166` | باغبان — Gardener |
| `occupation_167` | متخصص باغبانی — Horticulturist |
| `occupation_168` | ماهیگیر — Fisherman |
| `occupation_169` | جنگلبان / متخصص جنگل — Forester |
| `occupation_170` | مشاور کشاورزی — Agricultural Consultant |
| `occupation_171` | دستیار اداری — Administrative Assistant |
| `occupation_172` | منشی — Secretary |
| `occupation_173` | کارمند اداری — Office Clerk |
| `occupation_174` | مسئول پذیرش — Receptionist |
| `occupation_175` | دستیار مدیر — Executive Assistant |
| `occupation_176` | دستیار شخصی — Personal Assistant |
| `occupation_177` | متخصص منابع انسانی — Human Resources Specialist |
| `occupation_178` | مسئول خرید — Procurement Officer |
| `occupation_179` | مدیر خرید — Purchasing Manager |
| `occupation_180` | مسئول کنترل اسناد — Document Controller |
| `occupation_181` | معمار (تکرار شده) — Architect |
| `occupation_182` | طراح داخلی — Interior Designer |
| `occupation_183` | طراح مد — Fashion Designer |
| `occupation_184` | آرایشگر — Hairdresser |
| `occupation_185` | متخصص زیبایی — Beautician |
| `occupation_186` | مربی تناسب اندام — Fitness Trainer |
| `occupation_187` | مربی ورزشی — Sports Coach |
| `occupation_188` | ورزشکار — Athlete |
| `occupation_189` | نگهبان — Security Guard |
| `occupation_190` | افسر پلیس — Police Officer |
| `occupation_191` | آتش نشان — Firefighter |
| `occupation_192` | افسر نظامی — Military Officer |
| `occupation_193` | سرباز — Soldier |
| `occupation_194` | مددکار اجتماعی — Social Worker |
| `occupation_195` | کتابدار — Librarian |
| `occupation_196` | روزنامه نگار (تکرار شده) — Journalist |
| `occupation_197` | مشاور — Consultant |
| `occupation_198` | فریلنسر / آزادکار — Freelancer |
| `occupation_199` | فعال / کارمند مذهبی — Religious Worker |
| `occupation_200` | داوطلب — Volunteer |

### Catalog 5 — مصرف‌کننده: `embassy_appointment.urgency`

| Stored canonical value | Display label |
|---|---|
| `very_urgent` | خیلی فوری |
| `urgent` | فوری |
| `normal` | عادی |

### Catalog 6 — مصرف‌کننده: `embassy_appointment.gender`

| Stored canonical value | Display label |
|---|---|
| `boy` | مرد |
| `grill` | زن |

### Catalog 7 — مصرف‌کننده: `traveler_evaluation.nationality`

| Stored canonical value | Display label |
|---|---|
| `iranian` | ایرانی |
| `foreign` | خارجی |

### Catalog 8 — مصرف‌کننده: `traveler_evaluation.gender`

| Stored canonical value | Display label |
|---|---|
| `male` | مرد |
| `female` | زن |

### Catalog 9 — مصرف‌کننده: `traveler_evaluation.marital_status`

| Stored canonical value | Display label |
|---|---|
| `single` | مجرد |
| `married` | متاهل |
| `divorced` | مطلقه |
| `widowed` | بیوه |

### Catalog 10 — مصرف‌کننده: `traveler_evaluation.passport_type`

| Stored canonical value | Display label |
|---|---|
| `ordinary` | معمولی |
| `diplomatic` | دیپلمات |
| `service` | پاسپورت خدمت |
| `official` | پاسپورت اداری |
| `special` | پاسپورت مخصوص |
| `other` | پاسپورت‌های دیگر |

### Catalog 11 — مصرف‌کننده: `traveler_evaluation.eu_family_relation`

| Stored canonical value | Display label |
|---|---|
| `spouse` | همسر |
| `child` | فرزند |
| `grandchild` | نوه |
| `relative` | عضو فامیل |
| `in_law` | وابسته سببی |
| `other` | سایر |

### Catalog 12 — مصرف‌کننده: `traveler_evaluation.other_residency_passport`

| Stored canonical value | Display label |
|---|---|
| `have` | دارم |
| `do_not_have` | ندارم |

### Catalog 13 — مصرف‌کننده: `traveler_evaluation.travel_purpose`

| Stored canonical value | Display label |
|---|---|
| `tourism` | توریستی |
| `business` | تجاری |
| `family_friends` | بازدید از خانواده و دوستان |
| `historical` | تاریخی |
| `sports` | ورزشی |
| `official` | ملاقات رسمی |
| `medical` | دلیل پزشکی |
| `study` | تحصیلی |
| `airport_transit` | ترانزیت فرودگاهی |
| `other` | موارد دیگر |

### Catalog 14 — مصرف‌کننده: `traveler_evaluation.requested_entries`

| Stored canonical value | Display label |
|---|---|
| `single` | یکبار ورود |
| `double` | دوبار ورود |
| `multiple` | چند بار ورود (مولتیپل) |

### Catalog 15 — مصرف‌کننده: `traveler_evaluation.previous_fingerprints`، `traveler_evaluation.has_invitation`، `visa_request.has_foreign_currency_account`، `visa_request.has_property_deed`، `visa_request.has_rejection`، `visa_request.has_previous_schengen`

| Stored canonical value | Display label |
|---|---|
| `yes` | بله |
| `no` | خیر |

### Catalog 16 — مصرف‌کننده: `traveler_evaluation.travel_funding`

| Stored canonical value | Display label |
|---|---|
| `self` | توسط خود شخص |
| `sponsor` | توسط شخص حمایت کننده |

### Catalog 17 — مصرف‌کننده: `traveler_evaluation.self_funding_methods`

| Stored canonical value | Display label |
|---|---|
| `cash` | نقدی |
| `cheque` | چک |
| `credit_card` | کارت اعتباری |
| `prepaid_accommodation` | پیش خرید اقامت |
| `prepaid_transport` | پیش خرید حمل و نقل |
| `other` | موارد دیگر |

### Catalog 18 — مصرف‌کننده: `traveler_evaluation.sponsor_funding_methods`

| Stored canonical value | Display label |
|---|---|
| `invitation` | طبق دعوتنامه |
| `other` | موارد دیگر |
| `cash` | نقدی |
| `accommodation` | محل اقامت |
| `all_costs` | همه هزینه‌های سفر پوشش داده می‌شود |

### Catalog 19 — مصرف‌کننده: `traveler_evaluation.financial_accounts`

| Stored canonical value | Display label |
|---|---|
| `rial` | حساب ریالی |
| `foreign_currency` | حساب ارزی |

### Catalog 20 — مصرف‌کننده: `visa_request.marital_status`

| Stored canonical value | Display label |
|---|---|
| `single` | مجرد |
| `married` | متاهل |

### Catalog 21 — مصرف‌کننده: `visa_request.academic_level`

| Stored canonical value | Display label |
|---|---|
| `under_diploma` | زیردیپلم (Under Diploma) |
| `diploma` | دیپلم (Diploma) |
| `associate` | فوق‌دیپلم (Associate Degree) |
| `bachelor` | کارشناسی (Bachelor’s Degree) |
| `master` | کارشناسی ارشد (Master’s Degree) |
| `phd` | دکترا (PHD) |
| `postdoc` | فوق‌دکترا (Post-Doc) |

### Catalog 22 — مصرف‌کننده: `visa_request.invitation_type`

| Stored canonical value | Display label |
|---|---|
| `family` | دیدار خانواده |
| `friend` | دیدار دوست |
| `spouse_family` | دیدار همسر |
| `business` | سفر تجاری |
| `conference` | کنفرانس |
| `academic_research` | سفر علمی/تحقیقاتی |
| `cultural_sports` | فرهنگی/ورزشی |
| `medical` | درمان |
| `tourism` | توریسم (بدون دعوت‌نامه شخصی) |

### Catalog 23 — مصرف‌کننده: `visa_request.passive_income`

| Stored canonical value | Display label |
|---|---|
| `rent` | اجاره ملک |
| `bank_deposit` | سپرده بانکی |
| `stock_market` | بورس |
| `other` | سایر |

### Catalog 24 — مصرف‌کننده: `visa_request.employment_type`

| Stored canonical value | Display label |
|---|---|
| `self_employed` | آزاد |
| `government` | دولتی |
| `private` | خصوصی |
| `retired` | بازنشسته |
| `board_member` | مدیرعامل یا عضو هیئت مدیره |
| `doctor` | پزشک |
| `lawyer` | وکیل |
| `other` | سایر |

### Catalog 25 — مصرف‌کننده: `visa_request.employment_documents`

| Stored canonical value | Display label |
|---|---|
| `business_license` | جواز کسب |
| `management_card` | کارت مباشرت |
| `employment_letter` | نامه اشتغال به کار |
| `workplace_deed_lease` | سند یا اجاره‌نامه محل کار |
| `employment_decree` | حکم کارگزینی |
| `work_contract` | قرارداد کاری |
| `company_notice` | آگهی تاسیس و تغییرات |
| `payslip` | فیش حقوقی |
| `social_security` | بیمه تامین اجتماعی |

## 4. Validation و canonicalization

| Semantic/type | Frontend/source rule | Server/storage rule | Didar representation |
|---|---|---|---|
| national_id | inputmode numeric، pattern digits | digit normalization به ASCII؛ string؛ leading zero حفظ می‌شود | `متن کوتاه`، string |
| passport_number | pattern `[A-Za-z][0-9]{8}`، maxlength 9، autocapitalize | uppercase canonical؛ یک حرف انگلیسی + ۸ رقم | `متن کوتاه` |
| date | نمایش Jalali مطابق date renderer | canonical Gregorian `YYYY-MM-DD` | `متن کوتاه` |
| email | HTML email + server sanitization/validation | sanitized email string | `متن کوتاه` |
| mobile/phone | tel input؛ number normalization | canonical mobile normalization؛ string | `متن کوتاه` |
| number | min=0 در registry برای شمارنده/مبلغ | numeric validation سپس string-safe serialization | `متن کوتاه` |
| select/radio | انتخاب فقط از optionهای Registry | canonical stored value، نه label | `متن کوتاه` |
| checkbox/multiple | آرایه از optionهای مجاز | canonical values serialized consistently | `متن بلند` |
| file | MIME، حجم و تعداد از File Service | فایل در WordPress/File Service؛ path/temp path هرگز ارسال نمی‌شود | متن کوتاه برای یک reference، متن بلند برای چند reference |

جزئیات دقیق normalize/validation را Work باید در `Didar_Submission_Service`, `Didar_Field_Renderer`, `Didar_Input_Normalizer` و `Didar_Readable_Value_Serializer` با production behavior تطبیق دهد.

## 5. فیلدهای مشترک کسب‌وکار

| Field/semantic | Form Types | Same meaning? | Safe to reuse? | Notes |
|---|---|---|---|---|
| first_name / نام | همه ۵ فرم | بله | بله، پس از تأیید عنوان live | کلید در همه یکسان جز ساختارهای legacy |
| last_name / نام خانوادگی | همه ۵ فرم | بله | بله، پس از تأیید عنوان live | |
| email / ایمیل | همه ۵ فرم | بله | بله | canonical email |
| mobile/contact | consultation=`input_3`، سایر فرم‌ها `mobile` یا `phone` | از نظر معنا عمدتاً بله | REVIEW | label/field identity live بررسی شود؛ phone همراهان جداست |
| birth_date | embassy، traveler، visa | بله | بله | date canonical |
| passport_number | embassy، traveler، visa | بله | بله | passport canonical |
| national_id | traveler، visa و profile | بله | REVIEW/بله | Deal snapshot و Person profile را یکی نکنید |
| gender | embassy، traveler و Person profile | نزدیک/بله | REVIEW | canonical values ممکن است در فرم‌ها متفاوت باشند |

## 6. FORM_ONLY_FIELDS

### درخواست مشاوره (`consultation`)

`input_5` — موضوع مشاوره، `description` — توضیحات

### درخواست وقت سفارت (`embassy_appointment`)

`request_for` — ثبت درخواست برای خود یا شخص دیگر، `country` — انتخاب کشور، `service_type` — نوع خدمات، `profession` — شغل (حرفه و تخصص)، `appointment_date` — تاریخ، `urgency` — نوع وقت سفارت، `current_nationality` — ملیت فعلی، `birth_country` — کشور محل تولد، `passport_issue_place` — محل صدور گذرنامه، `father_name` — نام پدر، `mother_name` — نام مادر، `personal_details` — مشخصات شخصی

### فرم ارزیابی اطلاعات مسافران ویزاخان (`traveler_evaluation`)

`evaluation_date` — تاریخ، `mother_name` — نام مادر، `father_name` — نام پدر، `former_names` — هرگونه نام سابق، `nationality` — ملیت، `birth_place` — محل تولد، `marital_status` — وضعیت تاهل، `children_count` — تعداد فرزندان، `children_ages` — سن فرزندان، `passport_type` — نوع پاسپورت، `passport_issue_date` — تاریخ صدور پاسپورت، `passport_expiry_date` — تاریخ انقضا پاسپورت، `passport_issuer_country` — کشور صادر کننده، `eu_family_relation` — روابط خانوادگی با یک شهروند اتحادیه اروپا، سوئیس یا بریتانیا، `home_address` — آدرس محل سکونت، `postal_code` — کدپستی، `home_phone` — شماره منزل، `secondary_email` — ایمیل، `other_residency_passport` — اقامت و پاسپورت کشور دیگر، `current_job` — شغل کنونی، `work_address` — نشانی محل کار، `employer_name` — نام کارفرما/شرکت/ مدرسه، `work_postal_code` — کدپستی محل کار، `employer_phone` — تلفن کارفرما/شرکت/ مدرسه، `employer_email` — ایمیل کارفرما/شرکت/ مدرسه، `travel_purpose` — هدف از سفر، `travel_purpose_details` — اطلاعات تکمیلی در مورد هدف از سفر، `main_destination_country` — کشور مقصد اصلی، `first_entry_country` — کشور اولین ورود، `requested_entries` — تعداد ورودهای درخواستی، `schengen_first_entry` — تاریخ اولین ورود به حوزه شنگن، `schengen_first_exit` — تاریخ خروج از اولین ورود به حوزه شنگن، `previous_fingerprints` — اثر انگشت‌های قبلی برای ورود به حوزه شنگن، `previous_visa_number` — شماره ویزای صادره قبلی، `last_visa_valid_from` — ناریخ شروع اعتبار آخرین ویزای صادره، `last_visa_valid_to` — ناریخ پایان اعتبار آخرین ویزای صادره، `final_country_entry_permit_issuer` — مجوز ورود به کشور نهایی (در صورت وجود) - صادره از، `entry_permit_valid_from` — شروع اعتبار مجوز، `entry_permit_valid_to` — پایان اعتبار مجوز، `has_invitation` — آیا برای ورود به کشور مقصد دعوتنامه دارید؟، `host_or_hotel_name` — نام و نام خانوادگی دعوت کننده/نام هتل یا محل اقامت در کشور مقصد، `host_or_hotel_address` — آدرس شخص دعوت کننده/هتل یا محل اقامت، `host_or_hotel_phone` — شماره تماس دعوت کننده/هتل یا محل اقامت، `host_or_hotel_email` — ایمیل دعوت کننده/هتل یا محل اقامت، `travel_funding` — نحوه تامین هزینه‌های سفر، `self_funding_methods` — توسط خود شخص، `sponsor_funding_methods` — توسط شخص حمایت کننده، `financial_accounts` — حساب‌های مالی قابل ارایه، `rial_balance` — میزان موجودی قابل ارایه ریالی، `foreign_currency_balance` — میزان موجودی قابل ارایه ارزی (یورو/دلار)، `property_deeds_count` — تعداد اسناد ملکی قابل ارایه به نام متقاضی/همسر

### ثبت شکایات و پیشنهادات ویزاخان (`complaint_suggestion`)

`date` — تاریخ، `subject` — موضوع شکایت / پیشنهاد، `message` — پیام شکایت / پیشنهاد

### درخواست ویزا (`visa_request`)

`birth_surname` — نام خانوادگی زمان تولد، `birth_city` — شهر محل تولد، `birth_country` — کشور محل تولد، `current_nationality` — تابعیت فعلی، `birth_nationality` — تابعیت زمان تولد، `marital_status` — وضعیت تاهل، `residential_address` — آدرس کامل مسکونی، `postal_code` — کد پستی، `academic_level` — وضعیت تحصیلی، `invitation_type` — نوع دعوت‌نامه، `passport_expiry` — تاریخ انقضای گذرنامه، `passport_issuer_country` — کشور صادرکننده پاسپورت، `travel_destination` — مقصد سفر، `personal_photo` — عکس شخصی، `passport_main_page` — صفحه اصلی گذرنامه، `round_trip_ticket` — بلیط رفت و برگشت، `other_documents` — سایر مدارک، `account_balance` — مانده حساب (تومان)، `six_month_turnover` — مجموع گردش ۶ ماهه، `has_foreign_currency_account` — دارای حساب ارزی هستید؟، `has_property_deed` — سند ملکی به نام متقاضی، `passive_income` — درآمد غیرفعال، `employment_type` — نوع شغل، `occupation` — عنوان شغلی، `workplace_name` — نام محل کار، `job_title` — سمت شغلی، `employment_documents` — چک‌لیست مدارک شغلی، `has_rejection` — سابقه ریجکتی، `rejection_embassy` — نام سفارت ریجکت‌کننده، `rejection_date` — تاریخ ریجکتی، `has_previous_schengen` — سابقه ویزای شنگن قبلی، `previous_schengen_country` — نام کشور ویزای شنگن قبلی، `previous_schengen_date` — تاریخ ویزای شنگن قبلی، `estimated_travel_date` — تاریخ حدودی سفر، `schengen_exit_place` — محل خروج از شنگن (کشور/شهر)، `companions` — لیست همراهان

این فهرست بر اساس key/semantic است؛ Work باید association pipeline را در Didar با عنوان live تأیید کند، نه فقط label مشابه.

## 7. System Deal Fields و native Deal properties

| System field | Settings key | Current key/ID | Required for sync? | Reverse sync? | Shared? |
|---|---|---|---|---|---|
| WordPress Submission ID | `didar_system_submission_id_field_id` | `Field_8783_12_154` | بله برای Deal recovery/identity | در webhook Deal استفاده می‌شود | بله |
| Form Type | `didar_system_form_type_field_id` | `Field_8783_0_153` | بله برای form identity/recovery | بله، برای ساخت local از Deal webhook | بله |
| WordPress User ID | `didar_system_user_id_field_id` | `Field_8783_0_155` | برای resolve Person/owner context | برای webhook matching | بله |
| Public Status | `didar_public_status_field_id` | `MISSING` | خیر؛ اختیاری | بله اگر تنظیم شود | بله |

Native Deal properties که نباید Custom Field شوند: `Title`, `Description`, `PersonId`, `PipelineId`, `PipelineStageId`, `OwnerId`, `Status`, `Price`, `ExpectedCloseDate`, `VisibilityType`.

## 8. Current saved Deal mappings

| Form Type | Field Key | Label | Current target/key | Mapping status | Local assessment |
|---|---|---|---|---|---|
| `consultation` | `first_name` | نام | `Field_8783_0_147` — نام | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `consultation` | `last_name` | نام خانوادگی | `Field_8783_0_148` — نام خانوادگی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `consultation` | `input_3` | شماره همراه | `Field_8783_0_149` — شماره همراه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `consultation` | `email` | ایمیل | `Field_8783_0_150` — ایمیل | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `consultation` | `input_5` | موضوع مشاوره | `Field_8783_0_151` — موضوع مشاوره | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `consultation` | `description` | توضیحات | `Field_8783_1_152` — توضیحات | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `embassy_appointment` | `twitter` | X/Twitter | `—` | MISSING | REVIEW |
| `embassy_appointment` | `request_for` | ثبت درخواست برای خود یا شخص دیگر | `—` | MISSING | REVIEW |
| `embassy_appointment` | `country` | انتخاب کشور | `—` | MISSING | REVIEW |
| `embassy_appointment` | `service_type` | نوع خدمات | `—` | MISSING | REVIEW |
| `embassy_appointment` | `profession` | شغل (حرفه و تخصص) | `—` | MISSING | REVIEW |
| `embassy_appointment` | `appointment_date` | تاریخ | `—` | MISSING | REVIEW |
| `embassy_appointment` | `urgency` | نوع وقت سفارت | `—` | MISSING | REVIEW |
| `embassy_appointment` | `first_name` | نام | `FirstName` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `embassy_appointment` | `last_name` | نام خانوادگی | `LastName` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `embassy_appointment` | `mobile` | شماره موبایل | `MobilePhone` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `embassy_appointment` | `email` | ایمیل | `Email` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `embassy_appointment` | `current_nationality` | ملیت فعلی | `—` | MISSING | REVIEW |
| `embassy_appointment` | `passport_number` | شماره گذرنامه | `—` | MISSING | REVIEW |
| `embassy_appointment` | `birth_country` | کشور محل تولد | `—` | MISSING | REVIEW |
| `embassy_appointment` | `gender` | جنسیت | `—` | MISSING | REVIEW |
| `embassy_appointment` | `passport_issue_place` | محل صدور گذرنامه | `—` | MISSING | REVIEW |
| `embassy_appointment` | `father_name` | نام پدر | `—` | MISSING | REVIEW |
| `embassy_appointment` | `mother_name` | نام مادر | `—` | MISSING | REVIEW |
| `embassy_appointment` | `birth_date` | تاریخ تولد | `—` | MISSING | REVIEW |
| `embassy_appointment` | `personal_details` | مشخصات شخصی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `evaluation_date` | تاریخ | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `first_name` | نام | `FirstName` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `traveler_evaluation` | `last_name` | نام خانوادگی | `LastName` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `traveler_evaluation` | `mobile` | تلفن همراه | `MobilePhone` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `traveler_evaluation` | `email` | ایمیل | `Email` | SUSPECT | SUSPECT — native/unknown/non-Deal target |
| `traveler_evaluation` | `mother_name` | نام مادر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `father_name` | نام پدر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `former_names` | هرگونه نام سابق | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `nationality` | ملیت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `birth_date` | تاریخ تولد | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `birth_place` | محل تولد | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `gender` | جنسیت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `marital_status` | وضعیت تاهل | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `children_count` | تعداد فرزندان | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `children_ages` | سن فرزندان | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `national_id` | کد ملی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `passport_type` | نوع پاسپورت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `passport_number` | شماره گذرنامه | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `passport_issue_date` | تاریخ صدور پاسپورت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `passport_expiry_date` | تاریخ انقضا پاسپورت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `passport_issuer_country` | کشور صادر کننده | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `eu_family_relation` | روابط خانوادگی با یک شهروند اتحادیه اروپا، سوئیس یا بریتانیا | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `home_address` | آدرس محل سکونت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `postal_code` | کدپستی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `home_phone` | شماره منزل | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `secondary_email` | ایمیل | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `other_residency_passport` | اقامت و پاسپورت کشور دیگر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `current_job` | شغل کنونی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `work_address` | نشانی محل کار | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `employer_name` | نام کارفرما/شرکت/ مدرسه | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `work_postal_code` | کدپستی محل کار | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `employer_phone` | تلفن کارفرما/شرکت/ مدرسه | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `employer_email` | ایمیل کارفرما/شرکت/ مدرسه | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `travel_purpose` | هدف از سفر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `travel_purpose_details` | اطلاعات تکمیلی در مورد هدف از سفر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `main_destination_country` | کشور مقصد اصلی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `first_entry_country` | کشور اولین ورود | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `requested_entries` | تعداد ورودهای درخواستی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `schengen_first_entry` | تاریخ اولین ورود به حوزه شنگن | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `schengen_first_exit` | تاریخ خروج از اولین ورود به حوزه شنگن | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `previous_fingerprints` | اثر انگشت‌های قبلی برای ورود به حوزه شنگن | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `previous_visa_number` | شماره ویزای صادره قبلی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `last_visa_valid_from` | ناریخ شروع اعتبار آخرین ویزای صادره | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `last_visa_valid_to` | ناریخ پایان اعتبار آخرین ویزای صادره | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `final_country_entry_permit_issuer` | مجوز ورود به کشور نهایی (در صورت وجود) - صادره از | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `entry_permit_valid_from` | شروع اعتبار مجوز | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `entry_permit_valid_to` | پایان اعتبار مجوز | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `has_invitation` | آیا برای ورود به کشور مقصد دعوتنامه دارید؟ | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `host_or_hotel_name` | نام و نام خانوادگی دعوت کننده/نام هتل یا محل اقامت در کشور مقصد | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `host_or_hotel_address` | آدرس شخص دعوت کننده/هتل یا محل اقامت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `host_or_hotel_phone` | شماره تماس دعوت کننده/هتل یا محل اقامت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `host_or_hotel_email` | ایمیل دعوت کننده/هتل یا محل اقامت | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `travel_funding` | نحوه تامین هزینه‌های سفر | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `self_funding_methods` | توسط خود شخص | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `sponsor_funding_methods` | توسط شخص حمایت کننده | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `financial_accounts` | حساب‌های مالی قابل ارایه | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `rial_balance` | میزان موجودی قابل ارایه ریالی | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `foreign_currency_balance` | میزان موجودی قابل ارایه ارزی (یورو/دلار) | `—` | MISSING | REVIEW |
| `traveler_evaluation` | `property_deeds_count` | تعداد اسناد ملکی قابل ارایه به نام متقاضی/همسر | `—` | MISSING | REVIEW |
| `complaint_suggestion` | `date` | تاریخ | `Field_8783_0_160` — تاریخ | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `complaint_suggestion` | `first_name` | نام | `Field_8783_0_147` — نام | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `complaint_suggestion` | `last_name` | نام خانوادگی | `Field_8783_0_148` — نام خانوادگی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `complaint_suggestion` | `mobile` | تلفن همراه | `Field_8783_0_149` — شماره همراه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `complaint_suggestion` | `subject` | موضوع شکایت / پیشنهاد | `Field_8783_0_210` — موضوع شکایت / پیشنهاد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `complaint_suggestion` | `message` | پیام شکایت / پیشنهاد | `Field_8783_1_211` — پیام شکایت / پیشنهاد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `first_name` | نام | `Field_8783_0_147` — نام | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `last_name` | نام خانوادگی | `Field_8783_0_148` — نام خانوادگی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `birth_surname` | نام خانوادگی زمان تولد | `Field_8783_0_170` — نام خانوادگی زمان تولد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `birth_date` | تاریخ تولد | `Field_8783_0_169` — تاریخ تولد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `birth_city` | شهر محل تولد | `Field_8783_0_171` — شهر محل تولد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `birth_country` | کشور محل تولد | `Field_8783_0_164` — کشور محل تولد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `current_nationality` | تابعیت فعلی | `Field_8783_0_172` — تابعیت فعلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `birth_nationality` | تابعیت زمان تولد | `Field_8783_0_173` — تابعیت زمان تولد | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `national_id` | کد ملی | `Field_8783_0_174` — کد ملی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `marital_status` | وضعیت تاهل | `Field_8783_0_175` — وضعیت تاهل | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `mobile` | موبایل | `Field_8783_0_149` — شماره همراه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `email` | ایمیل | `Field_8783_0_150` — ایمیل | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `residential_address` | آدرس کامل مسکونی | `Field_8783_0_176` — آدرس کامل مسکونی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `postal_code` | کد پستی | `Field_8783_0_177` — کد پستی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `academic_level` | وضعیت تحصیلی | `Field_8783_0_178` — وضعیت تحصیلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `invitation_type` | نوع دعوت‌نامه | `Field_8783_0_179` — نوع دعوت‌نامه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `passport_number` | شماره گذرنامه | `Field_8783_0_163` — شماره گذرنامه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `passport_expiry` | تاریخ انقضای گذرنامه | `Field_8783_0_180` — تاریخ انقضای گذرنامه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `passport_issuer_country` | کشور صادرکننده پاسپورت | `Field_8783_0_181` — کشور صادرکننده پاسپورت | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `travel_destination` | مقصد سفر | `Field_8783_0_182` — مقصد سفر | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `personal_photo` | عکس شخصی | `Field_8783_0_183` — عکس شخصی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `passport_main_page` | صفحه اصلی گذرنامه | `Field_8783_0_184` — صفحه اصلی گذرنامه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `round_trip_ticket` | بلیط رفت و برگشت | `Field_8783_0_185` — بلیط رفت و برگشت | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `other_documents` | سایر مدارک | `Field_8783_0_186` — سایر مدارک | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `account_balance` | مانده حساب (تومان) | `Field_8783_0_187` — مانده حساب (تومان) | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `six_month_turnover` | مجموع گردش ۶ ماهه | `Field_8783_0_188` — مجموع گردش ۶ ماهه | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `has_foreign_currency_account` | دارای حساب ارزی هستید؟ | `Field_8783_0_189` — دارای حساب ارزی هستید؟ | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `has_property_deed` | سند ملکی به نام متقاضی | `Field_8783_0_190` — سند ملکی به نام متقاضی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `passive_income` | درآمد غیرفعال | `Field_8783_0_191` — درآمد غیرفعال | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `employment_type` | نوع شغل | `Field_8783_0_192` — نوع شغل | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `occupation` | عنوان شغلی | `Field_8783_0_193` — عنوان شغلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `workplace_name` | نام محل کار | `Field_8783_0_194` — نام محل کار | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `job_title` | سمت شغلی | `Field_8783_0_195` — سمت شغلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `employment_documents` | چک‌لیست مدارک شغلی | `Field_8783_0_196` — چک‌لیست مدارک شغلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `has_rejection` | سابقه ریجکتی | `Field_8783_0_197` — سابقه ریجکتی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `rejection_embassy` | نام سفارت ریجکت‌کننده | `Field_8783_0_198` — نام سفارت ریجکت‌کننده | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `rejection_date` | تاریخ ریجکتی | `Field_8783_0_199` — تاریخ ریجکتی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `has_previous_schengen` | سابقه ویزای شنگن قبلی | `Field_8783_0_200` — سابقه ویزای شنگن قبلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `previous_schengen_country` | نام کشور ویزای شنگن قبلی | `Field_8783_0_201` — نام کشور ویزای شنگن قبلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `previous_schengen_date` | تاریخ ویزای شنگن قبلی | `Field_8783_0_202` — تاریخ ویزای شنگن قبلی | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `estimated_travel_date` | تاریخ حدودی سفر | `Field_8783_0_203` — تاریخ حدودی سفر | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `schengen_exit_place` | محل خروج از شنگن (کشور/شهر) | `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) | CONFIGURED | NEEDS LIVE DIDAR CHECK |
| `visa_request` | `companions` | لیست همراهان | `Field_8783_1_212` — لیست همراهان | CONFIGURED | NEEDS LIVE DIDAR CHECK |

Important current findings: `embassy_appointment` و `traveler_evaluation` چهار mapping با نام‌های `FirstName`, `LastName`, `MobilePhone`, `Email` در target=`deal_custom` دارند؛ این‌ها در cache به‌عنوان Custom Field یافت نشدند و باید در production به‌عنوان native property یا Custom Field واقعی بررسی شوند. همچنین `visa_request.companions` فعلاً یک Deal mapping قدیمی دارد و به‌دلیل وجود Case representation باید فقط پس از تأیید business cleanup به‌صورت association بررسی/حذف شود؛ خود field حذف نشود.

## 9. Workflow / Deal Pipeline

| Form Type | Pipeline title | Pipeline ID | Status | Stage title/ID | Owner behavior | Public status |
|---|---|---|---|---|---|---|
| `consultation` | درخواست مشاوره | `8aa0d6fd-3e82-484c-b82f-0d7ade16df74` | pending_review: در انتظار بررسی → c2bd0375-8d12-43a0-bb54-c11f58885e37 (در انتظار بررسی)<br>eslah: نیاز به اصلاح مدارک → 0196b6c1-c3c3-483b-84b1-7bf709ae14ed (نیاز به اصلاح مدارک)<br>first-approval: تایید اولیه → 41a1aa0f-0d29-4f27-ad7f-b0e36705f765 (تایید اولیه)<br>done: تکمیل شده → 3162dbe9-bac2-4ffc-baba-c10c88874777 (تکمیل شده) | — | default owner setting + per-form assignee where configured | optional Deal field; current global key `empty` |
| `embassy_appointment` | وقت سفارت | `fc0d4e4b-f529-4b42-918c-0ea5f4d7538c` | pending_review: در انتظار بررسی → 5624059b-8c63-4b43-9258-433c7dcf6301 (در انتظار بررسی)<br>eslah: نیاز به اصلاح مدارک → 7aadcbd2-3ac5-44c7-adb2-36ddbc637afa (نیاز به اصلاح مدارک)<br>first-approval: تایید اولیه → 0c465c13-d0cc-4258-9af3-64aa2a616669 (تایید اولیه)<br>done: تکمیل شده → 78c9601e-62a3-46d5-a592-88250d78f996 (تکمیل شده) | — | default owner setting + per-form assignee where configured | optional Deal field; current global key `empty` |
| `traveler_evaluation` | فرم ارزیابی اطلاعات مسافران ویزاخان | `7a26a059-80f9-4d67-bfe0-20ad18a4956c` | pending_review: در انتظار بررسی → 34b1a103-6efc-4a82-afca-5d9a0a191ff6 (در انتظار بررسی) | — | default owner setting + per-form assignee where configured | optional Deal field; current global key `empty` |
| `complaint_suggestion` | ثبت شکایات و پیشنهادات ویزاخان | `75092f87-279b-4c32-84b6-cf054bb8ff7e` | pending_review: در انتظار بررسی → 3e722bae-e44f-4657-97e5-e61cfea83183 (در انتظار بررسی)<br>eslah: نیاز به اصلاح مدارک → b37260b7-44f8-421f-82c4-7c63e065ccc1 (نیاز به اصلاح مدارک)<br>first-approval: تایید اولیه → 7926bd17-8d48-4855-96b0-e817b6a0bc31 (تایید اولیه)<br>done: تکمیل شده → 278f7216-d7b7-4046-95ab-e057ce552cac (تکمیل شده) | — | default owner setting + per-form assignee where configured | optional Deal field; current global key `empty` |
| `visa_request` | درخواست ویزا | `654a4f93-f12f-4a8c-9d56-c6bb0e87d8b3` | pending_review: در انتظار بررسی → 15c55603-f316-4c30-a73d-afe1f2419828 (در انتظار بررسی)<br>eslah: نیاز به اصلاح مدارک → 281b4d97-115c-4010-bc15-3e9d589689b9 (نیاز به اصلاح مدارک)<br>first-approval: تایید اولیه → 42378acf-5726-4a79-919e-b0c7e406e75e (تایید اولیه)<br>done: تکمیل شده → c98f89ea-add8-4599-9e53-322bc2139fdb (تکمیل شده) | — | default owner setting + per-form assignee where configured | optional Deal field; current global key `empty` |

Configured default Deal owner is present (ID intentionally not repeated here); Work must validate it against the production Didar User list.

## 10. Canonical expected Deal pipeline–field matrix

Rules: KEEP means association belongs to this form/pipeline; REMOVE means only uncheck this pipeline association, never delete the field; REVIEW means ambiguous/shared or current target needs live confirmation; SYSTEM means shared system field.

### درخواست مشاوره / `consultation`

**KEEP**

- `Field_8783_0_147` — نام ← `first_name` (نام)
- `Field_8783_0_148` — نام خانوادگی ← `last_name` (نام خانوادگی)
- `Field_8783_0_149` — شماره همراه ← `input_3` (شماره همراه)
- `Field_8783_0_150` — ایمیل ← `email` (ایمیل)
- `Field_8783_0_151` — موضوع مشاوره ← `input_5` (موضوع مشاوره)
- `Field_8783_1_152` — توضیحات ← `description` (توضیحات)

**REMOVE IF CURRENTLY LINKED**

- `Field_8783_0_160` — تاریخ from `complaint_suggestion.date`
- `Field_8783_0_210` — موضوع شکایت / پیشنهاد from `complaint_suggestion.subject`
- `Field_8783_1_211` — پیام شکایت / پیشنهاد from `complaint_suggestion.message`
- `Field_8783_0_170` — نام خانوادگی زمان تولد from `visa_request.birth_surname`
- `Field_8783_0_171` — شهر محل تولد from `visa_request.birth_city`
- `Field_8783_0_164` — کشور محل تولد from `visa_request.birth_country`
- `Field_8783_0_172` — تابعیت فعلی from `visa_request.current_nationality`
- `Field_8783_0_173` — تابعیت زمان تولد from `visa_request.birth_nationality`
- `Field_8783_0_175` — وضعیت تاهل from `visa_request.marital_status`
- `Field_8783_0_176` — آدرس کامل مسکونی from `visa_request.residential_address`
- `Field_8783_0_177` — کد پستی from `visa_request.postal_code`
- `Field_8783_0_178` — وضعیت تحصیلی from `visa_request.academic_level`
- `Field_8783_0_180` — تاریخ انقضای گذرنامه from `visa_request.passport_expiry`
- `Field_8783_0_181` — کشور صادرکننده پاسپورت from `visa_request.passport_issuer_country`
- `Field_8783_0_182` — مقصد سفر from `visa_request.travel_destination`
- `Field_8783_0_183` — عکس شخصی from `visa_request.personal_photo`
- `Field_8783_0_184` — صفحه اصلی گذرنامه from `visa_request.passport_main_page`
- `Field_8783_0_185` — بلیط رفت و برگشت from `visa_request.round_trip_ticket`
- `Field_8783_0_186` — سایر مدارک from `visa_request.other_documents`
- `Field_8783_0_187` — مانده حساب (تومان) from `visa_request.account_balance`
- `Field_8783_0_188` — مجموع گردش ۶ ماهه from `visa_request.six_month_turnover`
- `Field_8783_0_189` — دارای حساب ارزی هستید؟ from `visa_request.has_foreign_currency_account`
- `Field_8783_0_190` — سند ملکی به نام متقاضی from `visa_request.has_property_deed`
- `Field_8783_0_191` — درآمد غیرفعال from `visa_request.passive_income`
- `Field_8783_0_192` — نوع شغل from `visa_request.employment_type`
- `Field_8783_0_193` — عنوان شغلی from `visa_request.occupation`
- `Field_8783_0_194` — نام محل کار from `visa_request.workplace_name`
- `Field_8783_0_195` — سمت شغلی from `visa_request.job_title`
- `Field_8783_0_196` — چک‌لیست مدارک شغلی from `visa_request.employment_documents`
- `Field_8783_0_197` — سابقه ریجکتی from `visa_request.has_rejection`
- `Field_8783_0_198` — نام سفارت ریجکت‌کننده from `visa_request.rejection_embassy`
- `Field_8783_0_199` — تاریخ ریجکتی from `visa_request.rejection_date`
- `Field_8783_0_200` — سابقه ویزای شنگن قبلی from `visa_request.has_previous_schengen`
- `Field_8783_0_201` — نام کشور ویزای شنگن قبلی from `visa_request.previous_schengen_country`
- `Field_8783_0_202` — تاریخ ویزای شنگن قبلی from `visa_request.previous_schengen_date`
- `Field_8783_0_203` — تاریخ حدودی سفر from `visa_request.estimated_travel_date`
- `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) from `visa_request.schengen_exit_place`
- `Field_8783_1_212` — لیست همراهان from `visa_request.companions`
- `Field_8783_0_179` — نوع دعوت‌نامه from `visa_request.invitation_type`

**REVIEW**

- Shared identity fields; current target title/entity/type must be checked live.

**SYSTEM**

- `Field_8783_12_154` — WordPress Submission ID
- `Field_8783_0_153` — Form Type
- `Field_8783_0_155` — WordPress User ID
- `didar_public_status_field_id` — Public Status

### وقت سفارت / `embassy_appointment`

**KEEP**

- `FirstName` — first_name ← `first_name` (نام)
- `LastName` — last_name ← `last_name` (نام خانوادگی)
- `MobilePhone` — mobile ← `mobile` (شماره موبایل)
- `Email` — email ← `email` (ایمیل)

**REMOVE IF CURRENTLY LINKED**

- `Field_8783_0_151` — موضوع مشاوره from `consultation.input_5`
- `Field_8783_1_152` — توضیحات from `consultation.description`
- `Field_8783_0_160` — تاریخ from `complaint_suggestion.date`
- `Field_8783_0_210` — موضوع شکایت / پیشنهاد from `complaint_suggestion.subject`
- `Field_8783_1_211` — پیام شکایت / پیشنهاد from `complaint_suggestion.message`
- `Field_8783_0_170` — نام خانوادگی زمان تولد from `visa_request.birth_surname`
- `Field_8783_0_171` — شهر محل تولد from `visa_request.birth_city`
- `Field_8783_0_164` — کشور محل تولد from `visa_request.birth_country`
- `Field_8783_0_172` — تابعیت فعلی from `visa_request.current_nationality`
- `Field_8783_0_173` — تابعیت زمان تولد from `visa_request.birth_nationality`
- `Field_8783_0_175` — وضعیت تاهل from `visa_request.marital_status`
- `Field_8783_0_176` — آدرس کامل مسکونی from `visa_request.residential_address`
- `Field_8783_0_177` — کد پستی from `visa_request.postal_code`
- `Field_8783_0_178` — وضعیت تحصیلی from `visa_request.academic_level`
- `Field_8783_0_180` — تاریخ انقضای گذرنامه from `visa_request.passport_expiry`
- `Field_8783_0_181` — کشور صادرکننده پاسپورت from `visa_request.passport_issuer_country`
- `Field_8783_0_182` — مقصد سفر from `visa_request.travel_destination`
- `Field_8783_0_183` — عکس شخصی from `visa_request.personal_photo`
- `Field_8783_0_184` — صفحه اصلی گذرنامه from `visa_request.passport_main_page`
- `Field_8783_0_185` — بلیط رفت و برگشت from `visa_request.round_trip_ticket`
- `Field_8783_0_186` — سایر مدارک from `visa_request.other_documents`
- `Field_8783_0_187` — مانده حساب (تومان) from `visa_request.account_balance`
- `Field_8783_0_188` — مجموع گردش ۶ ماهه from `visa_request.six_month_turnover`
- `Field_8783_0_189` — دارای حساب ارزی هستید؟ from `visa_request.has_foreign_currency_account`
- `Field_8783_0_190` — سند ملکی به نام متقاضی from `visa_request.has_property_deed`
- `Field_8783_0_191` — درآمد غیرفعال from `visa_request.passive_income`
- `Field_8783_0_192` — نوع شغل from `visa_request.employment_type`
- `Field_8783_0_193` — عنوان شغلی from `visa_request.occupation`
- `Field_8783_0_194` — نام محل کار from `visa_request.workplace_name`
- `Field_8783_0_195` — سمت شغلی from `visa_request.job_title`
- `Field_8783_0_196` — چک‌لیست مدارک شغلی from `visa_request.employment_documents`
- `Field_8783_0_197` — سابقه ریجکتی from `visa_request.has_rejection`
- `Field_8783_0_198` — نام سفارت ریجکت‌کننده from `visa_request.rejection_embassy`
- `Field_8783_0_199` — تاریخ ریجکتی from `visa_request.rejection_date`
- `Field_8783_0_200` — سابقه ویزای شنگن قبلی from `visa_request.has_previous_schengen`
- `Field_8783_0_201` — نام کشور ویزای شنگن قبلی from `visa_request.previous_schengen_country`
- `Field_8783_0_202` — تاریخ ویزای شنگن قبلی from `visa_request.previous_schengen_date`
- `Field_8783_0_203` — تاریخ حدودی سفر from `visa_request.estimated_travel_date`
- `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) from `visa_request.schengen_exit_place`
- `Field_8783_1_212` — لیست همراهان from `visa_request.companions`
- `Field_8783_0_179` — نوع دعوت‌نامه from `visa_request.invitation_type`

**REVIEW**

- Shared identity fields; current target title/entity/type must be checked live.

**SYSTEM**

- `Field_8783_12_154` — WordPress Submission ID
- `Field_8783_0_153` — Form Type
- `Field_8783_0_155` — WordPress User ID
- `didar_public_status_field_id` — Public Status

### فرم ارزیابی اطلاعات مسافران ویزاخان / `traveler_evaluation`

**KEEP**

- `FirstName` — first_name ← `first_name` (نام)
- `LastName` — last_name ← `last_name` (نام خانوادگی)
- `MobilePhone` — mobile ← `mobile` (تلفن همراه)
- `Email` — email ← `email` (ایمیل)

**REMOVE IF CURRENTLY LINKED**

- `Field_8783_0_151` — موضوع مشاوره from `consultation.input_5`
- `Field_8783_1_152` — توضیحات from `consultation.description`
- `Field_8783_0_160` — تاریخ from `complaint_suggestion.date`
- `Field_8783_0_210` — موضوع شکایت / پیشنهاد from `complaint_suggestion.subject`
- `Field_8783_1_211` — پیام شکایت / پیشنهاد from `complaint_suggestion.message`
- `Field_8783_0_170` — نام خانوادگی زمان تولد from `visa_request.birth_surname`
- `Field_8783_0_171` — شهر محل تولد from `visa_request.birth_city`
- `Field_8783_0_164` — کشور محل تولد from `visa_request.birth_country`
- `Field_8783_0_172` — تابعیت فعلی from `visa_request.current_nationality`
- `Field_8783_0_173` — تابعیت زمان تولد from `visa_request.birth_nationality`
- `Field_8783_0_175` — وضعیت تاهل from `visa_request.marital_status`
- `Field_8783_0_176` — آدرس کامل مسکونی from `visa_request.residential_address`
- `Field_8783_0_177` — کد پستی from `visa_request.postal_code`
- `Field_8783_0_178` — وضعیت تحصیلی from `visa_request.academic_level`
- `Field_8783_0_180` — تاریخ انقضای گذرنامه from `visa_request.passport_expiry`
- `Field_8783_0_181` — کشور صادرکننده پاسپورت from `visa_request.passport_issuer_country`
- `Field_8783_0_182` — مقصد سفر from `visa_request.travel_destination`
- `Field_8783_0_183` — عکس شخصی from `visa_request.personal_photo`
- `Field_8783_0_184` — صفحه اصلی گذرنامه from `visa_request.passport_main_page`
- `Field_8783_0_185` — بلیط رفت و برگشت from `visa_request.round_trip_ticket`
- `Field_8783_0_186` — سایر مدارک from `visa_request.other_documents`
- `Field_8783_0_187` — مانده حساب (تومان) from `visa_request.account_balance`
- `Field_8783_0_188` — مجموع گردش ۶ ماهه from `visa_request.six_month_turnover`
- `Field_8783_0_189` — دارای حساب ارزی هستید؟ from `visa_request.has_foreign_currency_account`
- `Field_8783_0_190` — سند ملکی به نام متقاضی from `visa_request.has_property_deed`
- `Field_8783_0_191` — درآمد غیرفعال from `visa_request.passive_income`
- `Field_8783_0_192` — نوع شغل from `visa_request.employment_type`
- `Field_8783_0_193` — عنوان شغلی from `visa_request.occupation`
- `Field_8783_0_194` — نام محل کار from `visa_request.workplace_name`
- `Field_8783_0_195` — سمت شغلی from `visa_request.job_title`
- `Field_8783_0_196` — چک‌لیست مدارک شغلی from `visa_request.employment_documents`
- `Field_8783_0_197` — سابقه ریجکتی from `visa_request.has_rejection`
- `Field_8783_0_198` — نام سفارت ریجکت‌کننده from `visa_request.rejection_embassy`
- `Field_8783_0_199` — تاریخ ریجکتی from `visa_request.rejection_date`
- `Field_8783_0_200` — سابقه ویزای شنگن قبلی from `visa_request.has_previous_schengen`
- `Field_8783_0_201` — نام کشور ویزای شنگن قبلی from `visa_request.previous_schengen_country`
- `Field_8783_0_202` — تاریخ ویزای شنگن قبلی from `visa_request.previous_schengen_date`
- `Field_8783_0_203` — تاریخ حدودی سفر from `visa_request.estimated_travel_date`
- `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) from `visa_request.schengen_exit_place`
- `Field_8783_1_212` — لیست همراهان from `visa_request.companions`
- `Field_8783_0_179` — نوع دعوت‌نامه from `visa_request.invitation_type`

**REVIEW**

- Shared identity fields; current target title/entity/type must be checked live.

**SYSTEM**

- `Field_8783_12_154` — WordPress Submission ID
- `Field_8783_0_153` — Form Type
- `Field_8783_0_155` — WordPress User ID
- `didar_public_status_field_id` — Public Status

### ثبت شکایات و پیشنهادات ویزاخان / `complaint_suggestion`

**KEEP**

- `Field_8783_0_147` — نام ← `first_name` (نام)
- `Field_8783_0_148` — نام خانوادگی ← `last_name` (نام خانوادگی)
- `Field_8783_0_149` — شماره همراه ← `mobile` (تلفن همراه)
- `Field_8783_0_160` — تاریخ ← `date` (تاریخ)
- `Field_8783_0_210` — موضوع شکایت / پیشنهاد ← `subject` (موضوع شکایت / پیشنهاد)
- `Field_8783_1_211` — پیام شکایت / پیشنهاد ← `message` (پیام شکایت / پیشنهاد)

**REMOVE IF CURRENTLY LINKED**

- `Field_8783_0_151` — موضوع مشاوره from `consultation.input_5`
- `Field_8783_1_152` — توضیحات from `consultation.description`
- `Field_8783_0_170` — نام خانوادگی زمان تولد from `visa_request.birth_surname`
- `Field_8783_0_171` — شهر محل تولد from `visa_request.birth_city`
- `Field_8783_0_164` — کشور محل تولد from `visa_request.birth_country`
- `Field_8783_0_172` — تابعیت فعلی from `visa_request.current_nationality`
- `Field_8783_0_173` — تابعیت زمان تولد from `visa_request.birth_nationality`
- `Field_8783_0_175` — وضعیت تاهل from `visa_request.marital_status`
- `Field_8783_0_176` — آدرس کامل مسکونی from `visa_request.residential_address`
- `Field_8783_0_177` — کد پستی from `visa_request.postal_code`
- `Field_8783_0_178` — وضعیت تحصیلی from `visa_request.academic_level`
- `Field_8783_0_180` — تاریخ انقضای گذرنامه from `visa_request.passport_expiry`
- `Field_8783_0_181` — کشور صادرکننده پاسپورت from `visa_request.passport_issuer_country`
- `Field_8783_0_182` — مقصد سفر from `visa_request.travel_destination`
- `Field_8783_0_183` — عکس شخصی from `visa_request.personal_photo`
- `Field_8783_0_184` — صفحه اصلی گذرنامه from `visa_request.passport_main_page`
- `Field_8783_0_185` — بلیط رفت و برگشت from `visa_request.round_trip_ticket`
- `Field_8783_0_186` — سایر مدارک from `visa_request.other_documents`
- `Field_8783_0_187` — مانده حساب (تومان) from `visa_request.account_balance`
- `Field_8783_0_188` — مجموع گردش ۶ ماهه from `visa_request.six_month_turnover`
- `Field_8783_0_189` — دارای حساب ارزی هستید؟ from `visa_request.has_foreign_currency_account`
- `Field_8783_0_190` — سند ملکی به نام متقاضی from `visa_request.has_property_deed`
- `Field_8783_0_191` — درآمد غیرفعال from `visa_request.passive_income`
- `Field_8783_0_192` — نوع شغل from `visa_request.employment_type`
- `Field_8783_0_193` — عنوان شغلی from `visa_request.occupation`
- `Field_8783_0_194` — نام محل کار from `visa_request.workplace_name`
- `Field_8783_0_195` — سمت شغلی from `visa_request.job_title`
- `Field_8783_0_196` — چک‌لیست مدارک شغلی from `visa_request.employment_documents`
- `Field_8783_0_197` — سابقه ریجکتی from `visa_request.has_rejection`
- `Field_8783_0_198` — نام سفارت ریجکت‌کننده from `visa_request.rejection_embassy`
- `Field_8783_0_199` — تاریخ ریجکتی from `visa_request.rejection_date`
- `Field_8783_0_200` — سابقه ویزای شنگن قبلی from `visa_request.has_previous_schengen`
- `Field_8783_0_201` — نام کشور ویزای شنگن قبلی from `visa_request.previous_schengen_country`
- `Field_8783_0_202` — تاریخ ویزای شنگن قبلی from `visa_request.previous_schengen_date`
- `Field_8783_0_203` — تاریخ حدودی سفر from `visa_request.estimated_travel_date`
- `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) from `visa_request.schengen_exit_place`
- `Field_8783_1_212` — لیست همراهان from `visa_request.companions`
- `Field_8783_0_179` — نوع دعوت‌نامه from `visa_request.invitation_type`

**REVIEW**

- Shared identity fields; current target title/entity/type must be checked live.

**SYSTEM**

- `Field_8783_12_154` — WordPress Submission ID
- `Field_8783_0_153` — Form Type
- `Field_8783_0_155` — WordPress User ID
- `didar_public_status_field_id` — Public Status

### درخواست ویزا / `visa_request`

**KEEP**

- `Field_8783_0_170` — نام خانوادگی زمان تولد ← `birth_surname` (نام خانوادگی زمان تولد)
- `Field_8783_0_169` — تاریخ تولد ← `birth_date` (تاریخ تولد)
- `Field_8783_0_171` — شهر محل تولد ← `birth_city` (شهر محل تولد)
- `Field_8783_0_164` — کشور محل تولد ← `birth_country` (کشور محل تولد)
- `Field_8783_0_172` — تابعیت فعلی ← `current_nationality` (تابعیت فعلی)
- `Field_8783_0_173` — تابعیت زمان تولد ← `birth_nationality` (تابعیت زمان تولد)
- `Field_8783_0_174` — کد ملی ← `national_id` (کد ملی)
- `Field_8783_0_175` — وضعیت تاهل ← `marital_status` (وضعیت تاهل)
- `Field_8783_0_149` — شماره همراه ← `mobile` (موبایل)
- `Field_8783_0_150` — ایمیل ← `email` (ایمیل)
- `Field_8783_0_176` — آدرس کامل مسکونی ← `residential_address` (آدرس کامل مسکونی)
- `Field_8783_0_177` — کد پستی ← `postal_code` (کد پستی)
- `Field_8783_0_178` — وضعیت تحصیلی ← `academic_level` (وضعیت تحصیلی)
- `Field_8783_0_163` — شماره گذرنامه ← `passport_number` (شماره گذرنامه)
- `Field_8783_0_180` — تاریخ انقضای گذرنامه ← `passport_expiry` (تاریخ انقضای گذرنامه)
- `Field_8783_0_181` — کشور صادرکننده پاسپورت ← `passport_issuer_country` (کشور صادرکننده پاسپورت)
- `Field_8783_0_182` — مقصد سفر ← `travel_destination` (مقصد سفر)
- `Field_8783_0_183` — عکس شخصی ← `personal_photo` (عکس شخصی)
- `Field_8783_0_184` — صفحه اصلی گذرنامه ← `passport_main_page` (صفحه اصلی گذرنامه)
- `Field_8783_0_185` — بلیط رفت و برگشت ← `round_trip_ticket` (بلیط رفت و برگشت)
- `Field_8783_0_186` — سایر مدارک ← `other_documents` (سایر مدارک)
- `Field_8783_0_187` — مانده حساب (تومان) ← `account_balance` (مانده حساب (تومان))
- `Field_8783_0_188` — مجموع گردش ۶ ماهه ← `six_month_turnover` (مجموع گردش ۶ ماهه)
- `Field_8783_0_189` — دارای حساب ارزی هستید؟ ← `has_foreign_currency_account` (دارای حساب ارزی هستید؟)
- `Field_8783_0_190` — سند ملکی به نام متقاضی ← `has_property_deed` (سند ملکی به نام متقاضی)
- `Field_8783_0_191` — درآمد غیرفعال ← `passive_income` (درآمد غیرفعال)
- `Field_8783_0_192` — نوع شغل ← `employment_type` (نوع شغل)
- `Field_8783_0_193` — عنوان شغلی ← `occupation` (عنوان شغلی)
- `Field_8783_0_194` — نام محل کار ← `workplace_name` (نام محل کار)
- `Field_8783_0_195` — سمت شغلی ← `job_title` (سمت شغلی)
- `Field_8783_0_196` — چک‌لیست مدارک شغلی ← `employment_documents` (چک‌لیست مدارک شغلی)
- `Field_8783_0_197` — سابقه ریجکتی ← `has_rejection` (سابقه ریجکتی)
- `Field_8783_0_198` — نام سفارت ریجکت‌کننده ← `rejection_embassy` (نام سفارت ریجکت‌کننده)
- `Field_8783_0_199` — تاریخ ریجکتی ← `rejection_date` (تاریخ ریجکتی)
- `Field_8783_0_200` — سابقه ویزای شنگن قبلی ← `has_previous_schengen` (سابقه ویزای شنگن قبلی)
- `Field_8783_0_201` — نام کشور ویزای شنگن قبلی ← `previous_schengen_country` (نام کشور ویزای شنگن قبلی)
- `Field_8783_0_202` — تاریخ ویزای شنگن قبلی ← `previous_schengen_date` (تاریخ ویزای شنگن قبلی)
- `Field_8783_0_203` — تاریخ حدودی سفر ← `estimated_travel_date` (تاریخ حدودی سفر)
- `Field_8783_0_204` — محل خروج از شنگن (کشور/شهر) ← `schengen_exit_place` (محل خروج از شنگن (کشور/شهر))
- `Field_8783_1_212` — لیست همراهان ← `companions` (لیست همراهان)
- `Field_8783_0_147` — نام ← `first_name` (نام)
- `Field_8783_0_148` — نام خانوادگی ← `last_name` (نام خانوادگی)
- `Field_8783_0_179` — نوع دعوت‌نامه ← `invitation_type` (نوع دعوت‌نامه)
- `companions` Deal field is **REVIEW** because active companion representation is Case-based.

**REMOVE IF CURRENTLY LINKED**

- `Field_8783_0_151` — موضوع مشاوره from `consultation.input_5`
- `Field_8783_1_152` — توضیحات from `consultation.description`
- `Field_8783_0_160` — تاریخ from `complaint_suggestion.date`
- `Field_8783_0_210` — موضوع شکایت / پیشنهاد from `complaint_suggestion.subject`
- `Field_8783_1_211` — پیام شکایت / پیشنهاد from `complaint_suggestion.message`

**REVIEW**

- Shared identity fields; current target title/entity/type must be checked live.
- `companions` legacy Deal field versus Case-only desired end state.

**SYSTEM**

- `Field_8783_12_154` — WordPress Submission ID
- `Field_8783_0_153` — Form Type
- `Field_8783_0_155` — WordPress User ID
- `didar_public_status_field_id` — Public Status

## 11. Visa companion Case architecture

- Confirmed Case API surface used by the current source: `POST /api/pipeline/list/1`, `POST /api/customfield/GetCustomfieldList`, `POST /api/Case/search`, and `POST /api/Case/Save_v2`. No Case delete/archive endpoint is treated as confirmed.
- Form Type: `visa_request`.
- WordPress: one `didar_submission` → `_didar_fields['companions']` repeater.
- Child keys: `full_name`, `age`, `occupation`, `national_id`, `passport_number`, `email`, `phone`, `personal_photo`, `passport_main_page`, `round_trip_ticket`, `other_documents`.
- File child fields: `personal_photo`, `passport_main_page`, `round_trip_ticket`, `other_documents`; each allows multiple files, maximum 2 per child field and 5MB per file.
- Stable identity: `companion_uid` generated as `cmp_<UUID>` and persisted in the same Submission.
- Local mappings: `_didar_companion_cases` keyed by UID; runtime values are not exported.
- Resolution: stored Case ID → exact Submission ID + Companion UID lookup → create only after zero exact matches.
- New Case omits `Id`; existing Case update uses persisted Case ID.
- Case payload links with `DealId = main Didar Deal ID`; Category is optional and omitted when empty.
- Removed companion: local mapping is marked `removed`; no remote Case delete/archive endpoint is confirmed, so remote Case remains untouched and its ID is not reused.

### Current Case configuration

- Pipeline: لیست همراهان درخواست ویزا (`7a08a1b0-0206-4c44-a837-3e2784e86940`)
- Initial Stage: در انتظار بررسی (`029adfab-bfc7-4a21-bcaf-69635fdf159e`)
- Category: empty; valid and non-blocking

### Case field mappings

| Companion Field Key | Label | Current Case Field ID/key | Mapping Status | Expected Entity |
|---|---|---|---|---|
| `full_name` | نام و نام خانوادگی | `Field_8785_0_261` — نام و نام خانوادگی | CONFIGURED | Case |
| `age` | سن | `Field_8785_12_262` — سن | CONFIGURED | Case |
| `occupation` | شغل | `Field_8785_0_263` — شغل | CONFIGURED | Case |
| `national_id` | کد ملی | `Field_8785_0_264` — کد ملی | CONFIGURED | Case |
| `passport_number` | شماره گذرنامه | `Field_8785_0_265` — شماره گذرنامه | CONFIGURED | Case |
| `email` | ایمیل | `Field_8785_0_266` — ایمیل | CONFIGURED | Case |
| `phone` | شماره تماس | `Field_8785_0_267` — شماره تماس | CONFIGURED | Case |
| `personal_photo` | عکس شخصی | `Field_8785_0_268` — عکس شخصی | CONFIGURED | Case |
| `passport_main_page` | صفحه اصلی گذرنامه | `Field_8785_0_269` — صفحه اصلی گذرنامه | CONFIGURED | Case |
| `round_trip_ticket` | بلیط رفت و برگشت | `Field_8785_0_270` — بلیط رفت و برگشت | CONFIGURED | Case |
| `other_documents` | سایر مدارک | `Field_8785_0_271` — سایر مدارک | CONFIGURED | Case |

| System purpose | Current Case Field ID/key | Status |
|---|---|---|
| submission_id | `Field_8785_12_258` — Submission ID | CONFIGURED |
| companion_uid | `Field_8785_0_259` — Companion UID | CONFIGURED |
| form_type | `Field_8785_0_260` — Form Type | CONFIGURED |

The local Case metadata cache currently reports `Number` for the mapped `age` and `Submission ID` fields. This is an audit flag only: the production policy for business Custom Fields is still **متن کوتاه** or **متن بلند**. Work must not change an existing field type automatically; verify the live field and stop if type correction would be required.

### Case live audit

Work must confirm: pipeline exists; stage belongs to it; all mapped fields exist with `FieldType=Case`; no Deal/Person fields are selected; system field keys are unique; empty Category remains allowed; create omits `Id`; update includes the existing Case ID.

## 12. File field inventory

| Form Type | Field Key | Label | Single/Multiple | Deal/Case | Didar representation |
|---|---|---|---|---|---|
| `visa_request` | `personal_photo` | عکس شخصی | Multiple | Deal legacy + Case child where mapped | Didar_File_Service owns binary; serializer sends safe readable URL/name reference only; no server/temp path |
| `visa_request` | `passport_main_page` | صفحه اصلی گذرنامه | Multiple | Deal legacy + Case child where mapped | Didar_File_Service owns binary; serializer sends safe readable URL/name reference only; no server/temp path |
| `visa_request` | `round_trip_ticket` | بلیط رفت و برگشت | Multiple | Deal legacy + Case child where mapped | Didar_File_Service owns binary; serializer sends safe readable URL/name reference only; no server/temp path |
| `visa_request` | `other_documents` | سایر مدارک | Multiple | Deal legacy + Case child where mapped | Didar_File_Service owns binary; serializer sends safe readable URL/name reference only; no server/temp path |
| `visa_request` | `companions.*.personal_photo` | عکس شخصی | Multiple | Case | same safe textual reference rule |
| `visa_request` | `companions.*.passport_main_page` | صفحه اصلی گذرنامه | Multiple | Case | same safe textual reference rule |
| `visa_request` | `companions.*.round_trip_ticket` | بلیط رفت و برگشت | Multiple | Case | same safe textual reference rule |
| `visa_request` | `companions.*.other_documents` | سایر مدارک | Multiple | Case | same safe textual reference rule |

Local files are stored in the plugin private storage/table architecture. Deleting a Submission deletes file records. Secure/direct download mode is a WordPress setting; do not copy customer URLs into this report.

## 13. WordPress User → Didar Person

- Person identity source is WordPress User, not request snapshot fields.
- Native Person payload: `FirstName`, `LastName`, `Email`, `MobilePhone`, `OwnerId`.
- Current custom profile mappings: `gender` → `Field_996_0_207`، `profile_image_url` → `Field_996_0_206`؛ other profile fields are not currently mapped.
- Profile fields include first_name, last_name, mobile, email, gender, birth_date, national_id and profile image policy. Mobile is effectively readonly except verified Digits change flow.
- Submission fields remain Deal snapshot data; do not alter Person mapping to fix a Deal field.

## 14. Current settings audit

| Area | Status | Evidence/Work action |
|---|---|---|
| Didar API configuration | READY | credential presence only; secret withheld |
| Webhook | READY | secret presence only; events currently support Deal/Person, not Case |
| Deal workflows | READY locally / LIVE CHECK | five per-form workflows present; validate titles/stages in production |
| Deal mappings | SUSPECT | 63 raw mappings; native-looking mappings and legacy companion Deal mapping require live check |
| Deal system fields | READY locally / LIVE CHECK | Submission ID, Form Type, User ID configured |
| Person mappings | PARTIAL/CONFIGURED | native identity plus two custom profile mappings; validate intended production fields |
| Case pipeline/stage | READY locally / LIVE CHECK | cached pipeline/stage pair present and belongs together locally |
| Case mappings | READY locally / LIVE CHECK | 11 Case child mappings + 3 system mappings; validate `FieldType=Case` live |
| Case category | READY | optional and empty; must not block Case sync |
| Import/export | READY | portable settings include Case config; runtime IDs/cache/PII excluded |
| Required overrides | NOT USED | current local setting has no overrides |
| File mode | CONFIGURED | current mode is not reproduced here; Work should inspect production setting |

## 15. Production WordPress audit plan

1. Open `https://visakhan.com/wp-admin/` in the authenticated session.
2. Open ns-didar settings; inspect API status without revealing secrets.
3. For each form, compare each active Field Key to the current mapping table. Preserve required/default settings unless a source contradiction is proven.
4. Treat `FirstName`, `LastName`, `MobilePhone`, `Email` as native-property suspects where currently stored as `deal_custom`; stop on ambiguity.
5. Inspect Case settings separately. Confirm Case pipeline/stage and all 11 child + 3 system mappings.
6. Do not paste production PII into this audit or logs. Do not create submissions during mapping audit.

## 16. Production Didar admin audit plan

1. Inspect only the five configured plugin Deal pipelines and the selected Case pipeline.
2. For each Deal Custom Field, compare actual title, FieldType, control type and pipeline associations with this report.
3. Add only a missing correct pipeline association; remove only an incorrect plugin association.
4. Never delete, rename, retag, or change type of an existing field. Leave unrelated pipelines untouched.
5. Reuse shared fields; create a field only when the source-of-truth proves it is missing, using only `متن کوتاه` or `متن بلند`.
6. Inspect Case fields as Case entity; do not reuse Deal/Contact fields for Cases.

## 17. Custom Field decision rules

| Live finding | Work action |
|---|---|
| Existing + correct title/entity/type + target association | KEEP/reuse |
| Existing + missing target association | Enable only target pipeline |
| Existing + wrong target association | Uncheck only wrong association |
| Existing but ambiguous duplicate/title/type | REVIEW; stop before changing |
| Missing | Create only with exact business title and `متن کوتاه`/`متن بلند`, then capture ID and update mapping deliberately |

## 18. Production test policy

- Use marker `QA NS-DIDAR FINAL TEST <timestamp>`.
- Use synthetic valid-format passport/national ID/mobile/email only; never real customer data or documents.
- For each form, create one controlled submission only after configuration audit.
- Visa Request test: one Submission, two companions, one Deal, two Cases, same `DealId`.
- After create, test update of main field and companion; add one companion; reorder if UX permits; verify stable IDs.
- Do not permanently delete production QA requests while Cases cannot be safely deleted/archived remotely. Leave records clearly marked and report IDs.

## 19. Per-form production QA scenarios

### درخواست مشاوره — `consultation`

- Entry: use the production page/button mapped to `consultation`; exact URL must be confirmed in WordPress.
- Populate identity fields, one representative date, one select/radio, and one long-text field where present; use synthetic valid-format data.
- Expected WordPress: exactly one parent Submission; no companion posts.
- Expected Didar: exactly one Deal in **درخواست مشاوره** at its default mapped stage; mapped fields only.

### درخواست وقت سفارت — `embassy_appointment`

- Entry: use the production page/button mapped to `embassy_appointment`; exact URL must be confirmed in WordPress.
- Populate identity fields, one representative date, one select/radio, and one long-text field where present; use synthetic valid-format data.
- Expected WordPress: exactly one parent Submission; no companion posts.
- Expected Didar: exactly one Deal in **وقت سفارت** at its default mapped stage; mapped fields only.

### فرم ارزیابی اطلاعات مسافران ویزاخان — `traveler_evaluation`

- Entry: use the production page/button mapped to `traveler_evaluation`; exact URL must be confirmed in WordPress.
- Populate identity fields, one representative date, one select/radio, and one long-text field where present; use synthetic valid-format data.
- Expected WordPress: exactly one parent Submission; no companion posts.
- Expected Didar: exactly one Deal in **فرم ارزیابی اطلاعات مسافران ویزاخان** at its default mapped stage; mapped fields only.

### ثبت شکایات و پیشنهادات ویزاخان — `complaint_suggestion`

- Entry: use the production page/button mapped to `complaint_suggestion`; exact URL must be confirmed in WordPress.
- Populate identity fields, one representative date, one select/radio, and one long-text field where present; use synthetic valid-format data.
- Expected WordPress: exactly one parent Submission; no companion posts.
- Expected Didar: exactly one Deal in **ثبت شکایات و پیشنهادات ویزاخان** at its default mapped stage; mapped fields only.

### درخواست ویزا — `visa_request`

- Entry: use the production page/button mapped to `visa_request`; exact URL must be confirmed in WordPress.
- Populate identity fields, one representative date, one select/radio, and one long-text field where present; use synthetic valid-format data.
- Include exactly two companion rows with synthetic names, uppercase valid-format passport values, optional files empty unless a safe fixture is approved.
- Expected WordPress: exactly one parent Submission; no companion posts.
- Expected Didar: exactly one Deal in **درخواست ویزا** at its default mapped stage; mapped fields only.
- Expected additional Didar: exactly two distinct Cases in the configured Case pipeline/stage; both `DealId` equal the main Deal ID; Category may be empty.

## 20. Update/retry verification

- Visa: edit a main field and companion A, sync once; same Submission/Deal/Case IDs. Add C: exactly one new Case. Reorder: no new Case and UID/Case association unchanged. Do not perform destructive delete tests in production.
- Other forms: edit several mapped fields and confirm existing Deal IDs are reused.
- Inspect `_didar_sync_state`, `_didar_companion_cases`, event logs and retry schedule; successful Cases must not be recreated.

## 21. CHATGPT WORK EXECUTION PACK

### A — Production targets

- WordPress Admin: `https://visakhan.com/wp-admin/`
- Didar: authenticated production admin session
- Scope: only ns-didar forms/pipelines/fields listed here.

### B — Plugin forms

| Persian Form Name | Form Type | Expected Deal Pipeline | Case Support |
|---|---|---|---|
| درخواست مشاوره | `consultation` | درخواست مشاوره | No |
| درخواست وقت سفارت | `embassy_appointment` | وقت سفارت | No |
| فرم ارزیابی اطلاعات مسافران ویزاخان | `traveler_evaluation` | فرم ارزیابی اطلاعات مسافران ویزاخان | No |
| ثبت شکایات و پیشنهادات ویزاخان | `complaint_suggestion` | ثبت شکایات و پیشنهادات ویزاخان | No |
| درخواست ویزا | `visa_request` | درخواست ویزا | companions → Case |

### C — Deal Custom Field matrix

**درخواست مشاوره / `consultation`**

- KEEP: mappings listed for this form in §8, after live entity/type/title confirmation.
- REMOVE: associations belonging only to another FORM_ONLY_FIELDS list in §6; uncheck association only.
- REVIEW: shared identity fields, native-looking current mappings, and `visa_request.companions`.
- SYSTEM: Submission ID, Form Type, WordPress User ID, plus Public Status only if configured.

**وقت سفارت / `embassy_appointment`**

- KEEP: mappings listed for this form in §8, after live entity/type/title confirmation.
- REMOVE: associations belonging only to another FORM_ONLY_FIELDS list in §6; uncheck association only.
- REVIEW: shared identity fields, native-looking current mappings, and `visa_request.companions`.
- SYSTEM: Submission ID, Form Type, WordPress User ID, plus Public Status only if configured.

**فرم ارزیابی اطلاعات مسافران ویزاخان / `traveler_evaluation`**

- KEEP: mappings listed for this form in §8, after live entity/type/title confirmation.
- REMOVE: associations belonging only to another FORM_ONLY_FIELDS list in §6; uncheck association only.
- REVIEW: shared identity fields, native-looking current mappings, and `visa_request.companions`.
- SYSTEM: Submission ID, Form Type, WordPress User ID, plus Public Status only if configured.

**ثبت شکایات و پیشنهادات ویزاخان / `complaint_suggestion`**

- KEEP: mappings listed for this form in §8, after live entity/type/title confirmation.
- REMOVE: associations belonging only to another FORM_ONLY_FIELDS list in §6; uncheck association only.
- REVIEW: shared identity fields, native-looking current mappings, and `visa_request.companions`.
- SYSTEM: Submission ID, Form Type, WordPress User ID, plus Public Status only if configured.

**درخواست ویزا / `visa_request`**

- KEEP: mappings listed for this form in §8, after live entity/type/title confirmation.
- REMOVE: associations belonging only to another FORM_ONLY_FIELDS list in §6; uncheck association only.
- REVIEW: shared identity fields, native-looking current mappings, and `visa_request.companions`.
- SYSTEM: Submission ID, Form Type, WordPress User ID, plus Public Status only if configured.

### D — Current WordPress Deal mappings

The exhaustive field-by-field table is §8. Current IDs are local saved keys, not proof of live correctness.

### E — Missing/suspect mappings

- Missing/blank active mappings: 71; Work must not fill every blank automatically because optional, native, internal and repeater fields are intentionally unmapped.
- Suspect mappings: `embassy_appointment.first_name` → `FirstName` (missing)، `embassy_appointment.last_name` → `LastName` (missing)، `embassy_appointment.mobile` → `MobilePhone` (missing)، `embassy_appointment.email` → `Email` (missing)، `traveler_evaluation.first_name` → `FirstName` (missing)، `traveler_evaluation.last_name` → `LastName` (missing)، `traveler_evaluation.mobile` → `MobilePhone` (missing)، `traveler_evaluation.email` → `Email` (missing).
- Primary suspect: embassy/traveler identity mappings stored as `deal_custom` with native property names.

### F — Shared system Deal fields

- `Field_8783_12_154` — WordPress Submission ID (didar_system_submission_id_field_id)
- `Field_8783_0_153` — Form Type (didar_system_form_type_field_id)
- `Field_8783_0_155` — WordPress User ID (didar_system_user_id_field_id)
- `didar_public_status_field_id` — Public Status (didar_public_status_field_id)

### G — Person mappings

- Native: FirstName, LastName, Email, MobilePhone, OwnerId.
- Custom current: gender → Field_996_0_207، profile_image_url → Field_996_0_206

### H — Visa companion Case configuration

- Pipeline: لیست همراهان درخواست ویزا / `7a08a1b0-0206-4c44-a837-3e2784e86940`
- Stage: در انتظار بررسی / `029adfab-bfc7-4a21-bcaf-69635fdf159e`
- Category: optional; empty is valid; omit `CaseCategoryId` when empty.
- Child/system mappings: exhaustive tables in §11.

### I — WordPress audit checklist

☐ authenticated production session ☐ all five form types ☐ every active Field Key ☐ required settings preserved ☐ Deal/native suspects reviewed ☐ Case settings separate ☐ no companion posts ☐ no secrets/PII copied

### J — Didar audit checklist

☐ only five plugin Deal pipelines ☐ Case pipeline ☐ title/entity/type checked ☐ associations corrected only ☐ no field deletion/rename/type change ☐ unrelated pipelines untouched ☐ Case fields are `FieldType=Case`

### K/L — Test scenarios and expected results

Use §19. For every form expect one WordPress Submission and one Deal; only Visa Request adds one Case per active companion.

### M — Production safety rules

- Do not delete old Custom Fields.
- Do not rename fields or change Custom Field types.
- Only change relevant Pipeline associations.
- Do not touch unrelated Didar pipelines.
- Do not use real customer PII or documents.
- No destructive Case cleanup or guessed endpoint.
- Stop and report ambiguous field identity.

## Source files inspected

- `includes/class-didar-form-registry.php`
- `includes/class-didar-submission-service.php`
- `includes/class-didar-field-renderer.php`
- `includes/class-didar-field-mapper.php`
- `includes/class-didar-sync-manager.php`
- `includes/class-didar-case-service.php`
- `includes/class-didar-settings.php`
- `includes/class-didar-workflow-manager.php`
- `includes/class-didar-custom-field-catalog.php`
- `includes/class-didar-reference-data.php`
- `includes/class-didar-admin.php`
- `includes/class-didar-settings-transfer.php`
- current local WordPress options/cache via runtime bootstrap

## Report status

**READY FOR PRODUCTION CHATGPT WORK AUDIT** — with explicit live validation required for current saved Deal mappings, especially native-looking mappings and the legacy Visa `companions` Deal field.
