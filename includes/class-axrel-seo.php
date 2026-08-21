<?php
defined('ABSPATH') || exit;

/**
 * Server-side SEO output for axrel_product pages: title, meta description,
 * canonical and Product JSON-LD land straight in the HTML on first render
 * (no client-side JS required), per the Shopify+WordPress SEO brief.
 * Defers to Yoast/RankMath/SEOPress for title/description/canonical when one
 * of them is active, to avoid duplicate or conflicting tags; JSON-LD is
 * always emitted since none of those plugins generate Product schema for a
 * non-WooCommerce post type on their own.
 */
class Axrel_SEO {

	public static function register() {
		add_action('wp_head', [__CLASS__, 'output_head_tags'], 1);
		add_filter('document_title_parts', [__CLASS__, 'filter_title']);
	}

	private static function is_target() {
		return is_singular(Axrel_Product_Sync::POST_TYPE);
	}

	public static function filter_title($parts) {
		if (!self::is_target()) {
			return $parts;
		}
		$title = self::get_seo_title(get_the_ID());
		if ($title) {
			$parts['title'] = $title;
		}
		return $parts;
	}

	public static function output_head_tags() {
		if (!self::is_target()) {
			return;
		}

		$post_id = get_the_ID();

		if (!self::seo_plugin_active()) {
			$description = self::get_seo_description($post_id);
			if ($description) {
				printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
			}
			printf('<link rel="canonical" href="%s">' . "\n", esc_url(get_permalink($post_id)));
		}

		echo self::build_json_ld($post_id);
	}

	private static function seo_plugin_active() {
		return defined('WPSEO_VERSION') || class_exists('RankMath') || defined('SEOPRESS_VERSION');
	}

	private static function get_seo_title($post_id) {
		$manual = get_post_meta($post_id, '_axrel_seo_title', true);
		return $manual ?: get_post_meta($post_id, '_axrel_seo_title_auto', true);
	}

	private static function get_seo_description($post_id) {
		$manual = get_post_meta($post_id, '_axrel_seo_description', true);
		return $manual ?: get_post_meta($post_id, '_axrel_seo_description_auto', true);
	}

	private static function build_json_ld($post_id) {
		$price = get_post_meta($post_id, '_axrel_price', true);

		$data = [
			'@context'    => 'https://schema.org/',
			'@type'       => 'Product',
			'name'        => get_the_title($post_id),
			'brand'       => get_post_meta($post_id, '_axrel_brand', true),
			'sku'         => get_post_meta($post_id, '_axrel_sku', true),
			'image'       => get_the_post_thumbnail_url($post_id, 'full') ?: '',
			'description' => self::get_seo_description($post_id),
			'offers'      => [
				'@type'         => 'Offer',
				'url'           => get_permalink($post_id),
				'priceCurrency' => get_post_meta($post_id, '_axrel_currency', true) ?: 'EUR',
				'price'         => $price !== '' ? $price : '0',
				'availability'  => 'https://schema.org/' . (get_post_meta($post_id, '_axrel_availability', true) ?: 'OutOfStock'),
			],
		];

		return '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
	}
}
