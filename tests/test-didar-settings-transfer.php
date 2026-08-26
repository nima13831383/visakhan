<?php

class Test_Didar_Settings_Transfer extends WP_UnitTestCase {
	private $transfer;

	public function set_up() {
		parent::set_up();
		delete_option( Didar_Settings::OPTION_NAME );
		delete_option( Didar_Settings_Transfer::BACKUPS_OPTION );
		$this->transfer = new Didar_Settings_Transfer( new Didar_Form_Registry(), new Didar_Settings(), new Didar_Logger() );
	}

	public function tear_down() { delete_option( Didar_Settings::OPTION_NAME ); delete_option( Didar_Settings_Transfer::BACKUPS_OPTION ); parent::tear_down(); }

	public function test_export_excludes_secrets_and_runtime_caches() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_api_key' => 'secret', 'didar_webhook_secret' => 'token', 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'p' ) ), 'didar_pipeline_cache' => array( 'runtime' => true ) ) );
		$json = $this->transfer->export_json();
		$this->assertStringNotContainsString( 'secret', $json ); $this->assertStringNotContainsString( 'token', $json ); $this->assertStringNotContainsString( 'didar_pipeline_cache', $json );
	}

	public function test_persian_labels_survive_json_round_trip() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_form_workflows' => array( 'consultation' => array( 'pipeline_id' => 'p', 'statuses' => array( 'pending' => array( 'label' => 'در انتظار بررسی', 'stage_id' => 's', 'is_default' => true, 'order' => 10 ) ) ) ) ) );
		$parsed = $this->transfer->parse_json( $this->transfer->export_json() ); $this->assertIsArray( $parsed ); $this->assertSame( 'در انتظار بررسی', $parsed['settings']['didar_form_workflows']['consultation']['statuses']['pending']['label'] );
	}

	public function test_rejects_malformed_and_future_schema() {
		$this->assertWPError( $this->transfer->parse_json( '{' ) );
		$this->assertWPError( $this->transfer->parse_json( wp_json_encode( array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 99, 'settings' => array() ) ) ) );
	}

	public function test_merge_preserves_unrelated_and_backup_is_created() {
		update_option( Didar_Settings::OPTION_NAME, array( 'didar_api_key' => 'keep', 'file_download_mode' => 'secure' ) );
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'frontend_requests_per_page' => 20 ) );
		$preview = $this->transfer->preview( $data, 'merge' ); $this->assertEmpty( $preview['errors'] ); $result = $this->transfer->apply( $preview ); $this->assertNotWPError( $result ); $saved = get_option( Didar_Settings::OPTION_NAME ); $this->assertSame( 'keep', $saved['didar_api_key'] ); $this->assertSame( 20, $saved['frontend_requests_per_page'] ); $this->assertNotEmpty( $this->transfer->latest_backup() );
	}

	public function test_missing_metadata_is_not_verified_not_invalid() {
		$cache_names = array( 'didar_deal_pipeline_cache', 'didar_custom_field_cache', 'didar_user_cache' );
		$saved = array(); foreach ( $cache_names as $name ) { $saved[ $name ] = get_option( $name, false ); delete_option( $name ); }
		$preview = $this->transfer->preview( $this->transfer->parse_json( $this->transfer->export_json() ), 'merge' );
		foreach ( $saved as $name => $value ) { if ( false !== $value ) { update_option( $name, $value, false ); } }
		$this->assertEmpty( $preview['warnings'] ); $this->assertEmpty( $preview['errors'] ); $this->assertNotEmpty( $preview['not_verified'] );
	}

	public function test_field_mappings_normalize_legacy_scalar_and_typed_shapes() {
		$data = array( 'format' => Didar_Settings_Transfer::FORMAT, 'schema_version' => 1, 'settings' => array( 'didar_field_mappings' => array( 'consultation' => array( 'first_name' => 'Field_ABC_1', 'last_name' => array( 'type' => 'deal_custom', 'key' => 'Field_DEF_2' ) ) ) ) );
		$preview = $this->transfer->preview( $data, 'merge' );
		$this->assertSame( array( 'target' => 'deal_custom', 'field' => 'Field_ABC_1' ), $preview['incoming']['didar_field_mappings']['consultation']['first_name'] );
		$this->assertSame( array( 'target' => 'deal_custom', 'field' => 'Field_DEF_2' ), $preview['incoming']['didar_field_mappings']['consultation']['last_name'] );
	}
}
