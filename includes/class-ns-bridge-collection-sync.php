<?php
defined('ABSPATH') || exit;

/**
 * Imports Shopify collections (custom + smart) as WooCommerce product
 * categories, and keeps each product's category assignment in sync with
 * its Shopify collection membership. Runs as part of the reconciliation
 * pass (daily, or "Esegui riconciliazione ora") rather than via webhook:
 * adding/removing a product from a collection doesn't trigger a
 * products/update webhook on Shopify's side, so real-time category sync
 * would need its own webhook subscriptions (collections/create|update|
 * delete) — not implemented yet, see README.
 *
 * Categories import flat (no parent/child) for now. Shopify shipped real
 * collection nesting via the Collection Sources API in July 2026, but that
 * API is GraphQL-only, requires API version 2026-07, and was still in
 * developer preview when this was written — building hierarchy detection
 * against a schema that can't be verified live risked shipping something
 * silently wrong. resolve_parent_term_id() is the seam to implement it
 * once that API has stabilized and been checked against a real store.
 */
class NS_Bridge_Collection_Sync {

	const TAXONOMY = 'product_cat';
	const META_SHOPIFY_ID = '_ns_bridge_shopify_collection_id';
	const META_IMAGE_SRC = '_ns_bridge_image_src';

	/**
	 * Pulls every Shopify collection (custom + smart), upserts each as a
	 * product_cat term, and collects which Shopify product IDs belong to
	 * each — the caller applies that to WooCommerce products afterwards
	 * (see apply_product_terms()), once product sync has run.
	 */
	public static function sync_all(NS_Bridge_Shopify_Client $client) {
		$stats = ['collections' => 0, 'errors' => 0];
		$product_terms = [];

		foreach (['custom', 'smart'] as $kind) {
			$page_info = null;

			do {
				$page = $kind === 'custom'
					? $client->list_custom_collections($page_info)
					: $client->list_smart_collections($page_info);

				if (is_wp_error($page)) {
					$stats['errors']++;
					NS_Bridge_Logger::log('collection_page_failed', $page->get_error_message());
					break;
				}

				foreach ($page['items'] as $collection) {
					$term_id = self::upsert_term($collection);
					if (is_wp_error($term_id)) {
						$stats['errors']++;
						NS_Bridge_Logger::log('collection_upsert_failed', $term_id->get_error_message());
						continue;
					}
					$stats['collections']++;

					$member_ids = self::collect_members($client, $collection['id']);
					if (is_wp_error($member_ids)) {
						$stats['errors']++;
						NS_Bridge_Logger::log('collection_members_failed', $member_ids->get_error_message());
						continue;
					}
					foreach ($member_ids as $shopify_product_id) {
						$product_terms[$shopify_product_id][] = $term_id;
					}
				}

				$page_info = $page['next_page'];
			} while ($page_info);
		}

		return ['stats' => $stats, 'product_terms' => $product_terms];
	}

	/**
	 * Applies the computed category set to every WP product we have a
	 * mapping for (replace, not append — a product removed from all its
	 * Shopify collections correctly loses those WooCommerce categories too).
	 */
	public static function apply_product_terms(array $product_terms) {
		$applied = 0;
		foreach ($product_terms as $shopify_product_id => $term_ids) {
			$post_id = NS_Bridge_Product_Sync::find_post_id((string) $shopify_product_id);
			if (!$post_id) {
				continue; // Not synced (yet); the product pass runs first, but skip gracefully regardless.
			}
			wp_set_object_terms($post_id, array_values(array_unique($term_ids)), self::TAXONOMY, false);
			$applied++;
		}
		return $applied;
	}

	private static function upsert_term(array $collection) {
		$shopify_id = (string) $collection['id'];
		$term_id    = self::find_term_id($shopify_id);
		$name       = sanitize_text_field($collection['title'] ?? '');

		$args = [
			'description' => wp_kses_post($collection['body_html'] ?? ''),
			'slug'        => sanitize_title($collection['handle'] ?? ($collection['title'] ?? $shopify_id)),
		];

		$result = $term_id
			? wp_update_term($term_id, self::TAXONOMY, array_merge(['name' => $name], $args))
			: wp_insert_term($name, self::TAXONOMY, $args);

		if (is_wp_error($result) && $result->get_error_code() === 'term_exists') {
			// Slug/name collision with an unrelated term — retry once with
			// the Shopify id appended so re-syncing stays idempotent.
			$args['slug'] .= '-' . $shopify_id;
			$result = $term_id
				? wp_update_term($term_id, self::TAXONOMY, array_merge(['name' => $name], $args))
				: wp_insert_term($name, self::TAXONOMY, $args);
		}

		if (is_wp_error($result)) {
			return $result;
		}

		$term_id = $result['term_id'];
		update_term_meta($term_id, self::META_SHOPIFY_ID, $shopify_id);

		self::sync_term_image($term_id, $collection);
		self::resolve_parent_term_id($term_id, $collection);

		return $term_id;
	}

	private static function sync_term_image($term_id, array $collection) {
		$image_src = $collection['image']['src'] ?? '';
		if (!$image_src) {
			return;
		}
		$alt = $collection['image']['alt'] ?? ($collection['title'] ?? '');
		$attachment_id = NS_Bridge_Media::get_or_sideload_attachment($image_src, $term_id, $alt, self::META_IMAGE_SRC, 'term');
		if ($attachment_id) {
			// 'thumbnail_id' is WooCommerce's own category-thumbnail term meta key.
			update_term_meta($term_id, 'thumbnail_id', $attachment_id);
		}
	}

	/**
	 * Not implemented yet — every collection currently imports as a
	 * top-level category. See the class docblock for why (Collection
	 * Sources API still in developer preview, schema unverifiable here).
	 */
	private static function resolve_parent_term_id($term_id, array $collection) {
		// Intentionally a no-op for now.
	}

	private static function find_term_id($shopify_id) {
		$terms = get_terms([
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'meta_key'   => self::META_SHOPIFY_ID,
			'meta_value' => $shopify_id,
			'number'     => 1,
			'fields'     => 'ids',
		]);
		return ($terms && !is_wp_error($terms)) ? (int) $terms[0] : null;
	}

	private static function collect_members(NS_Bridge_Shopify_Client $client, $collection_id) {
		$ids = [];
		$page_info = null;

		do {
			$page = $client->list_collection_products($collection_id, $page_info);
			if (is_wp_error($page)) {
				return $page;
			}
			foreach ($page['items'] as $product) {
				if (!empty($product['id'])) {
					$ids[] = (string) $product['id'];
				}
			}
			$page_info = $page['next_page'];
		} while ($page_info);

		return $ids;
	}
}
