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

	public function all() {
		$settings = get_option( self::OPTION_NAME, array() );
		return is_array( $settings ) ? $settings : array();
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
}
