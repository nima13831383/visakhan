<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves stable form_type/field_key mappings without hardcoded custom IDs. */
class Didar_Field_Mapper {
	private $registry;
	private $settings;
	private $files;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_File_Service $files = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->files    = $files;
	}

	public function mapping( $form_type, $field_key ) {
		$settings = $this->settings->all();
		$stored   = isset( $settings['didar_field_mappings'][ $form_type ][ $field_key ] ) && is_array( $settings['didar_field_mappings'][ $form_type ][ $field_key ] ) ? $settings['didar_field_mappings'][ $form_type ][ $field_key ] : array();
		$target   = isset( $stored['target'] ) ? sanitize_key( (string) $stored['target'] ) : '';
		$field    = isset( $stored['field'] ) && is_scalar( $stored['field'] ) ? sanitize_text_field( (string) $stored['field'] ) : '';
		$native_fields = 'person_native' === $target ? array( 'FirstName', 'LastName', 'Title', 'OwnerId', 'BirthDate', 'MobilePhone', 'WorkPhone', 'Email', 'Position', 'NationalCode', 'ZipCode', 'BackgroundInfo', 'CustomerCode', 'CityId', 'ProvinceId' ) : array( 'Title', 'Description', 'PersonId', 'PipelineId', 'PipelineStageId', 'OwnerId', 'Status', 'Price', 'ExpectedCloseDate', 'VisibilityType' );
		if ( in_array( $target, array( 'person_native', 'deal_native' ), true ) && ! in_array( $field, $native_fields, true ) ) { $target = ''; $field = ''; }
		if ( '' === $target && '' === $field ) {
			$native = array(
				'first_name' => array( 'target' => 'person_native', 'field' => 'FirstName' ),
				'last_name'  => array( 'target' => 'person_native', 'field' => 'LastName' ),
				'mobile'     => array( 'target' => 'person_native', 'field' => 'MobilePhone' ),
				'input_3'    => array( 'target' => 'person_native', 'field' => 'MobilePhone' ),
				'email'      => array( 'target' => 'person_native', 'field' => 'Email' ),
			);
			if ( isset( $native[ $field_key ] ) ) {
				return $native[ $field_key ];
			}
		}
		return array( 'target' => in_array( $target, array( 'person_native', 'person_custom', 'deal_native', 'deal_custom' ), true ) ? $target : '', 'field' => $field );
	}

	public function person_payload( $user, $fields, $form_type = '' ) {
		$parts = $this->name_parts( $fields, $user );
		$settings = $this->settings->all();
		$owner_id = isset( $settings['didar_default_owner_id'] ) ? sanitize_text_field( (string) $settings['didar_default_owner_id'] ) : '';
		$contact = array( 'Type' => 'Person', 'FirstName' => $parts['first_name'], 'LastName' => $parts['last_name'], 'Email' => $user ? sanitize_email( $user->user_email ) : '', 'OwnerId' => $owner_id );
		$custom  = array();
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'person_native' === $map['target'] && '' !== $map['field'] ) {
				$contact[ $map['field'] ] = $this->value( $value );
			} elseif ( 'person_custom' === $map['target'] && '' !== $map['field'] ) {
				$custom[ $map['field'] ] = $this->value( $value );
			}
		}
		if ( $user && ! isset( $contact['MobilePhone'] ) && $user->user_login ) {
			$mobile = get_user_meta( $user->ID, 'mobile', true );
			if ( $mobile ) {
				$contact['MobilePhone'] = sanitize_text_field( $mobile );
			}
		}
		if ( $custom ) {
			$contact['Fields'] = $custom;
		}
		return $contact;
	}

	/** Return the request mobile using the configured native mapping, with legacy consultation keys supported. */
	public function request_mobile( $form_type, $fields ) {
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'person_native' === $map['target'] && 'MobilePhone' === $map['field'] && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}
		foreach ( array( 'mobile', 'input_3' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) && '' !== trim( (string) $fields[ $key ] ) ) { return sanitize_text_field( (string) $fields[ $key ] ); }
		}
		return '';
	}

	public function deal_fields( $form_type, $fields, $post_id = 0 ) {
		$custom = array();
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'deal_custom' === $map['target'] && '' !== $map['field'] ) {
				$definition = $this->registry->fields( $form_type )[ $key ] ?? array();
				if ( 'file' === ( $definition['type'] ?? '' ) && $this->files && is_array( $value ) ) {
					$urls = array(); foreach ( $value as $file_id ) { $url = $this->files->get_sync_url( $file_id, $post_id, $key ); if ( $url ) { $urls[] = $url; } }
					$custom[ $map['field'] ] = $urls;
				} else {
					$custom[ $map['field'] ] = $this->value( $value, $form_type, $key, $post_id );
				}
			}
		}
		return $custom;
	}

	public function deal_native_fields( $form_type, $fields ) {
		$native = array();
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'deal_native' === $map['target'] && '' !== $map['field'] ) { $native[ $map['field'] ] = $this->value( $value ); }
		}
		return $native;
	}

	public function name_parts( $fields, $user = null ) {
		$first = isset( $fields['first_name'] ) && is_scalar( $fields['first_name'] ) ? trim( sanitize_text_field( $fields['first_name'] ) ) : '';
		$last  = isset( $fields['last_name'] ) && is_scalar( $fields['last_name'] ) ? trim( sanitize_text_field( $fields['last_name'] ) ) : '';
		$name  = isset( $fields['full_name'] ) && is_scalar( $fields['full_name'] ) ? trim( sanitize_text_field( $fields['full_name'] ) ) : ( isset( $fields['input_1'] ) ? trim( sanitize_text_field( (string) $fields['input_1'] ) ) : '' );
		if ( ! $first && $user ) { $first = sanitize_text_field( $user->first_name ); }
		if ( ! $last && $user ) { $last = sanitize_text_field( $user->last_name ); }
		if ( ( ! $first || ! $last ) && $name ) {
			$parts = preg_split( '/\s+/u', $name, 2 );
			$first = $first ?: ( isset( $parts[0] ) ? $parts[0] : '' );
			$last  = $last ?: ( isset( $parts[1] ) ? $parts[1] : $first );
		}
		return array( 'first_name' => $first ?: 'کاربر', 'last_name' => $last ?: 'دیدار' );
	}

	private function value( $value, $form_type = '', $field_key = '', $post_id = 0 ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) { $out[] = $this->value( $item, $form_type, $field_key, $post_id ); }
			return $out;
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
