<?php

class Test_Didar_Case_Service extends WP_UnitTestCase {
	public function test_only_case_custom_fields_are_eligible() {
		$this->assertTrue( Didar_Case_Service::is_case_field( array( 'key' => 'case_key', 'field_type' => 'Case', 'is_deleted' => false ) ) );
		$this->assertFalse( Didar_Case_Service::is_case_field( array( 'key' => 'deal_key', 'field_type' => 'Deal', 'is_deleted' => false ) ) );
		$this->assertFalse( Didar_Case_Service::is_case_field( array( 'key' => 'person_key', 'field_type' => 'Contact', 'is_deleted' => false ) ) );
		$this->assertFalse( Didar_Case_Service::is_case_field( array( 'key' => 'deleted', 'field_type' => 'Case', 'is_deleted' => true ) ) );
	}

	public function test_pipeline_metadata_and_stage_membership_are_validated() {
		update_option( Didar_Case_Service::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'case-pipeline', 'title' => 'Visa Cases', 'type' => 'Case', 'stages' => array( array( 'id' => 'stage-a', 'title' => 'New' ) ) ) ) ), false );
		update_option( Didar_Case_Service::FIELDS_OPTION, array( 'fields' => array( array( 'id' => '1', 'key' => 'case_submission', 'title' => 'Submission', 'field_type' => 'Case', 'is_deleted' => false ) ) ), false );
		$service = new Didar_Case_Service( new Didar_Settings() );
		$base = array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'stage-a', 'category_id' => 'category-1', 'field_mappings' => array( 'full_name' => 'case_submission' ), 'system_fields' => array() );
		$this->assertTrue( $service->valid_stage( 'case-pipeline', 'stage-a' ) );
		$this->assertFalse( $service->valid_stage( 'case-pipeline', 'stage-other' ) );
		$this->assertSame( 'ready', $service->validate_companion_case_configuration( $base )['status'] );
		$invalid = $base;
		$invalid['initial_stage_id'] = 'stage-other';
		$this->assertSame( 'stale', $service->validate_companion_case_configuration( $invalid )['status'] );
	}

	public function test_missing_category_keeps_configuration_ready() {
		update_option( Didar_Case_Service::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'case-pipeline', 'title' => 'Visa Cases', 'type' => 'Case', 'stages' => array( array( 'id' => 'stage-a', 'title' => 'New' ) ) ) ) ), false );
		update_option( Didar_Case_Service::FIELDS_OPTION, array( 'fields' => array( array( 'id' => '1', 'key' => 'case_submission', 'title' => 'Submission', 'field_type' => 'Case', 'is_deleted' => false ) ) ), false );
		$service = new Didar_Case_Service( new Didar_Settings() );
		$result = $service->validate_companion_case_configuration( array( 'pipeline_id' => 'case-pipeline', 'initial_stage_id' => 'stage-a', 'field_mappings' => array( 'full_name' => 'case_submission' ) ) );
		$this->assertSame( 'ready', $result['status'] );
		$this->assertNotContains( 'category_missing', $result['issues'] );
	}
}
