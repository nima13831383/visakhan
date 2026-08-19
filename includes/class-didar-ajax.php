<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Ajax {
	private $registry;
	private $renderer;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer ) {
		$this->registry = $registry;
		$this->renderer = $renderer;

		add_action( 'wp_ajax_didar_upload_file', array( $this, 'upload_file' ) );
		add_action( 'wp_ajax_didar_get_form_fields', array( $this, 'get_form_fields' ) );
		add_action( 'didar_cleanup_temporary_uploads', array( $this, 'cleanup_temporary_uploads' ) );
	}

	public function get_form_fields() {
		if ( false === check_ajax_referer( 'didar_admin_fields', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' ) ), 403 );
		}
		if ( ! current_user_can( 'create_didar_submissions' ) ) {
			wp_send_json_error( array( 'message' => __( 'شما اجازه انجام این کار را ندارید.', 'didar' ) ), 403 );
		}
		$type = isset( $_POST['form_type'] ) && ! is_array( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		$form = $this->registry->get( $type );
		if ( ! $form ) {
			wp_send_json_error( array( 'message' => __( 'نوع فرم نامعتبر است.', 'didar' ) ), 400 );
		}

		ob_start();
		$this->renderer->render_sections( $form, array(), array(), 'admin' );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	public function upload_file() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'برای بارگذاری فایل وارد حساب کاربری شوید.', 'didar' ) ), 401 );
		}
		if ( false === check_ajax_referer( 'didar_upload_file', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' ) ), 403 );
		}

		$type       = isset( $_POST['form_type'] ) && ! is_array( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		$field_name = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields     = $this->registry->fields( $type );
		if ( ! $this->registry->is_valid_type( $type ) || ! isset( $fields[ $field_name ] ) || 'file' !== $fields[ $field_name ]['type'] ) {
			wp_send_json_error( array( 'message' => __( 'فیلد فایل معتبر نیست.', 'didar' ) ), 400 );
		}
		$field = $fields[ $field_name ];

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'فایلی دریافت نشد.', 'didar' ) ), 400 );
		}
		$file = $_FILES['file'];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error( array( 'message' => __( 'بارگذاری فایل با خطا روبه‌رو شد.', 'didar' ) ), 400 );
		}
		$max_size = isset( $field['max_size'] ) ? absint( $field['max_size'] ) : 5 * MB_IN_BYTES;
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max_size ) {
			wp_send_json_error( array( 'message' => __( 'حجم فایل بیش از حد مجاز است.', 'didar' ) ), 400 );
		}

		$allowed_mimes = isset( $field['upload_mimes'] ) ? $field['upload_mimes'] : array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' );
		$checked       = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || ! in_array( $checked['type'], array_values( $allowed_mimes ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'نوع یا پسوند فایل مجاز نیست.', 'didar' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_upload( 'file', 0, array( 'post_title' => __( 'فایل موقت دیدار', 'didar' ) ), array( 'test_form' => false, 'mimes' => $allowed_mimes ) );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'بارگذاری فایل انجام نشد.', 'didar' ) ), 400 );
		}

		$actual_mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $actual_mime, array_values( $allowed_mimes ), true ) ) {
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_error( array( 'message' => __( 'نوع فایل مجاز نیست.', 'didar' ) ), 400 );
		}

		update_post_meta( $attachment_id, '_didar_temp_owner', get_current_user_id() );
		update_post_meta( $attachment_id, '_didar_temp_created', time() );
		update_post_meta( $attachment_id, '_didar_temp_form_type', $type );
		update_post_meta( $attachment_id, '_didar_temp_field', $field_name );

		wp_send_json_success( array( 'attachment_id' => $attachment_id, 'filename' => wp_basename( get_attached_file( $attachment_id ) ), 'message' => __( 'فایل با موفقیت بارگذاری شد.', 'didar' ) ) );
	}

	public function cleanup_temporary_uploads() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => 0,
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_didar_temp_created', 'value' => time() - DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $query->posts as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}
