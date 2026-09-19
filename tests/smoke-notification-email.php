<?php

/** No-network Email channel and multi-channel notification smoke test. */

define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/';
require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! interface_exists( 'Didar_Notification_Channel_Interface' ) || ! class_exists( 'Didar_WordPress_Email_Channel' ) ) { throw new RuntimeException( 'Notification Email classes did not load.' ); }

class Didar_Notification_Email_Smoke_Channel implements Didar_Notification_Channel_Interface {
	public $calls = array();
	public $retry = false;

	public function send( $job, $credentials ) {
		$this->calls[] = array( 'job' => $job, 'credentials' => $credentials );
		if ( $this->retry ) { return array( 'success' => false, 'retryable' => true, 'provider_id' => '', 'provider_code' => '', 'error_code' => 'synthetic_retry', 'error_message' => 'Synthetic retry.' ); }
		return array( 'success' => true, 'retryable' => false, 'provider_id' => '', 'provider_code' => 'synthetic_accepted', 'error_code' => '', 'error_message' => '' );
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

	$created_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'Email notification smoke request' ), true );
	if ( is_wp_error( $created_post ) ) { throw new RuntimeException( 'Synthetic request creation failed.' ); }
	update_post_meta( $created_post, '_didar_form_type', 'visa_request' );
	update_post_meta( $created_post, '_didar_fields', array( 'first_name' => 'آزمون', 'last_name' => 'ایمیل', 'postal_code' => '1234567890' ) );
	update_post_meta( $created_post, '_didar_internal_status', 'pending_review' );

	$defaults = Didar_Notification_Event_Registry::default_configuration();
	$legacy = Didar_Notification_Event_Registry::normalize_configuration( array( 'visa_request.created' => array( 'enabled' => 1, 'body_id' => 8001, 'user_ids' => array( $user_id ) ) ) );
	$all_notification_definitions = Didar_Notification_Event_Registry::all();
	$assert( 10 === count( $all_notification_definitions ), 'Email reuses six canonical events plus four manager subscriptions' );
	$assert( 6 === count( array_filter( $all_notification_definitions, function ( $definition ) { return empty( $definition['manager_only'] ); } ) ), 'Email still uses the six canonical domain event sources' );
	$assert( 10 === count( $defaults ) && false === $defaults['visa_request.created']['email_enabled'] && false === $defaults['visa_request.created.managers']['email_enabled'], 'Email is disabled by default for every subscription' );
	$assert( true === $legacy['visa_request.created']['sms_enabled'] && false === $legacy['visa_request.created']['email_enabled'], 'legacy enabled setting remains SMS-only' );

	$mapping = array( 'first_name', 'request_number', 'form_type' );
	$template = "نام: {0}\nشماره: {1}\nفرم: {2}";
	$assert( Didar_Notification_Event_Registry::validate_email_template( $template, $mapping )['valid'], 'valid numeric Email placeholders are accepted' );
	$assert( ! Didar_Notification_Event_Registry::validate_email_template( 'bad {9}', $mapping )['valid'], 'unknown Email placeholder indexes are rejected' );
	$assert( ! Didar_Notification_Event_Registry::validate_email_template( 'bad {request_number}', $mapping )['valid'], 'named Email placeholders are rejected' );
	$assert( ! Didar_Notification_Event_Registry::validate_email_template( 'bad {0', $mapping )['valid'], 'unbalanced Email placeholders are rejected' );
	$rendered = Didar_Notification_Event_Registry::render_email_template( $template, array( 'آزمون', (string) $created_post, 'درخواست ویزا' ) );
	$assert( ! is_wp_error( $rendered ) && "نام: آزمون\nشماره: {$created_post}\nفرم: درخواست ویزا" === $rendered, 'Email rendering preserves Persian text and newlines' );
	$assert( 'اعلان درخواست #' . $created_post . ': ایجاد درخواست ویزا' === Didar_Notification_Event_Registry::email_subject( 'visa_request.created', $created_post ), 'Email subject uses canonical request number and Persian event label' );

	$configuration = Didar_Notification_Event_Registry::default_configuration();
	$configuration['visa_request.created'] = array( 'sms_enabled' => 1, 'email_enabled' => 1, 'user_ids' => array( $user_id ), 'send_to_owner' => 1, 'send_to_assignee' => 0, 'body_id' => '8001', 'email_template' => $template, 'variables' => $mapping );
	$settings = $settings_snapshot;
	$settings['didar_notification_events'] = $configuration;
	$settings['melipayamak_api_key'] = 'synthetic-secret-never-exported';
	update_option( Didar_Settings::OPTION_NAME, $settings, false );

	$sms = new Didar_Notification_Email_Smoke_Channel();
	$email = new Didar_Notification_Email_Smoke_Channel();
	$manager = $plugin->notification_manager;
	$manager->set_channel( $sms, 'sms' );
	$manager->set_channel( $email, 'email' );
	$jobs = $manager->emit( 'visa_request.created', $created_post, 'email-smoke-1' );
	$created_jobs = array_map( 'absint', wp_list_pluck( $jobs, 'job_id' ) );
	$by_channel = array(); foreach ( $jobs as $job ) { $by_channel[ $job['channel'] ] = $job; }
	$email_job = $by_channel['email'] ?? array();
	$sms_job = $by_channel['sms'] ?? array();
	$assert( 2 === count( $jobs ) && isset( $by_channel['sms'], $by_channel['email'] ), 'SMS and Email create independent jobs for the same recipient' );
	$assert( 0 === strcmp( strtolower( $user->user_email ), $email_job['destination'] ?? '' ), 'Email destination uses canonical WordPress user_email' );
	$assert( 0 === strpos( (string) ( $email_job['idempotency_key'] ?? '' ), 'email_' ) && 0 === strpos( (string) ( $sms_job['idempotency_key'] ?? '' ), 'sms_' ), 'SMS and Email idempotency keys are channel-specific' );
	$legacy_sms_key = 'sms_' . hash( 'sha256', implode( '|', array( sanitize_key( 'visa_request.created' ), absint( $created_post ), 'email-smoke-1', 'mobile:09120000000' ) ) );
	$assert( $legacy_sms_key === (string) ( $sms_job['idempotency_key'] ?? '' ), 'existing SMS idempotency key shape remains compatible' );
	$assert( $template === ( $email_job['snapshot']['email_template'] ?? '' ) && $rendered === ( $email_job['snapshot']['rendered_body'] ?? '' ), 'Email template and rendered body are snapshotted' );
	$assert( 'اعلان درخواست #' . $created_post . ': ایجاد درخواست ویزا' === ( $email_job['snapshot']['subject'] ?? '' ), 'Email subject is snapshotted with the job' );
	$assert( array( 'first_name', 'request_number', 'form_type' ) === ( $email_job['variable_mapping'] ?? array() ) && array( 'آزمون', (string) $created_post, 'درخواست ویزا' ) === ( $email_job['variable_values'] ?? array() ), 'Email shares ordered mapping and resolved values with SMS' );
	$assert( false === strpos( wp_json_encode( $email_job ), 'synthetic-secret-never-exported' ), 'Email jobs do not contain provider credentials' );

	$duplicate = $manager->emit( 'visa_request.created', $created_post, 'email-smoke-1' );
	$assert( 2 === count( $duplicate ), 'repeated multi-channel event resolves two existing idempotent jobs' );
	$new_template = "تغییر: {1}\nنام: {0}";
	$configuration['visa_request.created']['email_template'] = $new_template;
	$settings['didar_notification_events'] = $configuration;
	update_option( Didar_Settings::OPTION_NAME, $settings, false );
	$new_jobs = $manager->emit( 'visa_request.created', $created_post, 'email-smoke-2' );
	$created_jobs = array_merge( $created_jobs, array_map( 'absint', wp_list_pluck( $new_jobs, 'job_id' ) ) );
	$new_email = array_values( array_filter( $new_jobs, function ( $job ) { return 'email' === $job['channel']; } ) )[0] ?? array();
	$assert( $new_template === ( $new_email['snapshot']['email_template'] ?? '' ) && $template === ( $email_job['snapshot']['email_template'] ?? '' ), 'later Email configuration does not alter an existing snapshot' );

	$assert( true === $manager->process_job( $email_job['job_id'] ) && 'sent' === $manager->queue()->get( $email_job['job_id'] )['state'], 'Email manager marks accepted fake delivery as sent' );
	$assert( true === $manager->process_job( $sms_job['job_id'] ) && 'sent' === $manager->queue()->get( $sms_job['job_id'] )['state'], 'SMS regression remains independently processable' );
	$assert( 1 === count( $email->calls ) && empty( $email->calls[0]['credentials'] ), 'Email adapter receives no provider credentials' );
	$assert( 1 === count( $sms->calls ), 'SMS adapter remains a separate channel' );

	$mail_call = array();
	$mail_arguments_filter = function ( $atts ) use ( &$mail_call ) { $mail_call = $atts; return $atts; };
	$mail_filter = function ( $pre, $atts ) { return true; };
	add_filter( 'wp_mail', $mail_arguments_filter, 1, 1 );
	add_filter( 'pre_wp_mail', $mail_filter, 9999, 2 );
	$real_email_result = ( new Didar_WordPress_Email_Channel() )->send( $email_job, array( 'must' => 'not be used' ) );
	remove_filter( 'wp_mail', $mail_arguments_filter, 1 );
	remove_filter( 'pre_wp_mail', $mail_filter, 9999 );
	$assert( ! empty( $real_email_result['success'] ) && 'wp_mail_accepted' === $real_email_result['provider_code'], 'WordPress Email adapter reports transport acceptance without network delivery' );
	$mail_to = is_array( $mail_call['to'] ?? null ) ? implode( ',', $mail_call['to'] ) : (string) ( $mail_call['to'] ?? '' );
	$assert( strtolower( $user->user_email ) === strtolower( $mail_to ), 'wp_mail receives the snapshotted destination' );
	$assert( (string) ( $email_job['snapshot']['subject'] ?? '' ) === (string) ( $mail_call['subject'] ?? '' ), 'wp_mail receives the snapshotted subject' );
	$expected_mail_body = str_replace( "\r\n", "\n", (string) ( $email_job['snapshot']['rendered_body'] ?? '' ) );
	$actual_mail_body = str_replace( "\r\n", "\n", (string) ( $mail_call['message'] ?? '' ) );
	$assert( $expected_mail_body === $actual_mail_body, 'wp_mail receives the snapshotted plain-text body' );

	$transfer = new Didar_Settings_Transfer( $plugin->registry, $plugin->settings, new Didar_Logger() );
	$portable = $transfer->portable_settings( $settings );
	$export = $transfer->export_json();
	$assert( ! empty( $portable['didar_notification_events']['visa_request.created']['email_enabled'] ) && isset( $portable['didar_notification_events']['visa_request.created']['email_template'] ) && $new_template === $portable['didar_notification_events']['visa_request.created']['email_template'], 'Email configuration is included in portable settings' );
	$assert( ! isset( $portable['melipayamak_api_key'] ) && false === strpos( $export, 'synthetic-secret-never-exported' ), 'portable settings exclude provider credentials' );
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
} finally {
	global $wpdb;
	foreach ( array_unique( array_filter( array_map( 'absint', $created_jobs ) ) ) as $job_id ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Didar_Notification_Queue::table_name() . ' WHERE job_id = %d', $job_id ) );
		$cron = _get_cron_array();
		foreach ( (array) $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ Didar_Notification_Manager::ITEM_HOOK ] ) ) {
				foreach ( $hooks[ Didar_Notification_Manager::ITEM_HOOK ] as $event ) {
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

if ( $failures ) { echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n"; exit( 1 ); }
echo 'PASS ' . $checks . " checks; no network or real email delivery.\n";
