<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Structured, redacting diagnostics for the Didar integration. */
class Didar_Logger {
	const TABLE_VERSION = '1.0.0';
	const OPTION_VERSION = 'didar_diagnostic_log_schema';
	const DISPLAY_TIMEZONE = 'Asia/Tehran';

	public static function display_time( $stored_gmt, $format = 'Y-m-d H:i:s' ) {
		try { $date = new DateTimeImmutable( (string) $stored_gmt, new DateTimeZone( 'UTC' ) ); return $date->setTimezone( new DateTimeZone( self::DISPLAY_TIMEZONE ) )->format( $format ); } catch ( Exception $e ) { return (string) $stored_gmt; }
	}

	public static function display_timestamp( $timestamp, $format = 'Y-m-d H:i:s' ) {
		try { return ( new DateTimeImmutable( '@' . absint( $timestamp ) ) )->setTimezone( new DateTimeZone( self::DISPLAY_TIMEZONE ) )->format( $format ); } catch ( Exception $e ) { return ''; }
	}

	public static function table_name() { global $wpdb; return $wpdb->prefix . 'didar_diagnostic_logs'; }

	public static function maybe_upgrade() {
		if ( self::TABLE_VERSION === get_option( self::OPTION_VERSION ) ) { return true; }
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$sql = "CREATE TABLE {$table} (
			log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at_gmt datetime NOT NULL,
			level varchar(16) NOT NULL,
			operation varchar(64) NOT NULL,
			direction varchar(32) NOT NULL DEFAULT '',
			entity_type varchar(32) NOT NULL DEFAULT '',
			local_id varchar(64) NOT NULL DEFAULT '',
			external_id varchar(128) NOT NULL DEFAULT '',
			form_type varchar(64) NOT NULL DEFAULT '',
			trace_id varchar(80) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			PRIMARY KEY (log_id), KEY created_at (created_at_gmt), KEY trace (trace_id), KEY operation (operation), KEY level (level), KEY local_id (local_id), KEY form_type (form_type)
		) {$wpdb->get_charset_collate()};";
		dbDelta( $sql );
		if ( self::table_exists() ) { update_option( self::OPTION_VERSION, self::TABLE_VERSION, false ); return true; }
		return new WP_Error( 'didar_diagnostic_log_schema', __( 'جدول گزارش تشخیصی دیدار آماده نشد.', 'didar' ) );
	}

	public static function trace_id( $existing = '' ) {
		$existing = sanitize_text_field( (string) $existing );
		return $existing ?: 'didar_sync_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	public function log( $level, $operation, $message, $context = array() ) {
		$level = strtoupper( sanitize_key( $level ) );
		$allowed = array( 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL' );
		if ( ! in_array( $level, $allowed, true ) ) { $level = 'INFO'; }
		$settings = get_option( Didar_Settings::OPTION_NAME, array() );
		$mode = isset( $settings['didar_debug_logging'] ) ? sanitize_key( $settings['didar_debug_logging'] ) : 'off';
		if ( 'off' === $mode && ! in_array( $level, array( 'ERROR', 'CRITICAL' ), true ) ) { return false; }
		if ( 'errors' === $mode && ! in_array( $level, array( 'WARNING', 'ERROR', 'CRITICAL' ), true ) ) { return false; }
		if ( ! self::maybe_upgrade() ) { return false; }
		$context = $this->redact( is_array( $context ) ? $context : array() );
		$fields = array( 'operation' => sanitize_key( $operation ), 'direction' => '', 'entity_type' => '', 'local_id' => '', 'external_id' => '', 'form_type' => '', 'trace_id' => '' );
		foreach ( $fields as $key => $unused ) { if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) { $fields[ $key ] = sanitize_text_field( (string) $context[ $key ] ); unset( $context[ $key ] ); } }
		global $wpdb;
		$result = $wpdb->insert( self::table_name(), array_merge( $fields, array( 'created_at_gmt' => current_time( 'mysql', true ), 'level' => $level, 'message' => sanitize_textarea_field( $message ), 'context' => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ), array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) );
		if ( false !== $result ) { $wpdb->query( 'DELETE FROM ' . self::table_name() . ' WHERE log_id < (SELECT cutoff.log_id FROM (SELECT log_id FROM ' . self::table_name() . ' ORDER BY log_id DESC LIMIT 1 OFFSET 5000) cutoff)' ); }
		return false !== $result;
	}

	public function recent( $filters = array(), $limit = 100 ) {
		global $wpdb; $where = array( '1=1' ); $args = array();
		foreach ( array( 'level', 'form_type', 'operation', 'trace_id' ) as $key ) { if ( ! empty( $filters[ $key ] ) ) { $where[] = "{$key} = %s"; $args[] = sanitize_text_field( $filters[ $key ] ); } }
		if ( ! empty( $filters['local_id'] ) ) { $where[] = 'local_id = %s'; $args[] = sanitize_text_field( $filters['local_id'] ); }
		$args[] = min( 200, max( 1, absint( $limit ) ) );
		$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY log_id DESC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	public function clear() { global $wpdb; return false !== $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); }

	private function redact( $value, $key = '' ) {
		$secret = preg_match( '/api[_-]?key|token|secret|authorization|cookie|password|credential|file[_-]?content/i', (string) $key );
		if ( $secret ) { return '[REDACTED]'; }
		if ( is_string( $value ) ) { if ( 'scheduled_at' === $key ) { try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( self::DISPLAY_TIMEZONE ) )->format( DATE_ATOM ); } catch ( Exception $e ) {} } return preg_replace( '/([?&]apikey=)[^&]+/i', '$1[REDACTED]', $value ); }
		if ( is_array( $value ) ) { $out = array(); foreach ( $value as $k => $v ) { $out[ $k ] = $this->redact( $v, (string) $k ); } return $out; }
		return is_scalar( $value ) || null === $value ? $value : '[REDACTED]';
	}

	private static function table_exists() { global $wpdb; return self::table_name() === $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', self::table_name() ) ); }
}
