<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Ajax {
	private $registry;
	private $renderer;
	private $service;
	private $files;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Submission_Service $service = null, Didar_File_Service $files = null ) {
		$this->registry = $registry;
		$this->renderer = $renderer;
		$this->service  = $service ? $service : new Didar_Submission_Service( $registry, new Didar_Event_Log() );
		$this->files    = $files ? $files : new Didar_File_Service( $registry, new Didar_Settings(), new Didar_Event_Log() );
		$this->files->set_submission_service( $this->service );

		add_action( 'wp_ajax_didar_upload_file', array( $this, 'upload_file' ) );
		add_action( 'wp_ajax_didar_remove_file', array( $this, 'remove_file' ) );
		add_action( 'wp_ajax_didar_get_form_fields', array( $this, 'get_form_fields' ) );
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

		$type          = isset( $_POST['form_type'] ) && ! is_array( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		$field_name    = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$file          = isset( $_FILES['file'] ) ? $_FILES['file'] : array();
		$result        = $this->files->upload( $file, $type, $field_name, $submission_id );
		if ( is_wp_error( $result ) ) {
			$status = in_array( $result->get_error_code(), array( 'authentication_required', 'forbidden_upload' ), true ) ? 403 : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}
		$result['message'] = __( 'فایل با موفقیت بارگذاری شد.', 'didar' );
		wp_send_json_success( $result );
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
		$file_id       = isset( $_POST['file_id'] ) && ! is_array( $_POST['file_id'] ) ? absint( wp_unslash( $_POST['file_id'] ) ) : 0;
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$result = $this->files->remove( $file_id, $type, $field_name, $submission_id );
		if ( is_wp_error( $result ) ) {
			$status = 'invalid_document' === $result->get_error_code() ? 400 : 403;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}
		wp_send_json_success( array( 'file_id' => $file_id, 'message' => __( 'فایل حذف شد.', 'didar' ) ) );
	}
}
