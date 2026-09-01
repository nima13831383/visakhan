<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Portable, versioned ns-didar settings import/export. Never handles credentials or runtime data. */
class Didar_Settings_Transfer {
	const FORMAT = 'ns-didar-settings';
	const SCHEMA_VERSION = 1;
	const MAX_BYTES = 1048576;
	const BACKUPS_OPTION = 'didar_settings_import_backups';
	const PREVIEW_PREFIX = 'didar_settings_import_preview_';

	private $registry;
	private $settings;
	private $workflow;
	private $logger;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->logger = $logger ? $logger : new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $settings, $this->logger );
	}

	private function portable_option_keys() {
		return array( 'visa_companion_case_settings', 'didar_form_workflows', 'didar_field_mappings', 'didar_form_field_placeholders', 'didar_broker_user_map', 'didar_form_default_assignees', 'didar_default_owner_id', 'didar_default_pipeline_id', 'didar_system_form_type_field_id', 'didar_system_submission_id_field_id', 'didar_public_status_field_id', 'field_required_overrides', 'profile_field_states', 'didar_user_person_mappings', 'colleague_can_view_internal_history', 'frontend_requests_per_page', 'file_download_mode', 'didar_debug_logging' );
	}

	/** Runtime-shaped portable values; unlike export, user maps remain keyed by local WP user ID. */
	private function portable_runtime_settings( $source ) {
		$source = is_array( $source ) ? $source : array();
		$out = array();
		$out['didar_form_field_defaults'] = isset( $source['didar_form_field_defaults'] ) && is_array( $source['didar_form_field_defaults'] ) ? $source['didar_form_field_defaults'] : array();
		foreach ( $this->portable_option_keys() as $key ) { if ( array_key_exists( $key, $source ) ) { $out[ $key ] = $source[ $key ]; } }
		return $out;
	}

	/** Explicit allowlist: add a key here only after deciding it is portable and non-secret. */
	public function portable_settings( $source = null ) {
		$source = is_array( $source ) ? $source : $this->settings->all();
		$out = $this->portable_runtime_settings( $source );
		// New installs have no persisted profile settings until an administrator
		// saves the page. Export their safe defaults explicitly so the feature is
		// portable from the first export, rather than silently omitting it.
		$out['profile_field_states'] = isset( $out['profile_field_states'] ) && is_array( $out['profile_field_states'] ) ? $out['profile_field_states'] : Didar_Settings::PROFILE_FIELD_STATES;
		$out['didar_user_person_mappings'] = isset( $out['didar_user_person_mappings'] ) && is_array( $out['didar_user_person_mappings'] ) ? $out['didar_user_person_mappings'] : array();
		$out['didar_broker_user_map'] = $this->export_user_mappings( $out['didar_broker_user_map'] ?? array() );
		$out['didar_form_default_assignees'] = $this->export_form_default_assignees( $out['didar_form_default_assignees'] ?? array() );
		$out['didar_form_field_placeholders'] = $this->normalize_placeholders( $out['didar_form_field_placeholders'] ?? array() );
		if ( isset( $out['didar_field_mappings'] ) && is_array( $out['didar_field_mappings'] ) ) {
			$normalized_mappings = array();
			foreach ( $out['didar_field_mappings'] as $form_type => $maps ) {
				$form_key = sanitize_key( (string) $form_type );
				if ( ! is_array( $maps ) ) { $warnings[] = 'ساختار نگاشت فیلدهای فرم «' . $form_key . '» معتبر نیست.'; continue; }
				foreach ( $maps as $field_key => $raw_map ) {
					if ( is_scalar( $raw_map ) ) { $map = array( 'target' => 'deal_custom', 'field' => sanitize_text_field( (string) $raw_map ) ); }
					elseif ( is_array( $raw_map ) ) { $target = $raw_map['target'] ?? ( $raw_map['type'] ?? 'deal_custom' ); $field = $raw_map['field'] ?? ( $raw_map['key'] ?? '' ); $map = array( 'target' => sanitize_key( (string) $target ), 'field' => is_scalar( $field ) ? sanitize_text_field( (string) $field ) : '' ); }
					else { continue; }
					if ( '' !== $map['target'] || '' !== $map['field'] ) { $normalized_mappings[ $form_key ][ sanitize_key( (string) $field_key ) ] = $map; }
				}
			}
			$out['didar_field_mappings'] = $normalized_mappings;
			foreach ( array( 'consultation', 'complaint_suggestion' ) as $unsupported_form ) {
				unset( $out['didar_field_mappings'][ $unsupported_form ]['applicant_note'] );
			}
		}
		return $out;
	}

	public function export_payload() {
		$payload = array( 'format' => self::FORMAT, 'schema_version' => self::SCHEMA_VERSION, 'plugin_version' => defined( 'DIDAR_VERSION' ) ? DIDAR_VERSION : '', 'exported_at' => gmdate( 'c' ), 'site' => array( 'home_url' => home_url(), 'environment_note' => 'informational only' ), 'api_credentials_included' => false, 'settings' => $this->portable_settings() );
		$this->log( 'didar_settings_exported', array( 'categories' => array_keys( $payload['settings'] ) ) );
		return $payload;
	}

	public function export_json() { return wp_json_encode( $this->export_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); }

	public function parse_json( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) { return new WP_Error( 'didar_import_empty', __( 'فایل تنظیمات خالی است.', 'didar' ) ); }
		if ( strlen( $json ) > self::MAX_BYTES ) { return new WP_Error( 'didar_import_too_large', __( 'حجم فایل تنظیمات بیش از حد مجاز است.', 'didar' ) ); }
		$data = json_decode( $json, true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) { return new WP_Error( 'didar_import_json', __( 'فایل JSON معتبر نیست.', 'didar' ) ); }
		if ( ( $data['format'] ?? '' ) !== self::FORMAT || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) { return new WP_Error( 'didar_import_format', __( 'ساختار فایل تنظیمات معتبر نیست.', 'didar' ) ); }
		if ( ! isset( $data['schema_version'] ) || ! is_numeric( $data['schema_version'] ) || (int) $data['schema_version'] !== self::SCHEMA_VERSION ) { return new WP_Error( 'didar_import_schema', __( 'نسخه فایل تنظیمات توسط این نسخه افزونه پشتیبانی نمی‌شود.', 'didar' ) ); }
		return $data;
	}

	public function preview( $data, $mode = 'merge' ) {
		if ( ! is_array( $data ) || ! isset( $data['settings'] ) ) { return new WP_Error( 'didar_import_format', __( 'ساختار فایل تنظیمات معتبر نیست.', 'didar' ) ); }
		$mode = 'replace' === $mode ? 'replace' : 'merge';
		$warnings = array(); $errors = array(); $not_verified = array();
		$trace = array( 'parsed' => $this->mapping_trace( $data['settings'] ) );
		$incoming = $this->normalize( $data['settings'], $warnings, $errors );
		$trace['schema_normalized'] = $this->mapping_trace( $incoming );
		$incoming = $this->preserve_unknown_mapping_forms( $incoming, $data['settings'], $warnings );
		$incoming = $this->normalize_mapping_shapes( $incoming, $warnings );
		$trace['preview_normalized'] = $this->mapping_trace( $incoming );
		// Diff against the actual option so exported WP-user descriptors are compared
		// with their canonical local numeric-key map, not with a different wire shape.
		$current = $this->settings->all();
		$proposed = 'replace' === $mode ? array_replace( $this->portable_defaults(), $incoming ) : array_replace( $current, $incoming );
		if ( array_key_exists( 'didar_broker_user_map', $incoming ) ) {
			$resolved = $this->resolve_user_mappings( $incoming['didar_broker_user_map'] );
			$proposed['didar_broker_user_map'] = $resolved['mappings'];
			$warnings = array_merge( $warnings, $resolved['warnings'] );
		}
		if ( array_key_exists( 'didar_form_default_assignees', $incoming ) ) {
			$resolved = $this->resolve_form_default_assignees( $incoming['didar_form_default_assignees'] );
			$proposed['didar_form_default_assignees'] = array_replace( (array) ( $proposed['didar_form_default_assignees'] ?? array() ), $resolved['mappings'] );
			$warnings = array_merge( $warnings, $resolved['warnings'] );
		}
		$this->validate( $incoming, $proposed, $warnings, $errors, $not_verified );
		// Validation is advisory: it annotates portable values and never removes them.
		$trace['metadata_validated'] = $this->mapping_trace( $incoming );
		$trace['merge_or_replace'] = $this->mapping_trace( $proposed );
		$trace['settings_sanitization'] = array_merge( $trace['merge_or_replace'], array( 'status' => 'not_invoked' ) );
		$this->metadata_status( $not_verified );
		$diff = $this->diff( $current, $proposed );
		$this->log( 'didar_settings_import_previewed', array( 'mode' => $mode, 'warnings' => count( $warnings ), 'errors' => count( $errors ), 'categories' => array_keys( $incoming ) ) );
		return array( 'mode' => $mode, 'incoming' => $incoming, 'proposed' => $proposed, 'warnings' => array_values( array_unique( $warnings ) ), 'errors' => array_values( array_unique( $errors ) ), 'not_verified' => array_values( array_unique( $not_verified ) ), 'diff' => $diff, 'summary' => $this->summary( $proposed ), 'trace' => $trace );
	}

	public function apply( $preview ) {
		if ( ! is_array( $preview ) || ! empty( $preview['errors'] ) || ! isset( $preview['proposed'] ) ) { return new WP_Error( 'didar_import_invalid', __( 'تنظیمات واردشده معتبر نیست و اعمال نشد.', 'didar' ) ); }
		$old = $this->settings->all(); $backup_id = $this->create_backup();
		$new = array_replace( $old, $preview['proposed'] );
		$trace = isset( $preview['trace'] ) && is_array( $preview['trace'] ) ? $preview['trace'] : array();
		$trace['update_option_input'] = $this->mapping_trace( $new );
		$trace['expected_before_update'] = $this->portable_settings_summaries( $new );
		$update_result = update_option( Didar_Settings::OPTION_NAME, $new, false );
		$trace['update_option_result'] = (bool) $update_result;
		$stored = get_option( Didar_Settings::OPTION_NAME, array() );
		$trace['database_after_apply'] = $this->mapping_trace( $stored );
		$verification = $this->verify_portable_settings( $this->portable_runtime_settings( $new ), $this->portable_runtime_settings( $stored ) );
		$trace['post_write_verification'] = $verification['results'];
		if ( ! $verification['verified'] ) {
			$failure = $verification['first_mismatch'];
			$this->logger->log( 'ERROR', 'didar_settings_import_verification_failed', 'Portable settings differed after import write; rollback initiated.', array( 'mode' => $preview['mode'], 'backup_id' => $backup_id, 'update_option_result' => (bool) $update_result, 'mismatch' => $failure, 'mapping_trace' => $trace ) );
			update_option( Didar_Settings::OPTION_NAME, $old, false );
			return new WP_Error( 'didar_import_verify', sprintf( __( 'تأیید ذخیره گزینه %s ناموفق بود و تنظیمات قبلی بازگردانده شد.', 'didar' ), $failure['option'] ), $failure );
		}
		$this->log( 'didar_settings_import_succeeded', array( 'mode' => $preview['mode'], 'backup_id' => $backup_id, 'categories' => array_keys( $this->portable_runtime_settings( $new ) ), 'update_option_result' => (bool) $update_result, 'mapping_trace' => $trace, 'verification' => $verification['results'] ) );
		return array( 'backup_id' => $backup_id, 'summary' => $this->summary( $new ), 'warnings' => $preview['warnings'], 'trace' => $trace, 'verification' => $verification );
	}

	public function create_backup() {
		$backups = get_option( self::BACKUPS_OPTION, array() ); $backups = is_array( $backups ) ? $backups : array();
		$id = wp_generate_uuid4(); $backups[] = array( 'id' => $id, 'created_at' => gmdate( 'c' ), 'actor_user_id' => get_current_user_id(), 'settings' => $this->portable_settings() );
		update_option( self::BACKUPS_OPTION, array_slice( $backups, -5 ), false );
		$this->log( 'didar_settings_import_backup_created', array( 'backup_id' => $id ) ); return $id;
	}
	public function latest_backup() { $all = get_option( self::BACKUPS_OPTION, array() ); return is_array( $all ) && $all ? end( $all ) : array(); }

	private function normalize( $raw, &$warnings, &$errors ) {
		$out = array(); $allowed = array_keys( $this->portable_settings( array_fill_keys( array( 'visa_companion_case_settings','didar_form_workflows','didar_field_mappings','didar_form_field_placeholders','didar_broker_user_map','didar_form_default_assignees','didar_default_owner_id','didar_default_pipeline_id','didar_system_form_type_field_id','didar_system_submission_id_field_id','didar_system_user_id_field_id','didar_public_status_field_id','field_required_overrides','profile_field_states','didar_user_person_mappings','colleague_can_view_internal_history','frontend_requests_per_page','file_download_mode','didar_debug_logging' ), null ) ) );
		foreach ( (array) $raw as $key => $value ) { if ( in_array( $key, $allowed, true ) ) { $out[$key] = $value; } else { $warnings[] = 'گزینه ناشناخته «' . sanitize_text_field( (string) $key ) . '» نادیده گرفته شد.'; } }
		foreach ( array( 'didar_form_workflows','didar_field_mappings','didar_form_field_placeholders','field_required_overrides','profile_field_states','didar_user_person_mappings' ) as $key ) { if ( isset( $out[$key] ) && ! is_array( $out[$key] ) ) { $errors[] = 'ساختار «' . $key . '» معتبر نیست.'; unset( $out[$key] ); } }
		if ( isset( $out['didar_form_field_placeholders'] ) ) { $out['didar_form_field_placeholders'] = $this->normalize_placeholders( $out['didar_form_field_placeholders'] ); }
		if ( isset( $out['visa_companion_case_settings'] ) && ! is_array( $out['visa_companion_case_settings'] ) ) { $errors[] = 'Visa companion Case settings structure is invalid.'; unset( $out['visa_companion_case_settings'] ); }
		foreach ( array( 'didar_form_workflows', 'didar_field_mappings', 'field_required_overrides' ) as $key ) {
			if ( empty( $out[ $key ] ) ) { continue; }
			foreach ( $out[ $key ] as $form_type => $value ) {
				if ( ! $this->registry->get( sanitize_key( $form_type ) ) ) { unset( $out[ $key ][ $form_type ] ); $warnings[] = 'نوع فرم ناشناخته «' . sanitize_key( $form_type ) . '» اعمال نمی‌شود.'; }
			}
		}
		if ( isset( $out['didar_broker_user_map'] ) && ! is_array( $out['didar_broker_user_map'] ) ) { $errors[] = 'ساختار نگاشت کاربران دیدار معتبر نیست.'; unset( $out['didar_broker_user_map'] ); }
		return $out;
	}

	private function normalize_mapping_shapes( $out, &$warnings ) {
		if ( empty( $out['didar_field_mappings'] ) || ! is_array( $out['didar_field_mappings'] ) ) { return $out; }
		$normalized = array();
		foreach ( $out['didar_field_mappings'] as $form_type => $maps ) {
			if ( ! is_array( $maps ) ) { $warnings[] = 'ساختار نگاشت فیلدهای فرم معتبر نیست.'; continue; }
			$form_key = sanitize_key( (string) $form_type );
			foreach ( $maps as $field_key => $raw_map ) {
				if ( is_scalar( $raw_map ) ) { $map = array( 'target' => 'deal_custom', 'field' => sanitize_text_field( (string) $raw_map ) ); }
				elseif ( is_array( $raw_map ) ) { $target = $raw_map['target'] ?? ( $raw_map['type'] ?? 'deal_custom' ); $field = $raw_map['field'] ?? ( $raw_map['key'] ?? '' ); $map = array( 'target' => sanitize_key( (string) $target ), 'field' => is_scalar( $field ) ? sanitize_text_field( (string) $field ) : '' ); }
				else { continue; }
				if ( '' !== $map['target'] || '' !== $map['field'] ) { $normalized[ $form_key ][ sanitize_key( (string) $field_key ) ] = $map; }
			}
		}
		$out['didar_field_mappings'] = $normalized;
		return $out;
	}

	private function preserve_unknown_mapping_forms( $normalized, $raw_settings, &$warnings ) {
		$raw_maps = isset( $raw_settings['didar_field_mappings'] ) && is_array( $raw_settings['didar_field_mappings'] ) ? $raw_settings['didar_field_mappings'] : array();
		foreach ( $raw_maps as $form_type => $maps ) {
			$form_key = sanitize_key( (string) $form_type );
			if ( ! $this->registry->get( $form_key ) && is_array( $maps ) ) { $normalized['didar_field_mappings'][ $form_key ] = $maps; $warnings[] = 'نوع فرم ناشناخته «' . $form_key . '» است؛ نگاشت آن حفظ شد اما در این سایت نمایش داده نمی‌شود.'; }
		}
		return $normalized;
	}

	private function validate( $incoming, $proposed, &$warnings, &$errors, &$not_verified ) {
		foreach ( (array) ( $incoming['didar_form_workflows'] ?? array() ) as $form_type => $workflow ) {
			if ( ! $this->registry->get( $form_type ) ) { $warnings[] = 'نوع فرم ناشناخته «' . sanitize_key( $form_type ) . '» اعمال نمی‌شود.'; continue; }
			if ( ! is_array( $workflow ) || empty( $workflow['pipeline_id'] ) || empty( $workflow['statuses'] ) || ! is_array( $workflow['statuses'] ) ) { $errors[] = 'گردش کار فرم «' . sanitize_key( $form_type ) . '» ناقص است.'; continue; }
			$pipeline = $this->workflow->pipeline( $workflow['pipeline_id'] );
			if ( $this->workflow->pipelines() && ! $pipeline ) { $errors[] = 'کاریز فرم «' . sanitize_key( $form_type ) . '» در اطلاعات دیدار یافت نشد.'; continue; }
			$defaults = 0; $keys = array(); $stage_ids = $pipeline ? wp_list_pluck( $pipeline['stages'], 'id' ) : array();
			foreach ( $workflow['statuses'] as $key => $status ) { $key = sanitize_key( is_array( $status ) && isset( $status['key'] ) ? $status['key'] : $key ); if ( ! $key || isset( $keys[$key] ) ) { $errors[] = 'کلید وضعیت تکراری یا نامعتبر است.'; } $keys[$key]=true; $defaults += ! empty( $status['is_default'] ); if ( empty( $status['stage_id'] ) ) { $errors[] = 'نگاشت مرحله وضعیت ناقص است.'; } elseif ( $pipeline && ! in_array( $status['stage_id'], $stage_ids, true ) ) { $errors[] = 'مرحله انتخاب‌شده به کاریز این فرم تعلق ندارد.'; } }
			if ( 1 !== $defaults ) { $errors[] = 'هر فرم باید دقیقاً یک وضعیت پیش‌فرض داشته باشد.'; }
		}
		$fields = $this->workflow->custom_fields();
		if ( $fields ) {
			$all_mappings = (array) ( $incoming['didar_field_mappings'] ?? array() );
			$all_mappings['_system'] = array(
				'a' => array( 'field' => $incoming['didar_system_form_type_field_id'] ?? '' ),
				'b' => array( 'field' => $incoming['didar_system_submission_id_field_id'] ?? '' ),
				'c' => array( 'field' => $incoming['didar_system_user_id_field_id'] ?? '' ),
				'd' => array( 'field' => $incoming['didar_public_status_field_id'] ?? '' ),
			);
			foreach ( $all_mappings as $mapping_form_type => $maps ) {
				foreach ( (array) $maps as $map ) {
					$key = is_array( $map ) ? ( $map['field'] ?? '' ) : '';
					if ( ! $key ) { continue; }
					$target = is_array( $map ) ? sanitize_key( $map['target'] ?? 'deal_custom' ) : 'deal_custom';
					// Native Person/Deal fields are not entries in the Deal custom-field catalog.
					if ( in_array( $target, array( 'person_native', 'deal_native', 'person_custom' ), true ) ) { continue; }
					$field = $this->workflow->custom_field( $key );
					if ( ! empty( $field['unverified'] ) ) { $not_verified[] = 'کاستوم‌فیلد «' . sanitize_text_field( $key ) . '» در اطلاعات فعلی دیدار یافت نشد؛ مقدار ذخیره می‌شود و بعداً باید بررسی شود.'; continue; }
					if ( ! Didar_Custom_Field_Catalog::is_deal_field( $field ) ) { $warnings[] = 'کاستوم‌فیلد «' . sanitize_text_field( $key ) . '» در فهرست فعلی دیدار معتبر نیست.'; continue; }
					$pipeline_ids = '_system' === $mapping_form_type ? array() : array( $incoming['didar_form_workflows'][ $mapping_form_type ]['pipeline_id'] ?? '' );
					if ( '_system' === $mapping_form_type ) { foreach ( (array) ( $incoming['didar_form_workflows'] ?? array() ) as $workflow ) { if ( ! empty( $workflow['pipeline_id'] ) ) { $pipeline_ids[] = $workflow['pipeline_id']; } } }
					foreach ( array_unique( array_filter( $pipeline_ids ) ) as $pipeline_id ) { if ( ! $this->workflow->custom_field_available_for_pipeline( $field, $pipeline_id ) ) { $warnings[] = 'کاستوم‌فیلد «' . sanitize_text_field( $key ) . '» برای کاریز انتخاب‌شده در دسترس نیست.'; break; } }
				}
			}
		}
		if ( ! $fields ) { $not_verified[] = 'اعتبار نگاشت کاستوم‌فیلدها با اطلاعات فعلی دیدار قابل بررسی نیست.'; }
		if ( ! $this->workflow->didar_users() ) { $not_verified[] = 'اعتبار UserIdهای دیدار با اطلاعات فعلی دیدار قابل بررسی نیست.'; }
		elseif ( ! empty( $incoming['didar_default_owner_id'] ) ) {
			$owner = $this->workflow->didar_user_by_user_id( $incoming['didar_default_owner_id'] );
			if ( ! $owner ) { $warnings[] = 'UserId مسئول پیش‌فرض در فهرست دیدار یافت نشد.'; }
			elseif ( ! empty( $owner['is_disabled'] ) ) { $warnings[] = 'کاربر دیدارِ مسئول پیش‌فرض غیرفعال است.'; }
		}
	}

	private function metadata_status( &$not_verified ) {
		$now = time();
		$caches = array(
			'pipelines' => $this->workflow->cache_info(),
			'کاستوم‌فیلدها' => $this->workflow->custom_field_cache_info(),
			'کاربران دیدار' => $this->workflow->didar_user_cache_info(),
		);
		foreach ( $caches as $label => $cache ) {
			$stamp = ! empty( $cache['refreshed_at_gmt'] ) ? strtotime( (string) $cache['refreshed_at_gmt'] . ' UTC' ) : false;
			if ( ! $stamp || ( $now - $stamp ) > DAY_IN_SECONDS ) { $not_verified[] = 'اطلاعات ' . $label . ' قدیمی یا در دسترس نیست؛ اعتبارسنجی دقیق این بخش انجام نشد.'; }
		}
	}

	private function export_user_mappings( $map ) { $out=array(); foreach((array)$map as $wp_id=>$didar_id){ $u=get_user_by('id',absint($wp_id)); if($u){$out[]=array('wordpress_user_id'=>absint($wp_id),'wordpress_user_login'=>$u->user_login,'wordpress_user_email'=>$u->user_email,'didar_user_id'=>sanitize_text_field((string)$didar_id));} } return $out; }
	private function export_form_default_assignees( $map ) { $out=array(); foreach((array)$map as $form_type=>$wp_id){ $form_type=sanitize_key((string)$form_type); $u=get_user_by('id',absint($wp_id)); if($form_type && $u){$out[]=array('form_type'=>$form_type,'wordpress_user_id'=>absint($u->ID),'wordpress_user_login'=>$u->user_login,'wordpress_user_email'=>$u->user_email);} } return $out; }
	private function resolve_form_default_assignees( $items ) { $out=array(); $warnings=array(); foreach((array)$items as $item){ if(!is_array($item))continue; $form=sanitize_key($item['form_type']??''); if(!$form||!$this->registry->get($form)){ $warnings[]='مسئول پیش‌فرض برای فرم ناشناخته نادیده گرفته شد.'; continue; } $login=sanitize_user($item['wordpress_user_login']??'',true); $email=sanitize_email($item['wordpress_user_email']??''); $u=$login?get_user_by('login',$login):false; if(!$u&&$email)$u=get_user_by('email',$email); if(!$u||!user_can($u,'didar_receive_requests')){ $warnings[]='مسئول پیش‌فرض فرم '.$form.' در این سایت پیدا نشد یا مجاز نیست؛ تنظیم نشد.'; continue; } $out[$form]=(int)$u->ID; } return array('mappings'=>$out,'warnings'=>$warnings); }
	private function resolve_user_mappings( $items ) { $out=array(); $warnings=array(); foreach((array)$items as $item){ if(!is_array($item)) continue; $login=sanitize_user($item['wordpress_user_login']??'',true); $email=sanitize_email($item['wordpress_user_email']??''); $user=$login?get_user_by('login',$login):false; if(!$user && $email) $user=get_user_by('email',$email); if(!$user){$warnings[]='کاربر WordPress برای نگاشت دیدار یافت نشد؛ نگاشت رد شد.';continue;} $didar=sanitize_text_field((string)($item['didar_user_id']??'')); if(!$didar)continue; $catalog=$this->workflow->didar_users(); if($catalog && (!$this->workflow->didar_user_by_user_id($didar) || !empty($this->workflow->didar_user_by_user_id($didar)['is_disabled']))) $warnings[]='UserId دیدار «'.$didar.'» معتبر یا فعال نیست.'; $out[$user->ID]=$didar; } return array('mappings'=>$out,'warnings'=>$warnings); }
	private function portable_defaults() { return array( 'didar_form_workflows'=>array(),'didar_field_mappings'=>array(),'didar_form_field_placeholders'=>array(),'didar_broker_user_map'=>array(),'didar_form_default_assignees'=>array(),'didar_default_owner_id'=>'','didar_default_pipeline_id'=>'','didar_system_form_type_field_id'=>'','didar_system_submission_id_field_id'=>'','didar_system_user_id_field_id'=>'','didar_public_status_field_id'=>'','field_required_overrides'=>array(),'profile_field_states'=>Didar_Settings::PROFILE_FIELD_STATES,'didar_user_person_mappings'=>array(),'colleague_can_view_internal_history'=>0,'frontend_requests_per_page'=>Didar_Settings::DEFAULT_REQUESTS_PER_PAGE,'file_download_mode'=>Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE,'didar_debug_logging'=>'off' ); }

	private function normalize_placeholders( $value ) {
		$out = array();
		foreach ( (array) $value as $form_type => $fields ) {
			$form_type = sanitize_key( (string) $form_type );
			if ( ! $form_type || ! $this->registry->get( $form_type ) || ! is_array( $fields ) ) { continue; }
			$definitions = $this->registry->didar_mapping_fields( $form_type );
			foreach ( $fields as $field_key => $placeholder ) {
				$field_key = sanitize_key( (string) $field_key );
				if ( ! isset( $definitions[ $field_key ] ) || ! Didar_Form_Registry::supports_placeholder( $definitions[ $field_key ] ) || ! is_scalar( $placeholder ) ) { continue; }
				$placeholder = sanitize_text_field( (string) $placeholder );
				if ( '' !== $placeholder ) { $out[ $form_type ][ $field_key ] = $placeholder; }
			}
		}
		return $out;
	}

	/**
	 * Produces the runtime semantic form used by post-write verification. Export
	 * transport shapes (notably broker descriptors) must never be compared here.
	 */
	public function canonicalize_portable_option( $option_name, $value ) {
		 switch ( $option_name ) {
			case 'didar_form_field_placeholders': return $this->normalize_placeholders( $value );
			case 'didar_form_field_defaults':
				$out = array(); $catalog = new Didar_User_Profile_Value_Catalog();
				foreach ( (array) $value as $form_type => $fields ) { foreach ( (array) $fields as $field_key => $source ) { $source = sanitize_key( (string) $source ); if ( $source && in_array( $source, $catalog->keys(), true ) ) { $out[ sanitize_key( $form_type ) ][ sanitize_key( $field_key ) ] = $source; } } }
				return $this->sort_associative( $out );
			case 'didar_field_mappings':
				$out = array();
				foreach ( (array) $value as $form_type => $maps ) {
					$form_type = sanitize_key( (string) $form_type );
					foreach ( (array) $maps as $field_key => $map ) {
						if ( is_scalar( $map ) ) { $target = 'deal_custom'; $field = $map; }
						elseif ( is_array( $map ) ) { $target = $map['target'] ?? ( $map['type'] ?? 'deal_custom' ); $field = $map['field'] ?? ( $map['key'] ?? '' ); }
						else { continue; }
						$target = sanitize_key( (string) $target ); $field = is_scalar( $field ) ? sanitize_text_field( (string) $field ) : '';
						if ( $form_type && $field ) { $out[ $form_type ][ sanitize_key( (string) $field_key ) ] = array( 'target' => $target, 'field' => $field ); }
					}
				}
				return $this->sort_associative( $out );

			case 'didar_broker_user_map':
				$out = array();
				foreach ( (array) $value as $wp_user_id => $didar_user_id ) {
					if ( is_array( $didar_user_id ) && isset( $didar_user_id['didar_user_id'] ) ) {
						$resolved = $this->resolve_user_mappings( array( $didar_user_id ) ); $out = array_replace( $out, $resolved['mappings'] ); continue;
					}
					$wp_user_id = absint( $wp_user_id ); $didar_user_id = is_scalar( $didar_user_id ) ? sanitize_text_field( (string) $didar_user_id ) : '';
					if ( $wp_user_id && $didar_user_id ) { $out[ $wp_user_id ] = $didar_user_id; }
				}
				ksort( $out, SORT_NUMERIC ); return $out;

			case 'didar_form_default_assignees':
				$out = array(); foreach ( (array) $value as $form_type => $user_id ) { $form_type = sanitize_key( (string) $form_type ); $user_id = absint( $user_id ); if ( $form_type && $user_id ) { $out[ $form_type ] = $user_id; } } ksort( $out, SORT_STRING ); return $out;

			case 'didar_form_workflows':
				$out = array();
				foreach ( (array) $value as $form_type => $workflow ) {
					$form_type = sanitize_key( (string) $form_type ); if ( ! $form_type || ! is_array( $workflow ) ) { continue; }
					$out[ $form_type ] = array( 'pipeline_id' => sanitize_text_field( (string) ( $workflow['pipeline_id'] ?? '' ) ), 'statuses' => array() );
					foreach ( (array) ( $workflow['statuses'] ?? array() ) as $status_key => $status ) {
						$status = is_array( $status ) ? $status : array(); $status_key = sanitize_key( (string) ( $status['key'] ?? $status_key ) ); if ( ! $status_key ) { continue; }
						$out[ $form_type ]['statuses'][ $status_key ] = array( 'label' => sanitize_text_field( (string) ( $status['label'] ?? $status_key ) ), 'stage_id' => sanitize_text_field( (string) ( $status['stage_id'] ?? '' ) ), 'is_default' => ! empty( $status['is_default'] ), 'order' => absint( $status['order'] ?? 0 ) );
					}
				}
				return $this->sort_associative( $out );

			case 'field_required_overrides':
				$out = array(); foreach ( (array) $value as $form_type => $fields ) { foreach ( (array) $fields as $field_key => $state ) { $out[ sanitize_key( (string) $form_type ) ][ sanitize_key( (string) $field_key ) ] = (bool) $state; } } return $this->sort_associative( $out );

			case 'profile_field_states':
				$out = Didar_Settings::PROFILE_FIELD_STATES; foreach ( (array) $value as $field => $state ) { $field = sanitize_key( (string) $field ); $state = sanitize_key( (string) $state ); if ( isset( $out[ $field ] ) && in_array( $state, array( 'editable', 'readonly', 'disabled' ), true ) ) { $out[ $field ] = $state; } } return $this->sort_associative( $out );

			case 'didar_user_person_mappings':
				$out = array(); foreach ( (array) $value as $property => $field_key ) { $property = sanitize_key( (string) $property ); $field_key = is_scalar( $field_key ) ? sanitize_text_field( (string) $field_key ) : ''; if ( in_array( $property, array( 'gender', 'display_name', 'profile_image_url' ), true ) && $field_key ) { $out[ $property ] = $field_key; } } return $this->sort_associative( $out );

			case 'colleague_can_view_internal_history': return ! empty( $value ) ? 1 : 0;
			case 'frontend_requests_per_page': return min( Didar_Settings::MAX_REQUESTS_PER_PAGE, max( Didar_Settings::MIN_REQUESTS_PER_PAGE, absint( $value ) ) );
			case 'file_download_mode': $mode = sanitize_key( (string) $value ); return in_array( $mode, array( 'secure', 'direct' ), true ) ? $mode : Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE;
			case 'didar_debug_logging': $mode = sanitize_key( (string) $value ); return in_array( $mode, array( 'off', 'errors', 'verbose' ), true ) ? $mode : 'off';
			case 'didar_default_owner_id':
			case 'didar_default_pipeline_id':
			case 'didar_system_form_type_field_id':
			case 'didar_system_submission_id_field_id':
			case 'didar_system_user_id_field_id':
			case 'didar_public_status_field_id': return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		return $this->sort_associative( $value );
	}

	private function verify_portable_settings( $expected, $actual ) {
		$results = array(); $first = array();
		foreach ( $expected as $option => $expected_value ) {
			$expected_canonical = $this->canonicalize_portable_option( $option, $expected_value );
			$actual_exists = array_key_exists( $option, $actual ); $actual_canonical = $actual_exists ? $this->canonicalize_portable_option( $option, $actual[ $option ] ) : null;
			$mismatch = $actual_exists ? $this->first_difference( $expected_canonical, $actual_canonical, $option ) : array( 'path' => $option, 'reason' => 'missing_option' );
			$entry = array( 'option' => $option, 'expected' => $this->safe_value_summary( $expected_canonical, $option ), 'actual' => $this->safe_value_summary( $actual_canonical, $option ), 'verified' => empty( $mismatch ) );
			if ( $mismatch ) { $entry['mismatch'] = $mismatch; if ( ! $first ) { $first = $entry; } }
			$results[] = $entry;
		}
		return array( 'verified' => ! $first, 'first_mismatch' => $first, 'results' => $results );
	}

	private function portable_settings_summaries( $settings ) {
		$out = array();
		foreach ( $this->portable_runtime_settings( $settings ) as $option => $value ) { $out[ $option ] = $this->safe_value_summary( $this->canonicalize_portable_option( $option, $value ), $option ); }
		return $out;
	}

	private function first_difference( $expected, $actual, $path ) {
		if ( gettype( $expected ) !== gettype( $actual ) ) { return array( 'path' => $path, 'reason' => 'type_mismatch', 'expected_type' => gettype( $expected ), 'actual_type' => gettype( $actual ) ); }
		if ( ! is_array( $expected ) ) { return $expected === $actual ? array() : array( 'path' => $path, 'reason' => 'value_mismatch', 'expected_type' => gettype( $expected ), 'actual_type' => gettype( $actual ) ); }
		foreach ( $expected as $key => $value ) { if ( ! array_key_exists( $key, $actual ) ) { return array( 'path' => $path . '.' . $key, 'reason' => 'missing_key', 'expected_type' => gettype( $value ), 'actual_type' => 'missing' ); } $difference = $this->first_difference( $value, $actual[ $key ], $path . '.' . $key ); if ( $difference ) { return $difference; } }
		foreach ( $actual as $key => $value ) { if ( ! array_key_exists( $key, $expected ) ) { return array( 'path' => $path . '.' . $key, 'reason' => 'unexpected_key', 'expected_type' => 'missing', 'actual_type' => gettype( $value ) ); } }
		return array();
	}

	private function safe_value_summary( $value, $option = '' ) {
		if ( is_array( $value ) ) {
			$summary = array( 'type' => 'array', 'count' => count( $value ) );
			if ( 'didar_field_mappings' === $option ) { $summary['mapping_counts'] = $this->mapping_trace( array( 'didar_field_mappings' => $value ) ); }
			if ( 'didar_form_workflows' === $option ) { $summary['workflow_count'] = count( $value ); }
			if ( 'didar_broker_user_map' === $option ) { $summary['user_mapping_count'] = count( $value ); }
			return $summary;
		}
		return array( 'type' => gettype( $value ), 'value' => is_bool( $value ) ? ( $value ? true : false ) : $value );
	}

	private function sort_associative( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $key => $item ) { $value[ $key ] = $this->sort_associative( $item ); }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
		return $value;
	}
	private function diff($old,$new){$groups=array('گردش کار فرم‌ها'=>array('didar_form_workflows'),'نگاشت فیلدهای فرم'=>array('didar_field_mappings'),'کاربران دیدار'=>array('didar_broker_user_map','didar_default_owner_id'),'فیلدهای سیستمی'=>array('didar_system_form_type_field_id','didar_system_submission_id_field_id','didar_system_user_id_field_id'),'وضعیت عمومی'=>array('didar_public_status_field_id'),'فرم اطلاعات کاربری'=>array('profile_field_states','didar_user_person_mappings'),'سایر تنظیمات'=>array('field_required_overrides','colleague_can_view_internal_history','frontend_requests_per_page','file_download_mode','didar_debug_logging','didar_default_pipeline_id'));$out=array();foreach($groups as $name=>$keys){$changed=0;$same=0;foreach($keys as $k){if(!array_key_exists($k,$new))continue;if(($old[$k]??null)===$new[$k])$same++;else $changed++;}$out[$name]=array('added'=>0,'changed'=>$changed,'unchanged'=>$same,'invalid'=>0);}return $out;}
	public function summary($settings){return array('workflows'=>count((array)($settings['didar_form_workflows']??array())),'field_mappings'=>array_sum(array_map('count',(array)($settings['didar_field_mappings']??array()))),'user_mappings'=>count((array)($settings['didar_broker_user_map']??array())),'system_mappings'=>count(array_filter(array($settings['didar_system_form_type_field_id']??'',$settings['didar_system_submission_id_field_id']??'',$settings['didar_system_user_id_field_id']??''))));}
	/** Safe diagnostic counts; Field Keys and user identifiers are never logged here. */
	public function mapping_trace( $settings ) {
		$counts = array( 'total' => 0, 'deal_custom' => 0, 'person_native' => 0, 'deal_native' => 0, 'person_custom' => 0 );
		foreach ( (array) ( $settings['didar_field_mappings'] ?? array() ) as $maps ) {
			foreach ( (array) $maps as $map ) {
				if ( ! is_scalar( $map ) && ! is_array( $map ) ) { continue; }
				$target = is_array( $map ) ? sanitize_key( (string) ( $map['target'] ?? ( $map['type'] ?? 'deal_custom' ) ) ) : 'deal_custom';
				$counts['total']++;
				if ( isset( $counts[ $target ] ) ) { $counts[ $target ]++; }
			}
		}
		$counts['system_fields'] = count( array_filter( array( $settings['didar_system_form_type_field_id'] ?? '', $settings['didar_system_submission_id_field_id'] ?? '', $settings['didar_system_user_id_field_id'] ?? '' ) ) );
		$counts['user_mappings'] = count( (array) ( $settings['didar_broker_user_map'] ?? array() ) );
		return $counts;
	}
	private function log($event,$context=array()){$context['schema_version']=self::SCHEMA_VERSION;$context['actor_wp_user_id']=get_current_user_id();$this->logger->log('INFO',$event,'Didar settings transfer event.',$context);}
}
