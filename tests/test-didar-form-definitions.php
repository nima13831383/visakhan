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

	public function test_file_schema_version_is_saved_only_for_a_complete_schema() {
		update_option( Didar_File_Service::SCHEMA_VERSION_OPTION, '0.9.0', false );
		delete_option( Didar_File_Service::SCHEMA_VERIFIED_OPTION );

		$this->assertTrue( Didar_File_Service::maybe_upgrade() );
		$this->assertTrue( Didar_File_Service::schema_is_current() );
		$this->assertSame( Didar_File_Service::SCHEMA_VERSION, get_option( Didar_File_Service::SCHEMA_VERSION_OPTION ) );
		$this->assertSame( Didar_File_Service::SCHEMA_VERSION, get_option( Didar_File_Service::SCHEMA_VERIFIED_OPTION ) );
	}

	public function test_file_schema_upgrade_recreates_a_missing_table_with_a_stale_success_marker() {
		global $wpdb;

		$table_name = Didar_File_Service::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( Didar_File_Service::SCHEMA_VERSION_OPTION, Didar_File_Service::SCHEMA_VERSION, false );
		delete_option( Didar_File_Service::SCHEMA_VERIFIED_OPTION );

		$this->assertFalse( Didar_File_Service::schema_is_current() );
		$this->assertTrue( Didar_File_Service::maybe_upgrade() );
		$this->assertTrue( Didar_File_Service::schema_is_current() );
		$this->assertSame( Didar_File_Service::SCHEMA_VERSION, get_option( Didar_File_Service::SCHEMA_VERIFIED_OPTION ) );
	}

	public function test_consultation_active_schema_and_rendering() {
		$fields = $this->registry->fields( 'consultation' );
		$this->assertSame( array( 'first_name', 'last_name', 'input_3', 'email', 'input_5', 'description', 'preferred_date', 'preferred_time' ), array_keys( $fields ) );
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

	public function test_iran_is_shared_by_country_fields_and_birth_country_defaults() {
		$countries = Didar_Reference_Data::countries();
		$this->assertArrayHasKey( 'iran', $countries );
		$this->assertSame( 'ایران', $countries['iran'] );

		$expected_country_fields = array(
			'embassy_appointment' => array( 'country', 'birth_country' ),
			'traveler_evaluation' => array( 'passport_issuer_country', 'main_destination_country', 'first_entry_country' ),
			'visa_request'        => array( 'birth_country', 'passport_issuer_country', 'travel_destination', 'previous_schengen_country' ),
		);
		foreach ( $expected_country_fields as $form_type => $field_names ) {
			$fields = $this->registry->fields( $form_type );
			foreach ( $field_names as $field_name ) {
				$this->assertArrayHasKey( $field_name, $fields );
				$this->assertArrayHasKey( 'iran', $fields[ $field_name ]['options'], $form_type . '.' . $field_name );
			}
		}

		foreach ( array( 'embassy_appointment', 'visa_request' ) as $form_type ) {
			$field = $this->registry->fields( $form_type )['birth_country'];
			$this->assertSame( 'iran', $field['default'] );

			ob_start();
			( new Didar_Field_Renderer() )->render_sections( $this->registry->get( $form_type ), array(), array(), 'frontend' );
			$html = ob_get_clean();
			$this->assertMatchesRegularExpression( '/<option[^>]+value="iran"[^>]+selected/', $html, $form_type );
		}
	}

	public function test_birth_country_validation_and_rendering_preserve_explicit_values() {
		foreach ( array( 'embassy_appointment', 'visa_request' ) as $form_type ) {
			$iran = $this->validator->validate( $form_type, array( 'birth_country' => 'iran' ), 'frontend' );
			$this->assertTrue( $iran['valid'], $form_type );
			$this->assertSame( 'iran', $iran['data']['birth_country'] );

			ob_start();
			( new Didar_Field_Renderer() )->render_sections( $this->registry->get( $form_type ), array( 'birth_country' => 'canada' ), array(), 'frontend' );
			$html = ob_get_clean();
			$this->assertMatchesRegularExpression( '/<option[^>]+value="canada"[^>]+selected/', $html, $form_type );
		$this->assertDoesNotMatchRegularExpression( '/<option[^>]+value="iran"[^>]+selected/', $html, $form_type );
		}
	}

	public function test_phase_three_embassy_and_visa_base_schema_uses_central_catalogs() {
		$service_types = Didar_Reference_Data::service_types();
		$this->assertSame( array( 'short_stay_tourist', 'business', 'work', 'family_reunification', 'transit', 'long_stay', 'residence' ), array_keys( $service_types ) );
		$this->assertSame( 'درخواست ویزای کوتاه مدت/توریستی', $service_types['short_stay_tourist'] );
		$this->assertSame( 'ویزای خانوادگی / الحاق خانواده', $service_types['family_reunification'] );

		$embassy = $this->registry->fields( 'embassy_appointment' );
		$visa    = $this->registry->fields( 'visa_request' );
		$this->assertSame( $service_types, $embassy['service_type']['options'] );
		$this->assertSame( 'فوریت وقت سفارت', $embassy['urgency']['label'] );
		$this->assertTrue( $embassy['profession']['searchable'] );
		$this->assertTrue( $embassy['profession']['allow_legacy'] );
		$this->assertArrayHasKey( 'other', $embassy['profession']['options'] );
		$this->assertSame( 'سایر', $embassy['profession']['options']['other'] );
		$this->assertGreaterThan( 150, count( $embassy['profession']['options'] ) );
		$this->assertSame( Didar_Reference_Data::request_for(), $visa['request_for']['options'] );
		$this->assertSame( 'self', $visa['request_for']['default'] );
		$this->assertTrue( $visa['occupation']['searchable'] );
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_field_defaults' => array( 'visa_request' => array( 'request_for' => 'other' ) ) ) );
		$this->assertSame( 'other', ( new Didar_Settings() )->field_default_value( 'visa_request', 'request_for', 'self', $visa['request_for']['options'] ) );
		delete_option( Didar_Settings::OPTION_NAME );
	}

	public function test_phase_three_legacy_service_and_free_text_values_are_renderable_and_editable() {
		$embassy = $this->registry->get( 'embassy_appointment' );
		ob_start();
		( new Didar_Field_Renderer() )->render_sections( $embassy, array( 'service_type' => 'study_visa', 'profession' => 'مهندس قدیمی' ), array(), 'frontend', 77 );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'ویزای تحصیلی', $html );
		$this->assertStringContainsString( 'مهندس قدیمی', $html );

		$legacy = $this->validator->validate( 'embassy_appointment', array( 'service_type' => 'study_visa', 'profession' => 'مهندس قدیمی' ), 'frontend', 77 );
		$this->assertSame( 'study_visa', $legacy['data']['service_type'] );
		$this->assertSame( 'مهندس قدیمی', $legacy['data']['profession'] );
	}

	public function test_iran_geography_is_a_unique_referential_catalog() {
		$geography = Didar_Reference_Data::iran_geography();
		$this->assertCount( 31, $geography );
		$this->assertCount( 1531, Didar_Reference_Data::cities() );
		$this->assertSame( array_keys( $geography ), array_values( array_unique( array_keys( $geography ) ) ) );

		$city_keys = array();
		foreach ( $geography as $province_key => $province ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $province_key );
			$this->assertNotEmpty( $province['label'] );
			$this->assertNotEmpty( $province['cities'] );
			foreach ( $province['cities'] as $city_key => $label ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $city_key );
				$this->assertNotEmpty( $label );
				$this->assertArrayNotHasKey( $city_key, $city_keys, 'City slugs must be unique across provinces.' );
				$city_keys[ $city_key ] = $province_key;
			}
			$this->assertSame( $province['cities'], Didar_Reference_Data::cities_for_province( $province_key ) );
		}

		$this->assertSame( 'tehran', Didar_Reference_Data::city_province_map()['tehran'] );
		$this->assertSame( 'fars', Didar_Reference_Data::city_province_map()['shiraz'] );

		$representative_cities = array(
			'tehran'            => array( 'tehran', 'rey', 'damavand' ),
			'fars'              => array( 'shiraz', 'marvdasht', 'jahrom' ),
			'isfahan'            => array( 'isfahan', 'kashan', 'najafabad' ),
			'razavi_khorasan'   => array( 'mashhad', 'neyshabur', 'sabzevar', 'torbat_e_heydariyeh' ),
			'khuzestan'         => array( 'ahvaz', 'abadan', 'dezful', 'khorramshahr' ),
			'east_azerbaijan'   => array( 'tabriz', 'maragheh', 'marand', 'mianeh' ),
		);
		foreach ( $representative_cities as $province_key => $city_keys_for_province ) {
			foreach ( $city_keys_for_province as $city_key ) {
				$this->assertArrayHasKey( $city_key, $geography[ $province_key ]['cities'], $province_key . '.' . $city_key );
			}
		}

		$phase2_keys = array(
			'ardabil' => array( 'ardabil', 'khalkhal', 'meshgin_shahr', 'parsabad' ),
			'east_azerbaijan' => array( 'tabriz', 'maragheh', 'marand', 'mianeh' ),
			'west_azerbaijan' => array( 'urmia', 'khoy', 'mahabad', 'maku' ),
			'alborz' => array( 'karaj', 'taleqan', 'nazarabad', 'savojbolagh' ),
			'bushehr' => array( 'bushehr', 'borazjan', 'kangan', 'genaveh' ),
			'chaharmahal_and_bakhtiari' => array( 'shahrekord', 'lordegan', 'borujen', 'farrokhshahr' ),
			'fars' => array( 'shiraz', 'marvdasht', 'jahrom', 'lar' ),
			'gilan' => array( 'rasht', 'anzali', 'lahijan', 'langarud' ),
			'golestan' => array( 'gorgan', 'gonbad_e_kavus', 'aliabad_katul', 'bandar_torkaman' ),
			'hamadan' => array( 'hamadan', 'malayer', 'nahavand', 'asadabad' ),
			'hormozgan' => array( 'bandar_abbas', 'kish', 'qeshm', 'minab' ),
			'ilam' => array( 'ilam', 'dehloran', 'mehran', 'abadanan' ),
			'isfahan' => array( 'isfahan', 'kashan', 'najafabad', 'khomeinishahr' ),
			'kerman' => array( 'kerman', 'sirjan', 'rafsanjan', 'bam' ),
			'kermanshah' => array( 'kermanshah', 'javanrud', 'eslamabad_e_gharb', 'paveh' ),
			'khuzestan' => array( 'ahvaz', 'abadan', 'dezful', 'khorramshahr' ),
			'kohgiluyeh_and_boyer_ahmad' => array( 'yasuj', 'dugombadan', 'dehdasht', 'charam' ),
			'kurdistan' => array( 'sanandaj', 'marivan', 'saghez', 'baneh' ),
			'lorestan' => array( 'khorramabad', 'borujerd', 'dorud', 'aligoodarz' ),
			'mazandaran' => array( 'sari', 'babol', 'amol', 'qaem_shahr' ),
			'markazi' => array( 'arak', 'saveh', 'khomein', 'mahalat' ),
			'north_khorasan' => array( 'bojnurd', 'shirvan', 'jajarm', 'esfarayen' ),
			'razavi_khorasan' => array( 'mashhad', 'neyshabur', 'sabzevar', 'torbat_e_heydariyeh' ),
			'south_khorasan' => array( 'birjand', 'qayen', 'tabas', 'nehbandan' ),
			'qazvin' => array( 'qazvin', 'takestan', 'abeyek', 'alvand' ),
			'qom' => array( 'qom', 'jafarieh', 'dastjerd', 'kahak' ),
			'semnan' => array( 'semnan', 'shahroud', 'damghan', 'garmsar' ),
			'sistan_and_baluchestan' => array( 'zahedan', 'chabahar', 'zabol', 'iranshahr' ),
			'tehran' => array( 'tehran', 'rey', 'shemiranat', 'damavand' ),
			'yazd' => array( 'yazd', 'meybod', 'ardakan', 'taft' ),
			'zanjan' => array( 'zanjan', 'abhar', 'khoramdareh', 'khodabandeh' ),
		);
		foreach ( $phase2_keys as $province_key => $city_keys_for_province ) {
			foreach ( $city_keys_for_province as $city_key ) {
				$this->assertArrayHasKey( $city_key, $geography[ $province_key ]['cities'], 'Phase 2 key removed: ' . $province_key . '.' . $city_key );
			}
		}
		$city_map = Didar_Reference_Data::city_province_map();
		foreach ( $city_map as $city_key => $province_key ) {
			$this->assertArrayHasKey( $province_key, $geography );
			$this->assertArrayHasKey( $city_key, $geography[ $province_key ]['cities'] );
		}
		foreach ( array( 'embassy_appointment', 'visa_request' ) as $form_type ) {
			$city_field = $this->registry->fields( $form_type )['birth_city'];
			$this->assertCount( 1531, $city_field['options'] );
			$this->assertSame( $city_map, $city_field['option_provinces'] );
		}
		$this->assertArrayHasKey( 'glvgah_mazandaran', $geography['mazandaran']['cities'] );
		$this->assertArrayHasKey( 'kshkvyyh_kerman', $geography['kerman']['cities'] );
	}

	public function test_birth_geography_validates_province_city_pairs_and_removes_foreign_stale_values() {
		$valid = $this->validator->validate( 'visa_request', array( 'birth_country' => 'iran', 'birth_province' => 'tehran', 'birth_city' => 'tehran', 'birth_place' => 'stale foreign value' ), 'frontend' );
		$this->assertTrue( $valid['valid'] );
		$this->assertArrayNotHasKey( 'birth_place', $valid['data'] );

		$invalid = $this->validator->validate( 'visa_request', array( 'birth_country' => 'iran', 'birth_province' => 'tehran', 'birth_city' => 'shiraz' ), 'frontend' );
		$this->assertFalse( $invalid['valid'] );
		$this->assertArrayHasKey( 'birth_city', $invalid['errors'] );
		foreach ( array( array( 'east_azerbaijan', 'tabriz' ), array( 'isfahan', 'kashan' ), array( 'razavi_khorasan', 'mashhad' ), array( 'khuzestan', 'ahvaz' ) ) as $pair ) {
			$result = $this->validator->validate( 'visa_request', array( 'birth_country' => 'iran', 'birth_province' => $pair[0], 'birth_city' => $pair[1] ), 'frontend' );
			$this->assertTrue( $result['valid'], $pair[0] . '.' . $pair[1] );
		}
		$invalid_cross_province = $this->validator->validate( 'visa_request', array( 'birth_country' => 'iran', 'birth_province' => 'isfahan', 'birth_city' => 'ahvaz' ), 'frontend' );
		$this->assertFalse( $invalid_cross_province['valid'] );
		$this->assertArrayHasKey( 'birth_city', $invalid_cross_province['errors'] );

		$foreign = $this->validator->validate( 'visa_request', array( 'birth_country' => 'canada', 'birth_province' => 'tehran', 'birth_city' => 'tehran', 'birth_place' => 'Toronto' ), 'frontend' );
		$this->assertTrue( $foreign['valid'] );
		$this->assertArrayNotHasKey( 'birth_province', $foreign['data'] );
		$this->assertArrayNotHasKey( 'birth_city', $foreign['data'] );
		$this->assertSame( 'Toronto', $foreign['data']['birth_place'] );

		$fields = $this->registry->fields( 'visa_request' );
		$this->assertSame( 'iran', $fields['birth_country']['default'] );
		$this->assertSame( 'birth_country', $fields['birth_province']['dependent_on'] );
		$this->assertSame( 'iran', $fields['birth_province']['dependent_value'] );
		$this->assertSame( 'birth_province', $fields['birth_city']['dependent_on'] );
		$this->assertSame( 'iran_cities', $fields['birth_city']['option_source'] );
	}

	public function test_new_upload_definitions_are_images_only_and_limited_to_five_mb() {
		$upload_fields = array();
		foreach ( $this->registry->all() as $form_type => $form ) {
			foreach ( $this->registry->fields( $form_type ) as $field_name => $field ) {
				if ( 'file' === ( $field['type'] ?? '' ) ) { $upload_fields[] = $field; }
				if ( 'repeater' === ( $field['type'] ?? '' ) ) {
					foreach ( (array) ( $field['columns'] ?? array() ) as $column ) { if ( is_array( $column ) && 'file' === ( $column['type'] ?? '' ) ) { $upload_fields[] = $column; } }
				}
			}
		}
		$this->assertNotEmpty( $upload_fields );
		foreach ( $upload_fields as $field ) {
			$this->assertSame( 5 * MB_IN_BYTES, (int) $field['max_size'] );
			$this->assertSame( array( 'jpg|jpeg', 'png', 'webp' ), array_keys( $field['upload_mimes'] ) );
			$this->assertSame( array( 'image/jpeg', 'image/png', 'image/webp' ), array_values( $field['mime_types'] ) );
		}
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

		update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'pipeline-legacy-test', 'statuses' => array( 'pending_review' => array( 'label' => 'در انتظار بررسی', 'stage_id' => 'stage-legacy-test', 'is_default' => true, 'order' => 10 ), 'initial_approval' => array( 'label' => 'تایید اولیه', 'stage_id' => 'stage-legacy-approval', 'order' => 20 ) ) ) ) ) );
		$this->assertTrue( $this->service->update_workflow( $post_id, array( 'request_status' => 'initial_approval' ) ) );
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
		$this->assertSame( array( 'companion_uid', 'full_name', 'family_relation', 'age', 'age_group', 'occupation', 'national_id', 'passport_number', 'email', 'phone', 'personal_photo', 'passport_main_page', 'round_trip_ticket', 'other_documents' ), array_keys( $columns ) );

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

	public function test_identity_semantics_are_global_and_canonical() {
		foreach ( array( 'embassy_appointment', 'traveler_evaluation', 'visa_request' ) as $form_type ) {
			$fields = $this->registry->fields( $form_type );
			if ( isset( $fields['passport_number'] ) ) { $this->assertSame( 'passport_number', $fields['passport_number']['semantic'] ); }
			if ( isset( $fields['national_id'] ) ) { $this->assertSame( 'national_id', $fields['national_id']['semantic'] ); }
		}
		$passport = $this->validator->validate( 'visa_request', array( 'passport_number' => 'b12345678' ), 'frontend' );
		$this->assertTrue( $passport['valid'] );
		$this->assertSame( 'B12345678', $passport['data']['passport_number'] );
		foreach ( array( 'AB1234567', 'A1234567', 'A123456789', '123456789' ) as $value ) {
			$this->assertFalse( $this->validator->validate( 'visa_request', array( 'passport_number' => $value ), 'frontend' )['valid'] );
		}
		$national_id = $this->validator->validate( 'visa_request', array( 'national_id' => '0012345678' ), 'frontend' );
		$this->assertTrue( $national_id['valid'] );
		$this->assertSame( '0012345678', $national_id['data']['national_id'] );
		$this->assertFalse( $this->validator->validate( 'visa_request', array( 'national_id' => '12-34' ), 'frontend' )['valid'] );
	}

	public function test_visa_has_separate_names_and_companion_file_paths() {
		$fields = $this->registry->fields( 'visa_request' );
		$this->assertArrayHasKey( 'first_name', $fields );
		$this->assertArrayHasKey( 'last_name', $fields );
		$this->assertArrayNotHasKey( 'full_name', $fields );
		foreach ( array( 'personal_photo', 'passport_main_page', 'round_trip_ticket', 'other_documents' ) as $key ) {
			$this->assertSame( 'file', $fields['companions']['columns'][ $key ]['type'] );
		}
		$this->assertSame( 'A12345678', $fields['companions']['columns']['passport_number']['placeholder'] );
		$this->assertArrayHasKey( 'full_name', $this->registry->legacy_fields( 'visa_request' ) );
	}

	public function test_renderer_preserves_submitted_values_and_jalali_display_values() {
		$renderer = new Didar_Field_Renderer();
		ob_start();
		$renderer->render_sections(
			$this->registry->get( 'visa_request' ),
			array(
				'first_name'           => 'نام واردشده',
				'passport_number'      => 'B123',
				'passport_number_display' => 'ignored',
				'birth_date'           => 'not-a-date',
				'birth_date_display'   => '۱۴۰۳/۰۱/۰۲',
				'companions'           => array( array( 'full_name' => 'همراه اول', 'passport_number' => 'C123' ) ),
			),
			array( 'passport_number' => 'شماره گذرنامه باید شامل یک حرف انگلیسی و هشت رقم باشد.' ),
			'frontend'
		);
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="نام واردشده"', $html );
		$this->assertStringContainsString( 'value="B123"', $html );
		$this->assertStringContainsString( 'value="۱۴۰۳/۰۱/۰۲"', $html );
		$this->assertStringContainsString( 'data-didar-semantic="passport_number"', $html );
		$this->assertStringContainsString( 'placeholder="A12345678"', $html );
		$this->assertStringContainsString( 'شماره گذرنامه باید شامل یک حرف انگلیسی و هشت رقم باشد.', $html );
		$this->assertStringContainsString( 'همراه اول', $html );
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
