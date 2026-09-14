<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent, non-destructive country catalog for ns-didar.
 *
 * Country keys are the values stored in submissions and are intentionally
 * immutable. Deactivation keeps historical values and labels available for
 * edit screens, details, and readable/PDF output.
 */
class Didar_Country_Catalog {
	const OPTION_NAME = 'didar_country_catalog';
	const VERSION     = 1;

	public static function get_countries( $include_disabled = false ) {
		$records = self::records();
		$countries = array();
		foreach ( $records as $key => $record ) {
			if ( ! $include_disabled && empty( $record['active'] ) ) {
				continue;
			}
			$countries[ $key ] = $record['label'];
		}
		return $countries;
	}

	public static function get_records() {
		return self::records();
	}

	public static function get_archived_countries() {
		$archived = array();
		foreach ( self::records() as $key => $record ) {
			if ( empty( $record['active'] ) ) {
				$archived[ $key ] = $record['label'];
			}
		}
		return $archived;
	}

	public static function get_country( $key, $include_disabled = true ) {
		$key = sanitize_key( (string) $key );
		$records = self::records();
		if ( ! isset( $records[ $key ] ) || ( ! $include_disabled && empty( $records[ $key ]['active'] ) ) ) {
			return array();
		}
		return $records[ $key ];
	}

	public static function get_country_label( $key, $fallback = '' ) {
		$key = sanitize_key( (string) $key );
		$country = self::get_country( $key, true );
		if ( ! empty( $country['label'] ) ) {
			return $country['label'];
		}
		$fallback = is_scalar( $fallback ) ? sanitize_text_field( (string) $fallback ) : '';
		return '' !== $fallback ? $fallback : ( '' !== $key ? sprintf( 'کشور ذخیره‌شده (%s)', $key ) : '');
	}

	public static function add_country( $key, $label ) {
		$key = self::normalize_key( $key );
		$label = self::normalize_label( $label );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( '' === $label ) {
			return new WP_Error( 'didar_country_label_required', 'نام کشور الزامی است.' );
		}
		$records = self::records();
		if ( isset( $records[ $key ] ) ) {
			return new WP_Error( 'didar_country_duplicate', 'این کلید کشور قبلاً ثبت شده است.' );
		}
		$now = gmdate( 'c' );
		$records[ $key ] = array( 'label' => $label, 'active' => 1, 'created_at_gmt' => $now, 'updated_at_gmt' => $now );
		self::persist( $records );
		return $records[ $key ];
	}

	public static function update_country( $key, $label ) {
		$key = sanitize_key( (string) $key );
		$label = self::normalize_label( $label );
		$records = self::records();
		if ( '' === $key || ! isset( $records[ $key ] ) ) {
			return new WP_Error( 'didar_country_not_found', 'کشور موردنظر پیدا نشد.' );
		}
		if ( 'iran' === $key ) {
			$label = 'ایران';
		}
		if ( '' === $label ) {
			return new WP_Error( 'didar_country_label_required', 'نام کشور الزامی است.' );
		}
		$records[ $key ]['label'] = $label;
		$records[ $key ]['updated_at_gmt'] = gmdate( 'c' );
		self::persist( $records );
		return $records[ $key ];
	}

	public static function set_active( $key, $active ) {
		$key = sanitize_key( (string) $key );
		$records = self::records();
		if ( '' === $key || ! isset( $records[ $key ] ) ) {
			return new WP_Error( 'didar_country_not_found', 'کشور موردنظر پیدا نشد.' );
		}
		if ( 'iran' === $key && ! $active ) {
			return new WP_Error( 'didar_country_iran_required', 'ایران به‌دلیل مقدار پیش‌فرض و سوابق موجود قابل غیرفعال‌سازی نیست.' );
		}
		$records[ $key ]['active'] = $active ? 1 : 0;
		$records[ $key ]['updated_at_gmt'] = gmdate( 'c' );
		self::persist( $records );
		return $records[ $key ];
	}

	/** Return a safe summary for diagnostics and tests without exposing settings. */
	public static function summary() {
		$records = self::records();
		$active = 0;
		foreach ( $records as $record ) {
			if ( ! empty( $record['active'] ) ) {
				$active++;
			}
		}
		return array( 'total' => count( $records ), 'active' => $active, 'disabled' => count( $records ) - $active, 'keys' => array_keys( $records ) );
	}

	private static function records() {
		$stored = get_option( self::OPTION_NAME, null );
		if ( null === $stored || ! is_array( $stored ) || self::stored_countries( $stored ) === array() ) {
			$stored = self::seed_from_defaults( $stored );
		}
		return self::normalize_records( $stored );
	}

	private static function stored_countries( $stored ) {
		if ( is_array( $stored ) && isset( $stored['countries'] ) && is_array( $stored['countries'] ) ) {
			return $stored['countries'];
		}
		return is_array( $stored ) ? $stored : array();
	}

	private static function seed_from_defaults( $existing ) {
		$defaults = class_exists( 'Didar_Reference_Data' ) && method_exists( 'Didar_Reference_Data', 'default_countries' ) ? Didar_Reference_Data::default_countries() : array();
		$records = array();
		$now = gmdate( 'c' );
		foreach ( $defaults as $key => $label ) {
			$key = sanitize_key( (string) $key );
			$label = self::normalize_label( $label );
			if ( '' === $key || '' === $label ) {
				continue;
			}
			$records[ $key ] = array( 'label' => $label, 'active' => 1, 'created_at_gmt' => $now, 'updated_at_gmt' => $now );
		}
		$seeded = array( 'version' => self::VERSION, 'countries' => $records );
		if ( null === $existing ) {
			add_option( self::OPTION_NAME, $seeded, '', false );
		} else {
			update_option( self::OPTION_NAME, $seeded, false );
		}
		return $seeded;
	}

	private static function normalize_records( $stored ) {
		$raw = self::stored_countries( $stored );
		$records = array();
		foreach ( $raw as $key => $record ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $record ) ) {
				$label = self::normalize_label( $record['label'] ?? '' );
				$active = ! array_key_exists( 'active', $record ) || ! empty( $record['active'] );
				$created = sanitize_text_field( (string) ( $record['created_at_gmt'] ?? '' ) );
				$updated = sanitize_text_field( (string) ( $record['updated_at_gmt'] ?? '' ) );
			} else {
				$label = self::normalize_label( $record );
				$active = true;
				$created = '';
				$updated = '';
			}
			if ( '' === $label ) {
				continue;
			}
			$records[ $key ] = array( 'label' => 'iran' === $key ? 'ایران' : $label, 'active' => $active ? 1 : 0, 'created_at_gmt' => $created, 'updated_at_gmt' => $updated );
		}
		return $records;
	}

	private static function persist( $records ) {
		update_option( self::OPTION_NAME, array( 'version' => self::VERSION, 'countries' => $records ), false );
	}

	private static function normalize_key( $key ) {
		$key = is_scalar( $key ) ? trim( (string) $key ) : '';
		if ( '' === $key || ! preg_match( '/^[a-z][a-z0-9_-]*$/', $key ) ) {
			return new WP_Error( 'didar_country_key_invalid', 'کلید کشور باید با حروف انگلیسی شروع شود و فقط شامل حروف کوچک، عدد، خط تیره یا زیرخط باشد.' );
		}
		return sanitize_key( $key );
	}

	private static function normalize_label( $label ) {
		return is_scalar( $label ) ? trim( sanitize_text_field( wp_unslash( (string) $label ) ) ) : '';
	}
}
