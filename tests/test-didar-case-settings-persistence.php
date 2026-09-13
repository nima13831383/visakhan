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

		foreach ( array( 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'case_role' ) as $key ) {
			$this->assertSame( 'Case_' . $key, $reloaded['visa_companion_case_settings']['main_field_mappings'][ $key ] );
		}
		$this->assertSame( 'Case_submission_id', $reloaded['visa_companion_case_settings']['system_fields']['submission_id'] );
		$this->assertSame( 'Case_full_name', $reloaded['case_form_settings']['embassy_appointment']['main_field_mappings']['full_name'] );
		$this->assertSame( $existing['didar_api_key'], $reloaded['didar_api_key'] );
		$this->assertSame( $existing['pdf_settings'], $reloaded['pdf_settings'] );
		$this->assertSame( $existing['didar_field_mappings'], $reloaded['didar_field_mappings'] );

		ob_start();
		$admin->render_case_companion_settings( 'didar_case_settings' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="didar_case_settings[visa_companion_case_settings][main_field_mappings][full_name]"', $html );
		$this->assertSame( 1, preg_match( '/name="didar_case_settings\[visa_companion_case_settings\]\[main_field_mappings\]\[full_name\]".*?value="Case_full_name"\s+selected=/s', $html ) );
		$this->assertStringContainsString( 'name="didar_case_settings[case_form_settings][embassy_appointment][main_field_mappings][full_name]"', $html );
		$this->assertSame( 1, preg_match( '/name="didar_case_settings\[case_form_settings\]\[embassy_appointment\]\[main_field_mappings\]\[full_name\]".*?value="Case_full_name"\s+selected=/s', $html ) );

		$portable = ( new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() ) )->portable_settings( $reloaded );
		$this->assertSame( $reloaded['visa_companion_case_settings'], $portable['visa_companion_case_settings'] );
		$this->assertSame( $reloaded['case_form_settings'], $portable['case_form_settings'] );
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
		foreach ( array( 'full_name', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'case_role' ) as $key ) {
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
