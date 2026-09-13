<?php

class Test_Didar_Settings_Transfer extends WP_UnitTestCase {
	private $transfer;
	private $settings_exists;
	private $settings_snapshot;
	private $settings_missing_marker;

	public function set_up() {
		parent::set_up();
		$this->settings_missing_marker = '__didar_settings_missing_' . uniqid( '', true );
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, $this->settings_missing_marker );
		$this->settings_exists = $this->settings_snapshot !== $this->settings_missing_marker;
		delete_option( Didar_Settings::OPTION_NAME );
		delete_option( Didar_Settings_Transfer::BACKUPS_OPTION );
		$this->transfer = new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() );
	}

	public function tear_down() {
		try {
			if ( $this->settings_exists ) {
				update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot, false );
			} else {
				delete_option( Didar_Settings::OPTION_NAME );
			}
			delete_option( Didar_Settings_Transfer::BACKUPS_OPTION );
		} finally {
			parent::tear_down();
		}
	}

	public function test_export_excludes_secrets_and_runtime_caches() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_api_key' => 'secret', 'didar_webhook_secret' => 'token', 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'p' ) ), 'didar_pipeline_cache' => array( 'runtime' => true ) ) );
		$json = $this->transfer->export_json();
		$this->assertStringNotContainsString( 'secret', $json ); $this->assertStringNotContainsString( 'token', $json ); $this->assertStringNotContainsString( 'didar_pipeline_cache', $json );
	}

	public function test_case_configuration_is_portable_but_runtime_case_ids_are_not() {
		update_option( Didar_Settings::OPTION_NAME, array( 'visa_companion_case_settings' => array( 'pipeline_id' => 'pipeline-1', 'initial_stage_id' => 'stage-1', 'category_id' => 'category-1', 'field_mappings' => array( 'full_name' => 'Case_Name' ), 'system_fields' => array( 'companion_uid' => 'Case_UID' ) ), 'didar_companion_runtime' => array( 'cmp_123' => array( 'case_id' => 'remote-case-1' ) ) ) );
		$json = $this->transfer->export_json();
		$this->assertStringContainsString( 'pipeline-1', $json );
		$this->assertStringContainsString( 'category-1', $json );
		$this->assertStringNotContainsString( 'remote-case-1', $json );
		$this->assertStringNotContainsString( 'didar_companion_runtime', $json );
	}

	public function test_all_form_applicant_note_mappings_survive_export_import_round_trip() {
		$maps = array(
			'consultation'         => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Consultation_Note' ) ),
			'embassy_appointment'  => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Embassy_Note' ) ),
			'traveler_evaluation'  => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Traveler_Note' ) ),
			'complaint_suggestion' => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Complaint_Note' ) ),
			'visa_request'         => array( 'applicant_note' => array( 'target' => 'deal_custom', 'field' => 'Field_Visa_Note' ) ),
		);
		$case_settings = array(
			'pipeline_id'        => 'pipeline-1',
			'initial_stage_id'   => 'stage-1',
			'field_mappings'     => array( 'full_name' => 'Case_Name' ),
			'main_field_mappings' => array( 'case_role' => 'Case_Role' ),
			'system_fields'      => array( 'submission_id' => 'Case_Submission', 'companion_uid' => 'Case_UID', 'form_type' => 'Case_Form' ),
		);
		$source = array(
			'didar_field_mappings'       => $maps,
			'visa_companion_case_settings' => $case_settings,
			'case_form_settings'         => array( 'visa_request' => $case_settings, 'embassy_appointment' => $case_settings ),
			'didar_companion_runtime'    => array( 'cmp_123' => array( 'case_id' => 'remote-case-1' ) ),
			'didar_case_pipeline_cache'  => array( 'pipelines' => array( 'runtime-pipeline' ) ),
		);
		update_option( Didar_Settings::OPTION_NAME, $source, false );
		$export = $this->transfer->export_payload();
		$this->assertSame( $maps, $export['settings']['didar_field_mappings'] );
		$this->assertSame( $case_settings, $export['settings']['visa_companion_case_settings'] );
		$this->assertArrayNotHasKey( 'didar_companion_runtime', $export['settings'] );
		$this->assertArrayNotHasKey( 'didar_case_pipeline_cache', $export['settings'] );
		delete_option( Didar_Settings::OPTION_NAME );

		$portable = $this->transfer->portable_settings( $source );
		$this->assertSame( $maps, $portable['didar_field_mappings'] );
		$this->assertSame( $case_settings, $portable['visa_companion_case_settings'] );
		$this->assertSame( $case_settings, $portable['case_form_settings']['embassy_appointment'] );
		$this->assertArrayNotHasKey( 'didar_companion_runtime', $portable );
		$this->assertArrayNotHasKey( 'didar_case_pipeline_cache', $portable );

		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => $portable );
		$preview = $this->transfer->preview( $data, 'replace' );
		$this->assertEmpty( $preview['errors'] );
		$this->assertSame( $maps, $preview['incoming']['didar_field_mappings'] );
		$result = $this->transfer->apply( $preview );
		$this->assertNotWPError( $result );

		$saved = get_option( Didar_Settings::OPTION_NAME, array() );
		$this->assertSame( $maps, $saved['didar_field_mappings'] );
		$this->assertSame( $case_settings, $saved['visa_companion_case_settings'] );
		$this->assertSame( $case_settings, $saved['case_form_settings']['embassy_appointment'] );
		$this->assertArrayNotHasKey( 'didar_companion_runtime', $saved );
		$this->assertArrayNotHasKey( 'didar_case_pipeline_cache', $saved );
	}

	public function test_system_user_id_field_is_exported_and_round_trips_in_merge_and_replace_modes() {
		$value = 'Field_System_User_42';
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_system_user_id_field_id' => $value ), false );
		$payload = $this->transfer->export_payload();
		$this->assertSame( $value, $payload['settings']['didar_system_user_id_field_id'] );

		foreach ( array( 'merge', 'replace' ) as $mode ) {
			delete_option( Didar_Settings::OPTION_NAME );
			$preview = $this->transfer->preview( $payload, $mode );
			$this->assertEmpty( $preview['errors'] );
			$this->assertSame( $value, $preview['incoming']['didar_system_user_id_field_id'] );
			$result = $this->transfer->apply( $preview );
			$this->assertNotWPError( $result );
			$this->assertSame( $value, get_option( Didar_Settings::OPTION_NAME, array() )['didar_system_user_id_field_id'] );
		}
	}

	public function test_persian_labels_survive_json_round_trip() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'p', 'statuses' => array( 'pending' => array( 'label' => 'در انتظار بررسی', 'stage_id' => 's', 'is_default' => true, 'order' => 10 ) ) ) ) ) );
		$parsed = $this->transfer->parse_json( $this->transfer->export_json() ); $this->assertIsArray( $parsed ); $this->assertSame( 'در انتظار بررسی', $parsed['settings']['didar_form_workflows']['consultation']['statuses']['pending']['label'] );
	}

	public function test_rejects_malformed_and_future_schema() {
		$this->assertWPError( $this->transfer->parse_json( '{' ) );
		$this->assertWPError( $this->transfer->parse_json( wp_json_encode( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 99, 'settings' => array() ) ) ) );
	}

	public function test_merge_preserves_unrelated_and_backup_is_created() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_api_key' => 'keep', 'file_download_mode' => 'secure' ) );
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'frontend_requests_per_page' => 20 ) );
		$preview = $this->transfer->preview( $data, 'merge' ); $this->assertEmpty( $preview['errors'] ); $result = $this->transfer->apply( $preview ); $this->assertNotWPError( $result ); $saved = get_option( Didar_Settings::OPTION_NAME ); $this->assertSame( 'keep', $saved['didar_api_key'] ); $this->assertSame( 20, $saved['frontend_requests_per_page'] ); $this->assertNotEmpty( $this->transfer->latest_backup() );
	}

	public function test_missing_metadata_is_not_verified_not_invalid() {
		$cache_names = array( 'didar_deal_pipeline_cache', 'didar_custom_field_cache', 'didar_user_cache' );
		$saved = array(); foreach ( $cache_names as $name ) { $saved[ $name ] = get_option( $name, false ); delete_option( $name ); }
		$preview = $this->transfer->preview( $this->transfer->parse_json( $this->transfer->export_json() ), 'merge' );
		foreach ( $saved as $name => $value ) { if ( false !== $value ) { update_option( $name, $value, false ); } }
		$this->assertEmpty( $preview['warnings'] ); $this->assertEmpty( $preview['errors'] ); $this->assertNotEmpty( $preview['not_verified'] );
	}

	public function test_field_mappings_normalize_legacy_scalar_and_typed_shapes() {
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'didar_field_mappings' => array( 'consultation' => array( 'first_name' => 'Field_ABC_1', 'last_name' => array( 'type' => 'deal_custom', 'key' => 'Field_DEF_2' ) ) ) ) );
		$preview = $this->transfer->preview( $data, 'merge' );
		$this->assertSame( array( 'target' => 'deal_custom', 'field' => 'Field_ABC_1' ), $preview['incoming']['didar_field_mappings']['consultation']['first_name'] );
		$this->assertSame( array( 'target' => 'deal_custom', 'field' => 'Field_DEF_2' ), $preview['incoming']['didar_field_mappings']['consultation']['last_name'] );
	}

	public function test_canonical_deal_keys_and_resolved_user_survive_missing_metadata() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'technical_unit2', 'user_email' => 'a4170500@gmail.com' ) );
		$cache_names = array( Didar_Custom_Field_Catalog::OPTION_NAME, Didar_User_Catalog::OPTION_NAME, Didar_Workflow_Manager::PIPELINES_OPTION );
		$saved = array(); foreach ( $cache_names as $name ) { $saved[ $name ] = get_option( $name, false ); delete_option( $name ); }
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array(
			'didar_field_mappings' => array( 'consultation' => array(
				'first_name' => array( 'target' => 'deal_custom', 'field' => 'Field_8783_0_147' ),
				'description' => array( 'target' => 'deal_custom', 'field' => 'Field_8783_1_152' ),
			) ),
			'didar_system_form_type_field_id' => 'Field_8783_0_153',
			'didar_system_submission_id_field_id' => 'Field_8783_12_154',
			'didar_system_user_id_field_id' => 'Field_8783_0_155',
			'didar_broker_user_map' => array( array( 'wordpress_user_login' => 'technical_unit2', 'wordpress_user_email' => 'a4170500@gmail.com', 'didar_user_id' => 'fb4d560d-836d-4927-a81c-27e088c101a9' ) ),
		) );
		$preview = $this->transfer->preview( $data, 'replace' );
		$this->assertEmpty( $preview['errors'] );
		$this->assertSame( 2, $preview['trace']['metadata_validated']['total'] );
		$this->assertSame( 2, $preview['trace']['metadata_validated']['deal_custom'] );
		$result = $this->transfer->apply( $preview );
		$this->assertNotWPError( $result );
		$saved_settings = get_option( Didar_Settings::OPTION_NAME );
		$this->assertSame( 'Field_8783_12_154', $saved_settings['didar_system_submission_id_field_id'] );
		$this->assertSame( 'Field_8783_0_147', $saved_settings['didar_field_mappings']['consultation']['first_name']['field'] );
		$this->assertSame( 'fb4d560d-836d-4927-a81c-27e088c101a9', $saved_settings['didar_broker_user_map'][ $user_id ] );
		foreach ( $saved as $name => $value ) { if ( false === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
	}

	public function test_canonical_verification_ignores_mapping_order_and_scalar_representations() {
		$left = $this->transfer->canonicalize_portable_option( 'didar_field_mappings', array( 'consultation' => array( 'last_name' => array( 'field' => 'Field_B', 'target' => 'deal_custom' ), 'first_name' => array( 'type' => 'deal_custom', 'key' => 'Field_A' ) ) ) );
		$right = $this->transfer->canonicalize_portable_option( 'didar_field_mappings', array( 'consultation' => array( 'first_name' => array( 'target' => 'deal_custom', 'field' => 'Field_A' ), 'last_name' => array( 'target' => 'deal_custom', 'field' => 'Field_B' ) ) ) );
		$this->assertSame( $left, $right );
		$this->assertSame( 1, $this->transfer->canonicalize_portable_option( 'colleague_can_view_internal_history', true ) );
		$this->assertSame( 10, $this->transfer->canonicalize_portable_option( 'frontend_requests_per_page', '10' ) );
	}

	public function test_apply_accepts_unchanged_value_and_reports_real_post_write_mismatch() {
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'didar_public_status_field_id' => 'Field_expected' ) );
		$preview = $this->transfer->preview( $data, 'replace' );
		$filter = function ( $new_value ) { if ( 'Field_expected' === ( $new_value['didar_public_status_field_id'] ?? '' ) ) { $new_value['didar_public_status_field_id'] = 'Field_changed_after_write'; } return $new_value; };
		add_filter( 'pre_update_option_' . Didar_Settings::OPTION_NAME, $filter );
		$result = $this->transfer->apply( $preview );
		remove_filter( 'pre_update_option_' . Didar_Settings::OPTION_NAME, $filter );
		$this->assertWPError( $result );
		$this->assertSame( 'didar_import_verify', $result->get_error_code() );
		$this->assertSame( 'didar_public_status_field_id', $result->get_error_data()['option'] );
		$this->assertSame( '', get_option( Didar_Settings::OPTION_NAME, array() )['didar_public_status_field_id'] ?? '' );
		$unchanged = $this->transfer->preview( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'didar_public_status_field_id' => '' ) ), 'replace' );
		$this->assertNotWPError( $this->transfer->apply( $unchanged ) );
	}
}
