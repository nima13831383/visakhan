<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Form_Registry {
	private $forms;

	public function __construct() {
		$this->forms = $this->build_forms();
		foreach ( $this->forms as $type => &$form ) {
			$form['type'] = $type;
		}
		unset( $form );
	}

	public function all() {
		return $this->forms;
	}

	public function get( $type ) {
		$type = sanitize_key( (string) $type );
		return isset( $this->forms[ $type ] ) ? $this->forms[ $type ] : null;
	}

	public function is_valid_type( $type ) {
		return null !== $this->get( $type );
	}

	public function fields( $type ) {
		$form = $this->get( $type );
		if ( ! $form ) {
			return array();
		}

		$fields = array();
		foreach ( $form['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$field['form_type']       = sanitize_key( $type );
				$fields[ $field['name'] ] = $field;
			}
		}
		return $fields;
	}

	/**
	 * Return definitions used only to label and format inactive historical data.
	 *
	 * Legacy fields never participate in active rendering, validation, or saving.
	 */
	public function legacy_fields( $type ) {
		$form = $this->get( $type );
		if ( ! $form || empty( $form['legacy_fields'] ) || ! is_array( $form['legacy_fields'] ) ) {
			return array();
		}

		$fields = array();
		foreach ( $form['legacy_fields'] as $field ) {
			$field['form_type']       = sanitize_key( $type );
			$fields[ $field['name'] ] = $field;
		}
		return $fields;
	}

	private function field( $name, $label, $type = 'text', $required = false, $extra = array() ) {
		return array_merge(
			array(
				'name'        => $name,
				'label'       => $label,
				'type'        => $type,
				'required'    => $required,
				'options'     => array(),
				'multiple'    => false,
				'placeholder' => '',
			),
			$extra
		);
	}

	private function section( $label, $fields, $description = '' ) {
		return array( 'label' => $label, 'description' => $description, 'fields' => $fields );
	}

	private function build_forms() {
		$yes_no = Didar_Reference_Data::yes_no();
		$visa_document_upload = array(
			'multiple'     => true,
			'max_files'    => 2,
			'max_size'     => 5 * MB_IN_BYTES,
			'accept'       => '.pdf,.doc,.docx,.jpg,.jpeg,.png,.webp',
			'upload_mimes' => array(
				'pdf'      => 'application/pdf',
				'doc'      => 'application/msword',
				'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
			),
			'mime_types'  => array(
				'application/pdf',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'image/jpeg',
				'image/png',
				'image/webp',
			),
			'description' => 'فرمت‌های مجاز: PDF، Word، JPG، PNG و WEBP. حداکثر ۲ فایل و ۵ مگابایت برای هر فایل.',
		);

		$country_lists = array(
			'embassy_appointment' => Didar_Reference_Data::countries_for_form( 'embassy_appointment' ),
			'traveler_evaluation' => Didar_Reference_Data::countries_for_form( 'traveler_evaluation' ),
			'visa_request'        => Didar_Reference_Data::countries_for_form( 'visa_request' ),
		);
		$occupation_lists = array(
			'embassy_appointment' => Didar_Reference_Data::occupations_for_form( 'embassy_appointment' ),
			'visa_request'        => Didar_Reference_Data::occupations_for_form( 'visa_request' ),
		);
		$academic_level_lists = array(
			'visa_request' => Didar_Reference_Data::academic_levels_for_form( 'visa_request' ),
		);

		return array(
			'consultation' => array(
				'label'          => 'درخواست مشاوره',
				'submit_label'   => 'ثبت درخواست مشاوره',
				'default_status' => 'pending_review',
				'sections'       => array(
					'main' => $this->section( 'اطلاعات مشاوره', array(
						$this->field( 'first_name', 'نام', 'text', true, array( 'autocomplete' => 'given-name', 'legacy_required_fallback' => 'input_1' ) ),
						$this->field( 'last_name', 'نام خانوادگی', 'text', true, array( 'autocomplete' => 'family-name', 'legacy_required_fallback' => 'input_1' ) ),
						$this->field( 'input_3', 'شماره همراه', 'text', true, array( 'autocomplete' => 'tel', 'inputmode' => 'tel' ) ),
						$this->field( 'email', 'ایمیل', 'email', false, array( 'autocomplete' => 'email' ) ),
						$this->field( 'input_5', 'موضوع مشاوره', 'text', true, array( 'legacy_display_options' => array( 'torist' => 'ویزای توریستی', 'tahsil' => 'ویزای تحصیلی', 'kari' => 'ویزای کاری', 'tamlik' => 'اقامت به شرط تملیک', 'sarmae' => 'اقامت به شرط سرمایه گذاری', 'melk' => 'اقامت خرید ملک' ) ) ),
						$this->field( 'description', 'توضیحات', 'textarea' ),
					) ),
				),
				'legacy_fields'  => array(
					$this->field( 'input_1', 'نام و نام خانوادگی', 'text', true ),
					$this->field( 'input_4', 'وضعیت تاهل', 'radio', true, array( 'options' => array( 'mojarad' => 'مجرد', 'motahal' => 'متاهل', 'motlage' => 'مطلقه', 'fothamsar' => 'همسر فوت شده' ) ) ),
					$this->field( 'input_6', 'نوع مشاوره', 'radio', true, array( 'options' => array( 'hosori' => 'حضوری', 'telfoni' => 'غیرحضوری (تلفنی)' ) ) ),
					$this->field( 'input_7', 'تاریخ مشاوره', 'date' ),
					$this->field( 'input_8', 'زمان مشاوره', 'time', false, array( 'multiple' => true, 'max_items' => 8 ) ),
				),
			),

			'embassy_appointment' => array(
				'label'          => 'درخواست وقت سفارت',
				'submit_label'   => 'درخواست وقت سفارت',
				'default_status' => 'pending_review',
				'sections'       => array(
					'request' => $this->section( 'اطلاعات درخواست', array(
						$this->field( 'twitter', 'X/Twitter', 'honeypot', false, array( 'internal' => true ) ),
						$this->field( 'request_for', 'ثبت درخواست برای خود یا شخص دیگر', 'radio', false, array( 'default' => 'self', 'options' => array( 'self' => 'برای خودم', 'other' => 'برای شخص دیگر' ) ) ),
						$this->field( 'country', 'انتخاب کشور', 'select', true, array( 'options' => $country_lists['embassy_appointment'] ) ),
						$this->field( 'service_type', 'نوع خدمات', 'select', true, array( 'options' => array( 'study_immigration' => 'مهاجرت تحصیلی', 'tourist_visa' => 'ویزای توریستی', 'work_visa' => 'ویزای کاری', 'study_visa' => 'ویزای تحصیلی' ) ) ),
						$this->field( 'profession', 'شغل (حرفه و تخصص)', 'select', false, array( 'options' => $occupation_lists['embassy_appointment'], 'legacy_options' => array( 'doctor' => 'پزشک', 'computer_engineer' => 'مهندس کامپیوتر', 'mechanic' => 'مکانیک' ), 'searchable' => true ) ),
						$this->field( 'appointment_date', 'تاریخ', 'date', true, array( 'display_format' => 'روز/ماه/سال', 'description' => 'تاریخ به‌صورت استاندارد ذخیره می‌شود.' ) ),
						$this->field( 'urgency', 'نوع وقت سفارت', 'radio', true, array( 'options' => array( 'very_urgent' => 'خیلی فوری', 'urgent' => 'فوری', 'normal' => 'عادی' ) ) ),
					) ),
					'personal' => $this->section( 'مشخصات شخصی', array(
						$this->field( 'first_name', 'نام', 'text', true, array( 'autocomplete' => 'given-name' ) ),
						$this->field( 'last_name', 'نام خانوادگی', 'text', true, array( 'autocomplete' => 'family-name' ) ),
						$this->field( 'mobile', 'شماره موبایل', 'text', true, array( 'autocomplete' => 'tel', 'inputmode' => 'tel' ) ),
						$this->field( 'email', 'ایمیل', 'email', false, array( 'autocomplete' => 'email' ) ),
						$this->field( 'current_nationality', 'ملیت فعلی', 'text', true ),
						$this->field( 'passport_number', 'شماره گذرنامه', 'text' ),
						$this->field( 'birth_country', 'کشور محل تولد', 'select', false, array( 'options' => $country_lists['embassy_appointment'], 'allow_legacy' => true ) ),
						$this->field( 'gender', 'جنسیت', 'radio', true, array( 'options' => array( 'boy' => 'مرد', 'grill' => 'زن' ) ) ),
						$this->field( 'passport_issue_place', 'محل صدور گذرنامه', 'text' ),
						$this->field( 'father_name', 'نام پدر', 'text' ),
						$this->field( 'mother_name', 'نام مادر', 'text' ),
						$this->field( 'birth_date', 'تاریخ تولد', 'date', true, array( 'display_format' => 'mm/dd/yyyy' ) ),
						$this->field( 'personal_details', 'مشخصات شخصی', 'repeater', false, array( 'internal' => true, 'columns' => array( 'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'mobile' => 'شماره موبایل', 'email' => 'ایمیل' ) ) ),
					) ),
				),
			),

			'traveler_evaluation' => array(
				'label'          => 'فرم ارزیابی اطلاعات مسافران ویزاخان',
				'description'    => 'متقاضی محترم، ضمن سپاس از انتخاب ویزاخان به‌عنوان مشاور و ارائه‌دهنده خدمات، خواهشمندیم جهت دریافت سرویس باکیفیت سؤالات زیر را با دقت و کامل پاسخ دهید. تکمیل جداگانه این فرم برای کلیه متقاضیان ویزا از یک خانواده الزامی است.',
				'submit_label'   => 'ثبت',
				'default_status' => 'pending_review',
				'sections'       => array(
					'identity' => $this->section( 'اطلاعات هویتی و شخصی', array(
						$this->field( 'evaluation_date', 'تاریخ', 'date' ), $this->field( 'first_name', 'نام' ), $this->field( 'last_name', 'نام خانوادگی' ),
						$this->field( 'mobile', 'تلفن همراه', 'text', false, array( 'inputmode' => 'tel', 'autocomplete' => 'tel' ) ), $this->field( 'email', 'ایمیل', 'email' ),
						$this->field( 'mother_name', 'نام مادر' ), $this->field( 'father_name', 'نام پدر' ), $this->field( 'former_names', 'هرگونه نام سابق' ),
						$this->field( 'nationality', 'ملیت', 'select', false, array( 'options' => array( 'iranian' => 'ایرانی', 'foreign' => 'خارجی' ) ) ),
						$this->field( 'birth_date', 'تاریخ تولد', 'date' ), $this->field( 'birth_place', 'محل تولد' ),
						$this->field( 'gender', 'جنسیت', 'select', false, array( 'options' => array( 'male' => 'مرد', 'female' => 'زن' ) ) ),
						$this->field( 'marital_status', 'وضعیت تاهل', 'select', false, array( 'options' => array( 'single' => 'مجرد', 'married' => 'متاهل', 'divorced' => 'مطلقه', 'widowed' => 'بیوه' ) ) ),
						$this->field( 'children_count', 'تعداد فرزندان', 'number', false, array( 'min' => 0 ) ), $this->field( 'children_ages', 'سن فرزندان' ), $this->field( 'national_id', 'کد ملی', 'text', false, array( 'inputmode' => 'numeric' ) ),
					) ),
					'passport' => $this->section( 'اطلاعات پاسپورت', array(
						$this->field( 'passport_type', 'نوع پاسپورت', 'select', false, array( 'options' => array( 'ordinary' => 'معمولی', 'diplomatic' => 'دیپلمات', 'service' => 'پاسپورت خدمت', 'official' => 'پاسپورت اداری', 'special' => 'پاسپورت مخصوص', 'other' => 'پاسپورت‌های دیگر' ) ) ),
						$this->field( 'passport_number', 'شماره پاسپورت' ), $this->field( 'passport_issue_date', 'تاریخ صدور پاسپورت', 'date' ), $this->field( 'passport_expiry_date', 'تاریخ انقضا پاسپورت', 'date' ), $this->field( 'passport_issuer_country', 'کشور صادر کننده', 'select', false, array( 'options' => $country_lists['traveler_evaluation'], 'allow_legacy' => true ) ),
					) ),
					'family_address' => $this->section( 'روابط خانوادگی و آدرس', array(
						$this->field( 'eu_family_relation', 'روابط خانوادگی با یک شهروند اتحادیه اروپا، سوئیس یا بریتانیا', 'select', false, array( 'options' => array( 'spouse' => 'همسر', 'child' => 'فرزند', 'grandchild' => 'نوه', 'relative' => 'عضو فامیل', 'in_law' => 'وابسته سببی', 'other' => 'سایر' ) ) ),
						$this->field( 'home_address', 'آدرس محل سکونت', 'textarea' ), $this->field( 'postal_code', 'کدپستی', 'text', false, array( 'inputmode' => 'numeric' ) ), $this->field( 'home_phone', 'شماره منزل', 'text', false, array( 'inputmode' => 'tel' ) ),
						$this->field( 'secondary_email', 'ایمیل', 'email' ), $this->field( 'other_residency_passport', 'اقامت و پاسپورت کشور دیگر', 'select', false, array( 'options' => array( 'have' => 'دارم', 'do_not_have' => 'ندارم' ) ) ),
					) ),
					'employment' => $this->section( 'شغل و محل کار', array(
						$this->field( 'current_job', 'شغل کنونی', 'text' ), $this->field( 'work_address', 'نشانی محل کار', 'textarea' ), $this->field( 'employer_name', 'نام کارفرما/شرکت/ مدرسه' ), $this->field( 'work_postal_code', 'کدپستی محل کار', 'text', false, array( 'inputmode' => 'numeric' ) ), $this->field( 'employer_phone', 'تلفن کارفرما/شرکت/ مدرسه', 'text', false, array( 'inputmode' => 'tel' ) ), $this->field( 'employer_email', 'ایمیل کارفرما/شرکت/ مدرسه', 'email' ),
					) ),
					'travel_purpose' => $this->section( 'هدف سفر', array(
						$this->field( 'travel_purpose', 'هدف از سفر', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'tourism' => 'توریستی', 'business' => 'تجاری', 'family_friends' => 'بازدید از خانواده و دوستان', 'historical' => 'تاریخی', 'sports' => 'ورزشی', 'official' => 'ملاقات رسمی', 'medical' => 'دلیل پزشکی', 'study' => 'تحصیلی', 'airport_transit' => 'ترانزیت فرودگاهی', 'other' => 'موارد دیگر' ) ) ),
						$this->field( 'travel_purpose_details', 'اطلاعات تکمیلی در مورد هدف از سفر', 'textarea' ), $this->field( 'main_destination_country', 'کشور مقصد اصلی', 'select', false, array( 'options' => $country_lists['traveler_evaluation'], 'allow_legacy' => true ) ), $this->field( 'first_entry_country', 'کشور اولین ورود', 'select', false, array( 'options' => $country_lists['traveler_evaluation'], 'allow_legacy' => true ) ),
						$this->field( 'requested_entries', 'تعداد ورودهای درخواستی', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'single' => 'یکبار ورود', 'double' => 'دوبار ورود', 'multiple' => 'چند بار ورود (مولتیپل)' ) ) ),
					) ),
					'schengen' => $this->section( 'سابقه شنگن و ویزا', array(
						$this->field( 'schengen_first_entry', 'تاریخ اولین ورود به حوزه شنگن', 'date' ), $this->field( 'schengen_first_exit', 'تاریخ خروج از اولین ورود به حوزه شنگن', 'date' ), $this->field( 'previous_fingerprints', 'اثر انگشت‌های قبلی برای ورود به حوزه شنگن', 'select', false, array( 'options' => $yes_no ) ),
						$this->field( 'previous_visa_number', 'شماره ویزای صادره قبلی' ), $this->field( 'last_visa_valid_from', 'ناریخ شروع اعتبار آخرین ویزای صادره', 'date' ), $this->field( 'last_visa_valid_to', 'ناریخ پایان اعتبار آخرین ویزای صادره', 'date' ),
						$this->field( 'final_country_entry_permit_issuer', 'مجوز ورود به کشور نهایی (در صورت وجود) - صادره از' ), $this->field( 'entry_permit_valid_from', 'شروع اعتبار مجوز', 'date' ), $this->field( 'entry_permit_valid_to', 'پایان اعتبار مجوز', 'date' ),
					) ),
					'invitation' => $this->section( 'دعوتنامه و محل اقامت', array(
						$this->field( 'has_invitation', 'آیا برای ورود به کشور مقصد دعوتنامه دارید؟', 'select', false, array( 'options' => $yes_no ) ), $this->field( 'host_or_hotel_name', 'نام و نام خانوادگی دعوت کننده/نام هتل یا محل اقامت در کشور مقصد' ), $this->field( 'host_or_hotel_address', 'آدرس شخص دعوت کننده/هتل یا محل اقامت', 'textarea' ), $this->field( 'host_or_hotel_phone', 'شماره تماس دعوت کننده/هتل یا محل اقامت', 'text', false, array( 'inputmode' => 'tel' ) ), $this->field( 'host_or_hotel_email', 'ایمیل دعوت کننده/هتل یا محل اقامت', 'email' ),
					) ),
					'funding' => $this->section( 'تأمین هزینه سفر', array(
						$this->field( 'travel_funding', 'نحوه تامین هزینه‌های سفر', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'self' => 'توسط خود شخص', 'sponsor' => 'توسط شخص حمایت کننده' ) ) ),
						$this->field( 'self_funding_methods', 'توسط خود شخص', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'cash' => 'نقدی', 'cheque' => 'چک', 'credit_card' => 'کارت اعتباری', 'prepaid_accommodation' => 'پیش خرید اقامت', 'prepaid_transport' => 'پیش خرید حمل و نقل', 'other' => 'موارد دیگر' ) ) ),
						$this->field( 'sponsor_funding_methods', 'توسط شخص حمایت کننده', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'invitation' => 'طبق دعوتنامه', 'other' => 'موارد دیگر', 'cash' => 'نقدی', 'accommodation' => 'محل اقامت', 'all_costs' => 'همه هزینه‌های سفر پوشش داده می‌شود' ) ) ),
						$this->field( 'financial_accounts', 'حساب‌های مالی قابل ارایه', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'rial' => 'حساب ریالی', 'foreign_currency' => 'حساب ارزی' ) ) ),
						$this->field( 'rial_balance', 'میزان موجودی قابل ارایه ریالی', 'number', false, array( 'min' => 0 ) ), $this->field( 'foreign_currency_balance', 'میزان موجودی قابل ارایه ارزی (یورو/دلار)', 'number', false, array( 'min' => 0 ) ), $this->field( 'property_deeds_count', 'تعداد اسناد ملکی قابل ارایه به نام متقاضی/همسر', 'number', false, array( 'min' => 0 ) ),
					) ),
				),
			),

			'complaint_suggestion' => array(
				'label'          => 'ثبت شکایات و پیشنهادات ویزاخان',
				'description'    => 'مشتری / همکار گرامی، از اینکه با ثبت نظرات ارزشمند خود ما را در بهبود و افزایش کیفیت ارائه خدمات یاری می‌نمایید بی‌نهایت سپاسگزاریم. همکاران ما در اسرع وقت برای شنیدن و بررسی نظرات شما تماس خواهند گرفت.',
				'submit_label'   => 'ثبت شکایت',
				'default_status' => 'pending_review',
				'sections'       => array(
					'main' => $this->section( 'شکایت یا پیشنهاد', array(
						$this->field( 'date', 'تاریخ', 'date' ), $this->field( 'first_name', 'نام' ), $this->field( 'last_name', 'نام خانوادگی' ), $this->field( 'mobile', 'تلفن همراه', 'text', false, array( 'inputmode' => 'tel', 'autocomplete' => 'tel' ) ), $this->field( 'subject', 'موضوع شکایت / پیشنهاد' ), $this->field( 'message', 'پیام شکایت / پیشنهاد', 'textarea' ),
					) ),
				),
			),

			'visa_request' => array(
				'label'          => 'درخواست ویزا',
				'submit_label'   => 'ثبت درخواست ویزا',
				'default_status' => 'pending_review',
				'sections'       => array(
					'identity' => $this->section( 'اطلاعات هویتی و تماسی', array(
						$this->field( 'full_name', 'نام و نام خانوادگی' ), $this->field( 'birth_surname', 'نام خانوادگی زمان تولد' ), $this->field( 'birth_date', 'تاریخ تولد', 'date' ), $this->field( 'birth_city', 'شهر محل تولد' ), $this->field( 'birth_country', 'کشور محل تولد', 'select', false, array( 'options' => $country_lists['visa_request'] ) ),
						$this->field( 'current_nationality', 'تابعیت فعلی' ), $this->field( 'birth_nationality', 'تابعیت زمان تولد' ), $this->field( 'national_id', 'کد ملی', 'text', false, array( 'inputmode' => 'numeric' ) ), $this->field( 'marital_status', 'وضعیت تاهل', 'select', false, array( 'options' => array( 'single' => 'مجرد', 'married' => 'متاهل' ) ) ),
						$this->field( 'mobile', 'موبایل', 'text', false, array( 'inputmode' => 'tel', 'autocomplete' => 'tel' ) ), $this->field( 'email', 'ایمیل', 'email', false, array( 'autocomplete' => 'email' ) ), $this->field( 'residential_address', 'آدرس کامل مسکونی', 'textarea' ), $this->field( 'postal_code', 'کد پستی', 'text', false, array( 'inputmode' => 'numeric' ) ),
					) ),
					'academic' => $this->section( 'مدارک تحصیلی', array(
						$this->field( 'academic_level', 'وضعیت تحصیلی', 'select', false, array( 'options' => $academic_level_lists['visa_request'] ) ),
						$this->field( 'invitation_type', 'نوع دعوت‌نامه', 'select', false, array( 'options' => array( 'family' => 'دیدار خانواده', 'friend' => 'دیدار دوست', 'spouse_family' => 'دیدار همسر', 'business' => 'سفر تجاری', 'conference' => 'کنفرانس', 'academic_research' => 'سفر علمی/تحقیقاتی', 'cultural_sports' => 'فرهنگی/ورزشی', 'medical' => 'درمان', 'tourism' => 'توریسم (بدون دعوت‌نامه شخصی)' ) ) ),
					) ),
					'travel_documents' => $this->section( 'مدارک سفر', array(
						$this->field( 'passport_number', 'شماره گذرنامه' ), $this->field( 'passport_expiry', 'تاریخ انقضای گذرنامه', 'date' ), $this->field( 'passport_issuer_country', 'کشور صادرکننده پاسپورت', 'select', false, array( 'options' => $country_lists['visa_request'] ) ), $this->field( 'travel_destination', 'مقصد سفر', 'select', false, array( 'options' => $country_lists['visa_request'] ) ),
					) ),
					'documents' => $this->section( 'مدارک', array(
						$this->field( 'personal_photo', 'عکس شخصی', 'file', false, $visa_document_upload ),
						$this->field( 'passport_main_page', 'صفحه اصلی گذرنامه', 'file', false, $visa_document_upload ),
						$this->field( 'round_trip_ticket', 'بلیط رفت و برگشت', 'file', false, $visa_document_upload ),
						$this->field( 'other_documents', 'سایر مدارک', 'file', false, $visa_document_upload ),
					) ),
					'financial' => $this->section( 'وضعیت مالی و دارایی‌ها', array(
						$this->field( 'account_balance', 'مانده حساب (تومان)', 'number', false, array( 'min' => 0 ) ), $this->field( 'six_month_turnover', 'مجموع گردش ۶ ماهه', 'number', false, array( 'min' => 0 ) ), $this->field( 'has_foreign_currency_account', 'دارای حساب ارزی هستید؟', 'radio', false, array( 'options' => $yes_no ) ), $this->field( 'has_property_deed', 'سند ملکی به نام متقاضی', 'radio', false, array( 'options' => $yes_no ) ),
						$this->field( 'passive_income', 'درآمد غیرفعال', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'rent' => 'اجاره ملک', 'bank_deposit' => 'سپرده بانکی', 'stock_market' => 'بورس', 'other' => 'سایر' ) ) ),
					) ),
					'employment' => $this->section( 'وضعیت شغلی و مدارک', array(
						$this->field( 'employment_type', 'نوع شغل', 'select', false, array( 'options' => array( 'self_employed' => 'آزاد', 'government' => 'دولتی', 'private' => 'خصوصی', 'retired' => 'بازنشسته', 'board_member' => 'مدیرعامل یا عضو هیئت مدیره', 'doctor' => 'پزشک', 'lawyer' => 'وکیل', 'other' => 'سایر' ) ) ),
						$this->field( 'occupation', 'عنوان شغلی', 'select', false, array( 'options' => $occupation_lists['visa_request'], 'searchable' => true ) ), $this->field( 'workplace_name', 'نام محل کار' ), $this->field( 'job_title', 'سمت شغلی' ),
						$this->field( 'employment_documents', 'چک‌لیست مدارک شغلی', 'checkbox', false, array( 'multiple' => true, 'options' => array( 'business_license' => 'جواز کسب', 'management_card' => 'کارت مباشرت', 'employment_letter' => 'نامه اشتغال به کار', 'workplace_deed_lease' => 'سند یا اجاره‌نامه محل کار', 'employment_decree' => 'حکم کارگزینی', 'work_contract' => 'قرارداد کاری', 'company_notice' => 'آگهی تاسیس و تغییرات', 'payslip' => 'فیش حقوقی', 'social_security' => 'بیمه تامین اجتماعی' ) ) ),
					) ),
					'history' => $this->section( 'سابقه ویزا و سفر', array(
						$this->field( 'has_rejection', 'سابقه ریجکتی', 'radio', false, array( 'options' => $yes_no ) ), $this->field( 'rejection_embassy', 'نام سفارت ریجکت‌کننده' ), $this->field( 'rejection_date', 'تاریخ ریجکتی', 'date' ), $this->field( 'has_previous_schengen', 'سابقه ویزای شنگن قبلی', 'radio', false, array( 'options' => $yes_no ) ), $this->field( 'previous_schengen_country', 'نام کشور ویزای شنگن قبلی', 'select', false, array( 'options' => $country_lists['visa_request'] ) ), $this->field( 'previous_schengen_date', 'تاریخ ویزای شنگن قبلی', 'date' ), $this->field( 'estimated_travel_date', 'تاریخ حدودی سفر', 'date' ), $this->field( 'schengen_exit_place', 'محل خروج از شنگن (کشور/شهر)' ),
					) ),
					'companions' => $this->section( 'همراهان', array(
						$this->field( 'companions', 'لیست همراهان', 'repeater', false, array( 'max_items' => 20, 'columns' => array(
							'full_name'   => array( 'label' => 'نام و نام خانوادگی', 'type' => 'text' ),
							'age'         => array( 'label' => 'سن', 'type' => 'text', 'inputmode' => 'numeric' ),
							'occupation'  => array( 'label' => 'شغل', 'type' => 'select', 'options' => $occupation_lists['visa_request'] ),
							'national_id' => array( 'label' => 'کد ملی', 'type' => 'text', 'inputmode' => 'numeric' ),
							'email'       => array( 'label' => 'ایمیل', 'type' => 'email', 'autocomplete' => 'email' ),
							'phone'       => array( 'label' => 'شماره تماس', 'type' => 'text', 'inputmode' => 'tel', 'autocomplete' => 'tel' ),
						) ) ),
					) ),
				),
			),
		);
	}
}
