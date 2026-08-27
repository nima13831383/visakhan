<?php

class Didar_User_Profile_Sync_Spy extends Didar_Sync_Manager {
	public $calls = array();

	public function __construct() {}

	public function sync_user_now( $user_id, $source = 'profile_form' ) {
		$this->calls[] = array( absint( $user_id ), $source );
		return true;
	}
}

class Didar_User_Profile_Test extends WP_UnitTestCase {
	private $user_ids = array();
	private $pre_insert_filter;
	private $previous_post;

	public function tear_down() {
		if ( $this->pre_insert_filter ) {
			remove_filter( 'wp_pre_insert_user_data', $this->pre_insert_filter, 10 );
		}
		$_POST = $this->previous_post;
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

	public function test_digits_billing_names_are_profile_fallbacks_when_wordpress_names_are_empty() {
		$user_id = self::factory()->user->create();
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'billing_first_name', 'Digits First' );
		update_user_meta( $user_id, 'billing_last_name', 'Digits Last' );

		$profile = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() ) )->wordpress_user_profile( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'Digits First', $profile['first_name'] );
		$this->assertSame( 'Digits Last', $profile['last_name'] );
	}

	public function test_wordpress_names_win_over_digits_billing_name_fallbacks() {
		$user_id = self::factory()->user->create();
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'first_name', 'WordPress First' );
		update_user_meta( $user_id, 'last_name', 'WordPress Last' );
		update_user_meta( $user_id, 'billing_first_name', 'Digits First' );
		update_user_meta( $user_id, 'billing_last_name', 'Digits Last' );

		$profile = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() ) )->wordpress_user_profile( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'WordPress First', $profile['first_name'] );
		$this->assertSame( 'WordPress Last', $profile['last_name'] );
	}

	public function test_existing_nickname_is_exposed_without_using_display_name_as_a_substitute() {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Public Name' ) );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'nickname', 'Ali' );

		$profile = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings() ) )->wordpress_user_profile( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'Ali', $profile['nickname'] );
		$this->assertSame( 'Public Name', $profile['display_name'] );
	}

	public function test_nickname_normalization_preserves_an_existing_nickname() {
		$user = new WP_User( self::factory()->user->create( array( 'user_login' => 'nickname-preserved', 'display_name' => 'Public Name' ) ) );
		$this->user_ids[] = $user->ID;
		$this->assertSame( 'Stored Nickname', $this->normalized_nickname( $user, 'Stored Nickname' ) );
	}

	public function test_nickname_normalization_uses_display_name_when_nickname_is_empty() {
		$user = new WP_User( self::factory()->user->create( array( 'user_login' => 'display-name-fallback', 'display_name' => 'Digits Display Name' ) ) );
		$this->user_ids[] = $user->ID;
		$this->assertSame( 'Digits Display Name', $this->normalized_nickname( $user, '' ) );
	}

	public function test_nickname_normalization_uses_user_login_when_nickname_and_display_name_are_empty() {
		$user = new WP_User( self::factory()->user->create( array( 'user_login' => 'login-fallback', 'display_name' => '' ) ) );
		$this->user_ids[] = $user->ID;
		$this->assertSame( 'login-fallback', $this->normalized_nickname( $user, '' ) );
	}

	public function test_invalid_percent_encoded_user_nicename_uses_a_stable_ascii_fallback() {
		$user = new WP_User( self::factory()->user->create( array( 'user_login' => 'nicename-fallback-user' ) ) );
		$this->user_ids[] = $user->ID;
		$user->data->user_nicename = '%d9%86%db%8c%d9%85%d8%a7';
		$this->assertSame( 'user-' . $user->ID, $this->normalized_user_nicename( $user ) );
	}

	public function test_digits_user_with_display_name_only_saves_with_a_non_empty_nickname_and_syncs() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'digits-display-only', 'display_name' => 'Digits Display Name', 'user_email' => 'digits-display-only@example.test', 'role' => 'subscriber' ) );
		$this->user_ids[] = $user_id;
		delete_user_meta( $user_id, 'nickname' );
		update_user_meta( $user_id, 'digits_phone', '+989121234567' );
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_nicename' => '%d9%86%db%8c%d9%85%d8%a7' ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );
		$roles = get_userdata( $user_id )->roles;

		$sync    = new Didar_User_Profile_Sync_Spy();
		$profile = new Didar_User_Profile( new Didar_Form_Registry(), new Didar_Settings(), $sync );
		$captured = array();
		$this->pre_insert_filter = function( $data, $update, $user_id, $userdata ) use ( &$captured ) {
			if ( $update ) {
				$captured = array( 'nickname' => $userdata['nickname'] ?? '', 'user_nicename' => $userdata['user_nicename'] ?? '', 'keys' => array_keys( $userdata ) );
			}
			return $data;
		};
		add_filter( 'wp_pre_insert_user_data', $this->pre_insert_filter, 10, 4 );

		$this->previous_post = $_POST;
		wp_set_current_user( $user_id );
		$_POST = array(
			'didar_profile_action' => 'update',
			'didar_profile_nonce'  => wp_create_nonce( 'didar_profile_update' ),
			'didar_profile'        => array(
				'first_name'   => 'Digits',
				'last_name'    => 'User',
				'display_name' => 'Updated Display Name',
				'email'        => 'digits-display-only@example.test',
			),
		);

		$method = new ReflectionMethod( $profile, 'save_current_user' );
		$method->setAccessible( true );
		$result = $method->invoke( $profile, get_userdata( $user_id ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'Digits Display Name', get_user_meta( $user_id, 'nickname', true ) );
		$this->assertSame( 'Digits Display Name', $captured['nickname'] );
		$this->assertNotEmpty( $captured['nickname'] );
		$this->assertContains( 'nickname', $captured['keys'] );
		$this->assertSame( 'user-' . $user_id, $captured['user_nicename'] );
		$this->assertSame( 'user-' . $user_id, get_userdata( $user_id )->user_nicename );
		$this->assertSame( 'Digits', get_user_meta( $user_id, 'first_name', true ) );
		$this->assertSame( 'User', get_user_meta( $user_id, 'last_name', true ) );
		$this->assertSame( $roles, get_userdata( $user_id )->roles );
		$this->assertSame( array( array( $user_id, 'frontend_profile' ) ), $sync->calls );
	}

	private function normalized_nickname( $user, $nickname ) {
		$sync    = new Didar_User_Profile_Sync_Spy();
		$profile = new Didar_User_Profile( new Didar_Form_Registry(), new Didar_Settings(), $sync );
		$method  = new ReflectionMethod( $profile, 'normalized_nickname' );
		$method->setAccessible( true );
		return $method->invoke( $profile, $user, $nickname );
	}

	private function normalized_user_nicename( $user ) {
		$sync    = new Didar_User_Profile_Sync_Spy();
		$profile = new Didar_User_Profile( new Didar_Form_Registry(), new Didar_Settings(), $sync );
		$method  = new ReflectionMethod( $profile, 'normalized_user_nicename' );
		$method->setAccessible( true );
		return $method->invoke( $profile, $user );
	}
}
