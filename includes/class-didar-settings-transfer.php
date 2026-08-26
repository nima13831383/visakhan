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

	/** Explicit allowlist: add a key here only after deciding it is portable and non-secret. */
	public function portable_settings( $source = null ) {
		$source = is_array( $source ) ? $source : $this->settings->all();
		$keys = array( 'didar_form_workflows', 'didar_field_mappings', 'didar_broker_user_map', 'didar_default_owner_id', 'didar_default_pipeline_id', 'didar_system_form_type_field_id', 'didar_system_submission_id_field_id', 'didar_system_user_id_field_id', 'didar_public_status_field_id', 'field_required_overrides', 'colleague_can_view_internal_history', 'frontend_requests_per_page', 'file_download_mode', 'didar_debug_logging' );
		$out = array();
		foreach ( $keys as $key ) { if ( array_key_exists( $key, $source ) ) { $out[ $key ] = $source[ $key ]; } }
		$out['didar_broker_user_map'] = $this->export_user_mappings( $out['didar_broker_user_map'] ?? array() );
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
		$incoming = $this->normalize( $data['settings'], $warnings, $errors );
		$incoming = $this->preserve_unknown_mapping_forms( $incoming, $data['settings'], $warnings );
		$incoming = $this->normalize_mapping_shapes( $incoming, $warnings );
		// Diff against the actual option so exported WP-user descriptors are compared
		// with their canonical local numeric-key map, not with a different wire shape.
		$current = $this->settings->all();
		$proposed = 'replace' === $mode ? array_replace( $this->portable_defaults(), $incoming ) : array_replace( $current, $incoming );
		if ( array_key_exists( 'didar_broker_user_map', $incoming ) ) {
			$resolved = $this->resolve_user_mappings( $incoming['didar_broker_user_map'] );
			$proposed['didar_broker_user_map'] = $resolved['mappings'];
			$warnings = array_merge( $warnings, $resolved['warnings'] );
		}
		$this->validate( $incoming, $proposed, $warnings, $errors, $not_verified );
		$this->metadata_status( $not_verified );
		$diff = $this->diff( $current, $proposed );
		$this->log( 'didar_settings_import_previewed', array( 'mode' => $mode, 'warnings' => count( $warnings ), 'errors' => count( $errors ), 'categories' => array_keys( $incoming ) ) );
		return array( 'mode' => $mode, 'incoming' => $incoming, 'proposed' => $proposed, 'warnings' => array_values( array_unique( $warnings ) ), 'errors' => array_values( array_unique( $errors ) ), 'not_verified' => array_values( array_unique( $not_verified ) ), 'diff' => $diff, 'summary' => $this->summary( $proposed ) );
	}

	public function apply( $preview ) {
		if ( ! is_array( $preview ) || ! empty( $preview['errors'] ) || ! isset( $preview['proposed'] ) ) { return new WP_Error( 'didar_import_invalid', __( 'تنظیمات واردشده معتبر نیست و اعمال نشد.', 'didar' ) ); }
		$old = $this->settings->all(); $backup_id = $this->create_backup();
		$new = array_replace( $old, $preview['proposed'] );
		if ( ! update_option( Didar_Settings::OPTION_NAME, $new, false ) && get_option( Didar_Settings::OPTION_NAME, array() ) !== $new ) { return new WP_Error( 'didar_import_write', __( 'ذخیره تنظیمات ناموفق بود.', 'didar' ) ); }
		$stored = get_option( Didar_Settings::OPTION_NAME, array() );
		foreach ( $preview['proposed'] as $key => $value ) { if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) { update_option( Didar_Settings::OPTION_NAME, $old, false ); return new WP_Error( 'didar_import_verify', __( 'تأیید ذخیره تنظیمات ناموفق بود و تنظیمات قبلی بازگردانده شد.', 'didar' ) ); } }
		$this->log( 'didar_settings_import_succeeded', array( 'mode' => $preview['mode'], 'backup_id' => $backup_id, 'categories' => array_keys( $preview['proposed'] ) ) );
		return array( 'backup_id' => $backup_id, 'summary' => $this->summary( $preview['proposed'] ), 'warnings' => $preview['warnings'] );
	}

	public function create_backup() {
		$backups = get_option( self::BACKUPS_OPTION, array() ); $backups = is_array( $backups ) ? $backups : array();
		$id = wp_generate_uuid4(); $backups[] = array( 'id' => $id, 'created_at' => gmdate( 'c' ), 'actor_user_id' => get_current_user_id(), 'settings' => $this->portable_settings() );
		update_option( self::BACKUPS_OPTION, array_slice( $backups, -5 ), false );
		$this->log( 'didar_settings_import_backup_created', array( 'backup_id' => $id ) ); return $id;
	}
	public function latest_backup() { $all = get_option( self::BACKUPS_OPTION, array() ); return is_array( $all ) && $all ? end( $all ) : array(); }

	private function normalize( $raw, &$warnings, &$errors ) {
		$out = array(); $allowed = array_keys( $this->portable_settings( array_fill_keys( array( 'didar_form_workflows','didar_field_mappings','didar_broker_user_map','didar_default_owner_id','didar_default_pipeline_id','didar_system_form_type_field_id','didar_system_submission_id_field_id','didar_system_user_id_field_id','didar_public_status_field_id','field_required_overrides','colleague_can_view_internal_history','frontend_requests_per_page','file_download_mode','didar_debug_logging' ), null ) ) );
		foreach ( (array) $raw as $key => $value ) { if ( in_array( $key, $allowed, true ) ) { $out[$key] = $value; } else { $warnings[] = 'گزینه ناشناخته «' . sanitize_text_field( (string) $key ) . '» نادیده گرفته شد.'; } }
		foreach ( array( 'didar_form_workflows','didar_field_mappings','field_required_overrides' ) as $key ) { if ( isset( $out[$key] ) && ! is_array( $out[$key] ) ) { $errors[] = 'ساختار «' . $key . '» معتبر نیست.'; unset( $out[$key] ); } }
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
	private function resolve_user_mappings( $items ) { $out=array(); $warnings=array(); foreach((array)$items as $item){ if(!is_array($item)) continue; $login=sanitize_user($item['wordpress_user_login']??'',true); $email=sanitize_email($item['wordpress_user_email']??''); $user=$login?get_user_by('login',$login):false; if(!$user && $email) $user=get_user_by('email',$email); if(!$user){$warnings[]='کاربر WordPress برای نگاشت دیدار یافت نشد؛ نگاشت رد شد.';continue;} $didar=sanitize_text_field((string)($item['didar_user_id']??'')); if(!$didar)continue; $catalog=$this->workflow->didar_users(); if($catalog && (!$this->workflow->didar_user_by_user_id($didar) || !empty($this->workflow->didar_user_by_user_id($didar)['is_disabled']))) $warnings[]='UserId دیدار «'.$didar.'» معتبر یا فعال نیست.'; $out[$user->ID]=$didar; } return array('mappings'=>$out,'warnings'=>$warnings); }
	private function portable_defaults() { return array( 'didar_form_workflows'=>array(),'didar_field_mappings'=>array(),'didar_broker_user_map'=>array(),'didar_default_owner_id'=>'','didar_default_pipeline_id'=>'','didar_system_form_type_field_id'=>'','didar_system_submission_id_field_id'=>'','didar_system_user_id_field_id'=>'','didar_public_status_field_id'=>'','field_required_overrides'=>array(),'colleague_can_view_internal_history'=>0,'frontend_requests_per_page'=>Didar_Settings::DEFAULT_REQUESTS_PER_PAGE,'file_download_mode'=>Didar_Settings::DEFAULT_FILE_DOWNLOAD_MODE,'didar_debug_logging'=>'off' ); }
	private function diff($old,$new){$groups=array('گردش کار فرم‌ها'=>array('didar_form_workflows'),'نگاشت فیلدهای فرم'=>array('didar_field_mappings'),'کاربران دیدار'=>array('didar_broker_user_map','didar_default_owner_id'),'فیلدهای سیستمی'=>array('didar_system_form_type_field_id','didar_system_submission_id_field_id','didar_system_user_id_field_id'),'وضعیت عمومی'=>array('didar_public_status_field_id'),'سایر تنظیمات'=>array('field_required_overrides','colleague_can_view_internal_history','frontend_requests_per_page','file_download_mode','didar_debug_logging','didar_default_pipeline_id'));$out=array();foreach($groups as $name=>$keys){$changed=0;$same=0;foreach($keys as $k){if(!array_key_exists($k,$new))continue;if(($old[$k]??null)===$new[$k])$same++;else $changed++;}$out[$name]=array('added'=>0,'changed'=>$changed,'unchanged'=>$same,'invalid'=>0);}return $out;}
	public function summary($settings){return array('workflows'=>count((array)($settings['didar_form_workflows']??array())),'field_mappings'=>array_sum(array_map('count',(array)($settings['didar_field_mappings']??array()))),'user_mappings'=>count((array)($settings['didar_broker_user_map']??array())),'system_mappings'=>count(array_filter(array($settings['didar_system_form_type_field_id']??'',$settings['didar_system_submission_id_field_id']??'',$settings['didar_system_user_id_field_id']??''))));}
	private function log($event,$context=array()){$context['schema_version']=self::SCHEMA_VERSION;$context['actor_wp_user_id']=get_current_user_id();$this->logger->log('INFO',$event,'Didar settings transfer event.',$context);}
}
