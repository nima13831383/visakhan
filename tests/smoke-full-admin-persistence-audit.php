<?php

/**
 * Local, reversible persistence audit for ns-didar administrative write paths.
 *
 * Run with: C:\xampp\php\php.exe tests\smoke-full-admin-persistence-audit.php
 *
 * It uses only synthetic WordPress objects, blocks the only executable queue
 * path with a local lock, and restores every option, user meta value, event,
 * scheduled event and synthetic post in finally. It never constructs or calls
 * a Didar API client method.
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/edit.php?post_type=didar_submission&page=didar-page-settings';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

class Didar_Full_Audit_Admin extends Didar_Admin {
	public function prepare_case_settings_save_for_audit( $current, $input, $scope ) {
		return $this->prepare_case_settings_save( $current, $input, $scope );
	}
}

class Didar_Full_Audit_Logger extends Didar_Logger {
	public function __construct() {}
	public function log( $level, $operation, $message, $context = array() ) { return true; }
}

class Didar_Full_Audit_Sync_Manager extends Didar_Sync_Manager {
	public function __construct() {}
	public function sync_user_now( $user_id, $source = 'profile_form', $options = array() ) { return true; }
}

$missing                  = '__didar_audit_missing_' . uniqid( '', true );
$settings_snapshot        = get_option( Didar_Settings::OPTION_NAME, $missing );
$pages_snapshot           = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, $missing );
$transfer_backups_snapshot = get_option( Didar_Settings_Transfer::BACKUPS_OPTION, $missing );
$previous_user_id         = get_current_user_id();
$post_snapshot            = $_POST;
$files_snapshot           = $_FILES;
$settings_errors_snapshot = $GLOBALS['wp_settings_errors'] ?? null;
$failures                 = array();
$checks                   = 0;
$created_posts            = array();
$added_caps               = array();
$profile_meta_snapshot    = array();
$queue_hook_removed       = false;

$assert = function ( $condition, $label ) use ( &$checks, &$failures ) {
	$checks++;
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$restore_option = function ( $option, $value ) use ( $missing ) {
	if ( $missing === $value ) {
		delete_option( $option );
		return;
	}
	update_option( $option, $value, false );
};

try {
	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( ! $administrators ) {
		throw new RuntimeException( 'No local administrator is available.' );
	}

	$audit_user_id = absint( $administrators[0] );
	wp_set_current_user( $audit_user_id );
	$audit_user = wp_get_current_user();
	foreach (
		array(
			'didar_manage_settings',
			'didar_view_all_requests',
			'didar_edit_requests',
			'didar_change_public_status',
			'didar_edit_public_notes',
			'didar_change_internal_status',
			'didar_add_internal_notes',
			'didar_assign_requests',
			'didar_receive_requests',
			'didar_change_request_owner',
		) as $cap
	) {
		if ( ! $audit_user->has_cap( $cap ) ) {
			$audit_user->add_cap( $cap );
			$added_caps[] = $cap;
		}
	}

	$plugin = Didar_Plugin::instance();
	$admin = new Didar_Full_Audit_Admin(
		$plugin->registry,
		$plugin->renderer,
		$plugin->validator,
		$plugin->service,
		$plugin->settings,
		$plugin->file_service,
		$plugin->request_search
	);
	$settings = new Didar_Settings();
	$transfer = new Didar_Settings_Transfer( $plugin->registry, $settings, new Didar_Full_Audit_Logger() );

	$all_profile_states = Didar_Settings::PROFILE_FIELD_STATES;
	$all_profile_states['email'] = 'readonly';
	$all_profile_states['national_id'] = 'disabled';
	$fixture = array(
		'didar_debug_logging'             => 'off',
		'profile_field_states'             => $all_profile_states,
		'didar_form_default_assignees'     => array( 'consultation' => $audit_user_id, 'visa_request' => $audit_user_id ),
		'didar_user_person_mappings'       => array( 'national_id' => 'person-national-id' ),
		'didar_field_mappings'             => array( 'visa_request' => array( 'passport_number' => array( 'target' => 'person_native', 'field' => 'Code' ) ) ),
		'didar_form_workflows'             => array(
			'visa_request' => array(
				'pipeline_id' => 'audit-pipeline',
				'statuses' => array(
					'pending_review' => array( 'label' => 'Pending', 'stage_id' => 'audit-stage', 'is_default' => true, 'order' => 10 ),
					'initial_approval' => array( 'label' => 'Approved', 'stage_id' => 'audit-stage-2', 'is_default' => false, 'order' => 20 ),
				),
			),
		),
		'case_form_settings' => array(
			'visa_request' => array( 'main_field_mappings' => array( 'full_name' => '' ) ),
		),
	);
	update_option( Didar_Settings::OPTION_NAME, $fixture, false );

	// Partial nested profile data must preserve a sibling control that is absent.
	$GLOBALS['wp_settings_errors'] = array();
	$profile_partial = $admin->sanitize_didar_settings(
		array( '_active_tab' => 'profile', 'profile_field_states' => array( 'national_id' => 'editable' ) )
	);
	update_option( Didar_Settings::OPTION_NAME, $profile_partial, false );
	$profile_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'editable' === $profile_reload['profile_field_states']['national_id'], 'profile state valid edit persists after reload' );
	$assert( 'readonly' === $profile_reload['profile_field_states']['email'], 'profile state absent sibling is preserved' );

	// A present zero clears only the selected default assignee; an absent form remains intact.
	$GLOBALS['wp_settings_errors'] = array();
	$assignee_partial = $admin->sanitize_didar_settings(
		array( '_active_tab' => 'forms', 'didar_form_default_assignees' => array( 'consultation' => '0' ) )
	);
	update_option( Didar_Settings::OPTION_NAME, $assignee_partial, false );
	$assignee_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $assignee_reload['didar_form_default_assignees']['consultation'] ), 'default assignee explicit unassign persists' );
	$assert( $audit_user_id === absint( $assignee_reload['didar_form_default_assignees']['visa_request'] ?? 0 ), 'default assignee absent form is preserved' );
	$GLOBALS['wp_settings_errors'] = array();
	$assignee_restore = $admin->sanitize_didar_settings(
		array( '_active_tab' => 'forms', 'didar_form_default_assignees' => array( 'consultation' => (string) $audit_user_id ) )
	);
	update_option( Didar_Settings::OPTION_NAME, $assignee_restore, false );
	$assert( $audit_user_id === absint( get_option( Didar_Settings::OPTION_NAME, array() )['didar_form_default_assignees']['consultation'] ?? 0 ), 'default assignee valid assignment persists after reload' );

	// Representative required/optional controls cover all five active forms.
	$override_input = array( '_active_tab' => 'forms', 'field_required_overrides' => array() );
	$override_fields = array();
	foreach ( $plugin->registry->all() as $form_type => $form ) {
		foreach ( $plugin->registry->fields( $form_type ) as $field_key => $field ) {
			if ( empty( $field['internal'] ) && 'honeypot' !== $field['type'] ) {
				$override_fields[ $form_type ] = $field_key;
				$override_input['field_required_overrides'][ $form_type ][ $field_key ] = 'optional';
				break;
			}
		}
	}
	$GLOBALS['wp_settings_errors'] = array();
	$optional_overrides = $admin->sanitize_didar_settings( $override_input );
	update_option( Didar_Settings::OPTION_NAME, $optional_overrides, false );
	$optional_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	foreach ( $override_fields as $form_type => $field_key ) {
		$assert( false === (bool) ( $optional_reload['field_required_overrides'][ $form_type ][ $field_key ] ?? true ), 'required to optional persists for ' . $form_type );
	}
	$required_input = array( '_active_tab' => 'forms', 'field_required_overrides' => array() );
	foreach ( $override_fields as $form_type => $field_key ) {
		$required_input['field_required_overrides'][ $form_type ][ $field_key ] = 'required';
	}
	$GLOBALS['wp_settings_errors'] = array();
	$required_overrides = $admin->sanitize_didar_settings( $required_input );
	update_option( Didar_Settings::OPTION_NAME, $required_overrides, false );
	$required_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	foreach ( $override_fields as $form_type => $field_key ) {
		$assert( true === (bool) ( $required_reload['field_required_overrides'][ $form_type ][ $field_key ] ?? false ), 'optional to required persists for ' . $form_type );
	}
	$GLOBALS['wp_settings_errors'] = array();
	$required_second_save = $admin->sanitize_didar_settings( $required_input );
	foreach ( $override_fields as $form_type => $field_key ) {
		$assert( true === (bool) ( $required_second_save['field_required_overrides'][ $form_type ][ $field_key ] ?? false ), 'required override survives a second save for ' . $form_type );
	}

	// Case blank remains an explicit tombstone through a scoped save and reload.
	$case_clear = $admin->prepare_case_settings_save_for_audit(
		$required_reload,
		array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ) ) ) ),
		'visa_request'
	);
	update_option( Didar_Settings::OPTION_NAME, $case_clear['settings'], false );
	$case_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( array_key_exists( 'full_name', $case_reload['case_form_settings']['visa_request']['main_field_mappings'] ) && '' === $case_reload['case_form_settings']['visa_request']['main_field_mappings']['full_name'], 'Case explicit unmap survives scoped save and reload' );
	$case_invalid = $admin->prepare_case_settings_save_for_audit(
		$case_reload,
		array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ), 'main_field_mappings' => array( 'full_name' => 'invalid-case-field' ) ) ) ),
		'visa_request'
	);
	$assert( ! empty( $case_invalid['invalid'] ) && '' === ( $case_invalid['settings']['case_form_settings']['visa_request']['main_field_mappings']['full_name'] ?? null ), 'Case invalid mapping preserves explicit clear and returns an invalid result' );

	// Omitted page setting is preserved; present zero is an explicit clear.
	$page_one = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Didar audit details' ), true );
	$page_two = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Didar audit edit' ), true );
	if ( is_wp_error( $page_one ) || is_wp_error( $page_two ) ) {
		throw new RuntimeException( 'Could not create local audit pages.' );
	}
	$created_posts[] = absint( $page_one );
	$created_posts[] = absint( $page_two );
	update_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array( 'details_page_id' => absint( $page_one ), 'edit_page_id' => absint( $page_two ) ), false );
	$page_partial = $admin->sanitize_page_settings( array( 'details_page_id' => '0' ) );
	update_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, $page_partial, false );
	$page_reload = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array() );
	$assert( 0 === absint( $page_reload['details_page_id'] ?? 1 ), 'page explicit clear persists' );
	$assert( absint( $page_two ) === absint( $page_reload['edit_page_id'] ?? 0 ), 'page absent control is preserved' );
	$page_second_save = $admin->sanitize_page_settings( array( 'details_page_id' => '0' ) );
	$assert( 0 === absint( $page_second_save['details_page_id'] ?? 1 ), 'page explicit clear survives second save' );

	// Import uses the official transfer service and keeps blanks/retired fields canonical.
	$import_source = array(
		'didar_field_mappings'       => array(),
		'didar_user_person_mappings' => array(),
		'didar_broker_user_map'      => array(),
		'didar_form_workflows'       => array( 'consultation' => array( 'pipeline_id' => '', 'statuses' => array() ) ),
		'case_form_settings'         => array( 'visa_request' => array( 'main_field_mappings' => array( 'full_name' => '', 'case_role' => 'retired' ), 'field_mappings' => array( 'companion_uid' => 'legacy' ) ) ),
	);
	$portable = $transfer->portable_settings( $import_source );
	$assert( ! isset( $portable['case_form_settings']['visa_request']['main_field_mappings']['case_role'] ), 'settings export removes retired Case role mapping' );
	$assert( ! isset( $portable['case_form_settings']['visa_request']['field_mappings']['companion_uid'] ), 'settings export removes legacy companion UID business mapping' );
	$payload = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => $portable );
	$preview = $transfer->preview( $payload, 'replace' );
	$assert( is_array( $preview ) && empty( $preview['errors'] ), 'settings import preview accepts controlled portable settings' );
	$applied = is_array( $preview ) ? $transfer->apply( $preview ) : new WP_Error( 'audit_preview_failed' );
	$assert( ! is_wp_error( $applied ), 'settings import applies through official transfer service' );
	$import_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( '' === ( $import_reload['didar_form_workflows']['consultation']['pipeline_id'] ?? null ), 'settings import preserves workflow explicit clear' );
	$assert( array_key_exists( 'full_name', $import_reload['case_form_settings']['visa_request']['main_field_mappings'] ?? array() ) && '' === $import_reload['case_form_settings']['visa_request']['main_field_mappings']['full_name'], 'settings import preserves Case explicit unmap' );
	$assert( empty( $import_reload['didar_field_mappings'] ), 'settings import preserves cleared Deal mappings' );
	$assert( empty( $import_reload['didar_user_person_mappings'] ), 'settings import preserves cleared Person mappings' );
	$assert( empty( $import_reload['didar_broker_user_map'] ), 'settings import preserves broker-map clear' );

	// Recreate a local workflow fixture for submission and workflow persistence.
	$submission_settings = $fixture;
	$submission_settings['profile_field_states'] = Didar_Settings::PROFILE_FIELD_STATES;
	update_option( Didar_Settings::OPTION_NAME, $submission_settings, false );

	$submission_id = wp_insert_post(
		array(
			'post_type'   => Didar_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'Synthetic persistence audit request',
			'post_author' => $audit_user_id,
		),
		true
	);
	if ( is_wp_error( $submission_id ) ) {
		throw new RuntimeException( 'Could not create synthetic local submission.' );
	}
	$submission_id = absint( $submission_id );
	$created_posts[] = $submission_id;
	update_post_meta( $submission_id, '_didar_form_type', 'visa_request' );
	update_post_meta( $submission_id, '_didar_fields', array( 'passport_number' => 'OLD', 'email' => 'old@example.test', 'birth_country' => 'germany', 'companions' => array() ) );
	update_post_meta( $submission_id, '_didar_shared_note', 'old note' );
	update_post_meta( $submission_id, Didar_Sync_Manager::META_PERSON_ID, 'local-person-identity' );
	update_post_meta( $submission_id, Didar_Sync_Manager::META_DEAL_ID, 'local-deal-identity' );
	update_post_meta( $submission_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, array( 'case_id' => 'local-case-identity' ) );

	// Prevent synthetic test writes from queuing a future CRM operation.
	remove_action( 'didar_submission_updated', array( $plugin->sync_manager, 'queue_submission' ), 20 );
	remove_action( 'didar_submission_workflow_changed', array( $plugin->sync_manager, 'queue_submission' ), 20 );
	$queue_hook_removed = true;

	$before_failure = $plugin->service->get_fields( $submission_id );
	$invalid_workflow = $submission_settings;
	$invalid_workflow['didar_form_workflows']['visa_request']['statuses']['pending_review']['is_default'] = false;
	update_option( Didar_Settings::OPTION_NAME, $invalid_workflow, false );
	$failed_save = $plugin->service->update( $submission_id, 'visa_request', array( 'passport_number' => 'MUST-NOT-SAVE' ), 'pending_review', $audit_user_id );
	$assert( is_wp_error( $failed_save ) && 'workflow_default_missing' === $failed_save->get_error_code(), 'invalid workflow blocks submission save' );
	$assert( $before_failure === $plugin->service->get_fields( $submission_id ), 'failed submission save leaves previous post-meta fields untouched' );
	update_option( Didar_Settings::OPTION_NAME, $submission_settings, false );

	$admin_save = $plugin->service->update(
		$submission_id,
		'visa_request',
		array(
			'passport_number' => 'NEW',
			'email'           => 'new@example.test',
			'birth_date'      => '2000-01-02',
			'birth_country'   => 'iran',
			'companions'      => array( array( 'companion_uid' => 'cmp_audit', 'full_name' => 'Synthetic companion', 'age' => 24 ) ),
		),
		'pending_review',
		$audit_user_id
	);
	$assert( true === $admin_save, 'admin submission service save succeeds locally' );
	$admin_reload = $plugin->service->get_fields( $submission_id );
	$assert( 'NEW' === ( $admin_reload['passport_number'] ?? '' ) && 'iran' === ( $admin_reload['birth_country'] ?? '' ), 'admin text/select/date values survive reload' );
	$assert( 'cmp_audit' === ( $admin_reload['companions'][0]['companion_uid'] ?? '' ), 'admin structured companion data survives reload' );

	$workflow_update = $plugin->service->update_workflow(
		$submission_id,
		array( 'request_status' => 'initial_approval', 'request_note' => 'workflow audit note', 'assigned_user_id' => $audit_user_id )
	);
	$assert( true === $workflow_update, 'request status, request note and assignment save locally' );
	$workflow_reload = array(
		'request_status' => get_post_meta( $submission_id, '_didar_status', true ),
		'request_note' => get_post_meta( $submission_id, '_didar_internal_note', true ),
		'internal_status' => get_post_meta( $submission_id, '_didar_internal_status', true ),
		'internal_note' => get_post_meta( $submission_id, '_didar_internal_note', true ),
		'assigned' => absint( get_post_meta( $submission_id, '_didar_assigned_user_id', true ) ),
	);
	$assert( 'initial_approval' === $workflow_reload['request_status'] && 'initial_approval' === $workflow_reload['internal_status'], 'request status persists in canonical and compatibility metadata' );
	$assert( 'workflow audit note' === $workflow_reload['request_note'] && 'workflow audit note' === $workflow_reload['internal_note'], 'request note persists in canonical workflow storage' );
	$assert( $audit_user_id === $workflow_reload['assigned'], 'request assignment persists after reload' );
	$assert( true === $plugin->service->update_workflow( $submission_id, array( 'assigned_user_id' => 0 ) ), 'request assignment can be explicitly cleared' );
	$assert( 0 === absint( get_post_meta( $submission_id, '_didar_assigned_user_id', true ) ), 'request unassignment persists after reload' );

	global $wpdb;
	$event_table = Didar_Event_Log::table_name();
	$count_events = function () use ( $wpdb, $event_table, $submission_id ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$event_table} WHERE submission_id = %d", $submission_id ) );
	};
	$event_count_before_repeat = $count_events();
	$repeat = $plugin->service->update_workflow( $submission_id, array( 'request_status' => 'initial_approval', 'request_note' => 'workflow audit note', 'assigned_user_id' => 0 ) );
	$assert( true === $repeat && $event_count_before_repeat === $count_events(), 'unchanged workflow save creates no duplicate event' );

	$frontend_save = $plugin->service->update_from_frontend(
		$submission_id,
		array( 'passport_number' => '', 'email' => 'frontend@example.test', 'birth_country' => 'canada', 'companions' => array() ),
		'frontend note',
		$audit_user_id
	);
	$assert( true === $frontend_save, 'authorized frontend edit path saves through local service' );
	$frontend_reload = $plugin->service->get_fields( $submission_id );
	$assert( '' === ( $frontend_reload['passport_number'] ?? null ) && 'canada' === ( $frontend_reload['birth_country'] ?? '' ), 'frontend explicit empty and select edit survive reload' );
	$assert( 'frontend note' === $plugin->service->get_shared_note( $submission_id ), 'frontend applicant note persists after reload' );

	// Profile value and profile-document reference operations use no file fixture and no sync client.
	foreach ( array( Didar_User_Profile_Value_Catalog::NATIONAL_ID_META, Didar_Profile_Document_Catalog::META_KEY ) as $meta_key ) {
		$profile_meta_snapshot[ $meta_key ] = get_user_meta( $audit_user_id, $meta_key, true );
		$profile_meta_snapshot[ $meta_key . '_exists' ] = metadata_exists( 'user', $audit_user_id, $meta_key );
	}
	$_FILES = array();
	$_POST = array(
		'didar_profile_action' => 'update',
		'didar_profile_nonce' => wp_create_nonce( 'didar_profile_update' ),
		'didar_profile' => array( 'national_id' => '1234567890' ),
	);
	$profile = new Didar_User_Profile( $plugin->registry, new Didar_Settings(), new Didar_Full_Audit_Sync_Manager(), new Didar_Full_Audit_Logger(), null );
	$profile_method = new ReflectionMethod( $profile, 'save_current_user' );
	$profile_method->setAccessible( true );
	$profile_saved = $profile_method->invoke( $profile, wp_get_current_user() );
	$assert( is_array( $profile_saved ) && '1234567890' === get_user_meta( $audit_user_id, Didar_User_Profile_Value_Catalog::NATIONAL_ID_META, true ), 'frontend profile user-meta value persists' );
	$_POST['didar_profile']['national_id'] = '';
	$_POST['didar_profile_nonce'] = wp_create_nonce( 'didar_profile_update' );
	$profile_cleared = $profile_method->invoke( $profile, wp_get_current_user() );
	$assert( is_array( $profile_cleared ) && '' === get_user_meta( $audit_user_id, Didar_User_Profile_Value_Catalog::NATIONAL_ID_META, true ), 'frontend profile explicit clear persists' );
	Didar_Profile_Document_Catalog::set_user_document( $audit_user_id, 'personal_photo', 901 );
	$assert( 901 === absint( Didar_Profile_Document_Catalog::get_user_documents( $audit_user_id )['personal_photo'] ?? 0 ), 'profile document reference persists' );
	Didar_Profile_Document_Catalog::remove_user_document( $audit_user_id, 'personal_photo' );
	$assert( 0 === absint( Didar_Profile_Document_Catalog::get_user_documents( $audit_user_id )['personal_photo'] ?? 1 ), 'profile document reference removal persists without filesystem access' );

	// Manual queue execution is stopped by a local lock before any worker/API call.
	$queue_state = array( 'status' => 'retry', 'generation_id' => 'audit-generation', 'automatic_attempts' => 2, 'manual_attempts' => 0 );
	update_post_meta( $submission_id, Didar_Sync_Manager::META_STATE, $queue_state );
	$lock_name = Didar_Sync_Manager::LOCK_PREFIX . $submission_id;
	update_option( $lock_name, array( 'expires_at' => time() + 300 ), false );
	$scheduled_at = time() + HOUR_IN_SECONDS;
	wp_schedule_single_event( $scheduled_at, Didar_Sync_Manager::CRON_HOOK, array( $submission_id ) );
	$run_locked = $plugin->sync_manager->run_queue_item( 'submission:' . $submission_id );
	$assert( is_wp_error( $run_locked ) && 'didar_sync_locked' === $run_locked->get_error_code(), 'Run Now stops at local lock without executing a worker' );
	$assert( $queue_state === get_post_meta( $submission_id, Didar_Sync_Manager::META_STATE, true ), 'blocked Run Now does not change generation or attempts' );
	delete_option( $lock_name );
	$discard = $plugin->sync_manager->discard_queue_item( 'submission:' . $submission_id );
	$assert( is_array( $discard ) && ! metadata_exists( 'post', $submission_id, Didar_Sync_Manager::META_STATE ), 'queue discard removes only synthetic queue state' );
	$assert( false === wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $submission_id ) ), 'queue discard removes synthetic retry event' );

	// The private removal core is exactly what purge_queue() invokes after discovery.
	update_post_meta( $submission_id, Didar_Sync_Manager::META_STATE, $queue_state );
	wp_schedule_single_event( $scheduled_at + 1, Didar_Sync_Manager::CRON_HOOK, array( $submission_id ) );
	$remove_queue_records = new ReflectionMethod( $plugin->sync_manager, 'remove_queue_records' );
	$remove_queue_records->setAccessible( true );
	$purge_result = $remove_queue_records->invoke(
		$plugin->sync_manager,
		array( $submission_id ),
		array(),
		array(),
		array( array( 'timestamp' => $scheduled_at + 1, 'hook' => Didar_Sync_Manager::CRON_HOOK, 'args' => array( $submission_id ) ) ),
		array()
	);
	$assert( is_array( $purge_result ) && ! metadata_exists( 'post', $submission_id, Didar_Sync_Manager::META_STATE ), 'queue purge core removes synthetic local queue state after reload' );
	$assert( 'local-person-identity' === get_post_meta( $submission_id, Didar_Sync_Manager::META_PERSON_ID, true ) && 'local-deal-identity' === get_post_meta( $submission_id, Didar_Sync_Manager::META_DEAL_ID, true ), 'queue purge core preserves Person and Deal identities' );
	$assert( 'local-case-identity' === ( get_post_meta( $submission_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, true )['case_id'] ?? '' ), 'queue purge core preserves Case identity' );

	// Invalid input keeps its previous value and cannot emit a success notice.
	$notice_fixture = get_option( Didar_Settings::OPTION_NAME, array() );
	$notice_fixture['didar_form_access'] = array( 'consultation' => array( 'url' => 'https://example.test/valid', 'barcode' => '' ) );
	update_option( Didar_Settings::OPTION_NAME, $notice_fixture, false );
	$GLOBALS['wp_settings_errors'] = array();
	$invalid_result = $admin->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_form_access' => array( 'consultation' => array( 'url' => 'not a URL', 'barcode' => '' ) ) ) );
	$invalid_errors = get_settings_errors( Didar_Settings::OPTION_NAME );
	$invalid_codes = wp_list_pluck( $invalid_errors, 'code' );
	$assert( Didar_Form_Access::sanitize_url( 'https://example.test/valid' ) === ( $invalid_result['didar_form_access']['consultation']['url'] ?? '' ), 'invalid setting preserves previous value' );
	$assert( in_array( 'didar_invalid_form_access_url_consultation', $invalid_codes, true ) && ! in_array( 'didar_settings_saved', $invalid_codes, true ), 'rejected setting does not receive a success notice' );
} catch ( Throwable $error ) {
	$failures[] = 'uncaught: ' . $error->getMessage();
} finally {
	if ( $queue_hook_removed && isset( $plugin ) ) {
		add_action( 'didar_submission_updated', array( $plugin->sync_manager, 'queue_submission' ), 20, 2 );
		add_action( 'didar_submission_workflow_changed', array( $plugin->sync_manager, 'queue_submission' ), 20, 2 );
	}
	if ( isset( $wpdb, $event_table ) && $event_table ) {
		foreach ( $created_posts as $post_id ) {
			$wpdb->delete( $event_table, array( 'submission_id' => absint( $post_id ) ), array( '%d' ) );
		}
	}
	foreach ( $created_posts as $post_id ) {
		wp_clear_scheduled_hook( Didar_Sync_Manager::CRON_HOOK, array( absint( $post_id ) ) );
		wp_delete_post( absint( $post_id ), true );
		delete_option( Didar_Sync_Manager::LOCK_PREFIX . absint( $post_id ) );
	}
	if ( isset( $audit_user_id ) ) {
		foreach ( $profile_meta_snapshot as $meta_key => $value ) {
			if ( str_ends_with( $meta_key, '_exists' ) ) {
				continue;
			}
			if ( empty( $profile_meta_snapshot[ $meta_key . '_exists' ] ) ) {
				delete_user_meta( $audit_user_id, $meta_key );
			} else {
				update_user_meta( $audit_user_id, $meta_key, $value );
			}
		}
	}
	$restore_option( Didar_Settings::OPTION_NAME, $settings_snapshot );
	$restore_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, $pages_snapshot );
	$restore_option( Didar_Settings_Transfer::BACKUPS_OPTION, $transfer_backups_snapshot );
	if ( isset( $audit_user ) ) {
		foreach ( $added_caps as $cap ) {
			$audit_user->remove_cap( $cap );
		}
	}
	$_POST = $post_snapshot;
	$_FILES = $files_snapshot;
	if ( null === $settings_errors_snapshot ) {
		unset( $GLOBALS['wp_settings_errors'] );
	} else {
		$GLOBALS['wp_settings_errors'] = $settings_errors_snapshot;
	}
	wp_set_current_user( $previous_user_id );
}

if ( $failures ) {
	echo 'FAIL checks=' . $checks . ' failures=' . implode( ' | ', $failures ) . ' settings_restored=YES network_calls=NO' . PHP_EOL;
	exit( 1 );
}

echo 'PASS checks=' . $checks . ' settings_restored=YES network_calls=NO live_crm_mutations=NO' . PHP_EOL;
