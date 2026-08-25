<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Safe cached catalogue of Didar operators. Canonical mapping identity is UserId. */
class Didar_User_Catalog {
	const OPTION_NAME = 'didar_user_cache';
	private $settings;
	private $logger;

	public function __construct( Didar_Settings $settings, Didar_Logger $logger = null ) { $this->settings = $settings; $this->logger = $logger ? $logger : new Didar_Logger(); }
	public function users() { $cache = $this->cache_info(); return isset( $cache['users'] ) && is_array( $cache['users'] ) ? $cache['users'] : array(); }
	public function cache_info() { $cache = get_option( self::OPTION_NAME, array() ); return is_array( $cache ) ? $cache : array(); }
	public function user_by_user_id( $user_id ) { foreach ( $this->users() as $user ) { if ( (string) ( $user['user_id'] ?? '' ) === (string) $user_id ) { return $user; } } return array(); }
	public function user_by_id( $id ) { foreach ( $this->users() as $user ) { if ( (string) ( $user['id'] ?? '' ) === (string) $id ) { return $user; } } return array(); }

	public function refresh() {
		$this->logger->log( 'INFO', 'didar_users_refresh_started', 'Didar User metadata refresh started.', array( 'source' => 'admin' ) );
		$response = ( new Didar_Api_Client( $this->settings, $this->logger ) )->users();
		if ( is_wp_error( $response ) ) { return $this->record_error( $response->get_error_code(), $response ); }
		$users = $this->normalize( $response );
		if ( ! $users ) { return $this->record_error( 'didar_user_response_empty' ); }
		$active = count( array_filter( $users, function ( $user ) { return empty( $user['is_disabled'] ); } ) );
		update_option( self::OPTION_NAME, array( 'users' => $users, 'refreshed_at_gmt' => current_time( 'mysql', true ), 'last_error' => '' ), false );
		$this->logger->log( 'INFO', 'didar_users_refresh_succeeded', 'Didar User metadata refreshed.', array( 'user_count' => count( $users ), 'active_user_count' => $active, 'source' => 'admin' ) );
		return $users;
	}

	private function normalize( $response ) {
		$items = isset( $response['Response'] ) && is_array( $response['Response'] ) ? $response['Response'] : $response; if ( isset( $items['List'] ) && is_array( $items['List'] ) ) { $items = $items['List']; }
		$out = array(); foreach ( (array) $items as $item ) { if ( ! is_array( $item ) || empty( $item['UserId'] ) ) { continue; } $out[] = array( 'id' => sanitize_text_field( (string) ( $item['Id'] ?? '' ) ), 'user_id' => sanitize_text_field( (string) $item['UserId'] ), 'code' => sanitize_text_field( (string) ( $item['Code'] ?? '' ) ), 'display_name' => sanitize_text_field( (string) ( $item['DisplayName'] ?? trim( ( $item['FirstName'] ?? '' ) . ' ' . ( $item['LastName'] ?? '' ) ) ) ), 'user_name' => sanitize_text_field( (string) ( $item['UserName'] ?? '' ) ), 'first_name' => sanitize_text_field( (string) ( $item['FirstName'] ?? '' ) ), 'last_name' => sanitize_text_field( (string) ( $item['LastName'] ?? '' ) ), 'is_owner' => ! empty( $item['IsOwner'] ), 'is_disabled' => ! empty( $item['IsDisabled'] ), 'invitation_accepted' => ! empty( $item['InvitationAccepted'] ) ); }
		usort( $out, function ( $a, $b ) { return strnatcasecmp( $a['display_name'], $b['display_name'] ); } ); return $out;
	}
	private function record_error( $code, $error = null ) { $cache = $this->cache_info(); $cache['last_error'] = sanitize_key( $code ); $cache['last_error_at_gmt'] = current_time( 'mysql', true ); update_option( self::OPTION_NAME, $cache, false ); $this->logger->log( 'ERROR', 'didar_users_refresh_failed', 'Didar User metadata refresh failed; existing cache retained.', array( 'error_code' => sanitize_key( $code ), 'source' => 'admin' ) ); return is_wp_error( $error ) ? $error : new WP_Error( sanitize_key( $code ), __( 'فهرست کاربران دیدار معتبر نیست.', 'didar' ) ); }
}
