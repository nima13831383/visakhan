<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical notification events and the deliberately small SMS variable
 * vocabulary. Event definitions live here so admin, dispatch, and tests use
 * the same keys and eligibility rules.
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
			'enabled'         => false,
			'user_ids'        => array(),
			'send_to_owner'   => false,
			'send_to_assignee'=> false,
			'body_id'         => '',
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
			$out[ $event_key ] = array(
				'enabled'          => ! empty( $item['enabled'] ),
				'user_ids'         => $user_ids,
				'send_to_owner'    => ! empty( $item['send_to_owner'] ),
				'send_to_assignee' => ! empty( $item['send_to_assignee'] ),
				'body_id'          => $body_id ? (string) $body_id : '',
				'variables'        => array_values( $variables ),
			);
		}

		return $out;
	}

	public static function configuration_ready( $configuration, $event_key ) {
		$configuration = self::normalize_configuration( $configuration );
		$event_key = self::normalize_key( $event_key );
		$item = $configuration[ $event_key ] ?? array();
		if ( empty( $item['enabled'] ) || '' === (string) ( $item['body_id'] ?? '' ) ) {
			return false;
		}
		return ! empty( $item['user_ids'] ) || ! empty( $item['send_to_owner'] ) || ! empty( $item['send_to_assignee'] );
	}
}
