<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Ajax {
	private $registry;
	private $renderer;
	private $service;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Submission_Service $service = null ) {
		$this->registry = $registry;
		$this->renderer = $renderer;
		$this->service  = $service ? $service : new Didar_Submission_Service( $registry, new Didar_Event_Log() );

		add_action( 'wp_ajax_didar_upload_file', array( $this, 'upload_file' ) );
		add_action( 'wp_ajax_didar_remove_file', array( $this, 'remove_file' ) );
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
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$fields     = $this->registry->fields( $type );
		if ( ! $this->registry->is_valid_type( $type ) || ! isset( $fields[ $field_name ] ) || 'file' !== $fields[ $field_name ]['type'] ) {
			wp_send_json_error( array( 'message' => __( 'فیلد فایل معتبر نیست.', 'didar' ) ), 400 );
		}
		$field = $fields[ $field_name ];
		if ( $submission_id && ! $this->can_edit_submission_documents( $submission_id, $type ) ) {
			wp_send_json_error( array( 'message' => __( 'شما اجازه بارگذاری فایل برای این درخواست را ندارید.', 'didar' ) ), 403 );
		}

		$max_files = ! empty( $field['max_files'] ) ? absint( $field['max_files'] ) : 1;
		if ( $this->document_count( $type, $field_name, $submission_id ) >= $max_files ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'برای این فیلد حداکثر %d فایل مجاز است.', 'didar' ), $max_files ) ), 400 );
		}

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) || ! isset( $_FILES['file']['error'], $_FILES['file']['size'], $_FILES['file']['tmp_name'], $_FILES['file']['name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'فایلی دریافت نشد.', 'didar' ) ), 400 );
		}
		$file = $_FILES['file'];
		foreach ( array( 'error', 'size', 'tmp_name', 'name' ) as $file_key ) {
			if ( is_array( $file[ $file_key ] ) || is_object( $file[ $file_key ] ) ) {
				wp_send_json_error( array( 'message' => __( 'ساختار فایل دریافتی معتبر نیست.', 'didar' ) ), 400 );
			}
		}
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
		update_post_meta( $attachment_id, '_didar_temp_submission_id', $submission_id );
		if ( $this->document_count( $type, $field_name, $submission_id ) > $max_files ) {
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_error( array( 'message' => sprintf( __( 'برای این فیلد حداکثر %d فایل مجاز است.', 'didar' ), $max_files ) ), 400 );
		}

		wp_send_json_success( array( 'attachment_id' => $attachment_id, 'filename' => wp_basename( get_attached_file( $attachment_id ) ), 'message' => __( 'فایل با موفقیت بارگذاری شد.', 'didar' ) ) );
	}

	public function remove_file() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'برای حذف فایل وارد حساب کاربری شوید.', 'didar' ) ), 401 );
		}
		if ( false === check_ajax_referer( 'didar_remove_file', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' ) ), 403 );
		}

		$type          = isset( $_POST['form_type'] ) && ! is_array( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		$field_name    = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) && ! is_array( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$fields        = $this->registry->fields( $type );
		if ( ! isset( $fields[ $field_name ] ) || 'file' !== $fields[ $field_name ]['type'] || ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'فایل انتخاب‌شده معتبر نیست.', 'didar' ) ), 400 );
		}

		$temp_owner      = absint( get_post_meta( $attachment_id, '_didar_temp_owner', true ) );
		$temp_form       = (string) get_post_meta( $attachment_id, '_didar_temp_form_type', true );
		$temp_field      = (string) get_post_meta( $attachment_id, '_didar_temp_field', true );
		$temp_submission = absint( get_post_meta( $attachment_id, '_didar_temp_submission_id', true ) );
		if ( $temp_owner ) {
			if ( $temp_owner !== get_current_user_id() || $temp_form !== $type || $temp_field !== $field_name || $temp_submission !== $submission_id ) {
				wp_send_json_error( array( 'message' => __( 'شما اجازه حذف این فایل را ندارید.', 'didar' ) ), 403 );
			}
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_success( array( 'attachment_id' => $attachment_id, 'message' => __( 'فایل حذف شد.', 'didar' ) ) );
		}

		if ( ! $submission_id || ! $this->can_edit_submission_documents( $submission_id, $type ) ) {
			wp_send_json_error( array( 'message' => __( 'شما اجازه حذف این فایل را ندارید.', 'didar' ) ), 403 );
		}
		$result = $this->service->remove_document( $submission_id, $type, $field_name, $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		wp_send_json_success( array( 'attachment_id' => $attachment_id, 'message' => __( 'فایل حذف شد.', 'didar' ) ) );
	}

	private function can_edit_submission_documents( $submission_id, $form_type ) {
		$post = get_post( absint( $submission_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || (string) get_post_meta( $submission_id, '_didar_form_type', true ) !== $form_type ) {
			return false;
		}

		return ( current_user_can( 'didar_edit_requests' ) && current_user_can( 'edit_post', $submission_id ) ) || $this->service->is_owner_editable( $submission_id, get_current_user_id() );
	}

	private function document_count( $form_type, $field_name, $submission_id ) {
		$count = 0;
		if ( $submission_id ) {
			$data  = $this->service->get_fields( $submission_id );
			$count = isset( $data[ $field_name ] ) ? count( array_unique( array_filter( array_map( 'absint', (array) $data[ $field_name ] ) ) ) ) : 0;
		}

		$meta_query = array(
			'relation' => 'AND',
			array( 'key' => '_didar_temp_owner', 'value' => get_current_user_id(), 'compare' => '=', 'type' => 'NUMERIC' ),
			array( 'key' => '_didar_temp_form_type', 'value' => $form_type, 'compare' => '=' ),
			array( 'key' => '_didar_temp_field', 'value' => $field_name, 'compare' => '=' ),
			array( 'key' => '_didar_temp_submission_id', 'value' => absint( $submission_id ), 'compare' => '=', 'type' => 'NUMERIC' ),
		);
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $meta_query,
			)
		);

		return $count + (int) $query->found_posts;
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
