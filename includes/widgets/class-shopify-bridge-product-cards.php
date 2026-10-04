<?php
defined('ABSPATH') || exit;

/** Shared "product card grid" renderer (image, title, price) for widgets that show a list of WP post IDs resolved from a Shopify product reference field. */
class Shopify_Bridge_Product_Cards {

	public static function render(array $post_ids, $wrapper_class = 'ns-bridge-product-cards', $image_size = 'medium', $show_rating = true, $show_separator = false) {
		echo '<div class="' . esc_attr($wrapper_class) . '">';
		$rendered = 0;
		foreach ($post_ids as $post_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
			if (!$product) {
				continue;
			}
			if ($show_separator && $rendered > 0) {
				echo '<span class="ns-bridge-product-card-separator" aria-hidden="true">+</span>';
			}
			$rendered++;
			$rating_html = '';
			if ($show_rating && $product->get_rating_count() > 0) {
				$rating_html = '<span class="ns-bridge-product-card-rating">' . wc_get_rating_html($product->get_average_rating()) . '</span>';
			}
			printf(
				'<a class="ns-bridge-product-card" href="%s"><span class="ns-bridge-product-card-image">%s</span><span class="ns-bridge-product-card-title">%s</span>%s<span class="ns-bridge-product-card-price">%s</span></a>',
				esc_url(get_permalink($post_id)),
				wp_kses_post($product->get_image($image_size)),
				esc_html($product->get_name()),
				$rating_html, // already escaped by wc_get_rating_html()
				wp_kses_post($product->get_price_html())
			);
		}
		echo '</div>';
	}
}
