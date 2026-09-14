<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared companion row schema for every form that supports companions. */
class Didar_Companion_Model {
	public static function supports_form( $form_type ) {
		return in_array( sanitize_key( (string) $form_type ), array( 'visa_request', 'embassy_appointment' ), true );
	}

	public static function columns( $occupation_options = array(), $upload_definition = array() ) {
		$upload_definition = is_array( $upload_definition ) ? $upload_definition : array();
		return array(
			'companion_uid' => array( 'label' => 'شناسه فنی همراه', 'type' => 'hidden', 'internal' => true ),
			'full_name' => array( 'label' => 'نام و نام خانوادگی', 'type' => 'text' ),
			'family_relation' => array( 'label' => 'نسبت خانوادگی', 'type' => 'select', 'options' => Didar_Reference_Data::family_relations() ),
			'age' => array( 'label' => 'سن', 'type' => 'number', 'inputmode' => 'numeric', 'min' => 0, 'max' => 130, 'step' => 1, 'semantic' => 'age' ),
			'age_group' => array( 'label' => 'گروه سنی', 'type' => 'select', 'options' => Didar_Reference_Data::age_groups() ),
			'occupation' => array( 'label' => 'شغل', 'type' => 'select', 'options' => is_array( $occupation_options ) ? $occupation_options : array() ),
			'national_id' => array( 'label' => 'کد ملی', 'type' => 'text', 'semantic' => 'national_id', 'inputmode' => 'numeric', 'pattern' => '[0-9]+' ),
			'passport_number' => array( 'label' => 'شماره گذرنامه', 'type' => 'text', 'semantic' => 'passport_number', 'placeholder' => 'A12345678', 'maxlength' => 9, 'pattern' => '[A-Za-z][0-9]{8}', 'autocapitalize' => 'characters' ),
			'email' => array( 'label' => 'ایمیل', 'type' => 'email', 'autocomplete' => 'email' ),
			'phone' => array( 'label' => 'شماره تماس', 'type' => 'text', 'inputmode' => 'tel', 'autocomplete' => 'tel' ),
			'personal_photo' => array_merge( array( 'label' => 'عکس شخصی', 'type' => 'file' ), $upload_definition ),
			'passport_main_page' => array_merge( array( 'label' => 'صفحه اصلی گذرنامه', 'type' => 'file' ), $upload_definition ),
			'round_trip_ticket' => array_merge( array( 'label' => 'بلیط رفت و برگشت', 'type' => 'file' ), $upload_definition ),
			'other_documents' => array_merge( array( 'label' => 'سایر مدارک', 'type' => 'file' ), $upload_definition ),
		);
	}

	/** Return submitted companion rows without inferring or changing business values. */
	public static function rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$out[] = $row;
		}
		return $out;
	}

	public static function main_uid( $post_id ) { return 'main_' . absint( $post_id ); }

	public static function main_applicant_row( $form_type, $fields ) {
		$form_type = sanitize_key( (string) $form_type );
		$first = sanitize_text_field( (string) ( $fields['first_name'] ?? '' ) );
		$last = sanitize_text_field( (string) ( $fields['last_name'] ?? '' ) );
		return array(
			'companion_uid' => '',
			'full_name' => trim( $first . ' ' . $last ),
			'family_relation' => '',
			'age' => '',
			'age_group' => '',
			'occupation' => sanitize_text_field( (string) ( $fields[ 'embassy_appointment' === $form_type ? 'profession' : 'occupation' ] ?? '' ) ),
			'national_id' => sanitize_text_field( (string) ( $fields['national_id'] ?? '' ) ),
			'passport_number' => sanitize_text_field( (string) ( $fields['passport_number'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $fields['email'] ?? '' ) ),
			'phone' => sanitize_text_field( (string) ( $fields[ 'embassy_appointment' === $form_type ? 'mobile' : 'mobile' ] ?? ( $fields['phone'] ?? '' ) ) ),
			'case_role' => 'main_applicant',
		);
	}
}
