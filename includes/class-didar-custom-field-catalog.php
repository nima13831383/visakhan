<?php

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}

/** Safe, cached catalogue of Didar Deal Custom Field metadata. */
class Didar_Custom_Field_Catalog {
	const OPTION_NAME = 'didar_custom_field_cache';

	private $settings;
	private $logger;

	public function __construct( Didar_Settings $settings, Didar_Logger $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger ? $logger : new Didar_Logger();
	}

	public function fields() {
		$cache = $this->cache_info();
		return isset( $cache['fields'] ) && is_array( $cache['fields'] ) ? $cache['fields'] : array();
	}

	public function cache_info() {
		$cache = get_option( self::OPTION_NAME, array() );
		return is_array( $cache ) ? $cache : array();
	}

	/** Refreshes only this cache. Existing valid metadata is retained on error. */
	public function refresh() {
		$this->logger->log( 'INFO', 'didar_custom_fields_refresh_started', 'Didar Custom Field metadata refresh started.', array( 'source' => 'admin' ) );
		$response = ( new Didar_Api_Client( $this->settings, $this->logger ) )->custom_fields();
		if ( is_wp_error( $response ) ) {
			return $this->record_refresh_error( $response->get_error_code(), $response );
		}

		$fields = $this->normalize( $response );
		if ( ! $fields ) {
			return $this->record_refresh_error( 'custom_field_response_empty' );
		}

		$active_deal_count = count( array_filter( $fields, array( __CLASS__, 'is_deal_field' ) ) );
		update_option( self::OPTION_NAME, array(
			'fields'           => $fields,
			'refreshed_at_gmt' => current_time( 'mysql', true ),
			'last_error'       => '',
		), false );
		$this->logger->log( 'INFO', 'didar_custom_fields_refresh_succeeded', 'Didar Custom Field metadata refreshed.', array( 'field_count' => count( $fields ), 'active_deal_field_count' => $active_deal_count, 'source' => 'admin' ) );
		return $fields;
	}

	public function field( $key ) {
		$key = (string) $key;
		foreach ( $this->fields() as $field ) {
			if ( $key === (string) ( $field['key'] ?? '' ) ) {
				return $field;
			}
		}
		return array();
	}

	public static function is_deal_field( $field ) {
		return is_array( $field ) && ! empty( $field['key'] ) && empty( $field['is_deleted'] ) && 'deal' === strtolower( (string) ( $field['field_type'] ?? '' ) );
	}

	/** ExcludedPipeLineIds is an exclusion list: a listed pipeline is unavailable. */
	public static function is_available_for_pipeline( $field, $pipeline_id ) {
		if ( ! self::is_deal_field( $field ) || ! $pipeline_id ) {
			return false;
		}
		return ! in_array( (string) $pipeline_id, (array) ( $field['excluded_pipeline_ids'] ?? array() ), true );
	}

	public function deal_fields_for_pipeline( $pipeline_id ) {
		return array_values( array_filter( $this->fields(), function ( $field ) use ( $pipeline_id ) {
			return self::is_available_for_pipeline( $field, $pipeline_id );
		} ) );
	}

	public function available_pipeline_ids( $field, $pipelines ) {
		$ids = wp_list_pluck( (array) $pipelines, 'id' );
		return array_values( array_filter( $ids, function ( $pipeline_id ) use ( $field ) {
			return self::is_available_for_pipeline( $field, $pipeline_id );
		} ) );
	}

	private function normalize( $response ) {
		$items = isset( $response['Response'] ) && is_array( $response['Response'] ) ? $response['Response'] : $response;
		if ( isset( $items['List'] ) && is_array( $items['List'] ) ) {
			$items = $items['List'];
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['Key'] ) || empty( $item['Id'] ) ) {
				continue;
			}
			$view_options = array();
			foreach ( array( 'ShowInAddDeal', 'ShowInAddContact', 'ShowInSideBar', 'IsRequired' ) as $key ) {
				if ( isset( $item['ViewOptions'][ $key ] ) ) {
					$view_options[ $key ] = (bool) $item['ViewOptions'][ $key ];
				}
			}
			$out[] = array(
				'id'                           => sanitize_text_field( (string) $item['Id'] ),
				'key'                          => sanitize_text_field( (string) $item['Key'] ),
				'title'                        => sanitize_text_field( (string) ( $item['Title'] ?? $item['Key'] ) ),
				'field_type'                   => sanitize_text_field( (string) ( $item['FieldType'] ?? '' ) ),
				'control_type'                 => sanitize_text_field( (string) ( $item['ControlType'] ?? '' ) ),
				'is_deleted'                   => ! empty( $item['IsDeleted'] ),
				'excluded_pipeline_ids'        => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $item['ExcludedPipeLineIds'] ?? array() ) ) ) ) ),
				'required_pipeline_stage_ids'  => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $item['RequiredPipeLineStageIds'] ?? array() ) ) ) ) ),
				'view_options'                 => $view_options,
			);
		}
		usort( $out, function ( $a, $b ) { return strnatcasecmp( $a['title'], $b['title'] ); } );
		return $out;
	}

	private function record_refresh_error( $code, $error = null ) {
		$cache = $this->cache_info();
		$cache['last_error'] = sanitize_key( $code );
		$cache['last_error_at_gmt'] = current_time( 'mysql', true );
		update_option( self::OPTION_NAME, $cache, false );
		$this->logger->log( 'ERROR', 'didar_custom_fields_refresh_failed', 'Didar Custom Field metadata refresh failed; existing cache retained.', array( 'error_code' => sanitize_key( $code ), 'source' => 'admin' ) );
		return is_wp_error( $error ) ? $error : new WP_Error( sanitize_key( $code ), __( 'فهرست فیلدهای دیدار معتبر نیست.', 'didar' ) );
	}
}
