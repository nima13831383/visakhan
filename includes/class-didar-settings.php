<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central access to Didar behavior settings and field requirement overrides.
 */
class Didar_Settings {
	const OPTION_NAME              = 'didar_settings';
	const DEFAULT_REQUESTS_PER_PAGE = 10;
	const MIN_REQUESTS_PER_PAGE     = 1;
	const MAX_REQUESTS_PER_PAGE     = 100;
	const DEFAULT_FILE_DOWNLOAD_MODE = 'secure';
	const DEFAULT_PDF_PRINT_WITH_FILES_TEXT = 'چاپ همراه فایل‌ها';
	const DEFAULT_PDF_PRINT_WITHOUT_FILES_TEXT = 'چاپ بدون فایل‌ها';
	const PROFILE_FIELD_STATES = array(
		'first_name'   => 'editable',
		'last_name'    => 'editable',
		'gender'       => 'editable',
		'display_name' => 'editable',
		'mobile'       => 'readonly',
		'email'        => 'editable',
		'profile_image'=> 'disabled',
		'birth_date'   => 'editable',
		'national_id'  => 'editable',
	);

	/** Return a cryptographically random path credential for the inbound webhook. */
	public static function generate_webhook_secret() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			return '';
		}
	}

	public function ensure_webhook_secret() {
		$settings = $this->all();
		$secret   = isset( $settings['didar_webhook_secret'] ) && is_string( $settings['didar_webhook_secret'] ) ? trim( $settings['didar_webhook_secret'] ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			return $secret;
		}
		$secret = self::generate_webhook_secret();
		if ( $secret ) {
			$settings['didar_webhook_secret'] = $secret;
			update_option( self::OPTION_NAME, $settings, false );
		}
		return $secret;
	}

	public function webhook_url() {
		$secret = $this->ensure_webhook_secret();
		return $secret ? rest_url( 'didar/v1/webhook/' . rawurlencode( $secret ) ) : '';
	}

	public function all() {
		$settings = get_option( self::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? $settings : array();
		// Remove the retired webhookId allowlist from persisted settings.
		if ( array_key_exists( 'didar_webhook_bindings', $settings ) ) {
			unset( $settings['didar_webhook_bindings'] );
			update_option( self::OPTION_NAME, $settings, false );
		}
		return $settings;
	}

	public function colleague_can_view_internal_history() {
		$settings = $this->all();
		return ! empty( $settings['colleague_can_view_internal_history'] );
	}

	public function frontend_requests_per_page() {
		$settings = $this->all();
		$value    = isset( $settings['frontend_requests_per_page'] ) ? absint( $settings['frontend_requests_per_page'] ) : self::DEFAULT_REQUESTS_PER_PAGE;

		return min( self::MAX_REQUESTS_PER_PAGE, max( self::MIN_REQUESTS_PER_PAGE, $value ) );
	}

	public function file_download_mode() {
		$settings = $this->all();
		$mode     = isset( $settings['file_download_mode'] ) && is_scalar( $settings['file_download_mode'] ) ? sanitize_key( (string) $settings['file_download_mode'] ) : '';
		return in_array( $mode, array( 'secure', 'direct' ), true ) ? $mode : self::DEFAULT_FILE_DOWNLOAD_MODE;
	}

	public static function normalize_pdf_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$with_files = isset( $settings['print_with_files_text'] ) && is_scalar( $settings['print_with_files_text'] ) ? sanitize_text_field( (string) $settings['print_with_files_text'] ) : '';
		$without_files = isset( $settings['print_without_files_text'] ) && is_scalar( $settings['print_without_files_text'] ) ? sanitize_text_field( (string) $settings['print_without_files_text'] ) : '';
		return array(
			'print_with_files_text'    => '' !== trim( $with_files ) ? $with_files : self::DEFAULT_PDF_PRINT_WITH_FILES_TEXT,
			'print_without_files_text' => '' !== trim( $without_files ) ? $without_files : self::DEFAULT_PDF_PRINT_WITHOUT_FILES_TEXT,
		);
	}

	public function pdf_settings() {
		$settings = $this->all();
		return self::normalize_pdf_settings( $settings['pdf_settings'] ?? array() );
	}

	public function pdf_print_with_files_text() {
		$pdf = $this->pdf_settings();
		return $pdf['print_with_files_text'];
	}

	public function pdf_print_without_files_text() {
		$pdf = $this->pdf_settings();
		return $pdf['print_without_files_text'];
	}

	public function form_access( $form_type ) {
		return Didar_Form_Access::get( $this->all(), $form_type );
	}

	/** Frontend profile-field policy. Mobile is always effectively readonly until a verified Digits change-number flow is integrated. */
	public function profile_field_state( $field ) {
		$field    = sanitize_key( (string) $field );
		$settings = $this->all();
		$states   = isset( $settings['profile_field_states'] ) && is_array( $settings['profile_field_states'] ) ? $settings['profile_field_states'] : array();
		$state    = isset( $states[ $field ] ) ? sanitize_key( (string) $states[ $field ] ) : ( self::PROFILE_FIELD_STATES[ $field ] ?? 'disabled' );
		$state    = in_array( $state, array( 'editable', 'readonly', 'disabled' ), true ) ? $state : ( self::PROFILE_FIELD_STATES[ $field ] ?? 'disabled' );
		return 'mobile' === $field && 'editable' === $state ? 'readonly' : $state;
	}

	/**
	 * Resolve a field's effective required state. Missing overrides preserve the registry default.
	 */
	public function is_required( $form_type, $field_key, $registry_default = false ) {
		$form_type = sanitize_key( (string) $form_type );
		$field_key = sanitize_key( (string) $field_key );
		$settings  = $this->all();
		$overrides = isset( $settings['field_required_overrides'] ) && is_array( $settings['field_required_overrides'] ) ? $settings['field_required_overrides'] : array();

		if ( isset( $overrides[ $form_type ] ) && is_array( $overrides[ $form_type ] ) && array_key_exists( $field_key, $overrides[ $form_type ] ) ) {
			return (bool) $overrides[ $form_type ][ $field_key ];
		}

		return (bool) $registry_default;
	}

	public function profile_default_source( $form_type, $field_key ) {
		$settings = $this->all();
		$value = $settings['didar_form_field_defaults'][ sanitize_key( $form_type ) ][ sanitize_key( $field_key ) ] ?? '';
		return in_array( $value, ( new Didar_User_Profile_Value_Catalog() )->keys(), true ) ? $value : '';
	}

	/** Resolve a literal choice default when a field explicitly exposes its own options as defaults. */
	public function field_default_value( $form_type, $field_key, $registry_default = '', $options = array() ) {
		$settings = $this->all();
		$value    = $settings['didar_form_field_defaults'][ sanitize_key( $form_type ) ][ sanitize_key( $field_key ) ] ?? '';
		$value    = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return $value && is_array( $options ) && array_key_exists( $value, $options ) ? $value : $registry_default;
	}

	/** Resolve the UI-only placeholder without changing the submitted/default value. */
	public function field_placeholder( $form_type, $field_key, $registry_default = '' ) {
		$form_type = sanitize_key( (string) $form_type );
		$field_key = sanitize_key( (string) $field_key );
		$settings  = $this->all();
		$override  = $settings['didar_form_field_placeholders'][ $form_type ][ $field_key ] ?? '';
		$override  = is_scalar( $override ) ? sanitize_text_field( (string) $override ) : '';
		return '' !== $override ? $override : sanitize_text_field( (string) $registry_default );
	}
}
