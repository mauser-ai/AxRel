<?php
defined('ABSPATH') || exit;

/**
 * Server-side title/meta description/canonical for Shopify-synced product
 * pages, so Google gets the tags on first render without JS. Product/Offer
 * JSON-LD is NOT generated here: WooCommerce's own WC_Structured_Data class
 * already emits it for every product page from the real price/stock/SKU we
 * sync, including AggregateOffer for variable products. Defers title/
 * description/canonical to Yoast/RankMath/SEOPress when one is active, to
 * avoid duplicate or conflicting tags.
 */
class Axrel_SEO {

	public static function register() {
		add_action('wp_head', [__CLASS__, 'output_head_tags'], 1);
		add_filter('document_title_parts', [__CLASS__, 'filter_title']);
	}

	private static function is_target() {
		return function_exists('is_product') && is_product()
			&& get_post_meta(get_the_ID(), Axrel_Product_Sync::META_SHOPIFY_ID, true) !== '';
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
		if (!self::is_target() || self::seo_plugin_active()) {
			return;
		}

		$post_id     = get_the_ID();
		$description = self::get_seo_description($post_id);

		if ($description) {
			printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
		}
		printf('<link rel="canonical" href="%s">' . "\n", esc_url(get_permalink($post_id)));
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
}
