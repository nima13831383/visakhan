<?php

class Test_Didar_Queue_Purge extends WP_UnitTestCase {
	private $post_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user = get_user_by( 'id', $this->user_id );
		$user->add_cap( 'didar_manage_settings' );
		wp_set_current_user( $this->user_id );
		$this->post_id = self::factory()->post->create( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $this->user_id ) );
		update_post_meta( $this->post_id, '_didar_form_type', 'visa_request' );
	}

	public function tear_down() {
		$this->unschedule_object_events( $this->post_id );
		$this->unschedule_object_events( $this->user_id );
		wp_delete_post( $this->post_id, true );
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function manager() {
		return Didar_Plugin::instance()->sync_manager;
	}

	public function test_purge_discards_submission_person_and_case_work_without_touching_ids() {
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending', 'attempts' => 3, 'last_error' => 'transport_timeout' ) );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, 'deal-existing' );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_PERSON_ID, 'person-existing' );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_CASE_STATE, array( 'status' => 'pending', 'issues' => array( 'case_retry' ) ) );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_COMPANION_CASES, array( 'cmp_1234567890abcdef' => array( 'case_id' => 'case-existing', 'status' => 'pending', 'last_error' => 'timeout' ) ) );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, array( 'uid' => 'main_123', 'case_id' => 'main-case-existing', 'status' => 'retrying', 'last_error' => 'timeout' ) );
		update_user_meta( $this->user_id, Didar_Sync_Manager::META_PERSON_STATE, array( 'status' => 'failed', 'attempts' => 2, 'error' => 'timeout' ) );
		update_option( Didar_Sync_Manager::LOCK_PREFIX . $this->post_id, array( 'token' => 'expired', 'expires_at' => time() - 10 ), false );
		wp_schedule_single_event( time() + 300, Didar_Sync_Manager::CRON_HOOK, array( $this->post_id ), true );
		wp_schedule_single_event( time() + 300, Didar_Sync_Manager::USER_HOOK, array( $this->user_id ), true );

		$deal_id = get_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, true );
		$person_id = get_post_meta( $this->post_id, Didar_Sync_Manager::META_PERSON_ID, true );
		$result = $this->manager()->purge_queue();

		$this->assertSame( 1, $result['submissions'] );
		$this->assertSame( 1, $result['cases'] );
		$this->assertSame( 1, $result['persons'] );
		$this->assertGreaterThanOrEqual( 2, $result['scheduled_events'] );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, Didar_Sync_Manager::META_STATE ) );
		$this->assertFalse( metadata_exists( 'user', $this->user_id, Didar_Sync_Manager::META_PERSON_STATE ) );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, Didar_Sync_Manager::META_CASE_STATE ) );
		$this->assertSame( $deal_id, get_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, true ) );
		$this->assertSame( $person_id, get_post_meta( $this->post_id, Didar_Sync_Manager::META_PERSON_ID, true ) );
		$this->assertSame( 'case-existing', get_post_meta( $this->post_id, Didar_Sync_Manager::META_COMPANION_CASES, true )['cmp_1234567890abcdef']['case_id'] );
		$this->assertSame( 'discarded', get_post_meta( $this->post_id, Didar_Sync_Manager::META_COMPANION_CASES, true )['cmp_1234567890abcdef']['status'] );
		$this->assertSame( 'main-case-existing', get_post_meta( $this->post_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, true )['case_id'] );
		$this->assertSame( 'discarded', get_post_meta( $this->post_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, true )['status'] );
		$this->assertSame( 0, $this->manager()->queue_status()['eligible'] );
		$this->assertSame( 0, $this->manager()->queue_status()['stale_locks'] );
	}

	public function test_recurring_workers_remain_and_discarded_items_are_not_swept() {
		wp_schedule_event( time() + 300, Didar_Sync_Manager::WORKER_SCHEDULE, Didar_Sync_Manager::CRON_HOOK, array(), true );
		wp_schedule_event( time() + 300, Didar_Sync_Manager::WORKER_SCHEDULE, Didar_Sync_Manager::USER_HOOK, array(), true );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending' ) );
		$this->manager()->purge_queue();

		$this->assertNotFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Didar_Sync_Manager::USER_HOOK ) );
		$this->assertSame( 0, $this->manager()->queue_status()['eligible'] );
	}

	public function test_future_submission_can_be_queued_again_after_purge() {
		$this->manager()->purge_queue();
		$this->manager()->queue_submission( $this->post_id );
		$this->assertSame( 'pending', get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true )['status'] );
		$state = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertNotFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $this->post_id, $state['generation_id'] ) ) );
	}

	private function unschedule_object_events( $object_id ) {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( array( Didar_Sync_Manager::CRON_HOOK, Didar_Sync_Manager::USER_HOOK ) as $hook ) {
				foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
					$args = is_array( $event['args'] ?? null ) ? $event['args'] : array();
					if ( absint( $args[0] ?? 0 ) === absint( $object_id ) ) { wp_unschedule_event( $timestamp, $hook, $args ); }
				}
			}
		}
	}
}
