<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the shared SQL-level request search used by admin and frontend lists.
 */
class Didar_Request_Search {
	const QUERY_VAR       = 'didar_request_search';
	const MAX_TERM_LENGTH = 100;

	public function __construct() {
		add_filter( 'posts_search', array( $this, 'filter_posts_search' ), 10, 2 );
	}

	public function apply_to_query( WP_Query $query, $term ) {
		$term = $this->sanitize_term( $term );
		if ( '' !== $term ) {
			$query->set( self::QUERY_VAR, $term );
		}
		return $term;
	}

	public function sanitize_term( $term ) {
		if ( ! is_scalar( $term ) ) {
			return '';
		}
		$term = trim( sanitize_text_field( (string) $term ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $term, 0, self::MAX_TERM_LENGTH );
		}
		return substr( $term, 0, self::MAX_TERM_LENGTH );
	}

	public function filter_posts_search( $search, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $search;
		}
		$term = $this->sanitize_term( $query->get( self::QUERY_VAR ) );
		if ( '' === $term ) {
			return $search;
		}

		global $wpdb;
		$like       = '%' . $wpdb->esc_like( $term ) . '%';
		$conditions = array(
			$wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $like ),
			$wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} didar_search_meta WHERE didar_search_meta.post_id = {$wpdb->posts}.ID AND didar_search_meta.meta_key = %s AND didar_search_meta.meta_value LIKE %s)", '_didar_fields', $like ),
		);
		$id_term = ltrim( $term, "# \t\n\r\0\x0B" );
		if ( ctype_digit( $id_term ) && absint( $id_term ) ) {
			$conditions[] = $wpdb->prepare( "{$wpdb->posts}.ID = %d", absint( $id_term ) );
		}

		return ' AND (' . implode( ' OR ', $conditions ) . ') ';
	}
}
