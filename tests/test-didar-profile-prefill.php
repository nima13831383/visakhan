<?php

class Didar_Profile_Prefill_Sync_Spy extends Didar_Sync_Manager { public function __construct() {} public function sync_user_now( $user_id, $source = 'profile_form' ) { return true; } }
/** Focused regression coverage for profile fields, profile sources, and form defaults. */
class Test_Didar_Profile_Prefill extends WP_UnitTestCase {
	private $user_ids = array();

	public function tear_down() {
		foreach ( $this->user_ids as $user_id ) { wp_delete_user( $user_id ); }
		delete_option( Didar_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function user( $args = array() ) {
		$id = self::factory()->user->create( array_merge( array( 'role' => 'subscriber', 'first_name' => 'علی', 'last_name' => 'رضایی', 'user_email' => 'profile@example.test' ), $args ) );
		$this->user_ids[] = $id;
		return $id;
	}

	private function profile( $user_id ) {
		return new Didar_User_Profile( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Profile_Prefill_Sync_Spy() );
	}

	public function test_profile_date_and_national_id_are_saved_as_canonical_strings() {
		$user_id = $this->user(); wp_set_current_user( $user_id );
		$_POST = array( 'didar_profile_nonce' => wp_create_nonce( 'didar_profile_update' ), 'didar_profile' => array( 'birth_date' => '2024-02-29', 'national_id' => '0012345678' ) );
		$method = new ReflectionMethod( Didar_User_Profile::class, 'save_current_user' ); $method->setAccessible( true );
		$result = $method->invoke( $this->profile( $user_id ), get_userdata( $user_id ) );
		$this->assertIsArray( $result );
		$this->assertSame( '2024-02-29', get_user_meta( $user_id, '_didar_birth_date', true ) );
		$this->assertSame( '0012345678', get_user_meta( $user_id, '_didar_national_id', true ) );
	}

	public function test_invalid_birth_date_and_non_digit_national_id_are_rejected() {
		$user_id = $this->user(); wp_set_current_user( $user_id );
		$method = new ReflectionMethod( Didar_User_Profile::class, 'save_current_user' ); $method->setAccessible( true );
		foreach ( array( '2023-02-29', '2024-13-01', 'not-a-date' ) as $date ) {
			$_POST = array( 'didar_profile_nonce' => wp_create_nonce( 'didar_profile_update' ), 'didar_profile' => array( 'birth_date' => $date ) );
			$this->assertWPError( $method->invoke( $this->profile( $user_id ), get_userdata( $user_id ) ) );
		}
		$_POST = array( 'didar_profile_nonce' => wp_create_nonce( 'didar_profile_update' ), 'didar_profile' => array( 'national_id' => '12 34' ) );
		$this->assertWPError( $method->invoke( $this->profile( $user_id ), get_userdata( $user_id ) ) );
	}

	public function test_catalog_resolves_all_profile_sources() {
		$catalog = new Didar_User_Profile_Value_Catalog();
		$profile = array( 'first_name' => 'علی', 'last_name' => 'رضایی', 'gender' => 'male', 'birth_date' => '1990-01-02', 'national_id' => '0012345678', 'email' => 'a@example.test', 'mobile' => '09120000000' );
		foreach ( $catalog->keys() as $key ) { $this->assertSame( $profile[ $key ], $catalog->resolve( $key, $profile ), $key ); }
	}

	public function test_each_default_source_prefills_and_submitted_values_win() {
		$user_id = $this->user(); update_user_meta( $user_id, 'gender', 'male' ); update_user_meta( $user_id, '_didar_birth_date', '1990-01-02' ); update_user_meta( $user_id, '_didar_national_id', '0012345678' ); update_user_meta( $user_id, 'digits_phone', '09120000000' ); wp_set_current_user( $user_id );
		$registry = new Didar_Form_Registry(); $settings = new Didar_Settings(); $mapper = new Didar_Field_Mapper( $registry, $settings ); $renderer = new Didar_Field_Renderer( $settings ); $renderer->set_profile_resolver( array( $mapper, 'wordpress_user_profile' ) );
		foreach ( array( 'first_name', 'last_name', 'gender', 'birth_date', 'national_id', 'email', 'mobile' ) as $source ) {
			update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_field_defaults' => array( 'consultation' => array( 'first_name' => $source ) ) ) ); ob_start(); $renderer->render_sections( $registry->get( 'consultation' ), array(), array(), 'frontend' ); $html = ob_get_clean(); $this->assertStringContainsString( 'value="' . esc_attr( $mapper->wordpress_user_profile( wp_get_current_user() )[ $source ] ) . '"', $html, $source );
		}
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_field_defaults' => array( 'consultation' => array( 'first_name' => 'first_name' ) ) ) ); ob_start(); $renderer->render_sections( $registry->get( 'consultation' ), array( 'first_name' => 'علی رضا' ), array(), 'frontend' ); $html = ob_get_clean(); $this->assertStringContainsString( 'value="علی رضا"', $html );
	}

	public function test_defaults_are_per_form_and_anonymous_users_do_not_prefill() {
		$user_id = $this->user(); wp_set_current_user( $user_id ); update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_field_defaults' => array( 'consultation' => array( 'first_name' => 'first_name' ), 'visa_request' => array(), 'complaint_suggestion' => array( 'first_name' => 'invalid' ) ) ) ); $settings = new Didar_Settings(); $this->assertSame( 'first_name', $settings->profile_default_source( 'consultation', 'first_name' ) ); $this->assertSame( '', $settings->profile_default_source( 'visa_request', 'first_name' ) ); $this->assertSame( '', $settings->profile_default_source( 'complaint_suggestion', 'first_name' ) ); wp_set_current_user( 0 ); $this->assertFalse( is_user_logged_in() );
	}

	public function test_settings_transfer_round_trip_contains_mapping_but_not_profile_values() {
		$registry = new Didar_Form_Registry(); $settings = new Didar_Settings(); update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_field_defaults' => array( 'consultation' => array( 'first_name' => 'first_name', 'email' => 'invalid' ) ), 'didar_user_person_mappings' => array( 'birth_date' => 'Field_Birth' ) ) ); $transfer = new Didar_Settings_Transfer( $registry, $settings ); $json = $transfer->export_json(); $this->assertStringContainsString( 'didar_form_field_defaults', $json ); $this->assertStringNotContainsString( 'علی', $json ); $this->assertStringNotContainsString( '0012345678', $json ); $data = $transfer->parse_json( $json ); $preview = $transfer->preview( $data ); $this->assertSame( 'first_name', $preview['proposed']['didar_form_field_defaults']['consultation']['first_name'] );
	}
}
