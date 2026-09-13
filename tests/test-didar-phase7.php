<?php

/** Focused Phase 7 coverage for public Didar form access settings and shortcode output. */
class Test_Didar_Phase7 extends WP_UnitTestCase {
	private $settings_snapshot;
	private $transfer;
	private $shortcodes;

	public function set_up() {
		parent::set_up();
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
		$registry = new Didar_Form_Registry();
		$settings = new Didar_Settings();
		$events   = new Didar_Event_Log();
		$files    = new Didar_File_Service( $registry, $settings, $events );
		$service  = new Didar_Submission_Service( $registry, $events, $settings, $files );
		$this->shortcodes = new Didar_Shortcodes( $registry, new Didar_Field_Renderer( $settings, $files ), new Didar_Validator( $registry, $settings, $files ), $service, $settings, $files );
		$this->transfer = new Didar_Settings_Transfer( $registry, $settings, new Didar_Logger() );
	}

	public function tear_down() {
		update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot );
		parent::tear_down();
	}

	public function test_url_and_image_validation_whitelist_safe_values() {
		$this->assertSame( 'https://www.google.com/form/consultation', Didar_Form_Access::sanitize_url( 'https://www.google.com/form/consultation' ) );
		$this->assertSame( '', Didar_Form_Access::sanitize_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Didar_Form_Access::sanitize_url( 'data:text/html,test' ) );
		$this->assertSame( 'https://www.google.com/qr.webp', Didar_Form_Access::sanitize_image_url( 'https://www.google.com/qr.webp' ) );
		$this->assertSame( '', Didar_Form_Access::sanitize_image_url( 'https://www.google.com/qr.svg' ) );
	}

	public function test_shortcode_renders_link_barcode_custom_text_and_safe_fallbacks() {
		$settings = $this->settings_snapshot;
		$settings['didar_form_access'] = array(
			'consultation' => array( 'url' => 'https://www.google.com/consultation', 'barcode' => '' ),
			'visa_request' => array( 'url' => '', 'barcode' => 'https://www.google.com/visa-qr.png' ),
		);
		update_option( Didar_Settings::OPTION_NAME, $settings );

		$link = $this->shortcodes->form_access_shortcode( array( 'form' => 'consultation', 'mode' => 'link', 'text' => '<script>alert(1)</script>' ) );
		$this->assertStringContainsString( 'https://www.google.com/consultation', $link );
		$this->assertStringContainsString( 'alert(1)', $link );
		$this->assertStringNotContainsString( '<script>', $link );
		$this->assertStringContainsString( 'noopener noreferrer', $link );

		$barcode = $this->shortcodes->form_access_shortcode( array( 'form' => 'visa_request', 'mode' => 'qr' ) );
		$this->assertStringContainsString( 'didar-form-access--barcode', $barcode );
		$this->assertStringContainsString( 'بارکد فرم درخواست ویزا', $barcode );
		$this->assertStringContainsString( 'https://www.google.com/visa-qr.png', $barcode );
		$this->assertSame( '', $this->shortcodes->form_access_shortcode( array( 'form' => 'visa_request', 'mode' => 'link' ) ) );
		$this->assertSame( '', $this->shortcodes->form_access_shortcode( array( 'form' => 'consultation', 'mode' => 'barcode' ) ) );
		$this->assertStringContainsString( 'didar-form-access--link', $this->shortcodes->form_access_shortcode( array( 'form' => 'consultation', 'mode' => 'invalid' ) ) );
		$this->assertSame( '', $this->shortcodes->form_access_shortcode( array( 'form' => 'invalid_form', 'mode' => 'link' ) ) );
	}

	public function test_form_access_is_portable_in_settings_transfer() {
		$settings = $this->settings_snapshot;
		$settings['didar_form_access'] = array( 'consultation' => array( 'url' => 'https://www.google.com/consultation', 'barcode' => 'https://www.google.com/consultation.jpg' ) );
		update_option( Didar_Settings::OPTION_NAME, $settings );
		$payload = $this->transfer->export_payload();
		$this->assertArrayHasKey( 'didar_form_access', $payload['settings'] );
		$this->assertSame( 'https://www.google.com/consultation', $payload['settings']['didar_form_access']['consultation']['url'] );
		$this->assertSame( 'https://www.google.com/consultation.jpg', $payload['settings']['didar_form_access']['consultation']['barcode'] );
		$preview = $this->transfer->preview( $payload, 'merge' );
		$this->assertFalse( is_wp_error( $preview ) );
		$this->assertSame( $payload['settings']['didar_form_access'], $preview['incoming']['didar_form_access'] );
	}
}
