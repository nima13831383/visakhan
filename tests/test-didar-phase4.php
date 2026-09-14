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

	public function test_both_forms_use_the_shared_companion_schema_and_independent_values() {
		$expected = array( 'companion_uid', 'full_name', 'family_relation', 'age', 'age_group', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'personal_photo', 'passport_main_page', 'round_trip_ticket', 'other_documents' );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $form_type ) {
			$fields = $this->registry->fields( $form_type );
			$this->assertArrayHasKey( 'companions_count', $fields );
			$this->assertSame( $expected, array_keys( $fields['companions']['columns'] ) );
			$this->assertSame( Didar_Reference_Data::family_relations(), $fields['companions']['columns']['family_relation']['options'] );
			$this->assertSame( Didar_Reference_Data::age_groups(), $fields['companions']['columns']['age_group']['options'] );
			$this->assertEmpty( $fields['companions']['columns']['age_group']['derived'] ?? false );
			$this->assertEmpty( $fields['companions_count']['derived'] ?? false );
			$this->assertEmpty( $fields['companions_count']['readonly'] ?? false );
		}

		$result = $this->validator->validate( 'visa_request', array( 'companions_count' => '3', 'companions' => array( array( 'full_name' => 'همراه', 'family_relation' => 'grandmother', 'age' => '10', 'age_group' => 'adult' ) ) ), 'frontend' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '3', $result['data']['companions_count'] );
		$this->assertSame( 'adult', $result['data']['companions'][0]['age_group'] );
		$this->assertSame( 'grandmother', $result['data']['companions'][0]['family_relation'] );
	}

	public function test_companion_age_group_is_optional_and_rows_remain_the_case_source() {
		$result = $this->validator->validate( 'embassy_appointment', array( 'companions_count' => '0', 'companions' => array( array( 'full_name' => 'همراه اول', 'age' => '65', 'age_group' => '' ), array( 'full_name' => 'همراه دوم', 'age' => '10', 'age_group' => 'elderly' ) ) ), 'frontend' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '0', $result['data']['companions_count'] );
		$this->assertSame( '', $result['data']['companions'][0]['age_group'] );
		$this->assertSame( 'elderly', $result['data']['companions'][1]['age_group'] );
		$this->assertCount( 2, Didar_Companion_Model::rows( $result['data']['companions'] ) );
	}

	public function test_saved_independent_values_render_and_map_without_derivation() {
		$values = array(
			'companions_count' => '3',
			'companions'       => array( array( 'full_name' => 'همراه', 'age' => '10', 'age_group' => 'adult' ) ),
		);
		$renderer = new Didar_Field_Renderer( new Didar_Settings() );
		foreach ( array( 'visa_request', 'embassy_appointment' ) as $form_type ) {
			ob_start();
			$renderer->render_sections( $this->registry->get( $form_type ), $values, array(), 'frontend' );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'name="didar_fields[companions_count]" value="3"', $html );
			$this->assertStringContainsString( 'name="didar_fields[companions][0][age_group]"', $html );
			$this->assertMatchesRegularExpression( '/<option[^>]*value=["\']adult["\'][^>]*selected/', $html );
			$this->assertStringNotContainsString( 'data-derived-count-field', $html );
			$this->assertStringNotContainsString( 'data-didar-derived', $html );
		}

		$mapper = new Didar_Field_Mapper( $this->registry, new Didar_Settings() );
		$case_fields = $mapper->companion_case_fields( 'visa_request', $values['companions'][0], 0, 0, array( 'age_group' => 'Case_AgeGroup' ) );
		$this->assertSame( Didar_Reference_Data::age_groups()['adult'], $case_fields['Case_AgeGroup'] );
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
