<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves notification recipients and freezes message inputs before queueing. */
class Didar_Notification_Recipient_Resolver {
	private $registry;
	private $settings;
	private $service;
	private $mapper;
	private $workflow;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Submission_Service $service, Didar_Field_Mapper $mapper, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->service  = $service;
		$this->mapper   = $mapper;
		$this->workflow = new Didar_Workflow_Manager( $registry, $settings, $logger ? $logger : new Didar_Logger() );
	}

	/** Return durable channel-specific job snapshots per deduplicated recipient. */
	public function jobs_for_event( $event_key, $submission_id, $occurrence_id, $configuration ) {
		$event_key = Didar_Notification_Event_Registry::normalize_key( $event_key );
		$definition = Didar_Notification_Event_Registry::get( $event_key );
		$post = get_post( absint( $submission_id ) );
		if ( ! $definition || ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) { return array(); }
		$config = Didar_Notification_Event_Registry::normalize_configuration( $configuration );
		$fields = $this->service->get_fields( $submission_id );
		$context = $this->context( $definition['form_type'], $submission_id, $post, $fields );
		$jobs = array();
		foreach ( Didar_Notification_Event_Registry::all() as $subscription_key => $subscription_definition ) {
			$source_event = Didar_Notification_Event_Registry::normalize_key( $subscription_definition['source_event'] ?? $subscription_key );
			if ( $event_key !== $source_event ) { continue; }
			$item = $config[ $subscription_key ] ?? array();
			$manager_only = ! empty( $subscription_definition['manager_only'] );
			$targets = array();
			foreach ( (array) ( $item['user_ids'] ?? array() ) as $user_id ) {
				$user_id = absint( $user_id );
				if ( $user_id && $this->service->is_eligible_assignee( $user_id ) ) { $targets[] = array( 'user_id' => $user_id, 'role' => 'staff' ); }
			}
			if ( ! $manager_only && ! empty( $item['send_to_owner'] ) ) { $targets[] = array( 'user_id' => $this->service->get_owner_user_id( $submission_id ), 'role' => 'owner' ); }
			if ( ! $manager_only && ! empty( $item['send_to_assignee'] ) ) { $targets[] = array( 'user_id' => absint( get_post_meta( $submission_id, '_didar_assigned_user_id', true ) ), 'role' => 'assignee' ); }
			$seen = array( 'sms' => array(), 'email' => array() );
			foreach ( $targets as $target ) {
				$user_id = absint( $target['user_id'] ?? 0 );
				if ( ! $user_id ) { continue; }
				$user = get_user_by( 'id', $user_id );
				if ( ! $user ) { continue; }
				$variables = $this->resolve_variables( (array) ( $item['variables'] ?? array() ), $context, $fields );
				$mapping = array_values( (array) ( $item['variables'] ?? array() ) );
				$values = array_values( $variables );
				$snapshot_base = array( 'source_event_key' => $event_key, 'subscription_key' => $subscription_key );

				if ( ! empty( $item['sms_enabled'] ) ) {
					$destination = $this->mapper->normalize_mobile( $this->mapper->wordpress_user_mobile( $user_id ) );
					$dedupe_key = $destination ? 'mobile:' . $destination : 'user:' . $user_id;
					if ( ! isset( $seen['sms'][ $dedupe_key ] ) ) {
						$seen['sms'][ $dedupe_key ] = true;
						$invalid_recipient = '' === $destination || ! preg_match( '/^09\d{9}$/', $destination );
						$jobs[] = $this->job_snapshot( 'sms', $subscription_key, $submission_id, $occurrence_id, $subscription_definition, $context, $target, $destination, absint( $item['body_id'] ?? 0 ), $mapping, $values, $snapshot_base, $invalid_recipient ? 'recipient_mobile_invalid' : '', $invalid_recipient ? 'The recipient has no valid canonical mobile number.' : '' );
					}
				}

				if ( ! empty( $item['email_enabled'] ) ) {
					$destination = strtolower( sanitize_email( (string) $user->user_email ) );
					$dedupe_key = $destination ? 'email:' . $destination : 'user:' . $user_id;
					if ( ! isset( $seen['email'][ $dedupe_key ] ) ) {
						$seen['email'][ $dedupe_key ] = true;
						$template_check = Didar_Notification_Event_Registry::validate_email_template( $item['email_template'] ?? '', $mapping );
						$rendered = ! empty( $template_check['valid'] ) ? Didar_Notification_Event_Registry::render_email_template( $item['email_template'], $values ) : new WP_Error( $template_check['code'], $template_check['message'] );
						$rendered_body = is_wp_error( $rendered ) ? '' : (string) $rendered;
						$error_code = '';
						$error_message = '';
						if ( ! is_email( $destination ) ) {
							$error_code = 'recipient_email_invalid';
							$error_message = 'The recipient has no valid canonical email address.';
						} elseif ( is_wp_error( $rendered ) ) {
							$error_code = sanitize_key( $rendered->get_error_code() );
							$error_message = sanitize_text_field( $rendered->get_error_message() );
						}
						$snapshot_extra = array_merge( $snapshot_base, array( 'email_template' => (string) ( $item['email_template'] ?? '' ), 'rendered_body' => $rendered_body, 'subject' => Didar_Notification_Event_Registry::email_subject( $subscription_key, $context['request_number'] ) ) );
						$jobs[] = $this->job_snapshot( 'email', $subscription_key, $submission_id, $occurrence_id, $subscription_definition, $context, $target, $destination, 0, $mapping, $values, $snapshot_extra, $error_code, $error_message );
					}
				}
			}
		}
		return $jobs;
	}

	private function job_snapshot( $channel, $event_key, $submission_id, $occurrence_id, $definition, $context, $target, $destination, $body_id, $mapping, $values, $snapshot_extra = array(), $error_code = '', $error_message = '' ) {
		$snapshot = array_merge(
			array(
				'channel'            => $channel,
				'event_key'          => $event_key,
				'occurrence_id'      => sanitize_text_field( (string) $occurrence_id ),
				'form_type'          => $definition['form_type'],
				'request_number'     => $context['request_number'],
				'request_status'     => $context['request_status'],
				'recipient_user_id'  => absint( $target['user_id'] ),
				'recipient_role'     => $target['role'],
				'destination'        => $destination,
				'variables'          => $this->paired_variables( $mapping, $values ),
			),
			$snapshot_extra
		);
		$invalid = '' !== $error_code;
		$recipient_key = $destination ? ( 'email' === $channel ? 'email:' . $destination : 'mobile:' . $destination ) : 'user:' . absint( $target['user_id'] );
		return array(
			'idempotency_key'   => $this->idempotency_key( $channel, $event_key, $submission_id, $occurrence_id, $recipient_key ),
			'channel'           => $channel,
			'event_key'         => $event_key,
			'submission_id'     => absint( $submission_id ),
			'recipient_user_id' => absint( $target['user_id'] ),
			'recipient_role'    => $target['role'],
			'destination'       => $destination,
			'body_id'           => absint( $body_id ),
			'variable_mapping'  => $mapping,
			'variable_values'   => $values,
			'snapshot'          => $snapshot,
			'state'             => $invalid ? 'failed' : 'queued',
			'error_code'        => $error_code,
			'error_message'     => $error_message,
		);
	}

	private function context( $form_type, $submission_id, $post, $fields ) {
		$form = $this->registry->get( $form_type );
		$owner_id = $this->service->get_owner_user_id( $submission_id );
		$creator_id = $this->service->get_creator_user_id( $submission_id );
		$owner = $owner_id ? get_user_by( 'id', $owner_id ) : false;
		$creator = $creator_id ? get_user_by( 'id', $creator_id ) : false;
		$profile = $owner ? $this->mapper->wordpress_user_profile( $owner ) : array();
		$first = $this->field_or_profile( $fields, 'first_name', $profile['first_name'] ?? '' );
		$last = $this->field_or_profile( $fields, 'last_name', $profile['last_name'] ?? '' );
		$full = trim( $first . ' ' . $last );
		if ( '' === $full ) { $full = sanitize_text_field( (string) ( $profile['display_name'] ?? '' ) ); }
		$status = sanitize_key( (string) get_post_meta( $submission_id, '_didar_internal_status', true ) );
		if ( '' === $status ) { $status = sanitize_key( (string) get_post_meta( $submission_id, '_didar_status', true ) ); }
		return array(
			'first_name'       => $first,
			'last_name'        => $last,
			'full_name'        => $full,
			'national_id'      => $this->field_or_profile( $fields, 'national_id', $profile['national_id'] ?? '' ),
			'postal_code'      => $this->scalar_field( $fields, 'postal_code' ),
			'form_type'        => sanitize_text_field( (string) ( $form['label'] ?? $form_type ) ),
			'request_status'   => $this->workflow->status_label( $form_type, $status ),
			'request_number'   => (string) absint( $submission_id ),
			'request_assignee' => $this->assignee_name( $submission_id ),
			'customer_phone'   => $this->user_mobile( $owner_id ),
			'user_role'        => $this->user_role_labels( $owner ),
			'request_creator_phone' => $this->user_mobile( $creator_id ),
		);
	}

	private function user_mobile( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}

		return $this->mapper->normalize_mobile( $this->mapper->wordpress_user_mobile( $user_id ) );
	}

	private function user_role_labels( $user ) {
		if ( ! $user || empty( $user->roles ) || ! function_exists( 'wp_roles' ) ) {
			return '';
		}

		$role_names = wp_roles()->get_names();
		$role_keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) ) );
		sort( $role_keys, SORT_STRING );
		$labels = array();
		foreach ( $role_keys as $role_key ) {
			if ( isset( $role_names[ $role_key ] ) && '' !== (string) $role_names[ $role_key ] ) {
				$labels[] = wp_strip_all_tags( translate_user_role( $role_names[ $role_key ] ) );
			}
		}

		return implode( '، ', array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $labels ) ) ) ) );
	}

	private function resolve_variables( $mapping, $context, $fields ) {
		$out = array();
		foreach ( $mapping as $variable ) {
			$key = sanitize_key( (string) $variable );
			$value = $context[ $key ] ?? '';
			$out[] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		return $out;
	}

	private function paired_variables( $mapping, $values ) {
		$out = array();
		foreach ( $mapping as $index => $key ) { $out[] = array( 'key' => sanitize_key( (string) $key ), 'value' => sanitize_text_field( (string) ( $values[ $index ] ?? '' ) ) ); }
		return $out;
	}

	private function field_or_profile( $fields, $key, $fallback ) {
	$value = $this->scalar_field( $fields, $key );
	return '' !== $value ? $value : sanitize_text_field( (string) $fallback );
	}

	private function scalar_field( $fields, $key ) {
	return isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? sanitize_text_field( (string) $fields[ $key ] ) : '';
	}

	private function assignee_name( $submission_id ) {
		$user_id = absint( get_post_meta( $submission_id, '_didar_assigned_user_id', true ) );
		$user = $user_id ? get_user_by( 'id', $user_id ) : false;
		return $user ? sanitize_text_field( (string) $user->display_name ) : '';
	}

	private function idempotency_key( $channel, $event_key, $submission_id, $occurrence_id, $recipient_key ) {
		$parts = array( sanitize_key( $event_key ), absint( $submission_id ), sanitize_text_field( (string) $occurrence_id ), sanitize_text_field( (string) $recipient_key ) );
		if ( 'email' === sanitize_key( $channel ) ) { array_unshift( $parts, 'email' ); }
		return sanitize_key( $channel ) . '_' . hash( 'sha256', implode( '|', $parts ) );
	}
}
