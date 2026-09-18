<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Generates an authorized, read-only PDF representation of a submission. */
class Didar_Pdf_Service {
	const ACTION = 'didar_download_pdf';
	const NONCE_ACTION_PREFIX = 'didar_download_pdf_';

	private $registry;
	private $settings;
	private $service;
	private $files;
	private $logger;
	private $workflow;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_Submission_Service $service, Didar_File_Service $files, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->service  = $service;
		$this->files    = $files;
		$this->logger   = $logger ? $logger : new Didar_Logger();
		$this->workflow = new Didar_Workflow_Manager( $registry, $settings, $this->logger );

		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'deny_unauthenticated' ) );
	}

	public function download_url( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'        => self::ACTION,
				'submission_id' => $submission_id,
				'_wpnonce'      => wp_create_nonce( self::NONCE_ACTION_PREFIX . $submission_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function modal_texts() {
		return array(
			'with_files'    => $this->settings->pdf_print_with_files_text(),
			'without_files' => $this->settings->pdf_print_without_files_text(),
		);
	}

	/** Stream the generated PDF after nonce and object authorization checks. */
	public function handle_download() {
		$submission_id = isset( $_GET['submission_id'] ) && ! is_array( $_GET['submission_id'] ) ? absint( wp_unslash( $_GET['submission_id'] ) ) : 0;
		$nonce         = isset( $_GET['_wpnonce'] ) && ! is_array( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$include_files = $this->requested_include_files( $_GET );

		if ( null === $include_files ) {
			$this->deny( __( 'حالت چاپ معتبر نیست.', 'didar' ), 400 );
		}
		if ( ! is_user_logged_in() || ! $submission_id || ! wp_verify_nonce( $nonce, self::NONCE_ACTION_PREFIX . $submission_id ) ) {
			$this->deny( __( 'اجازه دانلود این درخواست را ندارید.', 'didar' ), 403 );
		}

		$post = $this->authorized_submission( $submission_id, get_current_user_id() );
		if ( ! $post ) {
			$this->deny( __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ), 403 );
		}

		$pdf = $this->generate_for_post( $post, $include_files );
		if ( is_wp_error( $pdf ) ) {
			$this->log_error( 'pdf_generation_failed', $submission_id, $pdf->get_error_code() );
			$this->deny( __( 'ساخت فایل درخواست انجام نشد. لطفاً دوباره تلاش کنید.', 'didar' ), 500 );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . (string) strlen( $pdf['body'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $pdf['filename'] . '"' );
		echo $pdf['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes must be streamed unchanged.
		exit;
	}

	public function deny_unauthenticated() {
		$this->deny( __( 'برای دانلود درخواست ابتدا وارد حساب کاربری خود شوید.', 'didar' ), 401 );
	}

	/** Return a PDF payload without sending headers, which also keeps generation testable. */
	public function generate( $submission_id, $user_id = 0, $include_files = true ) {
		$post = $this->authorized_submission( $submission_id, $user_id ? $user_id : get_current_user_id() );
		if ( ! $post ) {
			return new WP_Error( 'forbidden', __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ) );
		}

		return $this->generate_for_post( $post, (bool) $include_files );
	}

	/** Build the escaped printable HTML model used by the PDF engine. */
	public function build_html( $submission_id, $user_id = 0, $include_files = true ) {
		$post = $this->authorized_submission( $submission_id, $user_id ? $user_id : get_current_user_id() );
		if ( ! $post ) {
			return new WP_Error( 'forbidden', __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ) );
		}

		return $this->html( $this->view_model( $post, (bool) $include_files ) );
	}

	private function authorized_submission( $submission_id, $user_id ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );
		return $submission_id && $user_id ? $this->service->get_accessible_submission( $submission_id, $user_id ) : null;
	}

	private function requested_include_files( $request ) {
		if ( ! is_array( $request ) || ! array_key_exists( 'include_files', $request ) ) { return true; }
		if ( is_array( $request['include_files'] ) ) { return null; }
		$value = sanitize_key( wp_unslash( $request['include_files'] ) );
		return '1' === $value ? true : ( '0' === $value ? false : null );
	}

	private function generate_for_post( $post, $include_files = true ) {
		if ( ! class_exists( 'Mpdf\\Mpdf' ) ) {
			return new WP_Error( 'pdf_engine_missing', __( 'موتور ساخت PDF در دسترس نیست.', 'didar' ) );
		}
		$form_type = sanitize_key( (string) get_post_meta( $post->ID, '_didar_form_type', true ) );
		if ( ! $this->registry->get( $form_type ) ) {
			return new WP_Error( 'invalid_submission', __( 'نوع فرم این درخواست معتبر نیست.', 'didar' ) );
		}

		$temp_dir = $this->temporary_directory();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		try {
			$config = array(
				'mode'           => 'utf-8',
				'format'         => 'A4',
				'orientation'    => 'P',
				'directionality' => 'rtl',
				'default_font'   => 'dejavusans',
				'autoScriptToLang'=> true,
				'autoLangToFont' => true,
				'useSubstitutions'=> true,
				'tempDir'        => $temp_dir,
			);
			$mpdf = new \Mpdf\Mpdf( $config );
			$mpdf->SetTitle( 'Didar Request #' . (int) $post->ID );
			$mpdf->SetAuthor( get_bloginfo( 'name' ) );
			$mpdf->WriteHTML( $this->html( $this->view_model( $post, (bool) $include_files ) ) );
			$body = $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
			if ( ! is_string( $body ) || 0 !== strpos( $body, '%PDF' ) ) {
				return new WP_Error( 'invalid_pdf_output', __( 'فایل PDF معتبر تولید نشد.', 'didar' ) );
			}

			return array(
				'body'     => $body,
				'filename' => 'didar-request-' . absint( $post->ID ) . '.pdf',
			);
		} catch ( Throwable $exception ) {
			return new WP_Error( 'pdf_generation_exception', __( 'ساخت فایل PDF انجام نشد.', 'didar' ) );
		} finally {
			$this->remove_directory( $temp_dir );
		}
	}

	private function view_model( $post, $include_files = true ) {
		$form_type = sanitize_key( (string) get_post_meta( $post->ID, '_didar_form_type', true ) );
		$form      = $this->registry->get( $form_type );
		$values    = $this->service->get_fields( $post->ID );
		$status    = $this->service->get_request_status( $post->ID );
		$status    = $this->workflow->status_label( $form_type, $status );
		$sections  = array();
		$serializer = new Didar_Readable_Value_Serializer( $this->files, $this->logger );

		foreach ( (array) ( $form['sections'] ?? array() ) as $section ) {
			$items = array();
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
				if ( ! $name || ! empty( $field['internal'] ) || 'honeypot' === ( $field['type'] ?? '' ) || in_array( $name, array( 'companions', 'companions_count' ), true ) ) {
					continue;
				}
				if ( 'file' === ( $field['type'] ?? '' ) && ! $include_files ) { continue; }
				$value = array_key_exists( $name, $values ) ? $values[ $name ] : '';
				$file_view = 'file' === ( $field['type'] ?? '' ) ? $this->file_view( $value, $post->ID, $name ) : array( 'value' => '', 'links' => array(), 'unavailable' => array() );
				$readable = $this->is_meaningful( $value ) ? ( 'file' === ( $field['type'] ?? '' ) ? $file_view['value'] : $this->readable_value( $serializer, $form_type, $name, $field, $value, $post->ID ) ) : '';
				$file_output = 'file' === ( $field['type'] ?? '' ) && ( ! empty( $file_view['links'] ) || ! empty( $file_view['unavailable'] ) );
				if ( ( '' === trim( (string) $readable ) && ! $file_output ) || '—' === trim( (string) $readable ) ) {
					$readable = '-';
				}
				$item = array( 'label' => (string) ( $field['label'] ?? $name ), 'value' => (string) $readable );
				if ( ! empty( $file_view['links'] ) ) { $item['links'] = $file_view['links']; }
				if ( ! empty( $file_view['unavailable'] ) ) { $item['unavailable'] = $file_view['unavailable']; }
				$items[] = $item;
			}
			if ( $items ) {
				$sections[] = array( 'label' => (string) ( $section['label'] ?? '' ), 'items' => $items );
			}
		}

		$legacy = array();
		$legacy_fields = $this->registry->legacy_fields( $form_type );
		foreach ( (array) $legacy_fields as $name => $field ) {
			if ( ! array_key_exists( $name, $values ) || ! $this->is_meaningful( $values[ $name ] ) ) {
				continue;
			}
			$legacy[] = array( 'label' => (string) ( $field['label'] ?? $name ), 'value' => $this->readable_value( $serializer, $form_type, $name, $field, $values[ $name ], $post->ID ) );
		}

		$stored_extra = array();
		$current_fields = $this->registry->fields( $form_type );
		$stored_labels  = get_post_meta( $post->ID, '_didar_field_labels', true );
		$stored_labels  = is_array( $stored_labels ) ? $stored_labels : array();
		$known_keys     = array_unique( array_merge( array_keys( $current_fields ), array_keys( $legacy_fields ), array( 'companions', 'companions_count' ) ) );
		foreach ( (array) $values as $key => $value ) {
			$key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';
			if ( ! $key || in_array( $key, $known_keys, true ) || $this->is_excluded_key( $key ) || ! $this->is_meaningful( $value ) ) {
				continue;
			}
			$definition = isset( $current_fields[ $key ] ) && is_array( $current_fields[ $key ] ) ? $current_fields[ $key ] : ( isset( $legacy_fields[ $key ] ) && is_array( $legacy_fields[ $key ] ) ? $legacy_fields[ $key ] : array() );
			$label = isset( $current_fields[ $key ]['label'] ) ? $current_fields[ $key ]['label'] : ( $legacy_fields[ $key ]['label'] ?? ( $stored_labels[ $key ] ?? $this->fallback_label( $key ) ) );
			$readable = $this->is_document_key( $key ) ? $this->stored_document_value( $value, $key ) : $this->readable_value( $serializer, $form_type, $key, $definition, $value, $post->ID );
			if ( '' !== trim( (string) $readable ) ) {
				$stored_extra[] = array( 'label' => (string) $label, 'value' => (string) $readable );
			}
		}

		$owner = Didar_User_Identity::for_submission( $post->ID );
		$created = get_post_time( 'Y-m-d', false, $post );
		$created = ( new Didar_Date_Service() )->format_for_display( $created );
		$companions = $this->companion_model( $form_type, $values, $post->ID, $serializer, (bool) $include_files );

		return array(
			'title'          => get_bloginfo( 'name' ),
			'form_label'     => (string) ( $form['label'] ?? __( 'درخواست دیدار', 'didar' ) ),
			'submission_id'  => absint( $post->ID ),
			'created_date'   => $created ? $created : get_the_date( '', $post ),
			'status'         => (string) $status,
			'owner_name'     => (string) $owner['name'],
			'owner_role'     => (string) $owner['role_label'],
			'sections'       => $sections,
			'legacy'         => $legacy,
			'stored_extra'   => $stored_extra,
			'applicant_note' => (string) $this->service->get_shared_note( $post->ID ),
			'companions'     => $companions,
		);
	}

	private function companion_model( $form_type, $values, $post_id, $serializer, $include_files = true ) {
		if ( ! Didar_Companion_Model::supports_form( $form_type ) ) {
			return array();
		}
		$fields = $this->registry->fields( $form_type );
		$columns = isset( $fields['companions']['columns'] ) && is_array( $fields['companions']['columns'] ) ? $fields['companions']['columns'] : array();
		$rows = array();
		foreach ( Didar_Companion_Model::rows( $values['companions'] ?? array() ) as $index => $row ) {
			$items = array();
			foreach ( $columns as $key => $column ) {
				if ( ! is_array( $column ) || ! empty( $column['internal'] ) || 'hidden' === ( $column['type'] ?? '' ) ) { continue; }
				if ( 'file' === ( $column['type'] ?? '' ) && ! $include_files ) { continue; }
				$value = array_key_exists( $key, $row ) ? $row[ $key ] : '';
				$file_view = 'file' === ( $column['type'] ?? '' ) ? $this->file_view( $value, $post_id, 'companions.' . $index . '.' . $key ) : array( 'value' => '', 'links' => array(), 'unavailable' => array() );
				$readable = $this->is_meaningful( $value ) ? ( 'file' === ( $column['type'] ?? '' ) ? $file_view['value'] : $this->readable_value( $serializer, $form_type, 'companions.' . $index . '.' . $key, $column, $value, $post_id ) ) : '';
				$file_output = 'file' === ( $column['type'] ?? '' ) && ( ! empty( $file_view['links'] ) || ! empty( $file_view['unavailable'] ) );
				if ( ( '' === trim( (string) $readable ) && ! $file_output ) || '—' === trim( (string) $readable ) ) { $readable = '-'; }
				$item = array( 'label' => (string) ( $column['label'] ?? $key ), 'value' => (string) $readable );
				if ( ! empty( $file_view['links'] ) ) { $item['links'] = $file_view['links']; }
				if ( ! empty( $file_view['unavailable'] ) ) { $item['unavailable'] = $file_view['unavailable']; }
				$items[] = $item;
			}
			$business_row = $row;
			unset( $business_row['companion_uid'], $business_row['age_group'] );
			if ( $items && $this->is_meaningful( $business_row ) ) { $rows[] = array( 'number' => count( $rows ) + 1, 'items' => $items ); }
		}
		$count_value = array_key_exists( 'companions_count', (array) $values ) ? $values['companions_count'] : '';
		$count = is_scalar( $count_value ) ? (string) $count_value : '';
		return array( 'count' => $count, 'rows' => $rows );
	}

	private function file_view( $value, $post_id, $field_key ) {
		$ids          = is_array( $value ) ? array_values( $value ) : array( $value );
		$links        = array();
		$unavailable  = array();
		$references   = 0;
		foreach ( $ids as $file_id ) {
			$file_id = absint( $file_id );
			if ( ! $file_id ) { continue; }
			$references++;
			$url = method_exists( $this->files, 'get_direct_url_for_reference' ) ? $this->files->get_direct_url_for_reference( $file_id, $post_id, $field_key ) : '';
			$url = $this->valid_direct_url( $url );
			$record = method_exists( $this->files, 'get_for_submission' ) ? $this->files->get_for_submission( $file_id, $post_id, $field_key ) : null;
			if ( ! $record || ! $url ) {
				$unavailable[] = __( 'فایل در دسترس نیست', 'didar' );
				continue;
			}
			$filename = isset( $record['original_name'] ) ? wp_basename( sanitize_text_field( (string) $record['original_name'] ) ) : '';
			$filename = $filename ? $filename : sprintf( __( 'فایل %d', 'didar' ), count( $links ) + count( $unavailable ) + 1 );
			$links[] = array( 'url' => $url, 'text' => $filename . ' — ' . __( 'دانلود فایل', 'didar' ) );
		}

		if ( ! $references ) { return array( 'value' => '', 'links' => array(), 'unavailable' => array() ); }
		return array( 'value' => '', 'links' => $links, 'unavailable' => $unavailable );
	}

	private function valid_direct_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) || false !== strpos( $url, "\0" ) || false !== strpos( $url, '\\' ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$validated = esc_url_raw( $url, array( 'http', 'https' ) );
		return $validated && $validated === $url ? $validated : '';
	}

	private function readable_value( $serializer, $form_type, $field_key, $definition, $value, $post_id ) {
		if ( 'time' === ( $definition['type'] ?? '' ) && is_scalar( $value ) ) {
			$display = Didar_Date_Service::format_time_for_display( $value );
			if ( $display ) { return $display; }
		}
		$readable = $serializer->serialize( $form_type, $field_key, is_array( $definition ) ? $definition : array(), $value, $post_id );
		if ( is_scalar( $readable ) && '' !== trim( (string) $readable ) && '—' !== trim( (string) $readable ) ) { return (string) $readable; }
		return $this->fallback_value( $value );
	}

	private function fallback_value( $value ) {
		if ( is_bool( $value ) ) { return $value ? __( 'بله', 'didar' ) : __( 'خیر', 'didar' ); }
		if ( is_scalar( $value ) ) {
			$value = sanitize_text_field( (string) $value );
			if ( in_array( strtolower( $value ), array( 'yes', 'true' ), true ) ) { return __( 'بله', 'didar' ); }
			if ( in_array( strtolower( $value ), array( 'no', 'false' ), true ) ) { return __( 'خیر', 'didar' ); }
			return $value;
		}
		if ( ! is_array( $value ) ) { return ''; }
		$parts = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && $this->is_excluded_key( $key ) ) { continue; }
			if ( ! $this->is_meaningful( $item ) ) { continue; }
			$rendered = $this->fallback_value( $item );
			if ( '' === $rendered ) { continue; }
			$parts[] = is_int( $key ) ? $rendered : $this->fallback_label( $key ) . ': ' . $rendered;
		}
		return implode( "\n", $parts );
	}

	private function stored_document_value( $value, $field_key ) {
		$ids = is_array( $value ) ? array_values( $value ) : array( $value );
		$count = count( array_filter( $ids, function ( $item ) { return $this->is_meaningful( $item ); } ) );
		if ( ! $count ) { return ''; }
		$unit = $this->is_document_key( $field_key ) && ( false !== strpos( $field_key, 'photo' ) || false !== strpos( $field_key, 'image' ) ) ? __( 'تصویر', 'didar' ) : __( 'مدرک', 'didar' );
		return sprintf( _n( '%d %s ثبت شده است', '%d %s ثبت شده است', $count, 'didar' ), $count, $unit );
	}

	private function is_meaningful( $value ) {
		if ( is_bool( $value ) || is_numeric( $value ) ) { return true; }
		if ( is_scalar( $value ) ) { return '' !== trim( (string) $value ); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { if ( $this->is_meaningful( $item ) ) { return true; } } }
		return false;
	}

	private function is_document_key( $key ) {
		return (bool) preg_match( '/(^|_)(file|files|document|documents|photo|image|upload|uploads)(_|$)/i', (string) $key );
	}

	private function fallback_label( $key ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]+/', ' ', (string) $key );
		$key = trim( preg_replace( '/[_\-]+/', ' ', $key ) );
		return $key ? ucwords( $key ) : __( 'اطلاعات ثبت‌شده', 'didar' );
	}

	private function is_excluded_key( $key ) {
		$key = sanitize_key( (string) $key );
		$exact = array( 'honeypot', 'twitter', 'companion_uid', 'main_applicant_uid', 'applicant_uid', 'deal_id', 'didar_deal_id', 'case_id', 'didar_case_id', 'pipeline_id', 'stage_id', 'retry_state', 'webhook_state', 'sync_debug', 'sync_state', 'api_payload', 'raw_api_payload', 'internal_hash', 'nonce', 'token', 'internal_note', 'admin_note' );
		if ( in_array( $key, $exact, true ) ) { return true; }
		return (bool) preg_match( '/(^|_)(deal|case|pipeline|stage|retry|webhook|sync|nonce|token|hash|api_payload)(_|$)/', $key );
	}

	private function html( $model ) {
		$out = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>';
		$out .= 'body{font-family:dejavusans,sans-serif;direction:rtl;color:#1f2937;font-size:10.5pt;line-height:1.65}h1{font-size:19pt;color:#123b5d;margin:0 0 4pt}h2{font-size:13pt;color:#123b5d;margin:18pt 0 7pt;border-bottom:1px solid #cad5df;padding-bottom:4pt}h3{font-size:11pt;color:#123b5d;margin:0 0 5pt}.meta{background:#f2f6f8;border:1px solid #d6e0e6;padding:8pt;margin:10pt 0 14pt}.meta-row{display:inline-block;width:48%;margin:2pt 0}.label{font-weight:bold;color:#405466}.rows{border:1px solid #d6e0e6;margin:0 0 10pt}.row{border-bottom:1px solid #e5ebef;padding:5pt 7pt}.row:last-child{border-bottom:0}.row-label{font-weight:bold;color:#405466;display:block;margin-bottom:1pt}.note{white-space:pre-wrap;background:#fffdf4;border:1px solid #eadfb5;padding:8pt}.companion{border:1px solid #d6e0e6;padding:7pt;margin:0 0 8pt;page-break-inside:avoid}.didar-pdf-file-link{color:#123b5d;text-decoration:underline}.muted{color:#687b8a}table{width:100%;border-collapse:collapse}td{padding:3pt 0;vertical-align:top}td:first-child{width:34%;font-weight:bold;color:#405466}';
		$out .= '</style></head><body>';
		$out .= '<h1>' . esc_html( $model['title'] ) . '</h1><div class="muted">' . esc_html__( 'نسخه چاپی درخواست', 'didar' ) . '</div>';
		$out .= '<div class="meta"><div class="meta-row"><span class="label">' . esc_html__( 'نوع فرم:', 'didar' ) . '</span> ' . esc_html( $model['form_label'] ) . '</div><div class="meta-row"><span class="label">' . esc_html__( 'شماره درخواست:', 'didar' ) . '</span> ' . esc_html( $model['submission_id'] ) . '</div><div class="meta-row"><span class="label">' . esc_html__( 'تاریخ ثبت:', 'didar' ) . '</span> ' . esc_html( $model['created_date'] ) . '</div><div class="meta-row"><span class="label">' . esc_html__( 'وضعیت:', 'didar' ) . '</span> ' . esc_html( $model['status'] ) . '</div><div class="meta-row"><span class="label">' . esc_html__( 'ثبت‌کننده:', 'didar' ) . '</span> ' . esc_html( $model['owner_name'] ) . '</div><div class="meta-row"><span class="label">' . esc_html__( 'نقش:', 'didar' ) . '</span> ' . esc_html( $model['owner_role'] ) . '</div></div>';
		foreach ( $model['sections'] as $section ) { $out .= '<h2>' . esc_html( $section['label'] ) . '</h2><div class="rows">'; foreach ( $section['items'] as $item ) { $out .= $this->html_item( $item ); } $out .= '</div>'; }
		if ( $model['legacy'] ) { $out .= '<h2>' . esc_html__( 'اطلاعات تاریخی', 'didar' ) . '</h2><div class="rows">'; foreach ( $model['legacy'] as $item ) { $out .= $this->html_item( $item ); } $out .= '</div>'; }
		if ( ! empty( $model['stored_extra'] ) ) { $out .= '<h2>' . esc_html__( 'اطلاعات ثبت‌شده تکمیلی', 'didar' ) . '</h2><div class="rows">'; foreach ( $model['stored_extra'] as $item ) { $out .= $this->html_item( $item ); } $out .= '</div>'; }
		$note = trim( (string) $model['applicant_note'] );
		$out .= '<h2>' . esc_html__( 'یادداشت متقاضی', 'didar' ) . '</h2><div class="note">' . nl2br( esc_html( '' !== $note ? $note : '-' ) ) . '</div>';
		if ( $model['companions'] ) { $out .= '<h2>' . esc_html__( 'همراهان', 'didar' ) . '</h2><p><span class="label">' . esc_html__( 'تعداد همراهان:', 'didar' ) . '</span> ' . esc_html( $model['companions']['count'] ) . '</p>'; foreach ( $model['companions']['rows'] as $companion ) { $out .= '<div class="companion"><h3>' . esc_html( sprintf( __( 'همراه %d', 'didar' ), $companion['number'] ) ) . '</h3><table>'; foreach ( $companion['items'] as $item ) { $out .= $this->html_companion_item( $item ); } $out .= '</table></div>'; } }
		$out .= '</body></html>';
		return $out;
	}

	private function html_item( $item ) {
		$out = '<div class="row"><span class="row-label">' . esc_html( $item['label'] ) . '</span><div>';
		if ( '' !== trim( (string) ( $item['value'] ?? '' ) ) ) { $out .= nl2br( esc_html( $item['value'] ) ); }
		foreach ( (array) ( $item['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['text'] ) ) { continue; }
			$out .= ( '' !== trim( (string) ( $item['value'] ?? '' ) ) ? '<br>' : '' ) . '<a class="didar-pdf-file-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['text'] ) . '</a>';
		}
		foreach ( (array) ( $item['unavailable'] ?? array() ) as $message ) { $out .= ( ( ! empty( $item['value'] ) || ! empty( $item['links'] ) ) ? '<br>' : '' ) . '<span class="muted">' . esc_html( $message ) . '</span>'; }
		if ( '' === trim( (string) ( $item['value'] ?? '' ) ) && empty( $item['links'] ) && empty( $item['unavailable'] ) ) { $out .= '-'; }
		return $out . '</div></div>';
	}

	private function html_companion_item( $item ) {
		$out = '<tr><td>' . esc_html( $item['label'] ) . '</td><td>';
		if ( '' !== trim( (string) ( $item['value'] ?? '' ) ) ) { $out .= nl2br( esc_html( $item['value'] ) ); }
		foreach ( (array) ( $item['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['text'] ) ) { continue; }
			$out .= ( '' !== trim( (string) ( $item['value'] ?? '' ) ) ? '<br>' : '' ) . '<a class="didar-pdf-file-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['text'] ) . '</a>';
		}
		foreach ( (array) ( $item['unavailable'] ?? array() ) as $message ) { $out .= ( ( ! empty( $item['value'] ) || ! empty( $item['links'] ) ) ? '<br>' : '' ) . '<span class="muted">' . esc_html( $message ) . '</span>'; }
		if ( '' === trim( (string) ( $item['value'] ?? '' ) ) && empty( $item['links'] ) && empty( $item['unavailable'] ) ) { $out .= '-'; }
		return $out . '</td></tr>';
	}

	private function temporary_directory() {
		$base = trailingslashit( get_temp_dir() );
		$name = 'didar-pdf-' . str_replace( '-', '', wp_generate_uuid4() );
		$path = $base . $name;
		return wp_mkdir_p( $path ) ? $path : new WP_Error( 'pdf_temp_unavailable', __( 'فضای موقت ساخت PDF در دسترس نیست.', 'didar' ) );
	}

	private function remove_directory( $directory ) {
		if ( ! is_string( $directory ) || '' === $directory || ! is_dir( $directory ) ) { return; }
		$items = scandir( $directory );
		if ( ! is_array( $items ) ) { return; }
		foreach ( $items as $item ) { if ( '.' === $item || '..' === $item ) { continue; } $path = $directory . DIRECTORY_SEPARATOR . $item; if ( is_dir( $path ) ) { $this->remove_directory( $path ); } elseif ( is_file( $path ) ) { wp_delete_file( $path ); } }
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cleanup must not mask the response.
	}

	private function log_error( $code, $submission_id, $detail = '' ) {
		$this->logger->log( 'ERROR', sanitize_key( $code ), 'PDF generation failed.', array( 'source' => 'pdf_service', 'submission_id' => absint( $submission_id ), 'detail' => sanitize_key( (string) $detail ) ) );
	}

	private function deny( $message, $status ) {
		wp_die( esc_html( $message ), '', array( 'response' => absint( $status ) ) );
	}
}
