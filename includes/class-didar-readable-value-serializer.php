<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Converts structured WordPress values to readable plain text for Didar. */
class Didar_Readable_Value_Serializer {
	const MAX_DEPTH = 6;
	const MAX_ITEMS = 100;

	private $files;
	private $logger;

	public function __construct( Didar_File_Service $files = null, Didar_Logger $logger = null ) {
		$this->files  = $files;
		$this->logger = $logger;
	}

	public function is_structured( $definition ) {
		$definition = is_array( $definition ) ? $definition : array();
		return in_array( (string) ( $definition['type'] ?? '' ), array( 'repeater', 'file', 'checkbox', 'multiselect', 'multi-select' ), true ) || ! empty( $definition['multiple'] ) || ! empty( $definition['columns'] );
	}

	public function serialize( $form_type, $field_key, $definition, $value, $post_id = 0 ) {
		$definition = is_array( $definition ) ? $definition : array();
		$type = (string) ( $definition['type'] ?? '' );
		if ( 'file' === $type ) { return $this->files( $value, $post_id, $field_key ); }
		if ( 'repeater' === $type || ! empty( $definition['columns'] ) ) { return $this->repeater( $value, $definition, 0 ); }
		if ( $this->is_structured( $definition ) && is_array( $value ) ) { return $this->list_value( $value, $definition ); }
		return $this->scalar( $value, $definition );
	}

	private function scalar( $value, $definition = array() ) {
		if ( is_bool( $value ) ) { return $value ? 'بله' : 'خیر'; }
		if ( is_scalar( $value ) ) { return 'textarea' === ( $definition['type'] ?? '' ) ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ); }
		return '';
	}

	private function label( $key, $definition ) {
		$options = isset( $definition['options'] ) && is_array( $definition['options'] ) ? $definition['options'] : array();
		if ( array_key_exists( $key, $options ) && is_scalar( $options[ $key ] ) ) { return (string) $options[ $key ]; }
		return is_scalar( $key ) ? (string) $key : '';
	}

	private function list_value( $value, $definition ) {
		$lines = array(); $items = is_array( $value ) ? array_values( $value ) : array( $value ); $count = 0;
		foreach ( $items as $item_key => $item ) { if ( $count++ >= self::MAX_ITEMS ) { $this->truncated(); break; } if ( is_bool( $item ) && ! $item ) { continue; } $choice = ( is_string( $item_key ) && ! empty( $definition['options'] ) && isset( $definition['options'][ $item_key ] ) ) ? $item_key : $item; $text = is_scalar( $choice ) ? $this->label( $choice, $definition ) : $this->nested( $choice, $definition, 0, '' ); if ( '' !== trim( $text ) ) { $lines[] = '- ' . $text; } }
		return implode( "\n", $lines );
	}

	private function repeater( $value, $definition, $depth ) {
		if ( $depth > self::MAX_DEPTH ) { $this->truncated(); return ''; }
		$columns = isset( $definition['columns'] ) && is_array( $definition['columns'] ) ? $definition['columns'] : array();
		$rows = is_array( $value ) ? array_values( $value ) : array(); $lines = array(); $number = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$properties = array();
			foreach ( $row as $key => $child ) {
				$child_def = isset( $columns[ $key ] ) && is_array( $columns[ $key ] ) ? $columns[ $key ] : array();
				$text = $this->nested( $child, $child_def, $depth + 1, '' );
				if ( '' === trim( $text ) ) { continue; }
				$label = isset( $child_def['label'] ) && '' !== (string) $child_def['label'] ? (string) $child_def['label'] : (string) $key;
				$properties[] = $this->indent_property( $label, $text, $depth + 1 );
			}
			if ( ! $properties ) { continue; }
			$number++; $lines[] = $number . ') ' . ltrim( array_shift( $properties ) );
			foreach ( $properties as $property ) { $lines[] = $property; }
			$lines[] = '';
		}
		return trim( implode( "\n", $lines ) );
	}

	private function nested( $value, $definition, $depth, $prefix ) {
		if ( $depth > self::MAX_DEPTH ) { $this->truncated(); return ''; }
		if ( is_scalar( $value ) || is_bool( $value ) ) { return ( is_scalar( $value ) && ! empty( $definition['options'] ) ) ? $this->label( $value, $definition ) : $this->scalar( $value, $definition ); }
		if ( ! is_array( $value ) ) { return ''; }
		if ( ! empty( $definition['columns'] ) ) { return $this->repeater( $value, $definition, $depth ); }
		if ( $this->is_structured( $definition ) ) { return $this->list_value( $value, $definition ); }
		$lines = array(); $count = 0;
		foreach ( $value as $key => $item ) {
			if ( $count++ >= self::MAX_ITEMS ) { $this->truncated(); break; }
			$text = $this->nested( $item, array(), $depth + 1, '' ); if ( '' === trim( $text ) ) { continue; }
			if ( is_int( $key ) ) { $lines[] = '- ' . $text; } else { $lines[] = $this->indent_property( (string) $key, $text, $depth ); }
		}
		return implode( "\n", $lines );
	}

	private function indent_property( $label, $text, $depth ) {
		$indent = str_repeat( '   ', max( 1, $depth ) ); $text = trim( (string) $text );
		if ( false !== strpos( $text, "\n" ) ) { $parts = explode( "\n", $text ); $first = array_shift( $parts ); return $indent . $label . ': ' . $first . "\n" . implode( "\n", array_map( function ( $line ) use ( $indent ) { return $indent . '   ' . $line; }, $parts ) ); }
		return $indent . $label . ': ' . $text;
	}

	private function files( $value, $post_id, $field_key ) {
		$ids = is_array( $value ) ? array_values( $value ) : ( '' !== (string) $value ? array( $value ) : array() ); $lines = array(); $number = 0;
		foreach ( $ids as $file_id ) {
			if ( ! $this->files || ! absint( $file_id ) ) { continue; }
			$record = $this->files->get( absint( $file_id ) );
			if ( ! is_array( $record ) || (int) ( $record['submission_id'] ?? 0 ) !== absint( $post_id ) || (string) ( $record['field_key'] ?? '' ) !== (string) $field_key ) { continue; }
			$name = sanitize_text_field( (string) ( $record['original_name'] ?? '' ) ); if ( '' === $name ) { continue; }
			// Didar receives only the existing direct sync URL; secure admin download URLs
			// are intentionally not used for outbound CRM field snapshots.
			$url = $this->files->get_sync_url( absint( $file_id ), $post_id, $field_key ); $number++; $lines[] = $number . ') ' . $name; if ( $url ) { $lines[] = '   لینک: ' . esc_url_raw( $url ); } $lines[] = '';
		}
		return trim( implode( "\n", $lines ) );
	}

	private function truncated() { if ( $this->logger ) { $this->logger->log( 'WARNING', 'didar_readable_value_truncated', 'Structured field value exceeded the readable serializer safety limit.', array( 'source' => 'readable_value_serializer' ) ); } }
}
