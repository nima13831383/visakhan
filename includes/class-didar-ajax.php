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
		add_action( 'wp_ajax_didar_upload_profile_document', array( $this, 'upload_profile_document' ) );
		add_action( 'wp_ajax_didar_remove_profile_document', array( $this, 'remove_profile_document' ) );
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
		$field_name    = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? Didar_File_Service::normalize_field_key( wp_unslash( $_POST['field'] ) ) : '';
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$file          = isset( $_FILES['file'] ) ? $_FILES['file'] : array();
		$result        = $this->files->upload( $file, $type, $field_name, $submission_id );
		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$status     = 400;
			if ( in_array( $error_code, array( 'authentication_required', 'forbidden_upload' ), true ) ) {
				$status = 403;
			} elseif ( in_array( $error_code, array( 'didar_database_error', 'didar_file_record_failed', 'storage_unavailable', 'storage_protection_failed' ), true ) ) {
				$status = 500;
			}
			wp_send_json_error( array( 'code' => $error_code, 'message' => $result->get_error_message() ), $status );
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
		$field_name    = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? Didar_File_Service::normalize_field_key( wp_unslash( $_POST['field'] ) ) : '';
		$file_id       = isset( $_POST['file_id'] ) && ! is_array( $_POST['file_id'] ) ? absint( wp_unslash( $_POST['file_id'] ) ) : 0;
		$submission_id = isset( $_POST['submission_id'] ) && ! is_array( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$result = $this->files->remove( $file_id, $type, $field_name, $submission_id );
		if ( is_wp_error( $result ) ) {
			$status = 'invalid_document' === $result->get_error_code() ? 400 : 403;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}
		wp_send_json_success( array( 'file_id' => $file_id, 'message' => __( 'فایل حذف شد.', 'didar' ) ) );
	}

	public function upload_profile_document() {
		if ( ! is_user_logged_in() || false === check_ajax_referer( 'didar_profile_document_upload', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'درخواست معتبر نیست.' ), 403 ); }
		$key = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( ! Didar_Profile_Document_Catalog::definition( $key ) ) { wp_send_json_error( array( 'message' => 'فیلد مدرک معتبر نیست.' ), 400 ); }
		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : array();
		$result = $this->files->upload( $file, 'profile', $key, 0 );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), 400 ); }
		if ( is_wp_error( $promoted = $this->files->promote_profile_file( $result['file_id'], get_current_user_id() ) ) ) { wp_send_json_error( array( 'message' => $promoted->get_error_message() ), 500 ); }
		$old = Didar_Profile_Document_Catalog::get_user_documents( get_current_user_id() )[ $key ] ?? 0;
		Didar_Profile_Document_Catalog::set_user_document( get_current_user_id(), $key, $result['file_id'] );
		if ( $old && absint( $old ) !== absint( $result['file_id'] ) ) { $this->files->delete_profile_file( $old, get_current_user_id() ); }
		$result['download_url'] = $this->files->get_download_url( $result['file_id'] );
		wp_send_json_success( $result );
	}

	public function remove_profile_document() {
		if ( ! is_user_logged_in() || false === check_ajax_referer( 'didar_profile_document_remove', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'درخواست معتبر نیست.' ), 403 ); }
		$key = isset( $_POST['field'] ) && ! is_array( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( ! Didar_Profile_Document_Catalog::definition( $key ) ) { wp_send_json_error( array( 'message' => 'فیلد مدرک معتبر نیست.' ), 400 ); }
		$file_id = absint( Didar_Profile_Document_Catalog::get_user_documents( get_current_user_id() )[ $key ] ?? 0 );
		Didar_Profile_Document_Catalog::remove_user_document( get_current_user_id(), $key );
		if ( $file_id ) { $this->files->delete_profile_file( $file_id, get_current_user_id() ); }
		wp_send_json_success( array( 'file_id' => $file_id, 'message' => 'فایل حذف شد.' ) );
	}
}
