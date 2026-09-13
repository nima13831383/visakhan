<?php

/** Focused Phase 8 coverage for authorized request PDFs and the Single Request action. */
class Test_Didar_Phase8 extends WP_UnitTestCase {
	private $created = array();
	private $viewer_id;
	private $owner_id;
	private $settings_snapshot;

	public function set_up() {
		parent::set_up();
		$this->viewer_id = self::factory()->user->create();
		$this->owner_id  = self::factory()->user->create();
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
		$user = new WP_User( $this->viewer_id );
		$user->add_cap( 'didar_view_all_requests' );
		$user->add_cap( 'didar_view_request' );
	}

	public function tear_down() {
		foreach ( $this->created as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot, false );
		parent::tear_down();
	}

	public function test_authorized_output_is_pdf_and_uses_readable_values() {
		$post_id = $this->submission( 'visa_request', array( 'birth_country' => 'iran', 'companions_count' => '1', 'companions' => array( array( 'companion_uid' => 'cmp_private', 'full_name' => 'همراه نمونه', 'family_relation' => 'spouse', 'age' => '30', 'age_group' => 'adult' ) ) ) );
		wp_set_current_user( $this->viewer_id );
		$pdf  = Didar_Plugin::instance()->pdf_service->generate( $post_id );
		$html = Didar_Plugin::instance()->pdf_service->build_html( $post_id );

		$this->assertIsArray( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf['body'] );
		$this->assertStringContainsString( 'ایران', $html );
		$this->assertStringContainsString( 'همراهان', $html );
		$this->assertStringNotContainsString( 'cmp_private', $html );
		$this->assertStringNotContainsString( 'companion_uid', $html );
		$this->assertStringNotContainsString( '_didar_fields', $html );
	}

	public function test_unauthorized_user_is_denied() {
		$post_id = $this->submission( 'consultation', array( 'first_name' => 'صاحب', 'last_name' => 'درخواست' ) );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertWPError( Didar_Plugin::instance()->pdf_service->generate( $post_id ) );
	}

	public function test_single_request_contains_print_action_for_authorized_viewer() {
		$post_id = $this->submission( 'consultation', array( 'first_name' => 'صاحب', 'last_name' => 'درخواست' ) );
		wp_set_current_user( $this->viewer_id );
		$_GET['didar_submission'] = (string) $post_id;
		$html = ( new Didar_Shortcodes( new Didar_Form_Registry(), Didar_Plugin::instance()->renderer, Didar_Plugin::instance()->validator, Didar_Plugin::instance()->service, Didar_Plugin::instance()->settings, Didar_Plugin::instance()->file_service ) )->submission_details_shortcode();
		unset( $_GET['didar_submission'] );
		$this->assertStringContainsString( 'چاپ درخواست', $html );
		$this->assertStringContainsString( 'didar_download_pdf', $html );
		$this->assertStringContainsString( 'data-didar-pdf-trigger', $html );
		$this->assertStringNotContainsString( 'target="_blank"', $html );
	}

