<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Frontend WordPress-profile editor. Person synchronization remains in Didar_Sync_Manager. */
class Didar_User_Profile {
	private $registry;
	private $settings;
	private $sync;
	private $logger;
	private $mapper;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Sync_Manager $sync, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->sync     = $sync;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->mapper   = new Didar_Field_Mapper( $registry, $settings, null, $this->logger );
		add_shortcode( 'didar_profile_form', array( $this, 'shortcode' ) );
	}

	public function shortcode( $atts = array() ) {
		wp_enqueue_style( 'didar-frontend', DIDAR_URL . 'assets/css/frontend.css', array(), DIDAR_VERSION );
		if ( ! is_user_logged_in() ) {
			return $this->notice( 'برای ویرایش اطلاعات کاربری ابتدا وارد حساب خود شوید.', 'warning' );
		}

		wp_enqueue_script( 'didar-user-profile', DIDAR_URL . 'assets/js/user-profile.js', array(), DIDAR_VERSION, true );
		$user    = wp_get_current_user();
		$notice  = $this->profile_success_notice();
		$errors  = array();
		if ( $this->is_profile_request() ) {
			$result = $this->save_current_user( $user );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$redirect = add_query_arg( 'didar_profile_updated', '1', $this->profile_redirect_url() );
				if ( wp_safe_redirect( $redirect ) ) {
					exit;
				}
				$notice = $result['notice'];
				$user   = wp_get_current_user();
			}
		}

		$profile = $this->mapper->wordpress_user_profile( $user );
		ob_start();
		echo '<div class="didar-app didar-profile-form" dir="rtl">';
		if ( $notice ) { echo $this->notice( $notice, 'success' ); }
		foreach ( $errors as $error ) { echo $this->notice( $error, 'error' ); }
		echo '<form method="post" enctype="multipart/form-data" class="didar-form" novalidate>';
		wp_nonce_field( 'didar_profile_update', 'didar_profile_nonce' );
		echo '<input type="hidden" name="didar_profile_action" value="update">';
		$this->profile_image_area( $user, $profile['profile_image_url'] );
		echo '<header class="didar-form-header didar-profile-edit"><p class="didar-eyebrow">حساب کاربری</p><h2>ویرایش اطلاعات کاربری</h2></header>';
		echo '<div class="didar-section didar-profile-section"><div class="didar-grid">';
		$this->text_field( 'first_name', 'نام', $profile['first_name'] );
		$this->text_field( 'last_name', 'نام خانوادگی', $profile['last_name'] );
		$this->text_field( 'display_name', 'نام نمایشی', $profile['display_name'] );
		$this->gender_field( $profile['gender'] );
		$this->text_field( 'mobile', 'شماره تلفن', $profile['mobile'], 'tel' );
		$this->text_field( 'email', 'ایمیل', $profile['email'], 'email' );
		echo '</div></div>';
		echo '<div class="didar-actions"><button type="submit" class="didar-submit"><span>ذخیره تغییرات</span><span class="didar-spinner" aria-hidden="true"></span></button></div>';
		echo '</form></div>';
		return ob_get_clean();
	}

	private function profile_image_area( $user, $url ) {
		$state = $this->settings->profile_field_state( 'profile_image' );
		if ( 'disabled' === $state ) { return; }

		echo '<div class="didar-profile-image-area">';
		echo '<div class="didar-profile-avatar">';
		if ( $url ) {
			echo '<img src="' . esc_url( $url ) . '" alt="تصویر پروفایل" width="120" height="120">';
		} else {
			$avatar = get_avatar( $user->ID, 120, '', 'تصویر پروفایل', array( 'class' => array( 'didar-profile-avatar__image' ) ) );
			if ( $avatar ) {
				echo $avatar;
			} else {
				$initial = function_exists( 'mb_substr' ) ? mb_substr( $user->display_name ?: $user->user_login, 0, 1, 'UTF-8' ) : substr( $user->display_name ?: $user->user_login, 0, 1 );
				echo '<span class="didar-profile-avatar__fallback" aria-hidden="true">' . esc_html( $initial ) . '</span>';
			}
		}
		echo '</div>';
		if ( 'editable' === $state ) {
			echo '<input id="didar-profile-image" class="screen-reader-text" type="file" name="didar_profile_image" accept="image/jpeg,image/png,image/gif,image/webp">';
			echo '<label class="didar-profile-image-picker" for="didar-profile-image">تغییر تصویر</label>';
		}
		echo '</div>';
	}

	private function is_profile_request() {
		return isset( $_POST['didar_profile_action'], $_POST['didar_profile_nonce'] )
			&& ! is_array( $_POST['didar_profile_action'] )
			&& 'update' === sanitize_key( wp_unslash( $_POST['didar_profile_action'] ) );
	}

	private function profile_success_notice() {
		if ( isset( $_GET['didar_profile_updated'] ) && ! is_array( $_GET['didar_profile_updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['didar_profile_updated'] ) ) ) {
			return 'اطلاعات کاربری با موفقیت بروزرسانی شد.';
		}
		return '';
	}

	private function profile_redirect_url() {
		$referer = wp_validate_redirect( wp_get_referer(), '' );
		if ( $referer ) {
			return remove_query_arg( array( 'didar_profile_updated', 'didar_profile_action', 'didar_profile_nonce' ), $referer );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && ! is_array( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return remove_query_arg( array( 'didar_profile_updated', 'didar_profile_action', 'didar_profile_nonce' ), home_url( $request_uri ) );
	}

	private function save_current_user( $user ) {
		$nonce = isset( $_POST['didar_profile_nonce'] ) && ! is_array( $_POST['didar_profile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['didar_profile_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'didar_profile_update' ) || ! $user || ! $user->ID ) {
			return new WP_Error( 'didar_profile_invalid_request', 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.' );
		}

		$submitted = isset( $_POST['didar_profile'] ) && is_array( $_POST['didar_profile'] ) ? wp_unslash( $_POST['didar_profile'] ) : array();
		$current   = $this->mapper->wordpress_user_profile( $user );
		$update    = array( 'ID' => $user->ID );
		$meta      = array();
		foreach ( array( 'first_name', 'last_name', 'display_name', 'email', 'gender', 'mobile' ) as $field ) {
			if ( ! array_key_exists( $field, $submitted ) || is_array( $submitted[ $field ] ) ) { continue; }
			$state = $this->settings->profile_field_state( $field );
			$value = 'email' === $field ? sanitize_email( $submitted[ $field ] ) : ( 'gender' === $field ? sanitize_text_field( $submitted[ $field ] ) : sanitize_text_field( $submitted[ $field ] ) );
			if ( 'disabled' === $state ) {
				// Disabled fields are not part of the form contract; forged values
				// are ignored and leave the existing WordPress value untouched.
				continue;
			}
			if ( 'editable' !== $state ) {
				if ( $value !== (string) ( $current[ $field ] ?? '' ) ) {
					return new WP_Error( 'didar_profile_readonly', 'تغییر یکی از فیلدهای فقط‌خواندنی یا غیرفعال مجاز نیست.' );
				}
				continue;
			}
			if ( 'email' === $field ) {
				if ( ! is_email( $value ) ) { return new WP_Error( 'didar_profile_email_invalid', 'نشانی ایمیل معتبر نیست.' ); }
				$owner = email_exists( $value );
				if ( $owner && absint( $owner ) !== absint( $user->ID ) ) { return new WP_Error( 'didar_profile_email_exists', 'این نشانی ایمیل قبلاً استفاده شده است.' ); }
				$update['user_email'] = $value;
			} elseif ( 'gender' === $field ) {
				if ( ! in_array( $value, array( 'male', 'female' ), true ) ) { return new WP_Error( 'didar_profile_gender_invalid', 'مقدار جنسیت معتبر نیست.' ); }
				$meta['gender'] = $value;
			} elseif ( 'display_name' === $field ) {
				$update['display_name'] = $value;
			} else {
				$meta[ $field ] = $value;
			}
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_user( $update );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		foreach ( $meta as $key => $value ) { update_user_meta( $user->ID, $key, $value ); }
		$image = $this->save_profile_image( $user->ID );
		if ( is_wp_error( $image ) ) { return $image; }

		$this->logger->log( 'INFO', 'didar_profile_updated', 'WordPress profile updated by the current user.', array( 'wp_user_id' => $user->ID, 'source' => 'frontend_profile' ) );
		$sync = $this->sync->sync_user_now( $user->ID, 'frontend_profile' );
		if ( is_wp_error( $sync ) ) {
			$this->logger->log( 'WARNING', 'didar_profile_sync_queued', 'WordPress profile saved; Didar Person sync was queued for retry.', array( 'wp_user_id' => $user->ID, 'error_code' => $sync->get_error_code(), 'source' => 'frontend_profile' ) );
			return array( 'notice' => 'اطلاعات شما ذخیره شد. همگام‌سازی دیدار در پس‌زمینه دوباره تلاش می‌شود.' );
		}
		return array( 'notice' => 'اطلاعات کاربری با موفقیت ذخیره شد.' );
	}

	private function save_profile_image( $user_id ) {
		if ( 'editable' !== $this->settings->profile_field_state( 'profile_image' ) || empty( $_FILES['didar_profile_image'] ) ) { return true; }
		$file = $_FILES['didar_profile_image'];
		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) { return true; }
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 5 * MB_IN_BYTES ) { return new WP_Error( 'didar_profile_image_invalid', 'بارگذاری تصویر ناموفق بود یا حجم آن بیش از ۵ مگابایت است.' ); }
		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$type    = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $type['type'] ) || ! in_array( $type['type'], $allowed, true ) ) { return new WP_Error( 'didar_profile_image_type', 'فقط تصویرهای JPG، PNG، GIF یا WebP مجاز هستند.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$attachment_id = media_handle_upload( 'didar_profile_image', 0, array( 'post_author' => absint( $user_id ) ), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $attachment_id ) ) { return new WP_Error( 'didar_profile_image_upload', 'تصویر پروفایل ذخیره نشد.' ); }
		update_user_meta( $user_id, 'profile_image', absint( $attachment_id ) );
		return true;
	}

	private function text_field( $field, $label, $value, $type = 'text' ) {
		$state = $this->settings->profile_field_state( $field );
		if ( 'disabled' === $state ) { return; }
		$id = 'didar-profile-' . sanitize_html_class( $field );
		echo '<p class="didar-field"><label class="didar-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( 'readonly' === $state ? ' <small>فقط خواندنی</small>' : '' ) . '</label>';
		echo '<input id="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $label ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'readonly' === $state ? ' readonly aria-readonly="true"' : ' name="didar_profile[' . esc_attr( $field ) . ']"' ) . '>';
		if ( 'mobile' === $field && 'readonly' === $state ) { echo '<small class="didar-description">شماره همراه از طریق سیستم ورود سایت مدیریت می‌شود.</small>'; }
		echo '</p>';
	}

	private function gender_field( $value ) {
		$state = $this->settings->profile_field_state( 'gender' );
		if ( 'disabled' === $state ) { return; }
		$options = array( 'female' => 'زن', 'male' => 'مرد' );
		$html = '<p class="didar-field"><label class="didar-label" for="didar-profile-gender">جنسیت' . ( 'readonly' === $state ? ' <small>فقط خواندنی</small>' : '' ) . '</label><select id="didar-profile-gender" aria-label="جنسیت"' . ( 'readonly' === $state ? ' disabled aria-readonly="true"' : ' name="didar_profile[gender]"' ) . '>';
		if ( '' === $value ) { $html .= '<option value="" disabled selected>— انتخاب جنسیت —</option>'; }
		foreach ( $options as $option_value => $option_label ) { $html .= '<option value="' . esc_attr( $option_value ) . '" ' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>'; }
		echo $html . '</select></p>';
	}

	private function notice( $message, $type ) { return '<div class="didar-notice didar-notice--' . esc_attr( $type ) . '" role="status">' . esc_html( $message ) . '</div>'; }
}
