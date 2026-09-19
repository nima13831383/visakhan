<?php

/** No-network diagnostics/rendering smoke test for Notification operations. */
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/visa/wp-admin/edit.php?post_type=didar_submission&page=didar-diagnostics&didar_sms_tab=sms';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$failures = array();
$checks = 0;
$assert = function ( $condition, $label ) use ( &$failures, &$checks ) { $checks++; if ( ! $condition ) { $failures[] = $label; } };

try {
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( ! $admin_ids ) { throw new RuntimeException( 'No local administrator is available.' ); }
	wp_set_current_user( absint( $admin_ids[0] ) );
	$user = wp_get_current_user();
	if ( ! $user->has_cap( 'didar_manage_settings' ) ) { $user->add_cap( 'didar_manage_settings' ); }
	$plugin = Didar_Plugin::instance();
	$admin = new Didar_Admin( $plugin->registry, $plugin->renderer, $plugin->validator, $plugin->service, $plugin->settings, $plugin->file_service, $plugin->request_search );
	ob_start();
	$admin->render_settings_page();
	$settings_html = ob_get_clean();
	$assert( false !== strpos( $settings_html, 'اعلان‌ها' ), 'Settings navigation includes Notification tab' );

	$_GET['didar_sms_tab'] = 'sms';
	ob_start();
	$admin->render_diagnostics_page();
	$diagnostics_html = ob_get_clean();
	$assert( false !== strpos( $diagnostics_html, 'گزارش / تاریخچه ارسال اعلان' ), 'Notification diagnostics has separate History section' );
	$assert( false !== strpos( $diagnostics_html, 'صف فعال اعلان‌ها' ), 'Notification diagnostics has separate Active Queue section' );
	$assert( false !== strpos( $diagnostics_html, 'didar_purge_notification_queue' ), 'Notification purge action is present' );
	$assert( false === strpos( $diagnostics_html, 'name="melipayamak_username"' ), 'Notification configuration form is absent from Diagnostics' );
	$assert( false !== strpos( $diagnostics_html, 'didar_notification_channel' ), 'Notification diagnostics has channel filter' );
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-didar-admin.php' );
	$assert( false !== strpos( $source, 'didar_run_notification_job' ) && false !== strpos( $source, 'didar_retry_notification_job' ) && false !== strpos( $source, 'didar_discard_notification_job' ), 'Run Now, Retry, and Discard remain available in queue renderer' );
	$assert( false !== strpos( $source, "'POST' !== strtoupper" ) && false !== strpos( $source, "check_admin_referer( 'didar_purge_notification_queue' )" ) && false !== strpos( $source, "current_user_can( 'didar_manage_settings' )" ), 'Notification purge is POST/capability/nonce protected' );
	$assert( false !== strpos( $source, "'didar_notification_events'" ) && false !== strpos( $source, "'notifications' === \$active_tab" ), 'Settings API sanitizer owns Notification persistence' );
} catch ( Throwable $e ) {
	$failures[] = 'exception: ' . $e->getMessage();
}

if ( $failures ) { echo 'FAIL ' . count( $failures ) . ' of ' . $checks . " checks\n" . implode( "\n", $failures ) . "\n"; exit( 1 ); }
echo 'PASS ' . $checks . " checks; no network, SMS, Email, Didar, or CRM mutations.\n";
