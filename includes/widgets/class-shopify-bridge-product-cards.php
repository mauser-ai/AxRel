<?php
defined('ABSPATH') || exit;

/** Shared "product card grid" renderer (image, title, price) for widgets that show a list of WP post IDs resolved from a Shopify product reference field. */
class Shopify_Bridge_Product_Cards {

	/**
	 * $hero_post_id (optional): the CURRENT product (the one this widget sits
	 * on), rendered first as an image-only item — no title/price, since it's
	 * the product the visitor is already looking at — on the same flex row
	 * as the rest, "+"-separated from the first real card exactly like the
	 * cards are separated from each other. Used by the Routine Tabs widget so
	 * its leftmost image always matches the page's own product instead of
	 * being a separate, uncontrolled/unrelated image.
	 */
	public static function render(array $post_ids, $wrapper_class = 'ns-bridge-product-cards', $image_size = 'medium', $show_rating = true, $show_separator = false, $hero_post_id = null) {
		echo '<div class="' . esc_attr($wrapper_class) . '">';
		$rendered = 0;

		$hero_product = $hero_post_id && function_exists('wc_get_product') ? wc_get_product($hero_post_id) : null;
		if ($hero_product) {
			printf(
				'<span class="ns-bridge-product-card-hero">%s</span>',
				wp_kses_post($hero_product->get_image('large'))
			);
			$rendered++;
		}

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
