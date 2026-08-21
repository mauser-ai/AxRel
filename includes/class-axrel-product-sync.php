<?php
defined('ABSPATH') || exit;

/**
 * Maps a Shopify product payload (from a webhook or from the reconciliation
 * pull) onto the axrel_product custom post type. Idempotent by design: safe
 * to call repeatedly with the same or a redelivered payload.
 */
class Axrel_Product_Sync {

	const POST_TYPE = 'axrel_product';
	const META_SHOPIFY_ID = '_axrel_shopify_id';
	const META_UPDATED_AT = '_axrel_shopify_updated_at';

	public static function upsert(array $product) {
		if (empty($product['id'])) {
			return new WP_Error('axrel_invalid_product', 'Missing Shopify product id');
		}

		$shopify_id  = (string) $product['id'];
		$existing_id = self::find_post_id($shopify_id);

		// Shopify webhooks can be delivered out of order or redelivered.
		// Never let an older payload clobber data from a newer one.
		if ($existing_id) {
			$stored_updated_at = get_post_meta($existing_id, self::META_UPDATED_AT, true);
			if ($stored_updated_at && !empty($product['updated_at'])
				&& strtotime($product['updated_at']) <= strtotime($stored_updated_at)) {
				return $existing_id;
			}
		}

		$variant  = $product['variants'][0] ?? [];
		$price    = isset($variant['price']) ? (float) $variant['price'] : null;
		$in_stock = self::is_in_stock($product);
		$status   = ($product['status'] ?? 'active') === 'active' ? 'publish' : 'draft';

		$postarr = [
			'post_type'    => self::POST_TYPE,
			'post_title'   => $product['title'] ?? '',
			'post_name'    => $product['handle'] ?? sanitize_title($product['title'] ?? $shopify_id),
			'post_content' => $product['body_html'] ?? '',
			'post_status'  => $status,
		];

		if ($existing_id) {
			$postarr['ID'] = $existing_id;
			$post_id = wp_update_post($postarr, true);
		} else {
			$post_id = wp_insert_post($postarr, true);
		}

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, self::META_SHOPIFY_ID, $shopify_id);
		update_post_meta($post_id, self::META_UPDATED_AT, $product['updated_at'] ?? current_time('mysql'));
		update_post_meta($post_id, '_axrel_price', $price);
		update_post_meta($post_id, '_axrel_currency', $product['currency'] ?? Axrel_Settings::get('default_currency'));
		update_post_meta($post_id, '_axrel_sku', $variant['sku'] ?? '');
		update_post_meta($post_id, '_axrel_brand', $product['vendor'] ?? '');
		update_post_meta($post_id, '_axrel_availability', $in_stock ? 'InStock' : 'OutOfStock');
		update_post_meta($post_id, '_axrel_checkout_url', self::build_checkout_url($product['handle'] ?? ''));

		self::maybe_generate_seo_meta($post_id, $product);
		self::sync_featured_image($post_id, $product);

		return $post_id;
	}

	public static function delete($shopify_id) {
		$post_id = self::find_post_id($shopify_id);
		if (!$post_id) {
			return;
		}
		// Soft delete: unpublish rather than trash, so nothing is lost if the
		// product reappears on Shopify (e.g. a mistaken archive/unarchive).
		wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
	}

	public static function find_post_id($shopify_id) {
		$posts = get_posts([
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'meta_key'       => self::META_SHOPIFY_ID,
			'meta_value'     => $shopify_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		]);
		return $posts ? (int) $posts[0] : null;
	}

	private static function is_in_stock(array $product) {
		foreach ($product['variants'] ?? [] as $variant) {
			if (($variant['inventory_quantity'] ?? 0) > 0 || ($variant['inventory_policy'] ?? '') === 'continue') {
				return true;
			}
		}
		return false;
	}

	private static function build_checkout_url($handle) {
		$storefront_domain = Axrel_Settings::get('storefront_domain') ?: Axrel_Settings::get('shop_domain');
		return "https://{$storefront_domain}/products/{$handle}";
	}

	/**
	 * Auto-generates the SEO title/description from Shopify data into
	 * "_auto" meta keys, kept separate from _axrel_seo_title/_description so
	 * an editor's manual override in WP is never overwritten by the next sync.
	 */
	private static function maybe_generate_seo_meta($post_id, array $product) {
		update_post_meta($post_id, '_axrel_seo_title_auto', ($product['title'] ?? '') . ' | ' . get_bloginfo('name'));
		$excerpt = wp_trim_words(wp_strip_all_tags($product['body_html'] ?? ''), 30, '...');
		update_post_meta($post_id, '_axrel_seo_description_auto', $excerpt);
	}

	private static function sync_featured_image($post_id, array $product) {
		$image_src = $product['image']['src'] ?? ($product['images'][0]['src'] ?? '');
		if (!$image_src) {
			return;
		}

		$stored_src = get_post_meta($post_id, '_axrel_image_src', true);
		if ($stored_src === $image_src && has_post_thumbnail($post_id)) {
			return; // Image unchanged, skip the re-download.
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt_text      = $product['image']['alt'] ?? ($product['title'] ?? '');
		$attachment_id = media_sideload_image($image_src, $post_id, $alt_text, 'id');

		if (is_wp_error($attachment_id)) {
			Axrel_Logger::log('image_sideload_failed', $attachment_id->get_error_message(), $image_src);
			return;
		}

		set_post_thumbnail($post_id, $attachment_id);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		update_post_meta($post_id, '_axrel_image_src', $image_src);
	}
}
