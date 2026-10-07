<?php
defined('ABSPATH') || exit;

/**
 * Imports Shopify collections (custom + smart) as WooCommerce product
 * categories, and keeps each product's category assignment in sync with
 * its Shopify collection membership. Two paths keep this current:
 * sync_all() + apply_product_terms() run as part of the reconciliation pass
 * (daily, or "Esegui riconciliazione ora") for a full-catalog pass; the
 * collections/create, collections/update and collections/delete webhooks
 * (Shopify_Bridge_Webhook_Handler) drive sync_single_collection() and
 * delete_term_for_collection() for real-time updates when a collection is
 * created, renamed, or has products manually added/removed. The one gap
 * webhooks don't cover: a smart (rule-based) collection whose membership
 * changes because a product's own attributes now match/no-longer-match its
 * rules, rather than a direct edit to the collection — Shopify doesn't fire
 * collections/update for that, so the daily reconciliation remains the
 * backstop for that specific case.
 *
 * Categories import flat (no parent/child) for now. Shopify shipped real
 * collection nesting via the Collection Sources API in July 2026, but that
 * API is GraphQL-only, requires API version 2026-07, and was still in
 * developer preview when this was written — building hierarchy detection
 * against a schema that can't be verified live risked shipping something
 * silently wrong. resolve_parent_term_id() is the seam to implement it
 * once that API has stabilized and been checked against a real store.
 */
class Shopify_Bridge_Collection_Sync {

	const TAXONOMY = 'product_cat';
	const META_SHOPIFY_ID = '_ns_bridge_shopify_collection_id';
	const META_IMAGE_SRC = '_ns_bridge_image_src';

	/**
	 * Pulls every Shopify collection (custom + smart), upserts each as a
	 * product_cat term, and collects which Shopify product IDs belong to
	 * each — the caller applies that to WooCommerce products afterwards
	 * (see apply_product_terms()), once product sync has run.
	 */
	public static function sync_all(Shopify_Bridge_Shopify_Client $client) {
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
					Shopify_Bridge_Logger::log('collection_page_failed', $page->get_error_message());
					break;
				}

				foreach ($page['items'] as $collection) {
					$term_id = self::upsert_term($collection);
					if (is_wp_error($term_id)) {
						$stats['errors']++;
						Shopify_Bridge_Logger::log('collection_upsert_failed', $term_id->get_error_message());
						continue;
					}
					$stats['collections']++;

					$member_ids = self::collect_members($client, $collection['id']);
					if (is_wp_error($member_ids)) {
						$stats['errors']++;
						Shopify_Bridge_Logger::log('collection_members_failed', $member_ids->get_error_message());
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
			$post_id = Shopify_Bridge_Product_Sync::find_post_id((string) $shopify_product_id);
			if (!$post_id) {
				continue; // Not synced (yet); the product pass runs first, but skip gracefully regardless.
			}
			wp_set_object_terms($post_id, array_values(array_unique($term_ids)), self::TAXONOMY, false);
			$applied++;
		}
		return $applied;
	}

	/**
	 * Real-time counterpart to sync_all() + apply_product_terms(), driven by
	 * the collections/create and collections/update webhooks instead of the
	 * daily reconciliation pass. Unlike apply_product_terms() (which replaces
	 * a product's entire category set from a full-catalog pass), this only
	 * adds/removes THIS ONE term on affected products — touching any other
	 * category a product belongs to would be wrong for a single-collection
	 * update. Diffs Shopify's current membership against WordPress's current
	 * one so both additions and removals are handled, not just additions.
	 */
	public static function sync_single_collection(Shopify_Bridge_Shopify_Client $client, array $collection) {
		$term_id = self::upsert_term($collection);
		if (is_wp_error($term_id)) {
			return $term_id;
		}

		$member_shopify_ids = self::collect_members($client, $collection['id']);
		if (is_wp_error($member_shopify_ids)) {
			return $member_shopify_ids;
		}

		$should_have = [];
		foreach ($member_shopify_ids as $shopify_product_id) {
			$post_id = Shopify_Bridge_Product_Sync::find_post_id($shopify_product_id);
			if ($post_id) {
				$should_have[$post_id] = true;
			}
		}

		$currently_has = get_objects_in_term($term_id, self::TAXONOMY);
		$currently_has = is_wp_error($currently_has) ? [] : array_map('intval', $currently_has);

		foreach (array_diff(array_keys($should_have), $currently_has) as $post_id) {
			wp_set_object_terms($post_id, [(int) $term_id], self::TAXONOMY, true);
		}
		foreach (array_diff($currently_has, array_keys($should_have)) as $post_id) {
			wp_remove_object_terms($post_id, (int) $term_id, self::TAXONOMY);
		}

		return $term_id;
	}

