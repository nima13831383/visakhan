<?php

/**
 * No-network notification architecture smoke test.
 *
 * Run with:
 * C:\xampp\php\php.exe tests\smoke-notifications.php
 */

define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/';
require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! interface_exists( 'Didar_Notification_Channel_Interface' ) ) { throw new RuntimeException( 'Notification classes did not load.' ); }

class Didar_Notification_Smoke_Channel implements Didar_Notification_Channel_Interface {
	public $calls = array();
	public $retry = false;

	public function send( $job, $credentials ) {
		$this->calls[] = array( 'job_id' => $job['job_id'], 'credentials' => $credentials );
		if ( $this->retry ) { return array( 'success' => false, 'retryable' => true, 'provider_id' => '', 'provider_code' => 'temporary', 'error_code' => 'transport_error', 'error_message' => 'Synthetic retry.' ); }
		return array( 'success' => true, 'retryable' => false, 'provider_id' => '123456789', 'provider_code' => '123456789', 'error_code' => '', 'error_message' => '' );
	}
}

$failures = array();
$checks = 0;
$created_post = 0;
$created_jobs = array();
$user_id = 0;
$meta_snapshot = array();
$cap_was_present = false;
$settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
$assert = function ( $condition, $label ) use ( &$checks, &$failures ) { $checks++; if ( ! $condition ) { $failures[] = $label; } };

