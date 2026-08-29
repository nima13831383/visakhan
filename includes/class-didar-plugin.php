<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Didar_Plugin {
	private static $instance;

	public $registry;
	public $renderer;
	public $validator;
	public $service;
	public $event_log;
	public $logger;
	public $settings;
	public $file_service;
	public $request_search;
	public $sync_manager;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( 'Didar_Post_Type', 'register' ) );
		add_action( 'plugins_loaded', array( 'Didar_Access_Control', 'maybe_upgrade' ), 5 );
		add_action( 'plugins_loaded', array( 'Didar_Schema_Manager', 'maybe_repair' ), 5 );
		add_action( 'didar_backfill_last_updated', array( $this, 'backfill_last_updated' ) );
		add_action( 'admin_notices', array( 'Didar_Schema_Manager', 'render_admin_notice' ) );
		add_action( 'network_admin_notices', array( 'Didar_Schema_Manager', 'render_admin_notice' ) );
		Didar_Access_Control::register_hooks();

		$this->registry  = new Didar_Form_Registry();
		$this->settings  = new Didar_Settings();
		$this->event_log = new Didar_Event_Log();
		$this->logger    = new Didar_Logger();
		Didar_Logger::maybe_upgrade();
		$this->event_log->maybe_schedule_backfill();
		$this->request_search = new Didar_Request_Search();
		$this->file_service = new Didar_File_Service( $this->registry, $this->settings, $this->event_log );
		$this->renderer  = new Didar_Field_Renderer( $this->settings, $this->file_service );
		$profile_mapper = new Didar_Field_Mapper( $this->registry, $this->settings, null, $this->logger );
		$this->renderer->set_profile_resolver( array( $profile_mapper, 'wordpress_user_profile' ) );
		$this->validator = new Didar_Validator( $this->registry, $this->settings, $this->file_service );
		$this->service   = new Didar_Submission_Service( $this->registry, $this->event_log, $this->settings, $this->file_service );
		$this->file_service->set_submission_service( $this->service );
		$this->sync_manager = new Didar_Sync_Manager( $this->registry, $this->settings, $this->event_log, $this->service, $this->file_service, $this->logger );
		// File replacement does not run activation hooks. Keep background workers
		// healthy on every normal bootstrap so pending durable work cannot strand.
		$this->ensure_runtime_workers();

		new Didar_Shortcodes( $this->registry, $this->renderer, $this->validator, $this->service, $this->settings, $this->file_service, $this->request_search );
		new Didar_User_Profile( $this->registry, $this->settings, $this->sync_manager, $this->logger );
		new Didar_Ajax( $this->registry, $this->renderer, $this->service, $this->file_service );

		if ( is_admin() ) {
			new Didar_Admin( $this->registry, $this->renderer, $this->validator, $this->service, $this->settings, $this->file_service, $this->request_search );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'didar', false, dirname( plugin_basename( DIDAR_FILE ) ) . '/languages' );
	}

	public function backfill_last_updated() {
		$this->event_log->backfill_last_updated();
	}

	/** Keep all recurring plugin workers healthy even when activation did not run. */
	private function ensure_runtime_workers() {
		$this->sync_manager->ensure_worker_schedule();
		if ( wp_next_scheduled( 'didar_cleanup_temporary_uploads' ) ) {
			return;
		}
		$result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'didar_cleanup_temporary_uploads', array(), true );
		if ( is_wp_error( $result ) || false === $result ) {
			$this->logger->log( 'ERROR', 'sync_schedule_failed', 'Didar temporary-upload cleanup worker could not be scheduled.', array( 'source' => 'plugin_bootstrap', 'queue_job_id' => 'didar_cleanup_temporary_uploads', 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed', 'error_message' => is_wp_error( $result ) ? $result->get_error_message() : 'wp_schedule_event returned false' ) );
		}
	}

	public static function activate() {
		Didar_Post_Type::register();
		Didar_Access_Control::install_roles_and_capabilities();
		$schema = Didar_Schema_Manager::install_and_verify();
		Didar_Logger::maybe_upgrade();
		if ( is_wp_error( $schema ) ) {
			$message    = $schema->get_error_message();
			$error_data = $schema->get_error_data();
			if ( is_array( $error_data ) && ! empty( $error_data['database_error'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: sanitized database error visible during plugin activation. */
					__( 'جزئیات فنی پایگاه داده: %s', 'didar' ),
					sanitize_text_field( (string) $error_data['database_error'] )
				);
			}

			wp_die(
				esc_html( $message ),
				esc_html__( 'فعال‌سازی دیدار انجام نشد', 'didar' ),
				array(
					'back_link' => true,
					'response'  => 500,
				)
			);
		}

		self::instance()->file_service->sync_storage_protection();

		self::instance()->ensure_runtime_workers();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Didar_Event_Log::BACKFILL_HOOK );
		wp_clear_scheduled_hook( 'didar_cleanup_temporary_uploads' );
		wp_clear_scheduled_hook( Didar_Sync_Manager::CRON_HOOK );
		wp_clear_scheduled_hook( Didar_Sync_Manager::USER_HOOK );
	}
}
