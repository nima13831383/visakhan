<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Access_Control {
	const ROLE_COLLEAGUE = 'didar_colleague';
	const ROLE_BROKER    = 'didar_broker';
	const VERSION        = '1.2.0';
	const VERSION_OPTION = 'didar_access_version';

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin_access' ), 1 );
		add_action( 'init', array( __CLASS__, 'remove_nader_admin_gate_for_didar' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'remove_nader_admin_gate_for_didar' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'add_assigned_requests_submenu' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'restrict_admin_menu' ), 999 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'filter_woocommerce_admin_restriction' ), 100 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'filter_woocommerce_admin_restriction' ), 100 );
	}

	/**
	 * Keep the active encrypted Nader theme's admin gate from overriding Didar
	 * authorization. The theme registers an anonymous admin_init callback from
	 * NaderSecurity.php at priority 10 and redirects every non-Administrator.
	 *
	 * @return void
	 */
	public static function remove_nader_admin_gate_for_didar() {
		if ( ! self::can_access_didar_admin() ) {
			return;
		}

		global $wp_filter;

		if ( empty( $wp_filter['admin_init'] ) || ! $wp_filter['admin_init'] instanceof WP_Hook ) {
			return;
		}

		foreach ( $wp_filter['admin_init']->callbacks as $priority => $callbacks ) {
			if ( $priority < 9 ) {
				continue;
			}

			foreach ( $callbacks as $id => $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;

				if ( ! $function instanceof Closure ) {
					continue;
				}

				$reflection = new ReflectionFunction( $function );
				$file_name  = $reflection->getFileName();

				if ( $file_name && 'NaderSecurity.php' === basename( $file_name ) ) {
					unset( $wp_filter['admin_init']->callbacks[ $priority ][ $id ] );
				}
			}
		}
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
		return array_unique( array_merge( array( 'read' ), self::administrator_caps() ) );
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
				'didar_view_all_requests',
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

	public static function can_edit_request( $post_id ) {
		$post_id = absint( $post_id );
		return $post_id
			&& Didar_Post_Type::POST_TYPE === get_post_type( $post_id )
			&& current_user_can( 'didar_view_request' )
			&& current_user_can( 'didar_edit_requests' )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Determine whether a user may enter the Didar administration area.
	 *
	 * This deliberately uses a Didar capability rather than a role name or a
	 * generic WordPress administration capability.
	 *
	 * @param int|WP_User|null $user Optional user ID or object. Defaults to the current user.
	 * @return bool
	 */
	public static function can_access_didar_admin( $user = null ) {
		if ( null === $user ) {
			return current_user_can( 'didar_view_requests' );
		}

		return user_can( $user, 'didar_view_requests' );
	}

	public static function restrict_admin_access() {
		if ( wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( current_user_can( 'manage_options' ) ) {
			if ( 'post.php' === $pagenow && 'POST' === $request_method ) {
				$post_id = isset( $_POST['post_ID'] ) && ! is_array( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0;
				if ( $post_id && Didar_Post_Type::POST_TYPE === get_post_type( $post_id ) ) {
					if ( ! self::can_edit_request( $post_id ) ) {
						self::deny_admin_save( __( 'درخواست ذخیره نشد؛ شما اجازه ویرایش این درخواست را ندارید.', 'didar' ) );
					}
					self::verify_admin_save_request( $post_id );
				}
			}
			return;
		}

		$admin_post_action = isset( $_REQUEST['action'] ) && ! is_array( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'admin-post.php' === $pagenow && 'didar_download_file' === $admin_post_action ) {
			return;
		}

		if ( ! self::can_access_didar_admin() ) {
			if ( current_user_can( 'didar_colleague_access' ) || ! current_user_can( 'edit_posts' ) ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			return;
		}

		$allowed = false;
		if ( 'edit.php' === $pagenow ) {
			$post_type = isset( $_GET['post_type'] ) && ! is_array( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			$allowed   = Didar_Post_Type::POST_TYPE === $post_type && self::can_access_didar_admin();
		} elseif ( 'post-new.php' === $pagenow ) {
			$post_type = isset( $_GET['post_type'] ) && ! is_array( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			$allowed   = Didar_Post_Type::POST_TYPE === $post_type && current_user_can( 'create_didar_submissions' );
		} elseif ( 'post.php' === $pagenow ) {
			$get_post_id  = isset( $_GET['post'] ) && ! is_array( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			$post_post_id = isset( $_POST['post_ID'] ) && ! is_array( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0;
			$post_id      = 'POST' === $request_method ? $post_post_id : $get_post_id;
			$allowed      = self::can_edit_request( $post_id );

			if ( $allowed && 'POST' === $request_method ) {
				self::verify_admin_save_request( $post_id );
			}
		} elseif ( 'options.php' === $pagenow ) {
			$option_page = isset( $_POST['option_page'] ) && ! is_array( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
			$allowed     = 'didar_page_settings' === $option_page && current_user_can( 'didar_manage_settings' );
		}

		if ( ! $allowed ) {
			if ( 'POST' === $request_method ) {
				self::deny_admin_save( __( 'درخواست ذخیره نشد؛ شما اجازه ویرایش این درخواست را ندارید.', 'didar' ) );
			}
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE ) );
			exit;
		}
	}

	private static function verify_admin_save_request( $post_id ) {
		$action = isset( $_POST['action'] ) && ! is_array( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$nonce  = isset( $_POST['didar_admin_nonce'] ) && ! is_array( $_POST['didar_admin_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['didar_admin_nonce'] ) ) : '';
		if ( 'editpost' !== $action || ! wp_verify_nonce( $nonce, 'didar_admin_save_submission_' . absint( $post_id ) ) ) {
			self::deny_admin_save( __( 'درخواست ذخیره نشد؛ نشست شما منقضی شده است. صفحه را تازه‌سازی و دوباره تلاش کنید.', 'didar' ) );
		}
	}

	private static function deny_admin_save( $message ) {
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	public static function restrict_admin_menu() {
		if ( current_user_can( 'manage_options' ) || ! self::can_access_didar_admin() ) {
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

	public static function add_assigned_requests_submenu() {
		if ( ! self::can_access_didar_admin() ) {
			return;
		}

		$parent = 'edit.php?post_type=' . Didar_Post_Type::POST_TYPE;
		add_submenu_page(
			$parent,
			__( 'درخواست‌های ارجاع‌شده به من', 'didar' ),
			__( 'ارجاع‌شده به من', 'didar' ),
			'didar_view_requests',
			$parent . '&didar_assignment=mine'
		);
	}

	public static function filter_admin_bar( $show ) {
		if ( ! self::can_access_didar_admin() && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}

	public static function filter_woocommerce_admin_restriction( $restricted ) {
		if ( self::can_access_didar_admin() ) {
			return false;
		}

		return $restricted;
	}
}
