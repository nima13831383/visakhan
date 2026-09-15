<?php

/**
 * Local, no-network recovery sweep smoke test.
 *
 * Run with: C:\xampp\php\php.exe tests\smoke-sync-stranding-recovery.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/';
require dirname( __DIR__, 4 ) . '/wp-load.php';

class Didar_Stranding_Recovery_Test_Double extends Didar_Sync_Manager {
	public $dispatched = array();

	public function process_scheduled_submission( $post_id = 0, $generation_id = '' ) {
		$this->dispatched[] = array( absint( $post_id ), sanitize_text_field( (string) $generation_id ) );
		return true;
	}
}

$failures = array();
$checks   = 0;
$created  = array();
$locks    = array();
$assert = function ( $condition, $label ) use ( &$checks, &$failures ) {
	$checks++;
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$invoke = function ( $object, $method, $arguments = array() ) {
	$reflection = new ReflectionMethod( 'Didar_Sync_Manager', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $arguments );
};

try {
	Didar_Post_Type::register();
	$plugin  = Didar_Plugin::instance();
	$manager = new Didar_Stranding_Recovery_Test_Double( $plugin->registry, $plugin->settings, $plugin->event_log, $plugin->service, $plugin->file_service, $plugin->logger, $plugin->case_service );
	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	$user_id = absint( $administrators[0] ?? 0 );
	if ( ! $user_id ) {
		throw new RuntimeException( 'No local administrator is available for smoke-test ownership.' );
	}
	$make_post = function () use ( $user_id, &$created ) {
		$post_id = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'Sync recovery smoke' ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Unable to create smoke-test submission.' );
		}
		update_post_meta( $post_id, '_didar_form_type', 'visa_request' );
		$created[] = $post_id;
		return $post_id;
	};
	$state_for = function ( $status = 'pending', $attempts = 0, $created_at = null, $next_retry_at = 0 ) {
		return array( 'generation_id' => wp_generate_uuid4(), 'payload_fingerprint' => 'smoke', 'source' => 'smoke', 'created_at' => null === $created_at ? time() : $created_at, 'updated_at' => null === $created_at ? time() : $created_at, 'automatic_attempts' => $attempts, 'manual_attempts' => 0, 'attempts' => $attempts, 'status' => $status, 'next_retry_at' => $next_retry_at, 'trace_id' => 'smoke' );
	};
	$run_sweep = function () use ( $invoke, $manager ) {
		$manager->dispatched = array();
		$invoke( $manager, 'process_pending_submissions' );
		return array_map( function ( $item ) { return $item[0]; }, $manager->dispatched );
	};

	// 1–3: normal fresh item is unchanged; missing event is ignored until stale; stale work recovers.
	$fresh = $make_post();
	$fresh_state = $state_for();
	update_post_meta( $fresh, Didar_Sync_Manager::META_STATE, $fresh_state );
	$assert( ! in_array( $fresh, $run_sweep(), true ), '1 normal fresh automatic item remains on the normal event path' );
	$assert( 0 === (int) get_post_meta( $fresh, Didar_Sync_Manager::META_STATE, true )['automatic_attempts'], '2 fresh missing-event scan consumes no attempt' );
	$stale = $make_post();
	$stale_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 );
	update_post_meta( $stale, Didar_Sync_Manager::META_STATE, $stale_state );
	$assert( in_array( $stale, $run_sweep(), true ), '3 stale pending zero-attempt generation is recovered through cron entry point' );

	// 4–5: active locks defer recovery, expired locks do not permanently block it.
	$locked = $make_post();
	$locked_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 );
	update_post_meta( $locked, Didar_Sync_Manager::META_STATE, $locked_state );
	$lock_name = Didar_Sync_Manager::LOCK_PREFIX . $locked;
	$locks[] = $lock_name;
	update_option( $lock_name, array( 'token' => 'smoke', 'expires_at' => time() + 60 ), false );
	$assert( ! in_array( $locked, $run_sweep(), true ) && 0 === (int) get_post_meta( $locked, Didar_Sync_Manager::META_STATE, true )['automatic_attempts'], '4 active lock skips recovery without consuming an attempt' );
	update_option( $lock_name, array( 'token' => 'smoke', 'expires_at' => time() - 1 ), false );
	$assert( in_array( $locked, $run_sweep(), true ), '5 expired lock permits later recovery' );

	// 6–10: retry timing and terminal/current-generation guards.
	$due = $make_post(); update_post_meta( $due, Didar_Sync_Manager::META_STATE, $state_for( 'pending', 1, time() - 60, time() - 1 ) );
	$assert( in_array( $due, $run_sweep(), true ), '6 due retry is dispatched' );
	$future = $make_post(); update_post_meta( $future, Didar_Sync_Manager::META_STATE, $state_for( 'pending', 1, time() - 60, time() + 600 ) );
	$assert( ! in_array( $future, $run_sweep(), true ), '7 retry not due is skipped' );
	$synced = $make_post(); $synced_state = $state_for( 'synced', 1, time() - 3600 ); update_post_meta( $synced, Didar_Sync_Manager::META_STATE, $synced_state );
	$assert( empty( $invoke( $manager, 'submission_recovery_candidate', array( $synced, $synced_state ) )['recover'] ), '8 synced generation is never recovered' );
	$exhausted = $make_post(); $exhausted_state = $state_for( 'exhausted', 3, time() - 3600 ); update_post_meta( $exhausted, Didar_Sync_Manager::META_STATE, $exhausted_state );
	$assert( empty( $invoke( $manager, 'submission_recovery_candidate', array( $exhausted, $exhausted_state ) )['recover'] ), '9 exhausted generation is never recovered' );
	$assert( ! $invoke( $manager, 'generation_is_eligible', array( $stale_state, 'superseded-generation' ) ), '10 superseded generation is rejected before execution' );

	// 11–14: the existing reservation, success, and failure methods remain authoritative.
	$race = $make_post(); $race_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 ); update_post_meta( $race, Didar_Sync_Manager::META_STATE, $race_state );
	$first_lock = $invoke( $manager, 'acquire_submission_lock', array( $race ) );
	$second_lock = $invoke( $manager, 'acquire_submission_lock', array( $race ) );
	$assert( '' !== $first_lock && '' === $second_lock && ! in_array( $race, $run_sweep(), true ), '11 item-event and recovery races are guarded by one shared lock' );
	$invoke( $manager, 'release_submission_lock', array( $race, $first_lock ) );
	$manual_race = $make_post(); $manual_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 ); update_post_meta( $manual_race, Didar_Sync_Manager::META_STATE, $manual_state );
	$manual_lock = $invoke( $manager, 'acquire_submission_lock', array( $manual_race ) );
	$manual_result = $manager->manual_sync( $manual_race );
	$assert( '' !== $manual_lock && is_wp_error( $manual_result ) && 'didar_sync_locked' === $manual_result->get_error_code(), '12 manual Run Now and recovery share the same execution lock' );
	$invoke( $manager, 'release_submission_lock', array( $manual_race, $manual_lock ) );
	$success = $make_post(); $success_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 ); update_post_meta( $success, Didar_Sync_Manager::META_STATE, $success_state ); $invoke( $manager, 'reserve_submission_execution', array( $success, 'automatic', $success_state['generation_id'] ) ); $invoke( $manager, 'success', array( $success, 'smoke-deal' ) );
	$assert( 'synced' === get_post_meta( $success, Didar_Sync_Manager::META_STATE, true )['status'] && ! in_array( $success, $run_sweep(), true ), '13 successful recovery is terminal and cannot run again' );
	$failed = $make_post(); $failed_state = $state_for( 'pending', 0, time() - Didar_Sync_Manager::WORKER_RECOVERY_GRACE - 1 ); update_post_meta( $failed, Didar_Sync_Manager::META_STATE, $failed_state ); $invoke( $manager, 'reserve_submission_execution', array( $failed, 'automatic', $failed_state['generation_id'] ) ); $invoke( $manager, 'fail', array( $failed, 'smoke_failure', true ) );
	$after_failed = get_post_meta( $failed, Didar_Sync_Manager::META_STATE, true );
	$assert( 1 === (int) $after_failed['automatic_attempts'] && 'pending' === $after_failed['status'], '14 failed recovered execution consumes exactly one attempt and retains retry behavior' );

	// 15: normal submissions still receive their prompt single-event schedule.
	$normal = $make_post(); $normal_state = $state_for(); update_post_meta( $normal, Didar_Sync_Manager::META_STATE, $normal_state );
	$invoke( $manager, 'schedule_submission', array( $normal, $normal_state['generation_id'], time() + 30, 'smoke' ) );
	$assert( false !== wp_next_scheduled( Didar_Sync_Manager::CRON_HOOK, array( $normal, $normal_state['generation_id'] ) ), '15 later normal submission keeps prompt item-event scheduling' );

	if ( $failures ) {
		throw new RuntimeException( implode( '; ', $failures ) );
	}
	echo 'PASS ' . $checks . ' checks; no Didar API calls.' . PHP_EOL;
} finally {
	if ( isset( $user_id ) && $user_id ) { wp_set_current_user( $user_id ); }
	foreach ( $locks as $lock_name ) { delete_option( $lock_name ); }
	foreach ( $created as $post_id ) { wp_clear_scheduled_hook( Didar_Sync_Manager::CRON_HOOK, array( $post_id, get_post_meta( $post_id, Didar_Sync_Manager::META_STATE, true )['generation_id'] ?? '' ) ); wp_delete_post( $post_id, true ); }
}
