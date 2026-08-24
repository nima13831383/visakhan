<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates asynchronous, idempotent WordPress ↔ Didar synchronization. */
class Didar_Sync_Manager {
	const CRON_HOOK = 'didar_process_sync';
	const USER_HOOK = 'didar_process_user_sync';
	const META_DEAL_ID = '_didar_deal_id';
	const META_PERSON_ID = '_didar_person_id';
	const META_STATE = '_didar_sync_state';
	const USER_PERSON_META = '_didar_person_id';

	private static $suppress = false;
	private $registry;
	private $settings;
	private $events;
	private $service;
	private $api;
	private $mapper;
	private $logger;
	private $workflow;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Event_Log $events, Didar_Submission_Service $service, Didar_File_Service $files, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->events   = $events;
		$this->service  = $service;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $settings, $this->logger );
		$this->api      = new Didar_Api_Client( $settings, $this->logger );
		$this->mapper   = new Didar_Field_Mapper( $registry, $settings, $files );

		add_action( 'user_register', array( $this, 'queue_user' ), 20, 1 );
		add_action( 'didar_submission_created', array( $this, 'queue_submission' ), 20, 1 );
		add_action( 'didar_submission_updated', array( $this, 'queue_submission' ), 20, 1 );
		add_action( 'didar_submission_workflow_changed', array( $this, 'queue_submission' ), 20, 1 );
		add_action( self::CRON_HOOK, array( $this, 'process_submission' ), 10, 1 );
		add_action( self::USER_HOOK, array( $this, 'process_user' ), 10, 1 );
		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
		add_filter( 'pre_delete_post', array( $this, 'guard_submission_delete' ), 10, 3 );
		add_filter( 'pre_trash_post', array( $this, 'guard_submission_delete' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'record_submission_trash' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'record_submission_delete' ), 20, 2 );
	}

	/** Normal customers have no request deletion path, including direct wp_delete_post calls. */
	public function guard_submission_delete( $delete, $post, $force_delete ) {
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) {
			return $delete;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			$this->logger->log( 'WARNING', 'deletion_denied', 'Request deletion denied by the server-side capability check.', array(
				'direction'       => 'wordpress_to_didar',
				'entity_type'     => 'submission',
				'local_id'        => $post->ID,
				'wp_user_id'      => $this->service->get_owner_user_id( $post->ID ),
				'form_type'       => get_post_meta( $post->ID, '_didar_form_type', true ),
				'didar_deal_id'   => get_post_meta( $post->ID, self::META_DEAL_ID, true ),
				'deletion_source' => 'wordpress_customer',
			) );
			return false;
		}

		return $delete;
	}

	/** Record the chosen WordPress lifecycle; no remote delete is attempted because Didar has no verified delete API. */
	public function record_submission_trash( $new_status, $old_status, $post ) {
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || 'trash' !== $new_status || 'trash' === $old_status ) {
			return;
		}

		$this->record_deletion_operation( $post, 'request_trashed' );
	}

	/** Record permanent deletion before WordPress removes the post and its mapping metadata. */
	public function record_submission_delete( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->record_deletion_operation( $post, 'request_deleted' );
	}

	private function record_deletion_operation( $post, $event_type ) {
		$source = current_user_can( 'manage_options' ) ? 'wordpress_admin' : 'wordpress_broker';
		$deal_id = sanitize_text_field( (string) get_post_meta( $post->ID, self::META_DEAL_ID, true ) );
		$context = array(
			'direction'       => 'wordpress_to_didar',
			'entity_type'     => 'submission',
			'local_id'        => $post->ID,
			'wp_user_id'      => $this->service->get_owner_user_id( $post->ID ),
			'form_type'       => get_post_meta( $post->ID, '_didar_form_type', true ),
			'didar_deal_id'   => $deal_id,
			'deletion_source' => $source,
			'operation'       => 'delete',
			'official_support'=> 'not_confirmed',
		);
		$this->events->add( $post->ID, $event_type, null, null, $context + array( 'source' => $source ) );
		$this->logger->log( 'INFO', $event_type, 'Authorized WordPress request deletion lifecycle recorded; remote Didar Deal deletion is not available in the verified official API.', $context );
		if ( $deal_id ) {
			$this->logger->log( 'WARNING', 'deal_delete_unsupported', 'WordPress request deletion was authorized, but no remote Deal delete/archive call was made because official Didar deletion support is not documented.', $context );
		}
	}

	public function queue_user( $user_id ) {
		if ( ! $this->enabled() || ! absint( $user_id ) ) { return; }
		$this->logger->log( 'INFO', 'person_sync_queue', 'User Person sync queued.', array( 'entity_type' => 'user', 'local_id' => absint( $user_id ), 'direction' => 'wordpress_to_didar', 'source' => 'user_register' ) );
		wp_schedule_single_event( time() + 5, self::USER_HOOK, array( absint( $user_id ) ) );
	}

	public function queue_submission( $post_id ) {
		$post_id = absint( $post_id );
		if ( self::$suppress ) { $this->logger->log( 'INFO', 'sync_suppressed', 'Sync suppressed: inbound Didar update.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'source' => 'queue_submission', 'skip_reason' => 'inbound_didar_suppression' ) ); return; }
		if ( ! $this->enabled() ) { $this->logger->log( 'WARNING', 'sync_skipped', 'Submission sync skipped because the Didar API is not configured.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'source' => 'queue_submission', 'skip_reason' => 'didar_api_not_configured' ) ); return; }
		if ( Didar_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) { $this->logger->log( 'WARNING', 'sync_skipped', 'Submission sync skipped because the post type is invalid.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'source' => 'queue_submission', 'skip_reason' => 'invalid_post_type' ) ); return; }
		$state = $this->state( $post_id );
		$state['trace_id'] = Didar_Logger::trace_id( $state['trace_id'] ?? '' );
		$state['status'] = 'pending';
		$state['updated_at'] = time();
		update_post_meta( $post_id, self::META_STATE, $state );
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			$when = time() + 5; wp_schedule_single_event( $when, self::CRON_HOOK, array( $post_id ) );
			$this->logger->log( 'INFO', 'sync_queued', 'Submission sync hook fired and the canonical submission was queued.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'owner_user_id' => $this->service->get_owner_user_id( $post_id ), 'internal_status' => $this->internal_status( $post_id ), 'create_update_mode' => get_post_meta( $post_id, self::META_DEAL_ID, true ) ? 'update' : 'create', 'sync_hook_fired' => 'yes', 'suppression' => 'off', 'trace_id' => $state['trace_id'], 'source' => 'submission_hook', 'queue_job_id' => self::CRON_HOOK, 'scheduled_at' => Didar_Logger::display_timestamp( $when, DATE_ATOM ) ) );
		}
	}

	/** Run the same centralized sync immediately after an admin save has fully persisted canonical data. */
	public function sync_after_admin_save( $post_id ) {
		$post_id = absint( $post_id );
		$this->logger->log( 'INFO', 'didar_admin_submission_sync_execute', 'Admin submission entered centralized sync after canonical persistence.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'owner_user_id' => $this->service->get_owner_user_id( $post_id ), 'internal_status' => $this->internal_status( $post_id ), 'create_update_mode' => get_post_meta( $post_id, self::META_DEAL_ID, true ) ? 'update' : 'create', 'sync_hook_fired' => 'yes', 'suppression' => self::$suppress ? 'on' : 'off', 'source' => 'wp_admin' ) );
		$result = $this->process_submission( $post_id );
		if ( ! is_wp_error( $result ) ) {
			while ( $when = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
				wp_unschedule_event( $when, self::CRON_HOOK, array( $post_id ) );
			}
		}

		return $result;
	}

	public function manual_sync( $post_id ) {
		$post_id = absint( $post_id ); $state = $this->state( $post_id );
		$state['trace_id'] = Didar_Logger::trace_id( $state['trace_id'] ?? '' ); update_post_meta( $post_id, self::META_STATE, $state );
		$this->logger->log( 'INFO', 'manual_sync', 'Manual sync requested.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'trace_id' => $state['trace_id'], 'source' => 'admin' ) );
		return $this->process_submission( $post_id );
	}

	public function process_user( $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user || ! $this->enabled() ) { return; }
		$settings = $this->settings->all();
		if ( empty( $settings['didar_default_owner_id'] ) ) { $this->log_user_state( $user->ID, 'pending', 'didar_default_owner_missing' ); return $this->queue_user_retry( $user->ID ); }
		$trace = Didar_Logger::trace_id( '' ); $this->api->set_trace_id( $trace );
		$result = $this->resolve_and_sync_person( $user, array(), '', 0, $trace );
		if ( is_wp_error( $result ) ) { $status = 'didar_person_conflict' === $result->get_error_code() ? 'conflict' : 'pending'; $this->log_user_state( $user->ID, $status, $result->get_error_code() ); if ( 'conflict' !== $status ) { return $this->queue_user_retry( $user->ID ); } return; }
		$this->log_user_state( $user->ID, 'synced', '' );
	}

	public function process_submission( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! $this->enabled() ) {
			$this->logger->log( 'WARNING', 'sync_skipped', 'Submission sync stopped before execution.', array( 'entity_type' => 'submission', 'local_id' => absint( $post_id ), 'source' => 'sync_execution', 'skip_reason' => ! $post ? 'missing_post' : ( Didar_Post_Type::POST_TYPE !== $post->post_type ? 'invalid_post_type' : 'didar_api_not_configured' ) ) );
			return new WP_Error( 'didar_sync_stopped', 'Sync stopped before execution.' );
		}
		$form_type = sanitize_key( (string) get_post_meta( $post->ID, '_didar_form_type', true ) );
		$state = $this->state( $post->ID ); $trace = Didar_Logger::trace_id( $state['trace_id'] ?? '' ); $state['trace_id'] = $trace; $state['last_attempt_at'] = time(); update_post_meta( $post->ID, self::META_STATE, $state );
		$this->api->set_trace_id( $trace );
		$this->logger->log( 'INFO', 'sync_execute', 'Submission sync execution started.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'source' => 'wp_cron' ) );
		$form = $this->registry->get( $form_type );
		if ( ! $form ) { $this->logger->log( 'ERROR', 'mapping', 'Sync stopped: form mapping is missing.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'form_type' => $form_type, 'trace_id' => $trace ) ); return $this->fail( $post->ID, 'invalid_form_type' ); }
		$user_id = $this->service->get_owner_user_id( $post->ID );
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) { $this->logger->log( 'ERROR', 'person_sync', 'Sync stopped: submission owner is missing.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'trace_id' => $trace ) ); return $this->fail( $post->ID, 'submission_owner_missing' ); }
		$settings = $this->settings->all();
		$workflow_errors = $this->workflow->configuration_errors( $form_type );
		if ( $workflow_errors ) { $this->logger->log( 'ERROR', 'settings_check', 'Sync stopped: this form has an invalid per-form workflow and is not allowed to fall back to legacy settings.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'workflow_errors' => $workflow_errors ) ); return $this->fail( $post->ID, 'per_form_workflow_invalid', true ); }
		$internal_status = $this->internal_status( $post->ID );
		$workflow_mapping = $this->workflow->mapping( $form_type, $internal_status );
		if ( empty( $workflow_mapping['pipeline_id'] ) || empty( $workflow_mapping['stage_id'] ) ) { $this->logger->log( 'ERROR', 'settings_check', 'Sync stopped: form-specific Pipeline or Pipeline Stage mapping is missing.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'form_type' => $form_type, 'internal_status' => $internal_status, 'trace_id' => $trace, 'pipeline_id' => $workflow_mapping['pipeline_id'] ?? '', 'pipeline_stage_id' => $workflow_mapping['stage_id'] ?? '' ) ); return $this->fail( $post->ID, 'pipeline_mapping_missing', true ); }
		$fields = $this->service->get_fields( $post->ID );
		$legacy_person_mappings = $this->mapper->legacy_request_person_mappings( $form_type );
		if ( $legacy_person_mappings ) {
			$this->logger->log( 'WARNING', 'mapping_legacy_person_target', 'Legacy form-to-Person mappings were preserved but skipped; request values are Deal snapshots and do not update Person identity.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'legacy_mappings' => $legacy_person_mappings, 'person_source' => 'wordpress_user_profile' ) );
		}
		$person_result = $this->resolve_submission_person( $user, $form_type, $post->ID, $trace );
		if ( is_wp_error( $person_result ) ) { return $this->fail( $post->ID, $person_result->get_error_code(), 'didar_person_conflict' !== $person_result->get_error_code() ); }
		$person_id = $person_result;
		$this->logger->log( 'INFO', 'deal_resume', 'Deal sync resumed after Person resolution.', array( 'entity_type' => 'person', 'local_id' => $post->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'person_source' => 'wordpress_user_profile' ) );
		$local_deal_id = sanitize_text_field( (string) get_post_meta( $post->ID, self::META_DEAL_ID, true ) );
		$previous_status = sanitize_key( (string) ( $state['last_synced_internal_status'] ?? '' ) );
		if ( $local_deal_id && $previous_status && $previous_status !== $internal_status ) {
			$this->logger->log( 'INFO', 'didar_workflow_stage_sync_started', 'Internal status changed; synchronizing the existing Deal stage.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $local_deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'old_status' => $previous_status, 'new_status' => $internal_status ) );
			$this->logger->log( 'INFO', 'didar_workflow_stage_resolved', 'Per-form workflow stage resolved for the new internal status.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $local_deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'old_status' => $previous_status, 'new_status' => $internal_status, 'pipeline_id' => $workflow_mapping['pipeline_id'], 'pipeline_stage_id' => $workflow_mapping['stage_id'] ) );
		}
		if ( $local_deal_id && $this->deal_id_used_by_other_submission( $local_deal_id, $post->ID ) ) { $this->logger->log( 'ERROR', 'deal_identity_conflict', 'The local Deal ID is already linked to another WordPress submission; update stopped to prevent overwrite.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $local_deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'lookup_strategy' => 'local_deal_meta' ) ); return $this->fail( $post->ID, 'didar_deal_conflict', false ); }
		$deal = array(
			'Id' => $local_deal_id,
			'Title' => sanitize_text_field( $form['label'] . ' #' . $post->ID ),
			'Description' => $this->service->get_shared_note( $post->ID ),
			'PersonId' => $person_id,
			'PipelineId' => $workflow_mapping['pipeline_id'],
			'PipelineStageId' => $workflow_mapping['stage_id'],
			'Status' => 'Pending',
			'Fields' => $this->mapper->deal_fields( $form_type, $fields, $post->ID ),
		);
		$this->logger->log( 'INFO', 'deal_identity', $deal['Id'] ? 'Stored local Deal ID found; updating the Deal belonging to this submission.' : 'No stored local Deal ID; Deal identity will use exact Submission ID lookup or create.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $deal['Id'], 'form_type' => $form_type, 'trace_id' => $trace, 'resolved_person_id' => $person_id, 'lookup_strategy' => $deal['Id'] ? 'local_deal_meta' : 'submission_id_custom_field' ) );
		$this->logger->log( 'INFO', 'deal_create', 'Deal payload built; form identity fields remain request snapshots.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'external_id' => $person_id, 'form_type' => $form_type, 'trace_id' => $trace, 'wp_user_id' => $user->ID, 'pipeline_id' => $deal['PipelineId'], 'pipeline_stage_id' => $deal['PipelineStageId'], 'deal_field_mapping' => $deal['Fields'], 'person_source' => 'wordpress_user_profile' ) );
		if ( empty( $deal['Id'] ) ) {
			$existing_deal_id = $this->find_deal_by_submission_id( $post->ID, $settings );
			if ( is_wp_error( $existing_deal_id ) ) {
				$lookup_error = 'didar_deal_lookup_failed';
				$this->logger->log( 'ERROR', 'deal_lookup_failed', 'Deal lookup failed; no Deal will be created because the existing Deal identity could not be verified.', array(
					'entity_type' => 'deal',
					'local_id' => $post->ID,
					'form_type' => $form_type,
					'trace_id' => $trace,
					'error_code' => $existing_deal_id->get_error_code(),
					'error_message' => $existing_deal_id->get_error_message(),
					'api_response' => $existing_deal_id->get_error_data(),
					'lookup_strategy' => 'submission_id_custom_field',
				) );
				return $this->fail( $post->ID, $lookup_error, true );
			}
			if ( $existing_deal_id ) {
				$deal['Id'] = $existing_deal_id;
			}
		}
		$deal = array_merge( $deal, $this->mapper->deal_native_fields( $form_type, $fields ) );
		if ( ! isset( $deal['Fields'] ) || ! is_array( $deal['Fields'] ) ) { $deal['Fields'] = array(); }
		if ( ! empty( $settings['didar_system_submission_id_field_id'] ) ) { $deal['Fields'][ sanitize_text_field( $settings['didar_system_submission_id_field_id'] ) ] = (string) $post->ID; }
		if ( ! empty( $settings['didar_system_form_type_field_id'] ) ) { $deal['Fields'][ sanitize_text_field( $settings['didar_system_form_type_field_id'] ) ] = $form_type; }
		if ( ! empty( $settings['didar_system_user_id_field_id'] ) ) { $deal['Fields'][ sanitize_text_field( $settings['didar_system_user_id_field_id'] ) ] = (string) $user->ID; }
		$owner = $this->didar_owner_for_wp_user( $this->service->get_assigned_user_id( $post->ID ) );
		if ( ! $owner && ! empty( $settings['didar_default_owner_id'] ) ) { $owner = sanitize_text_field( $settings['didar_default_owner_id'] ); }
		if ( $owner ) { $deal['OwnerId'] = $owner; }
		$public_field = isset( $settings['didar_public_status_field_id'] ) ? sanitize_text_field( (string) $settings['didar_public_status_field_id'] ) : '';
		if ( $public_field ) { $deal['Fields'][ $public_field ] = sanitize_key( (string) get_post_meta( $post->ID, '_didar_public_status', true ) ); }
		$is_new_deal = empty( $deal['Id'] );
		$create_payload = $deal;
		if ( $is_new_deal ) {
			unset( $create_payload['Id'] );
		}
		if ( $is_new_deal && ! empty( $create_payload['Fields'] ) ) {
			unset( $create_payload['Fields'] );
			$this->logger->log( 'INFO', 'deal_create', 'Creating the Deal with required native fields first; request Custom Fields will be applied in a same-Deal update.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'external_id' => $person_id, 'form_type' => $form_type, 'trace_id' => $trace, 'deal_payload' => $create_payload, 'deferred_field_count' => count( $deal['Fields'] ) ) );
		}
		$result = $this->api->save_deal( $create_payload );
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'ERROR', $is_new_deal ? 'deal_create' : 'deal_update', 'Deal API call failed.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'external_id' => $person_id, 'form_type' => $form_type, 'trace_id' => $trace, 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message(), 'api_response' => $result->get_error_data(), 'deal_payload' => $create_payload ) );
			if ( $local_deal_id && $previous_status && $previous_status !== $internal_status ) {
				$this->logger->log( 'ERROR', 'didar_workflow_stage_update_failed', 'Existing Didar Deal Pipeline Stage update failed.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $local_deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'old_status' => $previous_status, 'new_status' => $internal_status, 'pipeline_id' => $workflow_mapping['pipeline_id'], 'pipeline_stage_id' => $workflow_mapping['stage_id'], 'error_code' => $result->get_error_code() ) );
			}

			return $this->fail( $post->ID, $result->get_error_code(), true );
		}
		if ( $local_deal_id && $previous_status && $previous_status !== $internal_status ) {
			$this->logger->log( 'INFO', 'didar_workflow_stage_update_succeeded', 'Existing Didar Deal Pipeline and Pipeline Stage were updated.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $local_deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'old_status' => $previous_status, 'new_status' => $internal_status, 'pipeline_id' => $workflow_mapping['pipeline_id'], 'pipeline_stage_id' => $workflow_mapping['stage_id'], 'api_response' => $result ) );
		}
		$this->logger->log( 'INFO', 'deal_response', 'Deal API response received.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $person_id, 'form_type' => $form_type, 'trace_id' => $trace, 'api_response' => $result ) );
		$response = $this->response_object( $result );
		$deal_id = $deal['Id'] ?: ( isset( $response['Id'] ) ? sanitize_text_field( $response['Id'] ) : '' );
		if ( ! $deal_id ) { $this->logger->log( 'ERROR', 'deal_create', 'Deal API response did not contain a Deal ID.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'api_response' => $result ) ); return $this->fail( $post->ID, 'didar_deal_id_missing' ); }
		if ( $is_new_deal ) {
			$deal['Id'] = $deal_id;
			update_post_meta( $post->ID, self::META_DEAL_ID, $deal_id );
			$this->logger->log( 'INFO', 'deal_persist', 'New Deal ID stored before request Custom Field update to preserve retry idempotency.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'external_id' => $deal_id, 'form_type' => $form_type, 'trace_id' => $trace ) );
			if ( ! empty( $deal['Fields'] ) ) {
				$result = $this->api->save_deal( $deal );
				if ( is_wp_error( $result ) ) { $this->logger->log( 'ERROR', 'deal_update', 'Deal was created, but request Custom Field update failed; the local Deal ID was retained for retry.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message(), 'api_response' => $result->get_error_data(), 'deal_payload' => $deal ) ); return $this->fail( $post->ID, $result->get_error_code(), true ); }
				$this->logger->log( 'INFO', 'deal_response', 'Request Custom Fields applied to the newly created Deal.', array( 'entity_type' => 'deal', 'local_id' => $post->ID, 'external_id' => $deal_id, 'form_type' => $form_type, 'trace_id' => $trace, 'api_response' => $result ) );
			}
		}
		update_post_meta( $post->ID, self::META_DEAL_ID, $deal_id );
		$this->logger->log( 'INFO', 'deal_persist', 'Deal ID stored in WordPress; sync marked successful.', array( 'entity_type' => 'submission', 'local_id' => $post->ID, 'external_id' => $deal_id, 'form_type' => $form_type, 'trace_id' => $trace ) );
		$this->success( $post->ID, $deal_id, $internal_status );
		return true;
	}

	public function register_webhook_route() {
		register_rest_route( 'didar/v1', '/webhook', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'receive_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function receive_webhook( WP_REST_Request $request ) {
		$this->logger->log( 'INFO', 'webhook_received', 'Didar webhook received.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'webhook', 'webhook_event_id' => $request->get_header( 'x-didar-event-id' ), 'source' => 'webhook' ) );
		$settings = $this->settings->all();
		$secret = isset( $settings['didar_webhook_secret'] ) ? (string) $settings['didar_webhook_secret'] : '';
		$provided = (string) $request->get_header( 'x-didar-webhook-token' );
		if ( '' === $secret || '' === $provided || ! hash_equals( $secret, $provided ) ) { $this->logger->log( 'WARNING', 'webhook_authentication', 'Didar webhook authentication failed.', array( 'direction' => 'didar_to_wordpress', 'source' => 'webhook' ) ); return new WP_Error( 'didar_webhook_unauthorized', __( 'وب‌هوک دیدار احراز نشد.', 'didar' ), array( 'status' => 401 ) ); }
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload['data'] ) || empty( $payload['meta']['entityId'] ) ) { return new WP_Error( 'didar_webhook_invalid', __( 'ساختار وب‌هوک دیدار معتبر نیست.', 'didar' ), array( 'status' => 400 ) ); }
		$meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
		$event_id = isset( $meta['id'] ) ? sanitize_text_field( (string) $meta['id'] ) : md5( wp_json_encode( $payload ) );
		if ( $this->seen_webhook( $event_id ) ) { $this->logger->log( 'INFO', 'webhook_deduplication', 'Duplicate Didar webhook ignored.', array( 'direction' => 'didar_to_wordpress', 'webhook_event_id' => $event_id, 'source' => 'webhook' ) ); return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 ); }
		$entity = sanitize_key( isset( $meta['entityTitle'] ) ? (string) $meta['entityTitle'] : '' );
		$this->logger->log( 'INFO', 'webhook_authenticated', 'Didar webhook authenticated and accepted.', array( 'direction' => 'didar_to_wordpress', 'webhook_event_id' => $event_id, 'entity_type' => $entity, 'external_id' => $payload['meta']['entityId'] ?? '', 'source' => 'webhook' ) );
		if ( false !== strpos( $entity, 'deal' ) || 'deal' === $entity || 'معامله' === $entity ) { $this->apply_deal_webhook( $payload, $event_id ); }
		if ( false !== strpos( $entity, 'person' ) || 'person' === $entity || 'شخص' === $entity ) { $this->apply_person_webhook( $payload, $event_id ); }
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private function apply_person_webhook( $payload, $event_id ) {
		$data      = is_array( $payload['data'] ) ? $payload['data'] : array();
		$person_id = sanitize_text_field( $payload['meta']['entityId'] ?? ( $data['Id'] ?? '' ) );
		$user_id   = $this->wp_user_for_person( $person_id );
		$this->logger->log( 'INFO', 'person_webhook_separate', 'Didar Person webhook received; WordPress profile synchronization is disabled for this integration.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'person', 'external_id' => $person_id, 'wp_user_id' => $user_id, 'webhook_event_id' => $event_id, 'source' => 'didar_webhook', 'profile_sync' => 'disabled' ) );
	}

	private function apply_deal_webhook( $payload, $event_id ) {
		$data = is_array( $payload['data'] ) ? $payload['data'] : array();
		$deal_id = sanitize_text_field( isset( $payload['meta']['entityId'] ) ? $payload['meta']['entityId'] : ( isset( $data['Id'] ) ? $data['Id'] : '' ) );
		$post_id = $this->find_submission_by_deal( $deal_id );
		$settings = $this->settings->all();
		$fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		$system_submission_id = $this->deal_custom_field_value( $data, array( $settings['didar_system_submission_id_field_id'] ?? '' ) );
		if ( ! $post_id && is_scalar( $system_submission_id ) && absint( $system_submission_id ) ) {
			$post_id = $this->find_submission_by_submission_id( $system_submission_id, $deal_id );
		}
		if ( ! empty( $data['IsDeleted'] ) ) {
			$this->logger->log( 'WARNING', 'deal_delete_unsupported', 'Didar Deal deletion state received, but official reverse Deal deletion support is not confirmed; WordPress request was not deleted.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'deal', 'external_id' => $deal_id, 'local_id' => $post_id, 'webhook_event_id' => $event_id, 'source' => 'didar_webhook', 'deletion_source' => 'didar_webhook', 'official_support' => 'not_confirmed' ) );
			return;
		}
		if ( ! $post_id ) {
			$action_type = isset( $payload['meta']['actionType'] ) ? absint( $payload['meta']['actionType'] ) : 0;
			if ( 1 !== $action_type ) { $this->logger->log( 'INFO', 'webhook_matching', 'Deal webhook had no mapped WordPress submission.', array( 'entity_type' => 'deal', 'external_id' => $deal_id, 'webhook_event_id' => $event_id, 'source' => 'webhook' ) ); return; }
			$form_type = $this->form_type_from_deal( $data );
			$user_id = $this->wp_user_from_deal( $data );
			if ( ! $form_type || ! $user_id ) { $this->logger->log( 'WARNING', 'webhook_matching', 'Deal webhook could not resolve form type or WordPress user.', array( 'entity_type' => 'deal', 'external_id' => $deal_id, 'webhook_event_id' => $event_id, 'source' => 'webhook' ) ); return; }
			$post_id = $this->service->create_from_didar( $form_type, $this->mapped_submission_fields( $form_type, $data ), $user_id, $data['Description'] ?? '' );
			if ( is_wp_error( $post_id ) ) { $this->logger->log( 'ERROR', 'webhook_apply', 'Deal webhook submission creation failed.', array( 'entity_type' => 'deal', 'external_id' => $deal_id, 'webhook_event_id' => $event_id, 'error_code' => $post_id->get_error_code(), 'source' => 'webhook' ) ); return; }
			update_post_meta( $post_id, self::META_DEAL_ID, $deal_id );
			$person_id = sanitize_text_field( (string) get_user_meta( $user_id, self::USER_PERSON_META, true ) );
			if ( $person_id ) { update_post_meta( $post_id, self::META_PERSON_ID, $person_id ); }
			$this->logger->log( 'INFO', 'webhook_apply', 'Didar Deal created a WordPress request from explicit system mappings.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'deal', 'external_id' => $deal_id, 'local_id' => $post_id, 'wp_user_id' => $user_id, 'form_type' => $form_type, 'webhook_event_id' => $event_id, 'source' => 'didar_webhook' ) );
		}
		self::$suppress = true;
		try { $this->update_local_from_deal( $post_id, $data, $event_id ); } finally { self::$suppress = false; }
	}

	private function update_local_from_deal( $post_id, $data, $event_id ) {
		$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
		$old = $this->service->get_fields( $post_id );
		$new = $old;
		$fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		foreach ( $this->registry->fields( $form_type ) as $key => $definition ) {
			$map = $this->mapper->mapping( $form_type, $key );
			if ( 'deal_custom' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $fields ) ) { $new[ $key ] = $fields[ $map['field'] ]; }
			if ( 'deal_native' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $data ) ) { $new[ $key ] = $data[ $map['field'] ]; }
		}
		if ( $new !== $old ) { update_post_meta( $post_id, '_didar_fields', $new ); }
		$stage = isset( $data['PipelineStageId'] ) ? sanitize_text_field( $data['PipelineStageId'] ) : '';
		$pipeline = isset( $data['PipelineId'] ) ? sanitize_text_field( $data['PipelineId'] ) : '';
		$status = $this->workflow->reverse_mapping( $form_type, $pipeline, $stage );
		if ( $status ) {
			$old_status = (string) get_post_meta( $post_id, '_didar_internal_status', true );
			if ( $old_status !== $status ) { update_post_meta( $post_id, '_didar_internal_status', $status ); $this->events->add( $post_id, 'internal_status_changed', $old_status, $status, array( 'form_type' => $form_type, 'old_status_key' => $old_status, 'old_status_label' => $this->workflow->status_label( $form_type, $old_status ), 'new_status_key' => $status, 'new_status_label' => $this->workflow->status_label( $form_type, $status ), 'pipeline_id' => $pipeline, 'pipeline_stage_id' => $stage, 'source' => 'didar', 'actor' => 0 ) ); }
		} elseif ( $stage ) {
			$this->logger->log( 'WARNING', 'workflow_mapping_conflict', 'Didar webhook stage does not match this form workflow; local status was not changed.', array( 'form_type' => $form_type, 'local_id' => $post_id, 'pipeline_id' => $pipeline, 'pipeline_stage_id' => $stage, 'source' => 'didar_webhook' ) );
		}
		$settings = $this->settings->all(); $public_field = isset( $settings['didar_public_status_field_id'] ) ? sanitize_text_field( $settings['didar_public_status_field_id'] ) : ''; if ( $public_field && isset( $fields[ $public_field ] ) && isset( Didar_Reference_Data::statuses()[ sanitize_key( $fields[ $public_field ] ) ] ) ) { update_post_meta( $post_id, '_didar_public_status', sanitize_key( $fields[ $public_field ] ) ); update_post_meta( $post_id, '_didar_status', sanitize_key( $fields[ $public_field ] ) ); }
		$owner = isset( $data['OwnerId'] ) ? $this->wp_user_for_didar( $data['OwnerId'] ) : 0;
		if ( $owner ) { update_post_meta( $post_id, '_didar_assigned_user_id', $owner ); }
		$this->events->add( $post_id, 'didar_webhook_received', $old, $new, array( 'source' => 'Didar', 'event_id' => $event_id, 'entity_id' => isset( $data['Id'] ) ? $data['Id'] : '', 'request_snapshot_only' => true ) );
		$this->logger->log( 'INFO', 'webhook_apply', 'Didar Deal webhook updated request snapshot fields only; WordPress user profile was not modified.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'deal', 'external_id' => $deal_id, 'local_id' => $post_id, 'wp_user_id' => get_post_field( 'post_author', $post_id ), 'form_type' => $form_type, 'webhook_event_id' => $event_id, 'source' => 'didar_webhook', 'deal_field_mapping' => $fields ) );
	}

	private function mapped_submission_fields( $form_type, $data ) {
		$out = array(); $fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		foreach ( $this->registry->fields( $form_type ) as $key => $definition ) { $map = $this->mapper->mapping( $form_type, $key ); if ( 'deal_custom' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $fields ) ) { $out[ $key ] = $this->sanitize_external_value( $fields[ $map['field'] ], $definition ); } }
		return $out;
	}
	private function sanitize_external_value( $value, $definition ) { if ( is_array( $value ) ) { $out = array(); foreach ( $value as $key => $item ) { $clean_key = is_int( $key ) ? $key : sanitize_key( $key ); $out[ $clean_key ] = is_array( $item ) ? $this->sanitize_external_value( $item, $definition ) : sanitize_text_field( (string) $item ); } return $out; } return 'textarea' === ( $definition['type'] ?? '' ) ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ); }

	private function form_type_from_deal( $data ) {
		$settings = $this->settings->all(); $fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		$key = isset( $settings['didar_system_form_type_field_id'] ) ? sanitize_text_field( $settings['didar_system_form_type_field_id'] ) : '';
		$type = $key && isset( $fields[ $key ] ) ? sanitize_key( (string) $fields[ $key ] ) : '';
		return $this->registry->is_valid_type( $type ) ? $type : '';
	}

	private function wp_user_from_deal( $data ) {
		$settings = $this->settings->all();
		$field_id = isset( $settings['didar_system_user_id_field_id'] ) ? sanitize_text_field( (string) $settings['didar_system_user_id_field_id'] ) : '';
		$value    = $this->deal_custom_field_value( $data, array( $field_id ) );
		$user_id  = is_scalar( $value ) ? absint( $value ) : 0;
		return $user_id && get_user_by( 'id', $user_id ) ? $user_id : 0;
	}

	/** Registration/profile flow: resolve one Person deterministically and sync account data. */
	private function resolve_and_sync_person( $user, $fields, $form_type, $submission_id, $trace ) {
		$payload = $this->mapper->person_payload( $user, $fields, $form_type );
		$stored_id = sanitize_text_field( (string) get_user_meta( $user->ID, self::USER_PERSON_META, true ) );
		if ( $stored_id ) {
			$this->logger->log( 'INFO', 'person_resolution', 'Stored Didar Person ID found; updating the registration-linked profile Person.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $stored_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'source' => 'wordpress_user_profile', 'person_source' => 'wordpress_user_profile' ) );
			$payload['Id'] = $stored_id;
			$person_id = $stored_id;
		} else {
			$person_id = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace );
			if ( is_wp_error( $person_id ) ) { return $person_id; }
			if ( $person_id ) { $payload['Id'] = $person_id; update_user_meta( $user->ID, self::USER_PERSON_META, $person_id ); $this->logger->log( 'INFO', 'person_linked', 'Existing Didar Person linked and ID persisted.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace ) ); }
		}
		$creating = empty( $payload['Id'] );
		$result = $this->api->save_person( $payload );
		if ( is_wp_error( $result ) && $this->is_duplicate_contact_error( $result ) ) {
			$this->logger->log( 'WARNING', 'person_duplicate_recovery', 'Duplicate Person create detected; running exact recovery lookup.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'error_code' => $result->get_error_code() ) );
			$recovered = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace, true );
			if ( is_wp_error( $recovered ) ) { return $recovered; }
			if ( $recovered ) { $payload['Id'] = $recovered; $result = $this->api->save_person( $payload ); $person_id = $recovered; if ( is_wp_error( $result ) && $this->is_duplicate_contact_error( $result ) ) { $this->logger->log( 'WARNING', 'person_duplicate_recovery', 'Existing Person was resolved; Didar rejected the mapped update as a duplicate, so sync continues with the resolved Person.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace ) ); $result = array( 'Response' => array( 'Id' => $person_id ) ); } elseif ( ! is_wp_error( $result ) ) { $this->logger->log( 'INFO', 'person_duplicate_recovery', 'Duplicate recovery succeeded; existing Person updated.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace ) ); } }
		}
		if ( is_wp_error( $result ) ) { $this->logger->log( 'ERROR', 'person_save', 'Person create/update failed.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message(), 'api_response' => $result->get_error_data() ) ); return $result; }
		$response = $this->response_object( $result ); $person_id = $person_id ?: ( isset( $response['Id'] ) ? sanitize_text_field( $response['Id'] ) : '' );
		if ( ! $person_id ) { return new WP_Error( 'didar_person_id_missing', 'Didar Person ID was not returned.', array( 'trace_id' => $trace ) ); }
		update_user_meta( $user->ID, self::USER_PERSON_META, $person_id );
		$this->logger->log( 'INFO', $creating ? 'person_created' : 'person_updated', $creating ? 'New Didar Person created from WordPress user registration.' : 'Didar Person profile synchronized from WordPress user registration.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'person_source' => 'wordpress_user_profile' ) );
		$this->logger->log( 'INFO', 'person_persisted', 'Didar Person ID persisted in WordPress.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace ) );
		return $person_id;
	}

	/** Request flow: resolve/create the Person from the WordPress user only; never from request fields. */
	private function resolve_submission_person( $user, $form_type, $submission_id, $trace ) {
		$stored_id = sanitize_text_field( (string) get_user_meta( $user->ID, self::USER_PERSON_META, true ) );
		$context   = array( 'entity_type' => 'person', 'local_id' => $submission_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'person_source' => 'wordpress_user_profile' );
		if ( $stored_id ) {
			update_post_meta( $submission_id, self::META_PERSON_ID, $stored_id );
			$this->logger->log( 'INFO', 'person_resolution', 'Didar Person resolved from WordPress user identity.', $context + array( 'external_id' => $stored_id, 'lookup_strategy' => 'stored_user_person_meta' ) );
			$this->logger->log( 'INFO', 'person_persisted', 'Resolved Person ID persisted as a submission convenience reference.', $context + array( 'external_id' => $stored_id ) );
			$this->logger->log( 'INFO', 'request_identity_isolation', 'Form identity fields treated as Deal snapshot; Person profile unchanged.', $context );
			return $stored_id;
		}

		$payload = $this->mapper->person_payload( $user );
		$this->logger->log( 'INFO', 'person_resolution', 'No user Person link exists; resolving with authoritative WordPress profile data.', $context + array( 'lookup_strategy' => 'wordpress_user_profile' ) );
		$person_id = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace );
		if ( is_wp_error( $person_id ) ) {
			return $person_id;
		}
		if ( $person_id ) {
			update_user_meta( $user->ID, self::USER_PERSON_META, $person_id );
			update_post_meta( $submission_id, self::META_PERSON_ID, $person_id );
			$this->logger->log( 'INFO', 'person_linked', 'Existing Didar Person resolved from WordPress user profile; Person profile was not changed by request sync.', $context + array( 'external_id' => $person_id ) );
			return $person_id;
		}

		$this->logger->log( 'INFO', 'person_create', 'No Person matched authoritative WordPress user profile; creating the account Person.', $context + array( 'person_payload' => $payload ) );
		$created = $this->api->save_person( $payload );
		if ( is_wp_error( $created ) && $this->is_duplicate_contact_error( $created ) ) {
			$this->logger->log( 'WARNING', 'person_duplicate_recovery', 'Duplicate Person creation detected; repeating the WordPress profile lookup.', $context );
			$person_id = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace, true );
			if ( is_wp_error( $person_id ) ) {
				return $person_id;
			}
			if ( $person_id ) {
				update_user_meta( $user->ID, self::USER_PERSON_META, $person_id );
				update_post_meta( $submission_id, self::META_PERSON_ID, $person_id );
				$this->logger->log( 'INFO', 'person_duplicate_recovery', 'Duplicate recovery linked the existing account Person without a request-driven profile update.', $context + array( 'external_id' => $person_id ) );
				return $person_id;
			}
		}
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$response  = $this->response_object( $created );
		$person_id = isset( $response['Id'] ) ? sanitize_text_field( (string) $response['Id'] ) : '';
		if ( ! $person_id ) {
			return new WP_Error( 'didar_person_id_missing', 'Didar did not return a Person ID.', array( 'trace_id' => $trace ) );
		}
		update_user_meta( $user->ID, self::USER_PERSON_META, $person_id );
		update_post_meta( $submission_id, self::META_PERSON_ID, $person_id );
		$this->logger->log( 'INFO', 'person_created', 'New Didar Person created from authoritative WordPress user profile.', $context + array( 'external_id' => $person_id ) );
		$this->logger->log( 'INFO', 'person_persisted', 'Didar Person ID persisted on the WordPress user and submission.', $context + array( 'external_id' => $person_id ) );
		return $person_id;
	}

	private function find_exact_person_id( $payload, $user_id, $submission_id, $form_type, $trace, $recovery = false ) {
		$mobile = $this->normalize_mobile( $payload['MobilePhone'] ?? '' ); $email = $this->normalize_email( $payload['Email'] ?? '' );
		if ( $mobile ) { $matches = $this->search_exact_persons( 'MobilePhone', $payload['MobilePhone'], $mobile, $user_id, $submission_id, $form_type, $trace, $recovery ); if ( is_wp_error( $matches ) ) { return $matches; } if ( 1 === count( $matches ) ) { return $matches[0]; } if ( count( $matches ) > 1 ) { return $this->person_conflict( 'Multiple exact mobile matches found.', $matches, $user_id, $submission_id, $form_type, $trace ); } }
		if ( $email ) { $matches = $this->search_exact_persons( 'Email', $payload['Email'], $email, $user_id, $submission_id, $form_type, $trace, $recovery ); if ( is_wp_error( $matches ) ) { return $matches; } if ( 1 === count( $matches ) ) { return $matches[0]; } if ( count( $matches ) > 1 ) { return $this->person_conflict( 'Multiple exact email matches found.', $matches, $user_id, $submission_id, $form_type, $trace ); } }
		return '';
	}

	private function search_exact_persons( $field, $value, $normalized, $user_id, $submission_id, $form_type, $trace, $recovery ) {
		$this->logger->log( 'INFO', 'person_search', ( $recovery ? 'Duplicate recovery lookup started.' : 'Exact Person search started.' ), array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user_id, 'wp_user_id' => $user_id, 'form_type' => $form_type, 'trace_id' => $trace, 'match_field' => $field ) );
		$result = $this->api->search_person( array( $field => $value, 'IsDeleted' => 0 ), 0, 100 );
		if ( is_wp_error( $result ) ) { return $result; }
		$matches = array(); foreach ( $this->response_items( $result ) as $person ) { if ( ! is_array( $person ) || empty( $person['Id'] ) ) { continue; } $candidate = 'MobilePhone' === $field ? $this->normalize_mobile( $person['MobilePhone'] ?? '' ) : $this->normalize_email( $person['Email'] ?? '' ); if ( $candidate === $normalized ) { $matches[ sanitize_text_field( (string) $person['Id'] ) ] = true; } }
		$this->logger->log( count( $matches ) > 1 ? 'WARNING' : 'INFO', 'person_search', 'Exact ' . strtolower( $field ) . ' match count: ' . count( $matches ) . '.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user_id, 'wp_user_id' => $user_id, 'form_type' => $form_type, 'trace_id' => $trace, 'match_field' => $field, 'match_count' => count( $matches ) ) );
		return array_keys( $matches );
	}

	private function person_conflict( $message, $matches, $user_id, $submission_id, $form_type, $trace ) { $this->logger->log( 'ERROR', 'person_conflict', 'Ambiguous Person conflict; Deal creation stopped.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user_id, 'wp_user_id' => $user_id, 'form_type' => $form_type, 'trace_id' => $trace, 'match_count' => count( $matches ), 'candidate_person_ids' => $matches ) ); return new WP_Error( 'didar_person_conflict', $message, array( 'trace_id' => $trace, 'candidate_count' => count( $matches ) ) ); }
	private function is_duplicate_contact_error( $error ) { $data = $error->get_error_data(); return (bool) preg_match( '/duplicate\s+contacts\s+is\s+not\s+allowed/i', $error->get_error_message() . ' ' . wp_json_encode( $data ) ); }
	private function response_items( $response ) { $value = isset( $response['Response'] ) ? $response['Response'] : array(); if ( isset( $value['List'] ) && is_array( $value['List'] ) ) { return $value['List']; } if ( isset( $value['Items'] ) && is_array( $value['Items'] ) ) { return $value['Items']; } if ( isset( $value[0] ) ) { return $value; } return is_array( $value ) && isset( $value['Id'] ) ? array( $value ) : array(); }
	private function normalize_mobile( $value ) { $value = strtr( trim( (string) $value ), array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) ); $value = preg_replace( '/\D+/', '', $value ); return '00' === substr( $value, 0, 2 ) ? substr( $value, 2 ) : $value; }
	private function normalize_email( $value ) { return strtolower( trim( sanitize_email( (string) $value ) ) ); }

	private function find_submission_by_deal( $deal_id ) { $q = new WP_Query( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => self::META_DEAL_ID, 'meta_value' => $deal_id, 'no_found_rows' => true ) ); return ! empty( $q->posts[0] ) ? absint( $q->posts[0] ) : 0; }
	private function find_submission_by_submission_id( $submission_id, $deal_id = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id || Didar_Post_Type::POST_TYPE !== get_post_type( $submission_id ) ) {
			return 0;
		}
		$stored_deal_id = sanitize_text_field( (string) get_post_meta( $submission_id, self::META_DEAL_ID, true ) );
		return $stored_deal_id && $deal_id && $stored_deal_id !== $deal_id ? 0 : $submission_id;
	}
	private function deal_id_used_by_other_submission( $deal_id, $post_id ) { $q = new WP_Query( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 2, 'post__not_in' => array( absint( $post_id ) ), 'meta_key' => self::META_DEAL_ID, 'meta_value' => $deal_id, 'no_found_rows' => true ) ); return ! empty( $q->posts ); }
	private function find_deal_by_submission_id( $submission_id, $settings ) {
		$field_id = isset( $settings['didar_system_submission_id_field_id'] ) ? sanitize_text_field( (string) $settings['didar_system_submission_id_field_id'] ) : '';
		$context = array( 'entity_type' => 'deal', 'local_id' => $submission_id, 'form_type' => get_post_meta( $submission_id, '_didar_form_type', true ), 'trace_id' => $this->state( $submission_id )['trace_id'] ?? '', 'submission_id' => (string) $submission_id );
		if ( ! $field_id ) {
			$this->logger->log( 'WARNING', 'deal_identity', 'External idempotent Deal lookup unavailable: Submission ID Custom Field is not configured. Creating a new Deal; Person-based reuse is forbidden.', $context );
			return '';
		}
		$this->logger->log( 'INFO', 'deal_lookup', 'Looking up Deal only by exact WordPress Submission ID Custom Field.', $context + array( 'lookup_strategy' => 'submission_id_custom_field', 'custom_field_id' => $field_id ) );
		$metadata = $this->api->custom_fields();
		if ( is_wp_error( $metadata ) ) {
			$this->logger->log( 'ERROR', 'deal_lookup_failed', 'Deal lookup stopped because Custom Field metadata could not be loaded safely.', $context + array( 'error_code' => $metadata->get_error_code(), 'error_message' => $metadata->get_error_message(), 'api_response' => $metadata->get_error_data(), 'lookup_strategy' => 'submission_id_custom_field', 'configured_custom_field_key' => $field_id ) );
			return new WP_Error( 'didar_deal_lookup_failed', 'Deal Custom Field metadata could not be loaded.', array( 'trace_id' => $context['trace_id'], 'source_error' => $metadata->get_error_code() ) );
		}
		$field_metadata = $this->custom_field_metadata_by_key( $metadata, $field_id );
		$search_field_id = isset( $field_metadata['Id'] ) ? sanitize_text_field( (string) $field_metadata['Id'] ) : '';
		$show_in_add_deal = isset( $field_metadata['ViewOptions']['ShowInAddDeal'] ) ? (bool) $field_metadata['ViewOptions']['ShowInAddDeal'] : false;
		$this->logger->log( $search_field_id && $show_in_add_deal ? 'INFO' : 'ERROR', 'deal_field_metadata', $search_field_id && $show_in_add_deal ? 'Submission ID Custom Field metadata confirmed for Deal lookup.' : 'Deal lookup stopped: configured Submission ID Custom Field is not confirmed as Deal-usable.', $context + array( 'configured_custom_field_key' => $field_id, 'custom_field_id' => $search_field_id, 'custom_field_title' => $field_metadata['Title'] ?? '', 'custom_field_type' => $field_metadata['FieldType'] ?? '', 'control_type' => $field_metadata['ControlType'] ?? '', 'is_searchable' => $field_metadata['IsSearchable'] ?? null, 'show_in_add_deal' => $show_in_add_deal ) );
		if ( ! $search_field_id || ! $show_in_add_deal ) {
			return new WP_Error( 'didar_deal_lookup_failed', 'Configured Submission ID Custom Field is not Deal-usable.', array( 'trace_id' => $context['trace_id'], 'configured_custom_field_key' => $field_id ) );
		}
		$lookup_payload = array( 'Criteria' => array( 'IsDeleted' => false, 'CustomFields' => array( array( 'CustomFieldId' => $search_field_id, 'OR' => array( array( 'Type' => 'EqualToAny', 'Value' => array( (string) $submission_id ) ) ) ) ) ), 'From' => 0, 'Limit' => 100 );
		$this->logger->log( 'INFO', 'deal_lookup', 'Deal Submission ID lookup request built with the documented Custom Field identifier.', $context + array( 'lookup_strategy' => 'submission_id_custom_field', 'configured_custom_field_key' => $field_id, 'search_custom_field_id' => $search_field_id, 'endpoint' => '/api/deal/search_v2', 'request_payload' => $lookup_payload ) );
		$result = $this->api->search_deal( $lookup_payload['Criteria'], $lookup_payload['From'], $lookup_payload['Limit'] );
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'ERROR', 'deal_lookup_failed', 'Didar Deal lookup request failed; creation was intentionally stopped.', $context + array( 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message(), 'api_response' => $result->get_error_data(), 'lookup_strategy' => 'submission_id_custom_field', 'configured_custom_field_key' => $field_id, 'search_custom_field_id' => $search_field_id, 'endpoint' => '/api/deal/search_v2', 'request_payload' => $lookup_payload ) );
			return $result;
		}
		$matches = array();
		foreach ( $this->response_items( $result ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['Id'] ) ) { continue; }
			$value = $this->deal_custom_field_value( $item, array( $field_id, $search_field_id ) );
			if ( is_scalar( $value ) && (string) $value === (string) $submission_id ) { $matches[ sanitize_text_field( (string) $item['Id'] ) ] = true; }
		}
		$context['match_count'] = count( $matches );
		$this->logger->log( count( $matches ) > 1 ? 'WARNING' : 'INFO', 'deal_lookup', 'Exact WordPress Submission ID Deal match count: ' . count( $matches ) . '.', $context );
		if ( count( $matches ) > 1 ) { $this->logger->log( 'ERROR', 'deal_identity_conflict', 'Multiple Deals have the same exact WordPress Submission ID; Deal update/create stopped.', $context + array( 'candidate_deal_ids' => array_keys( $matches ) ) ); return new WP_Error( 'didar_deal_conflict', 'Multiple Didar Deals match this WordPress submission.', array( 'trace_id' => $context['trace_id'], 'candidate_count' => count( $matches ) ) ); }
		if ( 1 === count( $matches ) ) { $deal_id = (string) array_key_first( $matches ); update_post_meta( $submission_id, self::META_DEAL_ID, $deal_id ); $this->logger->log( 'INFO', 'deal_linked', 'Exact Deal linked to this WordPress submission and persisted locally.', $context + array( 'external_id' => $deal_id ) ); return $deal_id; }
		$this->logger->log( 'INFO', 'deal_create', 'No exact Deal matched this WordPress Submission ID; a new Deal will be created.', $context );
		return '';
	}

	private function deal_custom_field_value( $deal, $field_ids ) { $field_ids = array_filter( array_map( 'strval', (array) $field_ids ) ); $fields = isset( $deal['Fields'] ) && is_array( $deal['Fields'] ) ? $deal['Fields'] : array(); foreach ( $field_ids as $field_id ) { if ( array_key_exists( $field_id, $fields ) ) { return $fields[ $field_id ]; } } foreach ( $fields as $field ) { if ( ! is_array( $field ) ) { continue; } $candidate_id = (string) ( $field['CustomFieldId'] ?? $field['Key'] ?? '' ); if ( in_array( $candidate_id, $field_ids, true ) ) { return $field['Value'] ?? ''; } } return ''; }
	private function custom_field_metadata_by_key( $response, $key ) { $items = isset( $response['Response'] ) && is_array( $response['Response'] ) ? $response['Response'] : array(); foreach ( $items as $map_key => $item ) { if ( is_array( $item ) && (string) ( $item['Key'] ?? '' ) === (string) $key ) { return $item; } if ( (string) $map_key === (string) $key && is_array( $item ) ) { return $item; } } return array(); }
	private function wp_user_for_person( $person_id ) { $users = get_users( array( 'meta_key' => self::USER_PERSON_META, 'meta_value' => $person_id, 'number' => 1, 'fields' => 'ids' ) ); return ! empty( $users[0] ) ? absint( $users[0] ) : 0; }
	private function wp_user_for_didar( $didar_id ) { $settings = $this->settings->all(); foreach ( (array) ( $settings['didar_broker_user_map'] ?? array() ) as $wp => $didar ) { if ( (string) $didar === (string) $didar_id ) { return absint( $wp ); } } return 0; }
	private function didar_owner_for_wp_user( $user_id ) { $settings = $this->settings->all(); return $user_id && isset( $settings['didar_broker_user_map'][ $user_id ] ) ? sanitize_text_field( $settings['didar_broker_user_map'][ $user_id ] ) : ''; }
	private function internal_status( $post_id ) { $form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) ); $status = sanitize_key( (string) get_post_meta( $post_id, '_didar_internal_status', true ) ); return isset( $this->workflow->statuses( $form_type )[ $status ] ) ? $status : $this->workflow->default_status( $form_type, 'pending_review' ); }
	private function first_response_item( $response ) { if ( is_wp_error( $response ) || empty( $response['Response'] ) ) { return array(); } $value = $response['Response']; if ( isset( $value['List'][0] ) ) { return $value['List'][0]; } return isset( $value[0] ) ? $value[0] : ( is_array( $value ) ? $value : array() ); }
	private function response_object( $response ) { return isset( $response['Response'] ) && is_array( $response['Response'] ) ? $response['Response'] : array(); }
	private function enabled() { return $this->api->is_configured(); }
	private function state( $post_id ) { $state = get_post_meta( $post_id, self::META_STATE, true ); return is_array( $state ) ? $state : array( 'status' => 'new', 'attempts' => 0 ); }
	private function fail( $post_id, $error, $pending = false ) { $state = $this->state( $post_id ); $state['status'] = $pending ? 'pending' : 'failed'; $state['last_error'] = sanitize_key( $error ); $state['attempts'] = absint( $state['attempts'] ?? 0 ) + 1; $state['updated_at'] = time(); update_post_meta( $post_id, self::META_STATE, $state ); $this->events->add( $post_id, 'didar_sync_failed', null, null, array( 'source' => 'Didar', 'error' => sanitize_key( $error ), 'attempt' => $state['attempts'] ) ); $this->logger->log( $pending && $state['attempts'] < 10 ? 'WARNING' : 'ERROR', 'sync_failure', $pending && $state['attempts'] < 10 ? 'Sync failed; retry scheduled.' : 'Sync final failure.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'trace_id' => $state['trace_id'] ?? '', 'retry_count' => $state['attempts'], 'error_code' => $error ) ); if ( $pending && $state['attempts'] < 10 ) { $when = time() + min( HOUR_IN_SECONDS, 60 * max( 1, $state['attempts'] ) ); wp_schedule_single_event( $when, self::CRON_HOOK, array( $post_id ) ); $this->logger->log( 'INFO', 'retry_scheduled', 'Didar sync retry scheduled.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'trace_id' => $state['trace_id'] ?? '', 'retry_count' => $state['attempts'], 'retry_delay' => $when - time(), 'scheduled_at' => Didar_Logger::display_timestamp( $when, DATE_ATOM ) ) ); } return new WP_Error( sanitize_key( $error ), 'Didar synchronization failed.', array( 'trace_id' => $state['trace_id'] ?? '', 'retry_scheduled' => $pending && $state['attempts'] < 10 ) ); }
	private function success( $post_id, $deal_id, $internal_status = '' ) { $state = $this->state( $post_id ); $state['status'] = 'synced'; $state['last_error'] = ''; $state['last_synced_at'] = time(); $state['deal_id'] = $deal_id; $state['last_synced_internal_status'] = sanitize_key( (string) $internal_status ); update_post_meta( $post_id, self::META_STATE, $state ); return true; }
	private function log_user_state( $user_id, $status, $error ) { $state = get_user_meta( $user_id, '_didar_person_sync_state', true ); $state = is_array( $state ) ? $state : array(); $state['status'] = $status; $state['error'] = sanitize_key( $error ); $state['attempts'] = absint( $state['attempts'] ?? 0 ) + 1; $state['updated_at'] = time(); update_user_meta( $user_id, '_didar_person_sync_state', $state ); }
	private function queue_user_retry( $user_id ) { $state = get_user_meta( $user_id, '_didar_person_sync_state', true ); if ( is_array( $state ) && absint( $state['attempts'] ?? 0 ) < 10 ) { wp_schedule_single_event( time() + min( HOUR_IN_SECONDS, 60 * max( 1, absint( $state['attempts'] ) ) ), self::USER_HOOK, array( absint( $user_id ) ) ); } }
	private function seen_webhook( $event_id ) { $seen = get_option( 'didar_seen_webhooks', array() ); $seen = is_array( $seen ) ? $seen : array(); if ( isset( $seen[ $event_id ] ) ) { return true; } $seen[ $event_id ] = time(); if ( count( $seen ) > 500 ) { $seen = array_slice( $seen, -500, 500, true ); } update_option( 'didar_seen_webhooks', $seen, false ); return false; }
}
