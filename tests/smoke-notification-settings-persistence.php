<?php

/** No-network Save/Reload/Edit smoke test for the canonical Notification Settings tab. */
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/visa/wp-admin/edit.php?page=didar-page-settings&tab=notifications';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
$failures = array();
$checks = 0;
$assert = function ( $condition, $label ) use ( &$failures, &$checks ) { $checks++; if ( ! $condition ) { $failures[] = $label; } };

try {
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( ! $admin_ids ) { throw new RuntimeException( 'No local administrator is available.' ); }
	$smoke_admin_id = absint( $admin_ids[0] );
	wp_set_current_user( $smoke_admin_id );
	$user = wp_get_current_user();
	if ( ! $user->has_cap( 'didar_manage_settings' ) ) { $user->add_cap( 'didar_manage_settings' ); }
	$plugin = Didar_Plugin::instance();
	$admin = new Didar_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	$event = 'visa_request.created';
	$disabled_event = 'embassy_appointment.created';
	$first = array(
		'_active_tab' => 'notifications',
		'melipayamak_username' => 'first-user',
		'melipayamak_api_key' => 'secret-one',
		'didar_notification_events' => array( $event => array( 'sms_enabled' => '1', 'email_enabled' => '1', 'user_ids' => array( $smoke_admin_id, 987654 ), 'send_to_owner' => '1', 'send_to_assignee' => '1', 'body_id' => '101', 'email_template' => "خط اول {0}\nخط دوم {1}", 'variables' => array( 'request_number', 'request_status' ) ), $disabled_event => array( 'body_id' => '303', 'email_template' => 'خاموش {0}', 'variables' => array( 'request_number' ) ) ),
	);
	$current = $snapshot;
	$current['didar_debug_logging'] = 'verbose';
	$current['didar_form_access'] = array( 'visa_request' => array( 'url' => 'https://example.test/form', 'barcode' => '' ) );
	update_option( Didar_Settings::OPTION_NAME, $current, false );
	$sanitized = $admin->sanitize_didar_settings( $first );
	update_option( Didar_Settings::OPTION_NAME, $sanitized, false );
	$reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'first-user' === ( $reload['melipayamak_username'] ?? '' ), 'initial username Save/Reload persists' );
	$assert( 'secret-one' === ( $reload['melipayamak_api_key'] ?? '' ), 'initial API key Save/Reload persists' );
	$assert( ! empty( $reload['didar_notification_events'][ $event ]['sms_enabled'] ) && ! empty( $reload['didar_notification_events'][ $event ]['email_enabled'] ), 'SMS and Email persist independently' );
	$assert( 101 === (int) ( $reload['didar_notification_events'][ $event ]['body_id'] ?? 0 ), 'Body ID persists' );
	$assert( array( $smoke_admin_id, 987654 ) === $reload['didar_notification_events'][ $event ]['user_ids'], 'explicit recipient selection persists' );
	$assert( array( 'request_number', 'request_status' ) === $reload['didar_notification_events'][ $event ]['variables'], 'ordered variable mapping persists' );
	$assert( "خط اول {0}\nخط دوم {1}" === $reload['didar_notification_events'][ $event ]['email_template'], 'multiline Email template persists' );
	$assert( 303 === (int) ( $reload['didar_notification_events'][ $disabled_event ]['body_id'] ?? 0 ) && empty( $reload['didar_notification_events'][ $disabled_event ]['sms_enabled'] ) && empty( $reload['didar_notification_events'][ $disabled_event ]['email_enabled'] ), 'disabled event preserves its saved configuration' );
	ob_start();
	$admin->render_notification_settings_field();
	$html = ob_get_clean();
	$assert( false === strpos( $html, 'secret-one' ), 'API key is never rendered in plaintext' );
	$assert( false !== strpos( $html, 'name="didar_settings[melipayamak_api_key]"' ), 'protected API key input is rendered' );
	$_GET['tab'] = 'notifications';
	$admin->register_settings();
	ob_start();
	$admin->render_settings_page();
	$settings_page_html = ob_get_clean();
	$assert( false !== strpos( $settings_page_html, 'اعلان‌ها' ) && false !== strpos( $settings_page_html, 'name="didar_settings[melipayamak_username]"' ), 'Notification configuration renders inside the main Settings tab' );

	$second = $first;
	$second['melipayamak_username'] = 'second-user';
	$second['melipayamak_api_key'] = '';
	$second['didar_notification_events'][ $event ]['body_id'] = '202';
	$second['didar_notification_events'][ $event ]['user_ids'] = array( $smoke_admin_id );
	$second['didar_notification_events'][ $event ]['variables'] = array( 'request_status', 'request_number' );
	$second['didar_notification_events'][ $event ]['send_to_owner'] = array();
	$second['didar_notification_events'][ $event ]['send_to_assignee'] = '1';
	$sanitized = $admin->sanitize_didar_settings( $second );
	update_option( Didar_Settings::OPTION_NAME, $sanitized, false );
	$reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'second-user' === ( $reload['melipayamak_username'] ?? '' ), 'edited username persists' );
	$assert( 'secret-one' === ( $reload['melipayamak_api_key'] ?? '' ), 'blank protected key preserves existing key' );
	$assert( 202 === (int) ( $reload['didar_notification_events'][ $event ]['body_id'] ?? 0 ), 'edited Body ID persists' );
	$assert( array( $smoke_admin_id ) === $reload['didar_notification_events'][ $event ]['user_ids'], 'recipient removal preserves remaining selection' );
	$assert( array( 'request_status', 'request_number' ) === $reload['didar_notification_events'][ $event ]['variables'], 'reordered variable mapping persists' );
	$assert( empty( $reload['didar_notification_events'][ $event ]['send_to_owner'] ), 'owner toggle can be cleared explicitly' );
	$assert( ! empty( $reload['didar_notification_events'][ $event ]['send_to_assignee'] ), 'assignee toggle persists independently' );

	$third = $second;
	$third['melipayamak_api_key'] = 'secret-two';
	$sanitized = $admin->sanitize_didar_settings( $third );
	update_option( Didar_Settings::OPTION_NAME, $sanitized, false );
	$reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'secret-two' === ( $reload['melipayamak_api_key'] ?? '' ), 'replacement API key persists' );
	$assert( 'verbose' === ( $reload['didar_debug_logging'] ?? '' ), 'Notification save preserves unrelated General settings' );
	$assert( 'https://example.test/form' === ( $reload['didar_form_access']['visa_request']['url'] ?? '' ), 'Notification save preserves unrelated Forms settings' );

	$general = $admin->sanitize_didar_settings( array( '_active_tab' => 'general', 'didar_debug_logging' => 'off', 'frontend_requests_per_page' => '20', 'file_download_mode' => 'secure' ) );
	$assert( 'secret-two' === ( $general['melipayamak_api_key'] ?? '' ), 'General sanitizer preserves Notification API key before reload' );
	update_option( Didar_Settings::OPTION_NAME, $general, false );
	$reload = get_option( Didar_Settings::OPTION_NAME, array() );
	$assert( 'secret-two' === ( $reload['melipayamak_api_key'] ?? '' ), 'unrelated tab save preserves Notification API key' );
	$assert( 202 === (int) ( $reload['didar_notification_events'][ $event ]['body_id'] ?? 0 ), 'unrelated tab save preserves Notification configuration' );

	$transfer = new Didar_Settings_Transfer( $plugin->registry, new Didar_Settings(), new Didar_Logger() );
	$portable = $transfer->portable_settings( $reload );
	$assert( isset( $portable['didar_notification_events'][ $event ] ), 'portable export includes Notification configuration' );
	$assert( ! isset( $portable['melipayamak_api_key'] ) && ! isset( $portable['melipayamak_username'] ), 'portable export excludes provider credentials' );
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
} finally {
	update_option( Didar_Settings::OPTION_NAME, $snapshot, false );
}

if ( $failures ) { echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n"; exit( 1 ); }
echo 'PASS ' . $checks . " checks; no network, SMS, Email, Didar, or CRM mutations.\n";
