<?php

class Test_Didar_Readable_Value_Serializer extends WP_UnitTestCase {
	private $serializer;

	public function set_up() {
		parent::set_up();
		$this->serializer = new Didar_Readable_Value_Serializer();
	}

	public function test_scalar_is_preserved() {
		$this->assertSame( 'Germany', $this->serializer->serialize( 'x', 'country', array( 'type' => 'text' ), 'Germany' ) );
	}

	public function test_multiselect_is_one_item_per_line() {
		$value = $this->serializer->serialize( 'x', 'countries', array( 'type' => 'checkbox', 'multiple' => true, 'options' => array( 'de' => 'آلمان', 'fr' => 'فرانسه' ) ), array( 'de', 'fr' ) );
		$this->assertSame( "- آلمان\n- فرانسه", $value );
	}

	public function test_repeater_uses_labels_and_omits_empty_values() {
		$definition = array( 'type' => 'repeater', 'columns' => array( 'name' => array( 'label' => 'نام' ), 'email' => array( 'label' => 'ایمیل' ), 'phone' => array( 'label' => 'موبایل' ) ) );
		$value = $this->serializer->serialize( 'x', 'people', $definition, array( array( 'name' => 'علی', 'email' => '', 'phone' => '0912' ), array( 'name' => '', 'email' => '', 'phone' => '' ) ) );
		$this->assertSame( "1) نام: علی\n   موبایل: 0912", $value );
	}

	public function test_nested_values_are_indented_and_readable() {
		$definition = array( 'type' => 'repeater', 'columns' => array( 'name' => array( 'label' => 'نام' ), 'countries' => array( 'label' => 'کشورها', 'type' => 'checkbox', 'multiple' => true, 'options' => array( 'de' => 'آلمان', 'fr' => 'فرانسه' ) ) ) );
		$value = $this->serializer->serialize( 'x', 'people', $definition, array( array( 'name' => 'علی', 'countries' => array( 'de', 'fr' ) ) ) );
		$this->assertStringContainsString( "1) نام: علی", $value );
		$this->assertStringContainsString( "   کشورها: - آلمان", $value );
	}
}
