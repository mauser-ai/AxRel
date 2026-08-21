<?php
defined('ABSPATH') || exit;

/**
 * Maps a Shopify product payload (from a webhook or from the reconciliation
 * pull) onto a real WooCommerce product: WC_Product_Simple for a single
 * variant, WC_Product_Variable + WC_Product_Variation children when Shopify
 * reports real options (colore, ml, ...). WooCommerce owns the product/
 * variant admin UI and emits its own Product/Offer structured data; nothing
 * here ever adds to a WooCommerce cart — see class-axrel-frontend.php.
 *
 * Idempotent by design: safe to call repeatedly with the same or a
 * redelivered payload.
 */
class Axrel_Product_Sync {

	const POST_TYPE = 'product';
	const META_SHOPIFY_ID = '_axrel_shopify_id';
	const META_SHOPIFY_VARIANT_ID = '_axrel_shopify_variant_id';
	const META_UPDATED_AT = '_axrel_shopify_updated_at';

	public static function upsert(array $product) {
		if (!class_exists('WC_Product_Variable')) {
			return new WP_Error('axrel_woocommerce_missing', "WooCommerce non e' attivo: impossibile sincronizzare i prodotti.");
		}
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

		$is_variable = self::has_real_options($product);
		$wc_product  = self::get_or_create_wc_product($existing_id, $is_variable);
		if (is_wp_error($wc_product)) {
			return $wc_product;
		}

		$wc_product->set_name($product['title'] ?? '');
		$wc_product->set_description($product['body_html'] ?? '');
		$wc_product->set_slug($product['handle'] ?? sanitize_title($product['title'] ?? $shopify_id));
		$wc_product->set_status(($product['status'] ?? 'active') === 'active' ? 'publish' : 'draft');
		$wc_product->set_catalog_visibility('visible');
		$post_id = $wc_product->save();

		if (!$post_id) {
			return new WP_Error('axrel_wc_save_failed', 'Salvataggio prodotto WooCommerce fallito');
		}

		update_post_meta($post_id, self::META_SHOPIFY_ID, $shopify_id);
		update_post_meta($post_id, self::META_UPDATED_AT, $product['updated_at'] ?? current_time('mysql'));
		update_post_meta($post_id, '_axrel_brand', $product['vendor'] ?? '');

		self::maybe_generate_seo_meta($post_id, $product);
		self::sync_images($wc_product, $product);

		if ($is_variable) {
			self::sync_attributes($wc_product, $product);
			self::sync_variations($wc_product, $product);
			WC_Product_Variable::sync($post_id);
		} else {
			self::sync_simple_pricing($wc_product, $product);
		}

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

	public static function find_variation_post_id($shopify_variant_id) {
		$posts = get_posts([
			'post_type'      => 'product_variation',
			'post_status'    => 'any',
			'meta_key'       => self::META_SHOPIFY_VARIANT_ID,
			'meta_value'     => $shopify_variant_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		]);
		return $posts ? (int) $posts[0] : null;
	}

	/**
	 * Shopify always reports at least one option ("Title"/"Default Title")
	 * even for single-variant products, so a real, named option (or more
	 * than one variant) is what actually means "this needs a variant picker".
	 */
	private static function has_real_options(array $product) {
		foreach ($product['options'] ?? [] as $option) {
			if (($option['name'] ?? '') !== 'Title') {
				return true;
			}
		}
		return count($product['variants'] ?? []) > 1;
	}

	private static function get_or_create_wc_product($existing_id, $is_variable) {
		$target_type = $is_variable ? 'variable' : 'simple';

		if (!$existing_id) {
			return $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		$current = wc_get_product($existing_id);
		if (!$current) {
			return new WP_Error('axrel_wc_product_missing', "Prodotto WooCommerce {$existing_id} non trovato");
		}

		if ($current->get_type() !== $target_type) {
			// Product type changed on Shopify (e.g. a single item grew variants).
			// Drop any existing variations before switching type.
			if ($current->get_type() === 'variable') {
				self::delete_stale_variations($existing_id, []);
			}
			wp_set_object_terms($existing_id, $target_type, 'product_type');
			$current = $is_variable ? new WC_Product_Variable($existing_id) : new WC_Product_Simple($existing_id);
		}

		return $current;
	}

	private static function sync_attributes($wc_product, array $product) {
		$attributes = [];

		foreach (array_values($product['options'] ?? []) as $position => $option) {
			if (($option['name'] ?? '') === 'Title') {
				continue;
			}

			$values = array_values(array_unique(array_filter(array_map(
				function ($variant) use ($position) {
					return $variant['option' . ($position + 1)] ?? '';
				},
				$product['variants'] ?? []
			))));

			if (!$values) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id(0);
			$attribute->set_name($option['name']);
			$attribute->set_options($values);
			$attribute->set_position($position);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$attributes[] = $attribute;
		}

		$wc_product->set_attributes($attributes);
		$wc_product->save();
	}

	private static function sync_variations($wc_product, array $product) {
		$post_id             = $wc_product->get_id();
		$options             = array_values($product['options'] ?? []);
		$seen_variant_ids    = [];

		foreach ($product['variants'] ?? [] as $variant) {
			if (empty($variant['id'])) {
				continue;
			}
			$shopify_variant_id = (string) $variant['id'];
			$seen_variant_ids[] = $shopify_variant_id;

			$variation_post_id = self::find_variation_post_id($shopify_variant_id);
			$variation = $variation_post_id ? new WC_Product_Variation($variation_post_id) : new WC_Product_Variation();
			$variation->set_parent_id($post_id);

			$attributes = [];
			foreach ($options as $index => $option) {
				$value = $variant['option' . ($index + 1)] ?? '';
				if ($value !== '' && ($option['name'] ?? '') !== 'Title') {
					$attributes[sanitize_title($option['name'])] = $value;
				}
			}
			$variation->set_attributes($attributes);

			$variation->set_regular_price(isset($variant['price']) ? (string) $variant['price'] : '');
			$variation->set_sku($variant['sku'] ?? '');
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity(max(0, (int) ($variant['inventory_quantity'] ?? 0)));
			$variation->set_stock_status(self::is_variant_in_stock($variant) ? 'instock' : 'outofstock');
			$variation->set_status('publish');

			$variation_id = $variation->save();
			update_post_meta($variation_id, self::META_SHOPIFY_VARIANT_ID, $shopify_variant_id);

			$variant_image_src = self::find_variant_image_src($product, $variant);
			if ($variant_image_src) {
				$attachment_id = self::get_or_sideload_attachment(
					$variant_image_src,
					$variation_id,
					$variant['sku'] ?? ($product['title'] ?? ''),
					'_axrel_variant_image_src'
				);
				if ($attachment_id) {
					$variation = new WC_Product_Variation($variation_id);
					$variation->set_image_id($attachment_id);
					$variation->save();
				}
			}
		}

		self::delete_stale_variations($post_id, $seen_variant_ids);
	}

	private static function delete_stale_variations($post_id, array $seen_variant_ids) {
		$existing = get_posts([
			'post_type'      => 'product_variation',
			'post_parent'    => $post_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);

		foreach ($existing as $variation_id) {
			$shopify_variant_id = get_post_meta($variation_id, self::META_SHOPIFY_VARIANT_ID, true);
			if (!$shopify_variant_id || !in_array($shopify_variant_id, $seen_variant_ids, true)) {
				wp_delete_post($variation_id, true);
			}
		}
	}

	private static function sync_simple_pricing($wc_product, array $product) {
		$variant = $product['variants'][0] ?? [];

		$wc_product->set_regular_price(isset($variant['price']) ? (string) $variant['price'] : '');
		$wc_product->set_sku($variant['sku'] ?? '');
		$wc_product->set_manage_stock(true);
		$wc_product->set_stock_quantity(max(0, (int) ($variant['inventory_quantity'] ?? 0)));
		$wc_product->set_stock_status(self::is_variant_in_stock($variant) ? 'instock' : 'outofstock');
		$wc_product->save();

		if (!empty($variant['id'])) {
			update_post_meta($wc_product->get_id(), self::META_SHOPIFY_VARIANT_ID, (string) $variant['id']);
		}
	}

	private static function is_variant_in_stock(array $variant) {
		return ($variant['inventory_quantity'] ?? 0) > 0 || ($variant['inventory_policy'] ?? '') === 'continue';
	}

	private static function find_variant_image_src(array $product, array $variant) {
		if (empty($variant['image_id'])) {
			return '';
		}
		foreach ($product['images'] ?? [] as $image) {
			if ((string) ($image['id'] ?? '') === (string) $variant['image_id']) {
				return $image['src'] ?? '';
			}
		}
		return '';
	}

	private static function sync_images($wc_product, array $product) {
		$images = $product['images'] ?? [];
		if (!$images && !empty($product['image']['src'])) {
			$images = [$product['image']];
		}
		if (!$images) {
			return;
		}

		$post_id       = $wc_product->get_id();
		$alt_text      = $product['title'] ?? '';
		$attachment_ids = [];

		foreach (array_values($images) as $index => $image) {
			$attachment_id = self::get_or_sideload_attachment(
				$image['src'] ?? '',
				$post_id,
				$image['alt'] ?? $alt_text,
				'_axrel_image_src_' . $index
			);
			if ($attachment_id) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if (!$attachment_ids) {
			return;
		}

		$wc_product->set_image_id($attachment_ids[0]);
		$wc_product->set_gallery_image_ids(array_slice($attachment_ids, 1));
		$wc_product->save();
	}

	/**
	 * Sideloads an image into the media library, skipping the download if
	 * the source URL hasn't changed since the last sync (cached against
	 * $cache_meta_key on $parent_post_id).
	 */
	private static function get_or_sideload_attachment($image_src, $parent_post_id, $alt_text, $cache_meta_key) {
		if (!$image_src) {
			return null;
		}

		$stored_src           = get_post_meta($parent_post_id, $cache_meta_key, true);
		$stored_attachment_id = get_post_meta($parent_post_id, $cache_meta_key . '_attachment', true);
		if ($stored_src === $image_src && $stored_attachment_id && get_post($stored_attachment_id)) {
			return (int) $stored_attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image($image_src, $parent_post_id, $alt_text, 'id');
		if (is_wp_error($attachment_id)) {
			Axrel_Logger::log('image_sideload_failed', $attachment_id->get_error_message(), $image_src);
			return null;
		}

		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		update_post_meta($parent_post_id, $cache_meta_key, $image_src);
		update_post_meta($parent_post_id, $cache_meta_key . '_attachment', $attachment_id);

		return (int) $attachment_id;
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
}
