<?php

/** Regression coverage for Visa Request conditional travel-history fields. */
class Test_Didar_Visa_History extends WP_UnitTestCase {
	private $registry;
	private $validator;
	private $post_ids = array();

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		delete_option( Didar_Settings::OPTION_NAME );
		$this->registry  = new Didar_Form_Registry();
		$this->validator = new Didar_Validator( $this->registry, new Didar_Settings() );
	}

	public function tear_down() {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		delete_option( Didar_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_history_fields_are_conditional_multiselects_and_date_range_is_registered() {
		$fields = $this->registry->fields( 'visa_request' );
		$this->assertSame( 'کشورهای سفارت ریجکت‌کننده', $fields['rejection_embassy']['label'] );
		$this->assertTrue( $fields['rejection_embassy']['multiple'] );
		$this->assertTrue( $fields['rejection_embassy']['half_width'] );
		$this->assertSame( 'has_rejection', $fields['rejection_embassy']['conditional_on'] );
		$this->assertTrue( $fields['previous_schengen_country']['multiple'] );
		$this->assertTrue( $fields['previous_schengen_country']['half_width'] );
		$this->assertSame( 'has_previous_schengen', $fields['previous_schengen_country']['conditional_on'] );
		$this->assertSame( 'از تاریخ', $fields['estimated_travel_date']['label'] );
		$this->assertSame( 'تا تاریخ', $fields['estimated_travel_end_date']['label'] );
		$this->assertSame( 'estimated_travel_date', $fields['estimated_travel_end_date']['date_range_start'] );

		ob_start();
		( new Didar_Field_Renderer() )->render_sections( $this->registry->get( 'visa_request' ), array(), array(), 'frontend' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="didar_fields[rejection_embassy][]"', $html );
		$this->assertStringContainsString( 'multiple="multiple"', $html );
		$this->assertStringContainsString( 'data-didar-conditional-on="has_rejection"', $html );
		$this->assertStringContainsString( 'data-didar-date-range-start="estimated_travel_date"', $html );
		$this->assertMatchesRegularExpression( '/class="didar-field didar-field--select didar-conditional-hidden" data-didar-field="rejection_embassy"/', $html );
		$this->assertMatchesRegularExpression( '/class="didar-field didar-field--select didar-conditional-hidden" data-didar-field="previous_schengen_country"/', $html );
	}

	public function test_conditional_validation_clears_new_inactive_values_and_accepts_valid_multiple_countries() {
		$inactive = $this->validator->validate( 'visa_request', array( 'has_rejection' => 'no', 'rejection_embassy' => array( 'germany', 'canada' ), 'rejection_date' => '1405/01/10' ), 'frontend' );
		$this->assertSame( array(), $inactive['data']['rejection_embassy'] );
		$this->assertSame( '', $inactive['data']['rejection_date'] );
		$this->assertArrayNotHasKey( 'rejection_embassy', $inactive['errors'] );

		$active = $this->validator->validate( 'visa_request', array( 'has_rejection' => 'yes', 'rejection_embassy' => array( 'germany', 'iran' ), 'rejection_date' => '1405/01/10' ), 'frontend' );
		$this->assertSame( array( 'germany', 'iran' ), $active['data']['rejection_embassy'] );
		$this->assertSame( '2026-03-30', $active['data']['rejection_date'] );
	}

	public function test_previous_schengen_date_range_and_legacy_edit_values_are_preserved_safely() {
		$range = $this->validator->validate( 'visa_request', array( 'has_previous_schengen' => 'yes', 'estimated_travel_date' => '1405/02/10', 'estimated_travel_end_date' => '1405/02/09' ), 'frontend' );
		$this->assertArrayHasKey( 'estimated_travel_end_date', $range['errors'] );

		$post_id = self::factory()->post->create( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish' ) );
		$this->post_ids[] = $post_id;
		update_post_meta( $post_id, '_didar_fields', array( 'has_rejection' => 'no', 'rejection_embassy' => 'legacy-country', 'rejection_date' => '2024-01-01' ) );
		$edit = $this->validator->validate( 'visa_request', array( 'has_rejection' => 'no' ), 'frontend', $post_id );
		$this->assertSame( 'legacy-country', $edit['data']['rejection_embassy'] );
		$this->assertSame( '2024-01-01', $edit['data']['rejection_date'] );

		$legacy_multi = $this->validator->validate( 'visa_request', array( 'has_previous_schengen' => 'yes', 'previous_schengen_country' => 'legacy-country' ), 'frontend', $post_id );
		$this->assertSame( array( 'legacy-country' ), $legacy_multi['data']['previous_schengen_country'] );
	}

	public function test_readable_serialization_and_settings_transfer_keep_history_mappings() {
		$fields     = $this->registry->fields( 'visa_request' );
		$serializer = new Didar_Readable_Value_Serializer();
		$this->assertSame( 'آلمان، ایران', $serializer->serialize( 'visa_request', 'rejection_embassy', $fields['rejection_embassy'], array( 'germany', 'iran' ) ) );
		$this->assertSame( '1405/02/10', $serializer->serialize( 'visa_request', 'estimated_travel_end_date', $fields['estimated_travel_end_date'], '2026-04-30' ) );

		$transfer = new Didar_Settings_Transfer( $this->registry, new Didar_Settings() );
		$portable = $transfer->portable_settings( array( 'didar_field_mappings' => array( 'visa_request' => array( 'estimated_travel_end_date' => array( 'target' => 'deal_custom', 'field' => 'Configured_Field_Id' ) ) ) ) );
		$this->assertSame( 'Configured_Field_Id', $portable['didar_field_mappings']['visa_request']['estimated_travel_end_date']['field'] );
	}

	public function test_admin_history_uses_persian_event_and_field_presentation() {
		$service = new Didar_Submission_Service( $this->registry, new Didar_Event_Log(), new Didar_Settings() );
		$this->assertSame( 'درخواست به زباله‌دان منتقل شد', $service->get_event_label( 'request_trashed' ) );
		$this->assertSame( 'کشورهای سفارت ریجکت‌کننده', $service->get_event_context_label( array( 'event_meta' => array( 'form_type' => 'visa_request', 'field_name' => 'rejection_embassy' ) ) ) );
		$this->assertSame( 'کشورهای سفارت ریجکت‌کننده: آلمان، ایران', $service->format_event_value( 'submission_data_updated', array( 'کشورهای سفارت ریجکت‌کننده' => array( 'germany', 'iran' ) ), array( 'form_type' => 'visa_request' ) ) );
	}

	public function test_server_render_applies_the_conditional_state_to_actual_field_wrappers() {
		$rejection_children = array( 'rejection_embassy', 'rejection_date' );
		$schengen_children  = array( 'previous_schengen_country', 'previous_schengen_date', 'estimated_travel_date', 'estimated_travel_end_date', 'schengen_exit_place' );

		$new_html = $this->render_visa_history( array() );
		foreach ( array_merge( $rejection_children, $schengen_children ) as $name ) {
			$this->assert_hidden_wrapper( $new_html, $name );
		}
		$this->assert_visible_wrapper( $new_html, 'has_rejection' );
		$this->assert_visible_wrapper( $new_html, 'has_previous_schengen' );

		$rejection_yes_html = $this->render_visa_history( array( 'has_rejection' => 'yes' ) );
		foreach ( $rejection_children as $name ) {
			$this->assert_visible_wrapper( $rejection_yes_html, $name );
		}
		foreach ( $schengen_children as $name ) {
			$this->assert_hidden_wrapper( $rejection_yes_html, $name );
		}

		$rejection_no_html = $this->render_visa_history( array( 'has_rejection' => 'no' ) );
		foreach ( $rejection_children as $name ) {
			$this->assert_hidden_wrapper( $rejection_no_html, $name );
		}

		$edit_html = $this->render_visa_history( array( 'has_rejection' => 'yes', 'has_previous_schengen' => 'yes', 'rejection_embassy' => array( 'germany' ) ) );
		foreach ( array_merge( $rejection_children, $schengen_children ) as $name ) {
			$this->assert_visible_wrapper( $edit_html, $name );
		}
		$this->assertStringContainsString( 'value="germany"', $edit_html );

		$failed_validation_html = $this->render_visa_history( array( 'has_rejection' => '', 'has_previous_schengen' => '', 'rejection_embassy' => array( 'germany' ), 'previous_schengen_country' => array( 'canada' ) ) );
		foreach ( array_merge( $rejection_children, $schengen_children ) as $name ) {
			$this->assert_hidden_wrapper( $failed_validation_html, $name );
		}
		$this->assertStringContainsString( 'value="germany"', $failed_validation_html );
		$this->assertStringContainsString( 'value="canada"', $failed_validation_html );
	}

	public function test_conditional_hidden_css_is_scoped_to_the_form_wrapper() {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/frontend.css' );
		$this->assertStringContainsString( '.didar-form .didar-field.didar-conditional-hidden,', $css );
		$this->assertStringContainsString( '.didar-form .didar-field[hidden] { display: none !important; }', $css );
	}

	public function test_hidden_conditional_fields_skip_required_validation_until_parent_is_yes() {
		update_option( Didar_Settings::OPTION_NAME, array( 'field_required_overrides' => array( 'visa_request' => array( 'rejection_embassy' => true ) ) ) );
		$hidden = $this->validator->validate( 'visa_request', array( 'has_rejection' => 'no' ), 'frontend' );
		$this->assertArrayNotHasKey( 'rejection_embassy', $hidden['errors'] );

		$active = $this->validator->validate( 'visa_request', array( 'has_rejection' => 'yes' ), 'frontend' );
		$this->assertArrayHasKey( 'rejection_embassy', $active['errors'] );
	}

	private function render_visa_history( $values ) {
		ob_start();
		( new Didar_Field_Renderer() )->render_sections( $this->registry->get( 'visa_request' ), $values, array(), 'frontend' );
		return ob_get_clean();
	}

	private function assert_hidden_wrapper( $html, $name ) {
		$wrapper = $this->wrapper_attributes( $html, $name );
		$this->assertNotNull( $wrapper, $name . ' wrapper must be rendered.' );
		$this->assertTrue( $wrapper['hidden'], $name . ' must have the hidden attribute on its actual field wrapper.' );
		$this->assertSame( 'true', $wrapper['aria_hidden'], $name . ' must be aria-hidden.' );
		$this->assertTrue( $wrapper['conditional_hidden'], $name . ' must have the conditional hidden class.' );
	}

	private function assert_visible_wrapper( $html, $name ) {
		$wrapper = $this->wrapper_attributes( $html, $name );
		$this->assertNotNull( $wrapper, $name . ' wrapper must be rendered.' );
		$this->assertFalse( $wrapper['hidden'], $name . ' must not be hidden.' );
		$this->assertFalse( $wrapper['conditional_hidden'], $name . ' must not have the conditional hidden class.' );
	}

	private function wrapper_attributes( $html, $name ) {
		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$nodes = ( new DOMXPath( $document ) )->query( '//*[@data-didar-field="' . $name . '"]' );
		if ( ! $nodes || 1 !== $nodes->length ) {
			return null;
		}
		$wrapper = $nodes->item( 0 );
		return array(
			'hidden'             => $wrapper->hasAttribute( 'hidden' ),
			'aria_hidden'        => $wrapper->getAttribute( 'aria-hidden' ),
			'conditional_hidden' => false !== strpos( ' ' . $wrapper->getAttribute( 'class' ) . ' ', ' didar-conditional-hidden ' ),
		);
	}
}
