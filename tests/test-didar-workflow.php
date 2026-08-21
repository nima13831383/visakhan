<?php

/**
 * Integration tests for Didar workflow access and audit behavior.
 *
 * Run inside the standard WordPress PHPUnit test suite with the plugin loaded.
 */
class Test_Didar_Workflow extends WP_UnitTestCase {
	private $service;
	private $submission_ids = array();

	public function set_up() {
		parent::set_up();
		delete_option( Didar_Settings::OPTION_NAME );
		Didar_Post_Type::register();
		Didar_Access_Control::install_roles_and_capabilities();
		Didar_Event_Log::install_schema();
		$this->service = new Didar_Submission_Service( new Didar_Form_Registry(), new Didar_Event_Log() );
	}

	public function tear_down() {
		global $wpdb;
		foreach ( $this->submission_ids as $submission_id ) {
			$wpdb->delete( Didar_Event_Log::table_name(), array( 'submission_id' => $submission_id ), array( '%d' ) );
			wp_delete_post( $submission_id, true );
		}
		delete_option( Didar_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_roles_have_only_expected_access_shape() {
		$colleague = get_role( Didar_Access_Control::ROLE_COLLEAGUE );
		$broker    = get_role( Didar_Access_Control::ROLE_BROKER );

		$this->assertTrue( $colleague->has_cap( 'didar_view_own_internal_workflow' ) );
		$this->assertFalse( $colleague->has_cap( 'didar_view_requests' ) );
		$this->assertFalse( $colleague->has_cap( 'edit_posts' ) );
		$this->assertTrue( $broker->has_cap( 'didar_view_requests' ) );
		$this->assertTrue( $broker->has_cap( 'didar_view_all_requests' ) );
		$this->assertTrue( $broker->has_cap( 'didar_view_request' ) );
		$this->assertTrue( $broker->has_cap( 'didar_assign_requests' ) );
		$this->assertTrue( $broker->has_cap( 'create_didar_submissions' ) );
		$this->assertTrue( $broker->has_cap( 'publish_didar_submissions' ) );
		$this->assertTrue( $broker->has_cap( 'delete_others_didar_submissions' ) );
		$this->assertTrue( $broker->has_cap( 'didar_change_request_owner' ) );
		$this->assertTrue( $broker->has_cap( 'didar_manage_settings' ) );
		$this->assertFalse( $broker->has_cap( 'manage_options' ) );
		$this->assertFalse( $broker->has_cap( 'edit_posts' ) );
		$this->assertFalse( $colleague->has_cap( 'didar_view_all_requests' ) );
	}

	public function test_woocommerce_admin_restriction_does_not_block_broker() {
		$broker_id   = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$customer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $broker_id );
		$this->assertTrue( Didar_Access_Control::can_access_didar_admin() );
		$this->assertFalse( apply_filters( 'woocommerce_disable_admin_bar', true ) );
		$this->assertFalse( apply_filters( 'woocommerce_prevent_admin_access', true ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );
		$this->assertFalse( current_user_can( 'view_admin_dashboard' ) );

		wp_set_current_user( $customer_id );
		$this->assertFalse( Didar_Access_Control::can_access_didar_admin() );
		$this->assertTrue( apply_filters( 'woocommerce_disable_admin_bar', true ) );
		$this->assertTrue( apply_filters( 'woocommerce_prevent_admin_access', true ) );
	}

	public function test_broker_receives_assigned_requests_submenu() {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $menu, $submenu, $_registered_pages, $_parent_pages;

		$original_menu             = $menu;
		$original_submenu          = $submenu;
		$original_registered_pages = $_registered_pages;
		$original_parent_pages     = $_parent_pages;
		$parent                    = 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE;
		$menu                      = array( array( 'دیدار', 'edit_didar_submissions', $parent ) );
		$submenu                   = array(
			$parent => array(
				array( 'همه درخواست‌ها', 'edit_didar_submissions', $parent ),
			),
		);

		$broker_id = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		wp_set_current_user( $broker_id );
		Didar_Access_Control::add_assigned_requests_submenu();
		$broker_submenu = $submenu[ $parent ];

		$menu              = $original_menu;
		$submenu           = $original_submenu;
		$_registered_pages = $original_registered_pages;
		$_parent_pages     = $original_parent_pages;

		$this->assertCount( 2, $broker_submenu );
		$this->assertSame( $parent . '&didar_assignment=mine', $broker_submenu[1][2] );
	}

	public function test_customer_receives_public_but_not_internal_data_or_history() {
		$customer_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$submission_id = $this->create_submission( $customer_id );

		wp_set_current_user( $admin_id );
		$result = $this->service->update_workflow(
			$submission_id,
			array(
				'public_note'     => 'مدارک را ارسال کنید',
				'internal_note'   => 'پیگیری داخلی',
				'internal_status' => 'initial_approval',
			)
		);
		$this->assertTrue( $result );

		wp_set_current_user( $customer_id );
		$this->assertSame( 'pending_review', $this->service->get_public_status( $submission_id ) );
		$this->assertSame( 'مدارک را ارسال کنید', $this->service->get_public_note( $submission_id ) );
		$this->assertSame( '', $this->service->get_internal_status( $submission_id ) );
		$this->assertSame( '', $this->service->get_internal_note( $submission_id ) );
		$this->assertSame( array(), $this->service->get_events( $submission_id ) );
		$this->assertWPError( $this->service->update_workflow( $submission_id, array( 'internal_note' => 'جعلی' ) ) );
	}

	public function test_colleague_sees_only_own_internal_workflow_and_history() {
		$colleague_id       = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_COLLEAGUE ) );
		$other_colleague_id = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_COLLEAGUE ) );
		$first_id           = $this->create_submission( $colleague_id, 'متقاضی اول' );
		$second_id          = $this->create_submission( $other_colleague_id, 'متقاضی دوم' );

		wp_set_current_user( $colleague_id );
		$this->assertFalse( $this->service->can_view_internal( $first_id ) );
		$this->assertFalse( $this->service->can_view_history( $first_id ) );
		update_option( Didar_Settings::OPTION_NAME, array( 'colleague_can_view_internal_history' => 1 ) );
		$this->assertTrue( $this->service->can_view_internal( $first_id ) );
		$this->assertTrue( $this->service->can_view_history( $first_id ) );
		$this->assertNotEmpty( $this->service->get_events( $first_id ) );
		$this->assertFalse( $this->service->can_view_internal( $second_id ) );
		$this->assertFalse( $this->service->can_view_history( $second_id ) );
		$this->assertNull( $this->service->get_owned_submission( $second_id, $colleague_id ) );
	}

	public function test_guessed_submission_id_does_not_bypass_customer_ownership() {
		$first_customer  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$second_customer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$submission_id   = $this->create_submission( $first_customer );

		wp_set_current_user( $second_customer );
		$this->assertNull( $this->service->get_owned_submission( $submission_id, $second_customer ) );
		$this->assertFalse( $this->service->can_view_public( $submission_id ) );
		$this->assertSame( '', $this->service->get_public_status( $submission_id ) );
		$this->assertSame( '', $this->service->get_public_note( $submission_id ) );
	}

	public function test_broker_can_view_and_edit_another_users_request_through_central_scope() {
		$customer_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$broker_id     = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$colleague_id  = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_COLLEAGUE ) );
		$unrelated_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$submission_id = $this->create_submission( $customer_id );

		wp_set_current_user( $broker_id );
		$this->assertTrue( $this->service->user_can_view_all_requests( $broker_id ) );
		$this->assertTrue( Didar_Access_Control::can_edit_request( $submission_id ) );
		$this->assertSame( $submission_id, $this->service->get_accessible_submission( $submission_id, $broker_id )->ID );
		$this->assertTrue( $this->service->can_edit_from_frontend( $submission_id, $broker_id ) );
		$this->assertTrue( $this->service->can_view_internal( $submission_id ) );
		$this->assertTrue( $this->service->can_view_history( $submission_id ) );

		$fields               = $this->service->get_fields( $submission_id );
		$fields['description'] = 'ویرایش کارگزار';
		$result                = $this->service->update_from_frontend( $submission_id, $fields, 'یادداشت کارگزار', $broker_id );
		$this->assertTrue( $result );
		$this->assertSame( 'ویرایش کارگزار', $this->service->get_fields( $submission_id )['description'] );
		$this->assertSame( $broker_id, $this->service->get_events( $submission_id )[0]['actor_user_id'] );

		wp_set_current_user( $admin_id );
		$this->assertTrue( Didar_Access_Control::can_edit_request( $submission_id ) );

		wp_set_current_user( $colleague_id );
		$this->assertFalse( Didar_Access_Control::can_edit_request( $submission_id ) );

		wp_set_current_user( $unrelated_id );
		$this->assertFalse( Didar_Access_Control::can_edit_request( $submission_id ) );
		$this->assertFalse( $this->service->user_can_view_all_requests( $unrelated_id ) );
		$this->assertNull( $this->service->get_accessible_submission( $submission_id, $unrelated_id ) );
		$this->assertFalse( $this->service->can_edit_from_frontend( $submission_id, $unrelated_id ) );
		$this->assertWPError( $this->service->update_from_frontend( $submission_id, $fields, '', $broker_id ) );
	}

	public function test_failed_admin_save_redirect_does_not_report_wordpress_success() {
		$broker_id     = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$submission_id = $this->create_submission( $broker_id );
		$registry      = new Didar_Form_Registry();
		$admin         = new Didar_Admin( $registry, new Didar_Field_Renderer(), new Didar_Validator( $registry ), $this->service );

		wp_set_current_user( $broker_id );
		set_transient(
			'didar_admin_errors_' . $broker_id . '_' . $submission_id,
			array( 'errors' => array( '_form' => 'invalid' ) ),
			MINUTE_IN_SECONDS
		);
		$location = $admin->filter_save_redirect( admin_url( 'post.php?post=' . $submission_id . '&action=edit&message=4' ), $submission_id );

		$this->assertStringNotContainsString( 'message=4', $location );
		$this->assertStringContainsString( 'didar_save_error=1', $location );
		delete_transient( 'didar_admin_errors_' . $broker_id . '_' . $submission_id );
	}

	public function test_assignment_is_validated_and_append_only_events_ignore_noops() {
		$customer_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$broker_id     = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$submission_id = $this->create_submission( $customer_id );

		wp_set_current_user( $admin_id );
		$this->assertTrue( $this->service->update_workflow( $submission_id, array( 'assigned_user_id' => $broker_id ) ) );
		$after_assignment = $this->service->get_events( $submission_id );
		$this->assertSame( 'request_assigned', $after_assignment[0]['event_type'] );
		$this->assertTrue( $this->service->update_workflow( $submission_id, array( 'assigned_user_id' => $broker_id ) ) );
		$this->assertCount( count( $after_assignment ), $this->service->get_events( $submission_id ) );

		$this->assertWPError( $this->service->update_workflow( $submission_id, array( 'assigned_user_id' => $customer_id ) ) );
		wp_set_current_user( $broker_id );
		$this->assertTrue( $this->service->update_workflow( $submission_id, array( 'internal_status' => 'initial_approval', 'public_note' => 'در حال بررسی' ) ) );
		$this->assertNotEmpty( $this->service->get_events( $submission_id ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( $this->service->update_workflow( $submission_id, array( 'assigned_user_id' => 0 ) ) );
		$events = $this->service->get_events( $submission_id );
		$this->assertSame( 'assignment_removed', $events[0]['event_type'] );
		$this->assertGreaterThan( $events[1]['event_id'], $events[0]['event_id'] );
	}

	public function test_assigned_to_me_query_filters_in_sql() {
		$customer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$broker_id   = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mine        = $this->create_submission( $customer_id, 'ارجاعی' );
		$other       = $this->create_submission( $customer_id, 'ارجاع‌نشده' );

		wp_set_current_user( $admin_id );
		$this->service->update_workflow( $mine, array( 'assigned_user_id' => $broker_id ) );
		$query = new WP_Query(
			array(
				'post_type'      => Didar_Post_Type::POST_TYPE,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_didar_assigned_user_id', 'value' => $broker_id, 'compare' => '=', 'type' => 'NUMERIC' ) ),
			)
		);
		$this->assertContains( $mine, $query->posts );
		$this->assertNotContains( $other, $query->posts );
	}

	public function test_note_and_status_history_preserves_actor_timestamp_and_old_events() {
		$customer_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$submission_id = $this->create_submission( $customer_id );

		wp_set_current_user( $admin_id );
		$this->service->update_workflow( $submission_id, array( 'internal_note' => 'مدارک ناقص است' ) );
		$this->service->update_workflow( $submission_id, array( 'internal_note' => 'مدارک تکمیل شد' ) );
		$this->service->update_workflow( $submission_id, array( 'internal_status' => 'initial_approval' ) );
		$events = $this->service->get_events( $submission_id );

		$note_events = array_values( array_filter( $events, function ( $event ) { return 'internal_note_changed' === $event['event_type']; } ) );
		$this->assertCount( 2, $note_events );
		$this->assertSame( 'مدارک ناقص است', $note_events[0]['old_value'] );
		$this->assertSame( 'مدارک ناقص است', $note_events[1]['new_value'] );
		$this->assertSame( $admin_id, $note_events[0]['actor_user_id'] );
		$this->assertNotEmpty( $note_events[0]['created_at_gmt'] );
		$this->assertNotEmpty( array_filter( $events, function ( $event ) { return 'request_created' === $event['event_type']; } ) );
	}

	private function create_submission( $owner_id, $applicant_name = 'متقاضی آزمایشی' ) {
		$name_parts = preg_split( '/\s+/u', trim( $applicant_name ), 2 );
		wp_set_current_user( $owner_id );
		$submission_id = $this->service->create(
			'consultation',
			array(
				'first_name'  => isset( $name_parts[0] ) ? $name_parts[0] : 'متقاضی',
				'last_name'   => isset( $name_parts[1] ) ? $name_parts[1] : 'آزمایشی',
				'input_3'     => '09120000000',
				'email'       => 'applicant@example.com',
				'input_5'     => 'موضوع سفارشی آزمایشی',
				'description' => "توضیحات سطر اول\nتوضیحات سطر دوم",
			),
			$owner_id
		);
		$this->assertIsInt( $submission_id );
		$this->submission_ids[] = $submission_id;
		return $submission_id;
	}
}
