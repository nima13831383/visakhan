<?php

class Test_Didar_Sync_Generation extends WP_UnitTestCase {
	private $post_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		Didar_Post_Type::register();
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post_id = self::factory()->post->create( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $this->user_id ) );
		update_post_meta( $this->post_id, '_didar_form_type', 'visa_request' );
		update_post_meta( $this->post_id, '_didar_fields', array( 'first_name' => 'Generation' ) );
		$this->reset_request_generation_cache();
	}

	public function tear_down() {
		foreach ( array( Didar_Sync_Manager::CRON_HOOK, Didar_Sync_Manager::USER_HOOK ) as $hook ) {
			foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
				foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
					$args = is_array( $event['args'] ?? null ) ? $event['args'] : array();
					if ( in_array( $this->post_id, $args, true ) || in_array( $this->user_id, $args, true ) ) { wp_unschedule_event( $timestamp, $hook, $args ); }
				}
			}
		}
		wp_delete_post( $this->post_id, true );
		if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( $this->user_id ); }
		$this->reset_request_generation_cache();
		parent::tear_down();
	}

	public function test_three_automatic_failures_exhaust_one_generation_and_one_hundred_callbacks_are_noops() {
		$state = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'submission_update' ) );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->invoke( 'reserve_submission_execution', array( $this->post_id, 'automatic', $state['generation_id'] ) );
			$this->invoke( 'fail', array( $this->post_id, 'transport_timeout', true ) );
		}
		$state = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertSame( 3, (int) $state['automatic_attempts'] );
		$this->assertSame( 'exhausted', $state['status'] );
		for ( $run = 0; $run < 100; $run++ ) { $this->manager()->process_scheduled_submission( $this->post_id, $state['generation_id'] ); }
		$state = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertSame( 3, (int) $state['automatic_attempts'] );
	}

	public function test_success_is_terminal_even_when_a_stale_callback_runs() {
		$state = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'submission_update' ) );
		$this->invoke( 'reserve_submission_execution', array( $this->post_id, 'automatic', $state['generation_id'] ) );
		$this->invoke( 'success', array( $this->post_id, 'deal-test', 'pending_review' ) );
		for ( $run = 0; $run < 100; $run++ ) { $this->manager()->process_scheduled_submission( $this->post_id, $state['generation_id'] ); }
		$state = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertSame( 'synced', $state['status'] );
		$this->assertSame( 1, (int) $state['automatic_attempts'] );
	}

	public function test_explicit_same_value_resave_creates_a_fresh_generation_but_internal_meta_does_not() {
		$first = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'workflow_update' ) );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true ) );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_DEAL_ID, 'existing-deal' );
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_CASE_STATE, array( 'status' => 'pending', 'case_id' => 'existing-case' ) );
		Didar_Plugin::instance()->event_log->add( $this->post_id, 'didar_sync_failed', null, null, array( 'source' => 'test' ) );
		$this->assertSame( $first['generation_id'], get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true )['generation_id'] );
		$this->reset_request_generation_cache(); // Simulates the later, separate admin Update request.
		$second = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'workflow_update' ) );
		$this->assertNotSame( $first['generation_id'], $second['generation_id'] );
		$this->assertSame( $first['payload_fingerprint'], $second['payload_fingerprint'] );
		$this->assertSame( 0, (int) $second['automatic_attempts'] );
	}

	public function test_manual_failure_after_exhaustion_does_not_restart_automatic_retries() {
		$state = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'submission_update' ) );
		$state['automatic_attempts'] = 3; $state['attempts'] = 3; $state['status'] = 'exhausted';
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, $state );
		$this->invoke( 'reserve_submission_execution', array( $this->post_id, 'manual', $state['generation_id'] ) );
		$this->invoke( 'fail', array( $this->post_id, 'manual_transport_failure', true ) );
		$after = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertSame( 'exhausted', $after['status'] );
		$this->assertSame( 3, (int) $after['automatic_attempts'] );
		$this->assertSame( 1, (int) $after['manual_attempts'] );
		$this->assertFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $this->post_id, $state['generation_id'] ) ) );
	}

	public function test_manual_success_reuses_an_exhausted_generation_and_is_terminal() {
		$state = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'submission_update' ) );
		$state['automatic_attempts'] = 3; $state['attempts'] = 3; $state['status'] = 'exhausted';
		update_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, $state );
		$this->invoke( 'reserve_submission_execution', array( $this->post_id, 'manual', $state['generation_id'] ) );
		$this->invoke( 'success', array( $this->post_id, 'deal-existing', 'pending_review' ) );
		$after = get_post_meta( $this->post_id, Didar_Sync_Manager::META_STATE, true );
		$this->assertSame( $state['generation_id'], $after['generation_id'] );
		$this->assertSame( 'synced', $after['status'] );
		$this->assertSame( 3, (int) $after['automatic_attempts'] );
		$this->assertSame( 1, (int) $after['manual_attempts'] );
	}

	public function test_duplicate_cron_and_concurrent_lock_are_bounded_per_generation() {
		$state = $this->invoke( 'begin_submission_generation', array( $this->post_id, 'submission_update' ) );
		$this->invoke( 'schedule_submission', array( $this->post_id, $state['generation_id'], time() + 120, 'test' ) );
		$this->invoke( 'schedule_submission', array( $this->post_id, $state['generation_id'], time() + 120, 'test' ) );
		$this->assertNotFalse( wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $this->post_id, $state['generation_id'] ) ) );
		$first = $this->invoke( 'acquire_submission_lock', array( $this->post_id ) );
		$second = $this->invoke( 'acquire_submission_lock', array( $this->post_id ) );
		$this->assertNotEmpty( $first );
		$this->assertSame( '', $second );
		$this->invoke( 'release_submission_lock', array( $this->post_id, $first ) );
	}

	public function test_person_generation_uses_the_same_three_automatic_execution_cap() {
		$state = $this->invoke( 'begin_user_generation', array( $this->user_id, 'frontend_profile' ) );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->invoke( 'reserve_user_execution', array( $this->user_id, 'automatic', $state['generation_id'] ) );
			$this->invoke( 'fail_user', array( $this->user_id, 'transport_timeout', 'automatic', true ) );
		}
		$state = get_user_meta( $this->user_id, Didar_Sync_Manager::META_PERSON_STATE, true );
		$this->assertSame( 'exhausted', $state['status'] );
		$this->assertSame( 3, (int) $state['automatic_attempts'] );
		for ( $run = 0; $run < 100; $run++ ) { $this->manager()->process_scheduled_user( $this->user_id, $state['generation_id'] ); }
		$this->assertSame( 3, (int) get_user_meta( $this->user_id, Didar_Sync_Manager::META_PERSON_STATE, true )['automatic_attempts'] );
	}

	public function test_explicit_same_value_profile_resave_creates_a_fresh_person_generation() {
		$first = $this->invoke( 'begin_user_generation', array( $this->user_id, 'frontend_profile' ) );
		update_user_meta( $this->user_id, Didar_Sync_Manager::USER_PERSON_META, 'existing-person' );
		$this->assertSame( $first['generation_id'], get_user_meta( $this->user_id, Didar_Sync_Manager::META_PERSON_STATE, true )['generation_id'] );
		$this->reset_request_generation_cache();
		$second = $this->invoke( 'begin_user_generation', array( $this->user_id, 'frontend_profile' ) );
		$this->assertNotSame( $first['generation_id'], $second['generation_id'] );
		$this->assertSame( $first['payload_fingerprint'], $second['payload_fingerprint'] );
		$this->assertSame( 0, (int) $second['automatic_attempts'] );
	}

	private function manager() { return Didar_Plugin::instance()->sync_manager; }

	private function invoke( $method, $arguments ) {
		$reflection = new ReflectionMethod( 'Didar_Sync_Manager', $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->manager(), $arguments );
	}

	private function reset_request_generation_cache() {
		$property = new ReflectionProperty( 'Didar_Sync_Manager', 'request_submission_generations' ); $property->setAccessible( true ); $property->setValue( null, array() );
		$property = new ReflectionProperty( 'Didar_Sync_Manager', 'request_user_generations' ); $property->setAccessible( true ); $property->setValue( null, array() );
	}
}
