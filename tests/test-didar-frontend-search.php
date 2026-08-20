<?php

/**
 * Integration coverage for the shared request search and frontend list controls.
 */
class Test_Didar_Frontend_Search extends WP_UnitTestCase {
	private $registry;
	private $settings;
	private $events;
	private $files;
	private $service;
	private $search;
	private $shortcodes;
	private $post_ids = array();

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		Didar_Access_Control::install_roles_and_capabilities();
		Didar_Event_Log::install_schema();
		Didar_File_Service::install_schema();
		$this->registry = new Didar_Form_Registry();
		$this->settings = new Didar_Settings();
		$this->events   = new Didar_Event_Log();
		$this->files    = new Didar_File_Service( $this->registry, $this->settings, $this->events );
		$this->service  = new Didar_Submission_Service( $this->registry, $this->events, $this->settings, $this->files );
		$this->search   = new Didar_Request_Search();
		$renderer       = new Didar_Field_Renderer( $this->settings, $this->files );
		$validator      = new Didar_Validator( $this->registry, $this->settings, $this->files );
		$this->shortcodes = new Didar_Shortcodes( $this->registry, $renderer, $validator, $this->service, $this->settings, $this->files, $this->search );
		$_GET = array();
	}

	public function tear_down() {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		delete_option( Didar_Settings::OPTION_NAME );
		$_GET = array();
		parent::tear_down();
	}

	public function test_shared_search_finds_identifying_fields_and_request_id_with_author_scope() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$own_id   = $this->create_submission(
			$owner_id,
			'consultation',
			array(
				'first_name' => 'جستجوی-نام',
				'last_name'  => 'جستجوی-نام‌خانوادگی',
				'input_1'    => 'نام کامل قدیمی آزمایشی',
				'input_3'    => '09123456789',
				'email'      => 'frontend-search@example.com',
			)
		);
		$this->create_submission( $other_id, 'consultation', array( 'first_name' => 'جستجوی-نام', 'email' => 'frontend-search@example.com' ) );

		foreach ( array( 'جستجوی-نام', 'جستجوی-نام‌خانوادگی', 'نام کامل قدیمی', '09123456789', 'frontend-search@example.com', (string) $own_id, '#' . $own_id ) as $term ) {
			$query = new WP_Query(
				array(
					'post_type'                         => Didar_Post_Type::POST_TYPE,
					'post_status'                       => 'publish',
					'author'                            => $owner_id,
					Didar_Request_Search::QUERY_VAR      => $term,
					'posts_per_page'                    => 10,
				)
			);
			$this->assertSame( array( $own_id ), wp_list_pluck( $query->posts, 'ID' ), $term );
		}
	}

	public function test_shortcode_combines_search_type_filter_and_ownership() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$expected = $this->create_submission( $owner_id, 'consultation', array( 'first_name' => 'NeedleFrontend' ) );
		$wrong_type = $this->create_submission( $owner_id, 'visa_request', array( 'first_name' => 'NeedleFrontend' ) );
		$wrong_owner = $this->create_submission( $other_id, 'consultation', array( 'first_name' => 'NeedleFrontend' ) );
		wp_set_current_user( $owner_id );
		$_GET['didar_search'] = 'NeedleFrontend';
		$_GET['didar_type']   = 'consultation';

		$html = $this->shortcodes->submissions_shortcode( array() );
		$this->assertStringContainsString( '#' . $expected, $html );
		$this->assertStringNotContainsString( '#' . $wrong_type, $html );
		$this->assertStringNotContainsString( '#' . $wrong_owner, $html );
		$this->assertStringContainsString( 'value="NeedleFrontend"', $html );
		$this->assertStringContainsString( 'value="consultation" selected=', $html );
	}

	public function test_fixed_shortcode_type_cannot_be_overridden_by_query_parameter() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$consultation_id = $this->create_submission( $owner_id, 'consultation', array( 'first_name' => 'FixedTypeNeedle' ) );
		$visa_id = $this->create_submission( $owner_id, 'visa_request', array( 'first_name' => 'FixedTypeNeedle' ) );
		wp_set_current_user( $owner_id );
		$_GET['didar_search'] = 'FixedTypeNeedle';
		$_GET['didar_type']   = 'visa_request';

		$html = $this->shortcodes->submissions_shortcode( array( 'type' => 'consultation' ) );
		$this->assertStringContainsString( '#' . $consultation_id, $html );
		$this->assertStringNotContainsString( '#' . $visa_id, $html );
		$this->assertStringNotContainsString( 'name="didar_type"', $html );
		$this->assertStringContainsString( 'name="didar_search"', $html );
	}

	public function test_controls_attributes_invalid_input_and_pagination_state() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $owner_id );
		update_option( Didar_Settings::OPTION_NAME, array( 'frontend_requests_per_page' => 1 ) );
		$this->create_submission( $owner_id, 'consultation', array( 'first_name' => 'PagedNeedle' ) );
		$this->create_submission( $owner_id, 'consultation', array( 'first_name' => 'PagedNeedle' ) );
		$_GET['didar_search'] = str_repeat( 'PagedNeedle', 20 );
		$_GET['didar_type']   = 'invalid_type';
		$html = $this->shortcodes->submissions_shortcode( array() );
		$this->assertStringContainsString( 'درخواستی با این مشخصات پیدا نشد', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertLessThanOrEqual( Didar_Request_Search::MAX_TERM_LENGTH, strlen( $this->search->sanitize_term( $_GET['didar_search'] ) ) );

		$_GET = array( 'didar_search_2' => 'PagedNeedle', 'didar_type_2' => 'consultation', 'unrelated' => 'keep-me' );
		$html = $this->shortcodes->submissions_shortcode( array() );
		$this->assertStringContainsString( 'didar_search_2=PagedNeedle', $html );
		$this->assertStringContainsString( 'didar_type_2=consultation', $html );
		$this->assertStringContainsString( 'didar_page_2=2', $html );
		$this->assertStringContainsString( 'name="unrelated" value="keep-me"', $html );

		$disabled = new Didar_Shortcodes( $this->registry, new Didar_Field_Renderer( $this->settings, $this->files ), new Didar_Validator( $this->registry, $this->settings, $this->files ), $this->service, $this->settings, $this->files, $this->search );
		$html = $disabled->submissions_shortcode( array( 'search' => 'no', 'filter' => 'no' ) );
		$this->assertStringNotContainsString( 'didar-submissions-controls', $html );
	}

	public function test_submission_list_renders_first_and_last_name_columns_with_combined_name_fallbacks() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$direct_id = $this->create_submission( $owner_id, 'consultation', array( 'first_name' => 'نام مستقیم', 'last_name' => 'خانوادگی مستقیم' ) );
		$visa_id   = $this->create_submission( $owner_id, 'visa_request', array( 'full_name' => 'رضا نادری' ) );
		$legacy_id = $this->create_submission( $owner_id, 'consultation', array( 'input_1' => 'مریم احمدی قدیمی' ) );
		wp_set_current_user( $owner_id );

		$this->assertSame( array( 'first_name' => 'نام مستقیم', 'last_name' => 'خانوادگی مستقیم' ), $this->service->get_applicant_name_parts( $direct_id ) );
		$this->assertSame( array( 'first_name' => 'رضا', 'last_name' => 'نادری' ), $this->service->get_applicant_name_parts( $visa_id ) );
		$this->assertSame( array( 'first_name' => 'مریم', 'last_name' => 'احمدی قدیمی' ), $this->service->get_applicant_name_parts( $legacy_id ) );

		$html = $this->shortcodes->submissions_shortcode( array() );
		$this->assertStringContainsString( '<th scope="col">نام</th>', $html );
		$this->assertStringContainsString( '<th scope="col">نام خانوادگی</th>', $html );
		$this->assertStringContainsString( 'data-label="نام">نام مستقیم</td>', $html );
		$this->assertStringContainsString( 'data-label="نام خانوادگی">خانوادگی مستقیم</td>', $html );
		$this->assertStringContainsString( 'data-label="نام">رضا</td>', $html );
		$this->assertStringContainsString( 'data-label="نام خانوادگی">نادری</td>', $html );
	}

	private function create_submission( $author_id, $form_type, $fields ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Didar_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => 'Didar test request',
			)
		);
		$this->post_ids[] = $post_id;
		update_post_meta( $post_id, '_didar_form_type', $form_type );
		update_post_meta( $post_id, '_didar_fields', $fields );
		update_post_meta( $post_id, '_didar_public_status', 'pending_review' );
		return $post_id;
	}
}
