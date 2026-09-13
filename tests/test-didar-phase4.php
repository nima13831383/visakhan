<?php

if ( ! class_exists( 'Test_Didar_Phase4_Settings' ) ) {
	/** In-memory settings fixture; Phase 4 tests must not write the live option. */
	class Test_Didar_Phase4_Settings extends Didar_Settings {
		private $values;

		public function __construct( $values = array() ) {
			$this->values = is_array( $values ) ? $values : array();
		}

		public function all() {
			return $this->values;
		}
	}
}

class Test_Didar_Phase4 extends WP_UnitTestCase {
	private $registry;
	private $validator;

	public function setUp(): void {
		parent::setUp();
		$this->registry = new Didar_Form_Registry();
		$this->validator = new Didar_Validator( $this->registry, new Didar_Settings() );
	}

	public function test_both_forms_use_the_shared_companion_schema_and_derived_count() {
		$expected = array( 'companion_uid', 'full_name', 'family_relation', 'age', 'age_group', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'personal_photo', 'passport_main_page', 'round_trip_ticket', 'other_documents' );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $form_type ) {
			$fields = $this->registry->fields( $form_type );
			$this->assertArrayHasKey( 'companions_count', $fields );
			$this->assertSame( $expected, array_keys( $fields['companions']['columns'] ) );
			$this->assertSame( Didar_Reference_Data::family_relations(), $fields['companions']['columns']['family_relation']['options'] );
			$this->assertSame( Didar_Reference_Data::age_groups(), $fields['companions']['columns']['age_group']['options'] );
		}

		$result = $this->validator->validate( 'visa_request', array( 'companions_count' => '99', 'companions' => array( array( 'full_name' => 'همراه', 'family_relation' => 'grandmother', 'age' => '65', 'age_group' => 'child' ) ) ), 'frontend' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '1', $result['data']['companions_count'] );
		$this->assertSame( 'elderly', $result['data']['companions'][0]['age_group'] );
		$this->assertSame( 'grandmother', $result['data']['companions'][0]['family_relation'] );
	}

	public function test_age_boundaries_are_explicit_and_centralized() {
		foreach ( array( 0 => 'infant', 1 => 'infant', 2 => 'child', 12 => 'child', 13 => 'teenager', 17 => 'teenager', 18 => 'adult', 64 => 'adult', 65 => 'elderly' ) as $age => $group ) {
			$this->assertSame( $group, Didar_Companion_Model::age_group( $age ) );
		}
		$this->assertSame( '', Didar_Companion_Model::age_group( '' ) );
	}

	public function test_case_configuration_prefers_per_form_settings_and_preserves_visa_legacy_fallback() {
		$settings = new Test_Didar_Phase4_Settings( array( 'visa_companion_case_settings' => array( 'pipeline_id' => 'visa-pipeline' ), 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'embassy-pipeline' ) ) ) );
		$service = new Didar_Case_Service( $settings );
		$this->assertSame( 'visa-pipeline', $service->configuration( 'visa_request' )['pipeline_id'] );
		$this->assertSame( 'embassy-pipeline', $service->configuration( 'embassy_appointment' )['pipeline_id'] );
	}

	public function test_case_settings_export_includes_form_config_without_runtime_case_meta() {
		$settings_fixture = array( 'visa_companion_case_settings' => array( 'pipeline_id' => 'visa-pipeline', 'field_mappings' => array( 'family_relation' => 'Case_Relation' ) ), 'case_form_settings' => array( 'embassy_appointment' => array( 'pipeline_id' => 'embassy-pipeline', 'main_field_mappings' => array( 'case_role' => 'Case_Role' ) ) ), 'didar_companion_runtime' => array( 'cmp_123' => array( 'case_id' => 'remote-case' ) ) );
		$transfer = new Didar_Settings_Transfer( $this->registry, new Test_Didar_Phase4_Settings( $settings_fixture ) );
		$settings = $transfer->portable_settings();
		$this->assertArrayHasKey( 'case_form_settings', $settings );
		$this->assertArrayHasKey( 'embassy_appointment', $settings['case_form_settings'] );
		$this->assertArrayNotHasKey( 'didar_companion_runtime', $settings );
	}
}
