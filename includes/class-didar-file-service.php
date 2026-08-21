<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns Didar document storage, records, authorization, download, and cleanup.
 */
class Didar_File_Service {
	const SCHEMA_VERSION         = '1.1.0';
	const SCHEMA_VERSION_OPTION  = 'didar_file_schema_version';
	const SCHEMA_VERIFIED_OPTION = 'didar_file_schema_verified_version';
	const STORAGE_DIRECTORY      = 'didar-private';
	const TEMPORARY_TTL          = DAY_IN_SECONDS;

	private $registry;
	private $settings;
	private $events;
	private $submission_service;
	private $upload_subdirectory = '';

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Event_Log $events ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->events   = $events;

		add_action( 'admin_post_didar_download_file', array( $this, 'handle_secure_download' ) );
		add_action( 'admin_post_nopriv_didar_download_file', array( $this, 'deny_unauthenticated_download' ) );
		add_action( 'didar_cleanup_temporary_uploads', array( $this, 'cleanup_temporary_files' ) );
		add_action( 'update_option_' . Didar_Settings::OPTION_NAME, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_submission_files' ), 10, 2 );
	}

	public function set_submission_service( Didar_Submission_Service $service ) {
		$this->submission_service = $service;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'didar_files';
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
			file_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			original_name varchar(255) NOT NULL,
			stored_name varchar(191) NOT NULL,
			relative_path varchar(500) NOT NULL,
			mime_type varchar(100) NOT NULL,
			extension varchar(20) NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_user_id bigint(20) unsigned NOT NULL,
			submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_type varchar(64) NOT NULL,
			field_key varchar(191) NOT NULL,
			file_status varchar(20) NOT NULL DEFAULT 'temporary',
			created_at_gmt datetime NOT NULL,
			finalized_at_gmt datetime NULL,
			PRIMARY KEY  (file_id),
			UNIQUE KEY relative_path (relative_path(191)),
			KEY owner_status_created (owner_user_id,file_status,created_at_gmt),
			KEY submission_field (submission_id,field_key),
			KEY temp_context (owner_user_id,form_type,field_key,submission_id,file_status)
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
			self::log_database_error( 'didar_schema_upgrade_failed', $database_error );

			return new WP_Error(
				'didar_database_error',
				__( 'امکان آماده‌سازی محل ثبت اطلاعات فایل وجود ندارد.', 'didar' ),
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
			'file_id',
			'original_name',
			'stored_name',
			'relative_path',
			'mime_type',
			'extension',
			'file_size',
			'owner_user_id',
			'submission_id',
			'form_type',
			'field_key',
			'file_status',
			'created_at_gmt',
			'finalized_at_gmt',
		);
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}", 0 );
		if ( array_diff( $required_columns, (array) $columns ) ) {
			return false;
		}

