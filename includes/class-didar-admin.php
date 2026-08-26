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
	private $logger;
	private $workflow;
	private $settings_transfer;
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
		$this->logger = new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $this->settings, $this->logger );
		$this->settings_transfer = new Didar_Settings_Transfer( $registry, $this->settings, $this->logger );

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
		add_action( 'admin_post_didar_test_connection', array( $this, 'test_didar_connection' ) );
		add_action( 'admin_post_didar_manual_sync', array( $this, 'manual_sync' ) );
		add_action( 'admin_post_didar_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'admin_post_didar_refresh_pipelines', array( $this, 'refresh_pipelines' ) );
		add_action( 'admin_post_didar_settings_export', array( $this, 'settings_export' ) );
		add_action( 'admin_post_didar_settings_import_preview', array( $this, 'settings_import_preview' ) );
		add_action( 'admin_post_didar_settings_import_apply', array( $this, 'settings_import_apply' ) );
		add_action( 'admin_post_didar_rotate_webhook_secret', array( $this, 'rotate_webhook_secret' ) );
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
		add_submenu_page( 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE, __( 'تشخیص دیدار', 'didar' ), __( 'تشخیص و گزارش‌ها', 'didar' ), 'didar_manage_settings', 'didar-diagnostics', array( $this, 'render_diagnostics_page' ) );
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

		add_settings_section( 'didar_profile_settings', __( 'تنظیمات فرم اطلاعات کاربری', 'didar' ), array( $this, 'render_profile_settings_description' ), 'didar-page-settings' );
		add_settings_field( 'didar_profile_field_states', __( 'نمایش و ویرایش فیلدها', 'didar' ), array( $this, 'render_profile_field_states' ), 'didar-page-settings', 'didar_profile_settings' );
		add_settings_field( 'didar_user_person_mappings', __( 'نگاشت اطلاعات کاربر به مخاطب دیدار', 'didar' ), array( $this, 'render_user_person_mappings' ), 'didar-page-settings', 'didar_profile_settings' );

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

		add_settings_section( 'didar_crm_connection', __( 'اتصال به دیدار CRM', 'didar' ), '__return_false', 'didar-page-settings' );
		add_settings_field( 'didar_api_key', __( 'کلید API دیدار', 'didar' ), array( $this, 'render_didar_api_key' ), 'didar-page-settings', 'didar_crm_connection' );
		add_settings_field( 'didar_default_owner_id', __( 'شناسه User مسئول پیش‌فرض دیدار', 'didar' ), array( $this, 'render_didar_text_setting' ), 'didar-page-settings', 'didar_crm_connection', array( 'key' => 'didar_default_owner_id', 'description' => 'از User List رسمی دیدار دریافت شود.' ) );
		add_settings_field( 'didar_default_pipeline_id', __( 'شناسه کاریز پیش‌فرض معامله', 'didar' ), array( $this, 'render_didar_text_setting' ), 'didar-page-settings', 'didar_crm_connection', array( 'key' => 'didar_default_pipeline_id', 'description' => 'از List Deal Pipelines دریافت شود.' ) );
		add_settings_field( 'didar_webhook_secret', __( 'توکن پروژه برای وب‌هوک', 'didar' ), array( $this, 'render_didar_secret' ), 'didar-page-settings', 'didar_crm_connection', array( 'key' => 'didar_webhook_secret' ) );
		add_settings_field( 'didar_webhook_security', __( 'امنیت وب‌هوک دیدار', 'didar' ), array( $this, 'render_webhook_security' ), 'didar-page-settings', 'didar_crm_connection' );
		add_settings_field( 'didar_debug_logging', __( 'گزارش‌گیری تشخیصی دیدار', 'didar' ), array( $this, 'render_debug_logging' ), 'didar-page-settings', 'didar_crm_connection' );
		add_settings_field( 'didar_system_field_ids', __( 'فیلدهای سیستمی Deal', 'didar' ), array( $this, 'render_system_field_ids' ), 'didar-page-settings', 'didar_crm_connection' );

		add_settings_section( 'didar_crm_workflow', __( 'گردش کار فرم‌ها در دیدار', 'didar' ), '__return_false', 'didar-page-settings' );
		add_settings_field( 'didar_form_workflows', __( 'گردش کار فرم‌ها', 'didar' ), array( $this, 'render_form_workflows' ), 'didar-page-settings', 'didar_crm_workflow' );
		add_settings_field( 'didar_broker_user_map', __( 'کاربر WordPress ← User دیدار', 'didar' ), array( $this, 'render_broker_map' ), 'didar-page-settings', 'didar_crm_workflow' );
		add_settings_field( 'didar_public_status_field_id', __( 'Custom Field وضعیت عمومی Deal', 'didar' ), array( $this, 'render_didar_text_setting' ), 'didar-page-settings', 'didar_crm_workflow', array( 'key' => 'didar_public_status_field_id', 'description' => 'اختیاری؛ برای Public Status است، نه Pipeline Stage.' ) );

		add_settings_section( 'didar_crm_field_mapping', __( 'نگاشت فیلدهای فرم به Didar', 'didar' ), array( $this, 'render_didar_mapping_description' ), 'didar-page-settings' );
		add_settings_field( 'didar_field_mappings', __( 'نگاشت فیلدها', 'didar' ), array( $this, 'render_didar_field_mappings' ), 'didar-page-settings', 'didar_crm_field_mapping' );
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
		$output['profile_field_states'] = array();
		$submitted_profile_states = isset( $input['profile_field_states'] ) && is_array( $input['profile_field_states'] ) ? $input['profile_field_states'] : array();
		foreach ( Didar_Settings::PROFILE_FIELD_STATES as $field => $default ) {
			$state = isset( $submitted_profile_states[ $field ] ) && is_scalar( $submitted_profile_states[ $field ] ) ? sanitize_key( wp_unslash( $submitted_profile_states[ $field ] ) ) : $default;
			$output['profile_field_states'][ $field ] = in_array( $state, array( 'editable', 'readonly', 'disabled' ), true ) ? $state : $default;
		}
		$output['didar_user_person_mappings'] = array();
		$submitted_person_mappings = isset( $input['didar_user_person_mappings'] ) && is_array( $input['didar_user_person_mappings'] ) ? $input['didar_user_person_mappings'] : array();
		foreach ( array( 'gender', 'display_name', 'profile_image_url' ) as $property ) {
			$key = isset( $submitted_person_mappings[ $property ] ) && is_scalar( $submitted_person_mappings[ $property ] ) ? sanitize_text_field( wp_unslash( $submitted_person_mappings[ $property ] ) ) : '';
			if ( $key ) { $output['didar_user_person_mappings'][ $property ] = $key; }
		}
		$current_api_key = isset( $current['didar_api_key'] ) ? (string) $current['didar_api_key'] : '';
		$submitted_api_key = isset( $input['didar_api_key'] ) && is_scalar( $input['didar_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['didar_api_key'] ) ) ) : '';
		$output['didar_api_key'] = $submitted_api_key ?: $current_api_key;
		$debug = isset( $input['didar_debug_logging'] ) && is_scalar( $input['didar_debug_logging'] ) ? sanitize_key( wp_unslash( $input['didar_debug_logging'] ) ) : 'off';
		$output['didar_debug_logging'] = in_array( $debug, array( 'off', 'errors', 'verbose' ), true ) ? $debug : 'off';
		foreach ( array( 'didar_default_owner_id', 'didar_default_pipeline_id', 'didar_public_status_field_id' ) as $key ) {
			$output[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
		}
		$current_secret = isset( $current['didar_webhook_secret'] ) ? (string) $current['didar_webhook_secret'] : '';
		// Webhook URL credentials are generated/rotated explicitly, never imported or edited via this form.
		$output['didar_webhook_secret'] = preg_match( '/^[a-f0-9]{64}$/', $current_secret ) ? $current_secret : Didar_Settings::generate_webhook_secret();
		$output['didar_webhook_legacy_enabled'] = ! empty( $input['didar_webhook_legacy_enabled'] ) ? 1 : 0;
		$output['didar_system_form_type_field_id'] = isset( $input['didar_system_form_type_field_id'] ) && is_scalar( $input['didar_system_form_type_field_id'] ) ? sanitize_text_field( wp_unslash( $input['didar_system_form_type_field_id'] ) ) : '';
		$output['didar_system_submission_id_field_id'] = isset( $input['didar_system_submission_id_field_id'] ) && is_scalar( $input['didar_system_submission_id_field_id'] ) ? sanitize_text_field( wp_unslash( $input['didar_system_submission_id_field_id'] ) ) : '';
		$output['didar_system_user_id_field_id'] = isset( $input['didar_system_user_id_field_id'] ) && is_scalar( $input['didar_system_user_id_field_id'] ) ? sanitize_text_field( wp_unslash( $input['didar_system_user_id_field_id'] ) ) : '';
		$output['didar_status_pipeline_stage_map'] = isset( $current['didar_status_pipeline_stage_map'] ) && is_array( $current['didar_status_pipeline_stage_map'] ) ? $current['didar_status_pipeline_stage_map'] : array();
		$validated_workflows = $this->workflow->validate_workflows( $input['didar_form_workflows'] ?? array() );
		$output['didar_form_workflows'] = array_replace( isset( $current['didar_form_workflows'] ) && is_array( $current['didar_form_workflows'] ) ? $current['didar_form_workflows'] : array(), $validated_workflows );
		$output['didar_default_owner_id'] = $this->normalize_didar_user_mapping( $output['didar_default_owner_id'], $current['didar_default_owner_id'] ?? '', 0, 'didar_default_owner_id' );
		$system_pipelines = array(); foreach ( $output['didar_form_workflows'] as $workflow ) { if ( ! empty( $workflow['pipeline_id'] ) ) { $system_pipelines[] = $workflow['pipeline_id']; } } $system_pipelines = array_values( array_unique( $system_pipelines ) );
		foreach ( array( 'didar_system_form_type_field_id', 'didar_system_submission_id_field_id', 'didar_system_user_id_field_id', 'didar_public_status_field_id' ) as $system_key ) { $field_key = $output[ $system_key ] ?? ''; if ( ! $field_key ) { continue; } $metadata = $this->workflow->custom_field( $field_key ); $valid = Didar_Custom_Field_Catalog::is_deal_field( $metadata ); foreach ( $system_pipelines as $pipeline_id ) { $valid = $valid && $this->workflow->custom_field_available_for_pipeline( $metadata, $pipeline_id ); } if ( ! $valid ) { $output[ $system_key ] = $current[ $system_key ] ?? ''; add_settings_error( Didar_Settings::OPTION_NAME, 'didar_invalid_system_custom_field_' . $system_key, __( 'کاستوم‌فیلد سیستمی انتخاب‌شده در همه کاریزهای فعال قابل استفاده نیست.', 'didar' ), 'error' ); $this->logger->log( 'WARNING', 'didar_custom_field_mapping_invalid', 'Rejected invalid system Deal Custom Field mapping.', array( 'setting' => $system_key, 'custom_field_key' => $field_key, 'custom_field_id' => $metadata['id'] ?? '', 'pipeline_ids' => $system_pipelines ) ); } }
		$output['didar_broker_user_map'] = array();
		$submitted_brokers = isset( $input['didar_broker_user_map'] ) && is_array( $input['didar_broker_user_map'] ) ? $input['didar_broker_user_map'] : array();
		foreach ( $submitted_brokers as $wp_user_id => $didar_user_id ) { $wp_user_id = absint( $wp_user_id ); if ( ! $wp_user_id || ! is_scalar( $didar_user_id ) || '' === trim( (string) $didar_user_id ) ) { continue; } $candidate = sanitize_text_field( wp_unslash( $didar_user_id ) ); $normalized = $this->normalize_didar_user_mapping( $candidate, $current['didar_broker_user_map'][ $wp_user_id ] ?? '', $wp_user_id, 'didar_broker_user_map' ); if ( $normalized ) { $output['didar_broker_user_map'][ $wp_user_id ] = $normalized; } }
		$output['didar_field_mappings'] = array();
		$submitted_mappings = isset( $input['didar_field_mappings'] ) && is_array( $input['didar_field_mappings'] ) ? $input['didar_field_mappings'] : array();
		$targets = array( 'person_native', 'person_custom', 'deal_native', 'deal_custom' );
		foreach ( $this->registry->all() as $form_type => $form ) { foreach ( $this->registry->fields( $form_type ) as $field_key => $field ) { if ( ! empty( $field['internal'] ) || 'honeypot' === $field['type'] ) { continue; } $raw = isset( $submitted_mappings[ $form_type ][ $field_key ] ) && is_array( $submitted_mappings[ $form_type ][ $field_key ] ) ? $submitted_mappings[ $form_type ][ $field_key ] : array(); $target = isset( $raw['target'] ) && in_array( sanitize_key( $raw['target'] ), $targets, true ) ? sanitize_key( $raw['target'] ) : ''; $field_name = isset( $raw['field'] ) && is_scalar( $raw['field'] ) ? sanitize_text_field( wp_unslash( $raw['field'] ) ) : ''; if ( ! $target || ! $field_name ) { continue; } if ( 'deal_custom' === $target ) { $pipeline_id = $output['didar_form_workflows'][ $form_type ]['pipeline_id'] ?? ( $this->workflow->workflow( $form_type )['pipeline_id'] ?? '' ); $metadata = $this->workflow->custom_field( $field_name ); $valid = $pipeline_id && Didar_Custom_Field_Catalog::is_deal_field( $metadata ) && $this->workflow->custom_field_available_for_pipeline( $metadata, $pipeline_id ); if ( ! $valid ) { $current_map = $current['didar_field_mappings'][ $form_type ][ $field_key ] ?? array(); if ( is_array( $current_map ) && ( $current_map['field'] ?? '' ) === $field_name && ( $current_map['target'] ?? '' ) === $target ) { $output['didar_field_mappings'][ $form_type ][ $field_key ] = $current_map; } add_settings_error( Didar_Settings::OPTION_NAME, 'didar_invalid_custom_field_' . $form_type . '_' . $field_key, __( 'فیلد انتخاب‌شده در کاریز فعلی قابل استفاده نیست و ذخیره نشد.', 'didar' ), 'error' ); $this->logger->log( 'WARNING', 'didar_custom_field_mapping_invalid', 'Rejected invalid Deal Custom Field mapping.', array( 'form_type' => $form_type, 'field_key' => $field_key, 'custom_field_key' => $field_name, 'custom_field_id' => $metadata['id'] ?? '', 'pipeline_id' => $pipeline_id ) ); $this->logger->log( 'WARNING', 'didar_custom_field_pipeline_mismatch', 'Deal Custom Field is unavailable for the selected pipeline.', array( 'form_type' => $form_type, 'field_key' => $field_key, 'custom_field_key' => $field_name, 'pipeline_id' => $pipeline_id ) ); continue; } } $output['didar_field_mappings'][ $form_type ][ $field_key ] = array( 'target' => $target, 'field' => $field_name ); } }
		foreach ( (array) ( $current['didar_field_mappings'] ?? array() ) as $form_type => $maps ) { foreach ( (array) $maps as $field_key => $map ) { if ( is_array( $map ) && in_array( $map['target'] ?? '', array( 'person_native', 'person_custom', 'deal_native' ), true ) && ! isset( $output['didar_field_mappings'][ $form_type ][ $field_key ] ) ) { $output['didar_field_mappings'][ $form_type ][ $field_key ] = $map; } } }
		$protection = $this->files->sync_storage_protection( $output['file_download_mode'] );
		if ( is_wp_error( $protection ) ) {
			$output['file_download_mode'] = Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;
			add_settings_error( Didar_Settings::OPTION_NAME, 'didar_file_storage_protection', $protection->get_error_message(), 'error' );
		}

		return $output;
	}

	public function render_profile_settings_description() {
		echo '<p>این فرم با شورت‌کد <code>[didar_profile_form]</code> نمایش داده می‌شود. شماره تلفن توسط Digits و فرایند تأیید آن مدیریت می‌شود؛ تا زمانی که یک جریان تأییدشده تغییر شماره به این افزونه متصل نشده است، حتی در صورت انتخاب «قابل ویرایش» به‌صورت فقط‌خواندنی اجرا می‌شود.</p>';
	}

	public function render_profile_field_states() {
		$labels   = array( 'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'gender' => 'جنسیت', 'display_name' => 'نام نمایشی', 'mobile' => 'شماره تلفن', 'email' => 'ایمیل', 'profile_image' => 'تصویر پروفایل' );
		$settings = $this->settings->all();
		$states   = isset( $settings['profile_field_states'] ) && is_array( $settings['profile_field_states'] ) ? $settings['profile_field_states'] : array();
		echo '<table class="widefat striped"><tbody>';
		foreach ( $labels as $field => $label ) {
			$value = sanitize_key( (string) ( $states[ $field ] ?? Didar_Settings::PROFILE_FIELD_STATES[ $field ] ) );
			$name  = Didar_Settings::OPTION_NAME . '[profile_field_states][' . $field . ']';
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td><select name="' . esc_attr( $name ) . '"><option value="editable" ' . selected( $value, 'editable', false ) . '>قابل ویرایش</option><option value="readonly" ' . selected( $value, 'readonly', false ) . '>فقط خواندنی</option><option value="disabled" ' . selected( $value, 'disabled', false ) . '>غیرفعال / مخفی</option></select>' . ( 'mobile' === $field ? ' <span class="description">ویرایش مستقیم شماره تلفن غیرفعال است.</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function render_user_person_mappings() {
		$settings = $this->settings->all();
		$mapping  = isset( $settings['didar_user_person_mappings'] ) && is_array( $settings['didar_user_person_mappings'] ) ? $settings['didar_user_person_mappings'] : array();
		$labels   = array( 'gender' => 'جنسیت', 'display_name' => 'نام نمایشی', 'profile_image_url' => 'نشانی تصویر پروفایل' );
		echo '<table class="widefat striped"><thead><tr><th>WordPress User/Profile</th><th>Didar Person native field</th></tr></thead><tbody>';
		foreach ( array( 'first_name' => 'FirstName', 'last_name' => 'LastName', 'mobile' => 'MobilePhone', 'email' => 'Email' ) as $property => $native_field ) {
			echo '<tr><td><code>' . esc_html( $property ) . '</code></td><td><code>' . esc_html( $native_field ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">نام، نام خانوادگی، موبایل و ایمیل به فیلدهای native Person ارسال می‌شوند. این سه مقدار فقط در صورت وارد کردن Field Key به Custom Field مخاطب دیدار ارسال می‌شوند. کلیدهای ناشناخته ذخیره می‌شوند و هنگام ارسال بررسی‌نشده محسوب می‌شوند.</p>';
		foreach ( $labels as $property => $label ) {
			$name = Didar_Settings::OPTION_NAME . '[didar_user_person_mappings][' . $property . ']';
			echo '<p><label>' . esc_html( $label ) . ' <input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $mapping[ $property ] ?? '' ) . '" placeholder="Field_..."></label></p>';
		}
	}

	public function render_didar_api_key() { $value = $this->settings->all(); echo '<input type="password" autocomplete="new-password" class="regular-text" name="' . esc_attr( Didar_Settings::OPTION_NAME ) . '[didar_api_key]" value="" placeholder="' . esc_attr( empty( $value['didar_api_key'] ) ? '' : '••••••••' ) . '">'; echo '<p class="description">کلید در سمت سرور استفاده می‌شود و در صفحه یا گزارش‌ها نمایش داده نمی‌شود.</p>'; if ( ! empty( $value['didar_api_key'] ) ) { $url = wp_nonce_url( admin_url( 'admin-post.php?action=didar_test_connection' ), 'didar_test_connection' ); echo '<p><a class="button" href="' . esc_url( $url ) . '">آزمون اتصال دیدار</a></p>'; } }
	public function render_debug_logging() { $s = $this->settings->all(); $v = $s['didar_debug_logging'] ?? 'off'; echo '<select name="' . esc_attr( Didar_Settings::OPTION_NAME ) . '[didar_debug_logging]"><option value="off" ' . selected( $v, 'off', false ) . '>خاموش</option><option value="errors" ' . selected( $v, 'errors', false ) . '>فقط هشدار و خطا</option><option value="verbose" ' . selected( $v, 'verbose', false ) . '>کامل (Verbose)</option></select><p class="description">این گزینه مستقل از WP_DEBUG است؛ اطلاعات حساس و کلیدهای API هرگز ثبت نمی‌شوند.</p>'; }
	public function test_didar_connection() { if ( ! current_user_can( 'didar_manage_settings' ) || ! check_admin_referer( 'didar_test_connection' ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); } $result = ( new Didar_Api_Client( $this->settings ) )->test_connection(); $url = add_query_arg( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'page' => 'didar-page-settings', 'didar_connection' => is_wp_error( $result ) ? 'failed' : 'success' ), admin_url( 'edit.php' ) ); wp_safe_redirect( $url ); exit; }
	public function render_didar_secret( $args ) { echo '<p class="description">کلید مسیر به‌صورت تصادفی تولید می‌شود و در خروجی تنظیمات قرار نمی‌گیرد. برای تغییر آن از بخش امنیت وب‌هوک استفاده کنید.</p>'; }
	public function render_webhook_security() {
		$settings = $this->settings->all();
		$url = $this->settings->webhook_url();
		echo '<p><strong>آدرس وب‌هوک:</strong> <input type="text" readonly class="large-text" value="' . esc_attr( $url ) . '"></p>';
		echo '<p class="description">برای محیط عمومی، آدرس باید با HTTPS شروع شود. کلید مسیر، credential احراز هویت است و نباید در گزارش یا فایل export قرار گیرد.</p>';
		$rotate = wp_nonce_url( admin_url( 'admin-post.php?action=didar_rotate_webhook_secret' ), 'didar_rotate_webhook_secret' );
		echo '<p><a class="button" href="' . esc_url( $rotate ) . '" onclick="return confirm(\'پس از تغییر کلید، آدرس وب‌هوک در سامانه دیدار نیز باید بروزرسانی شود. ادامه می‌دهید؟\');">ایجاد مجدد کلید امنیتی</a></p>';
		echo '<label><input type="checkbox" name="' . esc_attr( Didar_Settings::OPTION_NAME . '[didar_webhook_legacy_enabled]' ) . '" value="1" ' . checked( ! empty( $settings['didar_webhook_legacy_enabled'] ), true, false ) . '> فعال‌سازی موقت مسیر قدیمی فقط با هدر X-Didar-Webhook-Token</label>';
		echo '<p class="description">شناسه‌ای از سامانه دیدار در WordPress لازم نیست؛ احراز هویت فقط با کلید موجود در مسیر انجام می‌شود.</p>';
	}
	public function rotate_webhook_secret() {
		if ( ! current_user_can( 'didar_manage_settings' ) || ! check_admin_referer( 'didar_rotate_webhook_secret' ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); }
		$settings = $this->settings->all(); $secret = Didar_Settings::generate_webhook_secret();
		if ( $secret ) { $settings['didar_webhook_secret'] = $secret; update_option( Didar_Settings::OPTION_NAME, $settings, false ); }
		wp_safe_redirect( add_query_arg( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'page' => 'didar-page-settings', 'didar_webhook_rotated' => 1 ), admin_url( 'edit.php' ) ) ); exit;
	}
	public function render_didar_text_setting( $args ) { $settings = $this->settings->all(); $key = sanitize_key( $args['key'] ); $value = isset( $settings[ $key ] ) ? $settings[ $key ] : ''; if ( 'didar_default_owner_id' === $key ) { $this->render_didar_user_select( Didar_Settings::OPTION_NAME . '[' . $key . ']', $value ); echo '<p class="description">UserId کاربر فعال دیدار برای مالک پیش‌فرض معامله.</p>'; return; } echo '<input type="text" class="regular-text" name="' . esc_attr( Didar_Settings::OPTION_NAME . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '">'; if ( ! empty( $args['description'] ) ) { echo '<p class="description">' . esc_html( $args['description'] ) . '</p>'; } }
	public function render_system_field_ids() { $s = $this->settings->all(); $pipeline_ids = $this->configured_pipeline_ids(); echo '<p class="description">فقط کاستوم‌فیلدهای معامله که در همه کاریزهای فعال فرم‌ها قابل استفاده هستند نمایش داده می‌شوند.</p>'; foreach ( array( 'didar_system_form_type_field_id' => 'نوع فرم', 'didar_system_submission_id_field_id' => 'شناسه درخواست WordPress', 'didar_system_user_id_field_id' => 'شناسه کاربر WordPress' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . ' '; $this->render_custom_field_select( Didar_Settings::OPTION_NAME . '[' . $key . ']', $s[ $key ] ?? '', $pipeline_ids, true ); echo '</label><br>'; } }
	public function render_form_workflows() { $pipelines = $this->workflow->pipelines(); $cache = $this->workflow->cache_info(); $field_cache = $this->workflow->custom_field_cache_info(); $user_cache = $this->workflow->didar_user_cache_info(); $refresh = wp_nonce_url( admin_url( 'admin-post.php?action=didar_refresh_pipelines' ), 'didar_refresh_pipelines' ); $deal_count = count( array_filter( $this->workflow->custom_fields(), array( 'Didar_Custom_Field_Catalog', 'is_deal_field' ) ) ); echo '<p><a class="button button-secondary" href="' . esc_url( $refresh ) . '">بروزرسانی اطلاعات دیدار</a></p>'; echo '<p class="description">آخرین بروزرسانی اطلاعات دیدار: ' . esc_html( $cache['refreshed_at_gmt'] ?? $field_cache['refreshed_at_gmt'] ?? $user_cache['refreshed_at_gmt'] ?? '—' ) . ' | تعداد کاریزها: ' . absint( count( $pipelines ) ) . ' | تعداد کاستوم فیلدها: ' . absint( $deal_count ) . ' | تعداد کاربران دیدار: ' . absint( count( $this->workflow->didar_users() ) ) . ( ! empty( $cache['last_error'] ) || ! empty( $field_cache['last_error'] ) || ! empty( $user_cache['last_error'] ) ? ' | ⚠ ' . esc_html( $cache['last_error'] ?? $field_cache['last_error'] ?? $user_cache['last_error'] ) : '' ) . '</p>'; if ( ! $pipelines || ! $this->workflow->custom_fields() || ! $this->workflow->didar_users() ) { echo '<p class="notice notice-warning inline">ابتدا اطلاعات دیدار را بروزرسانی کنید. داده‌های قبلی در خطا حفظ می‌شوند.</p>'; }
		foreach ( $this->registry->all() as $form_type => $form ) {
			$workflow = $this->workflow->workflow( $form_type ); $statuses = $this->workflow->statuses( $form_type );
			$workflow_errors = $this->workflow->configuration_errors( $form_type );
			if ( $workflow_errors ) { echo '<p class="notice notice-error inline">' . esc_html__( 'گردش کار اختصاصی این فرم ناقص یا نامعتبر است؛ تا اصلاح آن از نگاشت قدیمی استفاده نمی‌شود.', 'didar' ) . ' <code>' . esc_html( implode( ', ', $workflow_errors ) ) . '</code></p>'; }
			if ( ! $statuses ) { $defaults = Didar_Reference_Data::statuses(); $statuses = array( $form['default_status'] => array( 'label' => isset( $defaults[ $form['default_status'] ] ) ? $defaults[ $form['default_status'] ] : $form['default_status'], 'stage_id' => '', 'is_default' => true, 'order' => 10 ) ); }
			echo '<fieldset class="didar-form-workflow" data-didar-workflow="' . esc_attr( $form_type ) . '" data-pipelines="' . esc_attr( wp_json_encode( $pipelines ) ) . '"><h3>' . esc_html( $form['label'] ) . ' <code>' . esc_html( $form_type ) . '</code></h3>';
			echo '<label>کاریز دیدار <select class="didar-workflow-pipeline" name="' . esc_attr( Didar_Settings::OPTION_NAME . '[didar_form_workflows][' . $form_type . '][pipeline_id]' ) . '"><option value="">— انتخاب کاریز —</option>';
			foreach ( $pipelines as $pipeline ) { echo '<option value="' . esc_attr( $pipeline['id'] ) . '" ' . selected( isset( $workflow['pipeline_id'] ) ? $workflow['pipeline_id'] : '', $pipeline['id'], false ) . '>' . esc_html( $pipeline['title'] ) . '</option>'; }
			$selected_pipeline_id = isset( $workflow['pipeline_id'] ) ? $workflow['pipeline_id'] : '';
			echo '</select></label><table class="widefat striped"><tbody class="didar-workflow-rows">'; $i = 0; foreach ( $statuses as $key => $status ) { $this->render_workflow_status_row( $form_type, $i++, $key, $status, $pipelines, $selected_pipeline_id ); } echo '</tbody></table><p><button type="button" class="button didar-add-workflow-status">افزودن وضعیت</button></p></fieldset>';
		}
	}
	private function render_workflow_status_row( $form_type, $index, $key, $status, $pipelines, $selected_pipeline_id ) { $base = Didar_Settings::OPTION_NAME . '[didar_form_workflows][' . $form_type . '][statuses][' . $index . ']'; $selected_stage_id = isset( $status['stage_id'] ) ? $status['stage_id'] : ''; $pipeline = $this->workflow->pipeline( $selected_pipeline_id ); $valid_stage_ids = $pipeline ? wp_list_pluck( $pipeline['stages'], 'id' ) : array(); $stale = $selected_stage_id && ! in_array( $selected_stage_id, $valid_stage_ids, true ); echo '<tr class="didar-workflow-row"><td><input type="text" name="' . esc_attr( $base . '[label]' ) . '" value="' . esc_attr( $status['label'] ?? '' ) . '" required></td><td><input type="text" class="regular-text" name="' . esc_attr( $base . '[key]' ) . '" value="' . esc_attr( $key ) . '" pattern="[a-z0-9_\-]+" required></td><td><input type="radio" name="' . esc_attr( Didar_Settings::OPTION_NAME . '[didar_form_workflows][' . $form_type . '][default]' ) . '" class="didar-workflow-default" ' . checked( ! empty( $status['is_default'] ), true, false ) . '></td><td><select class="didar-workflow-stage" name="' . esc_attr( $base . '[stage_id]' ) . '" ' . disabled( ! $selected_pipeline_id, true, false ) . '>'; if ( ! $selected_pipeline_id ) { echo '<option value="">ابتدا کاریز را انتخاب کنید</option>'; } else { echo '<option value="">— انتخاب مرحله کاریز دیدار —</option>'; foreach ( (array) ( $pipeline['stages'] ?? array() ) as $stage ) { echo '<option value="' . esc_attr( $stage['id'] ) . '" ' . selected( $selected_stage_id, $stage['id'], false ) . '>' . esc_html( $stage['title'] . ' (' . $pipeline['title'] . ')' ) . '</option>'; } if ( $stale ) { echo '<option value="' . esc_attr( $selected_stage_id ) . '" selected>⚠ مرحله ذخیره‌شده در کاریز فعلی وجود ندارد</option>'; } } echo '</select><input type="hidden" class="didar-workflow-default-value" name="' . esc_attr( $base . '[is_default]' ) . '" value="' . ( ! empty( $status['is_default'] ) ? '1' : '0' ) . '"><input type="hidden" name="' . esc_attr( $base . '[order]' ) . '" value="' . esc_attr( $status['order'] ?? ( $index + 1 ) * 10 ) . '"></td><td><button type="button" class="button-link-delete didar-remove-workflow-status">حذف وضعیت</button></td></tr>'; }
	public function refresh_pipelines() { if ( ! current_user_can( 'didar_manage_settings' ) || ! check_admin_referer( 'didar_refresh_pipelines' ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); } $result = $this->workflow->refresh(); $url = add_query_arg( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'page' => 'didar-page-settings', 'didar_pipeline_refresh' => is_wp_error( $result ) ? 'failed' : 'success' ), admin_url( 'edit.php' ) ); wp_safe_redirect( $url ); exit; }
	public function render_broker_map() { $s = $this->settings->all(); echo '<div class="didar-user-mapping-list">'; foreach ( $this->service->eligible_assignees() as $user ) { echo '<div class="didar-user-mapping-row"><strong>' . esc_html( $user->display_name ) . ' (#' . absint( $user->ID ) . ')</strong>'; $this->render_didar_user_select( Didar_Settings::OPTION_NAME . '[didar_broker_user_map][' . absint( $user->ID ) . ']', $s['didar_broker_user_map'][ $user->ID ] ?? '', $user->ID ); echo '</div>'; } echo '</div>'; }
	private function render_didar_user_select( $name, $selected, $wp_user_id = 0 ) { $selected = sanitize_text_field( (string) $selected ); $users = $this->workflow->didar_users(); $match = $this->workflow->didar_user_by_user_id( $selected ); echo '<select class="didar-user-select" name="' . esc_attr( $name ) . '"><option value="">— بدون نگاشت —</option>'; foreach ( $users as $user ) { if ( ! empty( $user['is_disabled'] ) ) { continue; } echo '<option value="' . esc_attr( $user['user_id'] ) . '" ' . selected( $selected, $user['user_id'], false ) . '>' . esc_html( $this->didar_user_label( $user ) ) . '</option>'; } if ( $selected && ( ! $match || ! empty( $match['is_disabled'] ) ) ) { $legacy = $this->workflow->didar_user_by_id( $selected ); $label = $legacy ? '⚠ ' . $this->didar_user_label( $legacy ) . ' — شناسه قدیمی Id' : ( $match ? '⚠ ' . $this->didar_user_label( $match ) . ' — غیرفعال' : '⚠ User دیدار پیدا نشد — ' . $selected ); echo '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $label ) . '</option>'; } echo '</select>'; $details = $match ?: $this->workflow->didar_user_by_id( $selected ); if ( $details ) { echo '<div class="didar-user-details">' . esc_html( $details['user_name'] ?: '—' ) . '<br><code>UserId: ' . esc_html( $details['user_id'] ) . '</code>' . ( empty( $details['invitation_accepted'] ) ? '<br>⚠ دعوت هنوز پذیرفته نشده' : '' ) . '</div>'; } }
	private function didar_user_label( $user ) { $identity = ! empty( $user['code'] ) ? '#' . $user['code'] : ( $user['user_name'] ?? '' ); return trim( ( $user['display_name'] ?? '' ) . ' (' . $identity . ')' . ( ! empty( $user['is_owner'] ) ? ' — مالک' : '' ) ); }
	private function normalize_didar_user_mapping( $candidate, $current, $wp_user_id, $setting ) { $candidate = sanitize_text_field( (string) $candidate ); $current = sanitize_text_field( (string) $current ); $users = $this->workflow->didar_users(); if ( ! $candidate || ! $users ) { return $candidate; } $user = $this->workflow->didar_user_by_user_id( $candidate ); if ( $user && empty( $user['is_disabled'] ) ) { return $candidate; } $legacy = $this->workflow->didar_user_by_id( $candidate ); if ( $legacy && empty( $legacy['is_disabled'] ) && $candidate === $current ) { $this->logger->log( 'INFO', 'didar_user_mapping_migrated', 'Legacy Didar Id mapping migrated to canonical UserId.', array( 'wp_user_id' => absint( $wp_user_id ), 'legacy_didar_id' => $candidate, 'didar_user_id' => $legacy['user_id'], 'code' => $legacy['code'] ?? '', 'setting' => $setting ) ); return $legacy['user_id']; } if ( $candidate === $current ) { $this->logger->log( 'WARNING', 'didar_user_mapping_stale', 'Existing Didar User mapping is stale or disabled and was preserved.', array( 'wp_user_id' => absint( $wp_user_id ), 'didar_user_id' => $candidate, 'setting' => $setting ) ); add_settings_error( Didar_Settings::OPTION_NAME, 'didar_stale_user_' . $setting . '_' . absint( $wp_user_id ), __( 'نگاشت ذخیره‌شده کاربر دیدار معتبر یا فعال نیست و بدون تغییر حفظ شد.', 'didar' ), 'warning' ); return $candidate; } $this->logger->log( 'WARNING', 'didar_user_mapping_invalid', 'Rejected invalid Didar UserId mapping.', array( 'wp_user_id' => absint( $wp_user_id ), 'didar_user_id' => $candidate, 'setting' => $setting ) ); add_settings_error( Didar_Settings::OPTION_NAME, 'didar_invalid_user_' . $setting . '_' . absint( $wp_user_id ), __( 'کاربر انتخاب‌شده دیدار معتبر یا فعال نیست و ذخیره نشد.', 'didar' ), 'error' ); return $current; }
	public function render_didar_mapping_description() { echo '<p>کلید داخلی فرم و فیلد مبنای نگاشت است، نه برچسب فارسی. مقدار خالی یعنی عدم همگام‌سازی. هر مقدار واردشده در فرم، از جمله نام، نام خانوادگی، موبایل و ایمیل، snapshot همان Deal است و در صورت نیاز باید به Deal Custom Field نگاشت شود. اطلاعات Person فقط از پروفایل WordPress کاربر و جریان ثبت‌نام می‌آید؛ نگاشت فرم نباید پروفایل Person را تغییر دهد.</p>'; }
	public function render_didar_field_mappings() { $this->render_didar_field_mappings_selector(); }
	public function render_didar_field_mappings_selector() { $s = $this->settings->all(); $defaults = new Didar_Field_Mapper( $this->registry, $this->settings ); echo '<script type="application/json" id="didar-custom-field-catalog">' . wp_json_encode( array( 'fields' => $this->workflow->custom_fields(), 'pipelines' => $this->workflow->pipelines() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script><p>فیلد دیدار از کاستوم‌فیلدهای معامله و بر اساس کاریز انتخاب‌شده برای همین فرم تعیین می‌شود.</p>'; foreach ( $this->registry->all() as $form_type => $form ) { $pipeline_id = $this->workflow->workflow( $form_type )['pipeline_id'] ?? ''; echo '<div class="didar-field-mapping-form" data-didar-field-mapping="' . esc_attr( $form_type ) . '"><h3>' . esc_html( $form['label'] ) . '</h3><table class="widefat striped"><tbody>'; foreach ( $this->registry->fields( $form_type ) as $key => $field ) { if ( ! empty( $field['internal'] ) || 'honeypot' === $field['type'] ) { continue; } $map = $s['didar_field_mappings'][ $form_type ][ $key ] ?? $defaults->mapping( $form_type, $key ); $map = $this->normalize_mapping_for_display( $map ); $base = Didar_Settings::OPTION_NAME . '[didar_field_mappings][' . $form_type . '][' . $key . ']'; echo '<tr><td><strong>' . esc_html( $field['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code></td><td><input type="hidden" name="' . esc_attr( $base . '[target]' ) . '" value="deal_custom">'; $this->render_custom_field_select( $base . '[field]', $map['field'], $pipeline_id ? array( $pipeline_id ) : array() ); echo '</td></tr>'; } echo '</tbody></table></div>'; } }
	private function normalize_mapping_for_display( $map ) { if ( is_scalar( $map ) ) { return array( 'target' => 'deal_custom', 'field' => sanitize_text_field( (string) $map ) ); } if ( ! is_array( $map ) ) { return array( 'target' => '', 'field' => '' ); } return array( 'target' => sanitize_key( (string) ( $map['target'] ?? ( $map['type'] ?? 'deal_custom' ) ) ), 'field' => sanitize_text_field( (string) ( $map['field'] ?? ( $map['key'] ?? '' ) ) ) ); }
	private function configured_pipeline_ids() { $ids = array(); foreach ( $this->registry->all() as $form_type => $form ) { $id = $this->workflow->workflow( $form_type )['pipeline_id'] ?? ''; if ( $id ) { $ids[] = $id; } } return array_values( array_unique( $ids ) ); }
	private function render_custom_field_select( $name, $selected, $pipeline_ids, $system = false ) { $selected = sanitize_text_field( (string) $selected ); $pipeline_ids = array_values( array_filter( (array) $pipeline_ids ) ); $fields = $this->workflow->custom_fields(); $allowed = array(); foreach ( $fields as $field ) { $valid = Didar_Custom_Field_Catalog::is_deal_field( $field ); foreach ( $pipeline_ids as $pipeline_id ) { $valid = $valid && $this->workflow->custom_field_available_for_pipeline( $field, $pipeline_id ); } if ( $valid ) { $allowed[ $field['key'] ] = $field; } } $disabled = ! $system && ! $pipeline_ids; echo '<select class="didar-custom-field" name="' . esc_attr( $name ) . '" data-selected="' . esc_attr( $selected ) . '" ' . disabled( $disabled, true, false ) . '>'; echo $disabled ? '<option value="">ابتدا کاریز دیدار این فرم را انتخاب کنید</option>' : '<option value="">— بدون نگاشت —</option>'; foreach ( $allowed as $key => $field ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected, $key, false ) . '>' . esc_html( $this->custom_field_label( $field, $fields ) ) . '</option>'; } if ( $selected && ! isset( $allowed[ $selected ] ) ) { $field = $this->workflow->custom_field( $selected ); $message = ! $field ? '⚠ ' . $selected . ' — در اطلاعات فعلی دیدار یافت نشد' : ( ! empty( $field['is_deleted'] ) ? '⚠ ' . ( $field['title'] ?: $selected ) . ' — فیلد در دیدار حذف شده است' : '⚠ ' . ( $field['title'] ?: $selected ) . ' — در کاریز فعلی در دسترس نیست' ); echo '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $message ) . '</option>'; } echo '</select>'; if ( $disabled && $selected ) { echo '<input type="hidden" class="didar-disabled-custom-field" name="' . esc_attr( $name ) . '" value="' . esc_attr( $selected ) . '">'; } }
	private function custom_field_label( $field, $all_fields ) { $available = $this->workflow->custom_field_available_pipeline_ids( $field ); $pipelines = $this->workflow->pipelines(); $titles = array(); foreach ( $pipelines as $pipeline ) { if ( in_array( $pipeline['id'], $available, true ) ) { $titles[] = $pipeline['title']; } } $count = count( $available ); $scope = count( $pipelines ) && $count === count( $pipelines ) ? 'همه کاریزها' : ( $count <= 2 ? implode( '، ', $titles ) : $count . ' کاریز' ); $duplicates = 0; foreach ( $all_fields as $item ) { $duplicates += ( $item['title'] ?? '' ) === ( $field['title'] ?? '' ) ? 1 : 0; } return trim( $field['title'] . ( ! empty( $field['control_type'] ) ? ' — ' . $field['control_type'] : '' ) . ' (' . $scope . ')' . ( $duplicates > 1 ? ' — ' . $field['key'] : '' ) ); }

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
		echo '</form>';
		$this->render_settings_transfer_page();
		echo '</div>';
	}

	/** Admin coordinator only; parsing, validation and writes live in Didar_Settings_Transfer. */
	public function render_settings_transfer_page() {
		if ( isset( $_GET['didar_transfer_message'] ) && ! is_array( $_GET['didar_transfer_message'] ) ) { $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['didar_transfer_message'] ) ) ); $class = isset( $_GET['didar_transfer'] ) && 'error' === $_GET['didar_transfer'] ? 'notice-error' : 'notice-success'; echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>'; }
		$summary = $this->settings_transfer->summary( $this->settings_transfer->portable_settings() );
		echo '<hr><section class="didar-settings-transfer"><h2>درون‌ریزی / برون‌ریزی تنظیمات</h2><h3>برون‌ریزی تنظیمات</h3><p>گردش کار فرم‌ها: ' . absint( $summary['workflows'] ) . ' | نگاشت فیلدها: ' . absint( $summary['field_mappings'] ) . ' | نگاشت کاربران: ' . absint( $summary['user_mappings'] ) . '</p><p class="description">کلید API و اطلاعات محرمانه در فایل خروجی قرار نمی‌گیرند.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="didar_settings_export">' . wp_nonce_field( 'didar_settings_export', '_wpnonce', true, false ) . '<button class="button button-secondary">دانلود فایل تنظیمات</button></form>';
		echo '<h3>درون‌ریزی تنظیمات</h3><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="didar_settings_import_preview">' . wp_nonce_field( 'didar_settings_import_preview', '_wpnonce', true, false ) . '<p><input type="file" name="didar_settings_file" accept="application/json,.json" required></p><p><label><input type="radio" name="didar_import_mode" value="merge" checked> ادغام با تنظیمات فعلی</label><br><label><input type="radio" name="didar_import_mode" value="replace"> جایگزینی تنظیمات قابل انتقال</label></p><button class="button">بررسی فایل</button></form>';
		$preview = get_transient( Didar_Settings_Transfer::PREVIEW_PREFIX . get_current_user_id() );
		if ( is_array( $preview ) ) { $this->render_settings_import_preview( $preview ); }
		echo '</section>';
	}

	private function render_settings_import_preview( $preview ) {
		$trace = isset( $preview['trace'] ) && is_array( $preview['trace'] ) ? $preview['trace'] : array();
		if ( $trace ) {
			echo '<p><strong>نگاشت‌های موجود در فایل / قابل اعمال</strong></p><table class="widefat striped"><thead><tr><th>مرحله</th><th>کل</th><th>Deal Custom</th><th>Person Native</th><th>فیلد سیستمی</th><th>نگاشت کاربر</th></tr></thead><tbody>';
			foreach ( $trace as $stage => $counts ) { if ( ! is_array( $counts ) ) { continue; } echo '<tr><td>' . esc_html( $stage ) . ( ! empty( $counts['status'] ) ? ' (' . esc_html( $counts['status'] ) . ')' : '' ) . '</td><td>' . absint( $counts['total'] ?? 0 ) . '</td><td>' . absint( $counts['deal_custom'] ?? 0 ) . '</td><td>' . absint( $counts['person_native'] ?? 0 ) . '</td><td>' . absint( $counts['system_fields'] ?? 0 ) . '</td><td>' . absint( $counts['user_mappings'] ?? 0 ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '<h3>پیش‌نمایش درون‌ریزی</h3><p>خطا: <strong>' . absint( count( $preview['errors'] ) ) . '</strong> | هشدار: <strong>' . absint( count( $preview['warnings'] ) ) . '</strong> | بررسی‌نشده: <strong>' . absint( count( $preview['not_verified'] ?? array() ) ) . '</strong></p><table class="widefat striped"><thead><tr><th>دسته</th><th>اضافه می‌شود</th><th>تغییر می‌کند</th><th>بدون تغییر</th><th>نامعتبر</th></tr></thead><tbody>';
		foreach ( $preview['diff'] as $name => $counts ) { echo '<tr><td>' . esc_html( $name ) . '</td><td>' . absint( $counts['added'] ) . '</td><td>' . absint( $counts['changed'] ) . '</td><td>' . absint( $counts['unchanged'] ) . '</td><td>' . absint( $counts['invalid'] ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( ! empty( $preview['warnings'] ) ) { echo '<div class="notice notice-warning inline"><p>' . implode( '<br>', array_map( 'esc_html', $preview['warnings'] ) ) . '</p></div>'; }
		if ( ! empty( $preview['not_verified'] ) ) { echo '<div class="notice notice-info inline"><p><strong>بررسی‌نشده: ' . absint( count( $preview['not_verified'] ) ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $preview['not_verified'] ) ) . '<br><a class="button-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=didar_refresh_pipelines' ), 'didar_refresh_pipelines' ) ) . '">بروزرسانی اطلاعات دیدار و بررسی مجدد</a></p></div>'; }
		if ( ! empty( $preview['errors'] ) ) { echo '<div class="notice notice-error inline"><p>' . implode( '<br>', array_map( 'esc_html', $preview['errors'] ) ) . '</p></div>'; return; }
		foreach ( (array) ( $preview['incoming']['didar_form_workflows'] ?? array() ) as $type => $workflow ) { $old = $this->settings->all()['didar_form_workflows'][ $type ]['pipeline_id'] ?? ''; if ( $old && ! empty( $workflow['pipeline_id'] ) && (string) $old !== (string) $workflow['pipeline_id'] ) { echo '<div class="notice notice-warning inline"><p><strong>تغییر کاریز یک فرم باعث انتقال خودکار درخواست‌های قبلی در دیدار نمی‌شود.</strong></p></div>'; break; } }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="didar_settings_import_apply">' . wp_nonce_field( 'didar_settings_import_apply', '_wpnonce', true, false ) . '<button class="button button-primary">اعمال تنظیمات</button></form>';
	}

	public function settings_export() { $this->assert_transfer_request( 'didar_settings_export' ); $json = $this->settings_transfer->export_json(); nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ns-didar-settings-' . gmdate( 'Y-m-d' ) . '.json"' ); echo $json; exit; }
	public function settings_import_preview() { $this->assert_transfer_request( 'didar_settings_import_preview' ); if ( empty( $_FILES['didar_settings_file'] ) || ! is_array( $_FILES['didar_settings_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['didar_settings_file']['error'] ) { $this->transfer_redirect( 'error', 'فایل JSON معتبر انتخاب نشده است.' ); }
		$file = $_FILES['didar_settings_file']; $name = sanitize_file_name( $file['name'] ?? '' ); if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'json' || (int) $file['size'] > Didar_Settings_Transfer::MAX_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) { $this->transfer_redirect( 'error', 'فایل تنظیمات معتبر نیست یا حجم آن بیش از حد مجاز است.' ); }
		$json = file_get_contents( $file['tmp_name'] ); @unlink( $file['tmp_name'] ); $parsed = $this->settings_transfer->parse_json( $json ); if ( is_wp_error( $parsed ) ) { $this->transfer_redirect( 'error', $parsed->get_error_message() ); }
		$preview = $this->settings_transfer->preview( $parsed, isset( $_POST['didar_import_mode'] ) && 'replace' === $_POST['didar_import_mode'] ? 'replace' : 'merge' ); if ( is_wp_error( $preview ) ) { $this->transfer_redirect( 'error', $preview->get_error_message() ); } set_transient( Didar_Settings_Transfer::PREVIEW_PREFIX . get_current_user_id(), $preview, 15 * MINUTE_IN_SECONDS ); $this->transfer_redirect( 'preview', 'فایل تنظیمات بررسی شد.' ); }
	public function settings_import_apply() { $this->assert_transfer_request( 'didar_settings_import_apply' ); $key = Didar_Settings_Transfer::PREVIEW_PREFIX . get_current_user_id(); $preview = get_transient( $key ); if ( ! is_array( $preview ) ) { $this->transfer_redirect( 'error', 'پیش‌نمایش منقضی شده است؛ فایل را دوباره بررسی کنید.' ); } $result = $this->settings_transfer->apply( $preview ); if ( is_wp_error( $result ) ) { $this->logger->log( 'ERROR', 'didar_settings_import_failed', 'Settings import failed.', array( 'actor_wp_user_id' => get_current_user_id(), 'error_code' => $result->get_error_code() ) ); $this->transfer_redirect( 'error', $result->get_error_message() ); } delete_transient( $key ); $s = $result['summary']; $this->transfer_redirect( 'success', 'درون‌ریزی تنظیمات با موفقیت انجام شد. گردش کار فرم‌ها: ' . $s['workflows'] . ' | نگاشت فیلدها: ' . $s['field_mappings'] . ' | نگاشت کاربران: ' . $s['user_mappings'] . ' | هشدارها: ' . count( $result['warnings'] ) ); }
	private function assert_transfer_request( $nonce ) { if ( ! current_user_can( 'didar_manage_settings' ) || ! check_admin_referer( $nonce ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); } }
	private function transfer_redirect( $state, $message ) { $url = add_query_arg( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'page' => 'didar-page-settings', 'didar_transfer' => $state, 'didar_transfer_message' => rawurlencode( $message ) ), admin_url( 'edit.php' ) ); wp_safe_redirect( $url ); exit; }

	public function add_meta_boxes( $post ) {
		remove_meta_box( 'slugdiv', Didar_Post_Type::POST_TYPE, 'normal' );
		add_meta_box( 'didar-form-type', __( 'نوع فرم', 'didar' ), array( $this, 'render_form_type_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'didar-fields', __( 'اطلاعات درخواست / فرم', 'didar' ), array( $this, 'render_fields_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-notes', __( 'یادداشت متقاضی', 'didar' ), array( $this, 'render_notes_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-customer-workflow', __( 'اطلاعات نمایشی برای مشتری', 'didar' ), array( $this, 'render_customer_workflow_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-internal-workflow', __( 'گردش کار داخلی', 'didar' ), array( $this, 'render_internal_workflow_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-activity', __( 'تاریخچه درخواست', 'didar' ), array( $this, 'render_activity_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'didar-details', __( 'مالکیت', 'didar' ), array( $this, 'render_details_box' ), Didar_Post_Type::POST_TYPE, 'side', 'high' );
		add_meta_box( 'didar-sync-status', __( 'وضعیت همگام‌سازی دیدار', 'didar' ), array( $this, 'render_sync_status' ), Didar_Post_Type::POST_TYPE, 'side', 'default' );
	}

	public function render_sync_status( $post ) { $state = get_post_meta( $post->ID, '_didar_sync_state', true ); $state = is_array( $state ) ? $state : array(); $trace = $state['trace_id'] ?? ''; $url = wp_nonce_url( admin_url( 'admin-post.php?action=didar_manual_sync&post_id=' . absint( $post->ID ) ), 'didar_manual_sync_' . $post->ID ); echo '<p>Person ID: <code>' . esc_html( get_post_meta( $post->ID, Didar_Sync_Manager::META_PERSON_ID, true ) ?: '—' ) . '</code><br>Deal ID: <code>' . esc_html( get_post_meta( $post->ID, '_didar_deal_id', true ) ?: '—' ) . '</code><br>وضعیت: ' . esc_html( $state['status'] ?? 'new' ) . '<br>آخرین تلاش: ' . esc_html( ! empty( $state['last_attempt_at'] ) ? Didar_Logger::display_timestamp( $state['last_attempt_at'] ) : '—' ) . '<br>آخرین موفقیت: ' . esc_html( ! empty( $state['last_synced_at'] ) ? Didar_Logger::display_timestamp( $state['last_synced_at'] ) : '—' ) . '<br>خطای اخیر: ' . esc_html( $state['last_error'] ?? '—' ) . '<br>تلاش‌ها: ' . esc_html( $state['attempts'] ?? 0 ) . '<br>Trace: <code>' . esc_html( $trace ?: '—' ) . '</code></p><a class="button" href="' . esc_url( $url ) . '">همگام‌سازی با دیدار اکنون</a>'; }

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
		$form_type = sanitize_key( (string) get_post_meta( $post->ID, '_didar_form_type', true ) );
		if ( current_user_can( 'didar_change_internal_status' ) && $form_type ) {
			echo '<label class="didar-label" for="didar-internal-status">' . esc_html__( 'وضعیت داخلی', 'didar' ) . '</label>';
			$this->render_status_select( 'didar-internal-status', 'didar_internal_status', $status, $form_type );
		} elseif ( ! $form_type ) {
			echo '<span class="description">' . esc_html__( 'پس از انتخاب و ذخیره نوع فرم، وضعیت پیش‌فرض گردش کار آن اعمال می‌شود.', 'didar' ) . '</span>';
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

	private function render_status_select( $id, $name, $selected_status, $form_type = '' ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		$statuses = 'didar_internal_status' === $name && $form_type ? $this->workflow->statuses( $form_type ) : Didar_Reference_Data::statuses();
		foreach ( $statuses as $key => $label ) {
			$label = is_array( $label ) ? ( $label['label'] ?? $key ) : $label;
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
		$stored_type = (string) get_post_meta( $post->ID, '_didar_form_type', true );
		$owner_id    = $stored_type ? (int) $post->post_author : 0;
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
			echo '<p><label for="didar-owner"><strong>' . esc_html__( 'مالک / مشتری درخواست', 'didar' ) . '</strong></label><select id="didar-owner" name="didar_owner"><option value="0">' . esc_html__( '— انتخاب مشتری —', 'didar' ) . '</option>';
			foreach ( $users as $user ) {
				echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $owner_id, $user->ID, false ) . '>' . esc_html( $user->display_name . ' — ' . $user->user_email ) . '</option>';
			}
			echo '</select></p><p class="description">' . esc_html__( 'درخواست جدید باید به مشتری/کاربر WordPress واقعی متصل شود؛ حساب Administrator یا Broker فقط به‌عنوان ایجادکننده استفاده نمی‌شود.', 'didar' ) . '</p>';
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
		$trace_id    = Didar_Logger::trace_id( '' );
		$this->logger->log( 'INFO', 'didar_admin_submission_save_started', 'Admin submission save started.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'actor_user_id' => get_current_user_id(), 'intended_customer_user_id' => isset( $_POST['didar_owner'] ) && ! is_array( $_POST['didar_owner'] ) ? absint( wp_unslash( $_POST['didar_owner'] ) ) : 0, 'posted_form_type' => $posted_type, 'stored_form_type' => $stored_type, 'operation' => $update ? 'update' : 'create', 'trace_id' => $trace_id, 'source' => 'wp_admin' ) );
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
				if ( isset( $this->workflow->statuses( $form_type )[ $internal_status ] ) ) {
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
			$this->logger->log( 'ERROR', 'didar_admin_submission_save_failed', 'Admin submission save stopped: Form Type is missing or invalid.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'actor_user_id' => get_current_user_id(), 'posted_form_type' => $posted_type, 'stored_form_type' => $stored_type, 'trace_id' => $trace_id, 'reason' => 'invalid_form_type', 'source' => 'wp_admin' ) );
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => __( 'نوع فرم نامعتبر است.', 'didar' ) ), $shared_note );
			if ( ! $stored_type ) {
				$this->force_draft( $post_id, $post );
			}
			return;
		}
		$this->logger->log( 'INFO', 'didar_admin_submission_form_type_validated', 'Admin Form Type validated against the Form Registry.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'trace_id' => $trace_id, 'source' => 'wp_admin' ) );
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
		$owner_id = $stored_type ? (int) $post->post_author : 0;
		if ( current_user_can( 'didar_change_request_owner' ) && isset( $_POST['didar_owner'] ) && ! is_array( $_POST['didar_owner'] ) ) {
			$requested_owner = absint( wp_unslash( $_POST['didar_owner'] ) );
			if ( get_user_by( 'id', $requested_owner ) ) {
				$owner_id = $requested_owner;
			}
		}
		if ( ! $owner_id ) {
			$this->logger->log( 'ERROR', 'didar_admin_submission_save_failed', 'Admin submission save stopped: customer/owner is missing.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'actor_user_id' => get_current_user_id(), 'trace_id' => $trace_id, 'reason' => 'missing_owner', 'source' => 'wp_admin' ) );
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_owner' => __( 'برای ایجاد درخواست، مشتری / مالک WordPress را انتخاب کنید.', 'didar' ) ), $shared_note );
			if ( ! $stored_type ) {
				$this->force_draft( $post_id, $post );
			}
			return;
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
			$this->logger->log( 'ERROR', 'didar_admin_submission_save_failed', 'Admin submission service save failed.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'trace_id' => $trace_id, 'error_code' => $result->get_error_code(), 'source' => 'wp_admin' ) );
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => $result->get_error_message() ), $shared_note );
		} else {
			$this->logger->log( 'INFO', 'didar_admin_submission_saved', 'Admin submission reached canonical local persistence.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'canonical_form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'customer_user_id' => get_post_field( 'post_author', $post_id ), 'internal_status' => get_post_meta( $post_id, '_didar_internal_status', true ), 'trace_id' => $trace_id, 'source' => 'wp_admin' ) );
			$this->logger->log( 'INFO', 'didar_admin_submission_sync_started', 'Admin submission invoked the centralized Didar sync hook.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'trace_id' => $trace_id, 'source' => 'wp_admin' ) );
			$sync_result = Didar_Plugin::instance()->sync_manager->sync_after_admin_save( $post_id );
			if ( is_wp_error( $sync_result ) ) {
				$this->logger->log( 'ERROR', 'didar_admin_submission_sync_failed', 'Admin submission reached canonical persistence but centralized Didar sync failed.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => $form_type, 'trace_id' => $trace_id, 'error_code' => $sync_result->get_error_code(), 'source' => 'wp_admin' ) );
			}
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
		if ( current_user_can( 'didar_manage_settings' ) && isset( $_GET['didar_sync'] ) && ! is_array( $_GET['didar_sync'] ) ) { $ok = 'completed' === sanitize_key( wp_unslash( $_GET['didar_sync'] ) ); echo '<div class="notice ' . esc_attr( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $ok ? 'همگام‌سازی دستی اجرا شد؛ نتیجه و Trace ID را در کادر وضعیت و گزارش تشخیصی ببینید.' : 'همگام‌سازی دستی با خطا یا توقف مواجه شد؛ گزارش تشخیصی را بررسی کنید.' ) . '</p></div>'; }
		if ( current_user_can( 'didar_manage_settings' ) && isset( $_GET['didar_connection'] ) && ! is_array( $_GET['didar_connection'] ) ) {
			$connection = sanitize_key( wp_unslash( $_GET['didar_connection'] ) );
			echo '<div class="notice ' . esc_attr( 'success' === $connection ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( 'success' === $connection ? 'اتصال دیدار با موفقیت آزمایش شد.' : 'آزمون اتصال دیدار ناموفق بود.' ) . '</p></div>';
		}
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
		if ( $is_settings ) { wp_enqueue_script( 'didar-admin', DIDAR_URL . 'assets/js/admin.js', array(), DIDAR_VERSION, true ); return; }
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
			$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
			echo esc_html( $this->workflow->status_label( $form_type, $this->service->get_internal_status( $post_id ) ) );
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

	public function manual_sync() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! current_user_can( 'didar_manage_settings' ) || ! $post_id || ! check_admin_referer( 'didar_manual_sync_' . $post_id ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); }
		$result = Didar_Plugin::instance()->sync_manager->manual_sync( $post_id );
		$url = add_query_arg( array( 'post' => $post_id, 'action' => 'edit', 'didar_sync' => is_wp_error( $result ) ? 'failed' : 'completed' ), admin_url( 'post.php' ) ); wp_safe_redirect( $url ); exit;
	}

	public function clear_logs() {
		if ( ! current_user_can( 'didar_manage_settings' ) || ! check_admin_referer( 'didar_clear_logs' ) ) { wp_die( esc_html__( 'درخواست نامعتبر است.', 'didar' ), '', array( 'response' => 403 ) ); }
		$this->logger->clear(); wp_safe_redirect( add_query_arg( 'didar_logs', 'cleared', admin_url( 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE . '&page=didar-diagnostics' ) ) ); exit;
	}

	public function render_diagnostics_page() {
		if ( ! current_user_can( 'didar_manage_settings' ) ) { wp_die( esc_html__( 'دسترسی کافی نیست.', 'didar' ) ); }
		$filters = array(); foreach ( array( 'level', 'form_type', 'operation', 'local_id', 'trace_id' ) as $key ) { $filters[ $key ] = isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; }
		$rows = $this->logger->recent( $filters, 100 ); $clear = wp_nonce_url( admin_url( 'admin-post.php?action=didar_clear_logs' ), 'didar_clear_logs' ); $next = wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK ); $pending = new WP_Query( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false, 'meta_key' => '_didar_sync_state', 'meta_value' => 'pending', 'meta_compare' => 'LIKE' ) );
		echo '<div class="wrap" dir="rtl"><h1>' . esc_html__( 'تشخیص دیدار', 'didar' ) . '</h1><p>لاگ‌ها در جدول اختصاصی WordPress ذخیره می‌شوند و حداکثر ۵۰۰۰ رویداد نگه‌داری می‌شود. <a class="button" href="' . esc_url( $clear ) . '" onclick="return confirm(\'گزارش‌های دیدار پاک شود؟\');">پاک کردن گزارش‌ها</a></p><p><strong>WP-Cron دیدار:</strong> ' . esc_html( $next ? 'زمان‌بندی شده؛ ' . Didar_Logger::display_timestamp( $next ) : 'زمان‌بندی نشده' ) . ' — آیتم‌های pending تقریبی: ' . esc_html( $pending->found_posts ) . '</p><form method="get"><input type="hidden" name="post_type" value="' . esc_attr( Didar_Post_Type::POST_TYPE ) . '"><input type="hidden" name="page" value="didar-diagnostics"><input name="level" placeholder="Level" value="' . esc_attr( $filters['level'] ) . '"><input name="operation" placeholder="Operation" value="' . esc_attr( $filters['operation'] ) . '"><input name="form_type" placeholder="Form type" value="' . esc_attr( $filters['form_type'] ) . '"><input name="local_id" placeholder="Local ID" value="' . esc_attr( $filters['local_id'] ) . '"><input name="trace_id" placeholder="Trace ID" value="' . esc_attr( $filters['trace_id'] ) . '"> <button class="button">فیلتر</button></form>';
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Time</th><th>Level</th><th>Operation</th><th>Direction</th><th>Form</th><th>Local</th><th>External</th><th>Message</th><th>Trace</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { $details = isset( $row['context'] ) ? wp_json_encode( json_decode( $row['context'], true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : ''; echo '<tr><td>' . esc_html( Didar_Logger::display_time( $row['created_at_gmt'] ) ) . '</td><td>' . esc_html( $row['level'] ) . '</td><td>' . esc_html( $row['operation'] ) . '</td><td>' . esc_html( $row['direction'] ) . '</td><td>' . esc_html( $row['form_type'] ) . '</td><td>' . esc_html( $row['local_id'] ) . '</td><td>' . esc_html( $row['external_id'] ) . '</td><td>' . esc_html( $row['message'] ) . '<details><summary>جزئیات</summary><code>' . esc_html( substr( (string) $details, 0, 1200 ) ) . '</code></details></td><td><code>' . esc_html( $row['trace_id'] ) . '</code></td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="9">گزارشی ثبت نشده است.</td></tr>'; } echo '</tbody></table></div>';
	}
}
