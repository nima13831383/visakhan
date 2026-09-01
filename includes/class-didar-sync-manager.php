<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates asynchronous, idempotent WordPress ↔ Didar synchronization. */
class Didar_Sync_Manager {
	const WEBHOOK_RATE_LIMIT = 120;
	const WEBHOOK_RATE_WINDOW = 60;
	const CRON_HOOK = 'didar_process_sync';
	const USER_HOOK = 'didar_process_user_sync';
	const WORKER_SCHEDULE = 'didar_every_five_minutes';
	const LOCK_PREFIX = 'didar_submission_sync_lock_';
	const LOCK_TTL = 120;
	const META_DEAL_ID = '_didar_deal_id';
	const META_PERSON_ID = '_didar_person_id';
	const META_STATE = '_didar_sync_state';
	const META_COMPANION_CASES = '_didar_companion_cases';
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
	private $case_service;
	private $dates;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Event_Log $events, Didar_Submission_Service $service, Didar_File_Service $files, Didar_Logger $logger = null, Didar_Case_Service $case_service = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->events   = $events;
		$this->service  = $service;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $settings, $this->logger );
		$this->api      = new Didar_Api_Client( $settings, $this->logger );
		$this->mapper   = new Didar_Field_Mapper( $registry, $settings, $files, $this->logger );
		$this->case_service = $case_service ? $case_service : new Didar_Case_Service( $settings, $this->logger );
		$this->dates = new Didar_Date_Service();

		add_action( 'user_register', array( $this, 'queue_user' ), 20, 1 );
		// Digits writes its phone metadata after wp_create_user(), then fires this
		// action. The duplicate-safe queue makes the two hooks harmless together.
		add_action( 'register_new_user', array( $this, 'queue_user' ), 20, 1 );
		add_action( 'added_user_meta', array( $this, 'maybe_queue_user_mobile_change' ), 20, 4 );
		add_action( 'updated_user_meta', array( $this, 'maybe_queue_user_mobile_change' ), 20, 4 );
		add_action( 'didar_submission_created', array( $this, 'queue_submission' ), 20, 1 );
		add_action( 'didar_submission_updated', array( $this, 'queue_submission' ), 20, 1 );
		add_action( 'didar_submission_workflow_changed', array( $this, 'queue_submission' ), 20, 1 );
		add_action( self::CRON_HOOK, array( $this, 'process_submission' ), 10, 1 );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( self::USER_HOOK, array( $this, 'process_user' ), 10, 1 );
		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
		add_filter( 'pre_delete_post', array( $this, 'guard_submission_delete' ), 10, 3 );
		add_filter( 'pre_trash_post', array( $this, 'guard_submission_delete' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'record_submission_trash' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'record_submission_delete' ), 20, 2 );
	}

	/** Register the only additional interval needed for the durable submission sweep. */
	public function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::WORKER_SCHEDULE ] ) ) {
			$schedules[ self::WORKER_SCHEDULE ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Didar every five minutes' );
		}
		return $schedules;
	}

	/**
	 * Ensure a recurring no-argument variant of CRON_HOOK exists. Per-submission
	 * single events use the same hook with an ID argument; WP-Cron keeps them
	 * distinct. This sweep recovers durable pending post-meta jobs if any single
	 * event was lost or WP-Cron was unavailable when it was queued.
	 */
	public function ensure_worker_schedule() {
		$submission_ready = $this->ensure_recurring_hook( self::CRON_HOOK, 'submission' );
		$user_ready       = $this->ensure_recurring_hook( self::USER_HOOK, 'user' );
		return $submission_ready && $user_ready;
	}

	private function ensure_recurring_hook( $hook, $entity_type ) {
		if ( wp_next_scheduled( $hook ) ) {
			return true;
		}
		$result = wp_schedule_event( time() + MINUTE_IN_SECONDS, self::WORKER_SCHEDULE, $hook, array(), true );
		if ( is_wp_error( $result ) || false === $result ) {
			$this->logger->log( 'ERROR', 'sync_schedule_failed', 'Didar durable worker could not be scheduled.', array( 'entity_type' => $entity_type, 'source' => 'plugin_bootstrap', 'queue_job_id' => $hook, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed', 'error_message' => is_wp_error( $result ) ? $result->get_error_message() : 'wp_schedule_event returned false' ) );
			return false;
		}
		$this->logger->log( 'INFO', 'sync_worker_scheduled', 'Didar durable worker schedule was restored or created.', array( 'entity_type' => $entity_type, 'source' => 'plugin_bootstrap', 'queue_job_id' => $hook ) );
		return true;
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

	public function queue_user( $user_id, $source = 'user_register' ) {
		if ( ! $this->enabled() || ! absint( $user_id ) ) { return; }
		$user_id = absint( $user_id );
		$this->logger->log( 'INFO', 'didar_user_person_sync_started', 'WordPress User to Didar Person sync queued.', array( 'entity_type' => 'user', 'local_id' => $user_id, 'direction' => 'wordpress_to_didar', 'source' => sanitize_key( $source ) ) );
		if ( ! wp_next_scheduled( self::USER_HOOK, array( $user_id ) ) ) {
			$result = wp_schedule_single_event( time() + 5, self::USER_HOOK, array( $user_id ), true );
			if ( is_wp_error( $result ) || false === $result ) { $this->logger->log( 'ERROR', 'sync_schedule_failed', 'Didar Person sync dispatch could not be scheduled; the durable worker sweep will retry it.', array( 'entity_type' => 'user', 'local_id' => $user_id, 'source' => sanitize_key( $source ), 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed' ) ); }
		}
	}

	/** Digits has its own verified change-number workflows. Observe their canonical meta writes, never write those keys ourselves. */
	public function maybe_queue_user_mobile_change( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( ! in_array( (string) $meta_key, array( 'digits_phone', 'digits_phone_no', 'digt_countrycode' ), true ) ) {
			return;
		}
		$this->queue_user( absint( $user_id ), 'digits_mobile_meta' );
	}

	/** Public entry point for the frontend profile module; the actual Person logic remains centralized here. */
	public function sync_user_now( $user_id, $source = 'profile_form' ) {
		$result = $this->process_user( absint( $user_id ), $source );
		if ( is_wp_error( $result ) ) {
			$this->queue_user_retry( absint( $user_id ) );
		}
		return $result;
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
		if ( false === update_post_meta( $post_id, self::META_STATE, $state ) && ! metadata_exists( 'post', $post_id, self::META_STATE ) ) {
			$this->log_queue_failure( $post_id, $state, 'queue_persist_failed', 'The durable submission sync state could not be saved.' );
			return;
		}
		$this->logger->log( 'INFO', 'sync_queue_persisted', 'Submission sync state was durably persisted.', $this->sync_context( $post_id, $state ) );
		$this->ensure_worker_schedule();
		if ( ! $this->schedule_submission( $post_id, time(), 'submission_hook' ) ) {
			return;
		}
		// Ask WordPress to spawn the due single event now. This is asynchronous and
		// bounded by core's cron lock; the recurring worker remains the fallback.
		$this->logger->log( 'INFO', 'sync_immediate_started', 'Immediate best-effort sync dispatch started after durable queue persistence.', $this->sync_context( $post_id, $state ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
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

	public function process_user( $user_id = 0, $source = 'wp_cron' ) {
		if ( ! absint( $user_id ) ) {
			return $this->process_pending_users();
		}
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user || ! $this->enabled() ) { return new WP_Error( 'didar_user_sync_unavailable', 'User or Didar configuration is unavailable.' ); }
		$settings = $this->settings->all();
		if ( empty( $settings['didar_default_owner_id'] ) ) { $this->log_user_state( $user->ID, 'pending', 'didar_default_owner_missing' ); $this->queue_user_retry( $user->ID ); return new WP_Error( 'didar_default_owner_missing', 'Didar default owner is missing.' ); }
		if ( ! $this->mapper->wordpress_user_profile( $user )['mobile'] ) { $this->log_user_state( $user->ID, 'pending', 'didar_mobile_missing' ); $this->queue_user_retry( $user->ID ); return new WP_Error( 'didar_mobile_missing', 'Digits mobile is not available yet.' ); }
		$trace = Didar_Logger::trace_id( '' ); $this->api->set_trace_id( $trace );
		$result = $this->resolve_and_sync_person( $user, array(), '', 0, $trace );
		if ( is_wp_error( $result ) ) { $status = 'didar_person_conflict' === $result->get_error_code() ? 'conflict' : 'pending'; $this->log_user_state( $user->ID, $status, $result->get_error_code() ); if ( 'conflict' !== $status ) { $this->queue_user_retry( $user->ID ); } return $result; }
		$this->log_user_state( $user->ID, 'synced', '' );
		$this->clear_user_retries( $user->ID );
		return $result;
	}

	/** Sweep durable pending submissions in bounded batches; individual events remain faster-path dispatch. */
	private function process_pending_submissions() {
		$this->logger->log( 'INFO', 'sync_worker_started', 'Durable submission sync worker started.', array( 'entity_type' => 'submission', 'source' => 'wp_cron' ) );
		$query = new WP_Query( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 10, 'no_found_rows' => true, 'meta_key' => self::META_STATE, 'meta_value' => 'pending', 'meta_compare' => 'LIKE', 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $query->posts as $post_id ) {
			$this->process_submission( absint( $post_id ) );
		}
		return true;
	}

	/** Sweep retryable Person state too, so a missed one-off user event self-recovers. */
	private function process_pending_users() {
		$query = new WP_User_Query( array( 'fields' => 'ID', 'number' => 10, 'meta_key' => '_didar_person_sync_state', 'meta_value' => 'pending', 'meta_compare' => 'LIKE' ) );
		foreach ( (array) $query->get_results() as $user_id ) {
			$this->process_user( absint( $user_id ), 'wp_cron_sweep' );
		}
		return true;
	}

	public function process_submission( $post_id = 0 ) {
		if ( ! absint( $post_id ) ) {
			return $this->process_pending_submissions();
		}
		$post = get_post( absint( $post_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! $this->enabled() ) {
			$this->logger->log( 'WARNING', 'sync_skipped', 'Submission sync stopped before execution.', array( 'entity_type' => 'submission', 'local_id' => absint( $post_id ), 'source' => 'sync_execution', 'skip_reason' => ! $post ? 'missing_post' : ( Didar_Post_Type::POST_TYPE !== $post->post_type ? 'invalid_post_type' : 'didar_api_not_configured' ) ) );
			return new WP_Error( 'didar_sync_stopped', 'Sync stopped before execution.' );
		}
		$lock = $this->acquire_submission_lock( $post->ID );
		if ( ! $lock ) {
			$this->logger->log( 'INFO', 'sync_locked', 'Submission sync skipped because another request owns the active lock.', $this->sync_context( $post->ID, $this->state( $post->ID ) ) );
			return new WP_Error( 'didar_sync_locked', 'Submission sync is already in progress.' );
		}
		try {
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
			'Description' => $this->registry->supports_applicant_note( $form_type ) ? $this->service->get_shared_note( $post->ID ) : '',
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
		if ( ! $owner && ! empty( $settings['didar_default_owner_id'] ) ) { $owner = $this->canonical_didar_user_id( $settings['didar_default_owner_id'] ); }
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
		$this->sync_companion_cases( $post->ID, $form_type, $fields, $deal_id, $person_id, $trace );
		return true;
		} finally {
			$this->release_submission_lock( $post->ID, $lock );
		}
	}

	public function register_webhook_route() {
		$this->settings->ensure_webhook_secret();
		register_rest_route( 'didar/v1', '/webhook/(?P<secret>[a-f0-9]{64})', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'receive_webhook' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'didar/v1', '/webhook', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'receive_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Companion rows are synchronized only after the parent Deal has a durable ID. */
	private function sync_companion_cases( $post_id, $form_type, &$fields, $deal_id, $person_id, $trace ) {
		if ( 'visa_request' !== $form_type ) return true;
		$settings = $this->settings->all();
		$config = isset( $settings['visa_companion_case_settings'] ) && is_array( $settings['visa_companion_case_settings'] ) ? $settings['visa_companion_case_settings'] : array();
		$pipeline_id = sanitize_text_field( (string) ( $config['pipeline_id'] ?? '' ) );
		$stage_id = sanitize_text_field( (string) ( $config['initial_stage_id'] ?? '' ) );
		$mappings = isset( $config['field_mappings'] ) && is_array( $config['field_mappings'] ) ? $config['field_mappings'] : array();
		$validation = $this->case_service->validate_companion_case_configuration( $config );
		if ( ! $validation['ready'] ) {
			$this->logger->log( 'WARNING', 'case_sync_skipped', 'Visa companion Case sync skipped because Case settings are incomplete or stale.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'form_type' => $form_type, 'trace_id' => $trace, 'skip_reason' => 'case_configuration_' . $validation['status'], 'configuration_issues' => $validation['issues'] ) );
			return true;
		}
		$rows = isset( $fields['companions'] ) && is_array( $fields['companions'] ) ? $fields['companions'] : array();
		$links = get_post_meta( $post_id, self::META_COMPANION_CASES, true ); $links = is_array( $links ) ? $links : array();
		$system = isset( $config['system_fields'] ) && is_array( $config['system_fields'] ) ? $config['system_fields'] : array(); $active_uids = array();
		foreach ( $rows as $index => &$row ) {
			if ( ! is_array( $row ) ) $row = array();
			$uid = isset( $row['companion_uid'] ) && is_scalar( $row['companion_uid'] ) ? sanitize_text_field( (string) $row['companion_uid'] ) : '';
			if ( ! preg_match( '/^cmp_[a-f0-9-]{16,}$/', $uid ) || isset( $active_uids[ $uid ] ) ) { $uid = 'cmp_' . wp_generate_uuid4(); $row['companion_uid'] = $uid; }
			$active_uids[ $uid ] = true;
			$case_id = sanitize_text_field( (string) ( $links[ $uid ]['case_id'] ?? '' ) );
			if ( ! $case_id && ! empty( $system['submission_id'] ) && ! empty( $system['companion_uid'] ) ) {
				$submission_field = $this->case_service->case_field( $system['submission_id'] ); $uid_field = $this->case_service->case_field( $system['companion_uid'] );
				if ( $submission_field && $uid_field ) { $lookup = $this->case_service->search( array( 'IsDeleted' => false, 'DealId' => $deal_id, 'CustomFields' => array( array( 'CustomFieldId' => $submission_field['id'], 'OR' => array( array( 'Type' => 'EqualToAny', 'Value' => array( (string) $post_id ) ) ) ), array( 'CustomFieldId' => $uid_field['id'], 'OR' => array( array( 'Type' => 'EqualToAny', 'Value' => array( $uid ) ) ) ) ) ), 0, 10 ); if ( is_wp_error( $lookup ) ) { $links[ $uid ] = array( 'case_id' => '', 'status' => 'pending', 'last_error' => sanitize_key( $lookup->get_error_code() ), 'updated_at' => time() ); $this->logger->log( 'WARNING', 'case_resolution_failed', 'Exact companion Case lookup failed; creation was withheld until the lookup can be retried.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'companion_uid' => $uid, 'deal_id' => $deal_id, 'trace_id' => $trace, 'error_code' => $lookup->get_error_code() ) ); continue; } $matches = array(); foreach ( $this->response_items( $lookup ) as $item ) { if ( is_array( $item ) && ! empty( $item['Id'] ) && (string) ( $item['DealId'] ?? $deal_id ) === (string) $deal_id ) $matches[ sanitize_text_field( $item['Id'] ) ] = true; } if ( 1 === count( $matches ) ) $case_id = (string) array_key_first( $matches ); elseif ( count( $matches ) > 1 ) { $this->logger->log( 'ERROR', 'case_identity_conflict', 'Multiple exact Case matches were found; Case creation stopped.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'companion_uid' => $uid, 'deal_id' => $deal_id, 'trace_id' => $trace, 'match_count' => count( $matches ) ) ); continue; } }
			}
			$case = array( 'Id' => $case_id, 'Title' => $this->companion_case_title( $row, $post_id ), 'PipelineStageId' => $stage_id, 'DealId' => $deal_id, 'Status' => 'InProgress', 'Fields' => $this->mapper->companion_case_fields( $form_type, $row, $index, $post_id, $mappings ) );
			$case_category_id = sanitize_text_field( (string) ( $config['category_id'] ?? '' ) );
			if ( $case_category_id ) $case['CaseCategoryId'] = $case_category_id;
			if ( ! $case_id ) unset( $case['Id'] );
			foreach ( array( 'submission_id' => 'Submission ID', 'companion_uid' => 'Companion UID', 'form_type' => 'Form Type' ) as $system_key => $label ) { $target = sanitize_text_field( (string) ( $system[ $system_key ] ?? '' ) ); if ( $target ) $case['Fields'][ $target ] = 'submission_id' === $system_key ? (string) $post_id : ( 'companion_uid' === $system_key ? $uid : $form_type ); }
			$this->logger->log( 'INFO', $case_id ? 'case_update_started' : 'case_create_started', 'Visa companion Case synchronization started.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'external_id' => $case_id, 'companion_uid' => $uid, 'deal_id' => $deal_id, 'trace_id' => $trace ) );
			$result = $this->case_service->save( $case );
			if ( is_wp_error( $result ) ) { $links[ $uid ] = array( 'case_id' => $case_id, 'status' => 'pending', 'last_error' => sanitize_key( $result->get_error_code() ), 'updated_at' => time() ); $this->logger->log( 'WARNING', 'case_sync_failed', 'Visa companion Case synchronization failed; the parent submission remains Deal-synced and this companion remains retryable.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'external_id' => $case_id, 'companion_uid' => $uid, 'deal_id' => $deal_id, 'trace_id' => $trace, 'error_code' => $result->get_error_code() ) ); continue; }
			$response = $this->response_object( $result ); if ( empty( $response['Id'] ) && isset( $response['List'][0]['Id'] ) ) $response = $response['List'][0]; $resolved_id = $case_id ?: sanitize_text_field( (string) ( $response['Id'] ?? '' ) );
			if ( ! $resolved_id ) { $links[ $uid ] = array( 'case_id' => '', 'status' => 'pending', 'last_error' => 'case_id_missing', 'updated_at' => time() ); continue; }
			$links[ $uid ] = array( 'case_id' => $resolved_id, 'status' => 'synced', 'last_error' => '', 'updated_at' => time() ); $this->logger->log( 'INFO', $case_id ? 'case_updated' : 'case_created', 'Visa companion Case synchronized and linked locally.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'external_id' => $resolved_id, 'companion_uid' => $uid, 'deal_id' => $deal_id, 'trace_id' => $trace ) );
		}
		unset( $row );
		// The working rows array is a copy; persist generated stable UIDs back into the submission fields.
		$fields['companions'] = $rows;
		foreach ( $links as $known_uid => &$known_link ) { if ( ! isset( $active_uids[ $known_uid ] ) && is_array( $known_link ) && 'removed' !== ( $known_link['status'] ?? '' ) ) { $known_link['status'] = 'removed'; $known_link['removed_at'] = time(); $this->logger->log( 'WARNING', 'case_removed_remote_untouched', 'A companion was removed locally; the remote Case was left untouched because no official delete/archive endpoint is confirmed.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'external_id' => $known_link['case_id'] ?? '', 'companion_uid' => sanitize_text_field( $known_uid ), 'trace_id' => $trace, 'official_support' => 'not_confirmed' ) ); } } unset( $known_link );
		update_post_meta( $post_id, '_didar_fields', $fields ); update_post_meta( $post_id, self::META_COMPANION_CASES, $links );
		$pending_cases = false; foreach ( $links as $uid => $link ) { if ( is_array( $link ) && 'removed' === ( $link['status'] ?? '' ) ) continue; if ( ! isset( $link['status'] ) || 'synced' !== $link['status'] ) { $pending_cases = true; $this->logger->log( 'WARNING', 'case_retry_scheduled', 'A companion Case remains pending for the next canonical submission sync.', array( 'entity_type' => 'case', 'local_id' => absint( $post_id ), 'companion_uid' => sanitize_text_field( $uid ), 'trace_id' => $trace ) ); } } if ( $pending_cases ) $this->schedule_submission( $post_id, time() + 60, 'case_retry' );
		return true;
	}

	private function companion_case_title( $row, $post_id ) { $name = isset( $row['full_name'] ) && is_scalar( $row['full_name'] ) ? trim( sanitize_text_field( (string) $row['full_name'] ) ) : ''; return $name ? 'همراه - ' . $name : 'همراه درخواست #' . absint( $post_id ); }

	public function receive_webhook( WP_REST_Request $request ) {
		$settings = $this->settings->all();
		$route_params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : array();
		$route_secret = isset( $route_params['secret'] ) ? (string) $route_params['secret'] : '';
		$configured   = isset( $settings['didar_webhook_secret'] ) ? (string) $settings['didar_webhook_secret'] : '';
		$legacy       = empty( $route_secret );
		$authenticated = false;
		if ( ! $legacy && '' !== $configured && hash_equals( $configured, $route_secret ) ) {
			$authenticated = true;
		}
		if ( $legacy && ! empty( $settings['didar_webhook_legacy_enabled'] ) ) {
			$provided = (string) $request->get_header( 'x-didar-webhook-token' );
			$authenticated = '' !== $configured && '' !== $provided && hash_equals( $configured, $provided );
		}
		if ( ! $authenticated ) { $this->logger->log( 'WARNING', 'webhook_authentication', 'Didar webhook authentication failed.', array( 'direction' => 'didar_to_wordpress', 'source' => 'webhook', 'route_secret_present' => $legacy ? 'no' : 'yes' ) ); return new WP_Error( 'didar_webhook_unauthorized', __( 'وب‌هوک دیدار احراز نشد.', 'didar' ), array( 'status' => 401 ) ); }
		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( 0 !== strpos( $content_type, 'application/json' ) ) { return new WP_Error( 'didar_webhook_content_type', __( 'نوع محتوای وب‌هوک باید JSON باشد.', 'didar' ), array( 'status' => 415 ) ); }
		if ( $this->webhook_rate_limited() ) { $this->logger->log( 'WARNING', 'webhook_rate_limited', 'Didar webhook rate limit exceeded.', array( 'direction' => 'didar_to_wordpress', 'source' => 'webhook' ) ); return new WP_Error( 'didar_webhook_rate_limited', __( 'تعداد درخواست‌های وب‌هوک بیش از حد مجاز است.', 'didar' ), array( 'status' => 429 ) ); }
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) || ! isset( $payload['meta'] ) || ! is_array( $payload['meta'] ) ) { return new WP_Error( 'didar_webhook_invalid', __( 'ساختار وب‌هوک دیدار معتبر نیست.', 'didar' ), array( 'status' => 400 ) ); }
		$meta = $payload['meta'];
		if ( empty( $meta['id'] ) || empty( $meta['entityId'] ) || empty( $meta['entityTitle'] ) ) { return new WP_Error( 'didar_webhook_invalid', __( 'اطلاعات ضروری وب‌هوک دیدار ناقص است.', 'didar' ), array( 'status' => 400 ) ); }
		$event_id = isset( $meta['id'] ) ? sanitize_text_field( (string) $meta['id'] ) : md5( wp_json_encode( $payload ) );
		if ( $this->seen_webhook( $event_id ) ) { $this->logger->log( 'INFO', 'webhook_deduplication', 'Duplicate Didar webhook ignored.', array( 'direction' => 'didar_to_wordpress', 'webhook_event_id' => $event_id, 'source' => 'webhook' ) ); return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 ); }
		$entity = sanitize_key( isset( $meta['entityTitle'] ) ? (string) $meta['entityTitle'] : '' );
		$action = isset( $meta['actionType'] ) && is_scalar( $meta['actionType'] ) ? (int) $meta['actionType'] : 0;
		if ( ! $this->webhook_event_key( $entity, $action ) ) { return new WP_Error( 'didar_webhook_unsupported', __( 'رویداد یا موجودیت وب‌هوک دیدار پشتیبانی نمی‌شود.', 'didar' ), array( 'status' => 422 ) ); }
		$this->logger->log( 'INFO', 'webhook_authenticated', 'Didar webhook authenticated and accepted.', array( 'direction' => 'didar_to_wordpress', 'webhook_event_id' => $event_id, 'entity_type' => $entity, 'external_id' => $payload['meta']['entityId'] ?? '', 'source' => 'webhook' ) );
		if ( false !== strpos( $entity, 'deal' ) || 'deal' === $entity || 'معامله' === $entity ) { $this->apply_deal_webhook( $payload, $event_id ); }
		if ( false !== strpos( $entity, 'person' ) || 'person' === $entity || 'شخص' === $entity ) { $this->apply_person_webhook( $payload, $event_id ); }
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private function webhook_event_key( $entity, $action ) {
		$entity = sanitize_key( (string) $entity ); $action = (int) $action;
		$is_deal = false !== strpos( $entity, 'deal' ) || 'معامله' === $entity; $is_person = false !== strpos( $entity, 'person' ) || 'شخص' === $entity;
		if ( ! $is_deal && ! $is_person ) { return ''; }
		return ( $is_deal ? 'deal_' : 'person_' ) . ( 1 === $action ? 'created' : ( 2 === $action ? 'updated' : '' ) );
	}

	private function webhook_rate_limited() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'didar_webhook_rate_' . md5( $ip ); $count = (int) get_transient( $key );
		if ( $count >= self::WEBHOOK_RATE_LIMIT ) { return true; }
		set_transient( $key, $count + 1, self::WEBHOOK_RATE_WINDOW ); return false;
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
		$deal_id = sanitize_text_field( (string) get_post_meta( $post_id, self::META_DEAL_ID, true ) );
		$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
		$old = $this->service->get_fields( $post_id );
		$new = $old;
		$changed_field_keys = array();
		$fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		foreach ( $this->registry->fields( $form_type ) as $key => $definition ) {
			$map = $this->mapper->mapping( $form_type, $key );
			if ( 'deal_custom' === $map['target'] && $map['field'] && $this->mapper->is_structured_field( $definition ) && array_key_exists( $map['field'], $fields ) ) {
				$this->logger->log( 'INFO', 'didar_structured_field_inbound_ignored', 'Readable structured field text was not parsed back into local structured data.', array( 'entity_type' => 'deal', 'local_id' => $post_id, 'form_type' => $form_type, 'field_key' => $key, 'deal_field_key' => $map['field'], 'source' => 'didar_webhook' ) );
				continue;
			}
			if ( 'deal_custom' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $fields ) ) {
				$value = $fields[ $map['field'] ];
				if ( 'date' === (string) ( $definition['type'] ?? '' ) || 'date' === (string) ( $definition['semantic'] ?? '' ) ) {
					$raw_value = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( ! is_scalar( $value ) ) {
						$this->logger->log( 'ERROR', 'didar_date_deserialization_invalid', 'An inbound DATE custom field was not scalar; the existing local value was preserved.', array( 'entity_type' => 'deal', 'local_id' => $post_id, 'form_type' => $form_type, 'field_key' => $key, 'deal_field_key' => $map['field'], 'source' => 'didar_webhook' ) );
						continue;
					}
					if ( '' !== $raw_value ) {
						$value = $this->dates->normalize_input( $raw_value );
						if ( '' === $value ) {
							$this->logger->log( 'ERROR', 'didar_date_deserialization_invalid', 'An inbound DATE custom field was invalid; the existing local value was preserved.', array( 'entity_type' => 'deal', 'local_id' => $post_id, 'form_type' => $form_type, 'field_key' => $key, 'deal_field_key' => $map['field'], 'source' => 'didar_webhook' ) );
							continue;
						}
					}
				}
				$new[ $key ] = $value; $changed_field_keys[] = $key;
			}
			if ( 'deal_native' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $data ) ) { $new[ $key ] = $data[ $map['field'] ]; $changed_field_keys[] = $key; }
		}
		$meaningful_change = $new !== $old;
		if ( $new !== $old ) { update_post_meta( $post_id, '_didar_fields', $new ); }
		$stage = isset( $data['PipelineStageId'] ) ? sanitize_text_field( $data['PipelineStageId'] ) : '';
		$pipeline = isset( $data['PipelineId'] ) ? sanitize_text_field( $data['PipelineId'] ) : '';
		$status = $this->workflow->reverse_mapping( $form_type, $pipeline, $stage );
		if ( $status ) {
			$old_status = (string) get_post_meta( $post_id, '_didar_internal_status', true );
			if ( $old_status !== $status ) { $meaningful_change = true; update_post_meta( $post_id, '_didar_internal_status', $status ); $this->events->add( $post_id, 'internal_status_changed', $old_status, $status, array( 'form_type' => $form_type, 'old_status_key' => $old_status, 'old_status_label' => $this->workflow->status_label( $form_type, $old_status ), 'new_status_key' => $status, 'new_status_label' => $this->workflow->status_label( $form_type, $status ), 'pipeline_id' => $pipeline, 'pipeline_stage_id' => $stage, 'source' => 'didar', 'actor' => 0 ) ); }
		} elseif ( $stage ) {
			$this->logger->log( 'WARNING', 'workflow_mapping_conflict', 'Didar webhook stage does not match this form workflow; local status was not changed.', array( 'form_type' => $form_type, 'local_id' => $post_id, 'pipeline_id' => $pipeline, 'pipeline_stage_id' => $stage, 'source' => 'didar_webhook' ) );
		}
		$settings = $this->settings->all(); $public_field = isset( $settings['didar_public_status_field_id'] ) ? sanitize_text_field( $settings['didar_public_status_field_id'] ) : ''; if ( $public_field && isset( $fields[ $public_field ] ) && isset( Didar_Reference_Data::statuses()[ sanitize_key( $fields[ $public_field ] ) ] ) ) { $public_status = sanitize_key( $fields[ $public_field ] ); if ( $public_status !== (string) get_post_meta( $post_id, '_didar_public_status', true ) ) { $meaningful_change = true; } update_post_meta( $post_id, '_didar_public_status', $public_status ); update_post_meta( $post_id, '_didar_status', $public_status ); }
		$owner = isset( $data['OwnerId'] ) ? $this->wp_user_for_didar( $data['OwnerId'] ) : 0;
		if ( $owner ) { if ( (int) get_post_meta( $post_id, '_didar_assigned_user_id', true ) !== (int) $owner ) { $meaningful_change = true; } update_post_meta( $post_id, '_didar_assigned_user_id', $owner ); }
		$this->events->add( $post_id, 'didar_webhook_received', $old, $new, array( 'source' => 'Didar', 'event_id' => $event_id, 'entity_id' => isset( $data['Id'] ) ? $data['Id'] : '', 'request_snapshot_only' => true, 'meaningful_request_change' => $meaningful_change ) );
		$this->logger->log( 'INFO', 'webhook_apply', 'Didar Deal webhook updated request snapshot fields only; WordPress user profile was not modified.', array( 'direction' => 'didar_to_wordpress', 'entity_type' => 'deal', 'external_id' => $deal_id, 'local_id' => $post_id, 'wp_user_id' => get_post_field( 'post_author', $post_id ), 'form_type' => $form_type, 'webhook_event_id' => $event_id, 'source' => 'didar_webhook', 'changed_field_keys' => array_values( array_unique( $changed_field_keys ) ) ) );
	}

	private function mapped_submission_fields( $form_type, $data ) {
		$out = array(); $fields = isset( $data['Fields'] ) && is_array( $data['Fields'] ) ? $data['Fields'] : array();
		foreach ( $this->registry->fields( $form_type ) as $key => $definition ) {
			$map = $this->mapper->mapping( $form_type, $key );
			if ( 'deal_custom' === $map['target'] && $map['field'] && array_key_exists( $map['field'], $fields ) ) {
				$value = $this->sanitize_external_value( $fields[ $map['field'] ], $definition );
				if ( 'date' === (string) ( $definition['type'] ?? '' ) || 'date' === (string) ( $definition['semantic'] ?? '' ) ) {
					if ( '' !== (string) $value ) {
						$value = $this->dates->normalize_input( $value );
						if ( '' === $value ) {
							$this->logger->log( 'ERROR', 'didar_date_deserialization_invalid', 'An inbound DATE custom field was invalid; the local submission value was omitted.', array( 'entity_type' => 'deal', 'form_type' => $form_type, 'field_key' => $key, 'deal_field_key' => $map['field'], 'source' => 'didar_webhook' ) );
							continue;
						}
					}
				}
				$out[ $key ] = $value;
			}
		}
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
			$detail = $this->api->person_by_id( $stored_id );
			if ( is_wp_error( $detail ) ) {
				if ( ! $this->is_person_not_found_error( $detail ) ) {
					// A network/API failure is not evidence that the link is stale.
					return $detail;
				}
				delete_user_meta( $user->ID, self::USER_PERSON_META );
				$stored_id = '';
				$this->logger->log( 'WARNING', 'didar_user_person_stale_link', 'Stored Didar Person ID no longer exists; falling back to exact mobile lookup.', array( 'wp_user_id' => $user->ID, 'trace_id' => $trace ) );
			}
		}
		if ( $stored_id ) {
			// A mobile change may not silently move this WordPress user to another
			// Person. Resolve the canonical mobile first and stop on conflict.
			$mobile_match = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace );
			if ( is_wp_error( $mobile_match ) ) {
				return $mobile_match;
			}
			if ( $mobile_match && $mobile_match !== $stored_id ) {
				$this->logger->log( 'ERROR', 'didar_user_person_mobile_conflict', 'Canonical mobile is owned by another Didar Person; existing user link was preserved.', array( 'wp_user_id' => $user->ID, 'external_id' => $stored_id, 'candidate_person_id' => $mobile_match, 'trace_id' => $trace ) );
				return new WP_Error( 'didar_person_conflict', 'The mobile number belongs to another Didar Person.', array( 'trace_id' => $trace ) );
			}
			$this->logger->log( 'INFO', 'didar_user_person_found_by_id', 'Stored Didar Person ID was verified and retained.', array( 'wp_user_id' => $user->ID, 'external_id' => $stored_id, 'trace_id' => $trace ) );
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
		if ( is_wp_error( $result ) && $stored_id && $this->is_person_not_found_error( $result ) ) {
			// The local link is stale. A retry lookup is safe; a network error is
			// never treated as an absent Person and never creates a duplicate.
			$resolved = $this->find_exact_person_id( $payload, $user->ID, $submission_id, $form_type, $trace );
			if ( is_wp_error( $resolved ) ) { return $resolved; }
			if ( $resolved ) { $payload['Id'] = $resolved; $person_id = $resolved; $result = $this->api->save_person( $payload ); }
		}
		if ( is_wp_error( $result ) ) { $this->logger->log( 'ERROR', 'didar_user_person_sync_failed', 'Person create/update failed.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'error_code' => $result->get_error_code() ) ); return $result; }
		$response = $this->response_object( $result ); $person_id = $person_id ?: ( isset( $response['Id'] ) ? sanitize_text_field( $response['Id'] ) : '' );
		if ( ! $person_id ) { return new WP_Error( 'didar_person_id_missing', 'Didar Person ID was not returned.', array( 'trace_id' => $trace ) ); }
		update_user_meta( $user->ID, self::USER_PERSON_META, $person_id );
		$this->logger->log( 'INFO', $creating ? 'didar_user_person_created' : 'didar_user_person_updated', $creating ? 'New Didar Person created from WordPress user profile.' : 'Didar Person profile synchronized from WordPress user profile.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user->ID, 'external_id' => $person_id, 'wp_user_id' => $user->ID, 'form_type' => $form_type, 'trace_id' => $trace, 'person_source' => 'wordpress_user_profile' ) );
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

		$this->logger->log( 'INFO', 'person_create', 'No Person matched authoritative WordPress user profile; creating the account Person.', $context + array( 'person_payload_fields' => array_keys( $payload ) ) );
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
		$mobile = $this->mapper->normalize_mobile( $payload['MobilePhone'] ?? '' );
		if ( ! $mobile ) {
			return new WP_Error( 'didar_mobile_missing', 'A normalized mobile is required for Person resolution.', array( 'trace_id' => $trace ) );
		}
		$matches = array();
		foreach ( $this->mapper->mobile_lookup_variants( $mobile ) as $variant ) {
			$result = $this->api->person_by_mobile( $variant );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( $this->response_items( $result ) as $person ) {
				if ( ! is_array( $person ) || empty( $person['Id'] ) ) { continue; }
				$candidate = $this->mapper->normalize_mobile( $person['MobilePhone'] ?? ( $person['PhoneNumber'] ?? $variant ) );
				if ( $candidate === $mobile ) { $matches[ sanitize_text_field( (string) $person['Id'] ) ] = true; }
			}
		}
		$matches = array_keys( $matches );
		$this->logger->log( count( $matches ) > 1 ? 'WARNING' : 'INFO', 'didar_user_person_found_by_mobile', 'Exact normalized mobile lookup completed.', array( 'wp_user_id' => $user_id, 'local_id' => $submission_id ?: $user_id, 'form_type' => $form_type, 'trace_id' => $trace, 'match_count' => count( $matches ), 'recovery' => (bool) $recovery ) );
		if ( 1 === count( $matches ) ) { return $matches[0]; }
		return count( $matches ) > 1 ? $this->person_conflict( 'Multiple exact mobile matches found.', $matches, $user_id, $submission_id, $form_type, $trace ) : '';
	}

	private function person_conflict( $message, $matches, $user_id, $submission_id, $form_type, $trace ) { $this->logger->log( 'ERROR', 'person_conflict', 'Ambiguous Person conflict; Deal creation stopped.', array( 'entity_type' => 'person', 'local_id' => $submission_id ?: $user_id, 'wp_user_id' => $user_id, 'form_type' => $form_type, 'trace_id' => $trace, 'match_count' => count( $matches ), 'candidate_person_ids' => $matches ) ); return new WP_Error( 'didar_person_conflict', $message, array( 'trace_id' => $trace, 'candidate_count' => count( $matches ) ) ); }
	private function is_duplicate_contact_error( $error ) { $data = $error->get_error_data(); return (bool) preg_match( '/duplicate\s+contacts\s+is\s+not\s+allowed/i', $error->get_error_message() . ' ' . wp_json_encode( $data ) ); }
	private function response_items( $response ) { $value = isset( $response['Response'] ) ? $response['Response'] : array(); if ( isset( $value['List'] ) && is_array( $value['List'] ) ) { return $value['List']; } if ( isset( $value['Items'] ) && is_array( $value['Items'] ) ) { return $value['Items']; } if ( isset( $value[0] ) ) { return $value; } return is_array( $value ) && isset( $value['Id'] ) ? array( $value ) : array(); }
	private function normalize_mobile( $value ) { $value = strtr( trim( (string) $value ), array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) ); $value = preg_replace( '/\D+/', '', $value ); return '00' === substr( $value, 0, 2 ) ? substr( $value, 2 ) : $value; }
	private function normalize_email( $value ) { return strtolower( trim( sanitize_email( (string) $value ) ) ); }
	private function is_person_not_found_error( $error ) { $data = $error->get_error_data(); return is_array( $data ) && 404 === absint( $data['status'] ?? 0 ); }

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
	private function didar_owner_for_wp_user( $user_id ) { $settings = $this->settings->all(); $value = $user_id && isset( $settings['didar_broker_user_map'][ $user_id ] ) ? sanitize_text_field( $settings['didar_broker_user_map'][ $user_id ] ) : ''; return $this->canonical_didar_user_id( $value, $user_id ); }
	private function canonical_didar_user_id( $value, $wp_user_id = 0 ) { $value = sanitize_text_field( (string) $value ); if ( ! $value ) { return ''; } if ( $this->workflow->didar_user_by_user_id( $value ) ) { return $value; } $legacy = $this->workflow->didar_user_by_id( $value ); if ( $legacy && ! empty( $legacy['user_id'] ) ) { $this->logger->log( 'WARNING', 'didar_user_mapping_stale', 'Legacy Didar Id was resolved to canonical UserId at runtime.', array( 'wp_user_id' => absint( $wp_user_id ), 'legacy_didar_id' => $value, 'didar_user_id' => $legacy['user_id'] ) ); return $legacy['user_id']; } return $value; }
	private function internal_status( $post_id ) { $form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) ); $status = sanitize_key( (string) get_post_meta( $post_id, '_didar_internal_status', true ) ); return isset( $this->workflow->statuses( $form_type )[ $status ] ) ? $status : $this->workflow->default_status( $form_type, 'pending_review' ); }
	private function first_response_item( $response ) { if ( is_wp_error( $response ) || empty( $response['Response'] ) ) { return array(); } $value = $response['Response']; if ( isset( $value['List'][0] ) ) { return $value['List'][0]; } return isset( $value[0] ) ? $value[0] : ( is_array( $value ) ? $value : array() ); }
	private function response_object( $response ) { return isset( $response['Response'] ) && is_array( $response['Response'] ) ? $response['Response'] : array(); }
	private function enabled() { return $this->api->is_configured(); }
	private function state( $post_id ) { $state = get_post_meta( $post_id, self::META_STATE, true ); return is_array( $state ) ? $state : array( 'status' => 'new', 'attempts' => 0 ); }
	private function sync_context( $post_id, $state = array() ) { return array( 'entity_type' => 'submission', 'local_id' => absint( $post_id ), 'form_type' => get_post_meta( $post_id, '_didar_form_type', true ), 'trace_id' => $state['trace_id'] ?? '', 'source' => 'submission_hook' ); }
	private function log_queue_failure( $post_id, $state, $code, $message ) { $this->logger->log( 'ERROR', 'sync_queue_failed', $message, $this->sync_context( $post_id, $state ) + array( 'error_code' => sanitize_key( $code ) ) ); }
	private function schedule_submission( $post_id, $when, $source ) {
		if ( wp_next_scheduled( self::CRON_HOOK, array( absint( $post_id ) ) ) ) { return true; }
		$result = wp_schedule_single_event( $when, self::CRON_HOOK, array( absint( $post_id ) ), true );
		$state = $this->state( $post_id );
		if ( is_wp_error( $result ) || false === $result ) { $this->logger->log( 'ERROR', 'sync_schedule_failed', 'Durable submission sync was persisted but its prompt dispatch could not be scheduled; the recurring worker will retry it.', $this->sync_context( $post_id, $state ) + array( 'source' => $source, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed', 'error_message' => is_wp_error( $result ) ? $result->get_error_message() : 'wp_schedule_single_event returned false' ) ); return false; }
		$this->logger->log( 'INFO', 'sync_queued', 'Submission sync was durably persisted and scheduled for prompt dispatch.', $this->sync_context( $post_id, $state ) + array( 'source' => $source, 'queue_job_id' => self::CRON_HOOK, 'scheduled_at' => Didar_Logger::display_timestamp( $when, DATE_ATOM ) ) );
		return true;
	}
	private function acquire_submission_lock( $post_id ) { $key = self::LOCK_PREFIX . absint( $post_id ); $token = wp_generate_uuid4(); $now = time(); if ( add_option( $key, array( 'token' => $token, 'expires_at' => $now + self::LOCK_TTL ), '', false ) ) { return $token; } $existing = get_option( $key, array() ); if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) < $now ) { delete_option( $key ); if ( add_option( $key, array( 'token' => $token, 'expires_at' => $now + self::LOCK_TTL ), '', false ) ) { return $token; } } return ''; }
	private function release_submission_lock( $post_id, $token ) { $key = self::LOCK_PREFIX . absint( $post_id ); $existing = get_option( $key, array() ); if ( is_array( $existing ) && hash_equals( (string) ( $existing['token'] ?? '' ), (string) $token ) ) { delete_option( $key ); } }
	private function fail( $post_id, $error, $pending = false ) { $state = $this->state( $post_id ); $state['status'] = $pending ? 'pending' : 'failed'; $state['last_error'] = sanitize_key( $error ); $state['attempts'] = absint( $state['attempts'] ?? 0 ) + 1; $state['updated_at'] = time(); update_post_meta( $post_id, self::META_STATE, $state ); $this->events->add( $post_id, 'didar_sync_failed', null, null, array( 'source' => 'Didar', 'error' => sanitize_key( $error ), 'attempt' => $state['attempts'] ) ); $retry = $pending && $state['attempts'] < 10; $operation = $retry ? 'sync_worker_item_failed' : ( $pending ? 'sync_retry_exhausted' : 'sync_permanent_failure' ); $message = $retry ? 'Sync failed; durable retry remains pending.' : ( $pending ? 'Sync retry limit was exhausted.' : 'Sync stopped on a permanent validation or identity error.' ); $this->logger->log( $retry ? 'WARNING' : 'ERROR', $operation, $message, $this->sync_context( $post_id, $state ) + array( 'retry_count' => $state['attempts'], 'error_code' => $error ) ); if ( $retry ) { $when = time() + min( HOUR_IN_SECONDS, 60 * max( 1, $state['attempts'] ) ); if ( $this->schedule_submission( $post_id, $when, 'retry' ) ) { $this->logger->log( 'INFO', 'sync_retry_scheduled', 'Didar sync retry scheduled; durable worker sweep is also available.', $this->sync_context( $post_id, $state ) + array( 'retry_count' => $state['attempts'], 'retry_delay' => $when - time(), 'scheduled_at' => Didar_Logger::display_timestamp( $when, DATE_ATOM ) ) ); } } return new WP_Error( sanitize_key( $error ), 'Didar synchronization failed.', array( 'trace_id' => $state['trace_id'] ?? '', 'retry_scheduled' => $retry ) ); }
	private function success( $post_id, $deal_id, $internal_status = '' ) { $state = $this->state( $post_id ); $state['status'] = 'synced'; $state['last_error'] = ''; $state['last_synced_at'] = time(); $state['deal_id'] = $deal_id; $state['last_synced_internal_status'] = sanitize_key( (string) $internal_status ); update_post_meta( $post_id, self::META_STATE, $state ); return true; }
	private function log_user_state( $user_id, $status, $error ) { $state = get_user_meta( $user_id, '_didar_person_sync_state', true ); $state = is_array( $state ) ? $state : array(); $state['status'] = $status; $state['error'] = sanitize_key( $error ); $state['attempts'] = absint( $state['attempts'] ?? 0 ) + 1; $state['updated_at'] = time(); update_user_meta( $user_id, '_didar_person_sync_state', $state ); }
	private function queue_user_retry( $user_id ) { $state = get_user_meta( $user_id, '_didar_person_sync_state', true ); if ( is_array( $state ) && absint( $state['attempts'] ?? 0 ) < 10 && ! wp_next_scheduled( self::USER_HOOK, array( absint( $user_id ) ) ) ) { $result = wp_schedule_single_event( time() + min( HOUR_IN_SECONDS, 60 * max( 1, absint( $state['attempts'] ) ) ), self::USER_HOOK, array( absint( $user_id ) ), true ); if ( is_wp_error( $result ) || false === $result ) { $this->logger->log( 'ERROR', 'sync_schedule_failed', 'Didar Person retry dispatch could not be scheduled; the durable worker sweep will retry it.', array( 'entity_type' => 'user', 'local_id' => absint( $user_id ), 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed' ) ); } } }
	private function clear_user_retries( $user_id ) { while ( $when = wp_next_scheduled( self::USER_HOOK, array( absint( $user_id ) ) ) ) { wp_unschedule_event( $when, self::USER_HOOK, array( absint( $user_id ) ) ); } }
	private function seen_webhook( $event_id ) { $seen = get_option( 'didar_seen_webhooks', array() ); $seen = is_array( $seen ) ? $seen : array(); if ( isset( $seen[ $event_id ] ) ) { return true; } $seen[ $event_id ] = time(); if ( count( $seen ) > 500 ) { $seen = array_slice( $seen, -500, 500, true ); } update_option( 'didar_seen_webhooks', $seen, false ); return false; }
}
