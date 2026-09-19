<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable multi-channel notification jobs, separate from the Didar synchronization queue. */
class Didar_Notification_Queue {
	const SCHEMA_VERSION         = '1.1.0';
	const SCHEMA_VERSION_OPTION  = 'didar_notification_queue_schema_version';
	const SCHEMA_VERIFIED_OPTION = 'didar_notification_queue_schema_verified_version';
	const MAX_ATTEMPTS           = 5;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'didar_notification_queue';
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$sql = "CREATE TABLE {$table} (
			job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(190) NOT NULL,
			event_key varchar(100) NOT NULL,
			channel varchar(20) NOT NULL DEFAULT 'sms',
			submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_role varchar(40) NOT NULL DEFAULT '',
			destination varchar(320) NOT NULL DEFAULT '',
			body_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variable_mapping longtext NOT NULL,
			variable_values longtext NOT NULL,
			snapshot_json longtext NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'queued',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_attempt_at datetime NULL,
			sent_at datetime NULL,
			provider_id varchar(100) NOT NULL DEFAULT '',
			provider_code varchar(40) NOT NULL DEFAULT '',
			error_code varchar(80) NOT NULL DEFAULT '',
			error_message text NOT NULL,
			locked_at datetime NULL,
			lock_token varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (job_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY due_state (state,next_attempt_at),
			KEY submission_id (submission_id),
			KEY event_key (event_key),
			KEY recipient_user_id (recipient_user_id)
		) {$wpdb->get_charset_collate()};";
		dbDelta( $sql );
		if ( ! self::schema_is_current() ) {
			return new WP_Error( 'didar_notification_schema_failed', __( 'امکان آماده‌سازی صف اعلان‌ها وجود ندارد.', 'didar' ) );
		}
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		update_option( self::SCHEMA_VERIFIED_OPTION, self::SCHEMA_VERSION, false );
		return true;
	}

	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function schema_is_current() {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$table = self::table_name();
		$indexes = wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ), 'Key_name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$columns = wp_list_pluck( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ! array_diff( array( 'PRIMARY', 'idempotency_key', 'due_state', 'submission_id', 'event_key', 'recipient_user_id' ), array_unique( $indexes ) ) && in_array( 'channel', $columns, true );
	}

	public function create( $job ) {
		global $wpdb;
		$job = is_array( $job ) ? $job : array();
		$key = sanitize_text_field( (string) ( $job['idempotency_key'] ?? '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'didar_notification_idempotency_missing', __( 'شناسه تکرارنشدنی اعلان مشخص نیست.', 'didar' ) );
		}
		$existing = $this->find_by_idempotency( $key );
		if ( $existing ) {
			return $existing;
		}
		$now = current_time( 'mysql', true );
		$next = ! empty( $job['next_attempt_at'] ) ? sanitize_text_field( (string) $job['next_attempt_at'] ) : $now;
		$initial_state = isset( $job['state'] ) && is_scalar( $job['state'] ) ? sanitize_key( (string) $job['state'] ) : 'queued';
		$channel = sanitize_key( (string) ( $job['channel'] ?? 'sms' ) );
		$channel = in_array( $channel, array( 'sms', 'email' ), true ) ? $channel : 'sms';
		$destination = 'email' === $channel ? strtolower( sanitize_email( (string) ( $job['destination'] ?? '' ) ) ) : sanitize_text_field( (string) ( $job['destination'] ?? '' ) );
		$row = array(
			'idempotency_key'   => $key,
			'event_key'         => Didar_Notification_Event_Registry::normalize_key( $job['event_key'] ?? '' ),
			'channel'           => $channel,
			'submission_id'     => absint( $job['submission_id'] ?? 0 ),
			'recipient_user_id' => absint( $job['recipient_user_id'] ?? 0 ),
			'recipient_role'    => sanitize_key( (string) ( $job['recipient_role'] ?? '' ) ),
			'destination'       => $destination,
			'body_id'           => absint( $job['body_id'] ?? 0 ),
			'variable_mapping'  => wp_json_encode( array_values( (array) ( $job['variable_mapping'] ?? array() ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'variable_values'   => wp_json_encode( array_values( (array) ( $job['variable_values'] ?? array() ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'snapshot_json'     => wp_json_encode( is_array( $job['snapshot'] ?? null ) ? $job['snapshot'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'state'             => in_array( $initial_state, array( 'queued', 'failed' ), true ) ? $initial_state : 'queued',
			'attempts'          => absint( $job['attempts'] ?? 0 ),
			'next_attempt_at'   => $next,
			'error_code'        => sanitize_key( (string) ( $job['error_code'] ?? '' ) ),
			'error_message'     => sanitize_text_field( (string) ( $job['error_message'] ?? '' ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$result = $wpdb->insert( self::table_name(), $row );
		if ( false === $result ) {
			$existing = $this->find_by_idempotency( $key );
			return $existing ? $existing : new WP_Error( 'didar_notification_insert_failed', __( 'ذخیره اعلان انجام نشد.', 'didar' ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	public function get( $job_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE job_id = %d', absint( $job_id ) ), ARRAY_A );
		return $this->hydrate( $row );
	}

	public function find_by_idempotency( $key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE idempotency_key = %s', sanitize_text_field( (string) $key ) ), ARRAY_A );
		return $this->hydrate( $row );
	}

	public function list_jobs( $filters = array(), $limit = 100, $offset = 0 ) {
		global $wpdb;
		$filters = is_array( $filters ) ? $filters : array();
		$limit = min( 200, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		$where = array( '1=1' );
		$args = array();
		if ( ! empty( $filters['states'] ) && is_array( $filters['states'] ) ) {
			$states = array_values( array_intersect( array( 'queued', 'processing', 'retry', 'sent', 'failed', 'discarded' ), array_map( 'sanitize_key', $filters['states'] ) ) );
			if ( $states ) { $where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')'; foreach ( $states as $state ) { $args[] = $state; } }
		} elseif ( ! empty( $filters['state'] ) ) { $where[] = 'state = %s'; $args[] = sanitize_key( $filters['state'] ); }
		if ( ! empty( $filters['event_key'] ) ) { $where[] = 'event_key = %s'; $args[] = Didar_Notification_Event_Registry::normalize_key( $filters['event_key'] ); }
		if ( ! empty( $filters['channel'] ) ) { $where[] = 'channel = %s'; $args[] = in_array( sanitize_key( $filters['channel'] ), array( 'sms', 'email' ), true ) ? sanitize_key( $filters['channel'] ) : 'sms'; }
		if ( ! empty( $filters['submission_id'] ) ) { $where[] = 'submission_id = %d'; $args[] = absint( $filters['submission_id'] ); }
		$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, job_id DESC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_values( array_filter( array_map( array( $this, 'hydrate' ), (array) $rows ) ) );
	}

	public function count( $filters = array() ) {
		global $wpdb;
		$filters = is_array( $filters ) ? $filters : array();
		$where = array( '1=1' );
		$args = array();
		if ( ! empty( $filters['states'] ) && is_array( $filters['states'] ) ) {
			$states = array_values( array_intersect( array( 'queued', 'processing', 'retry', 'sent', 'failed', 'discarded' ), array_map( 'sanitize_key', $filters['states'] ) ) );
			if ( $states ) { $where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')'; foreach ( $states as $state ) { $args[] = $state; } }
		} elseif ( ! empty( $filters['state'] ) ) { $where[] = 'state = %s'; $args[] = sanitize_key( $filters['state'] ); }
		if ( ! empty( $filters['event_key'] ) ) { $where[] = 'event_key = %s'; $args[] = Didar_Notification_Event_Registry::normalize_key( $filters['event_key'] ); }
		if ( ! empty( $filters['channel'] ) ) { $where[] = 'channel = %s'; $args[] = in_array( sanitize_key( $filters['channel'] ), array( 'sms', 'email' ), true ) ? sanitize_key( $filters['channel'] ) : 'sms'; }
		if ( ! empty( $filters['submission_id'] ) ) { $where[] = 'submission_id = %d'; $args[] = absint( $filters['submission_id'] ); }
		$sql = 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public function purge_actionable() {
		$ids = array();
		do {
			$jobs = $this->list_jobs( array( 'states' => array( 'queued', 'retry', 'failed' ) ), 200 );
			$discarded = 0;
			foreach ( $jobs as $job ) {
				if ( $this->discard( $job['job_id'] ) ) { $ids[] = absint( $job['job_id'] ); $discarded++; }
			}
		} while ( $jobs && $discarded );
		return $ids;
	}

	/** Atomically claim one due job so overlapping workers cannot send twice. */
	public function claim( $job_id ) {
		global $wpdb;
		$token = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE " . self::table_name() . " SET state = 'processing', attempts = attempts + 1, last_attempt_at = %s, locked_at = %s, lock_token = %s, updated_at = %s WHERE job_id = %d AND state IN ('queued','retry') AND next_attempt_at <= %s", $now, $now, $token, $now, absint( $job_id ), $now ) );
		return $updated ? $this->get( $job_id ) : false;
	}

	public function due_job_ids( $limit = 20 ) {
		global $wpdb;
		$limit = min( 100, max( 1, absint( $limit ) ) );
		$now = current_time( 'mysql', true );
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT job_id FROM " . self::table_name() . " WHERE state IN ('queued','retry') AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, job_id ASC LIMIT %d", $now, $limit ) ) );
	}

	public function mark_success( $job_id, $provider_id, $provider_code = '' ) {
		return $this->update_state( $job_id, 'sent', array( 'sent_at' => current_time( 'mysql', true ), 'provider_id' => sanitize_text_field( (string) $provider_id ), 'provider_code' => sanitize_text_field( (string) $provider_code ), 'error_code' => '', 'error_message' => '' ), array(), array( 'processing' ) );
	}

	public function mark_retry( $job_id, $error_code, $error_message, $delay ) {
		$delay = max( 60, absint( $delay ) );
		return $this->update_state( $job_id, 'retry', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ), 'error_code' => sanitize_key( $error_code ), 'error_message' => sanitize_text_field( $error_message ) ), array(), array( 'processing' ) );
	}

	public function mark_failed( $job_id, $error_code, $error_message ) {
		return $this->update_state( $job_id, 'failed', array( 'next_attempt_at' => current_time( 'mysql', true ), 'error_code' => sanitize_key( $error_code ), 'error_message' => sanitize_text_field( $error_message ) ), array(), array( 'processing' ) );
	}

	public function retry( $job_id ) {
		return $this->update_state( $job_id, 'retry', array( 'next_attempt_at' => current_time( 'mysql', true ), 'error_code' => '', 'error_message' => '', 'locked_at' => null, 'lock_token' => '' ), array(), array( 'queued', 'retry', 'failed' ) );
	}

	public function discard( $job_id ) {
		return $this->update_state( $job_id, 'discarded', array( 'error_code' => 'discarded_by_admin', 'error_message' => 'Discarded by an administrator.', 'locked_at' => null, 'lock_token' => '' ), array(), array( 'queued', 'retry', 'failed' ) );
	}

	public function recover_stale( $age = 900 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, absint( $age ) ) );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE " . self::table_name() . " SET state = IF(attempts >= %d, 'failed', 'retry'), next_attempt_at = %s, error_code = IF(attempts >= %d, 'attempt_limit', 'stale_lock'), error_message = IF(attempts >= %d, 'Maximum attempts reached.', 'Recovered after a stale worker lock.'), locked_at = NULL, lock_token = '', updated_at = %s WHERE state = 'processing' AND locked_at IS NOT NULL AND locked_at < %s", self::MAX_ATTEMPTS, current_time( 'mysql', true ), self::MAX_ATTEMPTS, self::MAX_ATTEMPTS, current_time( 'mysql', true ), $cutoff ) );
	}

	private function update_state( $job_id, $state, $values, $blocked_states = array(), $required_states = array() ) {
		global $wpdb;
		$allowed = array( 'queued', 'processing', 'retry', 'sent', 'failed', 'discarded' );
		if ( ! in_array( $state, $allowed, true ) ) { return false; }
		if ( $blocked_states || $required_states ) {
			$current = $this->get( $job_id );
			if ( ! $current || ( $blocked_states && in_array( $current['state'], $blocked_states, true ) ) || ( $required_states && ! in_array( $current['state'], $required_states, true ) ) ) { return false; }
		}
		$values['state'] = $state;
		$values['updated_at'] = current_time( 'mysql', true );
		if ( ! array_key_exists( 'locked_at', $values ) ) { $values['locked_at'] = null; }
		if ( ! array_key_exists( 'lock_token', $values ) ) { $values['lock_token'] = ''; }
		$set = array();
		$args = array();
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				$set[] = '`' . esc_sql( $key ) . '` = NULL';
				continue;
			}
			$set[] = '`' . esc_sql( $key ) . '` = ' . ( is_int( $value ) ? '%d' : '%s' );
			$args[] = $value;
		}
		$where = 'job_id = %d';
		$args[] = absint( $job_id );
		$expected_states = $required_states ? $required_states : ( $blocked_states ? array_diff( $allowed, $blocked_states ) : array() );
		if ( $expected_states ) {
			$where .= ' AND state IN (' . implode( ',', array_fill( 0, count( $expected_states ), '%s' ) ) . ')';
			foreach ( $expected_states as $expected_state ) { $args[] = $expected_state; }
		}
		$sql = 'UPDATE ' . self::table_name() . ' SET ' . implode( ', ', $set ) . ' WHERE ' . $where;
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		return false !== $result && 0 < (int) $result;
	}

	private function hydrate( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['job_id'] ) ) { return null; }
		$channel = sanitize_key( (string) ( $row['channel'] ?? 'sms' ) );
		$row['channel'] = in_array( $channel, array( 'sms', 'email' ), true ) ? $channel : 'sms';
		foreach ( array( 'job_id', 'submission_id', 'recipient_user_id', 'body_id', 'attempts' ) as $key ) { $row[ $key ] = absint( $row[ $key ] ); }
		foreach ( array( 'variable_mapping', 'variable_values', 'snapshot_json' ) as $key ) {
			$value = json_decode( (string) ( $row[ $key ] ?? '' ), true );
			$row[ $key ] = is_array( $value ) ? $value : array();
		}
		// Keep a convenient runtime alias while preserving the existing storage key.
		$row['snapshot'] = $row['snapshot_json'];
		return $row;
	}
}
