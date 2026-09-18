<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical notification events and the deliberately small shared variable
 * vocabulary. Event definitions live here so admin, dispatch, and tests use
 * the same keys and eligibility rules for every delivery channel.
 */
class Didar_Notification_Event_Registry {
	const OPTION_NAME = 'didar_notification_events';

	public static function all() {
		return array(
			'visa_request.created'              => array( 'label' => 'ایجاد درخواست ویزا', 'form_type' => 'visa_request', 'trigger' => 'created' ),
			'embassy_appointment.created'        => array( 'label' => 'ایجاد درخواست وقت سفارت', 'form_type' => 'embassy_appointment', 'trigger' => 'created' ),
			'visa_request.assignee_changed'      => array( 'label' => 'تغییر مسئول درخواست ویزا', 'form_type' => 'visa_request', 'trigger' => 'assignee_changed' ),
			'embassy_appointment.assignee_changed' => array( 'label' => 'تغییر مسئول درخواست وقت سفارت', 'form_type' => 'embassy_appointment', 'trigger' => 'assignee_changed' ),
			'visa_request.status_changed'        => array( 'label' => 'تغییر وضعیت درخواست ویزا', 'form_type' => 'visa_request', 'trigger' => 'status_changed' ),
			'embassy_appointment.status_changed' => array( 'label' => 'تغییر وضعیت درخواست وقت سفارت', 'form_type' => 'embassy_appointment', 'trigger' => 'status_changed' ),
		);
	}

	public static function variables() {
		return array(
			'first_name'         => 'نام',
			'last_name'          => 'نام خانوادگی',
			'full_name'          => 'نام و نام خانوادگی',
			'national_id'        => 'کد ملی',
			'postal_code'        => 'کد پستی',
			'form_type'          => 'نوع فرم',
			'request_status'     => 'وضعیت درخواست',
			'request_number'     => 'شماره درخواست',
			'request_assignee'   => 'مسئول درخواست',
		);
	}

	public static function get( $key ) {
		$key = self::normalize_key( $key );
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : array();
	}

