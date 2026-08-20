<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Event_Log {
	const SCHEMA_VERSION         = '1.0.0';
	const SCHEMA_VERSION_OPTION  = 'didar_event_schema_version';
	const SCHEMA_VERIFIED_OPTION = 'didar_event_schema_verified_version';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'didar_events';
	}

	public static function maybe_upgrade() {
		if (
			self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION ) &&
			self::SCHEMA_VERSION === get_option( self::SCHEMA_VERIFIED_OPTION ) &&
			self::schema_is_current()
		) {
			return true;
		}

		return self::install_schema();
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			event_type varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_value longtext NULL,
			new_value longtext NULL,
			event_meta longtext NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (event_id),
			KEY submission_event (submission_id,event_id),
			KEY event_type (event_type),
			KEY actor_user_id (actor_user_id),
			KEY created_at_gmt (created_at_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
		$database_error = (string) $wpdb->last_error;

		if ( ! self::table_exists() ) {
			$create_sql = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
			$created    = $wpdb->query( $create_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $created ) {
				$database_error = (string) $wpdb->last_error;
			}
		}

		if ( ! self::table_exists() ) {
			$default_sql = str_replace( " {$charset_collate};", ';', $sql );
			$default_sql = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $default_sql, 1 );
			$created     = $wpdb->query( $default_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $created || '' !== (string) $wpdb->last_error ) {
				$database_error = (string) $wpdb->last_error;
			}
		}

		if ( ! self::schema_is_current() ) {
			if ( '' === $database_error ) {
				$database_error = 'The table creation query completed, but schema verification failed.';
			}

			delete_option( self::SCHEMA_VERIFIED_OPTION );
			self::log_database_error( 'didar_event_schema_upgrade_failed', $database_error );

			return new WP_Error(
				'didar_event_database_error',
				__( 'امکان آماده‌سازی جدول رویدادهای دیدار وجود ندارد.', 'didar' ),
				array(
					'table'          => $table_name,
					'database_error' => sanitize_text_field( $database_error ),
				)
			);
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		update_option( self::SCHEMA_VERIFIED_OPTION, self::SCHEMA_VERSION, false );

		return true;
	}

	public static function schema_is_current() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table_name = self::table_name();
		$required_columns = array(
			'event_id',
			'submission_id',
			'event_type',
			'actor_user_id',
			'old_value',
			'new_value',
			'event_meta',
			'created_at_gmt',
		);
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}", 0 );
		if ( array_diff( $required_columns, (array) $columns ) ) {
			return false;
		}

		$required_indexes = array( 'PRIMARY', 'submission_event', 'event_type', 'actor_user_id', 'created_at_gmt' );
		$indexes          = wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ), 'Key_name' );

		return ! array_diff( $required_indexes, array_unique( $indexes ) );
	}

	public function add( $submission_id, $event_type, $old_value = null, $new_value = null, $metadata = array() ) {
		global $wpdb;
		$submission_id = absint( $submission_id );
		$event_type    = sanitize_key( $event_type );
		if ( ! $submission_id || Didar_Post_Type::POST_TYPE !== get_post_type( $submission_id ) || ! $event_type ) {
			return false;
		}

		$actor_id = get_current_user_id();
		$actor    = $actor_id ? get_user_by( 'id', $actor_id ) : false;
		$metadata = is_array( $metadata ) ? $metadata : array();
		if ( $actor ) {
			$metadata['actor_label'] = $actor->display_name;
		}

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'submission_id' => $submission_id,
				'event_type'    => $event_type,
				'actor_user_id' => $actor_id,
				'old_value'     => $this->encode_value( $old_value ),
				'new_value'     => $this->encode_value( $new_value ),
				'event_meta'    => $this->encode_value( $metadata ),
				'created_at_gmt' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	public function get_for_submission( $submission_id, $limit = 100 ) {
		global $wpdb;
		$submission_id = absint( $submission_id );
		$limit         = min( 200, max( 1, absint( $limit ) ) );
		if ( ! $submission_id ) {
			return array();
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, submission_id, event_type, actor_user_id, old_value, new_value, event_meta, created_at_gmt FROM {$table} WHERE submission_id = %d ORDER BY event_id DESC LIMIT %d",
				$submission_id,
				$limit
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['event_id']      = (int) $row['event_id'];
			$row['submission_id'] = (int) $row['submission_id'];
			$row['actor_user_id'] = (int) $row['actor_user_id'];
			$row['old_value']     = $this->decode_value( $row['old_value'] );
			$row['new_value']     = $this->decode_value( $row['new_value'] );
			$row['event_meta']    = $this->decode_value( $row['event_meta'] );
		}
		unset( $row );

		return $rows;
	}

	private function encode_value( $value ) {
		return wp_json_encode( array( 'value' => $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private function decode_value( $value ) {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) && array_key_exists( 'value', $decoded ) ? $decoded['value'] : null;
	}

	private static function table_exists() {
		global $wpdb;

		$table_name = self::table_name();
		$found_table = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table_name
			)
		);

		return $table_name === $found_table;
	}

	private static function log_database_error( $code, $database_error = '' ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		global $wpdb;
		if ( '' === $database_error ) {
			$database_error = (string) $wpdb->last_error;
		}

		error_log(
			'[Didar] ' . wp_json_encode(
				array(
					'code'           => sanitize_key( $code ),
					'table'          => self::table_name(),
					'database_error' => sanitize_text_field( $database_error ),
				)
			)
		); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
