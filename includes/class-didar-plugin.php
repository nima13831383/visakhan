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

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'Didar_Post_Type', 'register' ) );

		$this->registry  = new Didar_Form_Registry();
		$this->renderer  = new Didar_Field_Renderer();
		$this->validator = new Didar_Validator( $this->registry );
		$this->service   = new Didar_Submission_Service( $this->registry );

		new Didar_Shortcodes( $this->registry, $this->renderer, $this->validator, $this->service );
		new Didar_Ajax( $this->registry, $this->renderer );

		if ( is_admin() ) {
			new Didar_Admin( $this->registry, $this->renderer, $this->validator, $this->service );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'didar', false, dirname( plugin_basename( DIDAR_FILE ) ) . '/languages' );
	}

	public static function activate() {
		Didar_Post_Type::register();
		Didar_Post_Type::add_administrator_capabilities();

		if ( ! wp_next_scheduled( 'didar_cleanup_temporary_uploads' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'didar_cleanup_temporary_uploads' );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'didar_cleanup_temporary_uploads' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'didar_cleanup_temporary_uploads' );
		}
	}
}
