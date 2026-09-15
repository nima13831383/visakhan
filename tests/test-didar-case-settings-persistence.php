<?php

class Didar_Case_Settings_Test_Admin extends Didar_Admin {
	public function prepare_case_settings_save_for_test( $current, $input, $scope = 'all' ) {
		return $this->prepare_case_settings_save( $current, $input, $scope );
	}
}

class Test_Didar_Case_Settings_Persistence extends WP_UnitTestCase {
	private $settings_snapshot;
	private $pipeline_snapshot;
	private $deal_pipeline_snapshot;
	private $fields_snapshot;
	private $settings_missing;
	private $pipeline_missing;
	private $deal_pipeline_missing;
	private $fields_missing;

	public function set_up() {
		parent::set_up();
		$this->settings_missing = '__didar_settings_missing_' . uniqid( '', true );
		$this->pipeline_missing = '__didar_case_pipelines_missing_' . uniqid( '', true );
		$this->deal_pipeline_missing = '__didar_deal_pipelines_missing_' . uniqid( '', true );
		$this->fields_missing = '__didar_case_fields_missing_' . uniqid( '', true );
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, $this->settings_missing );
		$this->pipeline_snapshot = get_option( Didar_Case_Service::PIPELINES_OPTION, $this->pipeline_missing );
		$this->deal_pipeline_snapshot = get_option( Didar_Workflow_Manager::PIPELINES_OPTION, $this->deal_pipeline_missing );
		$this->fields_snapshot = get_option( Didar_Case_Service::FIELDS_OPTION, $this->fields_missing );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user = get_user_by( 'id', $user_id );
		$user->add_cap( 'didar_manage_settings' );
		wp_set_current_user( $user_id );
		$this->seed_case_metadata();
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$this->restore_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot, $this->settings_missing );
		$this->restore_option( Didar_Case_Service::PIPELINES_OPTION, $this->pipeline_snapshot, $this->pipeline_missing );
		$this->restore_option( Didar_Workflow_Manager::PIPELINES_OPTION, $this->deal_pipeline_snapshot, $this->deal_pipeline_missing );
		$this->restore_option( Didar_Case_Service::FIELDS_OPTION, $this->fields_snapshot, $this->fields_missing );
		parent::tear_down();
	}

	public function test_case_mapping_save_preserves_unrelated_settings_and_renders_after_reload() {
		$existing = array(
			'didar_api_key' => 'preserved-test-value',
			'pdf_settings' => array( 'print_with_files_text' => 'با فایل', 'print_without_files_text' => 'بدون فایل' ),
			'didar_field_mappings' => array( 'consultation' => array( 'first_name' => array( 'target' => 'deal_custom', 'field' => 'Deal_Name' ) ) ),
			'visa_companion_case_settings' => array( 'pipeline_id' => 'old-pipeline' ),
			'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'old-embassy-pipeline' ) ),
		);
		update_option( Didar_Settings::OPTION_NAME, $existing, false );
		$admin = $this->admin();
		$posted = $this->posted_case_settings();
		$clean = $admin->sanitize_didar_settings( $posted );
		$saved = $existing;
		$saved['visa_companion_case_settings'] = $clean['visa_companion_case_settings'];
		$saved['case_form_settings'] = $clean['case_form_settings'];
		update_option( Didar_Settings::OPTION_NAME, $saved, false );
		$reloaded = ( new Didar_Settings() )->all();

		foreach ( array( 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone' ) as $key ) {
			$this->assertSame( 'Case_' . $key, $reloaded['visa_companion_case_settings']['main_field_mappings'][ $key ] );
		}
		$this->assertSame( 'Case_submission_id', $reloaded['visa_companion_case_settings']['system_fields']['submission_id'] );
		$this->assertSame( $reloaded['visa_companion_case_settings'], $reloaded['case_form_settings']['visa_request'] );
		$this->assertSame( 'Case_full_name', $reloaded['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );
		$this->assertSame( $existing['didar_api_key'], $reloaded['didar_api_key'] );
		$this->assertSame( $existing['pdf_settings'], $reloaded['pdf_settings'] );
		$this->assertSame( $existing['didar_field_mappings'], $reloaded['didar_field_mappings'] );

		ob_start();
		$admin->render_case_companion_settings( 'didar_case_settings' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="didar_case_settings[case_form_settings][visa_request][main_field_mappings][full_name]"', $html );
		$this->assertStringContainsString( 'name="didar_case_settings[case_form_settings][visa_request][_present][main_field_mappings][full_name]" value="1"', $html );
		$this->assertSame( 1, preg_match( '/_present\]\[main_field_mappings\]\[full_name\]" value="1"><select name="didar_case_settings\[case_form_settings\]\[visa_request\]\[main_field_mappings\]\[full_name\]"/s', $html ) );
		$this->assertSame( 1, preg_match( '/name="didar_case_settings\[case_form_settings\]\[visa_request\]\[main_field_mappings\]\[full_name\]".*?value="Case_full_name"\s+selected=/s', $html ) );
		$this->assertSame( $reloaded['visa_companion_case_settings'], $reloaded['case_form_settings']['visa_request'] );
		$this->assertStringContainsString( 'name="didar_case_settings[case_form_settings][embassy_appointment][main_field_mappings][full_name]"', $html );
		$this->assertSame( 1, preg_match( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[main_field_mappings\]\[full_name\]".*?value="Case_full_name"\s+selected=/s', $html ) );

		$portable = ( new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() ) )->portable_settings( $reloaded );
		$this->assertSame( $reloaded['visa_companion_case_settings'], $portable['visa_companion_case_settings'] );
		$this->assertSame( $reloaded['case_form_settings'], $portable['case_form_settings'] );
	}

	public function test_canonical_visa_case_form_save_persists_main_mappings_and_preserves_other_settings() {
		$main = array();
		foreach ( array( 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone' ) as $key ) {
			$main[ $key ] = 'Case_' . $key;
		}
		$visa = array(
			'pipeline_id' => 'case-pipeline',
			'initial_stage_id' => 'case-stage',
			'category_id' => 'old-category',
			'field_mappings' => array( 'family_relation' => 'Case_family_relation' ),
			'system_fields' => array( 'submission_id' => 'Case_submission_id', 'companion_uid' => 'Case_companion_uid', 'form_type' => 'Case_form_type' ),
		);
		$current = array(
			'didar_api_key' => 'preserved-test-value',
			'pdf_settings' => array( 'print_with_files_text' => 'با فایل', 'print_without_files_text' => 'بدون فایل' ),
			'visa_companion_case_settings' => $visa,
			'case_form_settings' => array( 'visa_request' => $visa, 'embassy_appointment' => array( 'pipeline_id' => 'embassy-old' ) ),
		);
		$posted = array( 'case_form_settings' => array( 'visa_request' => array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'case-stage', 'main_field_mappings' => $main ) ) );
		$result = $this->case_save_admin()->prepare_case_settings_save_for_test( $current, $posted, 'visa_request' );
		$saved = $result['settings'];

		$this->assertFalse( $result['invalid'] );
		foreach ( $main as $key => $target ) {
			$this->assertSame( $target, $saved['case_form_settings']['visa_request']['main_field_mappings'][ $key ] );
		}
		$this->assertSame( $saved['visa_companion_case_settings'], $saved['case_form_settings']['visa_request'] );
		$this->assertSame( $visa['field_mappings'], $saved['case_form_settings']['visa_request']['field_mappings'] );
		$this->assertSame( $visa['system_fields'], $saved['case_form_settings']['visa_request']['system_fields'] );
		$this->assertSame( $current['case_form_settings']['embassy_appointment'], $saved['case_form_settings']['embassy_appointment'] );
		$this->assertSame( $current['didar_api_key'], $saved['didar_api_key'] );
		$this->assertSame( $current['pdf_settings'], $saved['pdf_settings'] );

		$partial = $this->case_save_admin()->prepare_case_settings_save_for_test( $saved, array( 'case_form_settings' => array( 'visa_request' => array( 'category_id' => '' ) ) ), 'visa_request' );
		$this->assertSame( 'case-pipeline', $partial['settings']['case_form_settings']['visa_request']['pipeline_id'] );
		$this->assertSame( 'case-stage', $partial['settings']['case_form_settings']['visa_request']['initial_stage_id'] );
		$this->assertSame( $main, $partial['settings']['case_form_settings']['visa_request']['main_field_mappings'] );
		$this->assertSame( '', $partial['settings']['case_form_settings']['visa_request']['category_id'] );
	}

	public function test_case_save_removes_legacy_business_companion_uid_and_preserves_system_mapping() {
		$visa = array(
			'pipeline_id' => 'case-pipeline',
			'initial_stage_id' => 'case-stage',
			'field_mappings' => array( 'companion_uid' => 'Case_companion_uid' ),
			'system_fields' => array( 'submission_id' => 'Case_submission_id', 'companion_uid' => 'Case_companion_uid', 'form_type' => 'Case_form_type' ),
		);
		$result = $this->case_save_admin()->prepare_case_settings_save_for_test(
			array( 'visa_companion_case_settings' => $visa, 'case_form_settings' => array( 'visa_request' => $visa ) ),
			array( 'case_form_settings' => array( 'visa_request' => $visa ) ),
			'visa_request'
		);

		$this->assertFalse( $result['invalid'] );
		$this->assertArrayNotHasKey( 'companion_uid', $result['settings']['case_form_settings']['visa_request']['field_mappings'] );
		$this->assertSame( $visa['system_fields'], $result['settings']['case_form_settings']['visa_request']['system_fields'] );
	}

	public function test_case_mapping_presence_markers_persist_explicit_no_mapping_and_protect_invalid_values() {
		$visa = array(
			'pipeline_id'         => 'case-pipeline',
			'initial_stage_id'    => 'case-stage',
			'field_mappings'      => array( 'family_relation' => 'Case_family_relation' ),
			'main_field_mappings' => array( 'full_name' => 'Case_full_name' ),
			'system_fields'       => array( 'submission_id' => 'Case_submission_id' ),
		);
		$current = array(
			'visa_companion_case_settings' => $visa,
			'case_form_settings'           => array( 'visa_request' => $visa ),
		);
		$admin = $this->case_save_admin();

		$cleared = $admin->prepare_case_settings_save_for_test(
			$current,
			array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'field_mappings' => array( 'family_relation' => '1' ), 'main_field_mappings' => array( 'full_name' => '1' ), 'system_fields' => array( 'submission_id' => '1' ) ) ) ) ),
			'visa_request'
		);
		$cleared_visa = $cleared['settings']['case_form_settings']['visa_request'];
		$this->assertArrayHasKey( 'family_relation', $cleared_visa['field_mappings'] );
		$this->assertSame( '', $cleared_visa['field_mappings']['family_relation'] );
		$this->assertArrayHasKey( 'full_name', $cleared_visa['main_field_mappings'] );
		$this->assertSame( '', $cleared_visa['main_field_mappings']['full_name'] );
		$this->assertArrayHasKey( 'submission_id', $cleared_visa['system_fields'] );
		$this->assertSame( '', $cleared_visa['system_fields']['submission_id'] );

		$mapped = $admin->prepare_case_settings_save_for_test(
			$cleared['settings'],
			array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'field_mappings' => array( 'family_relation' => '1' ) ), 'field_mappings' => array( 'family_relation' => 'Case_age_group' ) ) ) ),
			'visa_request'
		);
		$this->assertSame( 'Case_age_group', $mapped['settings']['case_form_settings']['visa_request']['field_mappings']['family_relation'] );

		$invalid = $admin->prepare_case_settings_save_for_test(
			$mapped['settings'],
			array( 'case_form_settings' => array( 'visa_request' => array( '_present' => array( 'field_mappings' => array( 'family_relation' => '1' ) ), 'field_mappings' => array( 'family_relation' => 'not_a_case_field' ) ) ) ),
			'visa_request'
		);
		$this->assertSame( 'Case_age_group', $invalid['settings']['case_form_settings']['visa_request']['field_mappings']['family_relation'] );

		$absent = $admin->prepare_case_settings_save_for_test( $invalid['settings'], array( 'case_form_settings' => array( 'visa_request' => array() ) ), 'visa_request' );
		$this->assertSame( 'Case_age_group', $absent['settings']['case_form_settings']['visa_request']['field_mappings']['family_relation'] );
	}

	public function test_embassy_main_full_name_can_be_explicitly_unmapped_without_resurrection() {
		$old_target = 'Field_8785_0_261';
		$new_target = 'Field_8785_0_263';
		$current = array(
			'case_form_settings' => array(
				'embassy_appointment' => array(
					'pipeline_id'         => 'case-pipeline',
					'initial_stage_id'    => 'case-stage',
					'main_field_mappings' => array( 'full_name' => $old_target ),
				),
			),
		);
		$admin = $this->case_save_admin();
		$clear_post = array(
			'case_form_settings' => array(
				'embassy_appointment' => array(
					'_present'            => array( 'main_field_mappings' => array( 'full_name' => '1' ) ),
					'main_field_mappings' => array( 'full_name' => '' ),
				),
			),
		);

		$cleared = $admin->prepare_case_settings_save_for_test( $current, $clear_post, 'embassy_appointment' );
		$stored = $cleared['settings'];
		$this->assertArrayHasKey( 'full_name', $stored['case_form_settings']['embassy_appointment']['main_field_mappings'] );
		$this->assertSame( '', $stored['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );

		update_option( Didar_Settings::OPTION_NAME, $stored, false );
		$reloaded = ( new Didar_Settings() )->all();
		$this->assertArrayHasKey( 'full_name', $reloaded['case_form_settings']['embassy_appointment']['main_field_mappings'] );
		$this->assertSame( '', $reloaded['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );
		$this->assertSame( '', ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->configuration( 'embassy_appointment' )['main_field_mappings']['full_name'] );

		ob_start();
		$this->admin()->render_case_companion_settings( 'didar_case_settings' );
		$html = ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'name="didar_case_settings[case_form_settings][embassy_appointment][main_field_mappings][full_name]"' ) );
		$this->assertSame( 1, preg_match( '/<select name="didar_case_settings\\[case_form_settings\\]\\[embassy_appointment\\]\\[main_field_mappings\\]\\[full_name\\]">\\s*<option value="">— بدون نگاشت —<\\/option>/u', $html ) );
		$this->assertSame( 0, preg_match( '/<select name="didar_case_settings\\[case_form_settings\\]\\[embassy_appointment\\]\\[main_field_mappings\\]\\[full_name\\]">.*?value="' . preg_quote( $old_target, '/' ) . '"\\s+selected=/s', $html ) );

		$second_save = $admin->prepare_case_settings_save_for_test( $reloaded, $clear_post, 'embassy_appointment' );
		$this->assertArrayHasKey( 'full_name', $second_save['settings']['case_form_settings']['embassy_appointment']['main_field_mappings'] );
		$this->assertSame( '', $second_save['settings']['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );

		$mapped = $admin->prepare_case_settings_save_for_test( $second_save['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ), 'main_field_mappings' => array( 'full_name' => $old_target ) ) ) ), 'embassy_appointment' );
		$this->assertSame( $old_target, $mapped['settings']['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );
		$remapped = $admin->prepare_case_settings_save_for_test( $mapped['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( '_present' => array( 'main_field_mappings' => array( 'full_name' => '1' ) ), 'main_field_mappings' => array( 'full_name' => $new_target ) ) ) ), 'embassy_appointment' );
		$this->assertSame( $new_target, $remapped['settings']['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );

		$portable = ( new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() ) )->portable_settings( $stored );
		$this->assertArrayHasKey( 'full_name', $portable['case_form_settings']['embassy_appointment']['main_field_mappings'] );
		$this->assertSame( '', $portable['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );
	}

	public function test_case_renderer_hides_retired_business_and_role_mappings_but_keeps_system_uid() {
		$visa = array(
			'pipeline_id' => 'case-pipeline',
			'initial_stage_id' => 'case-stage',
			'field_mappings' => array( 'companion_uid' => 'Case_companion_uid' ),
			'main_field_mappings' => array( 'case_role' => 'Case_case_role' ),
			'system_fields' => array( 'companion_uid' => 'Case_companion_uid' ),
		);
		update_option( Didar_Settings::OPTION_NAME, array( 'visa_companion_case_settings' => $visa, 'case_form_settings' => array( 'visa_request' => $visa ) ), false );
		ob_start();
		$this->admin()->render_case_companion_settings( 'didar_case_settings' );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '[field_mappings][companion_uid]', $html );
		$this->assertStringContainsString( '[system_fields][companion_uid]', $html );
		$this->assertStringNotContainsString( '[main_field_mappings][case_role]', $html );
	}

	public function test_visa_main_and_companion_case_mappings_can_reuse_targets_across_separate_cases() {
		$targets = array(
			'full_name'       => 'Field_8785_0_261',
			'occupation'      => 'Field_8785_0_263',
			'national_id'     => 'Field_8785_0_264',
			'passport_number' => 'Field_8785_0_265',
			'email'           => 'Field_8785_0_266',
			'phone'           => 'Field_8785_0_267',
		);
		$posted = array(
			'case_form_settings' => array(
				'visa_request' => array(
					'pipeline_id'         => 'case-pipeline',
					'initial_stage_id'    => 'case-stage',
					'field_mappings'      => $targets,
					'main_field_mappings' => $targets,
					'system_fields'       => array(
						'submission_id' => 'Field_8785_12_258',
						'companion_uid' => 'Field_8785_0_259',
						'form_type'     => 'Field_8785_0_260',
					),
				),
			),
		);
		$current = array(
			'visa_companion_case_settings' => array(),
			'case_form_settings'           => array( 'visa_request' => array() ),
		);

		$result = $this->case_save_admin()->prepare_case_settings_save_for_test( $current, $posted, 'visa_request' );
		$saved = $result['settings']['case_form_settings']['visa_request'];

		$this->assertFalse( $result['invalid'] );
		foreach ( $targets as $key => $target ) {
			$this->assertSame( $target, $saved['field_mappings'][ $key ] );
			$this->assertSame( $target, $saved['main_field_mappings'][ $key ] );
		}
		$this->assertSame( '', $saved['main_field_mappings']['case_role'] ?? '' );
		$this->assertSame( $saved, $result['settings']['visa_companion_case_settings'] );

		$validation = ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->validate_companion_case_configuration( $saved, 'visa_request' );
		$this->assertTrue( $validation['ready'] );
		$this->assertNotContains( 'duplicate_field_mapping', $validation['issues'] );

		$round_trip = $this->case_save_admin()->prepare_case_settings_save_for_test( $result['settings'], $posted, 'visa_request' );
		$this->assertSame( $saved, $round_trip['settings']['case_form_settings']['visa_request'] );

		$inside_companion_collision = $posted;
		$inside_companion_collision['case_form_settings']['visa_request']['field_mappings']['occupation'] = $targets['full_name'];
		$collision_result = $this->case_save_admin()->prepare_case_settings_save_for_test( $result['settings'], $inside_companion_collision, 'visa_request' );
		$this->assertSame( $targets['occupation'], $collision_result['settings']['case_form_settings']['visa_request']['field_mappings']['occupation'] );
	}

	public function test_visa_main_and_companion_payloads_use_their_own_mapping_groups() {
		$plugin = Didar_Plugin::instance();
		$mapper = new Didar_Field_Mapper( $plugin->registry, new Didar_Settings(), null, new Didar_Logger() );
		$target = 'Field_8785_0_263';
		$main = $mapper->case_fields( 'visa_request', array( 'occupation' => 'Main occupation' ), 0, array( 'occupation' => $target ) );
		$companion = $mapper->companion_case_fields( 'visa_request', array( 'occupation' => 'Companion occupation' ), 0, 0, array( 'occupation' => $target ) );

		$this->assertSame( array( $target => 'Main occupation' ), $main );
		$this->assertSame( array( $target => 'Companion occupation' ), $companion );
	}

	public function test_general_settings_save_keeps_case_settings_when_case_form_is_not_submitted() {
		$existing = array(
			'visa_companion_case_settings' => array( 'pipeline_id' => 'case-pipeline', 'main_field_mappings' => array( 'full_name' => 'Case_full_name' ) ),
			'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline', 'main_field_mappings' => array( 'full_name' => 'Case_full_name' ) ) ),
		);
		update_option( Didar_Settings::OPTION_NAME, $existing, false );
		$clean = $this->admin()->sanitize_didar_settings( array( 'frontend_requests_per_page' => 20 ) );
		$this->assertSame( $existing['visa_companion_case_settings'], $clean['visa_companion_case_settings'] );
		$this->assertSame( $existing['case_form_settings'], $clean['case_form_settings'] );
	}

	public function test_global_sanitizer_preserves_unsubmitted_applicant_note_and_handles_falsy_values() {
		$existing = array(
			'colleague_can_view_internal_history' => 1,
			'frontend_requests_per_page' => 20,
			'didar_field_mappings' => array(
				'consultation' => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Consultation_Note' ) ),
			),
			'visa_companion_case_settings' => array( 'pipeline_id' => 'case-pipeline' ),
			'case_form_settings' => array( 'visa_request' => array( 'pipeline_id' => 'case-pipeline' ) ),
		);
		update_option( Didar_Settings::OPTION_NAME, $existing, false );
		$admin = $this->admin();

		$partial = $admin->sanitize_didar_settings( array( 'colleague_can_view_internal_history' => 0, 'frontend_requests_per_page' => 0 ) );
		$this->assertSame( 0, $partial['colleague_can_view_internal_history'] );
		$this->assertSame( Didar_Settings::MIN_REQUESTS_PER_PAGE, $partial['frontend_requests_per_page'] );
		$this->assertSame( $existing['didar_field_mappings']['consultation']['applicant_note'], $partial['didar_field_mappings']['consultation']['applicant_note'] );
		$this->assertSame( $existing['visa_companion_case_settings'], $partial['visa_companion_case_settings'] );
		$this->assertSame( $existing['case_form_settings'], $partial['case_form_settings'] );

		$cleared = $admin->sanitize_didar_settings( array( 'didar_field_mappings' => array( 'consultation' => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => '' ) ) ) ) );
		$this->assertArrayNotHasKey( 'applicant_note', $cleared['didar_field_mappings']['consultation'] );
	}

	public function test_tab_scoped_saves_preserve_absent_groups_and_allow_explicit_empty_values() {
		$existing = array(
			'didar_api_key' => 'keep-secret',
			'didar_debug_logging' => 'verbose',
			'didar_default_owner_id' => 'owner-old',
			'didar_form_access' => array( 'consultation' => array( 'url' => 'https://example.test/form', 'barcode' => '' ) ),
			'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'deal-pipeline' ) ),
			'didar_user_person_mappings' => array( 'gender' => 'Person_Gender' ),
			'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES,
			'visa_companion_case_settings' => array( 'pipeline_id' => 'case-pipeline', 'system_fields' => array( 'companion_uid' => 'Case_companion_uid' ) ),
			'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline' ) ),
		);
		update_option( Didar_Settings::OPTION_NAME, $existing, false );

		$forms = $this->admin()->sanitize_didar_settings( array( '_active_tab' => 'forms', 'didar_form_access' => array( 'consultation' => array( 'url' => '', 'barcode' => '' ) ) ) );
		$this->assertSame( '', $forms['didar_form_access']['consultation']['url'] );
		$this->assertSame( $existing['didar_api_key'], $forms['didar_api_key'] );
		$this->assertSame( $existing['didar_user_person_mappings'], $forms['didar_user_person_mappings'] );
		$this->assertSame( $existing['visa_companion_case_settings'], $forms['visa_companion_case_settings'] );

		update_option( Didar_Settings::OPTION_NAME, $forms, false );
		$profile = $this->admin()->sanitize_didar_settings( array( '_active_tab' => 'profile', 'didar_user_person_mappings' => array( 'gender' => '' ), 'profile_field_states' => Didar_Settings::PROFILE_FIELD_STATES ) );
		$this->assertArrayNotHasKey( 'gender', $profile['didar_user_person_mappings'] );
		$this->assertSame( 'verbose', $profile['didar_debug_logging'] );
		$this->assertSame( $forms['didar_form_access'], $profile['didar_form_access'] );
		$this->assertSame( $existing['visa_companion_case_settings'], $profile['visa_companion_case_settings'] );

		update_option( Didar_Settings::OPTION_NAME, $profile, false );
		$general = $this->admin()->sanitize_didar_settings( array( '_active_tab' => 'general', 'didar_debug_logging' => 'errors', 'didar_default_owner_id' => '' ) );
		$this->assertSame( 'errors', $general['didar_debug_logging'] );
		$this->assertSame( '', $general['didar_default_owner_id'] );
		$this->assertSame( $profile['didar_form_access'], $general['didar_form_access'] );
		$this->assertSame( $profile['didar_user_person_mappings'], $general['didar_user_person_mappings'] );
		$this->assertSame( $existing['visa_companion_case_settings'], $general['visa_companion_case_settings'] );
	}

	public function test_form_no_mapping_clears_legacy_target_without_resurrection() {
		$existing = array(
			'didar_field_mappings' => array(
				'consultation' => array(
					'first_name' => array( 'target' => 'person_native', 'field' => 'FirstName' ),
				),
			),
		);
		update_option( Didar_Settings::OPTION_NAME, $existing, false );

		$clean = $this->admin()->sanitize_didar_settings(
			array(
				'_active_tab'          => 'forms',
				'didar_field_mappings' => array(
					'consultation' => array(
						'first_name' => array( 'target' => 'deal_custom', 'field' => '' ),
					),
				),
			)
		);
		update_option( Didar_Settings::OPTION_NAME, $clean, false );
		$reloaded = get_option( Didar_Settings::OPTION_NAME, array() );

		$this->assertArrayNotHasKey( 'first_name', $reloaded['didar_field_mappings']['consultation'] );
	}

	public function test_embassy_dedicated_case_save_preserves_visa_and_renders_valid_pipeline_stage_and_category() {
		$visa = array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'case-stage', 'category_id' => 'visa-category', 'main_field_mappings' => array( 'full_name' => 'Case_full_name' ) );
		$current = array(
			'visa_companion_case_settings' => $visa,
			'case_form_settings'          => array( 'embassy_appointment' => array( 'pipeline_id' => 'old-pipeline', 'initial_stage_id' => 'old-stage', 'category_id' => 'old-category' ) ),
		);
		$posted = array(
			'visa_companion_case_settings' => array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'case-stage', 'category_id' => 'attempted-visa-change' ),
			'case_form_settings' => array(
				'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'case-stage', 'category_id' => 'embassy-category' ),
			),
		);
		$result = $this->case_save_admin()->prepare_case_settings_save_for_test( $current, $posted, 'embassy_appointment' );
		$embassy = $result['settings']['case_form_settings']['embassy_appointment'];

		$this->assertFalse( $result['invalid'] );
		$this->assertSame( 'case-pipeline', $embassy['pipeline_id'] );
		$this->assertSame( 'case-stage', $embassy['initial_stage_id'] );
		$this->assertSame( 'embassy-category', $embassy['category_id'] );
		$this->assertSame( $visa, $result['settings']['visa_companion_case_settings'] );
		$this->assertNotContains( 'pipeline_missing', ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->validate_companion_case_configuration( $embassy, 'embassy_appointment' )['issues'] );
		$this->assertNotContains( 'stage_missing', ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->validate_companion_case_configuration( $embassy, 'embassy_appointment' )['issues'] );

		update_option( Didar_Settings::OPTION_NAME, $result['settings'], false );
		ob_start();
		$this->admin()->render_case_companion_settings( 'didar_case_settings' );
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[pipeline_id\]".*?<option value="case-pipeline"[^>]*selected/s', $html );
		$this->assertMatchesRegularExpression( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[initial_stage_id\]".*?<option value="case-stage"[^>]*selected/s', $html );
		$this->assertStringContainsString( 'name="didar_case_settings[case_form_settings][embassy_appointment][category_id]" value="embassy-category"', $html );

		$pipeline_only = $this->case_save_admin()->prepare_case_settings_save_for_test( $result['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline-b', 'initial_stage_id' => '', 'category_id' => 'pipeline-only' ) ) ), 'embassy_appointment' );
		$pipeline_only_embassy = $pipeline_only['settings']['case_form_settings']['embassy_appointment'];
		$this->assertFalse( $pipeline_only['invalid'] );
		$this->assertSame( 'case-pipeline-b', $pipeline_only_embassy['pipeline_id'] );
		$this->assertSame( '', $pipeline_only_embassy['initial_stage_id'] );
		$this->assertSame( array( 'stage_missing' ), ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->validate_companion_case_configuration( $pipeline_only_embassy, 'embassy_appointment' )['issues'] );
		update_option( Didar_Settings::OPTION_NAME, $pipeline_only['settings'], false );
		ob_start();
		$this->admin()->render_case_companion_settings( 'didar_case_settings' );
		$pipeline_only_html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[pipeline_id\]".*?<option value="case-pipeline-b"[^>]*selected/s', $pipeline_only_html );
		$this->assertMatchesRegularExpression( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[initial_stage_id\]".*?<option value="case-stage-b"/s', $pipeline_only_html );
		$stage_only = $this->case_save_admin()->prepare_case_settings_save_for_test( $pipeline_only['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'initial_stage_id' => 'case-stage-b' ) ) ), 'embassy_appointment' );
		$this->assertFalse( $stage_only['invalid'] );
		$this->assertSame( 'case-pipeline-b', $stage_only['settings']['case_form_settings']['embassy_appointment']['pipeline_id'] );
		$this->assertSame( 'case-stage-b', $stage_only['settings']['case_form_settings']['embassy_appointment']['initial_stage_id'] );

		$invalid_stage = $this->case_save_admin()->prepare_case_settings_save_for_test( $pipeline_only['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline-b', 'initial_stage_id' => 'case-stage', 'category_id' => 'invalid-stage' ) ) ), 'embassy_appointment' );
		$this->assertTrue( $invalid_stage['invalid'] );
		$this->assertSame( 'case-pipeline-b', $invalid_stage['settings']['case_form_settings']['embassy_appointment']['pipeline_id'] );
		$this->assertSame( '', $invalid_stage['settings']['case_form_settings']['embassy_appointment']['initial_stage_id'] );

		$empty = $this->case_save_admin()->prepare_case_settings_save_for_test( $pipeline_only['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => '', 'initial_stage_id' => '' ) ) ), 'embassy_appointment' );
		$this->assertFalse( $empty['invalid'] );
		$this->assertSame( array( 'pipeline_missing', 'stage_missing' ), ( new Didar_Case_Service( new Didar_Settings(), new Didar_Logger() ) )->validate_companion_case_configuration( $empty['settings']['case_form_settings']['embassy_appointment'], 'embassy_appointment' )['issues'] );

		$blank_category = $this->case_save_admin()->prepare_case_settings_save_for_test( $result['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'case-stage', 'category_id' => '' ) ) ) );
		$this->assertFalse( $blank_category['invalid'] );
		$this->assertSame( '', $blank_category['settings']['case_form_settings']['embassy_appointment']['category_id'] );

		$invalid_pipeline = $this->case_save_admin()->prepare_case_settings_save_for_test( $result['settings'], array( 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'missing-pipeline', 'initial_stage_id' => 'case-stage' ) ) ) );
		$this->assertTrue( $invalid_pipeline['invalid'] );
		$this->assertSame( $embassy['pipeline_id'], $invalid_pipeline['settings']['case_form_settings']['embassy_appointment']['pipeline_id'] );
	}

	public function test_settings_save_preserves_applicant_note_mappings_for_every_supported_form() {
		$registry = Didar_Plugin::instance()->registry;
		$submitted_mappings = array(
			'unsupported_form' => array( 'applicant_note' => array( 'field' => 'Field_Unsupported_Note' ) ),
		);
		$workflows = array();

		foreach ( $registry->all() as $form_type => $form ) {
			$workflows[ $form_type ] = array(
				'pipeline_id' => 'deal-pipeline',
				'statuses'    => array(
					'pending_review' => array( 'label' => 'Pending', 'stage_id' => 'deal-stage', 'is_default' => true, 'order' => 10 ),
				),
			);
			if ( $registry->supports_applicant_note( $form_type ) ) {
				$submitted_mappings[ $form_type ] = array(
					'applicant_note' => array( 'field' => 'Field_' . $form_type . '_Note' ),
				);
			}
		}

		update_option( Didar_Workflow_Manager::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'deal-pipeline', 'title' => 'Deal pipeline', 'type' => 'Deal', 'stages' => array( array( 'id' => 'deal-stage', 'title' => 'Pending', 'index' => 1 ) ) ) ) ), false );
		$clean = $this->admin()->sanitize_didar_settings( array( 'didar_form_workflows' => $workflows, 'didar_field_mappings' => $submitted_mappings ) );

		foreach ( $registry->all() as $form_type => $form ) {
			if ( ! $registry->supports_applicant_note( $form_type ) ) {
				continue;
			}
			$this->assertSame( array( 'target' => 'deal_custom', 'field' => 'Field_' . $form_type . '_Note' ), $clean['didar_field_mappings'][ $form_type ]['applicant_note'] );
		}
		$this->assertArrayNotHasKey( 'unsupported_form', $clean['didar_field_mappings'] );
	}

	private function admin() {
		$plugin = Didar_Plugin::instance();
		return new Didar_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	}

	private function case_save_admin() {
		$plugin = Didar_Plugin::instance();
		return new Didar_Case_Settings_Test_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	}

	private function posted_case_settings() {
		$main = array();
		foreach ( array( 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone' ) as $key ) {
			$main[ $key ] = 'Case_' . $key;
		}
		return array(
			'visa_companion_case_settings' => array(
				'pipeline_id' => 'case-pipeline',
				'initial_stage_id' => 'case-stage',
				'field_mappings' => array( 'family_relation' => 'Case_family_relation', 'age_group' => 'Case_age_group' ),
				'main_field_mappings' => $main,
				'system_fields' => array( 'submission_id' => 'Case_submission_id', 'companion_uid' => 'Case_companion_uid', 'form_type' => 'Case_form_type' ),
			),
			'case_form_settings' => array(
				'embassy_appointment' => array(
					'pipeline_id' => 'case-pipeline',
					'initial_stage_id' => 'case-stage',
					'field_mappings' => array( 'family_relation' => 'Case_family_relation', 'age_group' => 'Case_age_group' ),
					'main_field_mappings' => $main,
					'system_fields' => array( 'submission_id' => 'Case_submission_id', 'companion_uid' => 'Case_companion_uid', 'form_type' => 'Case_form_type' ),
				),
			),
		);
	}

	private function seed_case_metadata() {
		$fields = array();
		foreach ( array( 'family_relation', 'age_group', 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'case_role', 'submission_id', 'companion_uid', 'form_type' ) as $key ) {
			$fields[] = array( 'id' => 'id-' . $key, 'key' => 'Case_' . $key, 'title' => $key, 'field_type' => 'case', 'is_deleted' => false );
		}
		foreach ( array( 'Field_8785_0_261', 'Field_8785_0_263', 'Field_8785_0_264', 'Field_8785_0_265', 'Field_8785_0_266', 'Field_8785_0_267', 'Field_8785_12_258', 'Field_8785_0_259', 'Field_8785_0_260' ) as $key ) {
			$fields[] = array( 'id' => 'id-' . $key, 'key' => $key, 'title' => $key, 'field_type' => 'case', 'is_deleted' => false );
		}
		update_option( Didar_Case_Service::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'case-pipeline', 'title' => 'Case pipeline', 'type' => 'Case', 'stages' => array( array( 'id' => 'case-stage', 'title' => 'Initial', 'index' => 1 ) ) ), array( 'id' => 'case-pipeline-b', 'title' => 'Case pipeline B', 'type' => 'Case', 'stages' => array( array( 'id' => 'case-stage-b', 'title' => 'Initial B', 'index' => 1 ) ) ) ) ), false );
		update_option( Didar_Case_Service::FIELDS_OPTION, array( 'fields' => $fields ), false );
	}

	private function restore_option( $name, $value, $missing ) {
		if ( $missing === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
