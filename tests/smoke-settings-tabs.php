<?php

/**
 * Local, no-network Settings persistence smoke test.
 *
 * Run with: C:\xampp\php\php.exe tests\smoke-settings-tabs.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/edit.php?post_type=didar_submission&page=didar-page-settings';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

class Didar_Settings_Smoke_Admin extends Didar_Admin {
	public function prepare_case_settings_save_for_smoke( $current, $input, $scope ) {
		return $this->prepare_case_settings_save( $current, $input, $scope );
	}
}

$missing       = '__didar_missing_' . uniqid( '', true );
$snapshot      = get_option( Didar_Settings::OPTION_NAME, $missing );
$page_snapshot = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, $missing );
$transfer_backup_snapshot = get_option( Didar_Settings_Transfer::BACKUPS_OPTION, $missing );
$failures      = array();
$checks        = 0;
$smoke_user    = null;
$added_cap     = false;
$assert        = function ( $condition, $label ) use ( &$failures, &$checks ) {
	$checks++;
	if ( ! $condition ) { $failures[] = $label; }
};

try {
	$administrator = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( ! $administrator ) { throw new RuntimeException( 'No local administrator is available.' ); }
	wp_set_current_user( (int) $administrator[0] );
	$user = wp_get_current_user();
	$smoke_user = $user;
	if ( ! $user->has_cap( 'didar_manage_settings' ) ) { $user->add_cap( 'didar_manage_settings' ); $added_cap = true; }

	$plugin = Didar_Plugin::instance();
	$admin  = new Didar_Settings_Smoke_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	$case_service = new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() );
	$case_fields  = $case_service->custom_fields();
	$pipelines    = $case_service->pipelines();
	$case_target  = $case_fields[0]['key'] ?? '';
	$case_target2 = $case_fields[1]['key'] ?? $case_target;
	$case_target4 = $case_fields[3]['key'] ?? $case_target2;
	$pipeline_id  = $pipelines[0]['id'] ?? '';
	$stage_id     = $pipelines[0]['stages'][0]['id'] ?? '';

	$fixture = array(
		'didar_api_key' => 'smoke-secret-preserved',
		'didar_debug_logging' => 'verbose',
		'didar_default_owner_id' => 'owner-to-clear',
		'didar_form_access' => array( 'consultation' => array( 'url' => 'https://example.test/form', 'barcode' => '' ) ),
		'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'workflow-preserved' ) ),
		'didar_field_mappings' => array( 'consultation' => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_To_Clear' ) ) ),
		'didar_user_person_mappings' => array( 'gender' => 'Person_Gender' ),
		'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES,
		'visa_companion_case_settings' => array(
			'pipeline_id' => $pipeline_id,
			'initial_stage_id' => $stage_id,
			'field_mappings' => array( 'companion_uid' => $case_target, 'family_relation' => $case_target2 ),
			'main_field_mappings' => array( 'case_role' => $case_target4 ),
			'system_fields' => array( 'companion_uid' => $case_target ),
		),
		'case_form_settings' => array(
			'embassy_appointment' => array( 'pipeline_id' => $pipeline_id, 'initial_stage_id' => $stage_id, 'main_field_mappings' => array( 'full_name' => $case_target2 ) ),
		),
		'didar_companion_runtime' => array( 'cmp_smoke' => array( 'case_id' => 'runtime-must-not-export' ) ),
	);
	update_option( Didar_Settings::OPTION_NAME, $fixture, false );
	$transfer = new Didar_Settings_Transfer( $plugin->registry, new Didar_Settings(), new Didar_Logger() );
	$portable = $transfer->portable_settings( $fixture );
	$assert( ! isset( $portable['didar_api_key'] ), 'export excludes credentials' );
	$assert( ! isset( $portable['didar_companion_runtime'] ), 'export excludes runtime Case IDs' );
	$assert( ! isset( $portable['visa_companion_case_settings']['field_mappings']['companion_uid'] ), 'export normalizes business companion_uid' );
	$assert( ! isset( $portable['visa_companion_case_settings']['main_field_mappings']['case_role'] ), 'export normalizes case_role' );
	$preview = $transfer->preview( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => $fixture ), 'merge' );
	$assert( is_array( $preview ) && isset( $preview['incoming'] ), 'legacy import preview remains structurally compatible' );
	$assert( ! isset( $preview['incoming']['visa_companion_case_settings']['field_mappings']['companion_uid'] ), 'import normalizes business companion_uid' );
	$assert( ! isset( $preview['incoming']['visa_companion_case_settings']['main_field_mappings']['case_role'] ), 'import normalizes case_role' );

	$forms = $admin->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_form_access' => array( 'consultation' => array( 'url' => '', 'barcode' => '' ) ), 'didar_field_mappings' => array( 'consultation' => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => '' ) ) ) ) );
	update_option( Didar_Settings::OPTION_NAME, $forms, false );
	$forms_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( '' === $forms_reload['didar_form_access']['consultation']['url'], 'B/K explicit empty form value' );
	$assert( ! isset( $forms_reload['didar_field_mappings']['consultation']['applicant_note'] ), 'F form field mapping Save/Reload' );
	$assert( 'verbose' === $forms_reload['didar_debug_logging'], 'I Forms preserves General' );
	$assert( $fixture['didar_user_person_mappings'] === $forms_reload['didar_user_person_mappings'], 'I Forms preserves Profile' );
	$assert( isset( $forms_reload['visa_companion_case_settings']['system_fields']['companion_uid'] ), 'I Forms preserves Cases' );

	$workflow_clear_fixture = $forms_reload;
	$workflow_clear_fixture['didar_form_workflows']['consultation'] = array( 'pipeline_id' => 'legacy-pipeline', 'statuses' => array( 'pending_review' => array( 'stage_id' => 'legacy-stage', 'is_default' => true, 'label' => 'Pending', 'order' => 10 ) ) );
	$workflow_clear_fixture['didar_default_pipeline_id'] = 'legacy-pipeline';
	update_option( Didar_Settings::OPTION_NAME, $workflow_clear_fixture, false );
	$workflow_cleared = $admin->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => '', 'statuses' => array( 'pending_review' => array( 'key' => 'pending_review', 'label' => 'Pending', 'stage_id' => '' ) ) ) ) ) );
	update_option( Didar_Settings::OPTION_NAME, $workflow_cleared, false );
	$workflow_cleared_reload = ( new Didar_Settings() )->all();
	$assert( isset( $workflow_cleared_reload['didar_form_workflows']['consultation'] ) && '' === $workflow_cleared_reload['didar_form_workflows']['consultation']['pipeline_id'], 'Forms workflow explicit empty pipeline persists as opt-out' );
	$assert( ! ( new Didar_Workflow_Manager( $plugin->registry, new Didar_Settings(), new Didar_Logger() ) )->workflow( 'consultation' )['pipeline_id'], 'Forms workflow clear does not fall back to legacy pipeline' );
	$workflow_portable = $transfer->portable_settings( $workflow_cleared_reload );
	$assert( isset( $workflow_portable['didar_form_workflows']['consultation'] ) && '' === $workflow_portable['didar_form_workflows']['consultation']['pipeline_id'], 'export preserves explicit workflow opt-out' );
	$workflow_import = $transfer->preview( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => array( 'didar_form_workflows' => $workflow_portable['didar_form_workflows'] ) ), 'replace' );
	$assert( is_array( $workflow_import ) && isset( $workflow_import['incoming']['didar_form_workflows']['consultation'] ) && '' === $workflow_import['incoming']['didar_form_workflows']['consultation']['pipeline_id'], 'import preserves explicit workflow opt-out' );

	$broker_fixture = $workflow_cleared_reload;
	$broker_fixture['didar_broker_user_map'] = array( 101 => 'didar-user-a', 202 => 'didar-user-b' );
	update_option( Didar_Settings::OPTION_NAME, $broker_fixture, false );
	$broker_cleared = $admin->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_broker_user_map' => array( 101 => '' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $broker_cleared, false );
	$broker_cleared_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $broker_cleared_reload['didar_broker_user_map'][101] ), 'Forms broker mapping explicit no-mapping clears' );
	$assert( 'didar-user-b' === ( $broker_cleared_reload['didar_broker_user_map'][202] ?? '' ), 'Forms broker mapping absent control preserves old value' );
	$legacy_mapping = $forms_reload;
	$legacy_mapping['didar_field_mappings']['consultation']['first_name'] = array( 'target' => 'person_native', 'field' => 'FirstName' );
	update_option( Didar_Settings::OPTION_NAME, $legacy_mapping, false );
	$legacy_clear = $admin->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_field_mappings' => array( 'consultation' => array( 'first_name' => array( 'target' => 'deal_custom', 'field' => '' ) ) ) ) );
	update_option( Didar_Settings::OPTION_NAME, $legacy_clear, false );
	$legacy_clear_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $legacy_clear_reload['didar_field_mappings']['consultation']['first_name'] ), 'F legacy mapping explicit no-mapping Save/Reload' );

	$profile = $admin->sanitize_didar_settings( array( '_active_tab' => 'profile', 'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES, 'didar_user_person_mappings' => array( 'gender' => 'Person_Gender_Changed' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $profile, false );
	$profile_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'Person_Gender_Changed' === $profile_reload['didar_user_person_mappings']['gender'], 'G Profile mapping Save/Reload' );
	$assert( $forms_reload['didar_form_access'] === $profile_reload['didar_form_access'], 'Profile preserves Forms' );
	$profile_clear = $admin->sanitize_didar_settings( array( '_active_tab' => 'profile', 'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES, 'didar_user_person_mappings' => array( 'gender' => '' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $profile_clear, false );
	$profile_clear_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $profile_clear_reload['didar_user_person_mappings']['gender'] ), 'Profile explicit empty mapping clears' );
	$profile_restore = $admin->sanitize_didar_settings( array( '_active_tab' => 'profile', 'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES, 'didar_user_person_mappings' => array( 'gender' => 'Person_Gender_Changed' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $profile_restore, false );
	$profile_clear = $admin->sanitize_didar_settings( array( '_active_tab' => 'profile', 'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES, 'didar_user_person_mappings' => array( 'gender' => '' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $profile_clear, false );
	$profile_clear_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $profile_clear_reload['didar_user_person_mappings']['gender'] ), 'G Profile no-mapping Save/Reload' );
	$profile_documents = $admin->sanitize_didar_settings( array( '_active_tab' => 'profile', 'profile_field_states' => array_merge( Didar_Settings::PROFILE_FIELD_STATES, array( 'birth_date' => 'disabled' ) ), 'didar_user_person_mappings' => array( 'national_card_front' => '' ) ) );
	update_option( Didar_Settings::OPTION_NAME, $profile_documents, false );
	$profile_documents_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( ! isset( $profile_documents_reload['didar_user_person_mappings']['national_card_front'] ), 'Profile document mapping explicit no-mapping clears' );
	$assert( 'disabled' === $profile_documents_reload['profile_field_states']['birth_date'], 'Profile state select persists' );

	$general = $admin->sanitize_didar_settings( array( '_active_tab' => 'general', 'didar_debug_logging' => 'errors', 'didar_default_owner_id' => '' ) );
	update_option( Didar_Settings::OPTION_NAME, $general, false );
	$general_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'errors' === $general_reload['didar_debug_logging'], 'A/H General select Save/Reload' );
	$assert( '' === $general_reload['didar_default_owner_id'], 'K General explicit empty' );
	$assert( ! isset( $general_reload['didar_user_person_mappings']['gender'] ), 'General preserves cleared Profile mapping' );
	$general_off = $admin->sanitize_didar_settings( array( '_active_tab' => 'general', 'colleague_can_view_internal_history' => 0, 'didar_debug_logging' => 'off', 'didar_default_owner_id' => '', 'didar_default_pipeline_id' => '', 'didar_public_status_field_id' => '' ) );
	update_option( Didar_Settings::OPTION_NAME, $general_off, false );
	$general_off_reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 0 === (int) $general_off_reload['colleague_can_view_internal_history'], 'General unchecked checkbox persists off' );
	$assert( '' === $general_off_reload['didar_default_pipeline_id'], 'General optional text clear persists' );
	$assert( '' === $general_off_reload['didar_public_status_field_id'], 'Forms public status clear persists' );

	$normalized = ( new Didar_Settings() )->all();
	$assert( ! isset( $normalized['visa_companion_case_settings']['field_mappings']['companion_uid'] ), 'C redundant companion_uid removed' );
	$assert( isset( $normalized['visa_companion_case_settings']['system_fields']['companion_uid'] ), 'C system companion_uid preserved' );
	$assert( ! isset( $normalized['visa_companion_case_settings']['main_field_mappings']['case_role'] ), 'retired case_role removed' );

	if ( $pipeline_id && $stage_id && $case_target ) {
		$case_clear_post = array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'field_mappings' => array( 'family_relation' => '1' ), 'main_field_mappings' => array( 'full_name' => '1' ), 'system_fields' => array( 'companion_uid' => '1' ) ) ) ) );
		$case_cleared = $admin->prepare_case_settings_save_for_smoke( $normalized, $case_clear_post, 'visa_request' );
		$assert( array_key_exists( 'family_relation', $case_cleared['settings']['case_form_settings']['visa_request']['field_mappings'] ) && '' === $case_cleared['settings']['case_form_settings']['visa_request']['field_mappings']['family_relation'], 'Case no-mapping stores companion empty tombstone' );
		$assert( array_key_exists( 'full_name', $case_cleared['settings']['case_form_settings']['visa_request']['main_field_mappings'] ) && '' === $case_cleared['settings']['case_form_settings']['visa_request']['main_field_mappings']['full_name'], 'Case no-mapping stores main empty tombstone' );
		$assert( array_key_exists( 'companion_uid', $case_cleared['settings']['case_form_settings']['visa_request']['system_fields'] ) && '' === $case_cleared['settings']['case_form_settings']['visa_request']['system_fields']['companion_uid'], 'Case no-mapping stores system empty tombstone' );

		$visa_post = array( 'case_form_settings' => array( 'visa_request' => array( 'pipeline_id' => $pipeline_id, 'initial_stage_id' => $stage_id, 'main_field_mappings' => array( 'full_name' => $case_target4 ) ) ) );
		$visa = $admin->prepare_case_settings_save_for_smoke( $case_cleared['settings'], $visa_post, 'visa_request' );
		update_option( Didar_Settings::OPTION_NAME, $visa['settings'], false );
		$visa_reload = get_option( Didar_Settings::OPTION_NAME, array() );
		$assert( $case_target4 === $visa_reload['case_form_settings']['visa_request']['main_field_mappings']['full_name'], 'D Visa main mapping Save/Reload' );
		$invalid_case_post = array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ), 'main_field_mappings' => array( 'full_name' => 'invalid-case-field' ) ) ) );
		$invalid_case = $admin->prepare_case_settings_save_for_smoke( $visa_reload, $invalid_case_post, 'visa_request' );
		$assert( $case_target4 === $invalid_case['settings']['case_form_settings']['visa_request']['main_field_mappings']['full_name'], 'Case invalid mapping preserves prior valid value' );
		$assert( 'off' === $visa_reload['didar_debug_logging'], 'J Case save preserves General' );
		$assert( ! isset( $visa_reload['didar_user_person_mappings']['gender'] ), 'J Case save preserves cleared Profile mapping' );

		$embassy_post = array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => $pipeline_id, 'initial_stage_id' => $stage_id, 'main_field_mappings' => array( 'full_name' => $case_target4 ) ) ) );
		$embassy = $admin->prepare_case_settings_save_for_smoke( $invalid_case['settings'], $embassy_post, 'embassy_appointment' );
		update_option( Didar_Settings::OPTION_NAME, $embassy['settings'], false );
		$embassy_reload = get_option( Didar_Settings::OPTION_NAME, array() );
		$assert( $case_target4 === $embassy_reload['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'], 'E Embassy mapping Save/Reload' );
		$embassy_clear_post = array( 'case_form_settings' => array( 'embassy_appointment' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ), 'main_field_mappings' => array( 'full_name' => '' ) ) ) );
		$embassy_cleared = $admin->prepare_case_settings_save_for_smoke( $embassy_reload, $embassy_clear_post, 'embassy_appointment' );
		update_option( Didar_Settings::OPTION_NAME, $embassy_cleared['settings'], false );
		$embassy_cleared_reload = ( new Didar_Settings() )->all();
		$assert( array_key_exists( 'full_name', $embassy_cleared_reload['case_form_settings']['embassy_appointment']['main_field_mappings'] ) && '' === $embassy_cleared_reload['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'], 'Embassy exact full_name no-mapping persists as empty tombstone on Save/Reload' );
		ob_start();
		$admin->render_case_companion_settings( 'didar_case_settings' );
		$embassy_cleared_html = ob_get_clean();
		$assert( 1 === substr_count( $embassy_cleared_html, 'name="didar_case_settings[case_form_settings][embassy_appointment][main_field_mappings][full_name]"' ), 'Embassy full_name has one Case mapping control and no same-name hidden fallback' );
		$assert( 1 === preg_match( '/<select name="didar_case_settings\\[case_form_settings\\]\\[embassy_appointment\\]\\[main_field_mappings\\]\\[full_name\\]">\\s*<option value="">— بدون نگاشت —<\\/option>/u', $embassy_cleared_html ), 'Embassy full_name reload renders no-mapping first' );
		$assert( 0 === preg_match( '/<select name="didar_case_settings\\[case_form_settings\\]\\[embassy_appointment\\]\\[main_field_mappings\\]\\[full_name\\]">.*?<option value="' . preg_quote( $case_target4, '/' ) . '"\\s+selected=/s', $embassy_cleared_html ), 'Embassy full_name reload does not select the old mapping' );
		$embassy_second_save = $admin->prepare_case_settings_save_for_smoke( $embassy_cleared_reload, $embassy_clear_post, 'embassy_appointment' );
		$assert( array_key_exists( 'full_name', $embassy_second_save['settings']['case_form_settings']['embassy_appointment']['main_field_mappings'] ) && '' === $embassy_second_save['settings']['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'], 'Embassy full_name remains explicit empty on second save' );

		$matrix_config = array(
			'pipeline_id'         => $pipeline_id,
			'initial_stage_id'    => $stage_id,
			'field_mappings'      => array( 'family_relation' => $case_target ),
			'main_field_mappings' => array( 'full_name' => $case_target2 ),
			'system_fields'       => array( 'submission_id' => $case_target4 ),
		);
		$matrix_current = $embassy_second_save['settings'];
		$matrix_current['visa_companion_case_settings'] = $matrix_config;
		$matrix_current['case_form_settings']['visa_request'] = $matrix_config;
		$matrix_current['case_form_settings']['embassy_appointment'] = $matrix_config;
		$matrix_clear_form = array(
			'_present' => array(
				'field_mappings'      => array( 'family_relation' => '1' ),
				'main_field_mappings' => array( 'full_name' => '1' ),
				'system_fields'       => array( 'submission_id' => '1' ),
			),
			'field_mappings'      => array( 'family_relation' => '' ),
			'main_field_mappings' => array( 'full_name' => '' ),
			'system_fields'       => array( 'submission_id' => '' ),
		);
		$matrix_clear_post = array( 'case_form_settings' => array( 'visa_request' => $matrix_clear_form, 'embassy_appointment' => $matrix_clear_form ) );
		$matrix_cleared = $admin->prepare_case_settings_save_for_smoke( $matrix_current, $matrix_clear_post, 'all' );
		update_option( Didar_Settings::OPTION_NAME, $matrix_cleared['settings'], false );
		$matrix_raw = get_option( Didar_Settings::OPTION_NAME, array() );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $matrix_form ) {
			$matrix_maps = $matrix_raw['case_form_settings'][ $matrix_form ];
			$assert( array_key_exists( 'family_relation', $matrix_maps['field_mappings'] ) && '' === $matrix_maps['field_mappings']['family_relation'], $matrix_form . ' raw companion empty tombstone' );
			$assert( array_key_exists( 'full_name', $matrix_maps['main_field_mappings'] ) && '' === $matrix_maps['main_field_mappings']['full_name'], $matrix_form . ' raw main empty tombstone' );
			$assert( array_key_exists( 'submission_id', $matrix_maps['system_fields'] ) && '' === $matrix_maps['system_fields']['submission_id'], $matrix_form . ' raw system empty tombstone' );
		}
		$matrix_legacy_conflict = $matrix_raw;
		$matrix_legacy_conflict['visa_companion_case_settings']['main_field_mappings']['full_name'] = $case_target2;
		update_option( Didar_Settings::OPTION_NAME, $matrix_legacy_conflict, false );
		$matrix_settings = new Didar_Settings();
		$matrix_case_service = new Didar_Case_Service( $matrix_settings, new Didar_Logger() );
		$assert( '' === $matrix_case_service->configuration( 'embassy_appointment' )['main_field_mappings']['full_name'], 'Embassy getter returns explicit empty' );
		$assert( '' === $matrix_case_service->configuration( 'visa_request' )['main_field_mappings']['full_name'], 'Visa canonical empty wins over conflicting legacy mapping' );
		$matrix_second = $admin->prepare_case_settings_save_for_smoke( $matrix_raw, $matrix_clear_post, 'all' );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $matrix_form ) {
			$matrix_maps = $matrix_second['settings']['case_form_settings'][ $matrix_form ];
			$assert( '' === $matrix_maps['field_mappings']['family_relation'] && '' === $matrix_maps['main_field_mappings']['full_name'] && '' === $matrix_maps['system_fields']['submission_id'], $matrix_form . ' all Case mapping tombstones survive second save' );
		}
		$matrix_reactivate_form = array(
			'_present' => $matrix_clear_form['_present'],
			'field_mappings'      => array( 'family_relation' => $case_target ),
			'main_field_mappings' => array( 'full_name' => $case_target2 ),
			'system_fields'       => array( 'submission_id' => $case_target4 ),
		);
		$matrix_reactivated = $admin->prepare_case_settings_save_for_smoke( $matrix_second['settings'], array( 'case_form_settings' => array( 'visa_request' => $matrix_reactivate_form, 'embassy_appointment' => $matrix_reactivate_form ) ), 'all' );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $matrix_form ) {
			$matrix_maps = $matrix_reactivated['settings']['case_form_settings'][ $matrix_form ];
			$assert( $case_target === $matrix_maps['field_mappings']['family_relation'] && $case_target2 === $matrix_maps['main_field_mappings']['full_name'] && $case_target4 === $matrix_maps['system_fields']['submission_id'], $matrix_form . ' all Case mapping tombstones reactivate with valid targets' );
		}
		$matrix_recleared = $admin->prepare_case_settings_save_for_smoke( $matrix_reactivated['settings'], $matrix_clear_post, 'all' );
		update_option( Didar_Settings::OPTION_NAME, $matrix_recleared['settings'], false );
		$matrix_recleared_raw = get_option( Didar_Settings::OPTION_NAME, array() );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $matrix_form ) {
			$matrix_maps = $matrix_recleared_raw['case_form_settings'][ $matrix_form ];
			$assert( '' === $matrix_maps['field_mappings']['family_relation'] && '' === $matrix_maps['main_field_mappings']['full_name'] && '' === $matrix_maps['system_fields']['submission_id'], $matrix_form . ' all Case mappings return to explicit empty storage' );
		}
		$matrix_portable = $transfer->portable_settings( $matrix_recleared['settings'] );
		$assert( array_key_exists( 'full_name', $matrix_portable['case_form_settings']['embassy_appointment']['main_field_mappings'] ) && '' === $matrix_portable['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'], 'Case export retains explicit empty tombstone' );
		$matrix_preview = $transfer->preview( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => array( 'case_form_settings' => $matrix_portable['case_form_settings'], 'visa_companion_case_settings' => $matrix_portable['visa_companion_case_settings'] ) ), 'replace' );
		$assert( is_array( $matrix_preview ) && array_key_exists( 'full_name', $matrix_preview['incoming']['case_form_settings']['embassy_appointment']['main_field_mappings'] ) && '' === $matrix_preview['incoming']['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'], 'Case import retains explicit empty tombstone' );
		$matrix_import_result = $transfer->apply( $matrix_preview );
		$matrix_imported_raw = get_option( Didar_Settings::OPTION_NAME, array() );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $matrix_form ) {
			$matrix_maps = $matrix_imported_raw['case_form_settings'][ $matrix_form ];
			$assert( ! is_wp_error( $matrix_import_result ) && array_key_exists( 'family_relation', $matrix_maps['field_mappings'] ) && '' === $matrix_maps['field_mappings']['family_relation'] && array_key_exists( 'full_name', $matrix_maps['main_field_mappings'] ) && '' === $matrix_maps['main_field_mappings']['full_name'] && array_key_exists( 'submission_id', $matrix_maps['system_fields'] ) && '' === $matrix_maps['system_fields']['submission_id'], $matrix_form . ' importer writes all explicit empty tombstones to the raw option' );
		}
		$matrix_mapper = new Didar_Field_Mapper( $plugin->registry, new Didar_Settings(), null, new Didar_Logger() );
		$matrix_payload = $matrix_mapper->case_fields( 'visa_request', array( 'full_name' => 'QA Applicant' ), 0, array( 'full_name' => '' ) );
		$assert( array() === $matrix_payload, 'Case payload skips explicit empty mapping' );
		$matrix_companion_payload = $matrix_mapper->companion_case_fields( 'visa_request', array( 'family_relation' => 'Sibling' ), 0, 0, array( 'family_relation' => '' ) );
		$assert( array() === $matrix_companion_payload, 'Companion Case payload skips explicit empty mapping' );
	}

	$_GET = array( 'tab' => 'forms' );
	$admin->register_settings();
	ob_start();
	$admin->render_settings_page();
	$html = ob_get_clean();
	$assert( 4 === substr_count( $html, 'nav-tab ' ), 'four Settings tabs render' );
	$assert( false !== strpos( $html, 'tab=forms' ), 'Forms tab URL renders' );
	$assert( false !== strpos( $html, 'didar_settings[_active_tab]' ), 'active tab marker renders' );
	$assert( false === strpos( $html, '[field_mappings][companion_uid]' ), 'business companion_uid absent from rendered UI' );
	$assert( false !== strpos( $html, 'name="didar_settings[didar_field_mappings]' ) && false !== strpos( $html, 'value=""' ), 'form no-mapping fallback renders' );
	$_GET = array( 'tab' => 'general' );
	ob_start();
	$admin->render_settings_page();
	$general_html = ob_get_clean();
	$assert( false !== strpos( $general_html, 'name="action" value="didar_refresh_all_metadata"' ), 'global refresh action renders on General' );
	$assert( false !== strpos( $general_html, 'method="post"' ), 'global refresh uses POST' );
	$assert( false !== strpos( $general_html, 'به‌روزرسانی همه اطلاعات دیدار' ), 'global refresh Persian label renders' );
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
} finally {
	if ( $missing === $snapshot ) { delete_option( Didar_Settings::OPTION_NAME ); } else { update_option( Didar_Settings::OPTION_NAME, $snapshot, false ); }
	if ( $missing === $page_snapshot ) { delete_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION ); } else { update_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, $page_snapshot, false ); }
	if ( $missing === $transfer_backup_snapshot ) { delete_option( Didar_Settings_Transfer::BACKUPS_OPTION ); } else { update_option( Didar_Settings_Transfer::BACKUPS_OPTION, $transfer_backup_snapshot, false ); }
	if ( $added_cap && $smoke_user instanceof WP_User ) { $smoke_user->remove_cap( 'didar_manage_settings' ); }
}

if ( $failures ) {
	echo 'FAIL checks=' . $checks . ' failures=' . count( $failures ) . PHP_EOL;
	foreach ( $failures as $failure ) { echo '- ' . $failure . PHP_EOL; }
	exit( 1 );
}

echo 'PASS checks=' . $checks . ' settings_restored=YES network_calls=NO' . PHP_EOL;
