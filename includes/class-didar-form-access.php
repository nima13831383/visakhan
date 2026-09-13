<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared settings and validation helpers for public Didar form access assets. */
class Didar_Form_Access {
	const SETTING_KEY        = 'didar_form_access';
	const MAX_IMAGE_BYTES    = 5242880;
	const DEFAULT_LINK_TEXT  = 'مشاهده فرم';

	public static function form_types() {
		return array( 'consultation', 'embassy_appointment', 'traveler_evaluation', 'complaint_suggestion', 'visa_request' );
	}

	public static function get( $settings, $form_type ) {
		$form_type = sanitize_key( (string) $form_type );
		$settings  = is_array( $settings ) ? $settings : array();
		$entry     = isset( $settings[ self::SETTING_KEY ][ $form_type ] ) && is_array( $settings[ self::SETTING_KEY ][ $form_type ] ) ? $settings[ self::SETTING_KEY ][ $form_type ] : array();
		return array(
			'url'     => self::sanitize_url( $entry['url'] ?? '' ),
			'barcode' => self::sanitize_image_url( $entry['barcode'] ?? '' ),
		);
	}

	public static function sanitize_url( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	public static function sanitize_image_url( $value ) {
		$url = self::sanitize_url( $value );
		if ( ! $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$ext   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? $url : '';
	}

	public static function attachment_image_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return '';
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) || filesize( $file ) > self::MAX_IMAGE_BYTES ) {
			return '';
		}

		return self::sanitize_image_url( wp_get_attachment_url( $attachment_id ) );
	}

	public static function normalize_entry( $entry ) {
		$entry = is_array( $entry ) ? $entry : array();
		return array(
			'url'     => self::sanitize_url( $entry['url'] ?? '' ),
			'barcode' => self::sanitize_image_url( $entry['barcode'] ?? '' ),
		);
	}
}
