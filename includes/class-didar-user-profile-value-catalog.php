<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Central catalogue of profile values that may be used as form defaults. */
class Didar_User_Profile_Value_Catalog {
	const BIRTH_DATE_META = '_didar_birth_date';
	const NATIONAL_ID_META = '_didar_national_id';

	public function sources() {
		return array(
			'first_name'  => array( 'label' => 'نام', 'type' => 'text' ),
			'last_name'   => array( 'label' => 'نام خانوادگی', 'type' => 'text' ),
			'gender'      => array( 'label' => 'جنسیت', 'type' => 'choice' ),
			'birth_date'  => array( 'label' => 'تاریخ تولد', 'type' => 'date' ),
			'national_id' => array( 'label' => 'کد ملی', 'type' => 'string' ),
			'email'       => array( 'label' => 'ایمیل', 'type' => 'email' ),
			'mobile'      => array( 'label' => 'شماره تلفن', 'type' => 'tel' ),
		);
	}

	public function keys() { return array_keys( $this->sources() ); }

	public function label( $key ) {
		$key = sanitize_key( (string) $key );
		$sources = $this->sources();
		return $sources[ $key ]['label'] ?? '';
	}

	/** Resolve from the canonical mapper profile shape; no user data is persisted here. */
	public function resolve( $key, $profile ) {
		$key = sanitize_key( (string) $key );
		if ( ! isset( $this->sources()[ $key ] ) || ! is_array( $profile ) ) { return ''; }
		$property = $key;
		$value = $profile[ $property ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Resolve a source for a user through the canonical profile resolver. */
	public function resolve_for_user( $key, $user, $profile_resolver ) {
		if ( ! is_callable( $profile_resolver ) || ! $user ) { return ''; }
		return $this->resolve( $key, call_user_func( $profile_resolver, $user ) );
	}
}
