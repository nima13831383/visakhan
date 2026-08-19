<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Admin {
	private $registry;
	private $renderer;
	private $validator;
	private $service;
	private $state_cache = array();
	private static $saving = false;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Validator $validator, Didar_Submission_Service $service ) {
		$this->registry  = $registry;
		$this->renderer  = $renderer;
		$this->validator = $validator;
		$this->service   = $service;

		add_action( 'add_meta_boxes_' . Didar_Post_Type::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Didar_Post_Type::POST_TYPE, array( $this, 'save_submission' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'manage_' . Didar_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Didar_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
	}

	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . Didar_Post_Type::POST_TYPE,
			__( 'تنظیمات صفحات دیدار', 'didar' ),
			__( 'تنظیمات صفحات', 'didar' ),
			'manage_options',
			'didar-page-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'didar_page_settings',
			Didar_Shortcodes::PAGE_SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_page_settings' ),
			)
		);

		add_settings_section(
			'didar_submission_pages',
			__( 'صفحات مشاهده و ویرایش درخواست', 'didar' ),
			array( $this, 'render_settings_description' ),
			'didar-page-settings'
		);
		add_settings_field(
			'didar_details_page_id',
			__( 'صفحه مشاهده جزئیات', 'didar' ),
			array( $this, 'render_page_setting' ),
			'didar-page-settings',
			'didar_submission_pages',
			array( 'key' => 'details_page_id', 'shortcode' => '[didar_submission_details]' )
		);
		add_settings_field(
			'didar_edit_page_id',
			__( 'صفحه ویرایش درخواست', 'didar' ),
			array( $this, 'render_page_setting' ),
			'didar-page-settings',
			'didar_submission_pages',
			array( 'key' => 'edit_page_id', 'shortcode' => '[didar_submission_edit]' )
		);
	}

	public function sanitize_page_settings( $input ) {
		$current = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$output  = array();

		foreach ( array( 'details_page_id', 'edit_page_id' ) as $key ) {
			$page_id = is_array( $input ) && isset( $input[ $key ] ) && ! is_array( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
			$output[ $key ] = $page_id && 'page' === get_post_type( $page_id ) ? $page_id : 0;
		}

		if ( $output['details_page_id'] && $output['details_page_id'] === $output['edit_page_id'] ) {
			add_settings_error(
				Didar_Shortcodes::PAGE_SETTINGS_OPTION,
				'didar_pages_must_differ',
				__( 'صفحه مشاهده جزئیات و صفحه ویرایش باید دو برگه متفاوت باشند.', 'didar' ),
				'error'
			);
			return $current;
		}

		return $output;
	}

	public function render_settings_description() {
		echo '<p>' . esc_html__( 'این دو برگه برای همه انواع فرم دیدار مشترک هستند. کد کوتاه نوشته‌شده زیر هر انتخاب را در همان برگه قرار دهید.', 'didar' ) . '</p>';
	}

	public function render_page_setting( $args ) {
		$settings = get_option( Didar_Shortcodes::PAGE_SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$key      = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
		$selected = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

		wp_dropdown_pages(
			array(
				'name'              => Didar_Shortcodes::PAGE_SETTINGS_OPTION . '[' . $key . ']',
				'id'                => 'didar-' . sanitize_html_class( $key ),
				'selected'          => $selected,
				'show_option_none'  => __( '— انتخاب برگه —', 'didar' ),
				'option_none_value' => '0',
			)
		);
		if ( ! empty( $args['shortcode'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'کد کوتاه لازم در این برگه: %s', 'didar' ), $args['shortcode'] ) ) . '</p>';
		}
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'تنظیمات صفحات دیدار', 'didar' ) . '</h1>';
		settings_errors( Didar_Shortcodes::PAGE_SETTINGS_OPTION );
		echo '<form action="options.php" method="post">';
		settings_fields( 'didar_page_settings' );
		do_settings_sections( 'didar-page-settings' );
		submit_button( __( 'ذخیره تنظیمات', 'didar' ) );
		echo '</form></div>';
	}

	public function add_meta_boxes( $post ) {
		remove_meta_box( 'slugdiv', Didar_Post_Type::POST_TYPE, 'normal' );
		add_meta_box( 'didar-form-type', __( 'نوع فرم', 'didar' ), array( $this, 'render_form_type_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'didar-fields', __( 'اطلاعات فرم', 'didar' ), array( $this, 'render_fields_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-notes', __( 'یادداشت‌ها', 'didar' ), array( $this, 'render_notes_box' ), Didar_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'didar-details', __( 'وضعیت و مالک', 'didar' ), array( $this, 'render_details_box' ), Didar_Post_Type::POST_TYPE, 'side', 'high' );
	}

	public function render_form_type_box( $post ) {
		wp_nonce_field( 'didar_admin_save_submission_' . $post->ID, 'didar_admin_nonce' );
		$stored_type = get_post_meta( $post->ID, '_didar_form_type', true );
		$state       = $this->get_admin_state( $post->ID );
		$selected    = $stored_type ? $stored_type : ( isset( $state['form_type'] ) ? $state['form_type'] : '' );

		echo '<div class="didar-admin-wrap" dir="rtl">';
		if ( $stored_type && $this->registry->get( $stored_type ) ) {
			$form = $this->registry->get( $stored_type );
			echo '<p><strong>' . esc_html( $form['label'] ) . '</strong></p><p class="description">' . esc_html__( 'برای جلوگیری از ناسازگاری داده‌ها، نوع فرم پس از ایجاد قابل تغییر نیست.', 'didar' ) . '</p>';
			echo '<input type="hidden" name="didar_form_type" value="' . esc_attr( $stored_type ) . '">';
		} else {
			echo '<label for="didar-form-type-select"><strong>' . esc_html__( 'نوع فرم را انتخاب کنید:', 'didar' ) . '</strong></label>';
			echo '<select id="didar-form-type-select" name="didar_form_type"><option value="">' . esc_html__( '— انتخاب نوع فرم —', 'didar' ) . '</option>';
			foreach ( $this->registry->all() as $type => $form ) {
				echo '<option value="' . esc_attr( $type ) . '" ' . selected( $selected, $type, false ) . '>' . esc_html( $form['label'] ) . '</option>';
			}
			echo '</select><span class="spinner" data-didar-admin-spinner></span>';
		}
		echo '</div>';
	}

	public function render_notes_box( $post ) {
		$state       = $this->get_admin_state( $post->ID );
		$shared_note = isset( $state['shared_note'] ) ? $state['shared_note'] : $this->service->get_shared_note( $post->ID );
		$admin_note  = isset( $state['admin_note'] ) ? $state['admin_note'] : (string) get_post_meta( $post->ID, '_didar_admin_note', true );

		echo '<div class="didar-admin-wrap didar-admin-notes" dir="rtl">';
		echo '<p><label class="didar-label" for="didar-shared-note">' . esc_html__( 'یادداشت مشترک', 'didar' ) . '</label>';
		echo '<textarea id="didar-shared-note" name="didar_shared_note" rows="5">' . esc_textarea( $shared_note ) . '</textarea>';
		echo '<span class="description">' . esc_html__( 'این یادداشت برای مالک درخواست و مدیران قابل مشاهده و ویرایش است.', 'didar' ) . '</span></p>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><label class="didar-label" for="didar-admin-note">' . esc_html__( 'یادداشت داخلی مدیران', 'didar' ) . '</label>';
			echo '<textarea id="didar-admin-note" name="didar_admin_note" rows="5">' . esc_textarea( $admin_note ) . '</textarea>';
			echo '<span class="description">' . esc_html__( 'این یادداشت فقط در مدیریت وردپرس نمایش داده می‌شود و مالک درخواست آن را نمی‌بیند.', 'didar' ) . '</span></p>';
		}
		echo '</div>';
	}

	public function render_fields_box( $post ) {
		$type  = get_post_meta( $post->ID, '_didar_form_type', true );
		$state = $this->get_admin_state( $post->ID );
		if ( ! $type && ! empty( $state['form_type'] ) ) {
			$type = $state['form_type'];
		}
		$form   = $this->registry->get( $type );
		$values = isset( $state['values'] ) ? $state['values'] : $this->service->get_fields( $post->ID );
		$errors = isset( $state['errors'] ) ? $state['errors'] : array();

		echo '<div id="didar-admin-fields" class="didar-admin-wrap" dir="rtl">';
		if ( $form ) {
			$this->renderer->render_sections( $form, $values, $errors, 'admin' );
		} else {
			echo '<div class="didar-admin-placeholder"><span class="dashicons dashicons-forms" aria-hidden="true"></span><p>' . esc_html__( 'ابتدا نوع فرم را انتخاب کنید تا فیلدهای مربوط نمایش داده شوند.', 'didar' ) . '</p></div>';
		}
		echo '</div>';
	}

	public function render_details_box( $post ) {
		$status   = get_post_meta( $post->ID, '_didar_status', true );
		$status   = $status ? $status : 'pending_review';
		$owner_id = $post->post_author ? (int) $post->post_author : get_current_user_id();
		$users    = get_users( array( 'number' => 100, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
		$listed_owner = false;
		foreach ( $users as $user ) {
			if ( (int) $user->ID === $owner_id ) {
				$listed_owner = true;
				break;
			}
		}
		if ( ! $listed_owner ) {
			$current_owner = get_user_by( 'id', $owner_id );
			if ( $current_owner ) {
				array_unshift( $users, $current_owner );
			}
		}

		echo '<div class="didar-admin-wrap" dir="rtl"><p><label for="didar-status"><strong>' . esc_html__( 'وضعیت', 'didar' ) . '</strong></label><select id="didar-status" name="didar_status">';
		foreach ( Didar_Reference_Data::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		if ( current_user_can( 'edit_others_didar_submissions' ) ) {
			echo '<p><label for="didar-owner"><strong>' . esc_html__( 'مالک درخواست', 'didar' ) . '</strong></label><select id="didar-owner" name="didar_owner">';
			foreach ( $users as $user ) {
				echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $owner_id, $user->ID, false ) . '>' . esc_html( $user->display_name . ' — ' . $user->user_email ) . '</option>';
			}
			echo '</select></p><p class="description">' . esc_html__( 'در سایت‌های دارای بیش از ۱۰۰ کاربر، مالک فعلی همواره حفظ می‌شود مگر اینکه گزینه دیگری انتخاب شود.', 'didar' ) . '</p>';
		} else {
			echo '<input type="hidden" name="didar_owner" value="' . esc_attr( $owner_id ) . '">';
		}
		echo '</div>';
	}

	public function save_submission( $post_id, $post, $update ) {
		if ( self::$saving || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['didar_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['didar_admin_nonce'] ) ), 'didar_admin_save_submission_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$stored_type = get_post_meta( $post_id, '_didar_form_type', true );
		$posted_type = isset( $_POST['didar_form_type'] ) ? sanitize_key( wp_unslash( $_POST['didar_form_type'] ) ) : '';
		$form_type   = $stored_type ? $stored_type : $posted_type;
		$form        = $this->registry->get( $form_type );
		$raw         = isset( $_POST['didar_fields'] ) && is_array( $_POST['didar_fields'] ) ? wp_unslash( $_POST['didar_fields'] ) : array();
		$note_errors = array();
		$shared_note = $this->service->get_shared_note( $post_id );
		if ( isset( $_POST['didar_shared_note'] ) ) {
			if ( is_array( $_POST['didar_shared_note'] ) ) {
				$note_errors['shared_note'] = __( 'ساختار یادداشت مشترک معتبر نیست.', 'didar' );
			} else {
				$shared_note = sanitize_textarea_field( wp_unslash( $_POST['didar_shared_note'] ) );
			}
		}
		$admin_note  = null;
		if ( current_user_can( 'manage_options' ) ) {
			$admin_note = (string) get_post_meta( $post_id, '_didar_admin_note', true );
			if ( isset( $_POST['didar_admin_note'] ) ) {
				if ( is_array( $_POST['didar_admin_note'] ) ) {
					$note_errors['admin_note'] = __( 'ساختار یادداشت داخلی مدیران معتبر نیست.', 'didar' );
				} else {
					$admin_note = sanitize_textarea_field( wp_unslash( $_POST['didar_admin_note'] ) );
				}
			}
		}

		if ( ! $form ) {
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => __( 'نوع فرم نامعتبر است.', 'didar' ) ), $shared_note, $admin_note );
			$this->force_draft( $post_id, $post );
			return;
		}
		if ( $note_errors ) {
			$this->store_admin_state( $post_id, $form_type, $raw, $note_errors, $shared_note, $admin_note );
			return;
		}

		$result = $this->validator->validate( $form_type, $raw, 'admin' );
		if ( ! $result['valid'] ) {
			$this->store_admin_state( $post_id, $form_type, $raw, $result['errors'], $shared_note, $admin_note );
			$this->force_draft( $post_id, $post );
			return;
		}

		$status   = isset( $_POST['didar_status'] ) ? sanitize_key( wp_unslash( $_POST['didar_status'] ) ) : $form['default_status'];
		$owner_id = (int) $post->post_author;
		if ( current_user_can( 'edit_others_didar_submissions' ) && isset( $_POST['didar_owner'] ) ) {
			$requested_owner = absint( wp_unslash( $_POST['didar_owner'] ) );
			if ( get_user_by( 'id', $requested_owner ) ) {
				$owner_id = $requested_owner;
			}
		}
		self::$saving = true;
		$result       = $this->service->update( $post_id, $form_type, $result['data'], $status, $owner_id );
		if ( ! is_wp_error( $result ) ) {
			$this->service->update_notes( $post_id, $shared_note, $admin_note );
		}
		self::$saving = false;
		if ( is_wp_error( $result ) ) {
			$this->store_admin_state( $post_id, $form_type, $raw, array( '_form' => $result->get_error_message() ), $shared_note, $admin_note );
		}
	}

	private function force_draft( $post_id, $post ) {
		if ( 'publish' !== $post->post_status && 'private' !== $post->post_status ) {
			return;
		}
		self::$saving = true;
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		self::$saving = false;
	}

	private function store_admin_state( $post_id, $form_type, $values, $errors, $shared_note = '', $admin_note = null ) {
		set_transient(
			'didar_admin_errors_' . get_current_user_id() . '_' . $post_id,
			array(
				'form_type'   => $form_type,
				'values'      => $values,
				'errors'      => $errors,
				'shared_note' => $shared_note,
				'admin_note'  => $admin_note,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private function get_admin_state( $post_id ) {
		if ( array_key_exists( $post_id, $this->state_cache ) ) {
			return $this->state_cache[ $post_id ];
		}
		$key   = 'didar_admin_errors_' . get_current_user_id() . '_' . $post_id;
		$state = get_transient( $key );
		delete_transient( $key );
		$this->state_cache[ $post_id ] = is_array( $state ) ? $state : array();
		return $this->state_cache[ $post_id ];
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || Didar_Post_Type::POST_TYPE !== $screen->post_type || empty( $_GET['post'] ) ) {
			return;
		}
		$state = $this->get_admin_state( absint( $_GET['post'] ) );
		if ( empty( $state['errors'] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'درخواست ذخیره نشد. خطاهای مشخص‌شده را برطرف کنید.', 'didar' ) . '</strong></p><ul class="didar-admin-errors">';
		foreach ( $state['errors'] as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Didar_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'didar-admin', DIDAR_URL . 'assets/css/admin.css', array(), DIDAR_VERSION );
		wp_enqueue_script( 'didar-admin', DIDAR_URL . 'assets/js/admin.js', array(), DIDAR_VERSION, true );
		wp_localize_script( 'didar-admin', 'didarAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'didar_admin_fields' ), 'loading' => __( 'در حال بارگذاری فیلدها…', 'didar' ), 'error' => __( 'بارگذاری فیلدها انجام نشد.', 'didar' ) ) );
	}

	public function columns( $columns ) {
		return array(
			'cb'            => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox">',
			'submission_id' => __( 'شماره درخواست', 'didar' ),
			'form_type'     => __( 'نوع فرم', 'didar' ),
			'user'          => __( 'کاربر', 'didar' ),
			'status'        => __( 'وضعیت', 'didar' ),
			'date'          => __( 'تاریخ', 'didar' ),
		);
	}

	public function column_content( $column, $post_id ) {
		if ( 'submission_id' === $column ) {
			echo '<strong><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">#' . esc_html( $post_id ) . '</a></strong>';
		} elseif ( 'form_type' === $column ) {
			$type = get_post_meta( $post_id, '_didar_form_type', true );
			$form = $this->registry->get( $type );
			echo esc_html( $form ? $form['label'] : $type );
		} elseif ( 'user' === $column ) {
			$user = get_user_by( 'id', get_post_field( 'post_author', $post_id ) );
			echo $user ? esc_html( $user->display_name . ' — ' . $user->user_email ) : '—';
		} elseif ( 'status' === $column ) {
			echo esc_html( $this->service->get_status_label( get_post_meta( $post_id, '_didar_status', true ) ) );
		}
	}

	public function filters( $post_type ) {
		if ( Didar_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		$selected_type   = isset( $_GET['didar_form_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['didar_form_type_filter'] ) ) : '';
		$selected_status = isset( $_GET['didar_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['didar_status_filter'] ) ) : '';
		echo '<select name="didar_form_type_filter"><option value="">' . esc_html__( 'همه انواع فرم', 'didar' ) . '</option>';
		foreach ( $this->registry->all() as $type => $form ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( $selected_type, $type, false ) . '>' . esc_html( $form['label'] ) . '</option>';
		}
		echo '</select><select name="didar_status_filter"><option value="">' . esc_html__( 'همه وضعیت‌ها', 'didar' ) . '</option>';
		foreach ( Didar_Reference_Data::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Didar_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta_query = array();
		if ( ! empty( $_GET['didar_form_type_filter'] ) ) {
			$type = sanitize_key( wp_unslash( $_GET['didar_form_type_filter'] ) );
			if ( $this->registry->is_valid_type( $type ) ) {
				$meta_query[] = array( 'key' => '_didar_form_type', 'value' => $type );
			}
		}
		if ( ! empty( $_GET['didar_status_filter'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['didar_status_filter'] ) );
			if ( isset( Didar_Reference_Data::statuses()[ $status ] ) ) {
				$meta_query[] = array( 'key' => '_didar_status', 'value' => $status );
			}
		}
		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query );
		}
	}
}
