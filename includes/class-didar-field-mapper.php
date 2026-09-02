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
		$payload = array(
			// The documented API value is lowercase "person".
			'Type'        => 'person',
			'FirstName'   => $profile['first_name'],
			// Didar requires LastName. A WordPress display name is a safe local
			// fallback; request/submission values are intentionally never used.
			'LastName'    => $profile['last_name'] ?: ( $profile['display_name'] ?: sanitize_text_field( (string) $user->user_login ) ),
			'Email'       => $profile['email'],
			'MobilePhone' => $profile['mobile'],
			'OwnerId'     => $owner_id,
		);

		$custom = $this->person_profile_custom_fields( $profile, $settings );
		if ( $custom ) {
			$payload['Fields'] = $custom;
		}

		return $payload;
	}

	/** Return account/profile identity data, with Digits' canonical mobile metadata first. */
	public function wordpress_user_profile( $user ) {
		if ( ! $user || empty( $user->ID ) ) {
			return array( 'first_name' => '', 'last_name' => '', 'nickname' => '', 'display_name' => '', 'email' => '', 'mobile' => '', 'gender' => '', 'birth_date' => '', 'national_id' => '', 'profile_image_url' => '' );
		}

		return array(
			// Digits' WooCommerce integration stores name fields under these
			// billing keys in some registration flows. WordPress remains
			// canonical, so those values are read only when the WP field is empty.
			'first_name' => $this->wordpress_user_name( $user->ID, 'first_name', 'billing_first_name' ),
			'last_name'  => $this->wordpress_user_name( $user->ID, 'last_name', 'billing_last_name' ),
			'nickname'   => sanitize_text_field( (string) get_user_meta( $user->ID, 'nickname', true ) ),
			'display_name' => sanitize_text_field( (string) $user->display_name ),
			'email'      => sanitize_email( (string) $user->user_email ),
			'mobile'     => $this->normalize_mobile( $this->wordpress_user_mobile( $user->ID ) ),
			'gender'     => $this->user_gender( $user->ID ),
			'birth_date' => sanitize_text_field( (string) get_user_meta( $user->ID, Didar_User_Profile_Value_Catalog::BIRTH_DATE_META, true ) ),
			'national_id' => sanitize_text_field( (string) get_user_meta( $user->ID, Didar_User_Profile_Value_Catalog::NATIONAL_ID_META, true ) ),
			'profile_image_url' => $this->profile_image_url( $user->ID ),
		);
	}

	/** Prefer canonical WordPress name metadata over the installed Digits/WooCommerce fallback. */
	private function wordpress_user_name( $user_id, $wordpress_key, $digits_fallback_key ) {
		$value = sanitize_text_field( (string) get_user_meta( $user_id, $wordpress_key, true ) );
		if ( '' !== $value ) {
			return $value;
		}

		return sanitize_text_field( (string) get_user_meta( $user_id, $digits_fallback_key, true ) );
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

	/**
	 * The documented Didar mobile lookup example uses the Iranian national
	 * representation (0912...). Keep that representation for Iranian numbers
	 * so Digits' +98/98/0098 variants all refer to one Person.
	 */
	public function normalize_mobile( $value ) {
		$value = strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
		$digits = preg_replace( '/\D+/', '', $value );
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) && '9' === substr( $digits, 2, 1 ) ) {
			return '0' . substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) && '9' === substr( $digits, 0, 1 ) ) {
			return '0' . $digits;
		}
		return $digits;
	}

	/** Return supported stored variants for duplicate-safe Didar lookup. */
	public function mobile_lookup_variants( $value ) {
		$canonical = $this->normalize_mobile( $value );
		if ( ! $canonical ) {
			return array();
		}
		if ( 11 === strlen( $canonical ) && '09' === substr( $canonical, 0, 2 ) ) {
			$international = '98' . substr( $canonical, 1 );
			return array( $canonical, '+' . $international, $international, '00' . $international );
		}
		return array( $canonical );
	}

	private function user_gender( $user_id ) {
		$user_id = absint( $user_id );
		$gender  = sanitize_text_field( (string) get_user_meta( $user_id, 'gender', true ) );
		if ( '' === $gender ) {
			$legacy = sanitize_text_field( (string) get_user_meta( $user_id, 'didar_gender', true ) );
			if ( '' !== $legacy ) {
				$gender = $legacy;
			}
		}
		$canonical = array(
			'female' => 'female',
			'زن'     => 'female',
			'خانم'   => 'female',
			'male'   => 'male',
			'مرد'    => 'male',
		);
		$gender   = $canonical[ $gender ] ?? '';
		if ( $gender && $gender !== (string) get_user_meta( $user_id, 'gender', true ) ) {
			update_user_meta( $user_id, 'gender', $gender );
		}
		return $gender;
	}

	private function profile_image_url( $user_id ) {
		$user_id = absint( $user_id );
		$value   = get_user_meta( $user_id, 'profile_image', true );
		if ( '' === $value || null === $value ) {
			$legacy = get_user_meta( $user_id, 'didar_profile_image_id', true );
			if ( '' !== $legacy && null !== $legacy ) {
				$value = $legacy;
				update_user_meta( $user_id, 'profile_image', $value );
			}
		}
		if ( is_numeric( $value ) ) {
			return esc_url_raw( (string) wp_get_attachment_url( absint( $value ) ) );
		}
		return esc_url_raw( (string) $value );
	}

	private function person_profile_custom_fields( $profile, $settings ) {
		$mapping = isset( $settings['didar_user_person_mappings'] ) && is_array( $settings['didar_user_person_mappings'] ) ? $settings['didar_user_person_mappings'] : array();
		$values  = array(
			'gender'            => $profile['gender'],
			'birth_date'       => $profile['birth_date'],
			'national_id'      => $profile['national_id'],
			'display_name'      => $profile['display_name'],
			'profile_image_url' => $profile['profile_image_url'],
		);
		$out = array();
		foreach ( $values as $property => $value ) {
			$key = isset( $mapping[ $property ] ) && is_scalar( $mapping[ $property ] ) ? sanitize_text_field( (string) $mapping[ $property ] ) : '';
			if ( $key && '' !== $value ) {
				// Profile mappings are textual/custom Person fields. Keep the local
				// canonical value untouched, but serialize business dates to the
				// single Jalali wire format used by all Didar custom fields.
				$definition = 'birth_date' === $property ? array( 'type' => 'date' ) : array( 'type' => 'text' );
				$serialized = $this->serializer->serialize( 'profile', $property, $definition, $value );
				if ( '' !== $serialized ) {
					$out[ $key ] = $serialized;
				}
			}
		}
		return $out;
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
		if ( $post_id && $this->registry->supports_applicant_note( $form_type ) ) {
			$map = $this->mapping( $form_type, 'applicant_note' );
			if ( 'deal_custom' === $map['target'] && '' !== $map['field'] ) {
				$definition = $this->registry->didar_mapping_fields( $form_type )['applicant_note'];
				$custom[ $map['field'] ] = $this->serializer->serialize( $form_type, 'applicant_note', $definition, get_post_meta( $post_id, '_didar_shared_note', true ), $post_id );
			}
		}
		return $custom;
	}

	/** Serialize one Visa companion row using the same safe file/value rules as Deal fields. */
	public function companion_case_fields( $form_type, $row, $row_index, $post_id = 0, $mappings = array() ) {
		$out = array();
		$definitions = $this->registry->fields( $form_type );
		$columns = isset( $definitions['companions']['columns'] ) ? $definitions['companions']['columns'] : array();
		foreach ( (array) $mappings as $source_key => $target_key ) {
			$source_key = sanitize_key( $source_key ); $target_key = sanitize_text_field( (string) $target_key );
			if ( ! $source_key || ! $target_key || ! array_key_exists( $source_key, $row ) || ! isset( $columns[ $source_key ] ) ) continue;
			$value = $row[ $source_key ];
			$out[ $target_key ] = $this->serializer->serialize( $form_type, 'companions.' . absint( $row_index ) . '.' . $source_key, $columns[ $source_key ], $value, $post_id );
		}
		return $out;
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
