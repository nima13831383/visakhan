<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Submission_Service {
	private $registry;

	public function __construct( Didar_Form_Registry $registry ) {
		$this->registry = $registry;
	}

	public function create( $form_type, $data, $author_id, $shared_note = '' ) {
		$form = $this->registry->get( $form_type );
		if ( ! $form || ! $author_id ) {
			return new WP_Error( 'invalid_submission', __( 'امکان ثبت درخواست وجود نداشت.', 'didar' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Didar_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => absint( $author_id ),
				'post_title'  => sprintf( '%s — %s', $form['label'], current_time( 'Y-m-d H:i' ) ),
				'meta_input'  => array(
					'_didar_form_type'  => $form_type,
					'_didar_status'     => $form['default_status'],
					'_didar_fields'     => $data,
					'_didar_shared_note' => sanitize_textarea_field( $shared_note ),
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'insert_failed', __( 'امکان ثبت درخواست وجود نداشت.', 'didar' ) );
		}

		$this->attach_files( $post_id, $form_type, $data );
		return $post_id;
	}

	public function update( $post_id, $form_type, $data, $status, $author_id = 0 ) {
		$post = get_post( $post_id );
		$form = $this->registry->get( $form_type );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! $form ) {
			return new WP_Error( 'invalid_submission', __( 'درخواست معتبر نیست.', 'didar' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'شما اجازه ویرایش این درخواست را ندارید.', 'didar' ) );
		}
		$statuses = Didar_Reference_Data::statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			$status = $form['default_status'];
		}

		update_post_meta( $post_id, '_didar_form_type', $form_type );
		update_post_meta( $post_id, '_didar_status', $status );
		update_post_meta( $post_id, '_didar_fields', $data );
		$this->attach_files( $post_id, $form_type, $data );

		$post_update = array(
			'ID'         => $post_id,
			'post_title' => sprintf( '%s — #%d', $form['label'], $post_id ),
		);
		if ( $author_id && (int) $author_id !== (int) $post->post_author && current_user_can( 'edit_others_didar_submissions' ) && get_user_by( 'id', $author_id ) ) {
			$post_update['post_author'] = absint( $author_id );
		}
		wp_update_post( wp_slash( $post_update ) );
		return true;
	}

	public function get_owned_submission( $post_id, $user_id ) {
		$post = get_post( absint( $post_id ) );
		if (
			! $post ||
			Didar_Post_Type::POST_TYPE !== $post->post_type ||
			'publish' !== $post->post_status ||
			(int) $post->post_author !== (int) $user_id
		) {
			return null;
		}

		return $post;
	}

	public function is_owner_editable( $post_id, $user_id ) {
		$post = $this->get_owned_submission( $post_id, $user_id );
		if ( ! $post ) {
			return false;
		}

		return 'completed' !== get_post_meta( $post->ID, '_didar_status', true );
	}

	public function update_by_owner( $post_id, $data, $shared_note, $user_id ) {
		$post = $this->get_owned_submission( $post_id, $user_id );
		if ( ! $post ) {
			return new WP_Error( 'forbidden', __( 'این درخواست در دسترس شما نیست.', 'didar' ) );
		}
		if ( ! $this->is_owner_editable( $post_id, $user_id ) ) {
			return new WP_Error( 'completed_submission', __( 'درخواست تکمیل‌شده دیگر قابل ویرایش نیست.', 'didar' ) );
		}

		$form_type = get_post_meta( $post_id, '_didar_form_type', true );
		if ( ! $this->registry->is_valid_type( $form_type ) ) {
			return new WP_Error( 'invalid_submission', __( 'درخواست معتبر نیست.', 'didar' ) );
		}

		update_post_meta( $post_id, '_didar_fields', $data );
		update_post_meta( $post_id, '_didar_shared_note', sanitize_textarea_field( $shared_note ) );
		$this->attach_files( $post_id, $form_type, $data );
		wp_update_post( array( 'ID' => $post_id ) );

		return true;
	}

	public function get_shared_note( $post_id ) {
		return (string) get_post_meta( $post_id, '_didar_shared_note', true );
	}

	public function update_notes( $post_id, $shared_note, $admin_note = null ) {
		$post = get_post( $post_id );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		update_post_meta( $post_id, '_didar_shared_note', sanitize_textarea_field( $shared_note ) );
		if ( null !== $admin_note && current_user_can( 'manage_options' ) ) {
			update_post_meta( $post_id, '_didar_admin_note', sanitize_textarea_field( $admin_note ) );
		}

		return true;
	}

	public function get_fields( $post_id ) {
		$fields = get_post_meta( $post_id, '_didar_fields', true );
		return is_array( $fields ) ? $fields : array();
	}

	public function get_status_label( $status ) {
		$statuses = Didar_Reference_Data::statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : __( 'نامشخص', 'didar' );
	}

	public function format_value( $field, $value ) {
		if ( '' === $value || null === $value || array() === $value ) {
			return '—';
		}
		if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
			$options = $field['options'];
			if ( ! empty( $field['legacy_options'] ) ) {
				$options = $options + $field['legacy_options'];
			}
			return isset( $options[ $value ] ) ? $options[ $value ] : (string) $value;
		}
		if ( 'file' === $field['type'] ) {
			$attachment_id = absint( $value );
			$title         = $attachment_id ? get_the_title( $attachment_id ) : '';
			return $title ? $title : sprintf( __( 'فایل شماره %d', 'didar' ), $attachment_id );
		}
		if ( 'checkbox' === $field['type'] && is_array( $value ) ) {
			$labels = array();
			foreach ( $value as $item ) {
				$labels[] = isset( $field['options'][ $item ] ) ? $field['options'][ $item ] : $item;
			}
			return implode( '، ', $labels );
		}
		if ( 'repeater' === $field['type'] && is_array( $value ) ) {
			$rows = array();
			foreach ( $value as $row ) {
				$rows[] = implode( ' | ', array_filter( array_map( 'strval', (array) $row ) ) );
			}
			return implode( "\n", $rows );
		}
		if ( is_array( $value ) ) {
			return implode( '، ', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}

	private function attach_files( $post_id, $form_type, $data ) {
		foreach ( $this->registry->fields( $form_type ) as $name => $field ) {
			if ( 'file' !== $field['type'] || empty( $data[ $name ] ) ) {
				continue;
			}
			$attachment_id = absint( $data[ $name ] );
			if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
			delete_post_meta( $attachment_id, '_didar_temp_owner' );
			update_post_meta( $attachment_id, '_didar_submission_id', $post_id );
		}
	}
}
