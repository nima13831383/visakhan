<?php

/**
 * Integration tests for the current form definitions and legacy-field safety.
 *
 * Run inside the standard WordPress PHPUnit test suite with the plugin loaded.
 */
class Test_Didar_Form_Definitions extends WP_UnitTestCase {
	private $registry;
	private $validator;
	private $service;
	private $file_service;
	private $submission_ids = array();
	private $file_ids = array();

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		Didar_Access_Control::install_roles_and_capabilities();
		Didar_Event_Log::install_schema();
		Didar_File_Service::install_schema();
		delete_option( Didar_Settings::OPTION_NAME );
		$this->registry     = new Didar_Form_Registry();
		$settings           = new Didar_Settings();
		$events             = new Didar_Event_Log();
		$this->file_service = new Didar_File_Service( $this->registry, $settings, $events );
		$this->validator    = new Didar_Validator( $this->registry, $settings, $this->file_service );
		$this->service      = new Didar_Submission_Service( $this->registry, $events, $settings, $this->file_service );
		$this->file_service->set_submission_service( $this->service );
	}

	public function tear_down() {
		global $wpdb;
		foreach ( $this->submission_ids as $submission_id ) {
			$wpdb->delete( Didar_Event_Log::table_name(), array( 'submission_id' => $submission_id ), array( '%d' ) );
			wp_delete_post( $submission_id, true );
		}
		foreach ( $this->file_ids as $file_id ) {
			$wpdb->delete( Didar_File_Service::table_name(), array( 'file_id' => $file_id ), array( '%d' ) );
		}
		delete_option( Didar_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_consultation_active_schema_and_rendering() {
		$fields = $this->registry->fields( 'consultation' );
		$this->assertSame( array( 'first_name', 'last_name', 'input_3', 'email', 'input_5', 'description' ), array_keys( $fields ) );
		$this->assertSame( 'email', $fields['email']['type'] );
		$this->assertSame( 'text', $fields['input_5']['type'] );
		$this->assertSame( array(), $fields['input_5']['options'] );
		$this->assertSame( 'textarea', $fields['description']['type'] );
		$this->assertTrue( $fields['first_name']['required'] );
		$this->assertTrue( $fields['last_name']['required'] );

		$renderer = new Didar_Field_Renderer();
		ob_start();
		$renderer->render_sections( $this->registry->get( 'consultation' ), array(), array(), 'frontend' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="didar_fields[first_name]"', $html );
		$this->assertStringContainsString( 'name="didar_fields[last_name]"', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( 'didar_fields[input_1]', $html );
		$this->assertStringNotContainsString( 'didar_fields[input_4]', $html );
		$this->assertStringNotContainsString( 'didar_fields[input_6]', $html );
		$this->assertStringNotContainsString( 'didar_fields[input_7]', $html );
		$this->assertStringNotContainsString( 'didar_fields[input_8]', $html );
	}

	public function test_consultation_validation_accepts_free_text_and_multiline_description() {
		$result = $this->validator->validate(
			'consultation',
			array(
				'first_name'  => 'علی',
				'last_name'   => 'محمدی',
				'input_3'     => '09120000000',
				'email'       => 'ali@example.com',
				'input_5'     => 'بررسی شرایط یک پرونده خاص',
				'description' => "سطر اول\nسطر دوم",
				'input_4'     => 'forged-value',
			),
			'frontend'
		);

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'بررسی شرایط یک پرونده خاص', $result['data']['input_5'] );
		$this->assertSame( "سطر اول\nسطر دوم", $result['data']['description'] );
		$this->assertArrayNotHasKey( 'input_4', $result['data'] );

		$invalid          = $this->valid_consultation_data();
		$invalid['email'] = 'not-an-email';
		$result           = $this->validator->validate( 'consultation', $invalid, 'frontend' );
		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'email', $result['errors'] );

		$missing_names               = $this->valid_consultation_data();
		$missing_names['first_name'] = '';
		$missing_names['last_name']  = '';
		$result                      = $this->validator->validate( 'consultation', $missing_names, 'frontend' );
		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'first_name', $result['errors'] );
		$this->assertArrayHasKey( 'last_name', $result['errors'] );
	}

	public function test_traveler_current_job_is_unrestricted_text_and_shared_list_remains() {
		$fields = $this->registry->fields( 'traveler_evaluation' );
		$this->assertSame( 'text', $fields['current_job']['type'] );
		$this->assertSame( array(), $fields['current_job']['options'] );
		$this->assertNotEmpty( Didar_Reference_Data::occupations_for_form( 'traveler_evaluation' ) );

		$result = $this->validator->validate( 'traveler_evaluation', array( 'current_job' => 'متخصص مرمت سازهای تاریخی' ), 'frontend' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'متخصص مرمت سازهای تاریخی', $result['data']['current_job'] );

		$renderer = new Didar_Field_Renderer();
		ob_start();
		$renderer->render_field( $fields['current_job'], '', '', 'admin' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_legacy_consultation_values_survive_active_and_workflow_updates() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => Didar_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $admin_id,
				'post_title'  => 'Legacy consultation',
			)
		);
		$this->submission_ids[] = $post_id;
		update_post_meta( $post_id, '_didar_form_type', 'consultation' );
		$legacy = array(
			'input_1' => 'علی محمدی قدیمی',
			'input_3' => '09120000000',
			'input_4' => 'motahal',
			'input_5' => 'torist',
			'input_6' => 'telfoni',
			'input_7' => '2025-01-02',
			'input_8' => array( '10:00', '11:00' ),
		);
		update_post_meta( $post_id, '_didar_fields', $legacy );

		$this->assertTrue( $this->service->update_workflow( $post_id, array( 'public_status' => 'initial_approval' ) ) );
		$this->assertSame( $legacy, $this->service->get_fields( $post_id ) );

		$legacy_edit               = $this->valid_consultation_data();
		$legacy_edit['first_name'] = '';
		$legacy_edit['last_name']  = '';
		$this->assertTrue( $this->validator->validate( 'consultation', $legacy_edit, 'admin', $post_id )['valid'] );
		$renderer = new Didar_Field_Renderer();
		ob_start();
		$renderer->render_sections( $this->registry->get( 'consultation' ), $legacy, array(), 'admin' );
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<input[^>]+id="didar-admin-first_name"(?![^>]+required)[^>]*>/', $html );
		$this->assertStringContainsString( 'اطلاعات تاریخی حفظ شده است', $html );
		$admin = new Didar_Admin( $this->registry, $renderer, $this->validator, $this->service );
		ob_start();
		$admin->render_fields_box( get_post( $post_id ) );
		$admin_html = ob_get_clean();
		$this->assertStringContainsString( 'اطلاعات تاریخی', $admin_html );
		$this->assertStringContainsString( 'نام کامل قدیمی', $admin_html );
		$this->assertStringNotContainsString( 'name="didar_fields[input_1]"', $admin_html );

		$result = $this->validator->validate( 'consultation', $this->valid_consultation_data(), 'admin', $post_id );
		$this->assertTrue( $result['valid'] );
		$this->assertTrue( $this->service->update( $post_id, 'consultation', $result['data'], 'initial_approval', $admin_id ) );
		$stored = $this->service->get_fields( $post_id );
		foreach ( array( 'input_1', 'input_4', 'input_6', 'input_7', 'input_8' ) as $legacy_key ) {
			$this->assertSame( $legacy[ $legacy_key ], $stored[ $legacy_key ] );
		}
		$this->assertSame( 'علی', $stored['first_name'] );

		$data_events = array_values( array_filter( $this->service->get_events( $post_id ), function ( $event ) { return 'submission_data_updated' === $event['event_type']; } ) );
		$this->assertCount( 1, $data_events );
		$this->assertArrayNotHasKey( 'input_1', $data_events[0]['old_value'] );
		$this->assertArrayNotHasKey( 'input_4', $data_events[0]['old_value'] );
	}

	public function test_legacy_definitions_are_read_only_and_separate_from_active_fields() {
		$legacy = $this->registry->legacy_fields( 'consultation' );
		$this->assertSame( array( 'input_1', 'input_4', 'input_6', 'input_7', 'input_8' ), array_keys( $legacy ) );
		foreach ( array_keys( $legacy ) as $name ) {
			$this->assertArrayNotHasKey( $name, $this->registry->fields( 'consultation' ) );
		}
	}

	public function test_required_overrides_resolve_default_required_and_optional_states() {
		$valid = $this->valid_consultation_data();
		unset( $valid['first_name'] );
		$this->assertFalse( $this->validator->validate( 'consultation', $valid, 'frontend' )['valid'] );

		update_option(
			Didar_Settings::OPTION_NAME,
			array( 'field_required_overrides' => array( 'consultation' => array( 'first_name' => false, 'email' => true ) ) )
		);
		$this->assertArrayNotHasKey( 'first_name', $this->validator->validate( 'consultation', $valid, 'frontend' )['errors'] );
		unset( $valid['email'] );
		$this->assertArrayHasKey( 'email', $this->validator->validate( 'consultation', $valid, 'frontend' )['errors'] );

		$renderer = new Didar_Field_Renderer();
		ob_start();
		$renderer->render_sections( $this->registry->get( 'consultation' ), array(), array(), 'frontend' );
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<input[^>]+id="didar-frontend-email"[^>]+required/', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]+id="didar-frontend-first_name"(?![^>]+required)[^>]*>/', $html );

		update_option( Didar_Settings::OPTION_NAME, array( 'field_required_overrides' => array() ) );
		$this->assertArrayHasKey( 'first_name', $this->validator->validate( 'consultation', $valid, 'frontend' )['errors'] );
	}

	public function test_visa_companions_preserve_identifiers_and_validate_nested_email() {
		$fields  = $this->registry->fields( 'visa_request' );
		$columns = $fields['companions']['columns'];
		$this->assertSame( array( 'full_name', 'age', 'occupation', 'national_id', 'email', 'phone' ), array_keys( $columns ) );

		$result = $this->validator->validate(
			'visa_request',
			array(
				'companions' => array(
					array( 'full_name' => 'همراه آزمایشی', 'age' => '08', 'occupation' => '', 'national_id' => '0012345678', 'email' => 'companion@example.com', 'phone' => '09120000001' ),
				),
			),
			'frontend'
		);
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '0012345678', $result['data']['companions'][0]['national_id'] );
		$this->assertSame( '09120000001', $result['data']['companions'][0]['phone'] );

		$invalid = $this->validator->validate( 'visa_request', array( 'companions' => array( array( 'email' => 'invalid-email' ) ) ), 'frontend' );
		$this->assertFalse( $invalid['valid'] );
		$this->assertArrayHasKey( 'companions', $invalid['errors'] );
		$malformed = $this->validator->validate( 'visa_request', array( 'companions' => array( array( 'phone' => array( 'forged' ) ) ) ), 'frontend' );
		$this->assertFalse( $malformed['valid'] );
	}

	public function test_visa_document_definitions_and_private_file_limits_are_server_enforced() {
		$fields = $this->registry->fields( 'visa_request' );
		foreach ( array( 'personal_photo', 'passport_main_page', 'round_trip_ticket', 'other_documents' ) as $field_key ) {
			$this->assertSame( 'file', $fields[ $field_key ]['type'] );
			$this->assertSame( 2, $fields[ $field_key ]['max_files'] );
			$this->assertContains( 'application/pdf', $fields[ $field_key ]['mime_types'] );
			$this->assertContains( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fields[ $field_key ]['mime_types'] );
			$this->assertContains( 'image/webp', $fields[ $field_key ]['mime_types'] );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$attachment_count = (int) wp_count_posts( 'attachment' )->inherit;
		$ids = array();
		for ( $index = 0; $index < 3; $index++ ) {
			$ids[] = $this->insert_file_record( $user_id, 0, 'personal_photo', 'temporary' );
		}
		$this->assertSame( $attachment_count, (int) wp_count_posts( 'attachment' )->inherit );
		$this->assertTrue( $this->validator->validate( 'visa_request', array( 'personal_photo' => array_slice( $ids, 0, 2 ) ), 'frontend' )['valid'] );
		$too_many = $this->validator->validate( 'visa_request', array( 'personal_photo' => $ids ), 'frontend' );
		$this->assertFalse( $too_many['valid'] );
		$this->assertArrayHasKey( 'personal_photo', $too_many['errors'] );

		$other_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		global $wpdb;
		$wpdb->update( Didar_File_Service::table_name(), array( 'owner_user_id' => $other_user ), array( 'file_id' => $ids[0] ), array( '%d' ), array( '%d' ) );
		$forged = $this->validator->validate( 'visa_request', array( 'personal_photo' => array( $ids[0] ) ), 'frontend' );
		$this->assertFalse( $forged['valid'] );
	}

	public function test_download_mode_defaults_secure_and_switches_urls_without_rewriting_file_records() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$post_id = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id ) );
		$this->submission_ids[] = $post_id;
		update_post_meta( $post_id, '_didar_form_type', 'visa_request' );
		$file_id = $this->insert_file_record( $user_id, $post_id, 'personal_photo', 'final' );
		update_post_meta( $post_id, '_didar_fields', array( 'personal_photo' => array( $file_id ) ) );

		$this->assertSame( 'secure', ( new Didar_Settings() )->file_download_mode() );
		$secure_url = $this->file_service->get_download_url( $file_id );
		$this->assertStringContainsString( 'admin-post.php', $secure_url );
		$this->assertStringNotContainsString( 'didar-private', $secure_url );

		$fields_before = get_post_meta( $post_id, '_didar_fields', true );
		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'direct' ) );
		$direct_url = $this->file_service->get_download_url( $file_id );
		$this->assertStringContainsString( 'didar-private', $direct_url );
		$this->assertStringNotContainsString( 'admin-post.php', $direct_url );
		$this->assertSame( $fields_before, get_post_meta( $post_id, '_didar_fields', true ) );
		$this->assertSame( $file_id, $this->file_service->get( $file_id )['file_id'] );

		update_option( Didar_Settings::OPTION_NAME, array( 'file_download_mode' => 'invalid' ) );
		$this->assertSame( 'secure', ( new Didar_Settings() )->file_download_mode() );
		$this->assertStringContainsString( 'admin-post.php', $this->file_service->get_download_url( $file_id ) );
	}

	private function insert_file_record( $owner_id, $submission_id, $field_key, $status ) {
		global $wpdb;
		$stored_name = wp_generate_uuid4() . '.pdf';
		$wpdb->insert(
			Didar_File_Service::table_name(),
			array(
				'original_name'  => 'passport.pdf',
				'stored_name'    => $stored_name,
				'relative_path'  => 'didar-private/tests/' . $stored_name,
				'mime_type'      => 'application/pdf',
				'extension'      => 'pdf',
				'file_size'      => 100,
				'owner_user_id'  => $owner_id,
				'submission_id'  => $submission_id,
				'form_type'      => 'visa_request',
				'field_key'      => $field_key,
				'file_status'    => $status,
				'created_at_gmt' => current_time( 'mysql', true ),
				'finalized_at_gmt' => 'final' === $status ? current_time( 'mysql', true ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$file_id          = (int) $wpdb->insert_id;
		$this->file_ids[] = $file_id;
		return $file_id;
	}

	private function valid_consultation_data() {
		return array(
			'first_name'  => 'علی',
			'last_name'   => 'محمدی',
			'input_3'     => '09120000000',
			'email'       => 'ali@example.com',
			'input_5'     => 'موضوع آزاد',
			'description' => "سطر اول\nسطر دوم",
		);
	}
}
