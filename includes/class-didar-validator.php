<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Validator {
	private $registry;
	private $settings;
	private $files;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings = null, Didar_File_Service $files = null ) {
		$this->registry = $registry;
		$this->settings = $settings ? $settings : new Didar_Settings();
		$this->files    = $files ? $files : new Didar_File_Service( $registry, $this->settings, new Didar_Event_Log() );
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
			$field['required'] = $this->settings->is_required( $form_type, $name, ! empty( $field['required'] ) );
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

		if ( in_array( $type, array( 'checkbox', 'repeater' ), true ) || ( in_array( $type, array( 'time', 'file' ), true ) && ! empty( $field['multiple'] ) ) ) {
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
			if ( $required && '' === trim( $raw ) && ! $this->has_legacy_required_fallback( $field, $submission_id ) ) {
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
				$value = ( new Didar_Date_Service() )->normalize_input( sanitize_text_field( $raw ) );
				if ( ! $value ) {
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
				return $this->validate_file( $field, $raw, $context, $submission_id );
			case 'hidden':
			case 'text':
			default:
				return sanitize_text_field( $raw );
		}
	}

	private function has_legacy_required_fallback( $field, $submission_id ) {
		if ( ! $submission_id || empty( $field['legacy_required_fallback'] ) ) {
			return false;
		}
		$stored = get_post_meta( absint( $submission_id ), '_didar_fields', true );
		$key    = sanitize_key( $field['legacy_required_fallback'] );
		return is_array( $stored ) && isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) && '' !== trim( (string) $stored[ $key ] );
	}

	private function validate_repeater( $field, $raw ) {
		$rows  = array();
		$limit = isset( $field['max_items'] ) ? absint( $field['max_items'] ) : 20;
		if ( count( $raw ) > $limit ) {
			return new WP_Error( 'too_many_repeater_items', sprintf( __( 'تعداد ردیف‌های «%s» بیش از حد مجاز است.', 'didar' ), $field['label'] ) );
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'invalid_repeater', sprintf( __( 'ساختار فیلد «%s» معتبر نیست.', 'didar' ), $field['label'] ) );
			}
			$clean = array();
			foreach ( $field['columns'] as $column => $column_definition ) {
				if ( isset( $row[ $column ] ) && ( is_array( $row[ $column ] ) || is_object( $row[ $column ] ) ) ) {
					return new WP_Error( 'invalid_repeater_value', sprintf( __( 'ساختار مقدار «%s» معتبر نیست.', 'didar' ), is_array( $column_definition ) ? $column_definition['label'] : $column_definition ) );
				}
				$value          = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
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
				} elseif ( 'email' === $column_type ) {
					$email = sanitize_email( $value );
					if ( '' !== trim( $value ) && ( '' === $email || ! is_email( $email ) ) ) {
						return new WP_Error( 'invalid_repeater_email', sprintf( __( 'ایمیل واردشده در «%s» معتبر نیست.', 'didar' ), $column_label ) );
					}
					$clean[ $column ] = $email;
				} elseif ( 'number' === $column_type ) {
					$normalized = $this->normalize_digits( $value );
					if ( '' !== trim( $normalized ) && ! is_numeric( $normalized ) ) {
						return new WP_Error( 'invalid_repeater_number', sprintf( __( 'مقدار «%s» باید عددی باشد.', 'didar' ), $column_label ) );
					}
					$clean[ $column ] = '' === trim( $normalized ) ? '' : (string) ( 0 + $normalized );
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

	private function validate_file( $field, $raw, $context, $submission_id = 0 ) {
		if ( ! empty( $field['multiple'] ) ) {
			if ( ! is_array( $raw ) ) {
				return new WP_Error( 'invalid_file_structure', __( 'ساختار فایل‌های انتخاب‌شده معتبر نیست.', 'didar' ) );
			}
			$max_files = ! empty( $field['max_files'] ) ? absint( $field['max_files'] ) : 1;
			if ( count( $raw ) > $max_files ) {
				return new WP_Error( 'too_many_files', sprintf( __( 'برای «%s» حداکثر %d فایل مجاز است.', 'didar' ), $field['label'], $max_files ) );
			}
			$file_ids = array();
			foreach ( $raw as $file_id ) {
				if ( is_array( $file_id ) || is_object( $file_id ) ) {
					return new WP_Error( 'invalid_file_structure', __( 'ساختار فایل‌های انتخاب‌شده معتبر نیست.', 'didar' ) );
				}
				$validated = $this->validate_file_id( $field, $file_id, $context, $submission_id );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				if ( $validated ) {
					$file_ids[] = $validated;
				}
			}
			$file_ids = array_values( array_unique( $file_ids ) );
			if ( count( $file_ids ) > $max_files ) {
				return new WP_Error( 'too_many_files', sprintf( __( 'برای «%s» حداکثر %d فایل مجاز است.', 'didar' ), $field['label'], $max_files ) );
			}
			return $file_ids;
		}

		return $this->validate_file_id( $field, $raw, $context, $submission_id );
	}

	private function validate_file_id( $field, $raw, $context, $submission_id = 0 ) {
		return $this->files->validate_file_id( $field, $raw, $context, $submission_id );
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
