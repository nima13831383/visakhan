<?php
/*
Plugin Name: Didar
Description: مدیریت امن درخواست‌ها و فرم‌های فارسی دیدار.
Version: 1.7.1
Requires at least: 6.4
Requires PHP: 7.4
Author: Didar
Text Domain: didar
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIDAR_VERSION', '1.7.1' );
define( 'DIDAR_FILE', __FILE__ );
define( 'DIDAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIDAR_URL', plugin_dir_url( __FILE__ ) );

require_once DIDAR_PATH . 'includes/class-didar-post-type.php';
require_once DIDAR_PATH . 'includes/class-didar-access-control.php';
require_once DIDAR_PATH . 'includes/class-didar-event-log.php';
require_once DIDAR_PATH . 'includes/class-didar-logger.php';
require_once DIDAR_PATH . 'includes/class-didar-reference-data.php';
require_once DIDAR_PATH . 'includes/class-didar-form-registry.php';
require_once DIDAR_PATH . 'includes/class-didar-settings.php';
require_once DIDAR_PATH . 'includes/class-didar-request-search.php';
require_once DIDAR_PATH . 'includes/class-didar-file-service.php';
require_once DIDAR_PATH . 'includes/class-didar-schema-manager.php';
require_once DIDAR_PATH . 'includes/class-didar-field-renderer.php';
require_once DIDAR_PATH . 'includes/class-didar-validator.php';
require_once DIDAR_PATH . 'includes/class-didar-submission-service.php';
require_once DIDAR_PATH . 'includes/class-didar-api-client.php';
require_once DIDAR_PATH . 'includes/class-didar-custom-field-catalog.php';
require_once DIDAR_PATH . 'includes/class-didar-user-catalog.php';
require_once DIDAR_PATH . 'includes/class-didar-workflow-manager.php';
require_once DIDAR_PATH . 'includes/class-didar-field-mapper.php';
require_once DIDAR_PATH . 'includes/class-didar-sync-manager.php';
require_once DIDAR_PATH . 'includes/class-didar-shortcodes.php';
require_once DIDAR_PATH . 'includes/class-didar-ajax.php';
require_once DIDAR_PATH . 'includes/class-didar-admin.php';
require_once DIDAR_PATH . 'includes/class-didar-plugin.php';

register_activation_hook( __FILE__, array( 'Didar_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Didar_Plugin', 'deactivate' ) );

Didar_Plugin::instance();
