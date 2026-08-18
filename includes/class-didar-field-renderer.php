<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Field_Renderer {
	public function render_sections( $form, $values = array(), $errors = array(), $context = 'frontend' ) {
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
				$value = array_key_exists( $field['name'], $values ) ? $values[ $field['name'] ] : ( isset( $field['default'] ) ? $field['default'] : '' );
				$error = isset( $errors[ $field['name'] ] ) ? $errors[ $field['name'] ] : '';
				$this->render_field( $field, $value, $error, $context );
			}
			echo '</div></fieldset>';
		}
	}

	public function render_field( $field, $value = '', $error = '', $context = 'frontend' ) {
		$name       = $field['name'];
		$id         = 'didar-' . $context . '-' . sanitize_html_class( $name );
		$type       = $field['type'];
		$wide       = in_array( $type, array( 'textarea', 'checkbox', 'radio', 'repeater', 'file' ), true );
		$classes    = 'didar-field didar-field--' . sanitize_html_class( $type ) . ( $wide ? ' didar-field--wide' : '' ) . ( $error ? ' didar-field--error' : '' );
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

		echo '<div class="' . esc_attr( $classes ) . '">';

		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			$this->render_choice_group( $field, $value, $id, $input_name, $described, $error );
		} else {
			echo '<label class="didar-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] );
			if ( ! empty( $field['required'] ) ) {
				echo ' <span class="didar-required" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__( 'الزامی', 'didar' ) . '</span>';
			}
			echo '</label>';

			switch ( $type ) {
				case 'textarea':
				echo '<textarea ' . $this->attributes( $field, $id, $input_name, $described, $error ) . ' rows="5">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'select':
					$this->render_select( $field, $value, $id, $input_name, $described, $error, $context );
					break;
				case 'repeater':
					$this->render_repeater( $field, $value, $id );
					break;
				case 'file':
					$this->render_file( $field, $value, $id, $input_name );
					break;
				case 'time':
					if ( ! empty( $field['multiple'] ) ) {
						$this->render_multiple_time( $field, $value, $id, $input_name, $described, $error );
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
		echo '</div>';
	}

	private function attributes( $field, $id, $name, $described, $error ) {
		$attributes = array(
			'id'   => $id,
			'name' => $name,
		);

		foreach ( array( 'placeholder', 'autocomplete', 'inputmode', 'accept', 'min', 'max', 'step' ) as $attribute ) {
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
		$options = $field['options'];
		if ( '' !== (string) $value && ! array_key_exists( (string) $value, $options ) ) {
			if ( isset( $field['legacy_options'][ $value ] ) ) {
				$options = array( (string) $value => $field['legacy_options'][ $value ] . ' — ' . __( 'مقدار قدیمی', 'didar' ) ) + $options;
			} elseif ( 'admin' === $context && ! empty( $field['allow_legacy'] ) ) {
				$options = array( (string) $value => sprintf( __( '%s — مقدار ذخیره‌شده قدیمی', 'didar' ), (string) $value ) ) + $options;
			}
		}

		echo '<select ' . $this->attributes( $field, $id, $name, $described, $error ) . '>';
		echo '<option value="">' . esc_html__( '— از فهرست انتخاب کنید —', 'didar' ) . '</option>';
		foreach ( $options as $option_value => $label ) {
			echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( (string) $value, (string) $option_value, false ) . '>' . esc_html( $label ) . '</option>';
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
			echo '<label class="didar-choice" for="' . esc_attr( $option_id ) . '">';
			echo '<input type="' . ( $is_checkbox ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $option_value ) . '" ' . checked( in_array( (string) $option_value, $values, true ), true, false ) . ( ! empty( $field['required'] ) && ! $is_checkbox ? ' required' : '' ) . '>';
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

	private function render_repeater( $field, $value, $id ) {
		$rows = is_array( $value ) && $value ? array_values( $value ) : array( array() );
		echo '<div id="' . esc_attr( $id ) . '" class="didar-repeater" data-didar-repeater data-field="' . esc_attr( $field['name'] ) . '" data-max-items="' . esc_attr( isset( $field['max_items'] ) ? $field['max_items'] : 20 ) . '">';
		foreach ( $rows as $row_index => $row ) {
			echo '<div class="didar-repeater-row">';
			foreach ( $field['columns'] as $column => $column_definition ) {
				$is_structured = is_array( $column_definition );
				$label         = $is_structured && isset( $column_definition['label'] ) ? $column_definition['label'] : $column_definition;
				$column_type   = $is_structured && isset( $column_definition['type'] ) ? $column_definition['type'] : 'text';
				$cell_value = is_array( $row ) && isset( $row[ $column ] ) ? $row[ $column ] : '';
				$cell_id    = $id . '-' . $row_index . '-' . $column;
				echo '<label for="' . esc_attr( $cell_id ) . '"><span>' . esc_html( $label ) . '</span>';
				if ( 'select' === $column_type && ! empty( $column_definition['options'] ) ) {
					echo '<select id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']"><option value="">' . esc_html__( '— انتخاب کنید —', 'didar' ) . '</option>';
					foreach ( $column_definition['options'] as $option_value => $option_label ) {
						echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( (string) $cell_value, (string) $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
					}
					echo '</select>';
				} else {
					echo '<input type="text" id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']" value="' . esc_attr( $cell_value ) . '">';
				}
				echo '</label>';
			}
			echo '<button type="button" class="didar-remove-row">' . esc_html__( 'حذف ردیف', 'didar' ) . '</button></div>';
		}
		echo '<button type="button" class="didar-add-row">' . esc_html__( 'افزودن ردیف', 'didar' ) . '</button></div>';
	}

	private function render_file( $field, $value, $id, $name ) {
		$attachment_id = absint( $value );
		echo '<div class="didar-file-upload" data-didar-upload data-form-type="" data-field="' . esc_attr( $field['name'] ) . '">';
		echo '<input type="file" id="' . esc_attr( $id ) . '-file"' . ( ! empty( $field['accept'] ) ? ' accept="' . esc_attr( $field['accept'] ) . '"' : '' ) . '>';
		echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $attachment_id ) . '">';
		echo '<button type="button" class="didar-upload-button">' . esc_html__( 'بارگذاری فایل', 'didar' ) . '</button>';
		echo '<span class="didar-upload-status" role="status" aria-live="polite"></span></div>';
	}
}
