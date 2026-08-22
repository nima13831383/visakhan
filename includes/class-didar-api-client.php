<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Centralized client for the documented Didar Open API endpoints. */
class Didar_Api_Client {
	const BASE_URL = 'https://app.didar.me';

	private $settings;
	private $logger;
	private $trace_id = '';

	public function __construct( Didar_Settings $settings, Didar_Logger $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger ? $logger : new Didar_Logger();
	}

	public function set_trace_id( $trace_id ) { $this->trace_id = sanitize_text_field( (string) $trace_id ); }

	public function is_configured() {
		$settings = $this->settings->all();
		return ! empty( $settings['didar_api_key'] ) && is_string( $settings['didar_api_key'] );
	}

	public function test_connection() {
		return $this->request( '/api/pipeline/list/0', null );
	}

	public function search_person( $criteria, $from = 0, $limit = 10 ) {
		return $this->request( '/api/contact/PersonSearch', array( 'Criteria' => (array) $criteria, 'From' => absint( $from ), 'Limit' => absint( $limit ) ) );
	}

	public function save_person( $contact ) {
		return $this->request( '/api/contact/save', array( 'Contact' => (array) $contact ) );
	}

	public function search_deal( $criteria, $from = 0, $limit = 10 ) {
		return $this->request( '/api/deal/search_v2', array( 'Criteria' => (array) $criteria, 'From' => absint( $from ), 'Limit' => absint( $limit ) ) );
	}

	public function save_deal( $deal ) {
		return $this->request( '/api/deal/save_v2', array( 'Deal' => (array) $deal ) );
	}

	public function pipelines() {
		return $this->request( '/api/pipeline/list/0', null );
	}

	public function users() {
		return $this->request( '/api/User/List', null );
	}

	public function custom_fields() {
		return $this->request( '/api/customfield/GetCustomfieldList', null );
	}

	public function save_note( $note ) {
		return $this->request( '/api/activity/save', array( 'Activity' => (array) $note ) );
	}

	private function request( $path, $body ) {
		$started = microtime( true );
		$settings = $this->settings->all();
		$api_key  = isset( $settings['didar_api_key'] ) && is_scalar( $settings['didar_api_key'] ) ? trim( (string) $settings['didar_api_key'] ) : '';
		if ( '' === $api_key ) {
			$this->logger->log( 'ERROR', 'api_request', 'Didar API request stopped: API key missing.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_method' => 'POST', 'error_code' => 'didar_not_configured', 'trace_id' => $this->trace_id ) );
			return new WP_Error( 'didar_not_configured', __( 'کلید API دیدار تنظیم نشده است.', 'didar' ) );
		}

		$url  = add_query_arg( 'apikey', $api_key, self::BASE_URL . $path );
		$args = array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$this->logger->log( 'INFO', 'api_request', 'Didar API request started.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_method' => 'POST', 'request_payload' => $body, 'trace_id' => $this->trace_id ) );
		$response = wp_remote_post( esc_url_raw( $url ), $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'ERROR', 'api_request', 'Didar API network error.', array( 'source' => 'api_client', 'endpoint' => $path, 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000 ), 'error_code' => $response->get_error_code(), 'error_message' => $response->get_error_message(), 'trace_id' => $this->trace_id ) );
			return new WP_Error( 'didar_http_error', __( 'ارتباط با دیدار برقرار نشد.', 'didar' ), array( 'source' => $response->get_error_code() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$this->logger->log( 'ERROR', 'api_response', 'Didar API returned a non-2xx response.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_method' => 'POST', 'http_status' => $status, 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000 ), 'response_body' => $data ? $data : $raw, 'error_code' => 'didar_api_error', 'trace_id' => $this->trace_id ) );
			return new WP_Error( 'didar_api_error', __( 'دیدار درخواست را نپذیرفت.', 'didar' ), array( 'status' => $status, 'response' => $data ? $data : $raw ) );
		}
		if ( ! is_array( $data ) ) {
			$this->logger->log( 'ERROR', 'api_response', 'Didar returned invalid JSON.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_status' => $status, 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000 ), 'response_body' => $raw, 'error_code' => 'didar_invalid_json', 'trace_id' => $this->trace_id ) );
			return new WP_Error( 'didar_invalid_json', __( 'پاسخ دیدار معتبر نیست.', 'didar' ) );
		}
		if ( isset( $data['Error'] ) || isset( $data['Errors'] ) || ( isset( $data['Success'] ) && false === $data['Success'] ) ) {
			$this->logger->log( 'ERROR', 'api_response', 'Didar reported an application-level error in a successful HTTP response.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_status' => $status, 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000 ), 'response_body' => $data, 'error_code' => 'didar_api_error', 'trace_id' => $this->trace_id ) );
			return new WP_Error( 'didar_api_error', __( 'دیدار خطایی در پاسخ اعلام کرد.', 'didar' ), array( 'status' => $status, 'response' => $data ) );
		}
		$this->logger->log( 'INFO', 'api_response', 'Didar API request completed.', array( 'source' => 'api_client', 'endpoint' => $path, 'http_status' => $status, 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000 ), 'response_status' => isset( $data['Status'] ) ? $data['Status'] : '', 'trace_id' => $this->trace_id ) );

		return $data;
	}
}
