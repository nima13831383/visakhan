<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Central definitions and user-meta access for reusable profile document images. */
class Didar_Profile_Document_Catalog {
	const META_KEY = '_didar_profile_documents';

	public static function definitions() {
		return array(
			'national_card_front'          => array( 'label' => 'تصویر روی کارت ملی' ),
			'national_card_back'           => array( 'label' => 'تصویر پشت کارت ملی' ),
			'passport_main_page'           => array( 'label' => 'تصویر صفحه اصلی پاسپورت' ),
			'personal_photo'               => array( 'label' => 'عکس پرسنلی' ),
			'birth_certificate_first_page' => array( 'label' => 'تصویر صفحه اول شناسنامه' ),
		);
	}

	public static function definition( $key ) {
		$key = sanitize_key( (string) $key );
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) { return null; }
		return array_merge(
			array(
				'name'         => $key,
				'type'         => 'file',
				'multiple'     => false,
				'max_files'    => 1,
				'max_size'     => 5 * MB_IN_BYTES,
				'accept'       => '.jpg,.jpeg,.png,.webp',
				'upload_mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ),
				'mime_types'   => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'profile_document' => true,
			),
			$definitions[ $key ]
		);
	}

	public static function keys() { return array_keys( self::definitions() ); }

	public static function get_user_documents( $user_id ) {
		$stored = get_user_meta( absint( $user_id ), self::META_KEY, true );
		$out = array();
		foreach ( self::keys() as $key ) { $out[ $key ] = isset( $stored[ $key ] ) ? absint( $stored[ $key ] ) : 0; }
		return $out;
	}

	public static function set_user_document( $user_id, $key, $file_id ) {
		$key = sanitize_key( (string) $key );
		if ( ! self::definition( $key ) ) { return false; }
		$documents = self::get_user_documents( $user_id );
		$documents[ $key ] = absint( $file_id );
		return update_user_meta( absint( $user_id ), self::META_KEY, $documents );
	}

	public static function remove_user_document( $user_id, $key ) { return self::set_user_document( $user_id, $key, 0 ); }
}