	public function test_pdf_modes_and_configured_modal_texts_are_settings_backed() {
		$settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
		$settings = is_array( $settings_snapshot ) ? $settings_snapshot : array();
		$settings['pdf_settings'] = array( 'print_with_files_text' => 'چاپ با پیوست', 'print_without_files_text' => 'چاپ ساده' );
		update_option( Didar_Settings::OPTION_NAME, $settings );
		$post_id = $this->submission( 'visa_request', array( 'personal_photo' => array( 901 ) ) );
		wp_set_current_user( $this->viewer_id );
		$html_with = Didar_Plugin::instance()->pdf_service->build_html( $post_id, $this->viewer_id, true );
		$html_without = Didar_Plugin::instance()->pdf_service->build_html( $post_id, $this->viewer_id, false );
		$pdf_with = Didar_Plugin::instance()->pdf_service->generate( $post_id, $this->viewer_id, true );
		$pdf_without = Didar_Plugin::instance()->pdf_service->generate( $post_id, $this->viewer_id, false );
		$this->assertStringContainsString( 'عکس شخصی', $html_with );
		$this->assertStringContainsString( 'فایل در دسترس نیست', $html_with );
		$this->assertStringNotContainsString( 'data:image', $html_with );
		$this->assertStringNotContainsString( '<img', $html_with );
		$this->assertStringNotContainsString( 'didar_download_pdf_file', $html_with );
		$this->assertStringNotContainsString( 'عکس شخصی', $html_without );
		$this->assertStringNotContainsString( 'ثبت شده است', $html_without );
		$this->assertStringStartsWith( '%PDF', $pdf_with['body'] );
		$this->assertStringStartsWith( '%PDF', $pdf_without['body'] );
		$transfer = new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() );
		$payload = $transfer->export_payload();
		$this->assertSame( 'چاپ با پیوست', $payload['settings']['pdf_settings']['print_with_files_text'] );
		$this->assertSame( 'چاپ ساده', $payload['settings']['pdf_settings']['print_without_files_text'] );
		$parsed = $transfer->parse_json( wp_json_encode( $payload ) );
		$this->assertIsArray( $parsed );
		$preview = $transfer->preview( $parsed, 'merge' );
		$this->assertIsArray( $preview );
		$this->assertSame( 'چاپ ساده', $preview['incoming']['pdf_settings']['print_without_files_text'] );
	}

	public function test_pdf_modal_uses_persian_defaults_when_unconfigured() {
		$settings = is_array( $this->settings_snapshot ) ? $this->settings_snapshot : array();
		unset( $settings['pdf_settings'] );
		update_option( Didar_Settings::OPTION_NAME, $settings );
		$post_id = $this->submission( 'consultation', array( 'first_name' => 'صاحب', 'last_name' => 'درخواست' ) );
		wp_set_current_user( $this->viewer_id );
		$_GET['didar_submission'] = (string) $post_id;
		$html = ( new Didar_Shortcodes( new Didar_Form_Registry(), Didar_Plugin::instance()->renderer, Didar_Plugin::instance()->validator, Didar_Plugin::instance()->service, new Didar_Settings(), Didar_Plugin::instance()->file_service ) )->submission_details_shortcode();
		unset( $_GET['didar_submission'] );
		$this->assertStringContainsString( 'چاپ همراه فایل‌ها', $html );
		$this->assertStringContainsString( 'چاپ بدون فایل‌ها', $html );
	}

	public function test_all_populated_business_fields_and_legacy_values_are_printed() {
		$registry = new Didar_Form_Registry();
		wp_set_current_user( $this->viewer_id );

		foreach ( $registry->all() as $form_type => $form ) {
			$fields = array();
			$expected = array();
			foreach ( $registry->fields( $form_type ) as $key => $field ) {
				if ( ! empty( $field['internal'] ) || 'honeypot' === ( $field['type'] ?? '' ) || in_array( $key, array( 'companions', 'companions_count' ), true ) ) { continue; }
				$value = $this->fixture_value( $key, $field );
				$fields[ $key ] = $value;
				if ( 'file' === ( $field['type'] ?? '' ) ) {
					$expected[] = 'فایل در دسترس نیست';
				} elseif ( in_array( $field['type'] ?? '', array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) ) {
					$option = array_key_first( $field['options'] );
					$expected[] = (string) $field['options'][ $option ];
				} elseif ( 'checkbox' === ( $field['type'] ?? '' ) && ! empty( $field['options'] ) ) {
					foreach ( array_slice( array_values( $field['options'] ), 0, 2 ) as $label ) { $expected[] = (string) $label; }
				} elseif ( 'date' === ( $field['type'] ?? '' ) ) {
					$expected[] = '1402/10/25';
				} else {
					$expected[] = (string) $value;
				}
			}
			$fields['legacy_custom_field'] = 'Legacy test value';
			$fields['didar_case_id'] = 'case-secret';
			$fields['pipeline_id'] = 'pipeline-secret';
			$fields['sync_debug'] = 'debug-secret';
			if ( Didar_Companion_Model::supports_form( $form_type ) ) {
				$fields['companions_count'] = '1';
				$fields['companions'] = array( array( 'companion_uid' => 'companion-secret', 'full_name' => 'همراه کامل', 'family_relation' => 'spouse', 'age' => '0', 'age_group' => 'infant', 'occupation' => 'occupation-value', 'national_id' => 'national-value', 'passport_number' => 'passport-value', 'email' => 'companion@example.com', 'phone' => '09000000000', 'personal_photo' => array( 901, 902 ), 'passport_main_page' => array( 903 ), 'round_trip_ticket' => array( 904 ), 'other_documents' => array( 905 ) ) );
			}
			$post_id = $this->submission( $form_type, $fields );
			$html = Didar_Plugin::instance()->pdf_service->build_html( $post_id );
			foreach ( $expected as $value ) { $this->assertStringContainsString( $value, $html, $form_type . ' lost a populated value' ); }
			$this->assertStringContainsString( 'Legacy test value', $html );
			$this->assertStringContainsString( 'یادداشت آزمون', $html );
			$this->assertStringNotContainsString( 'case-secret', $html );
			$this->assertStringNotContainsString( 'pipeline-secret', $html );
			$this->assertStringNotContainsString( 'debug-secret', $html );
			$this->assertStringNotContainsString( 'companion-secret', $html );
			if ( Didar_Companion_Model::supports_form( $form_type ) ) {
				foreach ( array( 'همراه کامل', 'نسبت خانوادگی', '0', 'نوزاد', 'occupation-value', 'national-value', 'passport-value', 'companion@example.com', '09000000000', 'فایل در دسترس نیست' ) as $value ) { $this->assertStringContainsString( $value, $html, $form_type . ' companion data was omitted' ); }
			}
		}
	}

	public function test_empty_current_fields_use_dash_zero_survives_and_empty_companions_are_explicit() {
		$registry = new Didar_Form_Registry();
		wp_set_current_user( $this->viewer_id );

		foreach ( $registry->all() as $form_type => $form ) {
			$post_id = $this->submission( $form_type, array(), '' );
			$html    = Didar_Plugin::instance()->pdf_service->build_html( $post_id );

			foreach ( $registry->fields( $form_type ) as $key => $field ) {
				if ( ! is_array( $field ) || ! empty( $field['internal'] ) || 'honeypot' === ( $field['type'] ?? '' ) || in_array( $key, array( 'companions', 'companions_count' ), true ) ) { continue; }
				$label = (string) ( $field['label'] ?? $key );
				$this->assertStringContainsString( '<span class="row-label">' . esc_html( $label ) . '</span><div>-</div>', $html, $form_type . ' omitted the empty placeholder for ' . $key );
			}
			$this->assertStringContainsString( '<h2>یادداشت متقاضی</h2><div class="note">-</div>', $html );

			if ( Didar_Companion_Model::supports_form( $form_type ) ) {
				$this->assertStringContainsString( 'تعداد همراهان:</span> 0', $html );
				$this->assertStringNotContainsString( '<h3>همراه ', $html );
			}
		}

		$number_key = '';
		$number_field = array();
		$checkbox_key = '';
		$checkbox_field = array();
		foreach ( $registry->fields( 'visa_request' ) as $key => $field ) {
			if ( ! $number_key && 'number' === ( $field['type'] ?? '' ) && 'companions_count' !== $key ) { $number_key = $key; $number_field = $field; }
			if ( ! $checkbox_key && 'checkbox' === ( $field['type'] ?? '' ) ) { $checkbox_key = $key; $checkbox_field = $field; }
		}
		$this->assertNotEmpty( $number_key );
		$this->assertNotEmpty( $checkbox_key );
		$post_id = $this->submission( 'visa_request', array( $number_key => '0', $checkbox_key => array() ), '' );
		$html    = Didar_Plugin::instance()->pdf_service->build_html( $post_id );
		$this->assertStringContainsString( '<span class="row-label">' . esc_html( $number_field['label'] ) . '</span><div>0</div>', $html );
		$this->assertStringContainsString( '<span class="row-label">' . esc_html( $checkbox_field['label'] ) . '</span><div>-</div>', $html );

		$post_id = $this->submission( 'visa_request', array( 'companions' => array( array( 'full_name' => 'همراه ناقص' ) ) ), '' );
		$html    = Didar_Plugin::instance()->pdf_service->build_html( $post_id );
		$this->assertStringContainsString( 'همراه ناقص', $html );
		foreach ( $registry->fields( 'visa_request' )['companions']['columns'] as $key => $column ) {
			if ( ! empty( $column['internal'] ) ) { continue; }
			$this->assertStringContainsString( '<td>' . esc_html( $column['label'] ) . '</td>', $html, 'Companion field ' . $key . ' was omitted when empty' );
		}
	}

	private function submission( $form_type, $fields, $note = 'یادداشت آزمون' ) {
		$post_id = wp_insert_post( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $this->owner_id, 'post_title' => 'Phase 8 test', 'meta_input' => array( '_didar_form_type' => $form_type, '_didar_fields' => $fields, '_didar_public_status' => 'pending_review', '_didar_internal_status' => 'pending_review', '_didar_shared_note' => $note ) ), true );
		$this->assertNotWPError( $post_id );
		$this->created[] = $post_id;
		return $post_id;
	}

	private function fixture_value( $key, $field ) {
		$type = $field['type'] ?? 'text';
		if ( 'file' === $type ) { return array( 801, 802 ); }
		if ( 'date' === $type ) { return '2024-01-15'; }
		if ( 'number' === $type ) { return '0'; }
		if ( 'time' === $type ) { return '09:30'; }
		if ( 'select' === $type || 'radio' === $type ) { return ! empty( $field['options'] ) ? (string) array_key_first( $field['options'] ) : 'business-' . $key; }
		if ( 'checkbox' === $type ) { return ! empty( $field['options'] ) ? array_slice( array_keys( $field['options'] ), 0, 2 ) : array( 'business-' . $key ); }
		if ( 'email' === $type ) { return 'field-' . sanitize_key( $key ) . '@example.com'; }
		return 'Business value ' . sanitize_key( $key );
	}
}
