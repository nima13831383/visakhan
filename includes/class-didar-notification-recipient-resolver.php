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

	/** Return one durable job snapshot per deduplicated recipient. */
	public function jobs_for_event( $event_key, $submission_id, $occurrence_id, $configuration ) {
		$definition = Didar_Notification_Event_Registry::get( $event_key );
		$post = get_post( absint( $submission_id ) );
		if ( ! $definition || ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) { return array(); }
		$config = Didar_Notification_Event_Registry::normalize_configuration( $configuration );
		$item = $config[ $event_key ] ?? array();
		$fields = $this->service->get_fields( $submission_id );
		$context = $this->context( $definition['form_type'], $submission_id, $post, $fields );
		$targets = array();

		foreach ( (array) ( $item['user_ids'] ?? array() ) as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id && $this->service->is_eligible_assignee( $user_id ) ) { $targets[] = array( 'user_id' => $user_id, 'role' => 'staff' ); }
		}
		if ( ! empty( $item['send_to_owner'] ) ) { $targets[] = array( 'user_id' => $this->service->get_owner_user_id( $submission_id ), 'role' => 'owner' ); }
		if ( ! empty( $item['send_to_assignee'] ) ) { $targets[] = array( 'user_id' => absint( get_post_meta( $submission_id, '_didar_assigned_user_id', true ) ), 'role' => 'assignee' ); }

		$seen = array();
		$jobs = array();
		foreach ( $targets as $target ) {
			$user_id = absint( $target['user_id'] ?? 0 );
			if ( ! $user_id ) { continue; }
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) { continue; }
			$destination = $this->mapper->normalize_mobile( $this->mapper->wordpress_user_mobile( $user_id ) );
			$dedupe_key = $destination ? 'mobile:' . $destination : 'user:' . $user_id;
			if ( isset( $seen[ $dedupe_key ] ) ) { continue; }
			$seen[ $dedupe_key ] = true;
			$variables = $this->resolve_variables( (array) ( $item['variables'] ?? array() ), $context, $fields );
			$mapping = array_values( (array) ( $item['variables'] ?? array() ) );
			$values = array_values( $variables );
			$invalid_recipient = '' === $destination || ! preg_match( '/^09\d{9}$/', $destination );
			$jobs[] = array(
				'idempotency_key'   => $this->idempotency_key( $event_key, $submission_id, $occurrence_id, $dedupe_key ),
				'event_key'         => $event_key,
				'submission_id'     => absint( $submission_id ),
				'recipient_user_id' => $user_id,
				'recipient_role'    => $target['role'],
				'destination'       => $destination,
				'body_id'           => absint( $item['body_id'] ?? 0 ),
				'variable_mapping'  => $mapping,
				'variable_values'   => $values,
				'snapshot'          => array( 'event_key' => $event_key, 'occurrence_id' => sanitize_text_field( (string) $occurrence_id ), 'form_type' => $definition['form_type'], 'request_number' => $context['request_number'], 'request_status' => $context['request_status'], 'recipient_user_id' => $user_id, 'recipient_role' => $target['role'], 'destination' => $destination, 'variables' => $this->paired_variables( $mapping, $values ) ),
				'state'             => $invalid_recipient ? 'failed' : 'queued',
				'error_code'        => $invalid_recipient ? 'recipient_mobile_invalid' : '',
				'error_message'     => $invalid_recipient ? 'The recipient has no valid canonical mobile number.' : '',
			);
		}
		return $jobs;
	}

	private function context( $form_type, $submission_id, $post, $fields ) {
		$form = $this->registry->get( $form_type );
		$owner_id = absint( $post->post_author );
		$owner = $owner_id ? get_user_by( 'id', $owner_id ) : false;
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
		);
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

	private function idempotency_key( $event_key, $submission_id, $occurrence_id, $recipient_key ) {
		return 'sms_' . hash( 'sha256', implode( '|', array( sanitize_key( $event_key ), absint( $submission_id ), sanitize_text_field( (string) $occurrence_id ), sanitize_text_field( (string) $recipient_key ) ) ) );
	}
}
