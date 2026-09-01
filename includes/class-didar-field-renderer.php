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
				$value = array_key_exists( $field['name'], $values ) ? $values[ $field['name'] ] : ( isset( $field['default'] ) ? $field['default'] : '' );
				if ( 'date' === $field['type'] && array_key_exists( $field['name'] . '_display', $values ) && is_scalar( $values[ $field['name'] . '_display' ] ) ) { $field['_display_value'] = (string) $values[ $field['name'] . '_display' ]; }
				if ( ! array_key_exists( $field['name'], $values ) && 'frontend' === $context && is_user_logged_in() ) {
					$source = $this->settings->profile_default_source( $form_type, $field['name'] );
					if ( $source ) {
						$user = wp_get_current_user();
						$profile_value = $this->profile_catalog->resolve_for_user( $source, $user, $this->profile_resolver );
						if ( '' !== $profile_value ) { $value = $profile_value; }
					}
				}
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

	private function render_date( $field, $value, $id, $name, $described, $error ) {
		$service = new Didar_Date_Service(); $display = isset( $field['_display_value'] ) ? $field['_display_value'] : $service->format_for_display( $value );
		$field['placeholder'] = $field['placeholder'] ?? '';
		if ( '' === $field['placeholder'] ) { $field['placeholder'] = '۱۴۰۵/۰۱/۰۱'; }
		$field['autocomplete'] = 'off';
		$visible = $this->attributes( $field, $id . '-jalali', $name . '_display', $described, $error ) . ' data-didar-datepicker="jalali" data-didar-date-target="' . esc_attr( $id . '-canonical' ) . '"';
		echo '<input type="text" value="' . esc_attr( $display ) . '" ' . $visible . '>';
		echo '<input type="hidden" id="' . esc_attr( $id . '-canonical' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}

	private function attributes( $field, $id, $name, $described, $error ) {
		$attributes = array(
			'id'   => $id,
			'name' => $name,
		);
		if ( ! empty( $field['semantic'] ) ) { $attributes['data-didar-semantic'] = sanitize_key( $field['semantic'] ); }

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
				echo '<label for="' . esc_attr( $cell_id ) . '"><span>' . esc_html( $label ) . '</span>';
				if ( 'select' === $column_type && ! empty( $column_definition['options'] ) ) {
					echo '<select id="' . esc_attr( $cell_id ) . '" name="didar_fields[' . esc_attr( $field['name'] ) . '][' . esc_attr( $row_index ) . '][' . esc_attr( $column ) . ']"><option value="">' . esc_html__( '— انتخاب کنید —', 'didar' ) . '</option>';
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

		echo '<div class="didar-file-upload" data-didar-upload data-form-type="' . esc_attr( isset( $field['form_type'] ) ? $field['form_type'] : '' ) . '" data-submission-id="' . esc_attr( absint( $submission_id ) ) . '" data-field="' . esc_attr( $field['name'] ) . '" data-input-name="' . esc_attr( $hidden_name ) . '" data-max-files="' . esc_attr( $max_files ) . '" data-required="' . esc_attr( ! empty( $field['required'] ) ? '1' : '0' ) . '">';
		echo '<div class="didar-file-picker"><input type="file" id="' . esc_attr( $id ) . '-file"' . ( $is_multiple ? ' multiple' : '' ) . ( ! empty( $field['accept'] ) ? ' accept="' . esc_attr( $field['accept'] ) . '"' : '' ) . ( ! empty( $field['required'] ) && ! $file_ids ? ' required aria-required="true"' : '' ) . '>';
		echo '<button type="button" class="didar-upload-button">' . esc_html__( 'بارگذاری فایل', 'didar' ) . '</button></div>';
		echo '<ul class="didar-uploaded-files" aria-live="polite">';
		foreach ( $file_ids as $file_id ) {
			$file = $this->files ? $this->files->get_display_data( $file_id, $submission_id, $field['name'], true ) : null;
			if ( ! $file ) {
				continue;
			}
			echo '<li data-didar-file="' . esc_attr( $file_id ) . '"><span>' . esc_html( $file['file_name'] ) . '</span><span class="didar-file-actions">';
			if ( $file['download_url'] ) {
				echo '<a class="didar-download-file" href="' . esc_url( $file['download_url'] ) . '">' . esc_html__( 'دانلود', 'didar' ) . '</a>';
			}
			echo '<input type="hidden" name="' . esc_attr( $hidden_name ) . '" value="' . esc_attr( $file_id ) . '"><button type="button" class="didar-remove-upload" data-file-id="' . esc_attr( $file_id ) . '">' . esc_html__( 'حذف', 'didar' ) . '</button></span></li>';
		}
		echo '</ul><span class="didar-upload-status" role="status" aria-live="polite"></span></div>';
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
