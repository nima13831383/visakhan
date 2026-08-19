<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Validator {
	private $registry;

	public function __construct( Didar_Form_Registry $registry ) {
		$this->registry = $registry;
	}

	public function validate( $form_type, $submitted, $context = 'frontend', $submission_id = 0 ) {
		$form = $this->registry->get( $form_type );
		if ( ! $form ) {
			return array( 'valid' => false, 'data' => array(), 'errors' => array( '_form' => __( 'نوع فرم نامعتبر است.', 'didar' ) ) );
		}
		if ( ! is_array( $submitted ) ) {
			$submitted = array();
		}

		$data   = array();
		$errors = array();
		foreach ( $this->registry->fields( $form_type ) as $name => $field ) {
			$raw = array_key_exists( $name, $submitted ) ? $submitted[ $name ] : null;

			if ( 'honeypot' === $field['type'] ) {
				if ( null !== $raw && '' !== trim( (string) $raw ) ) {
					$errors['_form'] = __( 'امکان ثبت درخواست وجود نداشت.', 'didar' );
				}
				continue;
			}
			if ( ! empty( $field['internal'] ) ) {
				continue;
			}

			$result = $this->validate_field( $field, $raw, $context, $submission_id );
			if ( is_wp_error( $result ) ) {
				$errors[ $name ] = $result->get_error_message();
				continue;
			}
			$data[ $name ] = $result;
		}

		return array( 'valid' => empty( $errors ), 'data' => $data, 'errors' => $errors );
	}

	private function validate_field( $field, $raw, $context, $submission_id = 0 ) {
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];

		if ( in_array( $type, array( 'checkbox', 'repeater' ), true ) || ( 'time' === $type && ! empty( $field['multiple'] ) ) ) {
			if ( null !== $raw && ! is_array( $raw ) ) {
				return new WP_Error( 'invalid_structure', sprintf( __( 'ساختار فیلد «%s» معتبر نیست.', 'didar' ), $label ) );
			}
			$raw = is_array( $raw ) ? $raw : array();
			if ( $required && empty( array_filter( $raw, array( $this, 'not_empty' ) ) ) ) {
				return $this->required_error( $label );
			}
		} else {
			if ( is_array( $raw ) || is_object( $raw ) ) {
				return new WP_Error( 'invalid_structure', sprintf( __( 'ساختار فیلد «%s» معتبر نیست.', 'didar' ), $label ) );
			}
			$raw = null === $raw ? '' : (string) $raw;
			if ( $required && '' === trim( $raw ) ) {
				return $this->required_error( $label );
			}
		}

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'email':
				$value = sanitize_email( $raw );
				if ( '' !== $raw && ( '' === $value || ! is_email( $value ) ) ) {
					return new WP_Error( 'invalid_email', sprintf( __( 'ایمیل واردشده در «%s» معتبر نیست.', 'didar' ), $label ) );
				}
				return $value;
			case 'number':
				if ( '' === trim( $raw ) ) {
					return '';
				}
				$normalized = $this->normalize_digits( $raw );
				if ( ! is_numeric( $normalized ) ) {
					return new WP_Error( 'invalid_number', sprintf( __( 'مقدار «%s» باید عددی باشد.', 'didar' ), $label ) );
				}
				$number = 0 + $normalized;
				if ( isset( $field['min'] ) && $number < $field['min'] ) {
					return new WP_Error( 'too_small', sprintf( __( 'مقدار «%s» کمتر از حد مجاز است.', 'didar' ), $label ) );
				}
				if ( isset( $field['max'] ) && $number > $field['max'] ) {
					return new WP_Error( 'too_large', sprintf( __( 'مقدار «%s» بیشتر از حد مجاز است.', 'didar' ), $label ) );
				}
				return (string) $number;
			case 'date':
				if ( '' === trim( $raw ) ) {
					return '';
				}
				$value = $this->normalize_digits( sanitize_text_field( $raw ) );
				$date  = DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
					return new WP_Error( 'invalid_date', sprintf( __( 'تاریخ «%s» معتبر نیست.', 'didar' ), $label ) );
				}
				return $value;
			case 'time':
				if ( ! empty( $field['multiple'] ) ) {
					$values = array();
					$limit  = isset( $field['max_items'] ) ? absint( $field['max_items'] ) : 10;
					foreach ( array_slice( $raw, 0, $limit ) as $time ) {
						if ( is_array( $time ) ) {
							return new WP_Error( 'invalid_time', sprintf( __( 'زمان «%s» معتبر نیست.', 'didar' ), $label ) );
						}
						$time = $this->normalize_digits( sanitize_text_field( $time ) );
						if ( '' === $time ) {
							continue;
						}
						if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
							return new WP_Error( 'invalid_time', sprintf( __( 'زمان «%s» معتبر نیست.', 'didar' ), $label ) );
						}
						$values[] = $time;
					}
					return array_values( array_unique( $values ) );
				}
				return sanitize_text_field( $raw );
			case 'select':
			case 'radio':
				if ( '' === trim( $raw ) ) {
					return '';
				}
				$value           = sanitize_key( $raw );
				$allowed_options = $field['options'];
				if ( ! empty( $field['legacy_options'] ) ) {
					$allowed_options = $allowed_options + $field['legacy_options'];
				}
				if ( '' === $value || ! array_key_exists( $value, $allowed_options ) ) {
					if ( 'admin' === $context && ! empty( $field['allow_legacy'] ) ) {
						return sanitize_text_field( $raw );
					}
					return new WP_Error( 'invalid_option', sprintf( __( 'گزینه انتخاب‌شده برای «%s» معتبر نیست.', 'didar' ), $label ) );
				}
				return $value;
			case 'checkbox':
				$values = array();
				foreach ( $raw as $item ) {
					if ( is_array( $item ) ) {
						return new WP_Error( 'invalid_option', sprintf( __( 'گزینه انتخاب‌شده برای «%s» معتبر نیست.', 'didar' ), $label ) );
					}
					$item = sanitize_key( $item );
					if ( ! array_key_exists( $item, $field['options'] ) ) {
						return new WP_Error( 'invalid_option', sprintf( __( 'گزینه انتخاب‌شده برای «%s» معتبر نیست.', 'didar' ), $label ) );
					}
					$values[] = $item;
				}
				return array_values( array_unique( $values ) );
			case 'repeater':
				return $this->validate_repeater( $field, $raw );
			case 'file':
				return $this->validate_attachment( $field, $raw, $context, $submission_id );
			case 'hidden':
			case 'text':
			default:
				return sanitize_text_field( $raw );
		}
	}

	private function validate_repeater( $field, $raw ) {
		$rows  = array();
		$limit = isset( $field['max_items'] ) ? absint( $field['max_items'] ) : 20;
		foreach ( array_slice( $raw, 0, $limit ) as $row ) {
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'invalid_repeater', sprintf( __( 'ساختار فیلد «%s» معتبر نیست.', 'didar' ), $field['label'] ) );
			}
			$clean = array();
			foreach ( $field['columns'] as $column => $column_definition ) {
				$value          = isset( $row[ $column ] ) && ! is_array( $row[ $column ] ) ? $row[ $column ] : '';
				$is_structured  = is_array( $column_definition );
				$column_type    = $is_structured && isset( $column_definition['type'] ) ? $column_definition['type'] : 'text';
				$column_label   = $is_structured && isset( $column_definition['label'] ) ? $column_definition['label'] : $column_definition;
				if ( 'select' === $column_type ) {
					if ( '' === trim( (string) $value ) ) {
						$clean[ $column ] = '';
						continue;
					}
					$option = sanitize_key( $value );
					if ( '' === $option || empty( $column_definition['options'] ) || ! array_key_exists( $option, $column_definition['options'] ) ) {
						return new WP_Error( 'invalid_repeater_option', sprintf( __( 'گزینه انتخاب‌شده برای «%s» معتبر نیست.', 'didar' ), $column_label ) );
					}
					$clean[ $column ] = $option;
				} else {
					$clean[ $column ] = sanitize_text_field( $value );
				}
			}
			if ( array_filter( $clean, array( $this, 'not_empty' ) ) ) {
				$rows[] = $clean;
			}
		}
		return $rows;
	}

	private function validate_attachment( $field, $raw, $context, $submission_id = 0 ) {
		$attachment_id = absint( $raw );
		if ( ! $attachment_id ) {
			return '';
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'invalid_attachment', __( 'فایل انتخاب‌شده معتبر نیست.', 'didar' ) );
		}
		$owner = absint( get_post_meta( $attachment_id, '_didar_temp_owner', true ) );
		if ( $owner ) {
			$temp_form  = get_post_meta( $attachment_id, '_didar_temp_form_type', true );
			$temp_field = get_post_meta( $attachment_id, '_didar_temp_field', true );
			if ( $temp_form !== $field['form_type'] || $temp_field !== $field['name'] ) {
				return new WP_Error( 'invalid_attachment_context', __( 'این فایل برای فیلد دیگری بارگذاری شده است.', 'didar' ) );
			}
		}
		if ( 'admin' !== $context && $owner !== get_current_user_id() ) {
			$attached_submission = absint( get_post_meta( $attachment_id, '_didar_submission_id', true ) );
			$owned_submission    = $submission_id ? get_post( $submission_id ) : null;
			if (
				! $owned_submission ||
				Didar_Post_Type::POST_TYPE !== $owned_submission->post_type ||
				(int) $owned_submission->post_author !== get_current_user_id() ||
				$attached_submission !== (int) $submission_id
			) {
				return new WP_Error( 'invalid_attachment_owner', __( 'شما اجازه استفاده از این فایل را ندارید.', 'didar' ) );
			}
		}
		if ( 'admin' === $context && ! current_user_can( 'edit_post', $attachment_id ) && $owner !== get_current_user_id() ) {
			return new WP_Error( 'invalid_attachment_owner', __( 'شما اجازه استفاده از این فایل را ندارید.', 'didar' ) );
		}
		$allowed = isset( $field['mime_types'] ) ? (array) $field['mime_types'] : array( 'image/jpeg', 'image/png', 'application/pdf' );
		if ( ! in_array( get_post_mime_type( $attachment_id ), $allowed, true ) ) {
			return new WP_Error( 'invalid_attachment_type', __( 'نوع فایل انتخاب‌شده مجاز نیست.', 'didar' ) );
		}
		return $attachment_id;
	}

	private function required_error( $label ) {
		return new WP_Error( 'required', sprintf( __( 'تکمیل فیلد «%s» الزامی است.', 'didar' ), $label ) );
	}

	public function not_empty( $value ) {
		return is_array( $value ) ? ! empty( array_filter( $value, array( $this, 'not_empty' ) ) ) : '' !== trim( (string) $value );
	}

	private function normalize_digits( $value ) {
		return strtr( (string) $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
	}
}
