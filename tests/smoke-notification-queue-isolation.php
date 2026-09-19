<?php

/** No-network isolation smoke test for the SMS notification queue. */
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/';
require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! interface_exists( 'Didar_Notification_Channel_Interface' ) ) { throw new RuntimeException( 'Notification classes did not load.' ); }

class Didar_Notification_Isolation_Smoke_Channel implements Didar_Notification_Channel_Interface {
	public $calls = array();
	public function send( $job, $credentials ) { $this->calls[] = absint( $job['job_id'] ?? 0 ); return array( 'success' => true, 'retryable' => false, 'provider_id' => 'isolation-provider-id', 'provider_code' => 'accepted', 'error_code' => '', 'error_message' => '' ); }
}

function didar_notification_isolation_cron_snapshot() {
	$hooks = array( Didar_Sync_Manager::CRON_HOOK, Didar_Sync_Manager::USER_HOOK );
	$events = array();
	foreach ( (array) _get_cron_array() as $timestamp => $scheduled ) { foreach ( $hooks as $hook ) { foreach ( (array) ( $scheduled[ $hook ] ?? array() ) as $event ) { $events[] = array( 'timestamp' => (int) $timestamp, 'hook' => $hook, 'args' => (array) ( $event['args'] ?? array() ), 'schedule' => (string) ( $event['schedule'] ?? '' ) ); } } }
	usort( $events, function ( $left, $right ) { return strcmp( wp_json_encode( $left ), wp_json_encode( $right ) ); } );
	$schedules = wp_get_schedules();
	return array( 'events' => $events, 'worker_schedule' => $schedules[ Didar_Sync_Manager::WORKER_SCHEDULE ] ?? null );
}

function didar_notification_isolation_unschedule_exact( $hook, $args ) {
	foreach ( (array) _get_cron_array() as $timestamp => $scheduled ) { foreach ( (array) ( $scheduled[ $hook ] ?? array() ) as $event ) { $event_args = (array) ( $event['args'] ?? array() ); if ( serialize( $event_args ) === serialize( (array) $args ) ) { wp_unschedule_event( $timestamp, $hook, $event_args ); } } }
}

function didar_notification_isolation_state( $post_id, $user_id ) {
	return array( 'post_state' => get_post_meta( $post_id, Didar_Sync_Manager::META_STATE, true ), 'person_state' => get_user_meta( $user_id, Didar_Sync_Manager::META_PERSON_STATE, true ), 'deal_id' => get_post_meta( $post_id, Didar_Sync_Manager::META_DEAL_ID, true ), 'person_id' => get_post_meta( $post_id, Didar_Sync_Manager::META_PERSON_ID, true ), 'companion_cases' => get_post_meta( $post_id, Didar_Sync_Manager::META_COMPANION_CASES, true ), 'main_case' => get_post_meta( $post_id, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, true ), 'case_state' => get_post_meta( $post_id, Didar_Sync_Manager::META_CASE_STATE, true ), 'user_person_id' => get_user_meta( $user_id, Didar_Sync_Manager::USER_PERSON_META, true ), 'submission_lock' => get_option( Didar_Sync_Manager::LOCK_PREFIX . absint( $post_id ), '__missing__' ), 'person_lock' => get_option( Didar_Sync_Manager::USER_LOCK_PREFIX . absint( $user_id ), '__missing__' ) );
}

function didar_notification_isolation_user_snapshot( $user_id, $keys ) { $out = array(); foreach ( $keys as $key ) { $out[ $key ] = array( 'exists' => metadata_exists( 'user', $user_id, $key ), 'value' => get_user_meta( $user_id, $key, true ) ); } return $out; }
function didar_notification_isolation_restore_user( $user_id, $snapshot ) { foreach ( $snapshot as $key => $item ) { if ( ! empty( $item['exists'] ) ) { update_user_meta( $user_id, $key, $item['value'] ); } else { delete_user_meta( $user_id, $key ); } } }

$failures = array();
$checks = 0;
$created_post = 0;
$created_job_keys = array();
$synthetic_didar_events = array();
$admin_id = 0;
$previous_user_id = get_current_user_id();
$settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
$user_meta_snapshot = array();
$cap_was_present = false;
$assert = function ( $condition, $label ) use ( &$checks, &$failures ) { $checks++; if ( ! $condition ) { $failures[] = $label; } };

