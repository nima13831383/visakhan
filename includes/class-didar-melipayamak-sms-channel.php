<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Classic Melipayamak REST SendByBaseNumber2 adapter. */
class Didar_Melipayamak_Sms_Channel implements Didar_Notification_Channel_Interface {
	const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

	public function send( $job, $credentials ) {
		$credentials = is_array( $credentials ) ? $credentials : array();
		$username = sanitize_text_field( (string) ( $credentials['username'] ?? '' ) );
		$api_key  = (string) ( $credentials['api_key'] ?? '' );
		$body_id  = absint( $job['body_id'] ?? 0 );
		$to       = sanitize_text_field( (string) ( $job['destination'] ?? '' ) );
		$mapping  = is_array( $job['variable_mapping'] ?? null ) ? array_values( $job['variable_mapping'] ) : array();
		$values   = is_array( $job['variable_values'] ?? null ) ? array_values( $job['variable_values'] ) : array();

		if ( '' === $username || '' === $api_key ) { return $this->failure( 'provider_not_configured', 'SMS provider credentials are not configured.', false ); }
		if ( ! $body_id ) { return $this->failure( 'body_id_missing', 'The configured SMS Body ID is missing.', false ); }
		if ( '' === $to || ! preg_match( '/^09\d{9}$/', $to ) ) { return $this->failure( 'recipient_invalid', 'The resolved recipient mobile number is invalid.', false ); }
		if ( count( $mapping ) !== count( $values ) ) { return $this->failure( 'variable_snapshot_invalid', 'The notification variable snapshot is invalid.', false ); }
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) { return $this->failure( 'variable_missing', 'A configured SMS variable has no resolved value.', false ); }
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'username' => $username,
					'password' => $api_key,
					'text'     => implode( ';', array_map( 'strval', $values ) ),
					'to'       => $to,
					'bodyId'   => $body_id,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->failure( 'transport_error', 'The SMS provider transport failed.', true, '', '' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = trim( (string) wp_remote_retrieve_body( $response ) );
		$parsed = json_decode( $body, true );
		$value  = is_array( $parsed ) ? ( $parsed['Value'] ?? ( $parsed['value'] ?? ( $parsed['recId'] ?? ( $parsed['RecId'] ?? '' ) ) ) ) : $body;
		$value  = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( $status >= 200 && $status < 300 && preg_match( '/^[1-9]\d*$/', $value ) ) {
			return array( 'success' => true, 'retryable' => false, 'provider_id' => $value, 'provider_code' => $value, 'error_code' => '', 'error_message' => '' );
		}

		$provider_code = $value;
		$retryable = $status >= 500 || 429 === $status || in_array( $value, array( '6', '-6' ), true );
		if ( '' === $provider_code ) { $provider_code = $status ? 'http_' . $status : 'empty_response'; }
		if ( $status >= 400 && $status < 500 && 429 !== $status ) { $retryable = false; }
		return $this->failure( 'provider_rejected', 'The SMS provider rejected the notification.', $retryable, $provider_code, '' );
	}

	private function failure( $code, $message, $retryable, $provider_code = '', $provider_id = '' ) {
		return array( 'success' => false, 'retryable' => (bool) $retryable, 'provider_id' => sanitize_text_field( (string) $provider_id ), 'provider_code' => sanitize_text_field( (string) $provider_code ), 'error_code' => sanitize_key( $code ), 'error_message' => sanitize_text_field( $message ) );
	}
}
