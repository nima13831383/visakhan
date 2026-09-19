<?php

/**
 * No-network smoke test for manager-only notification subscriptions.
 *
 * Run with:
 * C:\xampp\php\php.exe tests\smoke-notification-manager-subscriptions.php
 */

define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

if ( ! class_exists( 'Didar_Plugin' ) ) { throw new RuntimeException( 'ns-didar did not load.' ); }

$failures = array();
$checks = 0;
$created_posts = array();
$created_jobs = array();
$settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
$backup_snapshot = get_option( Didar_Settings_Transfer::BACKUPS_OPTION, array() );
$meta_snapshot = array();
$user_id = 0;
$owner_id = 0;
$receive_cap_was_present = false;
$manage_cap_was_present = false;
$assert = function ( $condition, $label ) use ( &$checks, &$failures ) { $checks++; if ( ! $condition ) { $failures[] = $label; } };

try {
	$plugin = Didar_Plugin::instance();
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	$user_id = absint( $admin_ids[0] ?? 0 );
	if ( ! $user_id ) { throw new RuntimeException( 'No local administrator available.' ); }
	$user = get_user_by( 'id', $user_id );
	$receive_cap_was_present = $user->has_cap( 'didar_receive_requests' );
	$manage_cap_was_present = $user->has_cap( 'didar_manage_settings' );
	if ( ! $receive_cap_was_present ) { $user->add_cap( 'didar_receive_requests' ); }
	if ( ! $manage_cap_was_present ) { $user->add_cap( 'didar_manage_settings' ); }
	$meta_snapshot['digits_phone'] = get_user_meta( $user_id, 'digits_phone', true );
	update_user_meta( $user_id, 'digits_phone', '09120000000' );
	wp_set_current_user( $user_id );
	$owner_id = wp_insert_user( array( 'user_login' => 'notification_owner_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'notification-owner-' . wp_rand( 1000, 9999 ) . '@example.test', 'role' => 'subscriber' ) );
	if ( is_wp_error( $owner_id ) ) { throw new RuntimeException( 'Synthetic request owner creation failed.' ); }
	$owner = get_user_by( 'id', $owner_id );
	$owner->add_role( 'editor' );
	update_user_meta( $owner_id, 'digits_phone', '09121111111' );

	$definitions = Didar_Notification_Event_Registry::all();
	$manager_keys = array( 'embassy_appointment.status_changed.managers', 'visa_request.status_changed.managers', 'embassy_appointment.created.managers', 'visa_request.created.managers' );
	$assert( 10 === count( $definitions ), 'ten notification subscriptions are registered' );
	$assert( $manager_keys === array_values( array_intersect( $manager_keys, array_keys( $definitions ) ) ), 'all four manager subscription keys are stable and present' );
	$assert( 'ایجاد درخواست وقت سفارت — مدیران' === $definitions['embassy_appointment.created.managers']['label'] && 'ایجاد درخواست ویزا — مدیران' === $definitions['visa_request.created.managers']['label'], 'created manager labels are canonical' );
	$assert( 'تغییر وضعیت درخواست وقت سفارت — مدیران' === $definitions['embassy_appointment.status_changed.managers']['label'] && 'تغییر وضعیت درخواست ویزا — مدیران' === $definitions['visa_request.status_changed.managers']['label'], 'status manager labels are canonical' );
	$assert( 'visa_request.created' === $definitions['visa_request.created.managers']['source_event'] && 'visa_request.status_changed' === $definitions['visa_request.status_changed.managers']['source_event'], 'Visa manager rows point to canonical source events' );
	$assert( 'embassy_appointment.created' === $definitions['embassy_appointment.created.managers']['source_event'] && 'embassy_appointment.status_changed' === $definitions['embassy_appointment.status_changed.managers']['source_event'], 'Embassy manager rows point to canonical source events' );
	$defaults = Didar_Notification_Event_Registry::default_configuration();
	$assert( 0 === count( array_filter( $defaults, function ( $item ) { return ! empty( $item['sms_enabled'] ) || ! empty( $item['email_enabled'] ); } ) ), 'manager subscriptions and normal subscriptions are disabled by default' );
	$assert( in_array( 'customer_phone', array_keys( Didar_Notification_Event_Registry::variables() ), true ) && in_array( 'user_role', array_keys( Didar_Notification_Event_Registry::variables() ), true ) && in_array( 'request_creator_phone', array_keys( Didar_Notification_Event_Registry::variables() ), true ), 'owner, role, and creator-phone variables are in the central whitelist' );

	$configuration = $defaults;
	$configuration['visa_request.created'] = array( 'sms_enabled' => 1, 'user_ids' => array( $user_id ), 'body_id' => '9001', 'variables' => array( 'request_number' ) );
	$configuration['visa_request.created.managers'] = array( 'sms_enabled' => 1, 'email_enabled' => 1, 'user_ids' => array( $user_id ), 'send_to_owner' => 1, 'send_to_assignee' => 1, 'body_id' => '9101', 'email_template' => 'مدیر {0} / {1}', 'variables' => array( 'form_type', 'request_number', 'customer_phone', 'user_role', 'request_creator_phone' ) );
	$configuration['visa_request.status_changed.managers'] = array( 'sms_enabled' => 1, 'user_ids' => array( $user_id ), 'body_id' => '9102', 'variables' => array( 'request_status' ) );
	$configuration['embassy_appointment.created.managers'] = array( 'sms_enabled' => 1, 'user_ids' => array( $user_id ), 'body_id' => '9103', 'variables' => array( 'request_number' ) );
	$configuration['embassy_appointment.status_changed.managers'] = array( 'sms_enabled' => 1, 'user_ids' => array( $user_id ), 'body_id' => '9104', 'variables' => array( 'request_status' ) );
	$normalized = Didar_Notification_Event_Registry::normalize_configuration( $configuration );
	$assert( ! $normalized['visa_request.created.managers']['send_to_owner'] && ! $normalized['visa_request.created.managers']['send_to_assignee'], 'manager normalization disables owner and assignee delivery controls' );
	$assert( 1 === count( $normalized['visa_request.created.managers']['user_ids'] ) && '9101' === $normalized['visa_request.created.managers']['body_id'], 'manager recipients and Body ID normalize independently' );
	$settings = $settings_snapshot;
	$settings['didar_notification_events'] = $configuration;
	update_option( Didar_Settings::OPTION_NAME, $settings, false );
	$admin = new Didar_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	$settings_after_save = $admin->sanitize_didar_settings( array( '_active_tab' => 'notifications', 'didar_notification_events' => $configuration ) );
	update_option( Didar_Settings::OPTION_NAME, $settings_after_save, false );
	$settings_after_reload = ( new Didar_Settings() )->all();
	$assert( array( 'form_type', 'request_number', 'customer_phone', 'user_role', 'request_creator_phone' ) === ( $settings_after_reload['didar_notification_events']['visa_request.created.managers']['variables'] ?? array() ), 'owner, role, and creator-phone ordered mapping survives Settings save and reload' );

	$visa_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $owner_id, 'post_title' => 'Manager subscription Visa smoke' ), true );
	if ( is_wp_error( $visa_post ) ) { throw new RuntimeException( 'Visa synthetic request creation failed.' ); }
	$created_posts[] = $visa_post;
	update_post_meta( $visa_post, '_didar_form_type', 'visa_request' );
	update_post_meta( $visa_post, '_didar_fields', array( 'first_name' => 'مدیر', 'last_name' => 'آزمون', 'mobile' => '09999999999' ) );
	update_post_meta( $visa_post, '_didar_created_by_user_id', $user_id );
	update_post_meta( $visa_post, '_didar_internal_status', 'pending_review' );
	update_post_meta( $visa_post, '_didar_assigned_user_id', $user_id );
	wp_set_current_user( $owner_id );
	$visa_jobs = $plugin->notification_manager->emit( 'visa_request.created', $visa_post, 'manager-visa-created-1' );
	$visa_manager_jobs = array_values( array_filter( $visa_jobs, function ( $job ) { return 'visa_request.created.managers' === ( $job['event_key'] ?? '' ); } ) );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $visa_jobs, 'job_id' ) );
	$manager_sms_job = current( array_filter( $visa_manager_jobs, function ( $job ) { return 'sms' === ( $job['channel'] ?? '' ); } ) );
	$manager_email_job = current( array_filter( $visa_manager_jobs, function ( $job ) { return 'email' === ( $job['channel'] ?? '' ); } ) );
	$manager_job = is_array( $manager_sms_job ) ? $manager_sms_job : array();
	$assert( 3 === count( $visa_jobs ) && 2 === count( $visa_manager_jobs ), 'canonical Visa created event fans out to normal and manager subscriptions' );
	$assert( 'staff' === ( $manager_job['recipient_role'] ?? '' ) && '9101' === (string) ( $manager_job['body_id'] ?? '' ), 'manager job uses the explicit staff recipient and its own Body ID' );
	$expected_owner_roles = $owner->roles;
	sort( $expected_owner_roles, SORT_STRING );
	$role_names = wp_roles()->get_names();
	$expected_role_labels = array();
	foreach ( $expected_owner_roles as $role_key ) { if ( isset( $role_names[ $role_key ] ) ) { $expected_role_labels[] = wp_strip_all_tags( translate_user_role( $role_names[ $role_key ] ) ); } }
	$expected_role_labels = implode( '، ', $expected_role_labels );
	$assert( array( 'form_type', 'request_number', 'customer_phone', 'user_role', 'request_creator_phone' ) === ( $manager_job['variable_mapping'] ?? array() ) && array( 'درخواست ویزا', (string) $visa_post, '09121111111', $expected_role_labels, '09120000000' ) === ( $manager_job['variable_values'] ?? array() ), 'manager ordered variables use owner and creator Digits values, not form phone data' );
	$assert( 'visa_request.created' === ( $manager_job['snapshot']['source_event_key'] ?? '' ) && 'visa_request.created.managers' === ( $manager_job['snapshot']['subscription_key'] ?? '' ), 'manager snapshot retains canonical source and subscription identity' );
	$assert( ! in_array( $manager_job['recipient_role'] ?? '', array( 'owner', 'assignee' ), true ), 'manager job never uses owner or assignee recipient roles' );
	$assert( 'اعلان درخواست #' . $visa_post . ': ایجاد درخواست ویزا — مدیران' === ( $manager_email_job['snapshot']['subject'] ?? '' ), 'manager Email subject uses the canonical request number and manager label' );
	$assert( (string) $manager_job['idempotency_key'] !== (string) ( $visa_jobs[0]['idempotency_key'] ?? '' ), 'normal and manager subscriptions have distinct idempotency identities' );
	delete_user_meta( $owner_id, 'digits_phone' );
	$missing_mobile_jobs = $plugin->notification_manager->emit( 'visa_request.created', $visa_post, 'manager-missing-owner-mobile-1' );
	$missing_mobile_job = current( array_filter( $missing_mobile_jobs, function ( $job ) { return 'visa_request.created.managers' === ( $job['event_key'] ?? '' ) && 'sms' === ( $job['channel'] ?? '' ); } ) );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $missing_mobile_jobs, 'job_id' ) );
	$assert( is_array( $missing_mobile_job ) && '' === ( $missing_mobile_job['variable_values'][2] ?? 'x' ) && '09120000000' === ( $missing_mobile_job['variable_values'][4] ?? '' ), 'missing owner Digits mobile fails safely without changing creator-phone resolution' );
	update_user_meta( $owner_id, 'digits_phone', '09121111111' );
	update_post_meta( $visa_post, '_didar_created_by_user_id', 999999999 );
	$missing_creator_jobs = $plugin->notification_manager->emit( 'visa_request.created', $visa_post, 'manager-missing-creator-1' );
	$missing_creator_job = current( array_filter( $missing_creator_jobs, function ( $job ) { return 'visa_request.created.managers' === ( $job['event_key'] ?? '' ) && 'sms' === ( $job['channel'] ?? '' ); } ) );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $missing_creator_jobs, 'job_id' ) );
	$assert( is_array( $missing_creator_job ) && '09121111111' === ( $missing_creator_job['variable_values'][2] ?? '' ) && '' === ( $missing_creator_job['variable_values'][4] ?? 'x' ), 'missing creator identity fails safely without changing customer-phone resolution' );
	update_post_meta( $visa_post, '_didar_created_by_user_id', $user_id );
	$repeat = $plugin->notification_manager->emit( 'visa_request.created', $visa_post, 'manager-visa-created-1' );
	$repeat_manager_jobs = array_values( array_filter( $repeat, function ( $job ) { return 'visa_request.created.managers' === ( $job['event_key'] ?? '' ); } ) );
	$assert( 3 === count( $repeat ) && 2 === count( $repeat_manager_jobs ) && (int) $repeat_manager_jobs[0]['job_id'] === (int) $manager_job['job_id'], 'repeated manager subscription delivery is idempotent' );

	$visa_status_jobs = $plugin->notification_manager->emit( 'visa_request.status_changed', $visa_post, 'manager-visa-status-1' );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $visa_status_jobs, 'job_id' ) );
	$assert( 1 === count( $visa_status_jobs ) && 'visa_request.status_changed.managers' === ( $visa_status_jobs[0]['event_key'] ?? '' ) && '9102' === (string) $visa_status_jobs[0]['body_id'], 'canonical Visa status event reaches the manager subscription only when configured' );

	$embassy_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'Manager subscription Embassy smoke' ), true );
	if ( is_wp_error( $embassy_post ) ) { throw new RuntimeException( 'Embassy synthetic request creation failed.' ); }
	$created_posts[] = $embassy_post;
	update_post_meta( $embassy_post, '_didar_form_type', 'embassy_appointment' );
	update_post_meta( $embassy_post, '_didar_fields', array( 'first_name' => 'سفارت', 'last_name' => 'آزمون' ) );
	update_post_meta( $embassy_post, '_didar_internal_status', 'pending_review' );
	$embassy_created_jobs = $plugin->notification_manager->emit( 'embassy_appointment.created', $embassy_post, 'manager-embassy-created-1' );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $embassy_created_jobs, 'job_id' ) );
	$assert( 1 === count( $embassy_created_jobs ) && 'embassy_appointment.created.managers' === ( $embassy_created_jobs[0]['event_key'] ?? '' ) && '9103' === (string) $embassy_created_jobs[0]['body_id'], 'canonical Embassy created event reaches its manager subscription' );
	$embassy_status_jobs = $plugin->notification_manager->emit( 'embassy_appointment.status_changed', $embassy_post, 'manager-embassy-status-1' );
	$created_jobs = array_merge( $created_jobs, wp_list_pluck( $embassy_status_jobs, 'job_id' ) );
	$assert( 1 === count( $embassy_status_jobs ) && 'embassy_appointment.status_changed.managers' === ( $embassy_status_jobs[0]['event_key'] ?? '' ) && '9104' === (string) $embassy_status_jobs[0]['body_id'], 'canonical Embassy status event reaches its manager subscription' );
	$assert( false === strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-didar-notification-manager.php' ), 'public_status' ), 'legacy Public Status does not add a manager notification hook' );

	$transfer = new Didar_Settings_Transfer( $plugin->registry, new Didar_Settings(), new Didar_Logger() );
	$portable = $transfer->portable_settings( $settings );
	$assert( '9101' === $portable['didar_notification_events']['visa_request.created.managers']['body_id'] && array( 'form_type', 'request_number', 'customer_phone', 'user_role', 'request_creator_phone' ) === $portable['didar_notification_events']['visa_request.created.managers']['variables'], 'portable export includes manager Body ID and ordered mapping' );
	$payload = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => Didar_Settings_Transfer::SCHEMA_VERSION, 'settings' => array( 'didar_notification_events' => $portable['didar_notification_events'] ) );
	$preview = $transfer->preview( $payload, 'merge' );
	$assert( ! is_wp_error( $preview ) && '9104' === $preview['incoming']['didar_notification_events']['embassy_appointment.status_changed.managers']['body_id'], 'settings import normalizes manager subscriptions from an exported payload' );
	$old_settings = get_option( Didar_Settings::OPTION_NAME, array() );
	$applied = $transfer->apply( $preview );
	$reloaded = ( new Didar_Settings() )->all();
	$assert( ! is_wp_error( $applied ) && '9102' === $reloaded['didar_notification_events']['visa_request.status_changed.managers']['body_id'] && ! $reloaded['didar_notification_events']['visa_request.status_changed.managers']['send_to_owner'], 'settings import restores manager rows without enabling owner delivery' );
	update_option( Didar_Settings::OPTION_NAME, $old_settings, false );

	ob_start();
	$admin->render_notification_settings_field();
	$html = ob_get_clean();
	$assert( substr_count( $html, 'مدیران' ) >= 4 && substr_count( $html, 'data-event-key="' ) === 10, 'Settings UI renders all ten subscriptions including four manager rows' );
	$assert( false === strpos( $html, 'didar_notification_events][visa_request.created.managers][send_to_owner]' ) && false === strpos( $html, 'didar_notification_events][embassy_appointment.status_changed.managers][send_to_assignee]' ), 'manager rows do not render owner or assignee controls' );

	$missing_owner_post = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => 0, 'post_title' => 'Manager missing identity smoke' ), true );
	if ( ! is_wp_error( $missing_owner_post ) ) {
		$created_posts[] = $missing_owner_post;
		update_post_meta( $missing_owner_post, '_didar_form_type', 'visa_request' );
		update_post_meta( $missing_owner_post, '_didar_created_by_user_id', 0 );
		$missing_jobs = $plugin->notification_manager->emit( 'visa_request.created', $missing_owner_post, 'manager-missing-identity-1' );
		$missing_manager = current( array_filter( $missing_jobs, function ( $job ) { return 'visa_request.created.managers' === ( $job['event_key'] ?? '' ) && 'sms' === ( $job['channel'] ?? '' ); } ) );
		$created_jobs = array_merge( $created_jobs, wp_list_pluck( $missing_jobs, 'job_id' ) );
		$assert( is_array( $missing_manager ) && array( 'درخواست ویزا', (string) $missing_owner_post, '', '', '' ) === ( $missing_manager['variable_values'] ?? array() ), 'missing owner, role, and Digits mobiles fail safely with empty snapshot values' );
	}
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
} finally {
	global $wpdb;
	foreach ( array_unique( array_filter( array_map( 'absint', $created_jobs ) ) ) as $job_id ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Didar_Notification_Queue::table_name() . ' WHERE job_id = %d', $job_id ) );
	}
	foreach ( array_unique( array_filter( array_map( 'absint', $created_posts ) ) ) as $post_id ) { wp_delete_post( $post_id, true ); }
	if ( $owner_id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $owner_id ); }
	if ( $user_id ) {
		foreach ( $meta_snapshot as $key => $value ) { if ( '' === $value || null === $value ) { delete_user_meta( $user_id, $key ); } else { update_user_meta( $user_id, $key, $value ); } }
		$user = get_user_by( 'id', $user_id );
		if ( $user && ! $receive_cap_was_present ) { $user->remove_cap( 'didar_receive_requests' ); }
		if ( $user && ! $manage_cap_was_present ) { $user->remove_cap( 'didar_manage_settings' ); }
	}
	update_option( Didar_Settings::OPTION_NAME, $settings_snapshot, false );
	update_option( Didar_Settings_Transfer::BACKUPS_OPTION, $backup_snapshot, false );
}

if ( $failures ) {
	echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n";
	exit( 1 );
}
echo 'PASS ' . $checks . " checks; no network, SMS, Email, Didar, or CRM mutations.\n";
