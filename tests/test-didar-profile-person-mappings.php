<?php

class Didar_Profile_Document_File_Service_Spy extends Didar_File_Service {
	public $urls = array();

	public function __construct() {}

	public function get_profile_sync_url( $file_id, $user_id, $field_key ) {
		return $this->urls[ absint( $file_id ) ] ?? '';
	}
}

class Test_Didar_Profile_Person_Mappings extends WP_UnitTestCase {
	private $user_ids = array();
	private $settings_snapshot;
	private $settings_exists;
	private $missing_marker;

	public function set_up() {
		parent::set_up();
		$this->missing_marker = '__didar_profile_mapping_missing_' . uniqid( '', true );
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, $this->missing_marker );
		$this->settings_exists = $this->settings_snapshot !== $this->missing_marker;
		delete_option( Didar_Settings::OPTION_NAME );
	}

	public function tear_down() {
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		if ( $this->settings_exists ) {
			update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot, false );
		} else {
			delete_option( Didar_Settings::OPTION_NAME );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_all_five_profile_document_mapping_controls_render() {
		$admin = $this->admin();
		ob_start();
		$admin->render_user_person_mappings();
		$html = ob_get_clean();
		$fields = array(
			'national_card_front'          => 'تصویر روی کارت ملی',
			'national_card_back'           => 'تصویر پشت کارت ملی',
			'passport_main_page'           => 'تصویر صفحه اصلی پاسپورت',
			'personal_photo'               => 'عکس پرسنلی',
			'birth_certificate_first_page' => 'تصویر صفحه اول شناسنامه',
		);
		$previous = strpos( $html, 'profile_image_url' );
		$this->assertNotFalse( $previous );
		foreach ( $fields as $key => $label ) {
			$this->assertStringContainsString( $label, $html );
			$this->assertStringContainsString( 'didar_settings[didar_user_person_mappings][' . $key . ']', $html );
			$current = strpos( $html, $key );
			$this->assertGreaterThan( $previous, $current );
			$previous = $current;
		}
	}

	public function test_all_five_profile_document_mappings_survive_sanitization_and_reload() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_ids[] = $user_id;
		wp_set_current_user( $user_id );
		$mapping = array(
			'gender'                       => 'Field_Profile_Gender',
			'display_name'                 => 'Field_Profile_Display',
			'profile_image_url'            => 'Field_Profile_Image',
			'birth_date'                   => 'Field_Profile_BirthDate',
			'national_id'                  => 'Field_Profile_NationalId',
			'national_card_front'          => 'Field_Profile_NationalCardFront',
			'national_card_back'           => 'Field_Profile_NationalCardBack',
			'passport_main_page'           => 'Field_Profile_PassportMain',
			'personal_photo'               => 'Field_Profile_PersonalPhoto',
			'birth_certificate_first_page' => 'Field_ProfileBirthCertificate',
		);
		$admin = $this->admin();
		$sanitized = $admin->sanitize_didar_settings( array( 'didar_user_person_mappings' => $mapping ) );
		$this->assertSame( $mapping, $sanitized['didar_user_person_mappings'] );
		update_option( Didar_Settings::OPTION_NAME, $sanitized, false );
		$this->assertSame( $mapping, ( new Didar_Settings() )->all()['didar_user_person_mappings'] );
		$this->assertSame( $mapping, ( new Didar_Settings() )->all()['didar_user_person_mappings'] );
	}

	public function test_existing_profile_documents_enter_person_fields_without_file_ids_or_paths() {
		$user_id = $this->user_with_documents();
		$spy = new Didar_Profile_Document_File_Service_Spy();
		$spy->urls = array(
			101 => 'https://files.example.test/national-front.jpg',
			102 => 'https://files.example.test/national-back.jpg',
			103 => 'https://files.example.test/passport-main.jpg',
			104 => 'https://files.example.test/personal-photo.jpg',
			105 => 'https://files.example.test/birth-certificate.jpg',
		);
		$mapping = $this->document_mapping();
		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'direct', 'didar_user_person_mappings' => $mapping ), false );
		$payload = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings(), $spy ) )->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( $spy->urls[101], $payload['Fields'][ $mapping['national_card_front'] ] );
		$this->assertSame( $spy->urls[102], $payload['Fields'][ $mapping['national_card_back'] ] );
		$this->assertSame( $spy->urls[103], $payload['Fields'][ $mapping['passport_main_page'] ] );
		$this->assertSame( $spy->urls[104], $payload['Fields'][ $mapping['personal_photo'] ] );
		$this->assertSame( $spy->urls[105], $payload['Fields'][ $mapping['birth_certificate_first_page'] ] );
		$encoded = wp_json_encode( $payload );
		$this->assertStringNotContainsString( 'didar-private', $encoded );
		$this->assertStringNotContainsString( 'C:\\', $encoded );
	}

	public function test_missing_mapping_and_missing_document_are_skipped() {
		$user_id = $this->user_with_documents();
		$spy = new Didar_Profile_Document_File_Service_Spy();
		$spy->urls = array( 101 => 'https://files.example.test/front.jpg' );
		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'direct', 'didar_user_person_mappings' => array( 'national_card_front' => 'Field_Front' ) ), false );
		$payload = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings(), $spy ) )->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'https://files.example.test/front.jpg', $payload['Fields']['Field_Front'] );
		$this->assertArrayNotHasKey( 'national_card_back', $payload['Fields'] );

		update_user_meta( $user_id, Didar_Profile_Document_Catalog::META_KEY, array( 'national_card_front' => 0 ) );
		$payload = ( new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings(), $spy ) )->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertArrayNotHasKey( 'Field_Front', $payload['Fields'] ?? array() );
	}

	public function test_replacement_changes_outbound_url_and_removal_can_clear_the_mapped_field() {
		$user_id = $this->user_with_documents();
		$spy = new Didar_Profile_Document_File_Service_Spy();
		$spy->urls = array( 104 => 'https://files.example.test/old-photo.jpg', 204 => 'https://files.example.test/new-photo.jpg' );
		$mapping = array( 'personal_photo' => 'Field_PersonalPhoto' );
		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'direct', 'didar_user_person_mappings' => $mapping ), false );
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings(), $spy );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( $spy->urls[104], $payload['Fields']['Field_PersonalPhoto'] );
		update_user_meta( $user_id, Didar_Profile_Document_Catalog::META_KEY, array( 'personal_photo' => 204 ) );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( $spy->urls[204], $payload['Fields']['Field_PersonalPhoto'] );
		$this->assertNotSame( $spy->urls[104], $payload['Fields']['Field_PersonalPhoto'] );

		update_user_meta( $user_id, Didar_Profile_Document_Catalog::META_KEY, array( 'personal_photo' => 0 ) );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertArrayNotHasKey( 'Field_PersonalPhoto', $payload['Fields'] ?? array() );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ), array(), '', array( 'clear_profile_documents' => array( 'personal_photo' ) ) );
		$this->assertArrayHasKey( 'Field_PersonalPhoto', $payload['Fields'] );
		$this->assertSame( '', $payload['Fields']['Field_PersonalPhoto'] );
	}

	public function test_profile_document_mapping_keeps_user_person_identity_and_stays_out_of_deal_and_case_payloads() {
		$user_id = $this->user_with_documents();
		update_user_meta( $user_id, '_didar_person_id', 'person-stable' );
		$spy = new Didar_Profile_Document_File_Service_Spy();
		$spy->urls = array( 104 => 'https://files.example.test/photo.jpg' );
		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'direct', 'didar_user_person_mappings' => array( 'personal_photo' => 'Field_PersonalPhoto' ) ), false );
		$mapper = new Didar_Field_Mapper( new Didar_Form_Registry(), new Didar_Settings(), $spy );
		$payload = $mapper->person_payload( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'person-stable', get_user_meta( $user_id, '_didar_person_id', true ) );
		$this->assertArrayNotHasKey( 'Id', $payload );
		$this->assertSame( array(), $mapper->deal_fields( 'visa_request', array( 'personal_photo' => 104 ) ) );
		$this->assertSame( array(), $mapper->case_fields( 'visa_request', array( 'personal_photo' => 104 ), 0, array( 'personal_photo' => 'Case_Document' ) ) );
	}

	private function document_mapping() {
		return array(
			'national_card_front'          => 'Field_Profile_NationalCardFront',
			'national_card_back'           => 'Field_Profile_NationalCardBack',
			'passport_main_page'           => 'Field_Profile_PassportMain',
			'personal_photo'               => 'Field_Profile_PersonalPhoto',
			'birth_certificate_first_page' => 'Field_ProfileBirthCertificate',
		);
	}

	private function user_with_documents() {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Profile Document User', 'user_email' => 'profile-documents@example.test' ) );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, 'first_name', 'Profile' );
		update_user_meta( $user_id, 'last_name', 'Document' );
		update_user_meta( $user_id, 'digits_phone', '+989121234567' );
		update_user_meta( $user_id, Didar_Profile_Document_Catalog::META_KEY, array( 'national_card_front' => 101, 'national_card_back' => 102, 'passport_main_page' => 103, 'personal_photo' => 104, 'birth_certificate_first_page' => 105 ) );
		return $user_id;
	}

	private function admin() {
		$registry = new Didar_Form_Registry();
		$settings = new Didar_Settings();
		$files = new Didar_File_Service( $registry, $settings, new Didar_Event_Log() );
		$service = new Didar_Submission_Service( $registry, new Didar_Event_Log(), $settings, $files );
		return new Didar_Admin( $registry, new Didar_Field_Renderer( $settings, $files ), new Didar_Validator( $registry, $settings, $files ), $service, $settings, $files );
	}
}