	public static function normalize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_.-]/i', '', (string) $key ) );
	}

	public static function default_configuration() {
		$out = array();
		foreach ( self::all() as $key => $definition ) {
			$out[ $key ] = self::default_event_configuration();
		}
		return $out;
	}

	public static function default_event_configuration() {
		return array(
			// `enabled` remains the legacy SMS setting for old imports and callers.
			'enabled'         => false,
			'sms_enabled'     => false,
			'email_enabled'   => false,
			'user_ids'        => array(),
			'send_to_owner'   => false,
			'send_to_assignee'=> false,
			'body_id'         => '',
			'email_template'  => '',
			'variables'       => array(),
		);
	}

	/** Normalize admin/import data without enabling or deleting unspecified rows. */
	public static function normalize_configuration( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::default_configuration();
		$allowed_variables = array_keys( self::variables() );

		foreach ( self::all() as $event_key => $definition ) {
			$item = isset( $raw[ $event_key ] ) && is_array( $raw[ $event_key ] ) ? $raw[ $event_key ] : array();
			$user_ids = isset( $item['user_ids'] ) && is_array( $item['user_ids'] ) ? $item['user_ids'] : ( isset( $item['staff_user_ids'] ) && is_array( $item['staff_user_ids'] ) ? $item['staff_user_ids'] : array() );
			$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

			$variables = array();
			$raw_variables = isset( $item['variables'] ) && is_array( $item['variables'] ) ? $item['variables'] : array();
			if ( $raw_variables ) {
				$numeric_keys = array_keys( $raw_variables ) === range( 0, count( $raw_variables ) - 1 );
				if ( ! $numeric_keys ) {
					ksort( $raw_variables, SORT_NUMERIC );
				}
				foreach ( $raw_variables as $variable ) {
					$variable = sanitize_key( (string) $variable );
					if ( in_array( $variable, $allowed_variables, true ) ) {
						$variables[] = $variable;
					}
				}
			}

			$body_id = isset( $item['body_id'] ) && is_scalar( $item['body_id'] ) ? absint( $item['body_id'] ) : 0;
			$legacy_sms_enabled = ! empty( $item['enabled'] );
			$sms_enabled = array_key_exists( 'sms_enabled', $item ) ? ! empty( $item['sms_enabled'] ) : $legacy_sms_enabled;
			$email_template = isset( $item['email_template'] ) && is_scalar( $item['email_template'] ) ? sanitize_textarea_field( (string) $item['email_template'] ) : '';
			$out[ $event_key ] = array(
				'enabled'          => $sms_enabled,
				'sms_enabled'      => $sms_enabled,
				'email_enabled'    => ! empty( $item['email_enabled'] ),
				'user_ids'         => $user_ids,
				'send_to_owner'    => ! empty( $item['send_to_owner'] ),
				'send_to_assignee' => ! empty( $item['send_to_assignee'] ),
				'body_id'          => $body_id ? (string) $body_id : '',
				'email_template'   => $email_template,
				'variables'        => array_values( $variables ),
			);
		}

		return $out;
	}

	public static function configuration_ready( $configuration, $event_key ) {
		return self::channel_configuration_ready( $configuration, $event_key, 'sms' );
	}

	public static function channel_configuration_ready( $configuration, $event_key, $channel ) {
		$configuration = self::normalize_configuration( $configuration );
		$event_key = self::normalize_key( $event_key );
		$channel = sanitize_key( (string) $channel );
		$item = $configuration[ $event_key ] ?? array();
		if ( ! in_array( $channel, array( 'sms', 'email' ), true ) ) {
			return false;
		}
		if ( ( 'sms' === $channel && ( empty( $item['sms_enabled'] ) || '' === (string) ( $item['body_id'] ?? '' ) ) ) || ( 'email' === $channel && ( empty( $item['email_enabled'] ) || '' === (string) ( $item['email_template'] ?? '' ) ) ) ) {
			return false;
		}
		return ! empty( $item['user_ids'] ) || ! empty( $item['send_to_owner'] ) || ! empty( $item['send_to_assignee'] );
	}

	/** Subject convention for Email jobs; no separate subject mapping is stored. */
	public static function email_subject( $event_key, $request_number ) {
		$definition = self::get( $event_key );
		$label = sanitize_text_field( (string) ( $definition['label'] ?? $event_key ) );
		return sprintf( 'اعلان درخواست #%d: %s', absint( $request_number ), $label );
	}

	/** Validate the numeric placeholder grammar used by Email templates. */
	public static function validate_email_template( $template, $variable_mapping ) {
		$template = is_scalar( $template ) ? (string) $template : '';
		$mapping_count = count( (array) $variable_mapping );
		if ( '' === trim( $template ) ) {
			return array( 'valid' => false, 'code' => 'email_template_missing', 'message' => 'Email template is empty.' );
		}
		$matches = array();
		if ( false === preg_match_all( '/\{([^{}]*)\}/u', $template, $matches ) ) {
			return array( 'valid' => false, 'code' => 'email_template_encoding_invalid', 'message' => 'Email template encoding is invalid.' );
		}
		$without_tokens = preg_replace( '/\{[^{}]*\}/u', '', $template );
		if ( false === $without_tokens || false !== strpos( $without_tokens, '{' ) || false !== strpos( $without_tokens, '}' ) ) {
			return array( 'valid' => false, 'code' => 'email_placeholder_unbalanced', 'message' => 'Email template contains an unbalanced placeholder.' );
		}
		foreach ( (array) ( $matches[1] ?? array() ) as $token ) {
			if ( '' === $token || ! ctype_digit( $token ) || absint( $token ) >= $mapping_count ) {
				return array( 'valid' => false, 'code' => ctype_digit( (string) $token ) ? 'email_placeholder_index_invalid' : 'email_placeholder_invalid', 'message' => 'Email template contains an invalid placeholder.' );
			}
		}
		return array( 'valid' => true, 'code' => '', 'message' => '' );
	}

	public static function render_email_template( $template, $values ) {
		$value_count = count( (array) $values );
		$validation = self::validate_email_template( $template, $value_count ? range( 0, $value_count - 1 ) : array() );
		if ( empty( $validation['valid'] ) ) {
			return new WP_Error( $validation['code'], $validation['message'] );
		}
		$rendered = preg_replace_callback( '/\{(\d+)\}/u', function ( $match ) use ( $values ) {
			return sanitize_text_field( (string) ( $values[ absint( $match[1] ) ] ?? '' ) );
		}, (string) $template );
		return false === $rendered ? new WP_Error( 'email_template_render_failed', 'Email template could not be rendered.' ) : $rendered;
	}
}
