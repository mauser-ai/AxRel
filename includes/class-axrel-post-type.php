<?php
defined('ABSPATH') || exit;

/**
 * Registers the axrel_product CPT with a stable, real WordPress URL
 * (/products/the-cure/) so Google indexes it as an ordinary product page,
 * per the Shopify+WordPress SEO brief.
 */
class Axrel_Post_Type {

	public static function register() {
		register_post_type(Axrel_Product_Sync::POST_TYPE, [
			'label'        => 'Prodotti Shopify',
			'public'       => true,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-cart',
			'supports'     => ['title', 'editor', 'thumbnail'],
			'has_archive'  => 'products',
			'rewrite'      => ['slug' => 'products', 'with_front' => false],
			'show_in_rest' => true,
		]);
	}
}
