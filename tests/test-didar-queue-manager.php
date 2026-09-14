<?php

class Didar_Queue_Manager_Test_Double extends Didar_Sync_Manager {
	public $executed = array();
	public $result = true;

	public function process_scheduled_submission( $post_id = 0 ) {
		$this->executed[] = array( 'submission', absint( $post_id ) );
		if ( true === $this->result ) {
			delete_post_meta( absint( $post_id ), self::META_STATE );
		}
		return $this->result;
	}

	public function process_scheduled_user( $user_id = 0 ) {
		$this->executed[] = array( 'person', absint( $user_id ) );
		if ( true === $this->result ) {
			delete_user_meta( absint( $user_id ), self::META_PERSON_STATE );
		}
		return $this->result;
	}
}

class Test_Didar_Queue_Manager extends WP_UnitTestCase {
	private $admin_id;
	private $user_id;
	private $post_id;
	private $other_post_id;

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->admin_id )->add_cap( 'didar_manage_settings' );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Queue person' ) );
		$this->post_id = $this->create_submission( $this->user_id );
		$this->other_post_id = $this->create_submission( $this->user_id );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		foreach ( array( $this->post_id, $this->other_post_id ) as $post_id ) {
			foreach ( array( Didar_Sync_Manager::CRON_HOOK, Didar_Sync_Manager::USER_HOOK ) as $hook ) {
				while ( $when = wp_next_scheduled( $hook, array( $post_id ) ) ) { wp_unschedule_event( $when, $hook, array( $post_id ) ); }
			}
			wp_delete_post( $post_id, true );
		}
		foreach ( array( $this->admin_id, $this->user_id ) as $user_id ) { if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( $user_id ); } }
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_inventory_merges_scheduled_submission_retry_into_one_row() {
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending', 'attempts' => 2, 'last_error' => 'transport_timeout' ) );
		wp_schedule_single_event( time() + 120, Didar_Sync_Manager::CRON_HOOK, array( $this->post_id ), true );

		$items = $this->manager()->queue_inventory();
		$matches = array_values( array_filter( $items, function ( $item ) { return 'submission' === $item['queue_type'] && $this->post_id === $item['object_id']; } ) );
		$this->assertCount( 1, $matches );
		$this->assertSame( 1, $matches[0]['scheduled_event_count'] );
		$this->assertTrue( $matches[0]['executable'] );
	}

	public function test_person_item_appears_once_and_discard_preserves_person_id() {
		update_user_meta( $this->user_id, Didar_Sync_Manager::META_PERSON_STATE, array( 'status' => 'pending', 'attempts' => 1, 'error' => 'mobile_missing' ) );
		update_user_meta( $this->user_id, Didar_Sync_Manager::USER_PERSON_META, 'person-existing' );
		wp_schedule_single_event( time() + 120, Didar_Sync_Manager::USER_HOOK, array( $this->user_id ), true );

		$items = array_values( array_filter( $this->manager()->queue_inventory(), function ( $item ) { return 'person' === $item['queue_type'] && $this->user_id === $item['object_id']; } ) );
		$this->assertCount( 1, $items );
		$this->manager()->discard_queue_item( $items[0]['item_key'] );
		$this->assertFalse( metadata_exists( 'user', $this->user_id, Didar_Sync_Manager::META_PERSON_STATE ) );
		$this->assertSame( 'person-existing', get_user_meta( $this->user_id, Didar_Sync_Manager::USER_PERSON_META, true ) );
		$this->assertFalse( wp_next_scheduled( Didar_Sync_Manager::USER_HOOK, array( $this->user_id ) ) );
	}

	public function test_discarding_one_submission_removes_only_its_state_and_retry() {
		foreach ( array( $this->post_id, $this->other_post_id ) as $post_id ) {
			update_post_meta( $post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending' ) );
			wp_schedule_single_event( time() + 120, Didar_Sync_Manager::CRON_HOOK, array( $post_id ), true );
		}
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, 'deal-existing' );

		$this->manager()->discard_queue_item( 'submission:' . $this->post_id );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, Didar_Sync_Manager::META_STATE ) );
		$this->assertSame( 'deal-existing', get_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, true ) );
		$this->assertFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $this->post_id ) ) );
		$this->assertTrue( metadata_exists( 'post', $this->other_post_id, Didar_Sync_Manager::META_STATE ) );
		$this->assertNotFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $this->other_post_id ) ) );
	}

	public function test_run_now_uses_only_the_selected_canonical_worker_item() {
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending' ) );
		update_post_meta( $this->other_post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending' ) );
		$manager = $this->test_manager();
		$result = $manager->run_queue_item( 'submission:' . $this->post_id );

		$this->assertNotWPError( $result );
		$this->assertSame( array( array( 'submission', $this->post_id ) ), $manager->executed );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, Didar_Sync_Manager::META_STATE ) );
		$this->assertTrue( metadata_exists( 'post', $this->other_post_id, Didar_Sync_Manager::META_STATE ) );
	}

	public function test_run_failure_keeps_item_queued_and_active_lock_is_rejected() {
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending' ) );
		$manager = $this->test_manager();
		$manager->result = new WP_Error( 'transport_timeout' );
		$this->assertWPError( $manager->run_queue_item( 'submission:' . $this->post_id ) );
		$this->assertTrue( metadata_exists( 'post', $this->post_id, Didar_Sync_Manager::META_STATE ) );

		update_option( Didar_Sync_Manager::LOCK_PREFIX . $this->post_id, array( 'token' => 'active', 'expires_at' => time() + 120 ), false );
		$this->assertSame( 'didar_sync_locked', $manager->run_queue_item( 'submission:' . $this->post_id )->get_error_code() );
		delete_option( Didar_Sync_Manager::LOCK_PREFIX . $this->post_id );
	}

	public function test_case_state_is_visible_but_has_no_independent_run_path() {
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_CASE_STATE, array( 'status' => 'pending' ) );
		$items = array_values( array_filter( $this->manager()->queue_inventory(), function ( $item ) { return 'case' === $item['queue_type'] && $this->post_id === $item['object_id']; } ) );
		$this->assertCount( 1, $items );
		$this->assertFalse( $items[0]['executable'] );
		$this->assertSame( 'didar_queue_item_not_executable', $this->manager()->run_queue_item( $items[0]['item_key'] )->get_error_code() );
	}

	public function test_arbitrary_item_key_is_rejected() {
		$this->assertSame( 'didar_queue_item_not_found', $this->manager()->run_queue_item( 'submission:999999999' )->get_error_code() );
		$this->assertSame( 'didar_queue_item_not_found', $this->manager()->discard_queue_item( 'hook:didar_process_sync' )->get_error_code() );
	}

	private function create_submission( $author_id ) {
		$post_id = self::factory()->post->create( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $author_id ) );
		update_post_meta( $post_id, '_didar_form_type', 'visa_request' );
		return $post_id;
	}

	private function manager() {
		return Didar_Plugin::instance()->sync_manager;
	}

	private function test_manager() {
		$plugin = Didar_Plugin::instance();
		return new Didar_Queue_Manager_Test_Double( $plugin->registry, $plugin->settings, $plugin->event_log, $plugin->service, $plugin->file_service, $plugin->logger, $plugin->case_service );
	}
}
