<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Converts business dates between canonical Gregorian ISO and Jalali presentation. */
class Didar_Date_Service {
	const JALALI_LOCALE = 'fa_IR@calendar=persian';
	const ENGINE_INTL = 'intl';
	const ENGINE_FALLBACK = 'fallback';
	const ENGINE_UNAVAILABLE = 'unavailable';

	public static function fallback_available() { return class_exists( 'DateTimeImmutable' ) && class_exists( 'DateTimeZone' ) && method_exists( __CLASS__, 'gregorian_to_jalali_fallback' ); }
	public static function is_supported() { return class_exists( 'IntlCalendar' ) || self::fallback_available(); }
	public static function engine() { return class_exists( 'IntlCalendar' ) ? self::ENGINE_INTL : ( self::fallback_available() ? self::ENGINE_FALLBACK : self::ENGINE_UNAVAILABLE ); }
	public static function intl_available() { return class_exists( 'IntlCalendar' ); }

	public function validate_gregorian( $value ) {
		$value = $this->ascii_digits( trim( (string) $value ) );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		return (bool) ( $date && $date->format( 'Y-m-d' ) === $value );
	}

	public function to_jalali( $gregorian_date ) {
		if ( self::ENGINE_FALLBACK === self::engine() ) { return $this->gregorian_to_jalali_fallback( $gregorian_date ); }
		if ( self::ENGINE_UNAVAILABLE === self::engine() ) { return ''; }
		$value = $this->to_jalali_intl( $gregorian_date );
		return $value ?: ( self::fallback_available() ? $this->gregorian_to_jalali_fallback( $gregorian_date ) : '' );
	}

	public function to_gregorian( $jalali_date ) {
		if ( self::ENGINE_FALLBACK === self::engine() ) { return $this->jalali_to_gregorian_fallback( $jalali_date ); }
		if ( self::ENGINE_UNAVAILABLE === self::engine() ) { return ''; }
		$value = $this->to_gregorian_intl( $jalali_date );
		return $value ?: ( self::fallback_available() ? $this->jalali_to_gregorian_fallback( $jalali_date ) : '' );
	}

	public function normalize_input( $value ) {
		$value = $this->ascii_digits( trim( (string) $value ) );
		if ( $this->validate_gregorian( $value ) ) { return $value; }
		return $this->to_gregorian( $value );
	}

	public function format_for_display( $canonical_date ) { return $this->to_jalali( $canonical_date ); }

	public function to_jalali_with_engine( $gregorian_date, $engine ) {
		return self::ENGINE_INTL === $engine ? $this->to_jalali_intl( $gregorian_date ) : $this->gregorian_to_jalali_fallback( $gregorian_date );
	}

	public function to_gregorian_with_engine( $jalali_date, $engine ) {
		return self::ENGINE_INTL === $engine ? $this->to_gregorian_intl( $jalali_date ) : $this->jalali_to_gregorian_fallback( $jalali_date );
	}

