<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Reference_Data {
	public static function countries() {
		return array(
			'china' => 'چین', 'thailand' => 'تایلند', 'japan' => 'ژاپن', 'south_korea' => 'کره جنوبی',
			'uae' => 'امارات', 'oman' => 'عمان', 'india' => 'هند', 'indonesia' => 'اندونزی',
			'malaysia' => 'مالزی', 'hong_kong' => 'هنگ‌کنگ', 'singapore' => 'سنگاپور', 'qatar' => 'قطر',
			'kuwait' => 'کویت', 'jordan' => 'اردن', 'uzbekistan' => 'ازبکستان', 'sri_lanka' => 'سریلانکا',
			'philippines' => 'فیلیپین', 'vietnam' => 'ویتنام', 'pakistan' => 'پاکستان', 'iraq' => 'عراق',
			'saudi_arabia' => 'عربستان', 'kyrgyzstan' => 'قرقیزستان', 'kazakhstan' => 'قزاقستان', 'mongolia' => 'مغولستان',
			'tajikistan' => 'تاجیکستان', 'greece' => 'یونان', 'france' => 'فرانسه', 'belgium' => 'بلژیک',
			'switzerland' => 'سوئیس', 'germany' => 'آلمان', 'sweden' => 'سوئد', 'austria' => 'اتریش',
			'hungary' => 'مجارستان', 'poland' => 'لهستان', 'finland' => 'فنلاند', 'croatia' => 'کرواسی',
			'spain' => 'اسپانیا', 'denmark' => 'دانمارک', 'norway' => 'نروژ', 'italy' => 'ایتالیا',
			'portugal' => 'پرتغال', 'romania' => 'رومانی', 'netherlands' => 'هلند', 'united_kingdom' => 'انگلیس',
			'slovenia' => 'اسلوونی', 'estonia' => 'استونی', 'czech_republic' => 'چک', 'lithuania' => 'لیتوانی',
			'cyprus' => 'قبرس', 'ireland' => 'ایرلند', 'turkey' => 'ترکیه', 'georgia' => 'گرجستان',
			'canada' => 'کانادا', 'united_states' => 'آمریکا', 'mexico' => 'مکزیک', 'brazil' => 'برزیل',
			'argentina' => 'آرژانتین', 'dominica' => 'دومینیکا', 'south_africa' => 'آفریقای جنوبی', 'tunisia' => 'تونس',
			'egypt' => 'مصر', 'morocco' => 'مراکش', 'ivory_coast' => 'ساحل عاج', 'australia' => 'استرالیا',
		);
	}

	public static function countries_for_form( $form_type ) {
		return self::for_form( 'countries', $form_type, self::countries() );
	}

	/**
	 * No city list is defined in FORMS.txt. These per-form lists intentionally
	 * start empty so projects can supply one without coupling the forms forever.
	 */
	public static function cities_for_form( $form_type ) {
		return self::for_form( 'cities', $form_type, array() );
	}

	public static function occupations() {
		static $occupations = null;
		if ( null !== $occupations ) {
			return $occupations;
		}

		$data = <<<'DIDAR_OCCUPATIONS'
Business Owner|صاحب کسب و کار
Entrepreneur|کارآفرین
Company Director|مدیر شرکت
General Manager|مدیرعامل / مدیر کل
Operations Manager|مدیر عملیات
Project Manager|مدیر پروژه
Sales Manager|مدیر فروش
Marketing Manager|مدیر بازاریابی
Office Manager|مدیر اداری
HR Manager|مدیر منابع انسانی
Business Consultant|مشاور کسب و کار
Management Consultant|مشاور مدیریت
Executive|مدیر اجرایی
Supervisor|سرپرست
Team Leader|سرپرست تیم
Accountant|حسابدار
Auditor|حسابرس
Financial Analyst|تحلیلگر مالی
Financial Manager|مدیر مالی
Banker|بانکدار
Bank Manager|مدیر بانک
Investment Analyst|تحلیلگر سرمایه‌گذاری
Insurance Agent|کارگزار بیمه
Tax Consultant|مشاور مالیاتی
Economist|اقتصاددان
Software Engineer|مهندس نرم‌افزار
Software Developer|توسعه دهنده نرم‌افزار
Web Developer|توسعه دهنده وب
Web Designer|طراح وب
Computer Engineer|مهندس کامپیوتر
IT Specialist|متخصص فناوری اطلاعات
IT Manager|مدیر فناوری اطلاعات
Systems Analyst|تحلیلگر سیستم
Data Analyst|تحلیلگر داده
Data Scientist|دانشمند داده
Database Administrator|مدیر پایگاه داده
Network Engineer|مهندس شبکه
Network Administrator|مدیر شبکه
Cybersecurity Specialist|متخصص امنیت سایبری
Programmer|برنامه‌نویس
Mobile App Developer|توسعه دهنده اپلیکیشن موبایل
UX/UI Designer|طراح UX/UI
Artificial Intelligence Engineer|مهندس هوش مصنوعی
Civil Engineer|مهندس عمران
Mechanical Engineer|مهندس مکانیک
Electrical Engineer|مهندس برق
Electronics Engineer|مهندس الکترونیک
Chemical Engineer|مهندس شیمی
Industrial Engineer|مهندس صنایع
Architectural Engineer|مهندس معماری
Petroleum Engineer|مهندس نفت
Mining Engineer|مهندس معدن
Environmental Engineer|مهندس محیط زیست
Biomedical Engineer|مهندس پزشکی
Telecommunications Engineer|مهندس مخابرات
Structural Engineer|مهندس سازه
Automation Engineer|مهندس اتوماسیون
Doctor / Physician|پزشک
Surgeon|جراح
Dentist|دندانپزشک
Pharmacist|داروساز
Nurse|پرستار
Midwife|ماما
Physiotherapist|فیزیوتراپیست
Psychologist|روان شناس
Psychiatrist|روان پزشک
Medical Technician|تکنسین پزشکی
Laboratory Technician|تکنسین آزمایشگاه
Radiologist|رادیولوژیست
Veterinarian|دامپزشک
Medical Assistant|دستیار پزشکی
Paramedic|تکنسین فوریت‌های پزشکی
Nutritionist|متخصص تغذیه
Teacher|معلم
School Teacher|معلم مدرسه
University Professor|استاد دانشگاه
Lecturer|مدرس / استاد
Researcher|پژوهشگر
Scientist|دانشمند
University Student|دانشجوی دانشگاه
Student|دانش‌آموز / دانشجو
PhD Student|دانشجوی دکترا
Research Assistant|دستیار پژوهشی
Academic|عضو هیئت علمی / دانشگاهی
School Principal|مدیر مدرسه
Lawyer|وکیل
Attorney|وکیل دادگستری
Legal Consultant|مشاور حقوقی
Judge|قاضی
Prosecutor|دادستان
Notary|سردفتر / دفتر اسناد رسمی
Government Employee|کارمند دولت
Civil Servant|کارمند خدمات دولتی
Diplomat|دیپلمات
Public Official|مقام دولتی
Salesperson|فروشنده
Sales Representative|نماینده فروش
Retailer|خرده فروش
Shopkeeper|مغازه دار
Cashier|صندوقدار
Customer Service Representative|کارشناس خدمات مشتریان
Call Center Agent|اپراتور مرکز تماس
Real Estate Agent|مشاور املاک
Travel Agent|آژانس دار / کارگزار سفر
Tour Operator|برگزارکننده تور
Insurance Broker|کارگزار بیمه
Architect|معمار
Construction Manager|مدیر ساخت و ساز
Contractor|پیمانکار
Builder|سازنده
Electrician|برق کار
Plumber|لوله کش
Carpenter|نجار
Welder|جوشکار
Mason|بنا
Painter|نقاش ساختمان
Mechanic|مکانیک
Auto Mechanic|مکانیک خودرو
Technician|تکنسین
Machine Operator|اپراتور ماشین‌آلات
Construction Worker|کارگر ساختمانی
Driver|راننده
Taxi Driver|راننده تاکسی
Truck Driver|راننده کامیون
Bus Driver|راننده اتوبوس
Delivery Driver|راننده تحویل کالا
Pilot|خلبان
Flight Attendant|مهماندار هواپیما
Aircraft Engineer|مهندس هواپیما
Ship Captain|ناخدای کشتی
Seafarer|دریانورد
Sailor|ملوان
Logistics Manager|مدیر لجستیک
Warehouse Manager|مدیر انبار
Hotel Manager|مدیر هتل
Receptionist|پذیرشگر
Hotel Staff|کارمند هتل
Chef|سرآشپز
Cook|آشپز
Waiter|پیشخدمت
Bartender|متصدی بار
Baker|نانوا
Pastry Chef|شیرینی پز
Restaurant Manager|مدیر رستوران
Tour Guide|راهنمای گردشگری
Travel Consultant|مشاور سفر
Journalist|روزنامه نگار
Reporter|خبرنگار
Photographer|عکاس
Videographer|فیلم بردار
Filmmaker|فیلم ساز
Director|کارگردان
Producer|تهیه کننده
Actor|بازیگر
Musician|موسیقیدان
Singer|خواننده
Artist|هنرمند
Graphic Designer|طراح گرافیک
Illustrator|تصویرگر
Writer|نویسنده
Translator|مترجم
Interpreter|مترجم شفاهی
Farmer|کشاورز
Agricultural Engineer|مهندس کشاورزی
Farm Worker|کارگر مزرعه
Gardener|باغبان
Horticulturist|متخصص باغبانی
Fisherman|ماهیگیر
Forester|جنگلبان / متخصص جنگل
Agricultural Consultant|مشاور کشاورزی
Administrative Assistant|دستیار اداری
Secretary|منشی
Office Clerk|کارمند اداری
Receptionist|مسئول پذیرش
Executive Assistant|دستیار مدیر
Personal Assistant|دستیار شخصی
Human Resources Specialist|متخصص منابع انسانی
Procurement Officer|مسئول خرید
Purchasing Manager|مدیر خرید
Document Controller|مسئول کنترل اسناد
Architect|معمار (تکرار شده)
Interior Designer|طراح داخلی
Fashion Designer|طراح مد
Hairdresser|آرایشگر
Beautician|متخصص زیبایی
Fitness Trainer|مربی تناسب اندام
Sports Coach|مربی ورزشی
Athlete|ورزشکار
Security Guard|نگهبان
Police Officer|افسر پلیس
Firefighter|آتش نشان
Military Officer|افسر نظامی
Soldier|سرباز
Social Worker|مددکار اجتماعی
Librarian|کتابدار
Journalist|روزنامه نگار (تکرار شده)
Consultant|مشاور
Freelancer|فریلنسر / آزادکار
Religious Worker|فعال / کارمند مذهبی
Volunteer|داوطلب
DIDAR_OCCUPATIONS;

		$occupations = array();
		foreach ( preg_split( '/\R/u', trim( $data ) ) as $index => $line ) {
			$parts = explode( '|', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$key                 = sprintf( 'occupation_%03d', $index + 1 );
			$occupations[ $key ] = $parts[1] . ' — ' . $parts[0];
		}

		return $occupations;
	}

	public static function occupations_for_form( $form_type ) {
		return self::for_form( 'occupations', $form_type, self::occupations() );
	}

	public static function academic_levels() {
		return array(
			'under_diploma' => 'زیردیپلم (Under Diploma)',
			'diploma'       => 'دیپلم (Diploma)',
			'associate'     => 'فوق‌دیپلم (Associate Degree)',
			'bachelor'      => 'کارشناسی (Bachelor’s Degree)',
			'master'        => 'کارشناسی ارشد (Master’s Degree)',
			'phd'           => 'دکترا (PHD)',
			'postdoc'       => 'فوق‌دکترا (Post-Doc)',
		);
	}

	public static function academic_levels_for_form( $form_type ) {
		return self::for_form( 'academic_levels', $form_type, self::academic_levels() );
	}

	public static function yes_no() {
		return array( 'yes' => 'بله', 'no' => 'خیر' );
	}

	public static function statuses() {
		return array(
			'pending_review'     => 'در انتظار بررسی',
			'initial_approval'   => 'تایید اولیه',
			'needs_correction'   => 'نیاز به اصلاح مدارک',
			'completed'          => 'تکمیل شده',
		);
	}

	/**
	 * Each form receives its own copy-on-write entry. Values are identical now,
	 * while a future form-specific change can replace only one map entry or use
	 * its dedicated filter without affecting the other forms.
	 */
	private static function for_form( $dataset, $form_type, $shared_options ) {
		$form_type = sanitize_key( $form_type );
		$lists     = array(
			'consultation'        => $shared_options,
			'embassy_appointment' => $shared_options,
			'traveler_evaluation' => $shared_options,
			'complaint_suggestion' => $shared_options,
			'visa_request'        => $shared_options,
		);

		$options = isset( $lists[ $form_type ] ) ? $lists[ $form_type ] : $shared_options;
		return apply_filters( 'didar_' . sanitize_key( $dataset ) . '_for_' . $form_type, $options, $form_type );
	}
}
