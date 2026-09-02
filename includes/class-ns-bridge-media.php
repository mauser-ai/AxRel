<?php
defined('ABSPATH') || exit;

/**
 * Shared, SSRF-guarded image sideloading used by both product and
 * collection sync (extracted from NS_Bridge_Product_Sync so the security
 * allowlist has one implementation, not two that could drift apart).
 */
class NS_Bridge_Media {

	/**
	 * Sideloads an image into the media library, skipping the download if
	 * the source URL hasn't changed since the last sync (cached against
	 * $cache_meta_key on the owning object). $object_type is 'post' or
	 * 'term' — terms have no post context, so their attachments are
	 * uploaded unparented.
	 */
	public static function get_or_sideload_attachment($image_src, $object_id, $alt_text, $cache_meta_key, $object_type = 'post') {
		if (!$image_src || !self::is_allowed_image_host($image_src)) {
			if ($image_src) {
				NS_Bridge_Logger::log('image_sideload_blocked', 'Host non in allowlist: ' . $image_src);
			}
			return null;
		}

		$get_meta    = $object_type === 'term' ? 'get_term_meta' : 'get_post_meta';
		$update_meta = $object_type === 'term' ? 'update_term_meta' : 'update_post_meta';

		$stored_src           = $get_meta($object_id, $cache_meta_key, true);
		$stored_attachment_id = $get_meta($object_id, $cache_meta_key . '_attachment', true);
		if ($stored_src === $image_src && $stored_attachment_id && get_post($stored_attachment_id)) {
			return (int) $stored_attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt_text = sanitize_text_field($alt_text);
		// media_sideload_image's second arg parents the attachment to a
		// post; a term has no post context, so upload unparented (0).
		$attach_to_post_id = $object_type === 'term' ? 0 : $object_id;
		$attachment_id      = media_sideload_image($image_src, $attach_to_post_id, $alt_text, 'id');
		if (is_wp_error($attachment_id)) {
			NS_Bridge_Logger::log('image_sideload_failed', $attachment_id->get_error_message(), $image_src);
			return null;
		}

		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		$update_meta($object_id, $cache_meta_key, $image_src);
		$update_meta($object_id, $cache_meta_key . '_attachment', $attachment_id);

		return (int) $attachment_id;
	}

	/**
	 * SSRF guard: the plugin never fetches an arbitrary URL from a webhook
	 * or API payload. Shopify image URLs always come from its own CDN or
	 * the configured shop/storefront domain — anything else is refused, so
	 * a forged or malformed payload can never make this server issue a
	 * server-side request to an internal address (cloud metadata endpoints,
	 * internal admin panels, ...).
	 */
	public static function is_allowed_image_host($url) {
		$parts = wp_parse_url($url);
		if (empty($parts['host']) || empty($parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
			return false;
		}

		$host = strtolower($parts['host']);
		$allowed = array_filter([
			'cdn.shopify.com',
			strtolower((string) NS_Bridge_Settings::get('shop_domain')),
			strtolower((string) NS_Bridge_Settings::get('storefront_domain')),
		]);

		foreach ($allowed as $allowed_host) {
			if ($host === $allowed_host) {
				return true;
			}
		}

		return (bool) preg_match('/\.myshopify\.com$/', $host);
	}
}