		$required_indexes = array( 'PRIMARY', 'relative_path', 'owner_status_created', 'submission_field', 'temp_context' );
		$indexes          = wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ), 'Key_name' );

		return ! array_diff( $required_indexes, array_unique( $indexes ) );
	}

	public function upload( $file, $form_type, $field_key, $submission_id = 0 ) {
		global $wpdb;

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', __( 'برای بارگذاری فایل وارد حساب کاربری شوید.', 'didar' ) );
		}

		$form_type    = sanitize_key( (string) $form_type );
		$field_key    = sanitize_key( (string) $field_key );
		$submission_id = absint( $submission_id );
		$field        = $this->get_file_field( $form_type, $field_key );
		if ( ! $field ) {
			return new WP_Error( 'invalid_file_field', __( 'فیلد فایل معتبر نیست.', 'didar' ) );
		}
		if ( $submission_id && ! $this->can_edit_submission( $submission_id, $form_type ) ) {
			return new WP_Error( 'forbidden_upload', __( 'شما اجازه بارگذاری فایل برای این درخواست را ندارید.', 'didar' ) );
		}

		$schema = $this->ensure_schema_available();
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$max_files = ! empty( $field['max_files'] ) ? absint( $field['max_files'] ) : 1;
		if ( $this->count_context_files( get_current_user_id(), $form_type, $field_key, $submission_id ) >= $max_files ) {
			return new WP_Error( 'file_limit', sprintf( __( 'برای این فیلد حداکثر %d فایل مجاز است.', 'didar' ), $max_files ) );
		}

		$validated = $this->validate_uploaded_file( $file, $field );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$storage = $this->ensure_storage();
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$stored_name = $this->generate_stored_name( $validated['extension'] );
		$upload      = $file;
		$upload['name'] = $stored_name;
		$this->upload_subdirectory = '/' . self::STORAGE_DIRECTORY . '/' . gmdate( 'Y/m' );
		add_filter( 'upload_dir', array( $this, 'filter_private_upload_directory' ) );
		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$moved = wp_handle_upload(
				$upload,
				array(
					'test_form' => false,
					'mimes'     => $validated['allowed_mimes'],
				)
			);
		} finally {
			remove_filter( 'upload_dir', array( $this, 'filter_private_upload_directory' ) );
			$this->upload_subdirectory = '';
		}

		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['file'] ) ) {
			return new WP_Error( 'upload_failed', __( 'بارگذاری فایل انجام نشد.', 'didar' ) );
		}

		$relative_path = $this->relative_path_from_absolute( $moved['file'] );
		if ( is_wp_error( $relative_path ) ) {
			wp_delete_file( $moved['file'] );
			return $relative_path;
		}

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'original_name'  => $validated['original_name'],
				'stored_name'    => $stored_name,
				'relative_path'  => $relative_path,
				'mime_type'      => $validated['mime_type'],
				'extension'      => $validated['extension'],
				'file_size'      => (int) $file['size'],
				'owner_user_id'  => get_current_user_id(),
				'submission_id'  => $submission_id,
				'form_type'      => $form_type,
				'field_key'      => $field_key,
				'file_status'    => 'temporary',
				'created_at_gmt' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			self::log_database_error( 'didar_file_record_failed' );
			wp_delete_file( $moved['file'] );
			return new WP_Error( 'didar_file_record_failed', __( 'ثبت اطلاعات فایل انجام نشد.', 'didar' ) );
		}

		$file_id = (int) $wpdb->insert_id;
		if ( $this->count_context_files( get_current_user_id(), $form_type, $field_key, $submission_id ) > $max_files ) {
			$this->delete_file_record( $file_id );
			return new WP_Error( 'file_limit', sprintf( __( 'برای این فیلد حداکثر %d فایل مجاز است.', 'didar' ), $max_files ) );
		}

		return array(
			'file_id'       => $file_id,
			'original_name' => $validated['original_name'],
			'display_name'  => $validated['original_name'],
			'mime_type'     => $validated['mime_type'],
			'size'          => (int) $file['size'],
		);
	}

	public function filter_private_upload_directory( $uploads ) {
		if ( ! $this->upload_subdirectory ) {
			return $uploads;
		}
		$uploads['subdir'] = $this->upload_subdirectory;
		$uploads['path']   = $uploads['basedir'] . $this->upload_subdirectory;
		$uploads['url']    = $uploads['baseurl'] . $this->upload_subdirectory;
		return $uploads;
	}

	public function get( $file_id ) {
		global $wpdb;
		$file_id = absint( $file_id );
		if ( ! $file_id ) {
			return null;
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_id = %d", $file_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( array( 'file_id', 'file_size', 'owner_user_id', 'submission_id' ) as $integer_key ) {
			$row[ $integer_key ] = (int) $row[ $integer_key ];
		}
		return $row;
	}

	public function validate_file_id( $field, $raw, $context = 'frontend', $submission_id = 0 ) {
		$file_id = absint( $raw );
		if ( ! $file_id ) {
			return '';
		}
		$record = $this->get( $file_id );
		if ( ! $record ) {
			return new WP_Error( 'invalid_file', __( 'فایل انتخاب‌شده معتبر نیست.', 'didar' ) );
		}

		$submission_id = absint( $submission_id );
		if ( $record['form_type'] !== $field['form_type'] || $record['field_key'] !== $field['name'] ) {
			return new WP_Error( 'invalid_file_context', __( 'این فایل برای فیلد دیگری بارگذاری شده است.', 'didar' ) );
		}

		if ( 'temporary' === $record['file_status'] ) {
			$is_new_admin_submission = 'admin' === $context && $submission_id && ! $record['submission_id'] && ! get_post_meta( $submission_id, '_didar_form_type', true );
			if (
				$record['owner_user_id'] !== get_current_user_id() ||
				( $submission_id && $record['submission_id'] !== $submission_id && ! $is_new_admin_submission ) ||
				( ! $submission_id && $record['submission_id'] )
			) {
				return new WP_Error( 'invalid_file_owner', __( 'شما اجازه استفاده از این فایل را ندارید.', 'didar' ) );
			}
		} elseif ( 'final' === $record['file_status'] ) {
			if ( ! $submission_id || $record['submission_id'] !== $submission_id || ! $this->can_edit_submission( $submission_id, $field['form_type'] ) ) {
				return new WP_Error( 'invalid_file_owner', __( 'شما اجازه استفاده از این فایل را ندارید.', 'didar' ) );
			}
		} else {
			return new WP_Error( 'invalid_file_status', __( 'فایل انتخاب‌شده معتبر نیست.', 'didar' ) );
		}

		$allowed_mimes = isset( $field['mime_types'] ) ? (array) $field['mime_types'] : array();
		$allowed_exts  = $this->allowed_extensions( isset( $field['upload_mimes'] ) ? (array) $field['upload_mimes'] : array() );
		if ( ! in_array( $record['mime_type'], $allowed_mimes, true ) || ! in_array( $record['extension'], $allowed_exts, true ) || is_wp_error( $this->absolute_path( $record ) ) ) {
			return new WP_Error( 'invalid_file_type', __( 'نوع فایل انتخاب‌شده مجاز نیست.', 'didar' ) );
		}

		return $file_id;
	}

	public function finalize_submission_files( $post_id, $form_type, $data, $old_data = array(), $log_changes = false ) {
		global $wpdb;
		$post_id = absint( $post_id );
		foreach ( $this->registry->fields( $form_type ) as $field_key => $field ) {
			if ( 'file' !== $field['type'] ) {
				continue;
			}

			$new_ids = $this->normalize_ids( isset( $data[ $field_key ] ) ? $data[ $field_key ] : array() );
			$old_ids = $this->normalize_ids( isset( $old_data[ $field_key ] ) ? $old_data[ $field_key ] : array() );
			$added   = array_values( array_diff( $new_ids, $old_ids ) );
			$removed = array_values( array_diff( $old_ids, $new_ids ) );

			foreach ( $new_ids as $file_id ) {
				$record = $this->get( $file_id );
				if ( ! $record || $record['form_type'] !== $form_type || $record['field_key'] !== $field_key ) {
					continue;
				}
				if ( 'temporary' === $record['file_status'] && $record['owner_user_id'] === get_current_user_id() && ( ! $record['submission_id'] || $record['submission_id'] === $post_id ) ) {
					$wpdb->update(
						self::table_name(),
						array( 'submission_id' => $post_id, 'file_status' => 'final', 'finalized_at_gmt' => current_time( 'mysql', true ) ),
						array( 'file_id' => $file_id ),
						array( '%d', '%s', '%s' ),
						array( '%d' )
					);
				}
			}

			if ( $log_changes && 1 === count( $added ) && 1 === count( $removed ) ) {
				$this->events->add( $post_id, 'file_replaced', $removed[0], $added[0], array( 'field_name' => $field_key, 'field_label' => $field['label'], 'file_id' => $added[0] ) );
				$this->delete_final_file( $removed[0], $post_id, $field_key );
				$added   = array();
				$removed = array();
			}
			foreach ( $added as $file_id ) {
				if ( $log_changes ) {
					$this->events->add( $post_id, 'file_added', null, $file_id, array( 'field_name' => $field_key, 'field_label' => $field['label'], 'file_id' => $file_id ) );
				}
			}
			foreach ( $removed as $file_id ) {
				$this->delete_final_file( $file_id, $post_id, $field_key );
				if ( $log_changes ) {
					$this->events->add( $post_id, 'file_removed', $file_id, null, array( 'field_name' => $field_key, 'field_label' => $field['label'], 'file_id' => $file_id ) );
				}
			}
		}
	}

	public function remove( $file_id, $form_type, $field_key, $submission_id = 0 ) {
		$file_id       = absint( $file_id );
		$submission_id = absint( $submission_id );
		$field         = $this->get_file_field( $form_type, $field_key );
		$record        = $this->get( $file_id );
		if ( ! $field || ! $record || $record['form_type'] !== $form_type || $record['field_key'] !== $field_key ) {
			return new WP_Error( 'invalid_document', __( 'فایل یا درخواست معتبر نیست.', 'didar' ) );
		}

		if ( 'temporary' === $record['file_status'] ) {
			if ( $record['owner_user_id'] !== get_current_user_id() || $record['submission_id'] !== $submission_id ) {
				return new WP_Error( 'forbidden_document', __( 'شما اجازه حذف این فایل را ندارید.', 'didar' ) );
			}
			return $this->delete_file_record( $file_id );
		}

		if ( 'final' !== $record['file_status'] || ! $submission_id || $record['submission_id'] !== $submission_id || ! $this->can_edit_submission( $submission_id, $form_type ) ) {
			return new WP_Error( 'forbidden_document', __( 'شما اجازه حذف این فایل را ندارید.', 'didar' ) );
		}

		$data        = $this->submission_service ? $this->submission_service->get_fields( $submission_id ) : array();
		$current_ids = $this->normalize_ids( isset( $data[ $field_key ] ) ? $data[ $field_key ] : array() );
		if ( ! in_array( $file_id, $current_ids, true ) ) {
			return new WP_Error( 'forbidden_document', __( 'شما اجازه حذف این فایل را ندارید.', 'didar' ) );
		}

		$deleted = $this->delete_final_file( $file_id, $submission_id, $field_key );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}
		$data[ $field_key ] = array_values( array_diff( $current_ids, array( $file_id ) ) );
		update_post_meta( $submission_id, '_didar_fields', $data );
		$this->events->add( $submission_id, 'file_removed', $file_id, null, array( 'field_name' => $field_key, 'field_label' => $field['label'], 'file_id' => $file_id ) );
		wp_update_post( array( 'ID' => $submission_id ) );
		return true;
	}

	public function get_display_data( $file_id, $submission_id = 0, $field_key = '', $can_delete = false ) {
		$record = $this->get( $file_id );
		if ( ! $record ) {
			return null;
		}
		if ( $submission_id && ( $record['submission_id'] !== absint( $submission_id ) || ( $field_key && $record['field_key'] !== $field_key ) ) ) {
			return null;
		}
		return array(
			'file_id'      => $record['file_id'],
			'file_name'    => $record['original_name'],
			'download_url' => 'final' === $record['file_status'] ? $this->get_download_url( $record['file_id'] ) : '',
			'can_delete'   => (bool) $can_delete,
			'mime_type'    => $record['mime_type'],
			'size'         => $record['file_size'],
		);
	}

	public function get_download_url( $file_id ) {
		$record = $this->get( $file_id );
		if ( ! $record || 'final' !== $record['file_status'] || ! $this->can_download( $record ) ) {
			return '';
		}
		if ( 'direct' === $this->settings->file_download_mode() ) {
			return $this->direct_url( $record );
		}
		$url = add_query_arg( array( 'action' => 'didar_download_file', 'file_id' => $record['file_id'] ), admin_url( 'admin-post.php' ) );
		return add_query_arg( '_wpnonce', wp_create_nonce( 'didar_download_file_' . $record['file_id'] ), $url );
	}

	public function handle_secure_download() {
		$file_id = isset( $_GET['file_id'] ) && ! is_array( $_GET['file_id'] ) ? absint( wp_unslash( $_GET['file_id'] ) ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) && ! is_array( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! $file_id || ! wp_verify_nonce( $nonce, 'didar_download_file_' . $file_id ) ) {
			wp_die( esc_html__( 'اجازه دانلود این فایل را ندارید.', 'didar' ), '', array( 'response' => 403 ) );
		}
		$record = $this->get( $file_id );
		if ( ! $record || 'final' !== $record['file_status'] || ! $this->can_download( $record ) ) {
			wp_die( esc_html__( 'فایل یافت نشد یا در دسترس شما نیست.', 'didar' ), '', array( 'response' => 404 ) );
		}
		$path = $this->absolute_path( $record );
		if ( is_wp_error( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'فایل در دسترس نیست.', 'didar' ), '', array( 'response' => 404 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $record['mime_type'] );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		$ascii_name = preg_replace( '/[^A-Za-z0-9._-]/', '_', $record['original_name'] );
		$ascii_name = $ascii_name ? $ascii_name : 'didar-file-' . $record['file_id'] . '.' . $record['extension'];
		header( 'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . rawurlencode( $record['original_name'] ) );
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'فایل در دسترس نیست.', 'didar' ), '', array( 'response' => 404 ) );
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}
		fclose( $handle );
		exit;
	}

	public function deny_unauthenticated_download() {
		wp_die( esc_html__( 'برای دانلود فایل وارد حساب کاربری شوید.', 'didar' ), '', array( 'response' => 401 ) );
	}

	public function can_download( $record ) {
		if ( ! is_array( $record ) || 'final' !== $record['file_status'] || ! $record['submission_id'] || ! $this->submission_service || ! $this->submission_service->can_view_public( $record['submission_id'] ) ) {
			return false;
		}
		$post = get_post( $record['submission_id'] );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || (string) get_post_meta( $post->ID, '_didar_form_type', true ) !== $record['form_type'] ) {
			return false;
		}
		$data = $this->submission_service->get_fields( $post->ID );
		$ids  = $this->normalize_ids( isset( $data[ $record['field_key'] ] ) ? $data[ $record['field_key'] ] : array() );
		return in_array( $record['file_id'], $ids, true );
	}

	public function cleanup_temporary_files() {
		global $wpdb;
		$table  = self::table_name();
		$before = gmdate( 'Y-m-d H:i:s', time() - self::TEMPORARY_TTL );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT file_id FROM {$table} WHERE file_status = %s AND created_at_gmt < %s ORDER BY file_id ASC LIMIT 100", 'temporary', $before ) );
		foreach ( (array) $ids as $file_id ) {
			$this->delete_file_record( $file_id );
		}
	}

	public function delete_submission_files( $post_id, $post = null ) {
		global $wpdb;
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}
		$table = self::table_name();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT file_id FROM {$table} WHERE submission_id = %d", absint( $post_id ) ) );
		foreach ( (array) $ids as $file_id ) {
			$this->delete_file_record( $file_id );
		}
	}

	public function settings_updated( $old_value, $new_value ) {
		$mode = is_array( $new_value ) && isset( $new_value['file_download_mode'] ) && is_scalar( $new_value['file_download_mode'] ) ? sanitize_key( (string) $new_value['file_download_mode'] ) : Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;
		$this->sync_storage_protection( in_array( $mode, array( 'secure', 'direct' ), true ) ? $mode : Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE );
	}

	public function sync_storage_protection( $mode = '' ) {
		$mode    = in_array( $mode, array( 'secure', 'direct' ), true ) ? $mode : $this->settings->file_download_mode();
		$storage = $this->storage_info();
		$root    = $storage['basedir'] . '/' . self::STORAGE_DIRECTORY;
		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'storage_unavailable', __( 'مسیر ذخیره‌سازی فایل‌های دیدار در دسترس نیست.', 'didar' ) );
		}

		$index_written = @file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( 'secure' === $mode ) {
			$htaccess_written = @file_put_contents( $root . '/.htaccess', "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$webconfig_written = @file_put_contents( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security><directoryBrowse enabled=\"false\"/></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$htaccess_written = @file_put_contents( $root . '/.htaccess', "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$webconfig_written = @file_put_contents( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><directoryBrowse enabled=\"false\"/></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( false === $index_written || false === $htaccess_written || false === $webconfig_written ) {
			return new WP_Error( 'storage_protection_failed', __( 'امکان به‌روزرسانی قواعد دسترسی پوشه فایل‌های دیدار وجود ندارد.', 'didar' ) );
		}
		return true;
	}

	private function validate_uploaded_file( $file, $field ) {
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['size'], $file['tmp_name'], $file['name'] ) ) {
			return new WP_Error( 'missing_file', __( 'فایلی دریافت نشد.', 'didar' ) );
		}
		foreach ( array( 'error', 'size', 'tmp_name', 'name' ) as $key ) {
			if ( is_array( $file[ $key ] ) || is_object( $file[ $key ] ) ) {
				return new WP_Error( 'invalid_file_structure', __( 'ساختار فایل دریافتی معتبر نیست.', 'didar' ) );
			}
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload_error', __( 'بارگذاری فایل با خطا روبه‌رو شد.', 'didar' ) );
		}
		$max_size = isset( $field['max_size'] ) ? absint( $field['max_size'] ) : 5 * MB_IN_BYTES;
		$max_size = min( $max_size, wp_max_upload_size() );
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max_size ) {
			return new WP_Error( 'file_size', __( 'حجم فایل بیش از حد مجاز است.', 'didar' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'بارگذاری فایل با خطا روبه‌رو شد.', 'didar' ) );
		}

		$allowed_mimes = isset( $field['upload_mimes'] ) ? (array) $field['upload_mimes'] : array();
		$checked       = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || ! in_array( $checked['type'], array_values( $allowed_mimes ), true ) ) {
			return new WP_Error( 'file_type', __( 'نوع یا پسوند فایل مجاز نیست.', 'didar' ) );
		}

		$original_name = sanitize_text_field( wp_basename( wp_unslash( $file['name'] ) ) );
		$original_name = str_replace( array( "\0", "\r", "\n" ), '', $original_name );
		$original_name = function_exists( 'mb_substr' ) ? mb_substr( $original_name, 0, 240 ) : substr( $original_name, 0, 240 );
		if ( ! $original_name ) {
			$original_name = 'didar-file.' . $checked['ext'];
		}
		return array(
			'original_name' => $original_name,
			'extension'     => strtolower( $checked['ext'] ),
			'mime_type'     => $checked['type'],
			'allowed_mimes' => $allowed_mimes,
		);
	}

	private function get_file_field( $form_type, $field_key ) {
		$fields = $this->registry->fields( sanitize_key( $form_type ) );
		return isset( $fields[ $field_key ] ) && 'file' === $fields[ $field_key ]['type'] ? $fields[ $field_key ] : null;
	}

	private function can_edit_submission( $submission_id, $form_type ) {
		$post = get_post( absint( $submission_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || (string) get_post_meta( $post->ID, '_didar_form_type', true ) !== $form_type ) {
			return false;
		}
		return Didar_Access_Control::can_edit_request( $post->ID ) || ( $this->submission_service && $this->submission_service->is_owner_editable( $post->ID, get_current_user_id() ) );
	}

	private function count_context_files( $owner_user_id, $form_type, $field_key, $submission_id ) {
		global $wpdb;
		$table = self::table_name();
		$temp  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_user_id = %d AND form_type = %s AND field_key = %s AND submission_id = %d AND file_status = %s",
				absint( $owner_user_id ),
				$form_type,
				$field_key,
				absint( $submission_id ),
				'temporary'
			)
		);
		$final = 0;
		if ( $submission_id ) {
			$final = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE submission_id = %d AND field_key = %s AND file_status = %s",
					absint( $submission_id ),
					$field_key,
					'final'
				)
			);
		}
		return $temp + $final;
	}

	private function delete_final_file( $file_id, $submission_id, $field_key ) {
		$record = $this->get( $file_id );
		if ( ! $record || 'final' !== $record['file_status'] || $record['submission_id'] !== absint( $submission_id ) || $record['field_key'] !== $field_key ) {
			return new WP_Error( 'invalid_document', __( 'فایل معتبر نیست.', 'didar' ) );
		}
		return $this->delete_file_record( $file_id );
	}

	private function delete_file_record( $file_id ) {
		global $wpdb;
		$record = $this->get( $file_id );
		if ( ! $record ) {
			return new WP_Error( 'invalid_document', __( 'فایل معتبر نیست.', 'didar' ) );
		}
		$path = $this->absolute_path( $record );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
			clearstatcache( true, $path );
			if ( file_exists( $path ) ) {
				return new WP_Error( 'file_delete_failed', __( 'حذف فایل انجام نشد.', 'didar' ) );
			}
		}
		$deleted = $wpdb->delete( self::table_name(), array( 'file_id' => absint( $file_id ) ), array( '%d' ) );
		return false !== $deleted ? true : new WP_Error( 'file_record_delete_failed', __( 'حذف اطلاعات فایل انجام نشد.', 'didar' ) );
	}

	private function ensure_storage() {
		$protected = $this->sync_storage_protection();
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		$storage = $this->storage_info();
		$path    = $storage['basedir'] . '/' . self::STORAGE_DIRECTORY . '/' . gmdate( 'Y/m' );
		return wp_mkdir_p( $path ) ? $path : new WP_Error( 'storage_unavailable', __( 'مسیر ذخیره‌سازی فایل‌های دیدار در دسترس نیست.', 'didar' ) );
	}

	private function ensure_schema_available() {
		if (
			self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION ) &&
			self::SCHEMA_VERSION === get_option( self::SCHEMA_VERIFIED_OPTION ) &&
			self::schema_is_current()
		) {
			return true;
		}

		return self::install_schema();
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

	private function storage_info() {
		$uploads = wp_upload_dir();
		return array(
			'basedir' => untrailingslashit( $uploads['basedir'] ),
			'baseurl' => untrailingslashit( $uploads['baseurl'] ),
		);
	}

	private function relative_path_from_absolute( $absolute_path ) {
		$storage = $this->storage_info();
		$base    = trailingslashit( wp_normalize_path( $storage['basedir'] ) );
		$path    = wp_normalize_path( $absolute_path );
		if ( 0 !== strpos( $path, $base ) ) {
			return new WP_Error( 'invalid_storage_path', __( 'مسیر فایل معتبر نیست.', 'didar' ) );
		}
		$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( 0 !== strpos( $relative, self::STORAGE_DIRECTORY . '/' ) || false !== strpos( $relative, '../' ) ) {
			return new WP_Error( 'invalid_storage_path', __( 'مسیر فایل معتبر نیست.', 'didar' ) );
		}
		return $relative;
	}

	private function absolute_path( $record ) {
		$relative = isset( $record['relative_path'] ) ? wp_normalize_path( (string) $record['relative_path'] ) : '';
		if ( ! $relative || 0 !== strpos( $relative, self::STORAGE_DIRECTORY . '/' ) || false !== strpos( $relative, '../' ) || preg_match( '#^[A-Za-z]:/#', $relative ) ) {
			return new WP_Error( 'invalid_storage_path', __( 'مسیر فایل معتبر نیست.', 'didar' ) );
		}
		$storage = $this->storage_info();
		$root    = trailingslashit( wp_normalize_path( $storage['basedir'] . '/' . self::STORAGE_DIRECTORY ) );
		$path    = wp_normalize_path( $storage['basedir'] . '/' . $relative );
		if ( 0 !== strpos( $path, $root ) ) {
			return new WP_Error( 'invalid_storage_path', __( 'مسیر فایل معتبر نیست.', 'didar' ) );
		}
		return $path;
	}

	private function direct_url( $record ) {
		$relative = isset( $record['relative_path'] ) ? wp_normalize_path( $record['relative_path'] ) : '';
		if ( ! $relative || false !== strpos( $relative, '../' ) || 0 !== strpos( $relative, self::STORAGE_DIRECTORY . '/' ) ) {
			return '';
		}
		$storage = $this->storage_info();
		$parts   = array_map( 'rawurlencode', explode( '/', $relative ) );
		return $storage['baseurl'] . '/' . implode( '/', $parts );
	}

	private function generate_stored_name( $extension ) {
		try {
			$random = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $exception ) {
			$random = str_replace( '-', '', wp_generate_uuid4() ) . wp_rand( 100000, 999999 );
		}
		return $random . '.' . strtolower( sanitize_key( $extension ) );
	}

	private function allowed_extensions( $mimes ) {
		$extensions = array();
		foreach ( array_keys( $mimes ) as $extension_group ) {
			foreach ( explode( '|', $extension_group ) as $extension ) {
				$extensions[] = strtolower( $extension );
			}
		}
		return array_values( array_unique( $extensions ) );
	}

	private function normalize_ids( $value ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
	}
}
