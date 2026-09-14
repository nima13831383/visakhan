<?php

/** Focused Phase 6 coverage for request owner identity and cached workflow rendering. */
class Test_Didar_Phase6 extends WP_UnitTestCase {
	private $registry;
	private $settings;
	private $service;
	private $shortcodes;
	private $posts = array();
	private $settings_snapshot;
	private $pipeline_snapshot;

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		Didar_Access_Control::install_roles_and_capabilities();
		Didar_Event_Log::install_schema();
		$this->registry = new Didar_Form_Registry();
		$this->settings = new Didar_Settings();
		$events        = new Didar_Event_Log();
		$files         = new Didar_File_Service( $this->registry, $this->settings, $events );
		$this->service = new Didar_Submission_Service( $this->registry, $events, $this->settings, $files );
		$renderer      = new Didar_Field_Renderer( $this->settings, $files );
		$validator     = new Didar_Validator( $this->registry, $this->settings, $files );
		$this->shortcodes = new Didar_Shortcodes( $this->registry, $renderer, $validator, $this->service, $this->settings, $files );
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
		$this->pipeline_snapshot = get_option( Didar_Workflow_Manager::PIPELINES_OPTION, array() );
		update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot );
		$_GET = array();
	}

	public function tear_down() {
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot );
		update_option( Didar_Workflow_Manager::PIPELINES_OPTION, $this->pipeline_snapshot );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_identity_uses_wordpress_display_name_and_deterministic_role_precedence() {
		$customer = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'حساب نمایش داده‌شده' ) );
		$agent    = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER, 'display_name' => 'کارگزار آزمایشی' ) );
		$worker   = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_COLLEAGUE, 'display_name' => 'همکار آزمایشی' ) );

		$this->assertSame( 'حساب نمایش داده‌شده', Didar_User_Identity::for_user( get_userdata( $customer ) )['name'] );
		$this->assertSame( 'مشتری', Didar_User_Identity::for_user( get_userdata( $customer ) )['role_label'] );
		$this->assertSame( 'کارگزار', Didar_User_Identity::for_user( get_userdata( $agent ) )['role_label'] );
		$this->assertSame( 'همکار', Didar_User_Identity::for_user( get_userdata( $worker ) )['role_label'] );
		$this->assertSame( 'کاربر حذف‌شده', Didar_User_Identity::for_user( false )['name'] );
	}

	public function test_list_and_details_show_post_owner_without_exposing_account_fields() {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'مالک درخواست' ) );
		$post_id  = $this->create_submission( $owner_id );
		wp_set_current_user( $owner_id );
		$list = $this->shortcodes->submissions_shortcode( array() );
		$this->assertStringContainsString( 'ثبت‌کننده درخواست', $list );
		$this->assertStringContainsString( 'مالک درخواست', $list );
		$this->assertStringContainsString( 'مشتری', $list );
		$this->assertStringNotContainsString( 'owner@example.com', $list );

		$_GET['didar_submission'] = $post_id;
		$details = $this->shortcodes->submission_details_shortcode();
		$this->assertStringContainsString( 'مالک درخواست', $details );
		$this->assertStringContainsString( 'class="didar-stage-progress"', $details );
		$this->assertStringContainsString( 'در انتظار بررسی', $details );
	}

	public function test_authorized_agent_renders_public_progress_order_and_current_status() {
		$agent_id = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER, 'display_name' => 'کارگزار' ) );
		$post_id  = $this->create_submission( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		update_post_meta( $post_id, '_didar_public_status', 'initial_approval' );
		update_post_meta( $post_id, '_didar_internal_status', 'initial_approval' );
		update_option( Didar_Settings::OPTION_NAME, array_merge( $this->settings_snapshot, array( 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'pipeline-phase6', 'statuses' => array( 'pending_review' => array( 'label' => 'بررسی', 'stage_id' => 'stage-one', 'is_default' => true, 'order' => 10 ), 'initial_approval' => array( 'label' => 'تأیید', 'stage_id' => 'stage-two', 'order' => 20 ) ) ) ) ) ) );
		update_option( Didar_Workflow_Manager::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'pipeline-phase6', 'title' => 'آزمایش', 'stages' => array( array( 'id' => 'stage-one', 'title' => 'مرحله اول' ), array( 'id' => 'stage-two', 'title' => 'مرحله دوم' ), array( 'id' => 'stage-three', 'title' => 'مرحله سوم' ) ) ) ) ) );
		wp_set_current_user( $agent_id );
		$_GET['didar_submission'] = $post_id;
		$html = $this->shortcodes->submission_details_shortcode();
		$this->assertStringContainsString( 'در انتظار بررسی', $html );
		$this->assertStringContainsString( 'نیاز به اصلاح مدارک', $html );
		$this->assertStringContainsString( 'تایید اولیه', $html );
		$this->assertStringContainsString( 'تکمیل شده', $html );
		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertStringContainsString( 'is-completed', $html );
		$this->assertStringContainsString( 'is-current', $html );
		$this->assertStringContainsString( 'is-future', $html );
	}

	public function test_public_progress_classes_follow_public_status_order() {
		$customer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cases       = array(
			'pending_review'   => array( 1, 0, 3 ),
			'needs_correction' => array( 1, 1, 2 ),
			'initial_approval' => array( 1, 2, 1 ),
			'completed'        => array( 1, 3, 0 ),
		);

		foreach ( $cases as $public_status => $expected ) {
			$post_id = $this->create_submission( $customer_id );
			update_post_meta( $post_id, '_didar_public_status', $public_status );
			update_post_meta( $post_id, '_didar_internal_status', 'internal_review' );
			wp_set_current_user( $customer_id );
			$_GET['didar_submission'] = $post_id;
			$html = $this->shortcodes->submission_details_shortcode();

			$this->assertSame( $expected[0], substr_count( $html, 'aria-current="step"' ), $public_status );
			$this->assertSame( $expected[1], substr_count( $html, 'didar-stage-progress__item is-completed' ), $public_status );
			$this->assertSame( $expected[2], substr_count( $html, 'didar-stage-progress__item is-future' ), $public_status );
			$this->assertMatchesRegularExpression( '/aria-current="step".*?didar-stage-progress__label">' . preg_quote( Didar_Reference_Data::statuses()[ $public_status ], '/' ) . '/s', $html );
		}
	}

	public function test_details_main_status_stays_public_for_customer_and_operator_views() {
		$customer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$agent_id    = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER ) );
		$post_id     = $this->create_submission( $customer_id );
		update_post_meta( $post_id, '_didar_internal_status', 'internal_review' );
		update_option(
			Didar_Settings::OPTION_NAME,
			array_merge(
				$this->settings_snapshot,
				array(
					'didar_form_workflows' => array(
						'consultation' => array(
							'pipeline_id' => 'pipeline-public-status',
							'statuses'   => array(
								'pending_review'  => array( 'label' => 'در انتظار بررسی', 'stage_id' => 'stage-public', 'is_default' => true, 'order' => 10 ),
								'internal_review' => array( 'label' => 'وضعیت داخلی محرمانه', 'stage_id' => 'stage-internal', 'order' => 20 ),
							),
						),
					),
				)
			)
		);
		update_option( Didar_Workflow_Manager::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'pipeline-public-status', 'title' => 'آزمایش', 'stages' => array( array( 'id' => 'stage-public', 'title' => 'مرحله عمومی' ), array( 'id' => 'stage-internal', 'title' => 'مرحله داخلی محرمانه' ) ) ) ) ) );

		wp_set_current_user( $customer_id );
		$_GET['didar_submission'] = $post_id;
		$customer_html = $this->shortcodes->submission_details_shortcode();
		$this->assertStringContainsString( 'در انتظار بررسی', $customer_html );
		$this->assertStringNotContainsString( 'وضعیت داخلی محرمانه', $customer_html );
		$this->assertStringContainsString( 'class="didar-stage-progress"', $customer_html );

		wp_set_current_user( $agent_id );
		$operator_html = $this->shortcodes->submission_details_shortcode();
		$this->assertStringContainsString( 'وضعیت داخلی محرمانه', $operator_html );
		$this->assertMatchesRegularExpression( '/aria-current="step".*?didar-stage-progress__label">در انتظار بررسی/s', $operator_html );
		$this->assertStringContainsString( 'class="didar-internal-workflow"', $operator_html );
		$this->assertMatchesRegularExpression( '/class="didar-internal-workflow".*?وضعیت داخلی محرمانه/s', $operator_html );
		$internal_section_pos = strpos( $operator_html, '<section class="didar-detail-section didar-internal-workflow"' );
		$this->assertNotFalse( $internal_section_pos );
		$main_html = substr( $operator_html, 0, $internal_section_pos );
		$this->assertStringNotContainsString( 'وضعیت داخلی محرمانه', $main_html );
		$this->assertStringNotContainsString( 'internal_review', $main_html );
		$this->assertStringContainsString( 'class="didar-stage-progress"', $operator_html );
	}

	public function test_details_public_status_falls_back_without_exposing_internal_status() {
		$customer_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id     = $this->create_submission( $customer_id );
		delete_post_meta( $post_id, '_didar_public_status' );
		delete_post_meta( $post_id, '_didar_status' );
		update_post_meta( $post_id, '_didar_internal_status', 'internal_review' );

		wp_set_current_user( $customer_id );
		$_GET['didar_submission'] = $post_id;
		$html = $this->shortcodes->submission_details_shortcode();

		$this->assertStringContainsString( 'در انتظار بررسی', $html );
		$this->assertStringNotContainsString( 'internal_review', $html );
	}

	private function create_submission( $author_id ) {
		$post_id = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $author_id, 'post_title' => 'Phase 6 request' ) );
		$this->posts[] = $post_id;
		update_post_meta( $post_id, '_didar_form_type', 'consultation' );
		update_post_meta( $post_id, '_didar_fields', array( 'first_name' => 'متقاضی', 'last_name' => 'آزمایشی' ) );
		update_post_meta( $post_id, '_didar_public_status', 'pending_review' );
		return $post_id;
	}
}
