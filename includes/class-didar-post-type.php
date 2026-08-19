<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Post_Type {
	const POST_TYPE = 'didar_submission';

	public static function register() {
		$labels = array(
			'name'                  => __( 'درخواست‌های دیدار', 'didar' ),
			'singular_name'         => __( 'درخواست', 'didar' ),
			'menu_name'             => __( 'دیدار', 'didar' ),
			'add_new'               => __( 'افزودن درخواست', 'didar' ),
			'add_new_item'          => __( 'افزودن درخواست جدید', 'didar' ),
			'edit_item'             => __( 'ویرایش درخواست', 'didar' ),
			'new_item'              => __( 'درخواست جدید', 'didar' ),
			'view_item'             => __( 'مشاهده درخواست', 'didar' ),
			'search_items'          => __( 'جستجوی درخواست‌ها', 'didar' ),
			'not_found'             => __( 'درخواستی یافت نشد.', 'didar' ),
			'not_found_in_trash'    => __( 'درخواستی در زباله‌دان یافت نشد.', 'didar' ),
			'all_items'             => __( 'همه درخواست‌ها', 'didar' ),
			'archives'              => __( 'درخواست‌ها', 'didar' ),
			'attributes'            => __( 'ویژگی‌های درخواست', 'didar' ),
			'insert_into_item'      => __( 'افزودن به درخواست', 'didar' ),
			'uploaded_to_this_item' => __( 'بارگذاری‌شده برای این درخواست', 'didar' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-forms',
				'supports'            => array(),
				'capability_type'     => array( 'didar_submission', 'didar_submissions' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts' => 'create_didar_submissions',
				),
			)
		);
	}

	public static function add_administrator_capabilities() {
		Didar_Access_Control::install_roles_and_capabilities();
	}
}
