<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Connects request domain events to durable notification jobs and a provider adapter. */
class Didar_Notification_Manager {
	const CRON_HOOK      = 'didar_notification_worker';
	const ITEM_HOOK      = 'didar_notification_job';
	const CRON_SCHEDULE  = 'didar_notification_five_minutes';
	const MAX_BATCH      = 20;

	private $registry;
	private $settings;
	private $service;
	private $logger;
	private $queue;
	private $resolver;
	private $channels = array();

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Submission_Service $service, Didar_Field_Mapper $mapper = null, Didar_Logger $logger = null, Didar_Notification_Queue $queue = null, Didar_Notification_Channel_Interface $channel = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->service  = $service;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->queue    = $queue ? $queue : new Didar_Notification_Queue();
		$mapper         = $mapper ? $mapper : new Didar_Field_Mapper( $registry, $settings, null, $this->logger );
		$this->resolver = new Didar_Notification_Recipient_Resolver( $registry, $settings, $service, $mapper, $this->logger );
		$this->channels = array(
			'sms'   => $channel ? $channel : new Didar_Melipayamak_Sms_Channel(),
			'email' => new Didar_WordPress_Email_Channel(),
		);

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'didar_submission_created', array( $this, 'on_submission_created' ), 30, 2 );
		add_action( 'didar_submission_assignee_changed', array( $this, 'on_assignee_changed' ), 30, 4 );
		add_action( 'didar_submission_request_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( self::CRON_HOOK, array( $this, 'process_due_jobs' ) );
		add_action( self::ITEM_HOOK, array( $this, 'process_job' ) );
	}

	public function set_channel( Didar_Notification_Channel_Interface $channel, $channel_name = 'sms' ) {
		$channel_name = sanitize_key( (string) $channel_name );
		if ( in_array( $channel_name, array( 'sms', 'email' ), true ) ) {
			$this->channels[ $channel_name ] = $channel;
		}
	}

	public function queue() { return $this->queue; }

	public function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'هر پنج دقیقه — صف اعلان دیدار', 'didar' ) );
		return $schedules;
	}

	public function ensure_worker_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$result = wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK, array(), true );
			if ( is_wp_error( $result ) || false === $result ) {
				$this->logger->log( 'ERROR', 'notification_schedule_failed', 'The notification worker could not be scheduled.', array( 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed' ) );
			}
		}
	}

	public function on_submission_created( $submission_id, $event_id = 0 ) {
		$form_type = sanitize_key( (string) get_post_meta( absint( $submission_id ), '_didar_form_type', true ) );
		$event_key = $this->event_key( $form_type, 'created' );
		return $event_key ? $this->emit( $event_key, $submission_id, $event_id ? 'event-' . absint( $event_id ) : 'created-' . absint( $submission_id ) ) : array();
	}

	public function on_assignee_changed( $submission_id, $old_value = 0, $new_value = 0, $meta = array() ) {
		$form_type = sanitize_key( (string) get_post_meta( absint( $submission_id ), '_didar_form_type', true ) );
		$event_key = $this->event_key( $form_type, 'assignee_changed' );
		$event_id = is_array( $meta ) ? absint( $meta['event_id'] ?? 0 ) : 0;
		return $event_key ? $this->emit( $event_key, $submission_id, $event_id ? 'event-' . $event_id : 'assignment-' . absint( $submission_id ) . '-' . absint( $new_value ) ) : array();
	}

	public function on_status_changed( $submission_id, $old_value = '', $new_value = '', $meta = array() ) {
		$form_type = sanitize_key( (string) get_post_meta( absint( $submission_id ), '_didar_form_type', true ) );
		$event_key = $this->event_key( $form_type, 'status_changed' );
		$event_id = is_array( $meta ) ? absint( $meta['event_id'] ?? 0 ) : 0;
		return $event_key ? $this->emit( $event_key, $submission_id, $event_id ? 'event-' . $event_id : 'status-' . absint( $submission_id ) . '-' . sanitize_key( $new_value ) ) : array();
	}

	public function emit( $event_key, $submission_id, $occurrence_id ) {
		$config = $this->settings->notification_events();
		$jobs = $this->resolver->jobs_for_event( $event_key, absint( $submission_id ), $occurrence_id, $config );
		$created = array();
		foreach ( $jobs as $job ) {
			$stored = $this->queue->create( $job );
			if ( is_wp_error( $stored ) ) {
				$this->logger->log( 'ERROR', 'notification_job_create_failed', 'A notification job could not be persisted.', array( 'event_key' => $event_key, 'submission_id' => absint( $submission_id ), 'error_code' => $stored->get_error_code() ) );
				continue;
			}
			$created[] = $stored;
			if ( 'queued' === ( $stored['state'] ?? '' ) ) { $this->schedule_job( $stored['job_id'] ); }
		}
		return $created;
	}

	public function process_job( $job_id ) {
		$claimed = $this->queue->claim( absint( $job_id ) );
		if ( ! $claimed ) { return false; }
		if ( $claimed['attempts'] > Didar_Notification_Queue::MAX_ATTEMPTS ) {
			$this->queue->mark_failed( $claimed['job_id'], 'attempt_limit', 'Maximum delivery attempts reached.' );
			return false;
		}
		$channel_name = in_array( sanitize_key( (string) ( $claimed['channel'] ?? 'sms' ) ), array( 'sms', 'email' ), true ) ? sanitize_key( (string) $claimed['channel'] ) : '';
		$channel = $channel_name && isset( $this->channels[ $channel_name ] ) ? $this->channels[ $channel_name ] : null;
		if ( ! $channel ) {
			$this->queue->mark_failed( $claimed['job_id'], 'notification_channel_invalid', 'The notification channel is not available.' );
			return false;
		}
		$credentials = 'sms' === $channel_name ? $this->settings->melipayamak_credentials() : array();
		$result = $channel->send( $claimed, $credentials );
		$result = is_array( $result ) ? $result : array( 'success' => false, 'retryable' => false, 'provider_id' => '', 'provider_code' => '', 'error_code' => 'provider_result_invalid', 'error_message' => 'The notification provider returned an invalid result.' );
		if ( ! empty( $result['success'] ) ) {
			$this->queue->mark_success( $claimed['job_id'], $result['provider_id'] ?? '', $result['provider_code'] ?? '' );
			return true;
		}
		$error_code = sanitize_key( (string) ( $result['error_code'] ?? 'provider_failed' ) );
		$error_message = sanitize_text_field( (string) ( $result['error_message'] ?? 'Notification delivery failed.' ) );
		if ( ! empty( $result['retryable'] ) && $claimed['attempts'] < Didar_Notification_Queue::MAX_ATTEMPTS ) {
			$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** max( 0, $claimed['attempts'] - 1 ) ) );
			$this->queue->mark_retry( $claimed['job_id'], $error_code, $error_message, $delay );
			$this->schedule_job( $claimed['job_id'], $delay );
			return false;
		}
		$this->queue->mark_failed( $claimed['job_id'], $error_code, $error_message );
		return false;
	}

	public function process_due_jobs() {
		$this->queue->recover_stale();
		foreach ( $this->queue->due_job_ids( self::MAX_BATCH ) as $job_id ) { $this->process_job( $job_id ); }
	}

	public function retry_job( $job_id ) {
		$result = $this->queue->retry( absint( $job_id ) );
		if ( $result ) { $this->schedule_job( $job_id ); }
		return $result;
	}

	public function purge_actionable_jobs() {
		$ids = $this->queue->purge_actionable();
		foreach ( $ids as $job_id ) {
			$this->unschedule_job( $job_id );
		}
		return $ids;
	}

	public function unschedule_job( $job_id ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || ! function_exists( '_get_cron_array' ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::ITEM_HOOK ] ) || ! is_array( $hooks[ self::ITEM_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ self::ITEM_HOOK ] as $event ) {
				$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				if ( isset( $args[0] ) && absint( $args[0] ) === $job_id && wp_unschedule_event( (int) $timestamp, self::ITEM_HOOK, $args ) ) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	private function schedule_job( $job_id, $delay = 5 ) {
		wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::ITEM_HOOK, array( absint( $job_id ) ), true );
	}

	private function event_key( $form_type, $trigger ) {
		$key = sanitize_key( $form_type ) . '.' . sanitize_key( $trigger );
		return Didar_Notification_Event_Registry::get( $key ) ? $key : '';
	}
}
