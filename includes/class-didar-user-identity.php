<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves the WordPress account identity shown for an accessible request. */
class Didar_User_Identity {
	const ROLE_ADMIN    = 'admin';
	const ROLE_AGENT    = 'agent';
	const ROLE_COWORKER = 'coworker';
	const ROLE_CUSTOMER = 'customer';

	public static function for_submission( $submission_id ) {
		$post = get_post( absint( $submission_id ) );
		if ( ! $post ) {
			return self::fallback();
		}

		return self::for_user( get_userdata( absint( $post->post_author ) ) );
	}

	public static function for_user( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return self::fallback();
		}

		$name = sanitize_text_field( (string) $user->display_name );
		if ( '' === $name ) {
			$name = __( 'کاربر', 'didar' );
		}

		$role_key = self::role_key( $user );
		return array(
			'name'      => $name,
			'role_key'  => $role_key,
			'role_label'=> self::role_label( $role_key ),
		);
	}

	public static function role_key( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return self::ROLE_CUSTOMER;
		}

		$roles = is_array( $user->roles ) ? $user->roles : array();
		if ( in_array( 'administrator', $roles, true ) || user_can( $user, 'manage_options' ) ) {
			return self::ROLE_ADMIN;
		}
		if ( in_array( Didar_Access_Control::ROLE_BROKER, $roles, true ) || user_can( $user, 'didar_assign_requests' ) || user_can( $user, 'didar_manage_settings' ) ) {
			return self::ROLE_AGENT;
		}
		if ( in_array( Didar_Access_Control::ROLE_COLLEAGUE, $roles, true ) || user_can( $user, 'didar_colleague_access' ) ) {
			return self::ROLE_COWORKER;
		}

		return self::ROLE_CUSTOMER;
	}

	public static function role_label( $role_key ) {
		$labels = array(
			self::ROLE_ADMIN    => __( 'مدیر', 'didar' ),
			self::ROLE_AGENT    => __( 'کارگزار', 'didar' ),
			self::ROLE_COWORKER => __( 'همکار', 'didar' ),
			self::ROLE_CUSTOMER => __( 'مشتری', 'didar' ),
		);

		return isset( $labels[ $role_key ] ) ? $labels[ $role_key ] : $labels[ self::ROLE_CUSTOMER ];
	}

	private static function fallback() {
		return array(
			'name'       => __( 'کاربر حذف‌شده', 'didar' ),
			'role_key'   => self::ROLE_CUSTOMER,
			'role_label' => __( 'مشتری', 'didar' ),
		);
	}
}
