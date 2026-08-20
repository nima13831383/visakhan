<?php

/**
 * Integration tests for Didar custom-table installation and self-repair.
 */
class Test_Didar_Schema_Manager extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		delete_option( Didar_Schema_Manager::ERROR_OPTION );
		$this->assertTrue( Didar_Schema_Manager::install_and_verify() );
	}

	public function tear_down() {
		Didar_Schema_Manager::install_and_verify();
		delete_option( Didar_Schema_Manager::ERROR_OPTION );
		parent::tear_down();
	}

	public function test_required_tables_are_registered_and_current() {
		$this->assertSame(
			array(
				Didar_Event_Log::table_name(),
				Didar_File_Service::table_name(),
			),
			Didar_Schema_Manager::required_tables()
		);
		$this->assertSame( array(), Didar_Schema_Manager::missing_tables() );
		$this->assertTrue( Didar_Schema_Manager::schema_is_current() );
	}

	public function test_normal_plugin_check_recreates_a_deleted_file_table() {
		global $wpdb;

		$table_name = Didar_File_Service::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertContains( $table_name, Didar_Schema_Manager::missing_tables() );
		$this->assertTrue( Didar_Schema_Manager::maybe_repair() );
		$this->assertTrue( Didar_File_Service::schema_is_current() );
		$this->assertNotContains( $table_name, Didar_Schema_Manager::missing_tables() );
	}

	public function test_normal_plugin_check_recreates_a_deleted_event_table() {
		global $wpdb;

		$table_name = Didar_Event_Log::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertContains( $table_name, Didar_Schema_Manager::missing_tables() );
		$this->assertTrue( Didar_Schema_Manager::maybe_repair() );
		$this->assertTrue( Didar_Event_Log::schema_is_current() );
		$this->assertNotContains( $table_name, Didar_Schema_Manager::missing_tables() );
	}

	public function test_periodic_full_check_repairs_an_incomplete_table() {
		global $wpdb;

		$table_name = Didar_Event_Log::table_name();
		$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX event_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option(
			Didar_Schema_Manager::STATE_OPTION,
			array(
				'version'         => Didar_Schema_Manager::SCHEMA_VERSION,
				'last_attempt'    => 0,
				'last_full_check' => 0,
			),
			true
		);

		$this->assertFalse( Didar_Event_Log::schema_is_current() );
		$this->assertTrue( Didar_Schema_Manager::maybe_repair() );
		$this->assertTrue( Didar_Event_Log::schema_is_current() );
	}

	public function test_missing_file_table_uses_database_defaults_when_the_configured_collation_fails() {
		global $wpdb;

		$table_name        = Didar_File_Service::table_name();
		$original_collate  = $wpdb->collate;
		$original_suppress = $wpdb->suppress_errors();
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->collate = 'didar_invalid_collation';
		$wpdb->suppress_errors( true );

		try {
			$this->assertTrue( Didar_File_Service::install_schema() );
			$this->assertTrue( Didar_File_Service::schema_is_current() );
		} finally {
			$wpdb->collate = $original_collate;
			$wpdb->suppress_errors( $original_suppress );
			Didar_File_Service::install_schema();
		}
	}
}
