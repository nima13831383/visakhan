<?php

/** Focused local webhook validation and deduplication regression coverage. */
class Test_Didar_Webhook_Validation extends WP_UnitTestCase {
	private $settings_snapshot;
	private $ledger_snapshot;
	private $posts = array();
	private $users = array();
	private $secret = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function set_up() {
		parent::set_up();
		$this->settings_snapshot = get_option( Didar_Settings::OPTION_NAME, array() );
		$this->ledger_snapshot   = get_option( 'didar_seen_webhooks', array() );
		update_option(
			Didar_Settings::OPTION_NAME,
			array(
				'didar_webhook_secret'          => $this->secret,
				'didar_webhook_legacy_enabled' => 0,
				'didar_field_mappings'         => array( 'consultation' => array( 'first_name' => array( 'target' => 'deal_custom', 'field' => 'Field_First_Name' ) ) ),
				'didar_user_person_mappings'   => array( 'birth_date' => 'Field_Birth_Date' ),
			),
			false
		);
		update_option( 'didar_seen_webhooks', array( 'existing-event' => 123 ), false );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function tear_down() {
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) {
				wp_delete_user( $user_id );
			}
		}
		update_option( Didar_Settings::OPTION_NAME, $this->settings_snapshot, false );
		update_option( 'didar_seen_webhooks', $this->ledger_snapshot, false );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_valid_deal_is_applied_and_exact_replay_is_deduplicated() {
		$post_id = $this->create_submission( 'deal-valid-1' );
		$payload = $this->payload( 'deal-valid-event', 'Deal', array( 'Fields' => array( 'Field_First_Name' => 'نام جدید' ) ) );

		$first = $this->receive( $payload );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'نام جدید', get_post_meta( $post_id, '_didar_fields', true )['first_name'] );
		$this->assertSame( 123, get_option( 'didar_seen_webhooks' )['existing-event'] );
		$this->assertArrayHasKey( 'deal-valid-event', get_option( 'didar_seen_webhooks' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Didar_Sync_Manager::META_STATE ) );

