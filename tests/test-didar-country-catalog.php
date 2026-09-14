<?php

/** Regression coverage for the persistent country catalog and all form consumers. */
class Test_Didar_Country_Catalog extends WP_UnitTestCase {
	private $option_snapshot;
	private $option_exists;

	public function set_up() {
		parent::set_up();
		$missing = '__didar_country_catalog_missing_' . uniqid( '', true );
		$this->option_snapshot = get_option( Didar_Country_Catalog::OPTION_NAME, $missing );
		$this->option_exists = $this->option_snapshot !== $missing;
	}

	public function tear_down() {
		if ( $this->option_exists ) {
			update_option( Didar_Country_Catalog::OPTION_NAME, $this->option_snapshot, false );
		} else {
			delete_option( Didar_Country_Catalog::OPTION_NAME );
		}
		parent::tear_down();
	}

	public function test_first_load_seeds_idempotently_and_preserves_iran() {
		delete_option( Didar_Country_Catalog::OPTION_NAME );
		$first = Didar_Country_Catalog::get_countries();
		$second = Didar_Country_Catalog::get_countries();

		$this->assertSame( $first, $second );
		$this->assertArrayHasKey( 'iran', $first );
		$this->assertSame( 'ایران', $first['iran'] );
		$this->assertArrayHasKey( 'germany', $first );
		$this->assertArrayHasKey( 'canada', $first );
	}

	public function test_add_duplicate_edit_disable_and_legacy_render_are_safe() {
		$qa_key = 'country_catalog_test';
		$this->assertNotWPError( Didar_Country_Catalog::add_country( $qa_key, 'کشور آزمون' ) );
		$this->assertWPError( Didar_Country_Catalog::add_country( $qa_key, 'تکراری' ) );
		$this->assertNotWPError( Didar_Country_Catalog::update_country( $qa_key, 'کشور آزمون ویرایش' ) );
		$this->assertSame( 'کشور آزمون ویرایش', Didar_Country_Catalog::get_country_label( $qa_key ) );
		$this->assertNotWPError( Didar_Country_Catalog::set_active( $qa_key, false ) );

		$registry = new Didar_Form_Registry();
		$field = $registry->fields( 'visa_request' )['travel_destination'];
		$this->assertArrayNotHasKey( $qa_key, $field['options'] );
		$this->assertSame( 'کشور آزمون ویرایش', $field['archived_options'][ $qa_key ] );

		ob_start();
		( new Didar_Field_Renderer() )->render_sections( $registry->get( 'visa_request' ), array( 'travel_destination' => $qa_key ), array(), 'admin', 1 );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'کشور آزمون ویرایش', $html );
		$this->assertStringContainsString( 'value="' . $qa_key . '"', $html );
		$this->assertStringContainsString( 'مقدار آرشیوی', $html );
		$this->assertWPError( Didar_Country_Catalog::set_active( 'iran', false ) );
	}

	public function test_managed_catalog_reaches_nationality_and_shared_country_consumers() {
		$qa_key = 'country_consumer_test';
		$this->assertNotWPError( Didar_Country_Catalog::add_country( $qa_key, 'کشور مصرف‌کننده' ) );
		$registry = new Didar_Form_Registry();

		foreach ( array(
			array( 'embassy_appointment', 'country' ),
			array( 'embassy_appointment', 'birth_country' ),
			array( 'traveler_evaluation', 'nationality' ),
			array( 'traveler_evaluation', 'passport_issuer_country' ),
			array( 'traveler_evaluation', 'main_destination_country' ),
			array( 'traveler_evaluation', 'first_entry_country' ),
			array( 'visa_request', 'birth_country' ),
			array( 'visa_request', 'passport_issuer_country' ),
			array( 'visa_request', 'travel_destination' ),
			array( 'visa_request', 'rejection_embassy' ),
			array( 'visa_request', 'previous_schengen_country' ),
		) as $consumer ) {
			$this->assertArrayHasKey( $qa_key, $registry->fields( $consumer[0] )[ $consumer[1] ]['options'], $consumer[0] . '.' . $consumer[1] );
		}
	}
}
