<?php

class Test_Didar_Date_Service extends WP_UnitTestCase {
	private $date;

	public function set_up() { parent::set_up(); $this->date = new Didar_Date_Service(); }

	public function test_gregorian_and_jalali_round_trip() {
		$this->assertSame( '1405/06/07', $this->date->to_jalali( '2026-08-29' ) );
		$this->assertSame( '2026-08-29', $this->date->to_gregorian( '1405/06/07' ) );
	}

	public function test_jalali_leap_day_is_accepted() { $this->assertSame( '2025-03-20', $this->date->normalize_input( '۱۴۰۳/۱۲/۳۰' ) ); }

	public function test_invalid_jalali_dates_are_rejected() {
		foreach ( array( '1402/12/30', '1405/13/01', '1405/02/32', 'not-a-date' ) as $value ) { $this->assertSame( '', $this->date->normalize_input( $value ), $value ); }
	}

	public function test_existing_canonical_dates_display_as_jalali() { $this->assertSame( '1374/01/01', $this->date->format_for_display( '1995-03-21' ) ); }

	public function test_empty_and_malformed_gregorian_values_do_not_become_dates() { $this->assertFalse( $this->date->validate_gregorian( '' ) ); $this->assertFalse( $this->date->validate_gregorian( '2024-02-30' ) ); }

	public function test_engine_is_available_without_requiring_intl() {
		$this->assertTrue( Didar_Date_Service::is_supported() );
		$this->assertContains( Didar_Date_Service::engine(), array( Didar_Date_Service::ENGINE_INTL, Didar_Date_Service::ENGINE_FALLBACK ) );
	}

	public function test_fallback_matches_intl_for_required_vectors() {
		if ( ! Didar_Date_Service::intl_available() ) { $this->markTestSkipped( 'IntlCalendar is not available in this PHP runtime.' ); }
		foreach ( array( '2026-08-29', '2025-03-20', '2024-02-29', '1995-03-21', '2000-01-01', '2031-01-01' ) as $value ) {
			$this->assertSame( $this->date->to_jalali_with_engine( $value, 'intl' ), $this->date->to_jalali_with_engine( $value, 'fallback' ), $value );
		}
		foreach ( array( '1405/06/07', '1403/12/30', '1374/01/01' ) as $value ) {
			$this->assertSame( $this->date->to_gregorian_with_engine( $value, 'intl' ), $this->date->to_gregorian_with_engine( $value, 'fallback' ), $value );
		}
	}

	public function test_fallback_rejects_invalid_esfand_and_handles_leap_boundaries() {
		$this->assertSame( '2025-03-20', $this->date->to_gregorian_with_engine( '1403/12/30', 'fallback' ) );
		$this->assertSame( '', $this->date->to_gregorian_with_engine( '1402/12/30', 'fallback' ) );
		$this->assertSame( '1404/01/01', $this->date->to_jalali_with_engine( '2025-03-21', 'fallback' ) );
	}

	public function test_date_only_conversion_is_timezone_independent() {
		$original = date_default_timezone_get();
		foreach ( array( 'UTC', 'Asia/Tehran', 'Europe/Berlin' ) as $timezone ) {
			date_default_timezone_set( $timezone );
			$this->assertSame( '1405/06/07', $this->date->to_jalali( '2026-08-29' ), $timezone );
			$this->assertSame( '2026-08-29', $this->date->to_gregorian( '1405/06/07' ), $timezone );
		}
		date_default_timezone_set( $original );
	}

	public function test_fallback_round_trip_has_no_one_day_drift() {
		foreach ( array( '1999-12-31', '2000-02-29', '2010-03-20', '2025-03-20', '2026-08-29', '2035-12-31' ) as $value ) {
			$this->assertSame( $value, $this->date->to_gregorian_with_engine( $this->date->to_jalali_with_engine( $value, 'fallback' ), 'fallback' ), $value );
		}
	}
}
