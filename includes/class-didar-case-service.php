<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Didar Case metadata and write abstraction. Case metadata is cached separately from Deal metadata. */
class Didar_Case_Service {
	const PIPELINES_OPTION = 'didar_case_pipeline_cache';
	const FIELDS_OPTION    = 'didar_case_custom_field_cache';
	const CACHE_TTL        = DAY_IN_SECONDS;

	private $settings;
	private $logger;

	public function __construct( Didar_Settings $settings, Didar_Logger $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger ? $logger : new Didar_Logger();
	}

	public function pipelines() { $cache = get_option( self::PIPELINES_OPTION, array() ); return is_array( $cache['pipelines'] ?? null ) ? $cache['pipelines'] : array(); }
	public function pipeline_cache_info() { $cache = get_option( self::PIPELINES_OPTION, array() ); return is_array( $cache ) ? $cache : array(); }
	public function custom_fields() { $cache = get_option( self::FIELDS_OPTION, array() ); return is_array( $cache['fields'] ?? null ) ? $cache['fields'] : array(); }
	public function custom_field_cache_info() { $cache = get_option( self::FIELDS_OPTION, array() ); return is_array( $cache ) ? $cache : array(); }
	public function pipeline( $id ) { foreach ( $this->pipelines() as $pipeline ) { if ( (string) $pipeline['id'] === (string) $id ) return $pipeline; } return array(); }
	public function case_field( $key ) { foreach ( $this->custom_fields() as $field ) { if ( (string) $field['key'] === (string) $key ) return $field; } return array(); }

	public function refresh() {
		$api = new Didar_Api_Client( $this->settings, $this->logger );
		$pipeline_response = $api->case_pipelines();
		if ( is_wp_error( $pipeline_response ) ) { $this->record_error( self::PIPELINES_OPTION, $pipeline_response->get_error_code() ); return $pipeline_response; }
		$pipelines = $this->normalize_pipelines( $pipeline_response );
		if ( ! $pipelines ) { $this->record_error( self::PIPELINES_OPTION, 'case_pipeline_response_empty' ); return new WP_Error( 'case_pipeline_response_empty', 'Didar Case pipeline metadata was empty.' ); }
		update_option( self::PIPELINES_OPTION, array( 'pipelines' => $pipelines, 'refreshed_at_gmt' => current_time( 'mysql', true ), 'last_error' => '' ), false );
		$field_response = $api->custom_fields();
		if ( is_wp_error( $field_response ) ) { $this->record_error( self::FIELDS_OPTION, $field_response->get_error_code() ); return $field_response; }
		$fields = $this->normalize_case_fields( $field_response );
		if ( ! $fields ) { $this->record_error( self::FIELDS_OPTION, 'case_custom_field_response_empty' ); return new WP_Error( 'case_custom_field_response_empty', 'Didar Case Custom Field metadata was empty.' ); }
		update_option( self::FIELDS_OPTION, array( 'fields' => $fields, 'refreshed_at_gmt' => current_time( 'mysql', true ), 'last_error' => '' ), false );
		return array( 'pipelines' => $pipelines, 'fields' => $fields );
	}

	public function save( $case ) { return ( new Didar_Api_Client( $this->settings, $this->logger ) )->save_case( $case ); }
	public function search( $criteria, $from = 0, $limit = 10 ) { return ( new Didar_Api_Client( $this->settings, $this->logger ) )->search_cases( $criteria, $from, $limit ); }

	public function valid_stage( $pipeline_id, $stage_id ) {
		$pipeline = $this->pipeline( $pipeline_id );
		return $pipeline && in_array( (string) $stage_id, wp_list_pluck( $pipeline['stages'], 'id' ), true );
	}

	public static function is_case_field( $field ) { return is_array( $field ) && ! empty( $field['key'] ) && empty( $field['is_deleted'] ) && 'case' === strtolower( (string) ( $field['field_type'] ?? '' ) ); }

