<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Submission_Service {
	private $registry;
	private $events;
	private $settings;
	private $files;
	private $logger;
	private $workflow;

	public function __construct( Didar_Form_Registry $registry, Didar_Event_Log $events, Didar_Settings $settings = null, Didar_File_Service $files = null ) {
		$this->registry = $registry;
		$this->events   = $events;
		$this->settings = $settings ? $settings : new Didar_Settings();
		$this->files    = $files ? $files : new Didar_File_Service( $registry, $this->settings, $events );
		$this->logger   = new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $this->settings, $this->logger );
		$this->files->set_submission_service( $this );
	}

	public function create( $form_type, $data, $author_id, $shared_note = '' ) {
		$form      = $this->registry->get( $form_type );
		$author_id = absint( $author_id );
		if ( ! $form || ! $author_id || ! get_user_by( 'id', $author_id ) ) {
			return new WP_Error( 'invalid_submission', __( 'امکان ثبت درخواست وجود نداشت.', 'didar' ) );
		}
		if ( $author_id !== get_current_user_id() && ! current_user_can( 'didar_change_request_owner' ) ) {
			return new WP_Error( 'forbidden_owner', __( 'شما اجازه ثبت درخواست برای این کاربر را ندارید.', 'didar' ) );
		}

		$default_status = $this->workflow->default_status( $form_type, $form['default_status'] );
		if ( ! $default_status ) {
			return new WP_Error( 'workflow_default_missing', __( 'وضعیت پیش‌فرض گردش کار این فرم مشخص نیست.', 'didar' ) );
		}
		$post_id        = wp_insert_post(
			array(
				'post_type'   => Didar_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => sprintf( '%s — %s', $form['label'], current_time( 'Y-m-d H:i' ) ),
				'meta_input'  => array(
					'_didar_form_type'          => $form_type,
					'_didar_created_by_user_id' => get_current_user_id(),
					'_didar_status'             => $default_status,
					'_didar_public_status'      => $default_status,
					'_didar_public_note'        => '',
					'_didar_internal_status'    => $default_status,
					'_didar_internal_note'      => '',
					'_didar_assigned_user_id'   => '',
					'_didar_fields'             => $data,
					'_didar_shared_note'        => sanitize_textarea_field( $shared_note ),
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'insert_failed', __( 'امکان ثبت درخواست وجود نداشت.', 'didar' ) );
		}

		$this->events->add(
			$post_id,
			'request_created',
			null,
			array( 'form_type' => $form_type, 'owner_user_id' => $author_id, 'public_status' => $default_status, 'internal_status' => $default_status )
		);
		$this->apply_default_assignee( $post_id, $form_type );
		$this->logger->log( 'INFO', 'submission_saved', 'WordPress submission saved.', array( 'entity_type' => 'submission', 'local_id' => $post_id, 'wp_user_id' => $author_id, 'form_type' => $form_type, 'source' => 'submission_service' ) );
		$this->attach_files( $post_id, $form_type, $data, array(), true );
		do_action( 'didar_submission_created', $post_id );
		return $post_id;
	}

	/** Create a local submission from a verified Didar webhook and an existing WP user. */
	public function create_from_didar( $form_type, $data, $author_id, $shared_note = '' ) {
		$form = $this->registry->get( $form_type );
		$author_id = absint( $author_id );
		if ( ! $form || ! $author_id || ! get_user_by( 'id', $author_id ) ) {
			return new WP_Error( 'invalid_external_submission', __( 'اطلاعات درخواست ورودی از دیدار کامل نیست.', 'didar' ) );
		}
		$default_status = $this->workflow->default_status( $form_type, $form['default_status'] );
		if ( ! $default_status ) { return new WP_Error( 'workflow_default_missing', __( 'وضعیت پیش‌فرض گردش کار این فرم مشخص نیست.', 'didar' ) ); }
		$post_id = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $author_id, 'post_title' => sprintf( '%s — %s', $form['label'], current_time( 'Y-m-d H:i' ) ), 'meta_input' => array( '_didar_form_type' => $form_type, '_didar_created_by_user_id' => 0, '_didar_status' => $default_status, '_didar_public_status' => $default_status, '_didar_public_note' => '', '_didar_internal_status' => $default_status, '_didar_internal_note' => '', '_didar_assigned_user_id' => '', '_didar_fields' => (array) $data, '_didar_shared_note' => sanitize_textarea_field( $shared_note ) ) ), true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }
		$this->events->add( $post_id, 'request_created', null, array( 'form_type' => $form_type, 'owner_user_id' => $author_id, 'source' => 'Didar' ) );
		$this->apply_default_assignee( $post_id, $form_type );
		return $post_id;
	}

	public function update( $post_id, $form_type, $data, $status, $author_id = 0 ) {
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';
		$post = get_post( $post_id );
		$form = $this->registry->get( $form_type );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! $form ) {
			return new WP_Error( 'invalid_submission', __( 'درخواست معتبر نیست.', 'didar' ) );
		}
		if ( ! Didar_Access_Control::can_edit_request( $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'شما اجازه ویرایش این درخواست را ندارید.', 'didar' ) );
		}
		if ( current_user_can( 'didar_change_public_status' ) && ! isset( Didar_Reference_Data::statuses()[ $status ] ) ) {
			return new WP_Error( 'invalid_status', __( 'وضعیت انتخاب‌شده معتبر نیست.', 'didar' ) );
		}

		$stored_type = (string) get_post_meta( $post_id, '_didar_form_type', true );
		if ( $stored_type && $stored_type !== $form_type ) {
			return new WP_Error( 'immutable_form_type', __( 'نوع فرم درخواست قابل تغییر نیست.', 'didar' ) );
		}

		$is_creation = '' === $stored_type;
		$old_fields  = $this->get_fields( $post_id );
		$old_owner   = (int) $post->post_author;
		$data        = $this->preserve_inactive_fields( $form_type, $old_fields, $data );

		update_post_meta( $post_id, '_didar_form_type', $form_type );
		update_post_meta( $post_id, '_didar_fields', $data );
		if ( ! metadata_exists( 'post', $post_id, '_didar_created_by_user_id' ) ) {
			update_post_meta( $post_id, '_didar_created_by_user_id', get_current_user_id() );
		}
		$internal_default_status = $this->workflow->default_status( $form_type, $form['default_status'] );
		if ( ! $internal_default_status ) {
			return new WP_Error( 'workflow_default_missing', __( 'وضعیت پیش‌فرض گردش کار این فرم مشخص نیست.', 'didar' ) );
		}
		$this->ensure_workflow_defaults( $post_id, $internal_default_status );

		$post_update = array(
			'ID'         => $post_id,
			'post_title' => sprintf( '%s — #%d', $form['label'], $post_id ),
		);
		if ( $author_id && (int) $author_id !== $old_owner && current_user_can( 'didar_change_request_owner' ) && get_user_by( 'id', $author_id ) ) {
			$post_update['post_author'] = absint( $author_id );
		}
		wp_update_post( wp_slash( $post_update ) );

		if ( $is_creation ) {
			$this->events->add(
				$post_id,
				'request_created',
				null,
				array( 'form_type' => $form_type, 'owner_user_id' => isset( $post_update['post_author'] ) ? (int) $post_update['post_author'] : $old_owner )
			);
			$this->apply_default_assignee( $post_id, $form_type );
		} else {
			$this->record_data_changes( $post_id, $form_type, $old_fields, $data );
			if ( isset( $post_update['post_author'] ) ) {
				$this->events->add( $post_id, 'request_owner_changed', $old_owner, (int) $post_update['post_author'] );
			}
		}

		$this->attach_files( $post_id, $form_type, $data, $old_fields, true );

		if ( current_user_can( 'didar_change_public_status' ) ) {
			$workflow = $this->update_workflow( $post_id, array( 'public_status' => $status ) );
			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
		}
		do_action( 'didar_submission_updated', $post_id );
		return true;
	}

	public function get_owned_submission( $post_id, $user_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || (int) $post->post_author !== (int) $user_id ) {
			return null;
		}
		return $post;
	}

	public function user_can_view_all_requests( $user_id = 0 ) {
		$user_id = absint( $user_id ? $user_id : get_current_user_id() );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		return $user && user_can( $user, 'didar_view_all_requests' );
	}

	public function scope_query_args( $args, $user_id = 0 ) {
		$user_id = absint( $user_id ? $user_id : get_current_user_id() );
		if ( ! $this->user_can_view_all_requests( $user_id ) ) {
			$args['author'] = $user_id;
		}

		return $args;
	}

	public function get_accessible_submission( $post_id, $user_id = 0 ) {
		$user_id = absint( $user_id ? $user_id : get_current_user_id() );
		$post    = get_post( absint( $post_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		if ( $this->user_can_view_all_requests( $user_id ) || (int) $post->post_author === $user_id ) {
			return $post;
		}

		return null;
	}

	public function can_view_submission( $post_id, $user_id = 0 ) {
		$user_id = absint( $user_id ? $user_id : get_current_user_id() );
		$post    = get_post( absint( $post_id ) );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type || ! $user_id ) {
			return false;
		}

		return $this->user_can_view_all_requests( $user_id ) || (int) $post->post_author === $user_id;
	}

	public function is_owner_editable( $post_id, $user_id ) {
		return $this->get_owned_submission( $post_id, $user_id ) && 'completed' !== $this->get_public_status_raw( $post_id );
	}

	public function can_edit_from_frontend( $post_id, $user_id = 0 ) {
		$user_id = absint( $user_id ? $user_id : get_current_user_id() );
		$post    = $this->get_accessible_submission( $post_id, $user_id );
		if ( ! $post ) {
			return false;
		}
		if ( $this->user_can_view_all_requests( $user_id ) ) {
			$user = get_userdata( $user_id );

			return $user && user_can( $user, 'didar_edit_requests' ) && user_can( $user, 'edit_post', $post->ID );
		}

		return $this->is_owner_editable( $post_id, $user_id );
	}

	public function update_by_owner( $post_id, $data, $shared_note, $user_id ) {
		$post = $this->get_owned_submission( $post_id, $user_id );
		if ( ! $post ) {
			return new WP_Error( 'forbidden', __( 'این درخواست در دسترس شما نیست.', 'didar' ) );
		}
		if ( ! $this->is_owner_editable( $post_id, $user_id ) ) {
			return new WP_Error( 'completed_submission', __( 'درخواست تکمیل‌شده دیگر قابل ویرایش نیست.', 'didar' ) );
		}

		return $this->update_frontend_data( $post_id, $data, $shared_note );
	}

	public function update_from_frontend( $post_id, $data, $shared_note, $user_id = 0 ) {
		$current_user_id = get_current_user_id();
		$user_id         = absint( $user_id ? $user_id : $current_user_id );
		if ( ! $current_user_id || $user_id !== $current_user_id ) {
			return new WP_Error( 'forbidden', __( 'این درخواست در دسترس شما نیست.', 'didar' ) );
		}
		$post            = $this->get_accessible_submission( $post_id, $user_id );
		if ( ! $post ) {
			return new WP_Error( 'forbidden', __( 'این درخواست در دسترس شما نیست.', 'didar' ) );
		}
		if ( ! $this->can_edit_from_frontend( $post_id, $user_id ) ) {
			return new WP_Error( 'completed_submission', __( 'این درخواست قابل ویرایش نیست.', 'didar' ) );
		}

		return $this->update_frontend_data( $post_id, $data, $shared_note );
	}

	private function update_frontend_data( $post_id, $data, $shared_note ) {
		$form_type = (string) get_post_meta( $post_id, '_didar_form_type', true );
		if ( ! $this->registry->is_valid_type( $form_type ) ) {
			return new WP_Error( 'invalid_submission', __( 'درخواست معتبر نیست.', 'didar' ) );
		}

		$old_fields = $this->get_fields( $post_id );
		$old_note   = $this->get_shared_note( $post_id );
		$new_note   = sanitize_textarea_field( $shared_note );
		$data       = $this->preserve_inactive_fields( $form_type, $old_fields, $data );
		update_post_meta( $post_id, '_didar_fields', $data );
		update_post_meta( $post_id, '_didar_shared_note', $new_note );
		$this->record_data_changes( $post_id, $form_type, $old_fields, $data );
		if ( $old_note !== $new_note ) {
			$this->events->add( $post_id, 'applicant_note_changed', $old_note, $new_note );
		}
		$this->attach_files( $post_id, $form_type, $data, $old_fields, true );
		wp_update_post( array( 'ID' => $post_id ) );
		do_action( 'didar_submission_updated', $post_id );
		return true;
	}

	public function update_workflow( $post_id, $changes, $internal = false ) {
		$post = get_post( $post_id );
		if ( ! $post || ( ! $internal && ! Didar_Access_Control::can_edit_request( $post_id ) ) || ! is_array( $changes ) ) {
			return new WP_Error( 'forbidden', __( 'شما اجازه انجام این کار را ندارید.', 'didar' ) );
		}

		$definitions = array(
			'public_status'   => array( 'cap' => 'didar_change_public_status', 'meta' => '_didar_public_status', 'event' => 'public_status_changed', 'type' => 'status' ),
			'public_note'     => array( 'cap' => 'didar_edit_public_notes', 'meta' => '_didar_public_note', 'event' => 'public_note_changed', 'type' => 'note' ),
			'internal_status' => array( 'cap' => 'didar_change_internal_status', 'meta' => '_didar_internal_status', 'event' => 'internal_status_changed', 'type' => 'status' ),
			'internal_note'   => array( 'cap' => 'didar_add_internal_notes', 'meta' => '_didar_internal_note', 'event' => 'internal_note_changed', 'type' => 'note' ),
			'assigned_user_id' => array( 'cap' => 'didar_assign_requests', 'meta' => '_didar_assigned_user_id', 'event' => 'assignment_changed', 'type' => 'assignment' ),
		);

		$prepared = array();
		foreach ( $changes as $key => $value ) {
			if ( ! isset( $definitions[ $key ] ) || ( $internal && 'assigned_user_id' !== $key ) || ( ! $internal && ! current_user_can( $definitions[ $key ]['cap'] ) ) ) {
				return new WP_Error( 'forbidden_workflow_change', __( 'شما اجازه تغییر این بخش از گردش کار را ندارید.', 'didar' ) );
			}
			$definition = $definitions[ $key ];
			if ( 'status' === $definition['type'] ) {
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_status', __( 'وضعیت انتخاب‌شده معتبر نیست.', 'didar' ) );
				}
				$value = sanitize_key( (string) $value );
				$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
				$valid_statuses = 'internal_status' === $key ? $this->workflow->statuses( $form_type ) : Didar_Reference_Data::statuses();
				if ( ! isset( $valid_statuses[ $value ] ) ) {
					return new WP_Error( 'invalid_status', __( 'وضعیت انتخاب‌شده معتبر نیست.', 'didar' ) );
				}
			} elseif ( 'note' === $definition['type'] ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					return new WP_Error( 'invalid_note', __( 'ساختار یادداشت معتبر نیست.', 'didar' ) );
				}
				$value = sanitize_textarea_field( $value );
			} else {
				if ( is_array( $value ) || is_object( $value ) ) {
					return new WP_Error( 'invalid_assignee', __( 'کاربر انتخاب‌شده مجاز به دریافت درخواست نیست.', 'didar' ) );
				}
				$value = absint( $value );
				if ( $value && ! $this->is_eligible_assignee( $value ) ) {
					return new WP_Error( 'invalid_assignee', __( 'کاربر انتخاب‌شده مجاز به دریافت درخواست نیست.', 'didar' ) );
				}
			}
			$prepared[ $key ] = array( 'definition' => $definition, 'value' => $value );
		}

		$changed_keys = array();
		foreach ( $prepared as $key => $item ) {
			$definition = $item['definition'];
			$new_value  = $item['value'];
			if ( 'public_status' === $key ) {
				$old_value = $this->get_public_status_raw( $post_id );
			} elseif ( 'internal_status' === $key ) {
				$old_value = $this->get_internal_status_raw( $post_id );
			} elseif ( 'internal_note' === $key ) {
				$old_value = $this->get_internal_note_raw( $post_id );
			} else {
				$old_value = get_post_meta( $post_id, $definition['meta'], true );
				if ( 'assignment' === $definition['type'] ) {
					$old_value = absint( $old_value );
				}
			}

			if ( $old_value === $new_value ) {
				continue;
			}
			$changed_keys[] = $key;
			update_post_meta( $post_id, $definition['meta'], $new_value );
			if ( 'public_status' === $key ) {
				update_post_meta( $post_id, '_didar_status', $new_value );
			}
			if ( 'internal_note' === $key ) {
				update_post_meta( $post_id, '_didar_admin_note', $new_value );
			}

			$event_type = $definition['event'];
			if ( 'assigned_user_id' === $key ) {
				$event_type = ! $new_value ? 'assignment_removed' : ( $old_value ? 'request_reassigned' : 'request_assigned' );
			}
			$meta = array();
			if ( $internal && 'assigned_user_id' === $key ) {
				$meta = array( 'form_type' => sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) ), 'source' => 'default_form_assignee' );
			}
			if ( 'internal_status' === $key ) {
				$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
				$mapping = $this->workflow->mapping( $form_type, $new_value );
				$meta = array( 'form_type' => $form_type, 'old_status_key' => $old_value, 'old_status_label' => $this->workflow->status_label( $form_type, $old_value ), 'new_status_key' => $new_value, 'new_status_label' => $this->workflow->status_label( $form_type, $new_value ), 'pipeline_id' => $mapping['pipeline_id'] ?? '', 'pipeline_stage_id' => $mapping['stage_id'] ?? '', 'source' => 'wordpress', 'actor' => get_current_user_id() );
			}
			$this->events->add( $post_id, $event_type, $old_value, $new_value, $meta );
		}
		if ( $changed_keys ) {
			do_action( 'didar_submission_workflow_changed', $post_id, $changed_keys );
		}

		return true;
	}

	public function update_notes( $post_id, $shared_note, $admin_note = null ) {
		$post = get_post( $post_id );
		if ( ! $post || ! Didar_Access_Control::can_edit_request( $post_id ) ) {
			return false;
		}

		$old_note = $this->get_shared_note( $post_id );
		$new_note = sanitize_textarea_field( $shared_note );
		if ( $old_note !== $new_note ) {
			update_post_meta( $post_id, '_didar_shared_note', $new_note );
			$this->events->add( $post_id, 'applicant_note_changed', $old_note, $new_note );
		}
		if ( null !== $admin_note ) {
			$result = $this->update_workflow( $post_id, array( 'internal_note' => $admin_note ) );
			return ! is_wp_error( $result );
		}
		do_action( 'didar_submission_updated', $post_id );
		return true;
	}

	public function get_fields( $post_id ) {
		$fields = get_post_meta( $post_id, '_didar_fields', true );
		return is_array( $fields ) ? $fields : array();
	}

	public function get_applicant_name_parts( $post_id ) {
		$fields     = $this->get_fields( $post_id );
		$first_name = isset( $fields['first_name'] ) && is_scalar( $fields['first_name'] ) ? trim( sanitize_text_field( (string) $fields['first_name'] ) ) : '';
		$last_name  = isset( $fields['last_name'] ) && is_scalar( $fields['last_name'] ) ? trim( sanitize_text_field( (string) $fields['last_name'] ) ) : '';
		if ( '' !== $first_name || '' !== $last_name ) {
			return array( 'first_name' => $first_name, 'last_name' => $last_name );
		}

		$combined = '';
		foreach ( array( 'full_name', 'input_1' ) as $combined_key ) {
			if ( isset( $fields[ $combined_key ] ) && is_scalar( $fields[ $combined_key ] ) ) {
				$combined = trim( sanitize_text_field( (string) $fields[ $combined_key ] ) );
				if ( '' !== $combined ) {
					break;
				}
			}
		}
		if ( '' === $combined ) {
			return array( 'first_name' => '', 'last_name' => '' );
		}

		$parts = preg_split( '/\s+/u', $combined, 2 );
		return array(
			'first_name' => isset( $parts[0] ) ? $parts[0] : '',
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	public function get_shared_note( $post_id ) {
		return (string) get_post_meta( $post_id, '_didar_shared_note', true );
	}

	public function get_public_status( $post_id ) {
		return $this->can_view_public( $post_id ) ? $this->get_public_status_raw( $post_id ) : '';
	}

	public function get_public_note( $post_id ) {
		return $this->can_view_public( $post_id ) ? (string) get_post_meta( $post_id, '_didar_public_note', true ) : '';
	}

	public function get_internal_status( $post_id ) {
		return $this->can_view_internal( $post_id ) ? $this->get_internal_status_raw( $post_id ) : '';
	}

	public function get_internal_note( $post_id ) {
		return $this->can_view_internal( $post_id ) ? $this->get_internal_note_raw( $post_id ) : '';
	}

	public function get_assigned_user_id( $post_id ) {
		return $this->can_view_internal( $post_id ) ? absint( get_post_meta( $post_id, '_didar_assigned_user_id', true ) ) : 0;
	}

	public function get_creator_user_id( $post_id ) {
		$creator_id = absint( get_post_meta( $post_id, '_didar_created_by_user_id', true ) );
		return $creator_id ? $creator_id : absint( get_post_field( 'post_author', $post_id ) );
	}

	/** The canonical request customer/owner is the submission post_author, not the last editor. */
	public function get_owner_user_id( $post_id ) {
		return absint( get_post_field( 'post_author', absint( $post_id ) ) );
	}

	public function can_view_public( $post_id ) {
		return $this->can_view_submission( $post_id, get_current_user_id() );
	}

	public function can_view_internal( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( current_user_can( 'didar_view_internal_workflow' ) && $this->can_view_submission( $post_id, get_current_user_id() ) ) {
			return true;
		}
		return $this->settings->colleague_can_view_internal_history() && current_user_can( 'didar_view_own_internal_workflow' ) && (int) $post->post_author === get_current_user_id();
	}

	public function can_view_history( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || Didar_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( current_user_can( 'didar_view_request_history' ) && $this->can_view_submission( $post_id, get_current_user_id() ) ) {
			return true;
		}
		return $this->settings->colleague_can_view_internal_history() && current_user_can( 'didar_view_own_request_history' ) && (int) $post->post_author === get_current_user_id();
	}

	public function get_events( $post_id, $limit = 100 ) {
		return $this->can_view_history( $post_id ) ? $this->events->get_for_submission( $post_id, $limit ) : array();
	}

	public function eligible_assignees() {
		return get_users(
			array(
				'capability' => 'didar_receive_requests',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'display_name', 'user_email', 'user_login' ),
			)
		);
	}

	public function default_assignee_id( $form_type ) {
		$form_type = sanitize_key( (string) $form_type );
		$defaults  = $this->settings->all();
		$user_id   = absint( $defaults['didar_form_default_assignees'][ $form_type ] ?? 0 );
		return $user_id && $this->is_eligible_assignee( $user_id ) ? $user_id : 0;
	}

	private function apply_default_assignee( $post_id, $form_type ) {
		$user_id = $this->default_assignee_id( $form_type );
		if ( ! $user_id || $this->get_assigned_user_id( $post_id ) ) {
			return false;
		}
		$result = $this->update_workflow( $post_id, array( 'assigned_user_id' => $user_id ), true );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		return true;
	}

	public function is_eligible_assignee( $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		return $user && user_can( $user, 'didar_receive_requests' );
	}

	public function get_status_label( $status ) {
		$statuses = Didar_Reference_Data::statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : __( 'نامشخص', 'didar' );
	}

	public function get_event_label( $event_type ) {
		$labels = array(
			'request_created'          => __( 'درخواست ایجاد شد', 'didar' ),
			'public_status_changed'    => __( 'وضعیت عمومی تغییر کرد', 'didar' ),
			'public_note_changed'      => __( 'یادداشت عمومی تغییر کرد', 'didar' ),
			'internal_status_changed'  => __( 'وضعیت داخلی تغییر کرد', 'didar' ),
			'internal_note_changed'    => __( 'یادداشت داخلی تغییر کرد', 'didar' ),
			'request_assigned'         => __( 'درخواست ارجاع شد', 'didar' ),
			'request_reassigned'       => __( 'مسئول درخواست تغییر کرد', 'didar' ),
			'assignment_removed'       => __( 'ارجاع درخواست حذف شد', 'didar' ),
			'submission_data_updated'  => __( 'اطلاعات درخواست ویرایش شد', 'didar' ),
			'applicant_note_changed'   => __( 'یادداشت متقاضی تغییر کرد', 'didar' ),
			'file_added'               => __( 'فایل افزوده شد', 'didar' ),
			'file_replaced'            => __( 'فایل جایگزین شد', 'didar' ),
			'file_removed'             => __( 'فایل حذف شد', 'didar' ),
			'request_owner_changed'    => __( 'مالک درخواست تغییر کرد', 'didar' ),
		);
		return isset( $labels[ $event_type ] ) ? $labels[ $event_type ] : __( 'فعالیت درخواست', 'didar' );
	}

	public function get_event_actor_label( $event ) {
		$actor = ! empty( $event['actor_user_id'] ) ? get_user_by( 'id', $event['actor_user_id'] ) : false;
		if ( $actor ) {
			return $actor->display_name;
		}
		if ( ! empty( $event['event_meta']['actor_label'] ) ) {
			return (string) $event['event_meta']['actor_label'];
		}
		return __( 'سیستم', 'didar' );
	}

	public function get_event_context_label( $event ) {
		if ( empty( $event['event_meta'] ) || ! is_array( $event['event_meta'] ) ) {
			return '';
		}
		if ( ! empty( $event['event_meta']['field_label'] ) ) {
			return (string) $event['event_meta']['field_label'];
		}
		return ! empty( $event['event_meta']['field_name'] ) ? (string) $event['event_meta']['field_name'] : '';
	}

	public function format_event_value( $event_type, $value ) {
		if ( null === $value || '' === $value || array() === $value || 0 === $value ) {
			return '—';
		}
		if ( in_array( $event_type, array( 'public_status_changed', 'internal_status_changed' ), true ) ) {
			return $this->get_status_label( $value );
		}
		if ( in_array( $event_type, array( 'request_assigned', 'request_reassigned', 'assignment_removed', 'request_owner_changed' ), true ) ) {
			$user = get_user_by( 'id', absint( $value ) );
			return $user ? $user->display_name : sprintf( __( 'کاربر #%d', 'didar' ), absint( $value ) );
		}
		if ( in_array( $event_type, array( 'file_added', 'file_replaced', 'file_removed' ), true ) && is_scalar( $value ) ) {
			$record = $this->files->get( absint( $value ) );
			return $record ? $record['original_name'] : sprintf( __( 'فایل دیدار #%d', 'didar' ), absint( $value ) );
		}
		if ( is_array( $value ) ) {
			return $this->format_event_array( $value );
		}
		return (string) $value;
	}

	private function format_event_array( $value ) {
		$parts = array();
		foreach ( $value as $key => $item ) {
			$rendered = is_array( $item ) ? $this->format_event_array( $item ) : (string) $item;
			$parts[]  = is_string( $key ) ? $key . ': ' . $rendered : $rendered;
		}
		return implode( ' | ', $parts );
	}

	public function format_event_time( $created_at_gmt ) {
		$local = get_date_from_gmt( $created_at_gmt, 'Y-m-d H:i:s' );
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $local ) );
	}

	public function format_event_datetime_attribute( $created_at_gmt ) {
		return gmdate( DATE_W3C, strtotime( $created_at_gmt . ' UTC' ) );
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
		if ( is_scalar( $value ) && ! empty( $field['legacy_display_options'] ) && isset( $field['legacy_display_options'][ $value ] ) ) {
			return $field['legacy_display_options'][ $value ];
		}
		if ( 'file' === $field['type'] ) {
			$file_ids = is_array( $value ) ? $value : array( $value );
			$labels   = array();
			foreach ( $file_ids as $file_id ) {
				$file_id = absint( $file_id );
				if ( ! $file_id ) {
					continue;
				}
				$record = $this->files->get( $file_id );
				if ( $record ) {
					$labels[] = $record['original_name'];
				}
			}
			return $labels ? implode( '، ', $labels ) : '—';
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

	/**
	 * Keep data that was loaded from storage but is no longer in the active schema.
	 *
	 * Submitted inactive keys are never accepted; only existing database values are
	 * merged back into the validated active payload.
	 */
	private function preserve_inactive_fields( $form_type, $stored_fields, $active_data ) {
		$active_definitions = $this->registry->fields( $form_type );
		$inactive_data      = array_diff_key( (array) $stored_fields, $active_definitions );
		return array_merge( $inactive_data, (array) $active_data );
	}

	private function ensure_workflow_defaults( $post_id, $default_status ) {
		$defaults = array(
			'_didar_public_status'    => $this->get_public_status_raw( $post_id ) ?: $default_status,
			'_didar_public_note'      => '',
			'_didar_internal_status'  => $default_status,
			'_didar_internal_note'    => $this->get_internal_note_raw( $post_id ),
			'_didar_assigned_user_id' => '',
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	private function get_public_status_raw( $post_id ) {
		$status = (string) get_post_meta( $post_id, '_didar_public_status', true );
		if ( ! $status ) {
			$status = (string) get_post_meta( $post_id, '_didar_status', true );
		}
		return isset( Didar_Reference_Data::statuses()[ $status ] ) ? $status : 'pending_review';
	}

	private function get_internal_status_raw( $post_id ) {
		$status = (string) get_post_meta( $post_id, '_didar_internal_status', true );
		$form_type = sanitize_key( (string) get_post_meta( $post_id, '_didar_form_type', true ) );
		return isset( $this->workflow->statuses( $form_type )[ $status ] ) ? $status : $this->workflow->default_status( $form_type, 'pending_review' );
	}

	private function get_internal_note_raw( $post_id ) {
		if ( metadata_exists( 'post', $post_id, '_didar_internal_note' ) ) {
			return (string) get_post_meta( $post_id, '_didar_internal_note', true );
		}
		return (string) get_post_meta( $post_id, '_didar_admin_note', true );
	}

	private function record_data_changes( $post_id, $form_type, $old_data, $new_data ) {
		$old_changed = array();
		$new_changed = array();
		foreach ( $this->registry->fields( $form_type ) as $name => $field ) {
			$old_value = array_key_exists( $name, $old_data ) ? $old_data[ $name ] : '';
			$new_value = array_key_exists( $name, $new_data ) ? $new_data[ $name ] : '';
			if ( maybe_serialize( $old_value ) === maybe_serialize( $new_value ) || 'file' === $field['type'] ) {
				continue;
			}
			$old_changed[ $field['label'] ] = $old_value;
			$new_changed[ $field['label'] ] = $new_value;
		}
		if ( $new_changed ) {
			$this->events->add( $post_id, 'submission_data_updated', $old_changed, $new_changed, array( 'form_type' => $form_type ) );
		}
	}

	private function attach_files( $post_id, $form_type, $data, $old_data = array(), $log_changes = false ) {
		$this->files->finalize_submission_files( $post_id, $form_type, $data, $old_data, $log_changes );
	}

	public function remove_document( $post_id, $form_type, $field_name, $file_id ) {
		return $this->files->remove( $file_id, $form_type, $field_name, $post_id );
	}
}
