<?php
defined('ABSPATH') || exit;

/** Shared "product card grid" renderer (image, title, price) for widgets that show a list of WP post IDs resolved from a Shopify product reference field. */
class Shopify_Bridge_Product_Cards {

	public static function render(array $post_ids, $wrapper_class = 'ns-bridge-product-cards', $image_size = 'medium') {
		echo '<div class="' . esc_attr($wrapper_class) . '">';
		foreach ($post_ids as $post_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
			if (!$product) {
				continue;
			}
			printf(
				'<a class="ns-bridge-product-card" href="%s"><span class="ns-bridge-product-card-image">%s</span><span class="ns-bridge-product-card-title">%s</span><span class="ns-bridge-product-card-price">%s</span></a>',
				esc_url(get_permalink($post_id)),
				wp_kses_post($product->get_image($image_size)),
				esc_html($product->get_name()),
				wp_kses_post($product->get_price_html())
			);
		}
		echo '</div>';
	}
}