try {
	global $wpdb;
	$plugin = Didar_Plugin::instance();
	$manager = $plugin->notification_manager;
	$queue = $manager->queue();
	$processing_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Didar_Notification_Queue::table_name() . " WHERE state = 'processing'" );
	if ( $processing_count ) { throw new RuntimeException( 'Precondition failed: existing processing notification jobs were found; no isolation mutation was attempted.' ); }
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	$admin_id = absint( $admin_ids[0] ?? 0 );
	if ( ! $admin_id ) { throw new RuntimeException( 'No local administrator available.' ); }
	$user = get_user_by( 'id', $admin_id );
	$cap_was_present = user_can( $user, 'didar_receive_requests' );
	if ( ! $cap_was_present ) { $user->add_cap( 'didar_receive_requests' ); }
	$user_meta_snapshot = didar_notification_isolation_user_snapshot( $admin_id, array( 'digits_phone', 'digits_phone_no', 'digt_countrycode', Didar_Sync_Manager::META_PERSON_STATE, Didar_Sync_Manager::USER_PERSON_META ) );
	update_user_meta( $admin_id, 'digits_phone', '09120000000' ); update_user_meta( $admin_id, 'digits_phone_no', '' ); update_user_meta( $admin_id, 'digt_countrycode', '' ); wp_set_current_user( $admin_id );

	$run_id = wp_generate_uuid4();
	$marker = 'Notification queue isolation ' . $run_id;
	$created_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $admin_id, 'post_title' => $marker ), true );
	if ( is_wp_error( $created_post ) ) { throw new RuntimeException( 'Synthetic request creation failed.' ); }
	update_post_meta( $created_post, '_didar_form_type', 'visa_request' );
	update_post_meta( $created_post, Didar_Sync_Manager::META_STATE, array( 'status' => 'pending', 'generation_id' => 'didar-generation-' . $run_id, 'attempts' => 2, 'last_error' => 'synthetic-preserved' ) );
	update_user_meta( $admin_id, Didar_Sync_Manager::META_PERSON_STATE, array( 'status' => 'retry', 'generation_id' => 'person-generation-' . $run_id, 'attempts' => 1, 'last_error' => 'synthetic-person-preserved' ) );
	update_post_meta( $created_post, Didar_Sync_Manager::META_DEAL_ID, 'synthetic-deal-' . $run_id ); update_post_meta( $created_post, Didar_Sync_Manager::META_PERSON_ID, 'synthetic-person-' . $run_id ); update_post_meta( $created_post, Didar_Sync_Manager::META_COMPANION_CASES, array( 'cmp_' . $run_id => array( 'case_id' => 'synthetic-case-' . $run_id ) ) ); update_post_meta( $created_post, Didar_Sync_Manager::META_MAIN_APPLICANT_CASE, array( 'case_id' => 'synthetic-main-case-' . $run_id ) ); update_post_meta( $created_post, Didar_Sync_Manager::META_CASE_STATE, array( 'status' => 'pending', 'marker' => $run_id ) ); update_user_meta( $admin_id, Didar_Sync_Manager::USER_PERSON_META, 'synthetic-user-person-' . $run_id );

	$sync_event_args = array( $created_post, 'didar-generation-' . $run_id ); $user_event_args = array( $admin_id, 'person-generation-' . $run_id );
	wp_schedule_single_event( time() + 300, Didar_Sync_Manager::CRON_HOOK, $sync_event_args, true ); wp_schedule_single_event( time() + 300, Didar_Sync_Manager::USER_HOOK, $user_event_args, true );
	$synthetic_didar_events = array( array( Didar_Sync_Manager::CRON_HOOK, $sync_event_args ), array( Didar_Sync_Manager::USER_HOOK, $user_event_args ) );

	$channel = new Didar_Notification_Isolation_Smoke_Channel(); $manager->set_channel( $channel );
	$create_job = function ( $label, $next_attempt_at = '' ) use ( $queue, $created_post, $run_id, &$created_job_keys ) {
		$key = 'sms_isolation_' . $run_id . '_' . wp_generate_uuid4() . '_' . sanitize_key( $label );
		$job = $queue->create( array( 'idempotency_key' => $key, 'event_key' => 'visa_request.created', 'submission_id' => $created_post, 'destination' => '09120000000', 'body_id' => 9101, 'variable_mapping' => array( 'request_number' ), 'variable_values' => array( (string) $created_post ), 'snapshot' => array( 'isolation_marker' => $run_id, 'label' => $label ), 'next_attempt_at' => $next_attempt_at ) );
		if ( is_wp_error( $job ) || ! is_array( $job ) || (string) ( $job['idempotency_key'] ?? '' ) !== $key ) { throw new RuntimeException( 'Synthetic notification job was not newly created.' ); }
		$created_job_keys[ absint( $job['job_id'] ) ] = $key; return $job;
	};

	$didar_before = didar_notification_isolation_state( $created_post, $admin_id ); $cron_before = didar_notification_isolation_cron_snapshot();
	$run_job = $create_job( 'run_now' ); $unrelated_job = $create_job( 'unrelated' ); $before_count = count( $queue->list_jobs( array( 'submission_id' => $created_post ), 200 ) ); $manager->process_job( $run_job['job_id'] );
	$assert( 'sent' === $queue->get( $run_job['job_id'] )['state'] && $before_count === count( $queue->list_jobs( array( 'submission_id' => $created_post ), 200 ) ), 'Run Now targets one SMS job without creating a duplicate' );
	$assert( 'queued' === $queue->get( $unrelated_job['job_id'] )['state'] && 1 === count( $channel->calls ), 'Run Now leaves an unrelated SMS job unchanged' );
	$assert( $didar_before === didar_notification_isolation_state( $created_post, $admin_id ) && $cron_before === didar_notification_isolation_cron_snapshot(), 'Run Now preserves Didar state and scheduled sync events' );

	$retry_job = $create_job( 'retry' ); $queue->claim( $retry_job['job_id'] ); $queue->mark_retry( $retry_job['job_id'], 'transport_error', 'Synthetic retry.', 60 ); $retry_before = didar_notification_isolation_state( $created_post, $admin_id ); $retry_count = count( $queue->list_jobs( array( 'submission_id' => $created_post ), 200 ) ); $retry_result = $manager->retry_job( $retry_job['job_id'] ); $retry_state = $queue->get( $retry_job['job_id'] );
	$assert( $retry_result && 'retry' === $retry_state['state'] && $retry_count === count( $queue->list_jobs( array( 'submission_id' => $created_post ), 200 ) ), 'Retry reuses one notification row without creating a duplicate' );
	$assert( 1 === (int) $retry_state['attempts'] && '' === $retry_state['error_code'] && $retry_before === didar_notification_isolation_state( $created_post, $admin_id ), 'Retry resets only notification state and preserves Didar state' );

	$active_job = $create_job( 'active' ); $queue->claim( $active_job['job_id'] ); $assert( false === $manager->retry_job( $active_job['job_id'] ) && false === $queue->discard( $active_job['job_id'] ) && 'processing' === $queue->get( $active_job['job_id'] )['state'], 'Retry and Discard cannot affect a processing notification job' );
	$discard_job = $create_job( 'discard' ); $discard_before = didar_notification_isolation_state( $created_post, $admin_id ); $assert( $queue->discard( $discard_job['job_id'] ) && 'discarded' === $queue->get( $discard_job['job_id'] )['state'] && get_post( $created_post ) && $discard_before === didar_notification_isolation_state( $created_post, $admin_id ), 'Discard affects only the selected row and preserves request, IDs, and Case state' );

	$race_job = $create_job( 'completion_race' ); $queue->claim( $race_job['job_id'] ); $queue->mark_retry( $race_job['job_id'], 'transport_error', 'Synthetic retry.', 60 ); $assert( false === $queue->mark_success( $race_job['job_id'], 'late-provider-id' ) && 'retry' === $queue->get( $race_job['job_id'] )['state'], 'Late completion cannot overwrite a newer retry state' );
	$sent_race_job = $create_job( 'completion_sent' ); $queue->claim( $sent_race_job['job_id'] ); $queue->mark_success( $sent_race_job['job_id'], 'synthetic-provider-id' ); $assert( false === $queue->mark_failed( $sent_race_job['job_id'], 'late_failure', 'Late failure.' ) && 'sent' === $queue->get( $sent_race_job['job_id'] )['state'], 'Late failure cannot overwrite a sent state' );

	$future_job = $create_job( 'future', gmdate( 'Y-m-d H:i:s', time() + 600 ) ); $sent_job = $create_job( 'sent_scan' ); $queue->claim( $sent_job['job_id'] ); $queue->mark_success( $sent_job['job_id'], 'synthetic-sent' ); $discarded_job = $create_job( 'discarded_scan' ); $queue->discard( $discarded_job['job_id'] ); $stale_job = $create_job( 'stale' ); $queue->claim( $stale_job['job_id'] );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Didar_Notification_Queue::table_name() . ' SET locked_at = %s WHERE job_id = %d AND idempotency_key = %s', gmdate( 'Y-m-d H:i:s', time() - 1800 ), $stale_job['job_id'], $created_job_keys[ $stale_job['job_id'] ] ) );
	$recovery_before = $queue->get( $stale_job['job_id'] ); $didar_before_recovery = didar_notification_isolation_state( $created_post, $admin_id ); $recovered = $queue->recover_stale( 60 ); $recovery_after = $queue->get( $stale_job['job_id'] ); $due_ids = $queue->due_job_ids( 200 );
	$assert( $recovered >= 1 && 'retry' === $recovery_after['state'] && (int) $recovery_after['attempts'] === (int) $recovery_before['attempts'], 'Recovery requeues stale work without consuming an attempt' );
	$assert( ! in_array( (int) $future_job['job_id'], $due_ids, true ) && ! in_array( (int) $active_job['job_id'], $due_ids, true ) && ! in_array( (int) $sent_job['job_id'], $due_ids, true ) && ! in_array( (int) $discarded_job['job_id'], $due_ids, true ), 'Recovery skips future, active, sent, and discarded notification jobs' );
	$assert( $didar_before_recovery === didar_notification_isolation_state( $created_post, $admin_id ) && $cron_before === didar_notification_isolation_cron_snapshot(), 'Recovery preserves Didar state and scheduled sync events' );
	$before_purge = didar_notification_isolation_state( $created_post, $admin_id );
	$settings_before_purge = get_option( Didar_Settings::OPTION_NAME, array() );
	$purged_ids = $manager->purge_actionable_jobs();
	$assert( is_array( $purged_ids ) && in_array( (int) $unrelated_job['job_id'], $purged_ids, true ) && 'discarded' === $queue->get( $unrelated_job['job_id'] )['state'], 'Purge discards actionable Notification jobs without executing them' );
	$assert( 'processing' === $queue->get( $active_job['job_id'] )['state'] && $before_purge === didar_notification_isolation_state( $created_post, $admin_id ) && $settings_before_purge === get_option( Didar_Settings::OPTION_NAME, array() ), 'Notification purge preserves active locks, request/CRM state, and configuration' );
	$assert( $cron_before === didar_notification_isolation_cron_snapshot(), 'Notification purge leaves Didar scheduled events unchanged' );

	$admin_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-didar-admin.php' );
	$assert( substr_count( $admin_source, "'didar_run_notification_job'" ) >= 2 && substr_count( $admin_source, "'didar_retry_notification_job'" ) >= 2 && substr_count( $admin_source, "'didar_discard_notification_job'" ) >= 2, 'Each SMS action has a distinct admin-post and nonce action' );
	$assert( false !== strpos( $admin_source, "'POST' !== strtoupper" ) && false !== strpos( $admin_source, "current_user_can( 'didar_manage_settings' )" ) && false !== strpos( $admin_source, 'check_admin_referer( $nonce_action )' ), 'SMS actions require POST, capability, and nonce checks' );
	$assert( Didar_Notification_Manager::CRON_HOOK !== Didar_Sync_Manager::CRON_HOOK && Didar_Notification_Manager::ITEM_HOOK !== Didar_Sync_Manager::CRON_HOOK && Didar_Notification_Manager::CRON_HOOK !== Didar_Sync_Manager::USER_HOOK, 'Notification hooks are distinct from Didar sync hooks' );
	$assert( $didar_before === didar_notification_isolation_state( $created_post, $admin_id ), 'All SMS queue operations preserve synthetic Didar state byte-for-byte' );
} catch ( Throwable $e ) { $failures[] = 'exception: ' . $e->getMessage(); }
finally {
	global $wpdb;
	$cleanup_queue = new Didar_Notification_Queue();
	foreach ( $created_job_keys as $job_id => $expected_key ) {
		$job = $cleanup_queue->get( $job_id );
		if ( $job && (string) ( $job['idempotency_key'] ?? '' ) === $expected_key ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Didar_Notification_Queue::table_name() . ' WHERE job_id = %d AND idempotency_key = %s', $job_id, $expected_key ) );
			didar_notification_isolation_unschedule_exact( Didar_Notification_Manager::ITEM_HOOK, array( $job_id ) );
		}
	}
	foreach ( $synthetic_didar_events as $event ) { didar_notification_isolation_unschedule_exact( $event[0], $event[1] ); }
	if ( $created_post && get_post( $created_post ) && get_the_title( $created_post ) === 'Notification queue isolation ' . $run_id && 'visa_request' === get_post_meta( $created_post, '_didar_form_type', true ) ) { wp_delete_post( $created_post, true ); }
	if ( $admin_id ) { didar_notification_isolation_restore_user( $admin_id, $user_meta_snapshot ); if ( ! $cap_was_present ) { $user = get_user_by( 'id', $admin_id ); if ( $user ) { $user->remove_cap( 'didar_receive_requests' ); } } }
	wp_set_current_user( $previous_user_id ); update_option( Didar_Settings::OPTION_NAME, $settings_snapshot, false );
}

if ( $failures ) { echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n"; exit( 1 ); }
echo 'PASS ' . $checks . " checks; no network, Didar calls, or external mutations.\n";
