<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates installation, upgrades, and runtime health checks for Didar tables.
 */
class Didar_Schema_Manager {
	const SCHEMA_VERSION      = '1.0.0';
	const STATE_OPTION        = 'didar_schema_state';
	const ERROR_OPTION        = 'didar_schema_last_error';
	const FULL_CHECK_INTERVAL = DAY_IN_SECONDS;
	const RETRY_INTERVAL      = 5 * MINUTE_IN_SECONDS;

	private static $repairing = false;

	public static function maybe_repair() {
		if ( self::$repairing ) {
			return true;
		}

		$state          = self::state();
		$missing_tables = self::missing_tables();
		$version_stale  = self::SCHEMA_VERSION !== $state['version'];
		$full_check_due = time() - $state['last_full_check'] >= self::FULL_CHECK_INTERVAL;

		if ( ! $missing_tables && ! $version_stale && ! $full_check_due ) {
			return true;
		}

		if ( $full_check_due && ! $missing_tables && ! $version_stale && self::schema_is_current() ) {
			$state['last_full_check'] = time();
			self::save_state( $state );

			return true;
		}

		$last_error = get_option( self::ERROR_OPTION, array() );
		if (
			is_array( $last_error ) &&
			! empty( $last_error['message'] ) &&
			time() - $state['last_attempt'] < self::RETRY_INTERVAL
		) {
			return new WP_Error( 'didar_schema_retry_later', (string) $last_error['message'] );
		}

		return self::install_and_verify();
	}

	public static function install_and_verify() {
		if ( self::$repairing ) {
			return true;
		}

		self::$repairing = true;
		$state = self::state();
		$state['last_attempt'] = time();
		self::save_state( $state );

		try {
			$event_result = Didar_Event_Log::install_schema();
			$file_result  = Didar_File_Service::install_schema();

			if ( is_wp_error( $event_result ) ) {
				return self::record_error( $event_result );
			}

			if ( is_wp_error( $file_result ) ) {
				return self::record_error( $file_result );
			}

			if ( ! self::schema_is_current() ) {
				return self::record_error(
					new WP_Error(
						'didar_schema_verification_failed',
						__( 'ساختار پایگاه داده دیدار کامل نیست و بازسازی خودکار آن انجام نشد.', 'didar' )
					)
				);
			}

			self::save_state(
				array(
					'version'         => self::SCHEMA_VERSION,
					'last_attempt'    => time(),
					'last_full_check' => time(),
				)
			);
			delete_option( self::ERROR_OPTION );

			return true;
		} finally {
			self::$repairing = false;
		}
	}

	public static function required_tables() {
		return array(
			Didar_Event_Log::table_name(),
			Didar_File_Service::table_name(),
		);
	}

	public static function missing_tables() {
		global $wpdb;

		$required_tables = self::required_tables();
		$placeholders    = implode( ', ', array_fill( 0, count( $required_tables ), '%s' ) );
		$tables          = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
				$required_tables
			)
		);

		return array_values( array_diff( $required_tables, (array) $tables ) );
	}

	public static function schema_is_current() {
		return Didar_Event_Log::schema_is_current() && Didar_File_Service::schema_is_current();
	}

	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = get_option( self::ERROR_OPTION, array() );
		if ( ! is_array( $error ) || empty( $error['message'] ) ) {
			return;
		}
		$message = (string) $error['message'];
		if ( ! empty( $error['database_error'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: sanitized database error visible only to administrators. */
				__( 'جزئیات فنی پایگاه داده: %s', 'didar' ),
				(string) $error['database_error']
			);
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: safe database schema error message. */
					__( 'دیدار نتوانست جداول موردنیاز خود را آماده کند: %s', 'didar' ),
					$message
				)
			)
		);
	}

	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'version'         => isset( $state['version'] ) ? sanitize_text_field( (string) $state['version'] ) : '',
			'last_attempt'    => isset( $state['last_attempt'] ) ? absint( $state['last_attempt'] ) : 0,
			'last_full_check' => isset( $state['last_full_check'] ) ? absint( $state['last_full_check'] ) : 0,
		);
	}

	private static function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, true );
	}

	private static function record_error( WP_Error $error ) {
		$error_data     = $error->get_error_data();
		$database_error = is_array( $error_data ) && isset( $error_data['database_error'] )
			? sanitize_text_field( (string) $error_data['database_error'] )
			: '';

		update_option(
			self::ERROR_OPTION,
			array(
				'code'           => sanitize_key( $error->get_error_code() ),
				'message'        => sanitize_text_field( $error->get_error_message() ),
				'database_error' => $database_error,
				'time'           => time(),
			),
			false
		);

		return $error;
	}
}
