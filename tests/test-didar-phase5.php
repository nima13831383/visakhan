<?php

/** Focused Phase 5 registry, validation, and profile-document contract coverage. */
class Test_Didar_Phase5 extends WP_UnitTestCase {
	public function test_embassy_country_and_all_shared_country_consumers() {
		$registry = new Didar_Form_Registry();
		$this->assertSame( 'country', $registry->fields( 'embassy_appointment' )['country']['name'] );
		$this->assertSame( 'ایران', $registry->fields( 'embassy_appointment' )['country']['options']['iran'] );
		$this->assertTrue( $registry->fields( 'embassy_appointment' )['country']['searchable'] );
		$this->assertTrue( $registry->fields( 'embassy_appointment' )['country']['allow_legacy'] );
		foreach ( array(
			array( 'traveler_evaluation', 'passport_issuer_country' ),
			array( 'traveler_evaluation', 'main_destination_country' ),
			array( 'traveler_evaluation', 'first_entry_country' ),
			array( 'visa_request', 'passport_issuer_country' ),
			array( 'visa_request', 'travel_destination' ),
			array( 'visa_request', 'previous_schengen_country' ),
		) as $consumer ) {
			$this->assertArrayHasKey( 'iran', $registry->fields( $consumer[0] )[ $consumer[1] ]['options'] );
		}
	}

	public function test_consultation_date_time_and_all_form_notes() {
		$registry = new Didar_Form_Registry();
		$this->assertSame( 'date', $registry->fields( 'consultation' )['preferred_date']['type'] );
		$this->assertSame( 'time', $registry->fields( 'consultation' )['preferred_time']['type'] );
		foreach ( array( 'consultation', 'embassy_appointment', 'traveler_evaluation', 'complaint_suggestion', 'visa_request' ) as $type ) {
			$this->assertTrue( $registry->supports_applicant_note( $type ) );
			$this->assertArrayHasKey( 'applicant_note', $registry->didar_mapping_fields( $type ) );
		}
		$validator = new Didar_Validator( $registry, new Didar_Settings() );
		$valid = $validator->validate( 'consultation', array( 'first_name' => 'A', 'last_name' => 'B', 'input_3' => '0912', 'input_5' => 'visa', 'preferred_date' => '1405/01/01', 'preferred_time' => '09:30' ) );
		$this->assertTrue( $valid['valid'] );
		$this->assertSame( '09:30', $valid['data']['preferred_time'] );
		$serializer = new Didar_Readable_Value_Serializer();
		$this->assertSame( '1403/01/01', $serializer->serialize( 'consultation', 'preferred_date', $registry->fields( 'consultation' )['preferred_date'], '2024-03-20' ) );
		$invalid = $validator->validate( 'consultation', array( 'first_name' => 'A', 'last_name' => 'B', 'input_3' => '0912', 'input_5' => 'visa', 'preferred_time' => '25:90' ) );
		$this->assertArrayHasKey( 'preferred_time', $invalid['errors'] );
	}

	public function test_profile_document_catalog_is_central_and_image_only() {
		$keys = Didar_Profile_Document_Catalog::keys();
		$this->assertSame( array( 'national_card_front', 'national_card_back', 'passport_main_page', 'personal_photo', 'birth_certificate_first_page' ), $keys );
		foreach ( $keys as $key ) {
			$definition = Didar_Profile_Document_Catalog::definition( $key );
			$this->assertSame( 'file', $definition['type'] );
			$this->assertSame( 5 * MB_IN_BYTES, $definition['max_size'] );
			$this->assertNotContains( 'application/pdf', $definition['mime_types'] );
			$this->assertContains( 'image/webp', $definition['mime_types'] );
		}
	}
}