	/** Real-time counterpart for collections/delete — removes the matching term, which WordPress automatically detaches from every product it was assigned to. */
	public static function delete_term_for_collection($shopify_collection_id) {
		$term_id = self::find_term_id((string) $shopify_collection_id);
		if (!$term_id) {
			return false;
		}
		return wp_delete_term($term_id, self::TAXONOMY);
	}

	/** Public: also called one collection at a time by Shopify_Bridge_Batch_Sync. */
	public static function upsert_term(array $collection) {
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
			// WordPress blocks a second term with the same name under the
			// same parent — changing only the slug doesn't dodge that check,
			// it just fails again (or, with an insert, can still slip through
			// as a same-name "twin" term with a different slug, which is
			// exactly what was producing the duplicate-looking categories).
			// The error itself carries the id of the term that already holds
			// this name — adopt that term as this collection's mapping
			// instead of fighting WordPress for a parallel one.
			$existing_id = $result->get_error_data('term_exists');
			$result      = $existing_id
				? wp_update_term((int) $existing_id, self::TAXONOMY, array_merge(['name' => $name], $args))
				: $result;
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
		$attachment_id = Shopify_Bridge_Media::get_or_sideload_attachment($image_src, $term_id, $alt, self::META_IMAGE_SRC, 'term');
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

	/**
	 * One-off cleanup for duplicates created by the old term_exists retry
	 * logic (see upsert_term()'s history): when a Shopify collection's name
	 * collided with an existing term, that logic created a second term with
	 * `-{shopify_id}` appended to the slug instead of adopting the existing
	 * one. Finds terms whose slug matches "<base-slug>-<shopify id>" where a
	 * sibling term with exactly "<base-slug>" exists, moves every product
	 * off the suffixed duplicate onto the base term, copies the Shopify
	 * mapping across, and deletes the duplicate. Requiring 6+ digits in the
	 * suffix keeps this from ever touching an unrelated term that just
	 * happens to end in a small number (e.g. "top-10").
	 */
	public static function merge_duplicate_terms() {
		$terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
		if (is_wp_error($terms)) {
			return ['merged' => 0, 'report' => [], 'error' => $terms->get_error_message()];
		}

		$by_slug = [];
		foreach ($terms as $term) {
			$by_slug[$term->slug] = $term;
		}

		$merged = 0;
		$report = [];

		foreach ($terms as $term) {
			if (!preg_match('/^(.+)-(\d{6,})$/', $term->slug, $matches)) {
				continue;
			}
			$base_term = $by_slug[$matches[1]] ?? null;
			if (!$base_term || $base_term->term_id === $term->term_id) {
				continue;
			}

			$product_ids = get_objects_in_term($term->term_id, self::TAXONOMY);
			if (!is_wp_error($product_ids)) {
				foreach ($product_ids as $post_id) {
					wp_set_object_terms((int) $post_id, [(int) $base_term->term_id], self::TAXONOMY, true);
				}
			}

			$shopify_id = get_term_meta($term->term_id, self::META_SHOPIFY_ID, true);
			if ($shopify_id) {
				update_term_meta($base_term->term_id, self::META_SHOPIFY_ID, $shopify_id);
			}

			$report[] = "\"{$term->name}\" ({$term->slug}) unito in ({$base_term->slug})";
			wp_delete_term($term->term_id, self::TAXONOMY);
			$merged++;
		}

		return ['merged' => $merged, 'report' => $report];
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

	/** Public: also called one collection at a time by Shopify_Bridge_Batch_Sync. */
	public static function collect_members(Shopify_Bridge_Shopify_Client $client, $collection_id) {
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
