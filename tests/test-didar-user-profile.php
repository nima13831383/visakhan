<?php

class Didar_User_Profile_Test extends WP_UnitTestCase {
	private $user_ids = array();

	public function tear_down() {
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		delete_option( Didar_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_iranian_mobile_variants_normalize_to_one_documented_didar_format() {
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() );
		foreach ( array( '09121234567', '989121234567', '+989121234567', '00989121234567', '۰۹۱۲۱۲۳۴۵۶۷' ) as $value ) {
			$this->assertSame( '09121234567', $mapper->normalize_mobile( $value ) );
		}
	}

	public function test_mobile_remains_server_side_readonly_without_a_verified_digits_change_flow() {
		update_option( Didar_Settings::OPTION_NAME, array( 'profile_field_states' => array( 'mobile' => 'editable' ) ) );
		$this->assertSame( 'readonly', ( new Didar_Settings() )->profile_field_state( 'mobile' ) );
	}

	public function test_person_payload_uses_profile_only_and_optional_contact_custom_mappings() {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Test Display', 'user_email' => 'profile-test@example.test' ) );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'first_name', 'Test' );
		update_user_meta( $user_id, 'last_name', 'User' );
		update_user_meta( $user_id, 'digits_phone', '+989121234567' );
		update_user_meta( $user_id, 'gender', 'female' );
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_default_owner_id' => 'owner-id', 'didar_user_person_mappings' => array( 'gender' => 'Field_contact_gender', 'display_name' => 'Field_contact_display' ) ) );
		$mapper  = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'person', $payload['Type'] );
		$this->assertSame( '09121234567', $payload['MobilePhone'] );
		$this->assertSame( 'female', $payload['Fields']['Field_contact_gender'] );
		$this->assertSame( 'Test Display', $payload['Fields']['Field_contact_display'] );
	}

	public function test_legacy_gender_is_migrated_only_when_canonical_value_is_empty() {
		$user_id = self::factory()->user->create();
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'didar_gender', 'female' );
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() );
		$this->assertSame( 'female', $mapper->wordpress_user_profile( get_user_by( 'id', $user_id ) )['gender'] );
		$this->assertSame( 'female', get_user_meta( $user_id, 'gender', true ) );
		update_user_meta( $user_id, 'gender', 'مرد' );
		$this->assertSame( 'male', $mapper->wordpress_user_profile( get_user_by( 'id', $user_id ) )['gender'] );
		$this->assertSame( 'male', get_user_meta( $user_id, 'gender', true ) );
	}

	public function test_legacy_female_gender_values_normalize_to_female() {
		$user_id = self::factory()->user->create();
		$this->user_ids[] = $user_id;
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() );
		foreach ( array( 'female', 'زن', 'خانم' ) as $value ) {
			update_user_meta( $user_id, 'gender', $value );
			$this->assertSame( 'female', $mapper->wordpress_user_profile( get_user_by( 'id', $user_id ) )['gender'] );
			$this->assertSame( 'female', get_user_meta( $user_id, 'gender', true ) );
		}
	}

	public function test_profile_image_uses_canonical_value_and_migrates_legacy_identifier() {
		$user_id = self::factory()->user->create();
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'didar_profile_image_id', '123' );
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() );
		$mapper->wordpress_user_profile( get_user_by( 'id', $user_id ) );
		$this->assertSame( '123', get_user_meta( $user_id, 'profile_image', true ) );
		update_user_meta( $user_id, 'profile_image', 'https://example.com/profile.jpg' );
		$this->assertSame( 'https://example.com/profile.jpg', $mapper->wordpress_user_profile( get_user_by( 'id', $user_id ) )['profile_image_url'] );
	}
}
