<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Admin {
	private $registry;
	private $renderer;
	private $validator;
	private $service;
	private $settings;
	private $files;
	private $request_search;
	private $state_cache = array();
	private static $saving = false;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Validator $validator, Didar_Submission_Service $service, Didar_Settings $settings = null, Didar_File_Service $files = null, Didar_Request_Search $request_search = null ) {
		$this->registry  = $registry;
		$this->renderer  = $renderer;
		$this->validator = $validator;
		$this->service   = $service;
		$this->settings  = $settings ? $settings : new Didar_Settings();
		$this->files     = $files ? $files : new Didar_File_Service( $registry, $this->settings, new Didar_Event_Log() );
		$this->request_search = $request_search ? $request_search : new Didar_Request_Search();

		add_action( 'add_meta_boxes_' . Didar_Post_Type::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Didar_Post_Type::POST_TYPE, array( $this, 'save_submission' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'manage_' . Didar_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Didar_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( 'post_row_actions', array( $this, 'request_row_actions' ), 10, 2 );
		add_filter( 'views_edit-' . Didar_Post_Type::POST_TYPE, array( $this, 'assignment_views' ) );
		add_filter( 'option_page_capability_didar_page_settings', array( $this, 'settings_capability' ) );
		add_filter( 'redirect_post_location', array( $this, 'filter_save_redirect' ), 10, 2 );
	}

	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . Didar_Post_Type::POST_TYPE,
			__( 'تنظیمات دیدار', 'didar' ),
			__( 'تنظیمات', 'didar' ),
			'didar_manage_settings',
			'didar-page-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'didar_page_settings',
			Didar_Shortcodes::PAGE_SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_page_settings' ),
			)
		);
		register_setting(
			'didar_page_settings',
			Didar_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_didar_settings' ),
			)
		);

		add_settings_section(
			'didar_submission_pages',
			__( 'صفحات مشاهده و ویرایش درخواست', 'didar' ),
			array( $this, 'render_settings_description' ),
			'didar-page-settings'
		);
		add_settings_field(
			'didar_details_page_id',
			__( 'صفحه مشاهده جزئیات', 'didar' ),
			array( $this, 'render_page_setting' ),
			'didar-page-settings',
			'didar_submission_pages',
			array( 'key' => 'details_page_id', 'shortcode' => '[didar_submission_details]' )
		);
		add_settings_field(
			'didar_edit_page_id',
			__( 'صفحه ویرایش درخواست', 'didar' ),
			array( $this, 'render_page_setting' ),
			'didar-page-settings',
			'didar_submission_pages',
			array( 'key' => 'edit_page_id', 'shortcode' => '[didar_submission_edit]' )
		);

		add_settings_section(
			'didar_behavior_settings',
			__( 'تنظیمات دسترسی و فهرست درخواست‌ها', 'didar' ),
			'__return_false',
			'didar-page-settings'
		);
		add_settings_field(
			'didar_colleague_history',
			__( 'دسترسی همکار به سوابق داخلی', 'didar' ),
			array( $this, 'render_colleague_history_setting' ),
			'didar-page-settings',
			'didar_behavior_settings'
		);
		add_settings_field(
			'didar_frontend_requests_per_page',
			__( 'تعداد درخواست‌ها در هر صفحه', 'didar' ),
			array( $this, 'render_requests_per_page_setting' ),
			'didar-page-settings',
			'didar_behavior_settings'
		);

		add_settings_section(
			'didar_file_settings',
			__( 'تنظیمات فایل‌های درخواست', 'didar' ),
			'__return_false',
			'didar-page-settings'
		);
		add_settings_field(
			'didar_file_download_mode',
			__( 'نحوه دانلود فایل‌های درخواست', 'didar' ),
			array( $this, 'render_file_download_mode_setting' ),
			'didar-page-settings',
			'didar_file_settings'
		);

		add_settings_section(
			'didar_field_requirement_settings',
			__( 'تنظیمات فیلدهای فرم‌ها', 'didar' ),
			array( $this, 'render_requirement_settings_description' ),
			'didar-page-settings'
		);
		add_settings_field(
			'didar_field_required_overrides',
			__( 'ضروری / اختیاری', 'didar' ),
			array( $this, 'render_field_requirement_settings' ),
			'didar-page-settings',
			'didar_field_requirement_settings'
		);
	}

	public function settings_capability() {
		return 'didar_manage_settings';
	}

	public function sanitize_didar_settings( $input ) {
		$current = get_option( Didar_Settings::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();
		if ( ! current_user_can( 'didar_manage_settings' ) || ! is_array( $input ) ) {
			return $current;
		}

		$output = array(
			'colleague_can_view_internal_history' => empty( $input['colleague_can_view_internal_history'] ) ? 0 : 1,
		);
		$per_page = isset( $input['frontend_requests_per_page'] ) && ! is_array( $input['frontend_requests_per_page'] ) ? absint( $input['frontend_requests_per_page'] ) : Didar_Settings::DEFAULT_REQUESTS_PER_PAGE;
		$output['frontend_requests_per_page'] = min( Didar_Settings::MAX_REQUESTS_PER_PAGE, max( Didar_Settings::MIN_REQUESTS_PER_PAGE, $per_page ) );
		$download_mode = isset( $input['file_download_mode'] ) && ! is_array( $input['file_download_mode'] ) ? sanitize_key( wp_unslash( $input['file_download_mode'] ) ) : Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;
		$output['file_download_mode'] = in_array( $download_mode, array( 'secure', 'direct' ), true ) ? $download_mode : Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;

		$submitted_overrides = isset( $input['field_required_overrides'] ) && is_array( $input['field_required_overrides'] ) ? $input['field_required_overrides'] : array();
		$clean_overrides     = array();
		foreach ( $this->registry->all() as $form_type => $form ) {
			if ( empty( $submitted_overrides[ $form_type ] ) || ! is_array( $submitted_overrides[ $form_type ] ) ) {
				continue;
			}
			foreach ( $this->registry->fields( $form_type ) as $field_key => $field ) {
				if ( ! empty( $field['internal'] ) || 'honeypot' === $field['type'] || ! isset( $submitted_overrides[ $form_type ][ $field_key ] ) || is_array( $submitted_overrides[ $form_type ][ $field_key ] ) ) {
					continue;
				}
				$state = sanitize_key( wp_unslash( $submitted_overrides[ $form_type ][ $field_key ] ) );
				if ( 'required' === $state ) {
					$clean_overrides[ $form_type ][ $field_key ] = true;
				} elseif ( 'optional' === $state ) {
					$clean_overrides[ $form_type ][ $field_key ] = false;
				}
			}
		}
		$output['field_required_overrides'] = $clean_overrides;
		$protection = $this->files->sync_storage_protection( $output['file_download_mode'] );
		if ( is_wp_error( $protection ) ) {
			$output['file_download_mode'] = Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;
			add_settings_error( Didar_Settings::OPTION_NAME, 'didar_file_storage_protection', $protection->get_error_message(), 'error' );
		}

		return $output;
	}

	public function sanitize_page_settings( $input ) {
		$current = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		if ( ! current_user_can( 'didar_manage_settings' ) ) {
			return $current;
		}
		$output  = array();

		foreach ( array( 'details_page_id', 'edit_page_id' ) as $key ) {
			$page_id = is_array( $input ) && isset( $input[ $key ] ) && ! is_array( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
			$output[ $key ] = $page_id && 'page' === get_post_type( $page_id ) ? $page_id : 0;
		}

		if ( $output['details_page_id'] && $output['details_page_id'] === $output['edit_page_id'] ) {
			add_settings_error(
				Didar_Shortcodes::PAGE_SETTINGS_OPTION,
				'didar_pages_must_differ',
				__( 'صفحه مشاهده جزئیات و صفحه ویرایش باید دو برگه متفاوت باشند.', 'didar' ),
				'error'
			);
			return $current;
		}

		return $output;
	}

	public function render_settings_description() {
		echo '<p>' . esc_html__( 'این دو برگه برای همه انواع فرم دیدار مشترک هستند. کد کوتاه نوشته‌شده زیر هر انتخاب را در همان برگه قرار دهید.', 'didar' ) . '</p>';
	}

	public function render_page_setting( $args ) {
		$settings = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$key      = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
		$selected = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

		wp_dropdown_pages(
			array(
				'name'              => Didar_Shortcodes::PAGE_SETTINGS_OPTION . '[' . $key . ']',
				'id'                => 'didar-' . sanitize_html_class( $key ),
				'selected'          => $selected,
				'show_option_none'  => __( '— انتخاب برگه —', 'didar' ),
				'option_none_value' => '0',
			)
		);
		if ( ! empty( $args['shortcode'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'کد کوتاه لازم در این برگه: %s', 'didar' ), $args['shortcode'] ) ) . '</p>';
		}
	}

	public function render_colleague_history_setting() {
		$settings = $this->settings->all();
		echo '<label><input type="checkbox" name="' . esc_attr( Didar_Settings::OPTION_NAME ) . '[colleague_can_view_internal_history]" value="1" ' . checked( ! empty( $settings['colleague_can_view_internal_history'] ), true, false ) . '> ' . esc_html__( 'اجازه مشاهده گردش کار داخلی و سوابق درخواست برای همکار', 'didar' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'این دسترسی فقط در فرانت‌اند و فقط برای درخواست متعلق به همان همکار اعمال می‌شود.', 'didar' ) . '</p>';
	}

	public function render_requests_per_page_setting() {
		echo '<input type="number" class="small-text" name="' . esc_attr( Didar_Settings::OPTION_NAME ) . '[frontend_requests_per_page]" min="' . esc_attr( Didar_Settings::MIN_REQUESTS_PER_PAGE ) . '" max="' . esc_attr( Didar_Settings::MAX_REQUESTS_PER_PAGE ) . '" value="' . esc_attr( $this->settings->frontend_requests_per_page() ) . '">';
		echo '<p class="description">' . esc_html( sprintf( __( 'برای فهرست فرانت‌اند [didar_submissions]. مقدار مجاز بین %1$d و %2$d است.', 'didar' ), Didar_Settings::MIN_REQUESTS_PER_PAGE, Didar_Settings::MAX_REQUESTS_PER_PAGE ) ) . '</p>';
	}

	public function render_file_download_mode_setting() {
		$name = Didar_Settings::OPTION_NAME . '[file_download_mode]';
		$mode = $this->settings->file_download_mode();
		echo '<fieldset class="didar-download-mode">';
		echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="secure" ' . checked( $mode, 'secure', false ) . '> <strong>' . esc_html__( 'دانلود امن', 'didar' ) . '</strong><span class="description">' . esc_html__( 'فایل فقط پس از ورود کاربر و بررسی دسترسی او به درخواست، توسط دیدار ارسال می‌شود.', 'didar' ) . '</span></label>';
		echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="direct" ' . checked( $mode, 'direct', false ) . '> <strong>' . esc_html__( 'دانلود مستقیم', 'didar' ) . '</strong><span class="description">' . esc_html__( 'لینک مستقیم فایل ارائه می‌شود و کنترل دسترسی دیدار هنگام دانلود اعمال نمی‌شود.', 'didar' ) . '</span></label>';
		echo '<p class="notice notice-warning inline"><strong>' . esc_html__( 'هشدار امنیتی:', 'didar' ) . '</strong> ' . esc_html__( 'در حالت دانلود مستقیم، هر شخصی که URL غیرقابل‌حدس فایل را در اختیار داشته باشد می‌تواند آن را دریافت کند. برای مدارک حساس حالت دانلود امن توصیه می‌شود.', 'didar' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'دیدار در حالت امن برای Apache و IIS قواعد منع دسترسی مستقیم ایجاد می‌کند. در Nginx یا وب‌سرورهایی که این فایل‌های پیکربندی را نادیده می‌گیرند، مدیر سرور باید دسترسی مستقیم به پوشه didar-private را مسدود کند.', 'didar' ) . '</p>';
		echo '</fieldset>';
	}

	public function render_requirement_settings_description() {
		echo '<p>' . esc_html__( 'فقط وضعیت ضروری بودن قابل تغییر است. «پیش‌فرض» همیشه از تعریف فعلی Form Registry استفاده می‌کند.', 'didar' ) . '</p>';
	}

	public function render_field_requirement_settings() {
		$settings  = $this->settings->all();
		$overrides = isset( $settings['field_required_overrides'] ) && is_array( $settings['field_required_overrides'] ) ? $settings['field_required_overrides'] : array();
		echo '<div class="didar-requirement-settings">';
		foreach ( $this->registry->all() as $form_type => $form ) {
			echo '<details><summary><strong>' . esc_html( $form['label'] ) . '</strong> <code>' . esc_html( $form_type ) . '</code></summary>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'فیلد', 'didar' ) . '</th><th>' . esc_html__( 'پیش‌فرض Registry', 'didar' ) . '</th><th>' . esc_html__( 'وضعیت مؤثر', 'didar' ) . '</th><th>' . esc_html__( 'Override', 'didar' ) . '</th></tr></thead><tbody>';
			foreach ( $this->registry->fields( $form_type ) as $field_key => $field ) {
				if ( ! empty( $field['internal'] ) || 'honeypot' === $field['type'] ) {
					continue;
				}
				$default      = ! empty( $field['required'] );
				$has_override = isset( $overrides[ $form_type ] ) && is_array( $overrides[ $form_type ] ) && array_key_exists( $field_key, $overrides[ $form_type ] );
				$selected     = $has_override ? ( $overrides[ $form_type ][ $field_key ] ? 'required' : 'optional' ) : 'default';
				$effective    = $this->settings->is_required( $form_type, $field_key, $default );
				$name         = Didar_Settings::OPTION_NAME . '[field_required_overrides][' . $form_type . '][' . $field_key . ']';
				echo '<tr><td><strong>' . esc_html( $field['label'] ) . '</strong><br><code>' . esc_html( $field_key ) . '</code></td><td>' . esc_html( $default ? __( 'ضروری', 'didar' ) : __( 'اختیاری', 'didar' ) ) . '</td><td>' . esc_html( $effective ? __( 'ضروری', 'didar' ) : __( 'اختیاری', 'didar' ) ) . '</td><td><select name="' . esc_attr( $name ) . '">';
				echo '<option value="default" ' . selected( $selected, 'default', false ) . '>' . esc_html__( 'پیش‌فرض', 'didar' ) . '</option>';
				echo '<option value="required" ' . selected( $selected, 'required', false ) . '>' . esc_html__( 'ضروری', 'didar' ) . '</option>';
				echo '<option value="optional" ' . selected( $selected, 'optional', false ) . '>' . esc_html__( 'اختیاری', 'didar' ) . '</option>';
				echo '</select></td></tr>';
			}
			echo '</tbody></table></details>';
		}
		echo '</div>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'didar_manage_settings' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به تنظیمات دیدار را ندارید.', 'didar' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap didar-settings-page" dir="rtl"><h1>' . esc_html__( 'تنظیمات دیدار', 'didar' ) . '</h1>';
		settings_errors();
		echo '<form action="options.php" method="post">';
		settings_fields( 'didar_page_settings' );
		do_settings_sections( 'didar-page-settings' );
		submit_button( __( 'ذخیره تنظیمات', 'didar' ) );
		echo '</form></div>';
	}

	public function add_meta_boxes( $post ) {
		remove_meta_box( 'slugdiv', Didar_Post_Type::POST_TYPE, 'normal' );
		add_meta_box( 'didar-form-type', __( 'نوع فرم', 'didar' ), array( $this, 'render_form_type_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'didar-fields', __( 'اطلاعات درخواست / فرم', 'didar' ), array( $this, 'render_fields_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-notes', __( 'یادداشت متقاضی', 'didar' ), array( $this, 'render_notes_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-customer-workflow', __( 'اطلاعات نمایشی برای مشتری', 'didar' ), array( $this, 'render_customer_workflow_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-internal-workflow', __( 'گردش کار داخلی', 'didar' ), array( $this, 'render_internal_workflow_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-activity', __( 'تاریخچه درخواست', 'didar' ), array( $this, 'render_activity_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'didar-details', __( 'مالکیت', 'didar' ), array( $this, 'render_details_box' ), Didar_Post_Type::POST_TYPE, 'side', 'high' );
	}

	public function render_form_type_box( $post ) {
		wp_nonce_field( 'didar_admin_save_submission_' . $post->ID, 'didar_admin_nonce' );
		$stored_type = get_post_meta( $post->ID, '_didar_form_type', true );
		$state       = $this->get_admin_state( $post->ID );
		$selected    = $stored_type ? $stored_type : ( isset( $state['form_type'] ) ? $state['form_type'] : '' );

		echo '<div class="didar-admin-wrap" dir="rtl">';
		if ( $stored_type && $this->registry->get( $stored_type ) ) {
			$form = $this->registry->get( $stored_type );
			echo '<p><strong>' . esc_html( $form['label'] ) . '</strong></p><p class="description">' . esc_html__( 'برای جلوگیری از ناسازگاری داده‌ها، نوع فرم پس از ایجاد قابل تغییر نیست.', 'didar' ) . '</p>';
			echo '<input type="hidden" name="didar_form_type" value="' . esc_attr( $stored_type ) . '">';
		} else {
			echo '<label for="didar-form-type-select"><strong>' . esc_html__( 'نوع فرم را انتخاب کنید:', 'didar' ) . '</strong></label>';
			echo '<select id="didar-form-type-select" name="didar_form_type"><option value="">' . esc_html__( '— انتخاب نوع فرم —', 'didar' ) . '</option>';
			foreach ( $this->registry->all() as $type => $form ) {
				echo '<option value="' . esc_attr( $type ) . '" ' . selected( $selected, $type, false ) . '>' . esc_html( $form['label'] ) . '</option>';
			}
			echo '</select><span class="spinner" data-didar-admin-spinner></span>';
		}
		echo '</div>';
	}

	public function render_notes_box( $post ) {
		$state       = $this->get_admin_state( $post->ID );
		$shared_note = isset( $state['shared_note'] ) ? $state['shared_note'] : $this->service->get_shared_note( $post->ID );

		echo '<div class="didar-admin-wrap didar-admin-notes" dir="rtl">';
		echo '<p><label class="didar-label" for="didar-shared-note">' . esc_html__( 'یادداشت متقاضی', 'didar' ) . '</label>';
		echo '<textarea id="didar-shared-note" name="didar_shared_note" rows="5">' . esc_textarea( $shared_note ) . '</textarea>';
		echo '<span class="description">' . esc_html__( 'این متن جزئی از محتوای ارسالی متقاضی است و با یادداشت عمومی یا داخلی یکی نیست.', 'didar' ) . '</span></p>';
		echo '</div>';
	}

	public function render_customer_workflow_box( $post ) {
		$status = $this->service->get_public_status( $post->ID );
		$note   = $this->service->get_public_note( $post->ID );
		echo '<div class="didar-admin-wrap didar-admin-workflow" dir="rtl">';
		echo '<p>';
		if ( current_user_can( 'didar_change_public_status' ) ) {
			echo '<label class="didar-label" for="didar-public-status">' . esc_html__( 'وضعیت عمومی', 'didar' ) . '</label>';
			$this->render_status_select( 'didar-public-status', 'didar_public_status', $status );
		} else {
			echo '<span class="didar-label">' . esc_html__( 'وضعیت عمومی', 'didar' ) . '</span>';
			echo '<strong>' . esc_html( $this->service->get_status_label( $status ) ) . '</strong>';
		}
		echo '</p><p>';
		if ( current_user_can( 'didar_edit_public_notes' ) ) {
			echo '<label class="didar-label" for="didar-public-note">' . esc_html__( 'یادداشت عمومی', 'didar' ) . '</label>';
			echo '<textarea id="didar-public-note" name="didar_public_note" rows="5">' . esc_textarea( $note ) . '</textarea>';
		} else {
			echo '<span class="didar-label">' . esc_html__( 'یادداشت عمومی', 'didar' ) . '</span>';
			echo '<span class="didar-readonly-note">' . ( '' !== $note ? nl2br( esc_html( $note ) ) : '—' ) . '</span>';
		}
		echo '<span class="description">' . esc_html__( 'مالک درخواست این بخش را می‌بیند اما نمی‌تواند آن را تغییر دهد.', 'didar' ) . '</span></p></div>';
	}

	public function render_internal_workflow_box( $post ) {
		if ( ! $this->service->can_view_internal( $post->ID ) ) {
			echo '<p>' . esc_html__( 'شما اجازه مشاهده این بخش را ندارید.', 'didar' ) . '</p>';
			return;
		}
		$status      = $this->service->get_internal_status( $post->ID );
		$note        = $this->service->get_internal_note( $post->ID );
		$assigned_id = $this->service->get_assigned_user_id( $post->ID );
		echo '<div class="didar-admin-wrap didar-admin-workflow" dir="rtl"><p>';
		if ( current_user_can( 'didar_change_internal_status' ) ) {
			echo '<label class="didar-label" for="didar-internal-status">' . esc_html__( 'وضعیت داخلی', 'didar' ) . '</label>';
			$this->render_status_select( 'didar-internal-status', 'didar_internal_status', $status );
		} else {
			echo '<span class="didar-label">' . esc_html__( 'وضعیت داخلی', 'didar' ) . '</span>';
			echo '<strong>' . esc_html( $this->service->get_status_label( $status ) ) . '</strong>';
		}
		echo '</p><p>';
		if ( current_user_can( 'didar_add_internal_notes' ) ) {
			echo '<label class="didar-label" for="didar-internal-note">' . esc_html__( 'یادداشت داخلی', 'didar' ) . '</label>';
			echo '<textarea id="didar-internal-note" name="didar_internal_note" rows="5">' . esc_textarea( $note ) . '</textarea>';
		} else {
			echo '<span class="didar-label">' . esc_html__( 'یادداشت داخلی', 'didar' ) . '</span>';
			echo '<span class="didar-readonly-note">' . ( '' !== $note ? nl2br( esc_html( $note ) ) : '—' ) . '</span>';
		}
		echo '</p><p>';
		if ( current_user_can( 'didar_assign_requests' ) ) {
			echo '<label class="didar-label" for="didar-assigned-user">' . esc_html__( 'مسئول درخواست', 'didar' ) . '</label>';
			echo '<select id="didar-assigned-user" name="didar_assigned_user_id"><option value="0">' . esc_html__( 'بدون مسئول / ارجاع نشده', 'didar' ) . '</option>';
			foreach ( $this->service->eligible_assignees() as $user ) {
				echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $assigned_id, $user->ID, false ) . '>' . esc_html( $user->display_name . ' — ' . $user->user_email ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<span class="didar-label">' . esc_html__( 'مسئول درخواست', 'didar' ) . '</span>';
			$user = $assigned_id ? get_user_by( 'id', $assigned_id ) : false;
			echo '<strong>' . esc_html( $user ? $user->display_name : __( 'ارجاع نشده', 'didar' ) ) . '</strong>';
		}
		echo '</p></div>';
	}

	public function render_activity_box( $post ) {
		if ( ! $this->service->can_view_history( $post->ID ) ) {
			echo '<p>' . esc_html__( 'شما اجازه مشاهده تاریخچه را ندارید.', 'didar' ) . '</p>';
			return;
		}
		$this->render_activity_timeline( $this->service->get_events( $post->ID ) );
	}

	private function render_status_select( $id, $name, $selected_status ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( Didar_Reference_Data::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private function render_activity_timeline( $events ) {
		if ( ! $events ) {
			echo '<p class="didar-empty-history">' . esc_html__( 'هنوز فعالیتی ثبت نشده است.', 'didar' ) . '</p>';
			return;
		}
		echo '<ol class="didar-activity-timeline" dir="rtl">';
		foreach ( $events as $event ) {
			echo '<li><div class="didar-activity-heading"><strong>' . esc_html( $this->service->get_event_label( $event['event_type'] ) ) . '</strong><time datetime="' . esc_attr( $this->service->format_event_datetime_attribute( $event['created_at_gmt'] ) ) . '">' . esc_html( $this->service->format_event_time( $event['created_at_gmt'] ) ) . '</time></div>';
			echo '<p class="didar-activity-actor">' . esc_html( sprintf( __( 'توسط %s', 'didar' ), $this->service->get_event_actor_label( $event ) ) ) . '</p>';
			$context_label = $this->service->get_event_context_label( $event );
			if ( $context_label ) {
				echo '<p class="didar-activity-context">' . esc_html( sprintf( __( 'دسته مدرک: %s', 'didar' ), $context_label ) ) . '</p>';
			}
			if ( null !== $event['old_value'] || null !== $event['new_value'] ) {
				echo '<div class="didar-activity-change"><span><b>' . esc_html__( 'مقدار قبلی:', 'didar' ) . '</b> ' . nl2br( esc_html( $this->service->format_event_value( $event['event_type'], $event['old_value'] ) ) ) . '</span><span><b>' . esc_html__( 'مقدار جدید:', 'didar' ) . '</b> ' . nl2br( esc_html( $this->service->format_event_value( $event['event_type'], $event['new_value'] ) ) ) . '</span></div>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	public function render_fields_box( $post ) {
		$type  = get_post_meta( $post->ID, '_didar_form_type', true );
		$state = $this->get_admin_state( $post->ID );
		if ( ! $type && ! empty( $state['form_type'] ) ) {
			$type = $state['form_type'];
		}
		$form          = $this->registry->get( $type );
		$stored_values = $this->service->get_fields( $post->ID );
		$values        = isset( $state['values'] ) ? array_merge( $stored_values, $state['values'] ) : $stored_values;
		$errors        = isset( $state['errors'] ) ? $state['errors'] : array();

		echo '<div id="didar-admin-fields" class="didar-admin-wrap" dir="rtl">';
		if ( $form ) {
			$this->renderer->render_sections( $form, $values, $errors, 'admin', $post->ID );
			$this->render_historical_fields( $type, $stored_values );
		} else {
			echo '<div class="didar-admin-placeholder"><span class="dashicons dashicons-forms" aria-hidden="true"></span><p>' . esc_html__( 'ابتدا نوع فرم را انتخاب کنید تا فیلدهای مربوط نمایش داده شوند.', 'didar' ) . '</p></div>';
		}
		echo '</div>';
	}

	private function render_historical_fields( $form_type, $values ) {
		$historical = array();
		foreach ( $this->registry->legacy_fields( $form_type ) as $name => $field ) {
			if ( ! array_key_exists( $name, $values ) || '' === $values[ $name ] || array() === $values[ $name ] ) {
				continue;
			}
			$historical[] = array(
				'label' => $field['label'],
				'value' => $this->service->format_value( $field, $values[ $name ] ),
			);
		}

		if ( ! $historical ) {
			return;
		}

		echo '<section class="didar-historical-data" aria-labelledby="didar-historical-data-title">';
		echo '<h3 id="didar-historical-data-title">' . esc_html__( 'اطلاعات تاریخی', 'didar' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'این فیلدها متعلق به نسخه قبلی فرم هستند و فقط برای نگهداری سابقه نمایش داده می‌شوند.', 'didar' ) . '</p><dl>';
		foreach ( $historical as $item ) {
			echo '<div><dt>' . esc_html( $item['label'] ) . '</dt><dd>' . nl2br( esc_html( $item['value'] ) ) . '</dd></div>';
		}
		echo '</dl></section>';
	}

	public function render_details_box( $post ) {
		$owner_id = $post->post_author ? (int) $post->post_author : get_current_user_id();
		echo '<div class="didar-admin-wrap" dir="rtl">';
		if ( current_user_can( 'didar_change_request_owner' ) ) {
			$users        = get_users( array( 'number' => 100, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
			$listed_owner = false;
			foreach ( $users as $user ) {
				if ( (int) $user->ID === $owner_id ) {
					$listed_owner = true;
					break;
				}
			}
			if ( ! $listed_owner ) {
				$current_owner = get_user_by( 'id', $owner_id );
				if ( $current_owner ) {
					array_unshift( $users, $current_owner );
				}
			}
			echo '<p><label for="didar-owner"><strong>' . esc_html__( 'مالک درخواست', 'didar' ) . '</strong></label><select id="didar-owner" name="didar_owner">';
			foreach ( $users as $user ) {
				echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $owner_id, $user->ID, false ) . '>' . esc_html( $user->display_name . ' — ' . $user->user_email ) . '</option>';
			}
			echo '</select></p><p class="description">' . esc_html__( 'در سایت‌های دارای بیش از ۱۰۰ کاربر، مالک فعلی همواره حفظ می‌شود مگر اینکه گزینه دیگری انتخاب شود.', 'didar' ) . '</p>';
		} else {
			$owner = get_user_by( 'id', $owner_id );
			echo '<p><strong>' . esc_html__( 'مالک درخواست:', 'didar' ) . '</strong><br>' . esc_html( $owner ? $owner->display_name : __( 'نامشخص', 'didar' ) ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'ایجادکننده ثبت‌شده:', 'didar' ) . '</strong><br>';
		$creator = get_user_by( 'id', $this->service->get_creator_user_id( $post->ID ) );
		echo esc_html( $creator ? $creator->display_name : __( 'نامشخص', 'didar' ) ) . '</p>';
		echo '</div>';
	}

	public function save_submission( $post_id, $post, $update ) {
		if ( self::$saving || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['didar_admin_nonce'] ) || is_array( $_POST['didar_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['didar_admin_nonce'] ) ), 'didar_admin_save_submission_' . $post_id ) ) {
			return;
		}
		if ( ! Didar_Access_Control::can_edit_request( $post_id ) ) {
			return;
		}

		$stored_type = get_post_meta( $post_id, '_didar_form_type', true );
		$posted_type = isset( $_POST['didar_form_type'] ) && ! is_array( $_POST['didar_form_type'] ) ? sanitize_key( wp_unslash( $_POST['didar_form_type'] ) ) : '';
		$form_type   = $stored_type ? $stored_type : $posted_type;
		$form        = $this->registry->get( $form_type );
		$raw         = isset( $_POST['didar_fields'] ) && is_array( $_POST['didar_fields'] ) ? wp_unslash( $_POST['didar_fields'] ) : array();
		$note_errors = array();
		$shared_note = $this->service->get_shared_note( $post_id );
		if ( isset( $_POST['didar_shared_note'] ) ) {
			if ( is_array( $_POST['didar_shared_note'] ) ) {
				$note_errors['shared_note'] = __( 'ساختار یادداشت مشترک معتبر نیست.', 'didar' );
			} else {
				$shared_note = sanitize_textarea_field( wp_unslash( $_POST['didar_shared_note'] ) );
			}
		}

		$workflow_changes = array();
		$public_status    = $this->service->get_public_status( $post_id );
		if ( current_user_can( 'didar_change_public_status' ) && isset( $_POST['didar_public_status'] ) ) {
			if ( is_array( $_POST['didar_public_status'] ) ) {
				$note_errors['public_status'] = __( 'ساختار وضعیت عمومی معتبر نیست.', 'didar' );
			} else {
				$public_status = sanitize_key( wp_unslash( $_POST['didar_public_status'] ) );
				if ( ! isset( Didar_Reference_Data::statuses()[ $public_status ] ) ) {
					$note_errors['public_status'] = __( 'وضعیت عمومی معتبر نیست.', 'didar' );
				}
			}
		}
		foreach ( array( 'public_note', 'internal_note' ) as $note_key ) {
			$post_key = 'didar_' . $note_key;
			$cap      = 'public_note' === $note_key ? 'didar_edit_public_notes' : 'didar_add_internal_notes';
			if ( current_user_can( $cap ) && isset( $_POST[ $post_key ] ) ) {
				if ( is_array( $_POST[ $post_key ] ) ) {
					$note_errors[ $note_key ] = __( 'ساختار یادداشت معتبر نیست.', 'didar' );
				} else {
					$workflow_changes[ $note_key ] = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) );
				}
			}
		}
		if ( current_user_can( 'didar_change_internal_status' ) && isset( $_POST['didar_internal_status'] ) ) {
			if ( is_array( $_POST['didar_internal_status'] ) ) {
				$note_errors['internal_status'] = __( 'ساختار وضعیت داخلی معتبر نیست.', 'didar' );
			} else {
				$internal_status = sanitize_key( wp_unslash( $_POST['didar_internal_status'] ) );
				if ( isset( Didar_Reference_Data::statuses()[ $internal_status ] ) ) {
					$workflow_changes['internal_status'] = $internal_status;
				} else {
					$note_errors['internal_status'] = __( 'وضعیت داخلی معتبر نیست.', 'didar' );
				}
			}
		}
		if ( current_user_can( 'didar_assign_requests' ) && isset( $_POST['didar_assigned_user_id'] ) ) {
			if ( is_array( $_POST['didar_assigned_user_id'] ) ) {
				$note_errors['assigned_user'] = __( 'ساختار مسئول درخواست معتبر نیست.', 'didar' );
			} else {
				$assigned_id = absint( wp_unslash( $_POST['didar_assigned_user_id'] ) );
				if ( $assigned_id && ! $this->service->is_eligible_assignee( $assigned_id ) ) {
					$note_errors['assigned_user'] = __( 'کاربر انتخاب‌شده مجاز به دریافت درخواست نیست.', 'didar' );
				} else {
					$workflow_changes['assigned_user_id'] = $assigned_id;
				}
			}
		}

		if ( ! $form ) {
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => __( 'نوع فرم نامعتبر است.', 'didar' ) ), $shared_note );
			if ( ! $stored_type ) {
				$this->force_draft( $post_id, $post );
			}
			return;
		}
		if ( $note_errors ) {
			$this->store_admin_state( $post_id, $form_type, $raw, $note_errors, $shared_note );
			return;
		}

		$result = $this->validator->validate( $form_type, $raw, 'admin', $post_id );
		if ( ! $result['valid'] ) {
			$this->store_admin_state( $post_id, $form_type, $raw, $result['errors'], $shared_note );
			if ( ! $stored_type ) {
				$this->force_draft( $post_id, $post );
			}
			return;
		}

		$status   = $public_status ? $public_status : $form['default_status'];
		$owner_id = (int) $post->post_author;
		if ( current_user_can( 'didar_change_request_owner' ) && isset( $_POST['didar_owner'] ) && ! is_array( $_POST['didar_owner'] ) ) {
			$requested_owner = absint( wp_unslash( $_POST['didar_owner'] ) );
			if ( get_user_by( 'id', $requested_owner ) ) {
				$owner_id = $requested_owner;
			}
		}
		self::$saving = true;
		$result       = $this->service->update( $post_id, $form_type, $result['data'], $status, $owner_id );
		if ( ! is_wp_error( $result ) ) {
			$this->service->update_notes( $post_id, $shared_note );
			if ( $workflow_changes ) {
				$result = $this->service->update_workflow( $post_id, $workflow_changes );
			}
		}
		self::$saving = false;
		if ( is_wp_error( $result ) ) {
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => $result->get_error_message() ), $shared_note );
		}
	}

	private function force_draft( $post_id, $post ) {
		if ( 'publish' !== $post->post_status && 'private' !== $post->post_status ) {
			return;
		}
		self::$saving = true;
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		self::$saving = false;
	}

	private function store_admin_state( $post_id, $form_type, $values, $errors, $shared_note = '' ) {
		set_transient(
			'didar_admin_errors_' . get_current_user_id() . '_' . $post_id,
			array(
				'form_type'   => $form_type,
				'values'      => $values,
				'errors'      => $errors,
				'shared_note' => $shared_note,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private function get_admin_state( $post_id ) {
		if ( array_key_exists( $post_id, $this->state_cache ) ) {
			return $this->state_cache[ $post_id ];
		}
		$key   = 'didar_admin_errors_' . get_current_user_id() . '_' . $post_id;
		$state = get_transient( $key );
		delete_transient( $key );
		$this->state_cache[ $post_id ] = is_array( $state ) ? $state : array();
		return $this->state_cache[ $post_id ];
	}

	public function filter_save_redirect( $location, $post_id ) {
		$key   = 'didar_admin_errors_' . get_current_user_id() . '_' . absint( $post_id );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['errors'] ) ) {
			return $location;
		}

		return add_query_arg( 'didar_save_error', 1, remove_query_arg( 'message', $location ) );
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || Didar_Post_Type::POST_TYPE !== $screen->post_type || empty( $_GET['post'] ) ) {
			return;
		}
		if ( is_array( $_GET['post'] ) ) {
			return;
		}
		$state = $this->get_admin_state( absint( wp_unslash( $_GET['post'] ) ) );
		if ( empty( $state['errors'] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'درخواست ذخیره نشد. خطاهای مشخص‌شده را برطرف کنید.', 'didar' ) . '</strong></p><ul class="didar-admin-errors">';
		foreach ( $state['errors'] as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		$is_settings = $screen && false !== strpos( (string) $screen->id, 'didar-page-settings' );
		if ( ! $screen || ( Didar_Post_Type::POST_TYPE !== $screen->post_type && ! $is_settings ) ) {
			return;
		}
		wp_enqueue_style( 'didar-admin', DIDAR_URL . 'assets/css/admin.css', array(), DIDAR_VERSION );
		if ( $is_settings ) {
			return;
		}
		wp_enqueue_script( 'didar-admin', DIDAR_URL . 'assets/js/admin.js', array(), DIDAR_VERSION, true );
		wp_localize_script( 'didar-admin', 'didarAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'didar_admin_fields' ), 'uploadNonce' => wp_create_nonce( 'didar_upload_file' ), 'removeNonce' => wp_create_nonce( 'didar_remove_file' ), 'loading' => __( 'در حال بارگذاری فیلدها…', 'didar' ), 'error' => __( 'بارگذاری فیلدها انجام نشد.', 'didar' ), 'messages' => array( 'uploading' => __( 'در حال بارگذاری…', 'didar' ), 'uploadInProgress' => __( 'تا پایان بارگذاری فایل صبر کنید.', 'didar' ), 'uploadError' => __( 'بارگذاری فایل انجام نشد.', 'didar' ), 'remove' => __( 'حذف', 'didar' ), 'removeError' => __( 'حذف فایل انجام نشد.', 'didar' ), 'fileLimit' => __( 'برای این فیلد حداکثر %d فایل مجاز است.', 'didar' ) ) ) );
	}

	public function columns( $columns ) {
		return array(
			'cb'            => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox">',
			'submission_id' => __( 'شماره درخواست', 'didar' ),
			'form_type'     => __( 'نوع فرم', 'didar' ),
			'user'          => __( 'کاربر', 'didar' ),
			'status'        => __( 'وضعیت عمومی', 'didar' ),
			'internal_status' => __( 'وضعیت داخلی', 'didar' ),
			'assigned_user' => __( 'مسئول', 'didar' ),
			'date'          => __( 'تاریخ', 'didar' ),
		);
	}

	public function column_content( $column, $post_id ) {
		if ( 'submission_id' === $column ) {
			echo '<strong><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">#' . esc_html( $post_id ) . '</a></strong>';
		} elseif ( 'form_type' === $column ) {
			$type = get_post_meta( $post_id, '_didar_form_type', true );
			$form = $this->registry->get( $type );
			echo esc_html( $form ? $form['label'] : $type );
		} elseif ( 'user' === $column ) {
			$user = get_user_by( 'id', get_post_field( 'post_author', $post_id ) );
			echo $user ? esc_html( $user->display_name . ' — ' . $user->user_email ) : '—';
		} elseif ( 'status' === $column ) {
			echo esc_html( $this->service->get_status_label( $this->service->get_public_status( $post_id ) ) );
		} elseif ( 'internal_status' === $column ) {
			echo esc_html( $this->service->get_status_label( $this->service->get_internal_status( $post_id ) ) );
		} elseif ( 'assigned_user' === $column ) {
			$assigned_id = $this->service->get_assigned_user_id( $post_id );
			$user        = $assigned_id ? get_user_by( 'id', $assigned_id ) : false;
			echo esc_html( $user ? $user->display_name : __( 'ارجاع نشده', 'didar' ) );
		}
	}

	public function filters( $post_type ) {
		if ( Didar_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		$selected_type   = isset( $_GET['didar_form_type_filter'] ) && ! is_array( $_GET['didar_form_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['didar_form_type_filter'] ) ) : '';
		$selected_status = isset( $_GET['didar_status_filter'] ) && ! is_array( $_GET['didar_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['didar_status_filter'] ) ) : '';
		echo '<select name="didar_form_type_filter"><option value="">' . esc_html__( 'همه انواع فرم', 'didar' ) . '</option>';
		foreach ( $this->registry->all() as $type => $form ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( $selected_type, $type, false ) . '>' . esc_html( $form['label'] ) . '</option>';
		}
		echo '</select><select name="didar_status_filter"><option value="">' . esc_html__( 'همه وضعیت‌ها', 'didar' ) . '</option>';
		foreach ( Didar_Reference_Data::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Didar_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$this->request_search->apply_to_query( $query, $query->get( 's' ) );
		$meta_query = array();
		if ( ! empty( $_GET['didar_form_type_filter'] ) && ! is_array( $_GET['didar_form_type_filter'] ) ) {
			$type = sanitize_key( wp_unslash( $_GET['didar_form_type_filter'] ) );
			if ( $this->registry->is_valid_type( $type ) ) {
				$meta_query[] = array( 'key' => '_didar_form_type', 'value' => $type );
			}
		}
		if ( ! empty( $_GET['didar_status_filter'] ) && ! is_array( $_GET['didar_status_filter'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['didar_status_filter'] ) );
			if ( isset( Didar_Reference_Data::statuses()[ $status ] ) ) {
				$meta_query[] = array(
					'relation' => 'OR',
					array( 'key' => '_didar_public_status', 'value' => $status ),
					array( 'key' => '_didar_status', 'value' => $status ),
				);
			}
		}
		$assignment = isset( $_GET['didar_assignment'] ) && ! is_array( $_GET['didar_assignment'] ) ? sanitize_key( wp_unslash( $_GET['didar_assignment'] ) ) : '';
		if ( 'mine' === $assignment ) {
			$meta_query[] = array( 'key' => '_didar_assigned_user_id', 'value' => get_current_user_id(), 'compare' => '=', 'type' => 'NUMERIC' );
		} elseif ( 'unassigned' === $assignment ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => '_didar_assigned_user_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_didar_assigned_user_id', 'value' => array( '', '0' ), 'compare' => 'IN' ),
			);
		}
		if ( $meta_query ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query = array_merge( array( 'relation' => 'AND' ), $meta_query );
			}
			$query->set( 'meta_query', $meta_query );
		}
	}

	public function request_row_actions( $actions, $post ) {
		if ( Didar_Post_Type::POST_TYPE !== $post->post_type || ! Didar_Access_Control::can_edit_request( $post->ID ) ) {
			return $actions;
		}
		$url = get_edit_post_link( $post->ID, '' );
		if ( $url ) {
			$actions['didar_view_details'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'مشاهده جزئیات', 'didar' ) . '</a>';
		}

		return $actions;
	}

	public function assignment_views( $views ) {
		if ( ! current_user_can( 'didar_view_requests' ) ) {
			return $views;
		}
		$base_url = admin_url( 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE );
		$current  = isset( $_GET['didar_assignment'] ) && ! is_array( $_GET['didar_assignment'] ) ? sanitize_key( wp_unslash( $_GET['didar_assignment'] ) ) : '';
		$mine_count = new WP_Query(
			array(
				'post_type'      => Didar_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( array( 'key' => '_didar_assigned_user_id', 'value' => get_current_user_id(), 'compare' => '=', 'type' => 'NUMERIC' ) ),
			)
		);
		$views['didar_assigned_to_me'] = '<a href="' . esc_url( add_query_arg( 'didar_assignment', 'mine', $base_url ) ) . '" class="' . ( 'mine' === $current ? 'current' : '' ) . '">' . esc_html__( 'درخواست‌های ارجاع‌شده به من', 'didar' ) . ' <span class="count">(' . esc_html( $mine_count->found_posts ) . ')</span></a>';
		$views['didar_unassigned'] = '<a href="' . esc_url( add_query_arg( 'didar_assignment', 'unassigned', $base_url ) ) . '" class="' . ( 'unassigned' === $current ? 'current' : '' ) . '">' . esc_html__( 'ارجاع‌نشده', 'didar' ) . '</a>';
		return $views;
	}
}
