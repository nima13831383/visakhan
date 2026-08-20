<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Access_Control {
	const ROLE_COLLEAGUE = 'didar_colleague';
	const ROLE_BROKER    = 'didar_broker';
	const VERSION        = '1.1.0';
	const VERSION_OPTION = 'didar_access_version';

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin_access' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'restrict_admin_menu' ), 999 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
	}

	public static function maybe_upgrade() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install_roles_and_capabilities();
		}
	}

	public static function install_roles_and_capabilities() {
		$colleague = add_role(
			self::ROLE_COLLEAGUE,
			__( 'همکار', 'didar' ),
			array(
				'read'                                => true,
				'didar_colleague_access'             => true,
				'didar_view_own_internal_workflow'   => true,
				'didar_view_own_request_history'     => true,
			)
		);
		if ( ! $colleague ) {
			$colleague = get_role( self::ROLE_COLLEAGUE );
		}

		$broker = add_role(
			self::ROLE_BROKER,
			__( 'کارگزار', 'didar' ),
			array( 'read' => true )
		);
		if ( ! $broker ) {
			$broker = get_role( self::ROLE_BROKER );
		}

		self::sync_role_caps( $colleague, self::colleague_caps() );
		self::sync_role_caps( $broker, self::broker_caps() );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::administrator_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private static function sync_role_caps( $role, $allowed_caps ) {
		if ( ! $role ) {
			return;
		}

		$protected_wp_caps = array(
			'activate_plugins', 'create_users', 'delete_users', 'edit_dashboard', 'edit_files',
			'edit_plugins', 'edit_posts', 'edit_pages', 'edit_theme_options', 'edit_themes',
			'edit_users', 'export', 'import', 'install_plugins', 'install_themes', 'list_users',
			'manage_categories', 'manage_links', 'manage_options', 'moderate_comments',
			'promote_users', 'publish_pages', 'publish_posts', 'remove_users', 'switch_themes',
			'update_core', 'update_plugins', 'update_themes', 'upload_files',
		);
		foreach ( $protected_wp_caps as $cap ) {
			$role->remove_cap( $cap );
		}
		$all_didar_caps = array_unique( array_merge( self::colleague_caps(), self::broker_caps(), self::administrator_caps() ) );
		foreach ( $all_didar_caps as $cap ) {
			if ( ! in_array( $cap, $allowed_caps, true ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( $allowed_caps as $cap ) {
			$role->add_cap( $cap );
		}
	}

	private static function colleague_caps() {
		return array(
			'read',
			'didar_colleague_access',
			'didar_view_own_internal_workflow',
			'didar_view_own_request_history',
		);
	}

	private static function broker_caps() {
		return array_merge(
			array(
				'read',
				'read_didar_submission',
				'read_private_didar_submissions',
				'edit_didar_submission',
				'edit_didar_submissions',
				'edit_others_didar_submissions',
				'edit_private_didar_submissions',
				'edit_published_didar_submissions',
			),
			self::workflow_caps()
		);
	}

	private static function administrator_caps() {
		return array_merge(
			array(
				'read_didar_submission',
				'read_private_didar_submissions',
				'edit_didar_submission',
				'edit_didar_submissions',
				'edit_others_didar_submissions',
				'edit_private_didar_submissions',
				'edit_published_didar_submissions',
				'publish_didar_submissions',
				'delete_didar_submission',
				'delete_didar_submissions',
				'delete_others_didar_submissions',
				'delete_private_didar_submissions',
				'delete_published_didar_submissions',
				'create_didar_submissions',
				'didar_change_request_owner',
				'didar_manage_settings',
			),
			self::workflow_caps()
		);
	}

	private static function workflow_caps() {
		return array(
			'didar_view_requests',
			'didar_view_request',
			'didar_edit_requests',
			'didar_change_public_status',
			'didar_edit_public_notes',
			'didar_view_internal_workflow',
			'didar_change_internal_status',
			'didar_add_internal_notes',
			'didar_assign_requests',
			'didar_receive_requests',
			'didar_view_request_history',
		);
	}

	public static function restrict_admin_access() {
		if ( wp_doing_ajax() || current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! current_user_can( 'didar_view_requests' ) ) {
			if ( current_user_can( 'didar_colleague_access' ) || ! current_user_can( 'edit_posts' ) ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			return;
		}

		global $pagenow;
		$allowed = false;
		if ( 'edit.php' === $pagenow ) {
			$post_type = isset( $_GET['post_type'] ) && ! is_array( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			$allowed   = Didar_Post_Type::POST_TYPE === $post_type && current_user_can( 'didar_view_requests' );
		} elseif ( 'post.php' === $pagenow ) {
			$post_id = isset( $_GET['post'] ) && ! is_array( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			$allowed = $post_id && Didar_Post_Type::POST_TYPE === get_post_type( $post_id ) && current_user_can( 'didar_view_request' ) && current_user_can( 'edit_post', $post_id );
		}

		if ( ! $allowed ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE ) );
			exit;
		}
	}

	public static function restrict_admin_menu() {
		if ( current_user_can( 'manage_options' ) || ! current_user_can( 'didar_view_requests' ) ) {
			return;
		}

		$allowed = 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE;
		global $menu;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && $allowed !== $item[2] ) {
				remove_menu_page( $item[2] );
			}
		}
	}

	public static function filter_admin_bar( $show ) {
		if ( ! current_user_can( 'didar_view_requests' ) && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}
}