try {
	$plugin = Didar_Plugin::instance();
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	$user_id = absint( $admin_ids[0] ?? 0 );
	if ( ! $user_id ) { throw new RuntimeException( 'No local administrator available.' ); }
	$user = get_user_by( 'id', $user_id );
	$cap_was_present = user_can( $user, 'didar_receive_requests' );
	if ( ! $cap_was_present ) { $user->add_cap( 'didar_receive_requests' ); }
	foreach ( array( 'digits_phone', 'digits_phone_no', 'digt_countrycode' ) as $meta_key ) { $meta_snapshot[ $meta_key ] = get_user_meta( $user_id, $meta_key, true ); }
	update_user_meta( $user_id, 'digits_phone', '09120000000' );
	update_user_meta( $user_id, 'digits_phone_no', '' );
	update_user_meta( $user_id, 'digt_countrycode', '' );
	wp_set_current_user( $user_id );

	$created_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'Notification smoke request' ), true );
	if ( is_wp_error( $created_post ) ) { throw new RuntimeException( 'Synthetic request creation failed.' ); }
	update_post_meta( $created_post, '_didar_form_type', 'visa_request' );
	update_post_meta( $created_post, '_didar_fields', array( 'first_name' => 'آزمون', 'last_name' => 'اعلان', 'national_id' => '0012345678', 'postal_code' => '1234567890' ) );
	update_post_meta( $created_post, '_didar_internal_status', 'pending_review' );
	update_post_meta( $created_post, '_didar_status', 'pending_review' );

	$assert( 6 === count( Didar_Notification_Event_Registry::all() ), 'six canonical events are registered' );
	$defaults = Didar_Notification_Event_Registry::default_configuration();
	$assert( 6 === count( $defaults ) && 0 === count( array_filter( $defaults, function ( $item ) { return ! empty( $item['enabled'] ); } ) ), 'all notification events are disabled by default' );

	$configuration = Didar_Notification_Event_Registry::default_configuration();
	$configuration['visa_request.created'] = array( 'enabled' => 1, 'user_ids' => array( $user_id ), 'send_to_owner' => 1, 'send_to_assignee' => 0, 'body_id' => '8001', 'variables' => array( 'form_type', 'request_number', 'postal_code' ) );
	$configuration['visa_request.status_changed'] = array( 'enabled' => 1, 'user_ids' => array(), 'send_to_owner' => 1, 'send_to_assignee' => 0, 'body_id' => '8002', 'variables' => array( 'request_status' ) );
	$configuration['visa_request.assignee_changed'] = array( 'enabled' => 1, 'user_ids' => array(), 'send_to_owner' => 0, 'send_to_assignee' => 1, 'body_id' => '8003', 'variables' => array( 'request_assignee' ) );
	$settings = $settings_snapshot;
	$settings['didar_notification_events'] = $configuration;
	$settings['melipayamak_username'] = 'smoke-user';
	$settings['melipayamak_api_key'] = 'synthetic-secret-never-exported';
	update_option( Didar_Settings::OPTION_NAME, $settings, false );
	$provider_request = array();
	$provider_filter = function ( $pre, $args, $url ) use ( &$provider_request ) {
		if ( Didar_Melipayamak_Sms_Channel::ENDPOINT !== $url ) {
			return $pre;
		}
		$provider_request = $args;
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'Value' => '987654321' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
	};
	add_filter( 'pre_http_request', $provider_filter, 10, 3 );
	$provider_result = ( new Didar_Melipayamak_Sms_Channel() )->send(
		array( 'body_id' => 8001, 'destination' => '09120000000', 'variable_mapping' => array( 'first_name', 'request_number' ), 'variable_values' => array( 'آزمون', (string) $created_post ) ),
		array( 'username' => 'smoke-user', 'api_key' => 'synthetic-provider-key' )
	);
	remove_filter( 'pre_http_request', $provider_filter, 10 );
	$provider_body = isset( $provider_request['body'] ) && is_array( $provider_request['body'] ) ? $provider_request['body'] : array();
	$assert( ! empty( $provider_result['success'] ) && '987654321' === (string) ( $provider_result['provider_id'] ?? '' ), 'Melipayamak adapter accepts a positive Value response without exposing the raw body' );
	$assert( 'smoke-user' === (string) ( $provider_body['username'] ?? '' ) && 'synthetic-provider-key' === (string) ( $provider_body['password'] ?? '' ) && 'آزمون;' . $created_post === (string) ( $provider_body['text'] ?? '' ) && 8001 === (int) ( $provider_body['bodyId'] ?? 0 ), 'classic SendByBaseNumber2 request payload is formed behind the adapter' );

	$channel = new Didar_Notification_Smoke_Channel();
	$manager = $plugin->notification_manager;
	$manager->set_channel( $channel );
	$first = $manager->emit( 'visa_request.created', $created_post, 'smoke-created-1' );
	$assert( 1 === count( $first ), 'owner and selected staff with one canonical mobile are deduplicated' );
	$job = $first[0];
	$created_jobs[] = $job['job_id'];
	$assert( '8001' === (string) $job['body_id'], 'Body ID is snapshotted at queue creation' );
	$assert( array( 'form_type', 'request_number', 'postal_code' ) === $job['variable_mapping'], 'variable mapping order is snapshotted' );
	$assert( array( 'درخواست ویزا', (string) $created_post, '1234567890' ) === $job['variable_values'], 'resolved variable values are snapshotted' );
	$assert( false === strpos( wp_json_encode( $job ), 'synthetic-secret-never-exported' ), 'provider credentials are absent from jobs' );

	$duplicate = $manager->emit( 'visa_request.created', $created_post, 'smoke-created-1' );
	$assert( 1 === count( $duplicate ) && (int) $duplicate[0]['job_id'] === (int) $job['job_id'], 'repeated event delivery reuses the same job idempotently' );
	$configuration['visa_request.created']['body_id'] = '8004';
	$configuration['visa_request.created']['variables'] = array( 'request_number' );
	$settings['didar_notification_events'] = $configuration;
	update_option( Didar_Settings::OPTION_NAME, $settings, false );
	$new_config_job = $manager->emit( 'visa_request.created', $created_post, 'smoke-created-2' )[0];
	$created_jobs[] = $new_config_job['job_id'];
	$assert( '8004' === (string) $new_config_job['body_id'] && array( 'request_number' ) === $new_config_job['variable_mapping'], 'new configuration applies only to later jobs' );
	$old_after_change = $manager->queue()->get( $job['job_id'] );
	$assert( '8001' === (string) $old_after_change['body_id'] && array( 'form_type', 'request_number', 'postal_code' ) === $old_after_change['variable_mapping'], 'existing queued snapshot is unchanged after settings edit' );

	$assert( true === $manager->process_job( $job['job_id'] ), 'fake provider success marks first job sent' );
	$assert( true === $manager->process_job( $new_config_job['job_id'] ), 'fake provider success marks later job sent' );
	$assert( 'sent' === $manager->queue()->get( $job['job_id'] )['state'], 'successful job has sent state' );
	$assert( 2 === count( $channel->calls ), 'no live provider was called; only fake adapter calls occurred' );

	$retry_job = $manager->emit( 'visa_request.created', $created_post, 'smoke-retry-1' )[0];
	$created_jobs[] = $retry_job['job_id'];
	$channel->retry = true;
	$manager->process_job( $retry_job['job_id'] );
	$retry_state = $manager->queue()->get( $retry_job['job_id'] );
	$assert( 'retry' === $retry_state['state'] && 1 === (int) $retry_state['attempts'], 'retryable provider result keeps the same job and schedules backoff' );
	$channel->retry = false;
	$manager->retry_job( $retry_job['job_id'] );
	$manager->process_job( $retry_job['job_id'] );
	$assert( 'sent' === $manager->queue()->get( $retry_job['job_id'] )['state'] && 2 === (int) $manager->queue()->get( $retry_job['job_id'] )['attempts'], 'retry reuses the same durable job' );

	$before_status = count( $manager->queue()->list_jobs( array(), 200 ) );
	$status_workflow = new Didar_Workflow_Manager( $plugin->registry, $plugin->settings, new Didar_Logger() );
	$status_definitions = $status_workflow->statuses( 'visa_request' );
	if ( ! $status_definitions ) { $status_definitions = Didar_Reference_Data::statuses(); }
	$new_status = 'pending_review';
	foreach ( $status_definitions as $status_key => $status_label ) { if ( 'pending_review' !== $status_key ) { $new_status = $status_key; break; } }
	$status_result = $plugin->service->update_workflow( $created_post, array( 'request_status' => $new_status ) );
	$after_status = count( $manager->queue()->list_jobs( array(), 200 ) );
	$plugin->service->update_workflow( $created_post, array( 'request_status' => $new_status ) );
	$after_same_status = count( $manager->queue()->list_jobs( array(), 200 ) );
	$assert( ! is_wp_error( $status_result ) && $after_status === $before_status + 1 && $after_same_status === $after_status, 'canonical status notification fires only on an actual change' );
	$plugin->service->update_workflow( $created_post, array( 'assigned_user_id' => $user_id ), true );
	$after_assignment = count( $manager->queue()->list_jobs( array(), 200 ) );
	$plugin->service->update_workflow( $created_post, array( 'assigned_user_id' => $user_id ), true );
	$assert( $after_assignment === $after_same_status + 1 && count( $manager->queue()->list_jobs( array(), 200 ) ) === $after_assignment, 'assignee notification fires only on an actual change' );

	$transfer = new Didar_Settings_Transfer( $plugin->registry, $plugin->settings, new Didar_Logger() );
	$portable = $transfer->portable_settings( $settings );
	$export = $transfer->export_json();
	$assert( isset( $portable['didar_notification_events']['visa_request.created'] ), 'event configuration is portable' );
	$assert( ! isset( $portable['melipayamak_api_key'] ) && false === strpos( $export, 'synthetic-secret-never-exported' ), 'API key is excluded from portable settings export' );
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
} finally {
	global $wpdb;
	foreach ( array_unique( array_filter( array_map( 'absint', $created_jobs ) ) ) as $job_id ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Didar_Notification_Queue::table_name() . ' WHERE job_id = %d', $job_id ) );
		$cron = _get_cron_array();
		foreach ( (array) $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ Didar_Notification_Manager::ITEM_HOOK ] ) ) {
				foreach ( $hooks[ Didar_Notification_Manager::ITEM_HOOK ] as $hash => $event ) {
					if ( in_array( $job_id, (array) ( $event['args'] ?? array() ), true ) ) { wp_unschedule_event( $timestamp, Didar_Notification_Manager::ITEM_HOOK, $event['args'] ); }
				}
			}
		}
	}
	if ( $created_post ) { wp_delete_post( $created_post, true ); }
	if ( $user_id ) {
		foreach ( $meta_snapshot as $key => $value ) { if ( '' === $value || null === $value ) { delete_user_meta( $user_id, $key ); } else { update_user_meta( $user_id, $key, $value ); } }
		if ( ! $cap_was_present ) { $user = get_user_by( 'id', $user_id ); if ( $user ) { $user->remove_cap( 'didar_receive_requests' ); } }
	}
	update_option( Didar_Settings::OPTION_NAME, $settings_snapshot, false );
}

if ( $failures ) {
	echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n";
	exit( 1 );
}
echo 'PASS ' . $checks . " checks; no network or provider calls.\n";