	private function to_jalali_intl( $gregorian_date ) {
		if ( ! $this->validate_gregorian( $gregorian_date ) || ! class_exists( 'IntlCalendar' ) ) { return ''; }
		try {
			$calendar = IntlCalendar::createInstance( new DateTimeZone( 'UTC' ), self::JALALI_LOCALE );
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $gregorian_date, new DateTimeZone( 'UTC' ) );
			$calendar->setTime( $date->getTimestamp() * 1000 );
			return sprintf( '%04d/%02d/%02d', $calendar->get( IntlCalendar::FIELD_YEAR ), $calendar->get( IntlCalendar::FIELD_MONTH ) + 1, $calendar->get( IntlCalendar::FIELD_DAY_OF_MONTH ) );
		} catch ( Throwable $e ) { return ''; }
	}

	private function to_gregorian_intl( $jalali_date ) {
		$parts = $this->jalali_parts( $jalali_date );
		if ( ! $parts || ! class_exists( 'IntlCalendar' ) ) { return ''; }
		try {
			$calendar = IntlCalendar::createInstance( new DateTimeZone( 'UTC' ), self::JALALI_LOCALE );
			$calendar->clear();
			$calendar->set( $parts[0], $parts[1] - 1, $parts[2], 0, 0, 0 );
			$value = ( new DateTimeImmutable( '@' . (int) ( $calendar->getTime() / 1000 ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
			return $this->to_jalali_intl( $value ) === sprintf( '%04d/%02d/%02d', $parts[0], $parts[1], $parts[2] ) ? $value : '';
		} catch ( Throwable $e ) { return ''; }
	}

	/* Component-only fallback based on the public Jalaali 33-year break algorithm. */
	private function gregorian_to_jalali_fallback( $value ) {
		if ( ! $this->validate_gregorian( $value ) ) { return ''; }
		list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', $value ) );
		$g_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$jy = $gy > 1600 ? 979 : 0; $gy -= $gy > 1600 ? 1600 : 621;
		$gy2 = $gm > 2 ? $gy + 1 : $gy;
		$days = 365 * $gy + (int) floor( ( $gy2 + 3 ) / 4 ) - (int) floor( ( $gy2 + 99 ) / 100 ) + (int) floor( ( $gy2 + 399 ) / 400 ) - 80 + $gd + $g_days[ $gm - 1 ];
		$jy += 33 * (int) floor( $days / 12053 ); $days %= 12053; $jy += 4 * (int) floor( $days / 1461 ); $days %= 1461;
		if ( $days > 365 ) { $jy += (int) floor( ( $days - 1 ) / 365 ); $days = ( $days - 1 ) % 365; }
		$jm = $days < 186 ? 1 + (int) floor( $days / 31 ) : 7 + (int) floor( ( $days - 186 ) / 30 );
		$jd = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	private function jalali_to_gregorian_fallback( $value ) {
		$parts = $this->jalali_parts( $value ); if ( ! $parts ) { return ''; }
		list( $jy, $jm, $jd ) = $parts; $gy = $jy > 979 ? 1600 : 621; $jy -= $jy > 979 ? 979 : 0;
		$days = 365 * $jy + (int) floor( $jy / 33 ) * 8 + (int) floor( ( ( $jy % 33 ) + 3 ) / 4 ) + 78 + $jd + ( $jm < 7 ? ( $jm - 1 ) * 31 : ( $jm - 7 ) * 30 + 186 );
		$gy += 400 * (int) floor( $days / 146097 ); $days %= 146097;
		if ( $days > 36524 ) { $gy += 100 * (int) floor( --$days / 36524 ); $days %= 36524; if ( $days >= 365 ) { $days++; } }
		$gy += 4 * (int) floor( $days / 1461 ); $days %= 1461;
		if ( $days > 365 ) { $gy += (int) floor( ( $days - 1 ) / 365 ); $days = ( $days - 1 ) % 365; }
		$gd = $days + 1; $leap = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
		$month_days = array( 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ); $gm = 1;
		while ( $gm <= 12 && $gd > $month_days[ $gm - 1 ] ) { $gd -= $month_days[ $gm - 1 ]; $gm++; }
		$canonical = sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
		return $this->gregorian_to_jalali_fallback( $canonical ) === sprintf( '%04d/%02d/%02d', $parts[0], $parts[1], $parts[2] ) ? $canonical : '';
	}

	private function jalali_parts( $value ) {
		$value = str_replace( '-', '/', $this->ascii_digits( trim( (string) $value ) ) );
		if ( ! preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $match ) ) { return array(); }
		$year = (int) $match[1]; $month = (int) $match[2]; $day = (int) $match[3];
		return $year >= 1 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= ( $month <= 6 ? 31 : ( $month <= 11 ? 30 : 30 ) ) ? array( $year, $month, $day ) : array();
	}

	private function ascii_digits( $value ) { return strtr( (string) $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) ); }
}
