<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves stable form_type/field_key mappings without hardcoded custom IDs. */
class Didar_Field_Mapper {
	private $registry;
	private $settings;
	private $files;
	private $serializer;

	public function __construct( Didar_Form_Registry $registry, Didar_Settings $settings, Didar_File_Service $files = null, Didar_Logger $logger = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->files    = $files;
		$this->serializer = new Didar_Readable_Value_Serializer( $files, $logger );
	}

	public function mapping( $form_type, $field_key ) {
		$settings = $this->settings->all();
		$stored   = isset( $settings['didar_field_mappings'][ $form_type ][ $field_key ] ) && is_array( $settings['didar_field_mappings'][ $form_type ][ $field_key ] ) ? $settings['didar_field_mappings'][ $form_type ][ $field_key ] : array();
		$target   = isset( $stored['target'] ) ? sanitize_key( (string) $stored['target'] ) : '';
		$field    = isset( $stored['field'] ) && is_scalar( $stored['field'] ) ? sanitize_text_field( (string) $stored['field'] ) : '';
		$native_fields = 'person_native' === $target ? array( 'FirstName', 'LastName', 'Title', 'OwnerId', 'BirthDate', 'MobilePhone', 'WorkPhone', 'Email', 'Position', 'NationalCode', 'ZipCode', 'BackgroundInfo', 'CustomerCode', 'CityId', 'ProvinceId' ) : array( 'Title', 'Description', 'PersonId', 'PipelineId', 'PipelineStageId', 'OwnerId', 'Status', 'Price', 'ExpectedCloseDate', 'VisibilityType' );
		if ( in_array( $target, array( 'person_native', 'deal_native' ), true ) && ! in_array( $field, $native_fields, true ) ) { $target = ''; $field = ''; }
		if ( '' === $target && '' === $field ) {
			$request_identity_fields = array( 'first_name', 'last_name', 'full_name', 'mobile', 'input_3', 'phone', 'email', 'applicant_email', 'applicant_mobile' );
			if ( in_array( $field_key, $request_identity_fields, true ) ) {
				return array( 'target' => 'deal_custom', 'field' => '' );
			}
		}
		return array( 'target' => in_array( $target, array( 'person_native', 'person_custom', 'deal_native', 'deal_custom' ), true ) ? $target : '', 'field' => $field );
	}

	/** Build the Person payload exclusively from the WordPress account/profile. */
	public function person_payload( $user, $fields = array(), $form_type = '' ) {
		$profile = $this->wordpress_user_profile( $user );
		$settings = $this->settings->all();
		$owner_id = isset( $settings['didar_default_owner_id'] ) ? sanitize_text_field( (string) $settings['didar_default_owner_id'] ) : '';
		return array(
			'Type'        => 'Person',
			'FirstName'   => $profile['first_name'],
			'LastName'    => $profile['last_name'],
			'Email'       => $profile['email'],
			'MobilePhone' => $profile['mobile'],
			'OwnerId'     => $owner_id,
		);
	}

	/** Return account/profile identity data, with Digits' canonical mobile metadata first. */
	public function wordpress_user_profile( $user ) {
		if ( ! $user || empty( $user->ID ) ) {
			return array( 'first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => '' );
		}

		return array(
			'first_name' => sanitize_text_field( (string) get_user_meta( $user->ID, 'first_name', true ) ),
			'last_name'  => sanitize_text_field( (string) get_user_meta( $user->ID, 'last_name', true ) ),
			'email'      => sanitize_email( (string) $user->user_email ),
			'mobile'     => $this->wordpress_user_mobile( $user->ID ),
		);
	}

	/** Resolve the installed Digits value; do not use form-entered or guessed mobile keys. */
	public function wordpress_user_mobile( $user_id ) {
		$user_id = absint( $user_id );
		$mobile  = sanitize_text_field( (string) get_user_meta( $user_id, 'digits_phone', true ) );
		if ( $mobile ) {
			return $mobile;
		}

		$phone_no   = sanitize_text_field( (string) get_user_meta( $user_id, 'digits_phone_no', true ) );
		$country    = sanitize_text_field( (string) get_user_meta( $user_id, 'digt_countrycode', true ) );
		return $country . $phone_no;
	}

	public function deal_fields( $form_type, $fields, $post_id = 0 ) {
		$custom = array();
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'deal_custom' === $map['target'] && '' !== $map['field'] ) {
				$definition = $this->registry->fields( $form_type )[ $key ] ?? array();
				$custom[ $map['field'] ] = $this->serializer->serialize( $form_type, $key, $definition, $value, $post_id );
			}
		}
		return $custom;
	}

	public function is_structured_field( $definition ) { return $this->serializer->is_structured( $definition ); }

	public function deal_native_fields( $form_type, $fields ) {
		$native = array();
		foreach ( (array) $fields as $key => $value ) {
			$map = $this->mapping( $form_type, $key );
			if ( 'deal_native' === $map['target'] && '' !== $map['field'] ) { $native[ $map['field'] ] = $this->value( $value ); }
		}
		return $native;
	}

	/** Return legacy request mappings that still point at Person fields, without changing their saved settings. */
	public function legacy_request_person_mappings( $form_type ) {
		$legacy = array();
		foreach ( (array) $this->registry->fields( $form_type ) as $key => $definition ) {
			$map = $this->mapping( $form_type, $key );
			if ( in_array( $map['target'], array( 'person_native', 'person_custom' ), true ) ) {
				$legacy[ $key ] = $map;
			}
		}

		return $legacy;
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
