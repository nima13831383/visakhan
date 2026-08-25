<?php

/** Unit-like assertions for the exclusion-list semantics used by mapping validation. */
class Test_Didar_Custom_Field_Catalog extends WP_UnitTestCase {
	private function deal_field( $excluded = array() ) {
		return array( 'id' => 'field-id', 'key' => 'Field_1_0_1', 'title' => 'آزمایشی', 'field_type' => 'Deal', 'control_type' => 'TextBox', 'is_deleted' => false, 'excluded_pipeline_ids' => $excluded );
	}

	public function test_excluded_pipeline_ids_are_an_exclusion_list() {
		$field = $this->deal_field( array( 'pipeline-a', 'pipeline-b' ) );
		$this->assertFalse( Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, 'pipeline-a' ) );
		$this->assertFalse( Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, 'pipeline-b' ) );
		$this->assertTrue( Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, 'pipeline-c' ) );
	}

	public function test_empty_exclusion_list_allows_every_deal_pipeline() {
		$field = $this->deal_field();
		$this->assertTrue( Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, 'pipeline-a' ) );
		$this->assertTrue( Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, 'pipeline-b' ) );
	}

	public function test_deleted_or_non_deal_fields_cannot_be_mapped() {
		$deleted = $this->deal_field(); $deleted['is_deleted'] = true;
		$contact = $this->deal_field(); $contact['field_type'] = 'Contact';
		$this->assertFalse( Didar_Custom_Field_Catalog::is_available_for_pipeline( $deleted, 'pipeline-a' ) );
		$this->assertFalse( Didar_Custom_Field_Catalog::is_available_for_pipeline( $contact, 'pipeline-a' ) );
	}
}
