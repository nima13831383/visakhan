<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves and validates the form-specific WordPress ↔ Didar Deal workflow. */
class Didar_Workflow_Manager {
	const PIPELINES_OPTION = 'didar_deal_pipeline_cache';
	private $registry;
	private $settings;
	private $logger;
	private $custom_fields;
	private $users;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->custom_fields = new Didar_Custom_Field_Catalog( $settings, $this->logger );
		$this->users = new Didar_User_Catalog( $settings, $this->logger );
	}

	public function pipelines() {
		$cache = get_option( self::PIPELINES_OPTION, array() );
		return is_array( $cache ) && isset( $cache['pipelines'] ) && is_array( $cache['pipelines'] ) ? $cache['pipelines'] : array();
	}

	public function cache_info() {
		$cache = get_option( self::PIPELINES_OPTION, array() );
		return is_array( $cache ) ? $cache : array();
	}

	public function custom_fields() { return $this->custom_fields->fields(); }
	public function custom_field_cache_info() { return $this->custom_fields->cache_info(); }
	public function custom_field( $key ) { return $this->custom_fields->field( $key ); }
	public function deal_fields_for_pipeline( $pipeline_id ) { return $this->custom_fields->deal_fields_for_pipeline( $pipeline_id ); }
	public function custom_field_available_for_pipeline( $field, $pipeline_id ) { return Didar_Custom_Field_Catalog::is_available_for_pipeline( $field, $pipeline_id ); }
	public function custom_field_available_pipeline_ids( $field ) { return $this->custom_fields->available_pipeline_ids( $field, $this->pipelines() ); }
	public function didar_users() { return $this->users->users(); }
	public function didar_user_cache_info() { return $this->users->cache_info(); }
	public function didar_user_by_user_id( $user_id ) { return $this->users->user_by_user_id( $user_id ); }
	public function didar_user_by_id( $id ) { return $this->users->user_by_id( $id ); }

	public function refresh() {
		$this->logger->log( 'INFO', 'didar_custom_fields_refresh_started', 'Didar metadata refresh started.', array( 'source' => 'admin' ) );
		$api = new Didar_Api_Client( $this->settings, $this->logger );
		$response = $api->pipelines();
		$pipeline_error = null;
		$pipelines = array();
		if ( is_wp_error( $response ) ) {
			$this->record_refresh_error( $response->get_error_code() );
			$pipeline_error = $response;
		} else {
			$pipelines = $this->normalize_pipelines( $response );
		}
		if ( ! $pipeline_error && ! $pipelines ) {
			$this->record_refresh_error( 'pipeline_response_empty' );
			$pipeline_error = new WP_Error( 'pipeline_response_empty', __( 'فهرست کاریزهای معامله دیدار معتبر نیست.', 'didar' ) );
		}
		if ( ! $pipeline_error ) { $stage_count = 0; foreach ( $pipelines as $pipeline ) { $stage_count += count( $pipeline['stages'] ); } update_option( self::PIPELINES_OPTION, array( 'pipelines' => $pipelines, 'refreshed_at_gmt' => current_time( 'mysql', true ), 'last_error' => '' ), false ); $this->logger->log( 'INFO', 'pipeline_metadata_refresh', 'Didar Deal pipeline metadata refreshed.', array( 'pipeline_count' => count( $pipelines ), 'stage_count' => $stage_count, 'source' => 'admin' ) ); }
		$fields = $this->custom_fields->refresh();
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$users = $this->users->refresh();
		if ( is_wp_error( $users ) ) { return $users; }
		if ( $pipeline_error ) { return $pipeline_error; }
		$this->logger->log( 'INFO', 'didar_custom_fields_refresh_succeeded', 'Didar metadata refresh succeeded.', array( 'pipeline_count' => count( $pipelines ), 'custom_field_count' => count( $fields ), 'active_deal_field_count' => count( array_filter( $fields, array( 'Didar_Custom_Field_Catalog', 'is_deal_field' ) ) ), 'user_count' => count( $users ), 'source' => 'admin' ) );
		return $pipelines;
	}

	public function workflow( $form_type, $allow_legacy = true ) {
		$form_type = sanitize_key( (string) $form_type );
		$all = $this->settings->all();
		if ( isset( $all['didar_form_workflows'][ $form_type ] ) && is_array( $all['didar_form_workflows'][ $form_type ] ) ) {
			return $this->sanitize_workflow( $all['didar_form_workflows'][ $form_type ] );
		}
		if ( ! $allow_legacy ) { return array(); }
		$legacy = array( 'pipeline_id' => sanitize_text_field( (string) ( $all['didar_default_pipeline_id'] ?? '' ) ), 'statuses' => array(), 'legacy' => true );
		foreach ( (array) ( $all['didar_status_pipeline_stage_map'] ?? array() ) as $key => $stage_id ) {
			$key = sanitize_key( $key );
			if ( $key && $stage_id ) { $legacy['statuses'][ $key ] = array( 'label' => Didar_Reference_Data::statuses()[ $key ] ?? $key, 'stage_id' => sanitize_text_field( $stage_id ), 'is_default' => 'pending_review' === $key, 'order' => count( $legacy['statuses'] ) * 10 + 10 ); }
		}
		return $legacy['pipeline_id'] || $legacy['statuses'] ? $legacy : array();
	}

	public function statuses( $form_type, $allow_legacy = true ) {
		$workflow = $this->workflow( $form_type, $allow_legacy );
		$statuses = isset( $workflow['statuses'] ) && is_array( $workflow['statuses'] ) ? $workflow['statuses'] : array();
		uasort( $statuses, function ( $a, $b ) { return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ); } );
		return $statuses;
	}

	public function status_label( $form_type, $status ) {
		$statuses = $this->statuses( $form_type );
		return isset( $statuses[ $status ]['label'] ) ? $statuses[ $status ]['label'] : ( Didar_Reference_Data::statuses()[ $status ] ?? __( 'نامشخص', 'didar' ) );
	}

	public function default_status( $form_type, $legacy_default = 'pending_review' ) {
		$statuses = $this->statuses( $form_type );
		$defaults = array_keys( array_filter( $statuses, function ( $item ) { return ! empty( $item['is_default'] ); } ) );
		if ( 1 === count( $defaults ) ) { return $defaults[0]; }
		if ( count( $defaults ) > 1 ) {
			$this->logger->log( 'WARNING', 'workflow_default_conflict', 'Multiple default statuses found; workflow resolution stopped.', array( 'form_type' => $form_type, 'default_statuses' => $defaults ) );
			return '';
		}
		if ( ! $statuses ) { return sanitize_key( $legacy_default ); }
		$this->logger->log( 'WARNING', 'workflow_default_missing', 'Form workflow has no default status.', array( 'form_type' => $form_type ) );
		return '';
	}

	public function mapping( $form_type, $status ) {
		$workflow = $this->workflow( $form_type );
		$status = sanitize_key( (string) $status );
		if ( empty( $workflow['pipeline_id'] ) || empty( $workflow['statuses'][ $status ]['stage_id'] ) ) { return array(); }
		return array( 'pipeline_id' => $workflow['pipeline_id'], 'stage_id' => $workflow['statuses'][ $status ]['stage_id'], 'status' => $status, 'legacy' => ! empty( $workflow['legacy'] ) );
	}

	/**
	 * Returns validation errors only when a form has explicitly opted into the
	 * per-form workflow configuration. Legacy data remains a migration fallback
	 * only when that form has no per-form workflow entry at all.
	 */
	public function configuration_errors( $form_type ) {
		$form_type = sanitize_key( (string) $form_type );
		$all       = $this->settings->all();
		if ( ! isset( $all['didar_form_workflows'][ $form_type ] ) || ! is_array( $all['didar_form_workflows'][ $form_type ] ) ) {
			return array();
		}

		$workflow = $this->sanitize_workflow( $all['didar_form_workflows'][ $form_type ] );
		$errors   = array();
		if ( ! $workflow['pipeline_id'] ) {
			$errors[] = 'pipeline_missing';
		}
		if ( ! $workflow['statuses'] ) {
			$errors[] = 'statuses_missing';
		}

		$defaults = 0;
		$pipeline = $workflow['pipeline_id'] ? $this->pipeline( $workflow['pipeline_id'] ) : array();
		$stage_ids = $pipeline ? wp_list_pluck( $pipeline['stages'], 'id' ) : array();
		foreach ( $workflow['statuses'] as $status ) {
			$defaults += ! empty( $status['is_default'] ) ? 1 : 0;
			if ( empty( $status['stage_id'] ) ) {
				$errors[] = 'stage_missing';
				continue;
			}
			if ( $pipeline && ! in_array( $status['stage_id'], $stage_ids, true ) ) {
				$errors[] = 'stage_not_in_pipeline';
			}
		}
		if ( $workflow['statuses'] && 1 !== $defaults ) {
			$errors[] = 'default_invalid';
		}

		return array_values( array_unique( $errors ) );
	}

	public function reverse_mapping( $form_type, $pipeline_id, $stage_id ) {
		$workflow = $this->workflow( $form_type );
		if ( empty( $workflow['pipeline_id'] ) || (string) $workflow['pipeline_id'] !== (string) $pipeline_id ) { return ''; }
		foreach ( $this->statuses( $form_type ) as $key => $definition ) { if ( (string) ( $definition['stage_id'] ?? '' ) === (string) $stage_id ) { return $key; } }
		return '';
	}

	public function pipeline( $pipeline_id ) { foreach ( $this->pipelines() as $pipeline ) { if ( (string) $pipeline['id'] === (string) $pipeline_id ) { return $pipeline; } } return array(); }

	public function validate_workflows( $submitted ) {
		$out = array();
		$submitted = is_array( $submitted ) ? $submitted : array();
		foreach ( $this->registry->all() as $form_type => $form ) {
			if ( empty( $submitted[ $form_type ] ) || ! is_array( $submitted[ $form_type ] ) ) { continue; }
			$workflow = $this->sanitize_workflow( $submitted[ $form_type ] );
			if ( ! $workflow['pipeline_id'] && ! $workflow['statuses'] ) { continue; }
			$pipeline = $this->pipeline( $workflow['pipeline_id'] );
			if ( ! $pipeline ) { add_settings_error( Didar_Settings::OPTION_NAME, 'didar_invalid_pipeline_' . $form_type, __( 'کاریز انتخاب‌شده در داده‌های کش‌شده دیدار وجود ندارد.', 'didar' ), 'error' ); continue; }
			$stage_ids = wp_list_pluck( $pipeline['stages'], 'id' );
			$defaults = 0;
			foreach ( $workflow['statuses'] as $key => $status ) {
				if ( ! in_array( $status['stage_id'], $stage_ids, true ) ) { add_settings_error( Didar_Settings::OPTION_NAME, 'didar_invalid_stage_' . $form_type, __( 'مرحله انتخاب‌شده به کاریز این فرم تعلق ندارد.', 'didar' ), 'error' ); continue 2; }
				$defaults += ! empty( $status['is_default'] ) ? 1 : 0;
			}
			if ( 1 !== $defaults ) { add_settings_error( Didar_Settings::OPTION_NAME, 'didar_default_status_' . $form_type, __( 'هر فرم باید دقیقاً یک وضعیت پیش‌فرض داشته باشد.', 'didar' ), 'error' ); continue; }
			$out[ $form_type ] = $workflow;
		}
		return $out;
	}

	private function sanitize_workflow( $workflow ) {
		$out = array( 'pipeline_id' => isset( $workflow['pipeline_id'] ) && is_scalar( $workflow['pipeline_id'] ) ? sanitize_text_field( wp_unslash( $workflow['pipeline_id'] ) ) : '', 'statuses' => array() );
		$raw = isset( $workflow['statuses'] ) && is_array( $workflow['statuses'] ) ? $workflow['statuses'] : array();
		foreach ( $raw as $key => $item ) {
			$item = is_array( $item ) ? $item : array(); $key = sanitize_key( isset( $item['key'] ) ? $item['key'] : $key );
			if ( ! $key ) { continue; }
			$out['statuses'][ $key ] = array( 'label' => isset( $item['label'] ) && is_scalar( $item['label'] ) ? sanitize_text_field( wp_unslash( $item['label'] ) ) : $key, 'stage_id' => isset( $item['stage_id'] ) && is_scalar( $item['stage_id'] ) ? sanitize_text_field( wp_unslash( $item['stage_id'] ) ) : '', 'is_default' => ! empty( $item['is_default'] ), 'order' => isset( $item['order'] ) ? absint( $item['order'] ) : count( $out['statuses'] ) * 10 + 10 );
		}
		return $out;
	}

	private function normalize_pipelines( $response ) {
		$items = isset( $response['Response'] ) ? $response['Response'] : $response;
		if ( isset( $items['List'] ) ) { $items = $items['List']; }
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || 'deal' !== strtolower( (string) ( $item['Type'] ?? '' ) ) || empty( $item['Id'] ) ) { continue; }
			$stages = array(); foreach ( (array) ( $item['Stages'] ?? array() ) as $stage ) { if ( is_array( $stage ) && ! empty( $stage['Id'] ) ) { $stages[] = array( 'id' => sanitize_text_field( $stage['Id'] ), 'title' => sanitize_text_field( $stage['Title'] ?? $stage['Id'] ), 'index' => absint( $stage['Index'] ?? 0 ), 'color' => sanitize_text_field( $stage['Color'] ?? '' ) ); } }
			usort( $stages, function ( $a, $b ) { return $a['index'] <=> $b['index']; } ); $out[] = array( 'id' => sanitize_text_field( $item['Id'] ), 'title' => sanitize_text_field( $item['Title'] ?? $item['Id'] ), 'type' => 'Deal', 'stages' => $stages );
		}
		usort( $out, function ( $a, $b ) { return strnatcasecmp( $a['title'], $b['title'] ); } ); return $out;
	}
	private function record_refresh_error( $error ) { $cache = $this->cache_info(); $cache['last_error'] = sanitize_key( $error ); $cache['last_error_at_gmt'] = current_time( 'mysql', true ); update_option( self::PIPELINES_OPTION, $cache, false ); $this->logger->log( 'ERROR', 'pipeline_metadata_refresh', 'Didar pipeline metadata refresh failed; existing cache retained.', array( 'error_code' => sanitize_key( $error ) ) ); }
}
