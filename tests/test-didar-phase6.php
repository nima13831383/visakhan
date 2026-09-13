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
		$this->assertStringContainsString( 'class="didar-stage-progress didar-stage-progress--compact"', $details );
	}

	public function test_authorized_agent_renders_cached_pipeline_order_and_current_stage() {
		$agent_id = self::factory()->user->create( array( 'role' => Didar_Access_Control::ROLE_BROKER, 'display_name' => 'کارگزار' ) );
		$post_id  = $this->create_submission( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		update_post_meta( $post_id, '_didar_internal_status', 'initial_approval' );
		update_option( Didar_Settings::OPTION_NAME, array_merge( $this->settings_snapshot, array( 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'pipeline-phase6', 'statuses' => array( 'pending_review' => array( 'label' => 'بررسی', 'stage_id' => 'stage-one', 'is_default' => true, 'order' => 10 ), 'initial_approval' => array( 'label' => 'تأیید', 'stage_id' => 'stage-two', 'order' => 20 ) ) ) ) ) ) );
		update_option( Didar_Workflow_Manager::PIPELINES_OPTION, array( 'pipelines' => array( array( 'id' => 'pipeline-phase6', 'title' => 'آزمایش', 'stages' => array( array( 'id' => 'stage-one', 'title' => 'مرحله اول' ), array( 'id' => 'stage-two', 'title' => 'مرحله دوم' ), array( 'id' => 'stage-three', 'title' => 'مرحله سوم' ) ) ) ) ) );
		wp_set_current_user( $agent_id );
		$_GET['didar_submission'] = $post_id;
		$html = $this->shortcodes->submission_details_shortcode();
		$this->assertStringContainsString( 'مرحله اول', $html );
		$this->assertStringContainsString( 'مرحله دوم', $html );
		$this->assertStringContainsString( 'مرحله سوم', $html );
		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertStringContainsString( 'is-completed', $html );
		$this->assertStringContainsString( 'is-current', $html );
		$this->assertStringContainsString( 'is-future', $html );
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