	/** Canonical Case configuration check shared by diagnostics and synchronization. */
	public function validate_companion_case_configuration( $config = null ) {
		if ( null === $config ) { $settings = $this->settings->all(); $config = $settings['visa_companion_case_settings'] ?? array(); }
		$config = is_array( $config ) ? $config : array(); $issues = array();
		$pipeline_id = sanitize_text_field( (string) ( $config['pipeline_id'] ?? '' ) ); $stage_id = sanitize_text_field( (string) ( $config['initial_stage_id'] ?? '' ) );
		$pipeline = $pipeline_id ? $this->pipeline( $pipeline_id ) : array();
		if ( ! $pipeline_id ) $issues[] = 'pipeline_missing'; elseif ( ! $pipeline ) $issues[] = 'pipeline_stale';
		if ( ! $stage_id ) $issues[] = 'stage_missing'; elseif ( $pipeline && ! $this->valid_stage( $pipeline_id, $stage_id ) ) $issues[] = 'stage_not_in_pipeline';
		$mapped_keys = array(); foreach ( (array) ( $config['field_mappings'] ?? array() ) as $source => $target ) { $target = sanitize_text_field( (string) $target ); if ( ! $target ) continue; if ( in_array( $target, $mapped_keys, true ) ) $issues[] = 'duplicate_field_mapping'; $mapped_keys[] = $target; if ( ! self::is_case_field( $this->case_field( $target ) ) ) $issues[] = 'case_field_stale'; }
		$system_keys = array(); foreach ( (array) ( $config['system_fields'] ?? array() ) as $purpose => $target ) { $target = sanitize_text_field( (string) $target ); if ( ! $target ) continue; if ( in_array( $target, $system_keys, true ) ) $issues[] = 'duplicate_system_mapping'; $system_keys[] = $target; if ( ! self::is_case_field( $this->case_field( $target ) ) ) $issues[] = 'system_field_stale'; }
		$issues = array_values( array_unique( $issues ) ); $stale_codes = array( 'pipeline_stale', 'stage_not_in_pipeline', 'case_field_stale', 'system_field_stale' ); $status = array_intersect( $stale_codes, $issues ) ? 'stale' : ( $issues ? 'incomplete' : 'ready' ); return array( 'status' => $status, 'ready' => 'ready' === $status, 'issues' => $issues );
	}

	private function normalize_pipelines( $response ) {
		$items = $response['Response'] ?? $response; if ( isset( $items['List'] ) ) $items = $items['List']; $out = array();
		foreach ( (array) $items as $item ) { if ( ! is_array( $item ) || empty( $item['Id'] ) || 'case' !== strtolower( (string) ( $item['Type'] ?? '' ) ) ) continue; $stages = array(); foreach ( (array) ( $item['Stages'] ?? array() ) as $stage ) { if ( is_array( $stage ) && ! empty( $stage['Id'] ) ) $stages[] = array( 'id' => sanitize_text_field( $stage['Id'] ), 'title' => sanitize_text_field( $stage['Title'] ?? $stage['Id'] ), 'index' => absint( $stage['Index'] ?? 0 ) ); } usort( $stages, function( $a, $b ) { return $a['index'] <=> $b['index']; } ); $out[] = array( 'id' => sanitize_text_field( $item['Id'] ), 'title' => sanitize_text_field( $item['Title'] ?? $item['Id'] ), 'type' => 'Case', 'stages' => $stages ); }
		usort( $out, function( $a, $b ) { return strnatcasecmp( $a['title'], $b['title'] ); } ); return $out;
	}

	private function normalize_case_fields( $response ) {
		$items = $response['Response'] ?? $response; if ( isset( $items['List'] ) ) $items = $items['List']; $out = array();
		foreach ( (array) $items as $item ) { if ( ! is_array( $item ) || empty( $item['Key'] ) || empty( $item['Id'] ) ) continue; $field = array( 'id' => sanitize_text_field( $item['Id'] ), 'key' => sanitize_text_field( $item['Key'] ), 'title' => sanitize_text_field( $item['Title'] ?? $item['Key'] ), 'field_type' => sanitize_text_field( $item['FieldType'] ?? '' ), 'control_type' => sanitize_text_field( $item['ControlType'] ?? '' ), 'is_deleted' => ! empty( $item['IsDeleted'] ) ); if ( self::is_case_field( $field ) ) $out[] = $field; }
		usort( $out, function( $a, $b ) { return strnatcasecmp( $a['title'], $b['title'] ); } ); return $out;
	}

	private function record_error( $option, $code ) { $cache = get_option( $option, array() ); $cache = is_array( $cache ) ? $cache : array(); $cache['last_error'] = sanitize_key( $code ); $cache['last_error_at_gmt'] = current_time( 'mysql', true ); update_option( $option, $cache, false ); $this->logger->log( 'ERROR', 'didar_case_metadata_refresh_failed', 'Didar Case metadata refresh failed; existing cache retained.', array( 'option' => $option, 'error_code' => sanitize_key( $code ) ) ); }
}
