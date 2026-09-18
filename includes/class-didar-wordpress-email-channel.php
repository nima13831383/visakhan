<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sends snapshotted plain-text Email jobs through WordPress' mail boundary. */
class Didar_WordPress_Email_Channel implements Didar_Notification_Channel_Interface {
	public function send( $job, $credentials ) {
		$destination = strtolower( sanitize_email( (string) ( $job['destination'] ?? '' ) ) );
		$snapshot = is_array( $job['snapshot'] ?? null ) ? $job['snapshot'] : array();
		$subject = isset( $snapshot['subject'] ) && is_scalar( $snapshot['subject'] ) ? sanitize_text_field( (string) $snapshot['subject'] ) : '';
		$body = isset( $snapshot['rendered_body'] ) && is_scalar( $snapshot['rendered_body'] ) ? (string) $snapshot['rendered_body'] : '';
		if ( ! is_email( $destination ) ) {
			return array( 'success' => false, 'retryable' => false, 'provider_id' => '', 'provider_code' => '', 'error_code' => 'recipient_email_invalid', 'error_message' => 'The recipient has no valid canonical email address.' );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			return array( 'success' => false, 'retryable' => false, 'provider_id' => '', 'provider_code' => '', 'error_code' => 'email_snapshot_invalid', 'error_message' => 'The Email snapshot is incomplete.' );
		}
		$accepted = wp_mail( $destination, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( $accepted ) {
			return array( 'success' => true, 'retryable' => false, 'provider_id' => '', 'provider_code' => 'wp_mail_accepted', 'error_code' => '', 'error_message' => '' );
		}
		return array( 'success' => false, 'retryable' => true, 'provider_id' => '', 'provider_code' => 'wp_mail_rejected', 'error_code' => 'mail_transport_failed', 'error_message' => 'WordPress mail transport did not accept the message.' );
	}
}