		update_post_meta( $post_id, '_didar_fields', array( 'first_name' => 'مقدار محلی' ) );
		$duplicate = $this->receive( $payload );
		$this->assertSame( 200, $duplicate->get_status() );
		$this->assertTrue( $duplicate->get_data()['duplicate'] );
		$this->assertSame( 'مقدار محلی', get_post_meta( $post_id, '_didar_fields', true )['first_name'] );
	}

	public function test_unsupported_case_is_not_seen_and_replay_is_still_unsupported() {
		$payload = $this->payload( 'unsupported-case-event', 'Case', array( 'Fields' => array() ) );

		$first = $this->receive( $payload );
		$this->assertWPError( $first );
		$this->assertSame( 'didar_webhook_unsupported', $first->get_error_code() );
		$this->assertSame( 422, $first->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'unsupported-case-event', get_option( 'didar_seen_webhooks' ) );

		$replay = $this->receive( $payload );
		$this->assertWPError( $replay );
		$this->assertSame( 'didar_webhook_unsupported', $replay->get_error_code() );
		$this->assertSame( 422, $replay->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'unsupported-case-event', get_option( 'didar_seen_webhooks' ) );
	}

	/** @dataProvider invalid_deal_fields_provider */
	public function test_non_array_deal_fields_are_rejected_without_ledger_or_mutation( $fields ) {
		$post_id = $this->create_submission( 'deal-invalid-' . md5( wp_json_encode( $fields ) ) );
		$before  = get_post_meta( $post_id, '_didar_fields', true );
		$event_id = 'invalid-fields-' . md5( wp_json_encode( $fields ) );
		$payload = $this->payload( $event_id, 'Deal', array( 'Fields' => $fields ) );

		$result = $this->receive( $payload );
		$this->assertWPError( $result );
		$this->assertSame( 'didar_webhook_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, get_post_meta( $post_id, '_didar_fields', true ) );
		$this->assertArrayNotHasKey( $event_id, get_option( 'didar_seen_webhooks' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Didar_Sync_Manager::META_STATE ) );
	}

	public function invalid_deal_fields_provider() {
		return array( array( 'string' ), array( 123 ), array( true ), array( null ) );
	}

	public function test_absent_deal_fields_preserves_existing_processing_behavior() {
		$post_id = $this->create_submission( 'deal-fields-absent' );
		$result = $this->receive( $this->payload( 'deal-fields-absent-event', 'Deal', array() ) );

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( 'first_name' => 'قدیمی' ), get_post_meta( $post_id, '_didar_fields', true ) );
		$this->assertArrayHasKey( 'deal-fields-absent-event', get_option( 'didar_seen_webhooks' ) );
	}

	public function test_valid_person_and_duplicate_person_preserve_existing_dedupe_behavior() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'webhook-person@example.test' ) );
		$this->users[] = $user_id;
		update_user_meta( $user_id, Didar_Sync_Manager::USER_PERSON_META, 'person-valid-1' );
		update_user_meta( $user_id, Didar_User_Profile_Value_Catalog::BIRTH_DATE_META, '1990-01-01' );
		$payload = $this->payload( 'person-valid-event', 'Person', array( 'Fields' => array( 'Field_Birth_Date' => '1403/01/01' ) ), 'person-valid-1' );

		$first = $this->receive( $payload );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( '2024-03-20', get_user_meta( $user_id, Didar_User_Profile_Value_Catalog::BIRTH_DATE_META, true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, Didar_Sync_Manager::META_PERSON_STATE ) );

		update_user_meta( $user_id, Didar_User_Profile_Value_Catalog::BIRTH_DATE_META, '2000-01-01' );
		$duplicate = $this->receive( $payload );
		$this->assertSame( 200, $duplicate->get_status() );
		$this->assertTrue( $duplicate->get_data()['duplicate'] );
		$this->assertSame( '2000-01-01', get_user_meta( $user_id, Didar_User_Profile_Value_Catalog::BIRTH_DATE_META, true ) );
	}

	public function test_authentication_and_basic_validation_remain_restricted() {
		$payload = $this->payload( 'security-event', 'Deal', array( 'Fields' => array() ) );
		$missing = $this->receive( $payload, null );
		$this->assertWPError( $missing );
		$this->assertSame( 'didar_webhook_unauthorized', $missing->get_error_code() );
		$this->assertSame( 401, $missing->get_error_data()['status'] );

		$invalid = $this->receive( $payload, str_repeat( 'b', 64 ) );
		$this->assertWPError( $invalid );
		$this->assertSame( 'didar_webhook_unauthorized', $invalid->get_error_code() );
		$this->assertSame( 401, $invalid->get_error_data()['status'] );

		$malformed = $this->manager()->receive_webhook( $this->request_body( '{' ) );
		$this->assertWPError( $malformed );
		$this->assertSame( 'didar_webhook_invalid', $malformed->get_error_code() );
		$this->assertSame( 400, $malformed->get_error_data()['status'] );
	}

	private function create_submission( $deal_id ) {
		$user_id = self::factory()->user->create( array( 'user_email' => sanitize_key( $deal_id ) . '@example.test' ) );
		$this->users[] = $user_id;
		$post_id = self::factory()->post->create( array( 'post_type' => Didar_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_author' => $user_id ) );
		$this->posts[] = $post_id;
		update_post_meta( $post_id, '_didar_form_type', 'consultation' );
		update_post_meta( $post_id, '_didar_deal_id', $deal_id );
		update_post_meta( $post_id, '_didar_fields', array( 'first_name' => 'قدیمی' ) );
		return $post_id;
	}

	private function payload( $event_id, $entity, $data, $entity_id = '' ) {
		return array(
			'meta' => array( 'id' => $event_id, 'entityId' => $entity_id ?: 'external-' . $event_id, 'entityTitle' => $entity, 'actionType' => 2 ),
			'data' => $data,
		);
	}

	private function receive( $payload, $secret = null ) {
		return $this->manager()->receive_webhook( $this->request( $payload, $secret ) );
	}

	private function request( $payload, $secret = null ) {
		$request = new WP_REST_Request( 'POST', '/didar/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		if ( null !== $secret ) {
			$request->set_url_params( array( 'secret' => $secret ?: $this->secret ) );
		}
		return $request;
	}

	private function request_body( $body ) {
		$request = new WP_REST_Request( 'POST', '/didar/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_url_params( array( 'secret' => $this->secret ) );
		$request->set_body( $body );
		return $request;
	}

	private function manager() {
		return Didar_Plugin::instance()->sync_manager;
	}
}
