<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Field_Renderer {
	private $settings;
	private $files;
	private $profile_catalog;
	private $profile_resolver;

	public function __construct( Didar_Settings $settings = null, Didar_File_Service $files = null ) {
		$this->settings = $settings ? $settings : new Didar_Settings();
		$this->files    = $files;
		$this->profile_catalog = new Didar_User_Profile_Value_Catalog();
	}

	public function set_profile_resolver( $resolver ) { $this->profile_resolver = is_callable( $resolver ) ? $resolver : null; }

	public function render_sections( $form, $values = array(), $errors = array(), $context = 'frontend', $submission_id = 0 ) {
		$form_type = isset( $form['type'] ) ? sanitize_key( $form['type'] ) : '';
		$profile_form = 'frontend' === $context && ! $submission_id && in_array( $form_type, array( 'embassy_appointment', 'visa_request' ), true );
		$profile      = array();
		if ( 'frontend' === $context && is_user_logged_in() && is_callable( $this->profile_resolver ) ) {
			$user    = wp_get_current_user();
			$profile = call_user_func( $this->profile_resolver, $user );
			$profile = is_array( $profile ) ? $profile : array();
		}
		$request_for = $this->request_for_value( $form_type, $form, $values );
		$resolved_values = array();
		foreach ( $form['sections'] as $section_key => $section ) {
			$visible_fields = array_filter(
				$section['fields'],
				function ( $field ) use ( $context ) {
					if ( ! empty( $field['internal'] ) && 'honeypot' !== $field['type'] ) {
						return false;
					}
					return ! ( 'admin' === $context && 'honeypot' === $field['type'] );
				}
			);

			if ( empty( $visible_fields ) ) {
				continue;
			}

			echo '<fieldset class="didar-section" data-section="' . esc_attr( $section_key ) . '">';
			echo '<legend>' . esc_html( $section['label'] ) . '</legend>';
			if ( ! empty( $section['description'] ) ) {
				echo '<p class="didar-section-description">' . esc_html( $section['description'] ) . '</p>';
			}

			echo '<div class="didar-grid">';
			foreach ( $visible_fields as $field ) {
				$field['form_type'] = $form_type;
				$field['required']  = $this->settings->is_required( $form_type, $field['name'], ! empty( $field['required'] ) );
				$has_value = array_key_exists( $field['name'], $values );
				$default   = isset( $field['default'] ) ? $field['default'] : '';
				if ( ! empty( $field['default_value_options'] ) ) { $default = $this->settings->field_default_value( $form_type, $field['name'], $default, $field['options'] ?? array() ); }
				$value     = $has_value ? $values[ $field['name'] ] : $default;
				$profile_source = $this->profile_source_for_field( $form_type, $field );
				$profile_value  = $this->profile_value_for_field( $field, $profile_source, $profile );
				$profile_ready  = $profile_form && '' !== $profile_source && $this->profile_value_present( $profile_value );
				if ( $profile_ready && ( ! $profile_form || 'self' === $request_for ) ) {
					$field['_profile_source'] = $profile_source;
					$field['_profile_value']  = $profile_value;
					if ( ( 'date' === ( $field['type'] ?? '' ) || 'date' === ( $field['semantic'] ?? '' ) ) && is_scalar( $profile_value ) ) {
						$field['_profile_display_value'] = ( new Didar_Date_Service() )->format_for_display( $profile_value );
					}
				}
				if ( ! $has_value && $this->profile_value_present( $profile_value ) && ( ! $profile_form || 'self' === $request_for ) ) {
					$value = $profile_value;
					if ( $profile_form ) {
						$field['_profile_origin'] = true;
					}
				}
				if ( ( 'date' === ( $field['type'] ?? '' ) || 'date' === ( $field['semantic'] ?? '' ) ) && array_key_exists( $field['name'] . '_display', $values ) && is_scalar( $values[ $field['name'] . '_display' ] ) ) { $field['_display_value'] = (string) $values[ $field['name'] . '_display' ]; }
				if ( ! empty( $field['conditional_on'] ) ) {
					$parent_name                 = sanitize_key( (string) $field['conditional_on'] );
					$parent_value                = isset( $resolved_values[ $parent_name ] ) ? $resolved_values[ $parent_name ] : ( $values[ $parent_name ] ?? '' );
					$field['_conditional_active'] = is_scalar( $parent_value ) && (string) $parent_value === (string) ( $field['conditional_value'] ?? 'yes' );
				}
				$resolved_values[ $field['name'] ] = $value;
				$error = isset( $errors[ $field['name'] ] ) ? $errors[ $field['name'] ] : '';
				if ( ! empty( $field['required'] ) && '' === (string) $value && ! empty( $field['legacy_required_fallback'] ) && ! empty( $values[ $field['legacy_required_fallback'] ] ) ) {
					$field['required']    = false;
					$field['description'] = __( 'در این درخواست قدیمی، مقدار نام ترکیبی به‌صورت جداگانه در بخش اطلاعات تاریخی حفظ شده است.', 'didar' );
				}
				$this->render_field( $field, $value, $error, $context, $submission_id );
			}
			echo '</div></fieldset>';
		}
	}

	private function request_for_value( $form_type, $form, $values ) {
		if ( isset( $values['request_for'] ) && is_scalar( $values['request_for'] ) && in_array( sanitize_key( $values['request_for'] ), array( 'self', 'other' ), true ) ) {
			return sanitize_key( $values['request_for'] );
		}
		foreach ( $form['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( 'request_for' === ( $field['name'] ?? '' ) ) {
					$default = $field['default'] ?? 'self';
					if ( ! empty( $field['default_value_options'] ) ) { $default = $this->settings->field_default_value( $form_type, 'request_for', $default, $field['options'] ?? array() ); }
					return sanitize_key( (string) $default );
				}
			}
		}
		return 'self';
	}

	private function profile_source_for_field( $form_type, $field ) {
		if ( 'request_for' === ( $field['name'] ?? '' ) ) {
			return '';
		}
		$configured = $this->settings->profile_default_source( $form_type, $field['name'] );
		if ( $configured ) {
			return $configured;
		}
		if ( in_array( $form_type, array( 'embassy_appointment', 'visa_request' ), true ) && ! empty( $field['profile_autofill'] ) && is_scalar( $field['profile_autofill'] ) ) {
			return sanitize_key( (string) $field['profile_autofill'] );
		}
		return '';
	}

	private function profile_value_for_field( $field, $source, $profile ) {
		if ( ! $source || ! isset( $profile[ $source ] ) ) {
			return '';
		}
		$value = $profile[ $source ];
		if ( 'file' === ( $field['type'] ?? '' ) ) {
			return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : ( absint( $value ) ? array( absint( $value ) ) : array() );
		}
		if ( ! is_scalar( $value ) ) { return ''; }
		$value = (string) $value;
		if ( ! empty( $field['profile_value_map'] ) && isset( $field['profile_value_map'][ $value ] ) ) {
			$value = (string) $field['profile_value_map'][ $value ];
		}
		return $value;
	}

	private function profile_value_present( $value ) {
		return is_array( $value ) ? ! empty( array_filter( $value ) ) : '' !== (string) $value;
	}

	public function render_field( $field, $value = '', $error = '', $context = 'frontend', $submission_id = 0 ) {
		if ( ! empty( $field['form_type'] ) && ! empty( $field['name'] ) && Didar_Form_Registry::supports_placeholder( $field ) ) {
			$field['placeholder'] = $this->settings->field_placeholder( $field['form_type'], $field['name'], $field['placeholder'] ?? '' );
		}
		if ( ! empty( $field['form_type'] ) && ! empty( $field['name'] ) ) {
			$field['required'] = $this->settings->is_required( $field['form_type'], $field['name'], ! empty( $field['required'] ) );
		}
		$name       = $field['name'];
		$id         = 'didar-' . $context . '-' . sanitize_html_class( $name );
		$type       = $field['type'];
		$wide       = in_array( $type, array( 'textarea', 'checkbox', 'radio', 'repeater', 'file' ), true ) || ( 'select' === $type && ! empty( $field['multiple'] ) && empty( $field['half_width'] ) );
		$is_conditionally_hidden = isset( $field['_conditional_active'] ) && ! $field['_conditional_active'];
		$classes    = 'didar-field didar-field--' . sanitize_html_class( $type ) . ( $wide ? ' didar-field--wide' : '' ) . ( $error ? ' didar-field--error' : '' ) . ( $is_conditionally_hidden ? ' didar-conditional-hidden' : '' );
		$described  = array();
		$input_name = 'didar_fields[' . $name . ']';

		if ( 'honeypot' === $type ) {
			echo '<div class="didar-honeypot" aria-hidden="true">';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" value="" tabindex="-1" autocomplete="off">';
			echo '</div>';
			return;
		}

		if ( ! empty( $field['description'] ) || ! empty( $field['display_format'] ) ) {
			$described[] = $id . '-description';
		}
		if ( $error ) {
			$described[] = $id . '-error';
		}
		$described[] = $id . '-live-error';

		$conditional_attributes = '';
		if ( ! empty( $field['conditional_on'] ) ) {
			$conditional_attributes .= ' data-didar-conditional-on="' . esc_attr( sanitize_key( $field['conditional_on'] ) ) . '"';
			$conditional_attributes .= ' data-didar-conditional-value="' . esc_attr( sanitize_key( $field['conditional_value'] ?? 'yes' ) ) . '"';
		}
		echo '<div class="' . esc_attr( $classes ) . '" data-didar-field="' . esc_attr( sanitize_key( $name ) ) . '"' . $conditional_attributes . ( $is_conditionally_hidden ? ' hidden="hidden" aria-hidden="true"' : '' ) . '>';

		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			$this->render_choice_group( $field, $value, $id, $input_name, $described, $error );
		} else {
			echo '<label class="didar-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] );
			if ( ! empty( $field['required'] ) ) {
				echo ' <span class="didar-required" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__( 'الزامی', 'didar' ) . '</span>';
			}
			echo '</label>';

			 if ( 'date' === $type || 'date' === ( $field['semantic'] ?? '' ) ) {
				$this->render_date( $field, $value, $id, $input_name, $described, $error );
			} else switch ( $type ) {
				case 'date':
					$this->render_date( $field, $value, $id, $input_name, $described, $error );
					break;
				case 'textarea':
				echo '<textarea ' . $this->attributes( $field, $id, $input_name, $described, $error ) . ' rows="5">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'select':
					$this->render_select( $field, $value, $id, $input_name, $described, $error, $context );
					break;
				case 'repeater':
					$this->render_repeater( $field, $value, $id, $submission_id );
					break;
				case 'file':
					$this->render_file( $field, $value, $id, $input_name, $submission_id );
					break;
				case 'time':
					if ( ! empty( $field['multiple'] ) ) {
						$this->render_multiple_time( $field, $value, $id, $input_name, $described, $error );
					} elseif ( 'frontend' === $context && ! empty( $field['custom_time_picker'] ) ) {
						$this->render_custom_time_picker( $field, $value, $id, $input_name, $described, $error );
					} else {
						echo '<input type="time" value="' . esc_attr( (string) $value ) . '" ' . $this->attributes( $field, $id, $input_name, $described, $error ) . '>';
					}
					break;
				default:
					$html_type = in_array( $type, array( 'text', 'email', 'number', 'date', 'hidden' ), true ) ? $type : 'text';
					echo '<input type="' . esc_attr( $html_type ) . '" value="' . esc_attr( (string) $value ) . '" ' . $this->attributes( $field, $id, $input_name, $described, $error ) . '>';
					break;
			}
		}

		if ( ! empty( $field['description'] ) || ! empty( $field['display_format'] ) ) {
			$description = isset( $field['description'] ) ? $field['description'] : '';
			if ( ! empty( $field['display_format'] ) ) {
				$description = trim( $description . ' ' . sprintf( __( 'فرمت نمایشی: %s', 'didar' ), $field['display_format'] ) );
			}
			echo '<p class="didar-description" id="' . esc_attr( $id . '-description' ) . '">' . esc_html( $description ) . '</p>';
		}
		if ( $error ) {
			echo '<p class="didar-error" id="' . esc_attr( $id . '-error' ) . '" role="alert">' . esc_html( $error ) . '</p>';
		}
		echo '<p class="didar-live-error" id="' . esc_attr( $id . '-live-error' ) . '" role="alert" aria-live="polite" hidden></p>';
		echo '</div>';
	}

	private function render_date( $field, $value, $id, $name, $described, $error ) {
		$service = new Didar_Date_Service(); $display = isset( $field['_display_value'] ) ? $field['_display_value'] : $service->format_for_display( $value );
		$field['placeholder'] = $field['placeholder'] ?? '';
		if ( '' === $field['placeholder'] ) { $field['placeholder'] = '۱۴۰۵/۰۱/۰۱'; }
		$field['autocomplete'] = 'off';
		$visible = $this->attributes( $field, $id . '-jalali', $name . '_display', $described, $error ) . ' data-didar-datepicker="jalali" data-didar-date-target="' . esc_attr( $id . '-canonical' ) . '"';
		echo '<input type="text" value="' . esc_attr( $display ) . '" ' . $visible . '>';
		$profile_attributes = '';
		if ( ! empty( $field['_profile_source'] ) ) { $profile_attributes .= ' data-didar-profile-source="' . esc_attr( sanitize_key( $field['_profile_source'] ) ) . '"'; }
		if ( array_key_exists( '_profile_value', $field ) && is_scalar( $field['_profile_value'] ) && '' !== (string) $field['_profile_value'] ) { $profile_attributes .= ' data-didar-profile-value="' . esc_attr( (string) $field['_profile_value'] ) . '"'; }
		if ( ! empty( $field['_profile_origin'] ) ) { $profile_attributes .= ' data-didar-profile-origin="1"'; }
		echo '<input type="hidden" id="' . esc_attr( $id . '-canonical' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $profile_attributes . '>';
	}

	/** Consultation's accessible Persian time-picker UI; the hidden value remains canonical ASCII HH:MM. */
	private function render_custom_time_picker( $field, $value, $id, $name, $described, $error ) {
		$canonical   = Didar_Date_Service::ascii_digits( trim( (string) $value ) );
		$display     = Didar_Date_Service::format_time_for_display( $canonical );
		$placeholder = trim( (string) ( $field['placeholder'] ?? '' ) );
		$placeholder = '' !== $placeholder ? $placeholder : __( 'انتخاب ساعت', 'didar' );
		$popover_id  = $id . '-time-picker';
		$label       = (string) ( $field['label'] ?? __( 'ساعت', 'didar' ) );

		echo '<div class="didar-time-picker" data-didar-time-picker data-didar-time-step="' . esc_attr( (string) ( $field['step'] ?? '60' ) ) . '" data-didar-time-placeholder="' . esc_attr( $placeholder ) . '">';
		echo '<input type="hidden" id="' . esc_attr( $id . '-canonical' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $canonical ) . '" data-didar-time-canonical="1">';
		echo '<button type="button" id="' . esc_attr( $id ) . '" class="didar-time-picker__trigger" data-didar-time-trigger aria-haspopup="dialog" aria-expanded="false" aria-controls="' . esc_attr( $popover_id ) . '" aria-label="' . esc_attr( $label ) . '"' . ( $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '' ) . '>';
		echo '<span class="didar-time-picker__value' . ( '' === $display ? ' is-placeholder' : '' ) . '" data-didar-time-display dir="ltr">' . esc_html( '' !== $display ? $display : $placeholder ) . '</span>';
		echo '<svg class="didar-time-picker__icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/></svg>';
		echo '</button>';
		echo '<div id="' . esc_attr( $popover_id ) . '" class="didar-time-picker__popover" data-didar-time-popover role="dialog" aria-label="' . esc_attr( sprintf( __( 'انتخاب %s', 'didar' ), $label ) ) . '" hidden>';
		echo '<div class="didar-time-picker__header"><strong data-didar-time-title>' . esc_html__( 'انتخاب ساعت', 'didar' ) . '</strong><button type="button" class="didar-time-picker__close" data-didar-time-close aria-label="' . esc_attr__( 'بستن انتخاب ساعت', 'didar' ) . '">&times;</button></div>';
		echo '<div class="didar-time-picker__options" data-didar-time-options></div>';
		echo '</div></div>';
	}

	private function attributes( $field, $id, $name, $described, $error ) {
		$attributes = array(
			'id'   => $id,
			'name' => $name,
		);
		if ( ! empty( $field['semantic'] ) ) { $attributes['data-didar-semantic'] = sanitize_key( $field['semantic'] ); }
		if ( ! empty( $field['type'] ) ) { $attributes['data-didar-field-type'] = sanitize_key( $field['type'] ); }
		if ( ! empty( $field['dependent_on'] ) ) { $attributes['data-didar-dependent-on'] = sanitize_key( $field['dependent_on'] ); }
		if ( ! empty( $field['dependent_value'] ) ) { $attributes['data-didar-dependent-value'] = sanitize_key( $field['dependent_value'] ); }
		if ( ! empty( $field['dependent_country'] ) ) { $attributes['data-didar-dependent-country'] = sanitize_key( $field['dependent_country'] ); }
		if ( ! empty( $field['conditional_on'] ) ) { $attributes['data-didar-conditional-on'] = sanitize_key( $field['conditional_on'] ); }
		if ( ! empty( $field['conditional_value'] ) ) { $attributes['data-didar-conditional-value'] = sanitize_key( $field['conditional_value'] ); }
		if ( ! empty( $field['date_range_start'] ) ) { $attributes['data-didar-date-range-start'] = sanitize_key( $field['date_range_start'] ); }
		if ( ! empty( $field['option_source'] ) ) { $attributes['data-didar-option-source'] = sanitize_key( $field['option_source'] ); }
		if ( ! empty( $field['foreign_birth_location'] ) ) { $attributes['data-didar-foreign-birth-location'] = '1'; }
		if ( ! empty( $field['_profile_source'] ) ) { $attributes['data-didar-profile-source'] = sanitize_key( $field['_profile_source'] ); }
		if ( array_key_exists( '_profile_value', $field ) && is_scalar( $field['_profile_value'] ) && '' !== (string) $field['_profile_value'] ) { $attributes['data-didar-profile-value'] = (string) $field['_profile_value']; }
		if ( ! empty( $field['_profile_display_value'] ) ) { $attributes['data-didar-profile-display'] = (string) $field['_profile_display_value']; }
		if ( ! empty( $field['_profile_origin'] ) ) { $attributes['data-didar-profile-origin'] = '1'; }
		if ( 'request_for' === ( $field['name'] ?? '' ) ) { $attributes['data-didar-request-for'] = '1'; }

		foreach ( array( 'placeholder', 'autocomplete', 'autocapitalize', 'inputmode', 'accept', 'min', 'max', 'step', 'pattern', 'maxlength' ) as $attribute ) {
			if ( isset( $field[ $attribute ] ) && '' !== $field[ $attribute ] ) {
				$attributes[ $attribute ] = $field[ $attribute ];
			}
		}
		if ( ! empty( $field['required'] ) ) {
			$attributes['required'] = 'required';
			$attributes['aria-required'] = 'true';
		}
		if ( $error ) {
			$attributes['aria-invalid'] = 'true';
		}
		if ( ! empty( $field['readonly'] ) ) { $attributes['readonly'] = 'readonly'; }
		if ( $described ) {
			$attributes['aria-describedby'] = implode( ' ', $described );
		}

		$html = '';
		foreach ( $attributes as $key => $attribute_value ) {
			$html .= sprintf( '%s="%s" ', esc_attr( $key ), esc_attr( $attribute_value ) );
		}
		return trim( $html );
	}

	private function render_select( $field, $value, $id, $name, $described, $error, $context ) {
		$options  = $field['options'];
		$multiple = ! empty( $field['multiple'] );
		$values   = $multiple ? ( is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ) ) ) : ( '' !== (string) $value ? array( (string) $value ) : array() ) ) : array( (string) $value );
		foreach ( $values as $selected_value ) {
			if ( '' === $selected_value || array_key_exists( $selected_value, $options ) ) {
				continue;
			}
			if ( isset( $field['legacy_options'][ $selected_value ] ) ) {
				$options = array( $selected_value => $field['legacy_options'][ $selected_value ] . ' — ' . __( 'مقدار قدیمی', 'didar' ) ) + $options;
			} elseif ( ! empty( $field['allow_legacy'] ) ) {
				$options = array( $selected_value => sprintf( __( '%s — مقدار ذخیره‌شده قدیمی', 'didar' ), $selected_value ) ) + $options;
			}
		}

		$select_name       = $multiple ? $name . '[]' : $name;
		$select_attributes = $this->attributes( $field, $id, $select_name, $described, $error );
		if ( $multiple ) {
			$select_attributes .= ' multiple="multiple"';
		}
		$searchable = ! empty( $field['searchable'] ) || count( $options ) > 6;
		if ( $searchable ) { $select_attributes .= ' data-didar-searchable="1"'; }
		echo '<select ' . $select_attributes . '>';
		if ( ! $multiple ) {
			echo '<option value="">' . esc_html__( '— از فهرست انتخاب کنید —', 'didar' ) . '</option>';
		}
		foreach ( $options as $option_value => $label ) {
			$province = isset( $field['option_provinces'][ $option_value ] ) ? ' data-didar-province="' . esc_attr( $field['option_provinces'][ $option_value ] ) . '"' : '';
			echo '<option value="' . esc_attr( $option_value ) . '"' . $province . ' ' . selected( in_array( (string) $option_value, $values, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private function render_choice_group( $field, $value, $id, $name, $described, $error ) {
		$values = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$is_checkbox = 'checkbox' === $field['type'];
		echo '<fieldset id="' . esc_attr( $id ) . '" class="didar-choice-group"' . ( $error ? ' aria-invalid="true"' : '' ) . ( $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '' ) . '>';
		echo '<legend class="didar-label">' . esc_html( $field['label'] );
		if ( ! empty( $field['required'] ) ) {
			echo ' <span class="didar-required" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__( 'الزامی', 'didar' ) . '</span>';
		}
		echo '</legend><div class="didar-choices">';
		foreach ( $field['options'] as $option_value => $label ) {
			$option_id   = $id . '-' . sanitize_html_class( $option_value );
			$option_name = $is_checkbox ? $name . '[]' : $name;
			$profile_attributes = '';
			if ( ! empty( $field['_profile_source'] ) ) {
				$profile_attributes .= ' data-didar-profile-source="' . esc_attr( sanitize_key( $field['_profile_source'] ) ) . '"';
			}
			if ( array_key_exists( '_profile_value', $field ) && (string) $field['_profile_value'] === (string) $option_value ) {
				$profile_attributes .= ' data-didar-profile-value="' . esc_attr( (string) $field['_profile_value'] ) . '"';
				if ( ! empty( $field['_profile_origin'] ) ) { $profile_attributes .= ' data-didar-profile-origin="1"'; }
			}
			if ( 'request_for' === ( $field['name'] ?? '' ) ) { $profile_attributes .= ' data-didar-request-for="1"'; }
			echo '<label class="didar-choice" for="' . esc_attr( $option_id ) . '">';
			echo '<input type="' . ( $is_checkbox ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $option_value ) . '" ' . checked( in_array( (string) $option_value, $values, true ), true, false ) . ( ! empty( $field['required'] ) && ! $is_checkbox ? ' required' : '' ) . $profile_attributes . '>';
			echo '<span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div></fieldset>';
	}

	private function render_multiple_time( $field, $value, $id, $name, $described, $error ) {
		$values = is_array( $value ) ? array_values( $value ) : ( $value ? array( $value ) : array( '' ) );
		echo '<div class="didar-repeatable-times" data-didar-times data-max-items="' . esc_attr( isset( $field['max_items'] ) ? $field['max_items'] : 10 ) . '">';
		foreach ( $values as $index => $time ) {
			echo '<div class="didar-repeatable-row"><input type="time" value="' . esc_attr( $time ) . '" ' . $this->attributes( $field, $id . '-' . $index, $name . '[]', $described, $error ) . '><button type="button" class="didar-remove-row">' . esc_html__( 'حذف', 'didar' ) . '</button></div>';
		}
		echo '<button type="button" class="didar-add-row">' . esc_html__( 'افزودن زمان', 'didar' ) . '</button></div>';
	}

	private function render_repeater( $field, $value, $id, $submission_id = 0 ) {
		$rows = is_array( $value ) && $value ? array_values( $value ) : array( array() );
		echo '<div id="' . esc_attr( $id ) . '" class="didar-repeater" data-didar-repeater data-field="' . esc_attr( $field['name'] ) . '" data-max-items="' . esc_attr( isset( $field['max_items'] ) ? $field['max_items'] : 20 ) . '">';
		foreach ( $rows as $row_index => $row ) {
			echo '<div class="didar-repeater-row" data-row-index="' . esc_attr( absint( $row_index ) ) . '">';
			foreach ( $field['columns'] as $column => $column_definition ) {
				$is_structured = is_array( $column_definition );
				$label         = $is_structured && isset( $column_definition['label'] ) ? $column_definition['label'] : $column_definition;
				$column_type   = $is_structured && isset( $column_definition['type'] ) ? $column_definition['type'] : 'text';
				$cell_value = is_array( $row ) && isset( $row[ $column ] ) ? $row[ $column ] : '';
				$cell_id    = $id . '-' . $row_index . '-' . $column;
				if ( 'hidden' === $column_type ) {
					echo '<input type="hidden" id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']" value="' . esc_attr( $cell_value ) . '">';
					continue;
				}
				echo '<label for="' . esc_attr( $cell_id ) . '"><span>' . esc_html( $label ) . '</span>';
				if ( 'select' === $column_type && ! empty( $column_definition['options'] ) ) {
					$searchable = ! empty( $column_definition['searchable'] ) || count( $column_definition['options'] ) > 6;
					echo '<select id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']"' . ( $searchable ? ' data-didar-searchable="1"' : '' ) . '><option value="">' . esc_html__( '— انتخاب کنید —', 'didar' ) . '</option>';
					foreach ( $column_definition['options'] as $option_value => $option_label ) {
						echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( (string) $cell_value, (string) $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
					}
					echo '</select>';
				} elseif ( 'file' === $column_type ) {
					$file_field = $column_definition;
					$file_field['name']      = 'companions.' . absint( $row_index ) . '.' . $column;
					$file_field['form_type'] = $field['form_type'];
					$this->render_file( $file_field, $cell_value, $cell_id, 'didar_fields[' . $field['name'] . '][' . absint( $row_index ) . '][' . $column . ']', $submission_id );
				} else {
					$html_type = in_array( $column_type, array( 'text', 'email', 'number' ), true ) ? $column_type : 'text';
					echo '<input type="' . esc_attr( $html_type ) . '" id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']" value="' . esc_attr( $cell_value ) . '"' . ( $is_structured && ! empty( $column_definition['semantic'] ) ? ' data-didar-semantic="' . esc_attr( sanitize_key( $column_definition['semantic'] ) ) . '"' : '' );
					foreach ( array( 'placeholder', 'inputmode', 'autocomplete', 'autocapitalize', 'min', 'max', 'step', 'pattern', 'maxlength' ) as $attribute ) {
						if ( $is_structured && isset( $column_definition[ $attribute ] ) && '' !== $column_definition[ $attribute ] ) {
							echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( $column_definition[ $attribute ] ) . '"';
						}
					}
					echo '>';
				}
				echo '</label>';
			}
			echo '<button type="button" class="didar-remove-row">' . esc_html__( 'حذف ردیف', 'didar' ) . '</button></div>';
		}
		echo '<button type="button" class="didar-add-row">' . esc_html__( 'افزودن ردیف', 'didar' ) . '</button></div>';
	}

	private function render_file( $field, $value, $id, $name, $submission_id = 0 ) {
		$file_ids       = is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : array_filter( array( absint( $value ) ) );
		$max_files      = ! empty( $field['max_files'] ) ? absint( $field['max_files'] ) : 1;
		$is_multiple    = ! empty( $field['multiple'] );
		$hidden_name    = $is_multiple ? $name . '[]' : $name;

		$max_size = ! empty( $field['max_size'] ) ? absint( $field['max_size'] ) : 5 * MB_IN_BYTES;
		$profile_origin = ! empty( $field['_profile_origin'] ) ? ' data-didar-profile-origin="1"' : '';
		echo '<div class="didar-file-upload" data-didar-upload data-form-type="' . esc_attr( isset( $field['form_type'] ) ? $field['form_type'] : '' ) . '" data-submission-id="' . esc_attr( absint( $submission_id ) ) . '" data-field="' . esc_attr( $field['name'] ) . '" data-input-name="' . esc_attr( $hidden_name ) . '" data-max-files="' . esc_attr( $max_files ) . '" data-max-size="' . esc_attr( $max_size ) . '" data-required="' . esc_attr( ! empty( $field['required'] ) ? '1' : '0' ) . '"' . ( ! empty( $field['_profile_source'] ) ? ' data-didar-profile-source="' . esc_attr( $field['_profile_source'] ) . '"' : '' ) . ( ! empty( $field['_profile_value'][0] ) ? ' data-didar-profile-value="' . esc_attr( absint( $field['_profile_value'][0] ) ) . '"' : '' ) . $profile_origin . '>';
		echo '<div class="didar-file-picker"><input type="file" id="' . esc_attr( $id ) . '-file"' . ( $is_multiple ? ' multiple' : '' ) . ( ! empty( $field['accept'] ) ? ' accept="' . esc_attr( $field['accept'] ) . '"' : '' ) . ( ! empty( $field['required'] ) && ! $file_ids ? ' required aria-required="true"' : '' ) . '>';
		echo '</div>';
		echo '<ul class="didar-uploaded-files" aria-live="polite">';
		foreach ( $file_ids as $file_id ) {
			$file = $this->files ? $this->files->get_display_data( $file_id, $submission_id, $field['name'], true ) : null;
			if ( ! $file ) {
				continue;
			}
			echo '<li class="didar-upload-item is-success" data-didar-file="' . esc_attr( $file_id ) . '" data-didar-upload-state="success">';
			if ( $file['download_url'] ) {
				echo '<img class="didar-upload-thumb" src="' . esc_url( $file['download_url'] ) . '" alt="" loading="lazy">';
			}
			echo '<span class="didar-upload-item__content"><span class="didar-upload-item-name">' . esc_html( $file['file_name'] ) . '</span><span class="didar-upload-item-status" role="status" aria-live="polite">✓ بارگذاری شد</span></span><span class="didar-file-actions">';
			if ( $file['download_url'] ) {
				echo '<a class="didar-download-file" href="' . esc_url( $file['download_url'] ) . '">' . esc_html__( 'دانلود', 'didar' ) . '</a>';
			}
			echo '<input type="hidden" name="' . esc_attr( $hidden_name ) . '" value="' . esc_attr( $file_id ) . '"' . ( ! empty( $field['_profile_origin'] ) ? ' data-didar-profile-origin="1"' : '' ) . '><button type="button" class="didar-remove-upload" data-file-id="' . esc_attr( $file_id ) . '">' . esc_html__( 'حذف', 'didar' ) . '</button></span></li>';
		}
		echo '</ul><div class="didar-upload-preview" aria-live="polite"></div></div>';
	}

	public function render_file_details( $field, $value, $submission_id ) {
		$file_ids = is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : array_filter( array( absint( $value ) ) );
		$files    = array();
		foreach ( $file_ids as $file_id ) {
			$file = $this->files ? $this->files->get_display_data( $file_id, $submission_id, $field['name'], false ) : null;
			if ( $file ) {
				$files[] = $file;
			}
		}
		if ( ! $files ) {
			echo '—';
			return;
		}
		echo '<ul class="didar-detail-files">';
		foreach ( $files as $file ) {
			echo '<li><span>' . esc_html( $file['file_name'] ) . '</span>';
			if ( $file['download_url'] ) {
				echo '<a class="didar-download-file" href="' . esc_url( $file['download_url'] ) . '">' . esc_html__( 'دانلود', 'didar' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public function render_repeater_details( $field, $value, $submission_id ) {
		$rows = is_array( $value ) ? $value : array();
		if ( ! $rows ) { echo '—'; return; }
		echo '<div class="didar-repeater-details">';
		foreach ( $rows as $row_index => $row ) {
			echo '<section class="didar-repeater-detail-row"><h4>' . esc_html( sprintf( 'همراه %d', absint( $row_index ) + 1 ) ) . '</h4><dl>';
			foreach ( $field['columns'] as $column => $definition ) {
				$definition = is_array( $definition ) ? $definition : array( 'label' => $definition, 'type' => 'text' );
				$child_value = is_array( $row ) && array_key_exists( $column, $row ) ? $row[ $column ] : '';
				if ( '' === $child_value || array() === $child_value ) { continue; }
				echo '<div><dt>' . esc_html( $definition['label'] ?? $column ) . '</dt><dd>';
				if ( 'file' === ( $definition['type'] ?? '' ) ) {
					$definition['name'] = 'companions.' . absint( $row_index ) . '.' . $column;
					$definition['form_type'] = $field['form_type'] ?? 'visa_request';
					$this->render_file_details( $definition, $child_value, $submission_id );
				} else { echo nl2br( esc_html( is_array( $child_value ) ? implode( '، ', array_map( 'strval', $child_value ) ) : (string) $child_value ) ); }
				echo '</dd></div>';
			}
			echo '</dl></section>';
		}
		echo '</div>';
	}
}
