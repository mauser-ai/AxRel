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
 * Categories get parent/child structure too, from Shopify's Collection
 * Sources API (see resolve_parent_term_id()): a collection that lists other
 * collections as sources imports as the parent, each of those as its
 * WooCommerce children. Requires one extra GraphQL call per collection
 * since REST has no concept of this; that call is pinned to API version
 * 2026-07 regardless of what the site has configured elsewhere (see
 * Shopify_Bridge_Shopify_Client::get_collection_sub_collections()), so this
 * works out of the box without needing the merchant to bump their own
 * configured Admin API version.
 */
class Shopify_Bridge_Collection_Sync {

	const TAXONOMY = 'product_cat';
	const META_SHOPIFY_ID = '_ns_bridge_shopify_collection_id';
	const META_IMAGE_SRC = '_ns_bridge_image_src';

	/**
	 * Pulls every Shopify collection — via list_collections_graphql(), not
	 * the REST custom/smart listings, since a collection built from the
	 * Collection Sources model is invisible to those entirely on a
	 * pre-2026-07 request (confirmed live: it simply never appeared in a
	 * REST-driven reconciliation, so its hierarchy was never resolved even
	 * though the collection itself already existed as a term from an
	 * earlier webhook delivery) — upserts each as a product_cat term, and
	 * collects which Shopify product IDs belong to each; the caller applies
	 * that to WooCommerce products afterwards (see apply_product_terms()),
	 * once product sync has run.
	 */
	public static function sync_all(Shopify_Bridge_Shopify_Client $client) {
		$stats = ['collections' => 0, 'errors' => 0];
		$product_terms = [];
		// term_id => collection (REST shape), so hierarchy can be resolved in
		// a second pass once every collection already exists as a term — a
		// sub-collection can't be made a child of a parent that isn't synced
		// yet, and Shopify's own collection order isn't guaranteed to put
		// parents before children.
		$synced = [];
		// shopify collection id (string) => term_id, built straight from what
		// this very run just upserted. The hierarchy pass needs this lookup
		// immediately after creating 24 new terms in the same request — on a
		// host with a persistent object cache (confirmed: this store runs on
		// Kinsta, Redis object cache by default) a fresh get_terms() meta
		// query can lag behind writes from earlier in the same request, which
		// is exactly what made every single child report "not found yet" even
		// though the run's own stats said all 24 collections upserted with
		// zero errors. Reading from this in-memory map instead of hitting the
		// DB/cache again sidesteps that entirely for the one path that
		// matters most (a full reconciliation, which always has every
		// collection in hand already).
		$shopify_id_to_term_id = [];

		$cursor = null;
		do {
			$page = $client->list_collections_graphql($cursor);

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
				$synced[$term_id] = $collection;
				$shopify_id_to_term_id[(string) $collection['id']] = $term_id;

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

			$cursor = $page['next_page'];
		} while ($cursor);

		foreach ($synced as $term_id => $collection) {
			self::resolve_parent_term_id($term_id, $collection, $client, $shopify_id_to_term_id);
		}

		return ['stats' => $stats, 'product_terms' => $product_terms];
	}

	/**
	 * Applies the computed category set to every WP product we have a
	 * mapping for — replace, not append, so a collection a product was
	 * removed from on Shopify disappears here too.
	 *
	 * $products_without_collections: Shopify ids of synced products that
	 * belong to no collection at all anymore. They never appear in
	 * $product_terms, so without this they'd silently keep their old
	 * categories forever. Get reset to WooCommerce's default category. The
	 * caller must only pass these when the collection pass had no errors —
	 * a failed membership fetch looks exactly like "no collections" and
	 * would otherwise wipe correct categories.
	 */
	public static function apply_product_terms(array $product_terms, array $products_without_collections = []) {
		$applied = 0;
		foreach ($product_terms as $shopify_product_id => $term_ids) {
			$post_id = Shopify_Bridge_Product_Sync::find_post_id((string) $shopify_product_id);
			if (!$post_id) {
				continue; // Not synced (yet); the product pass runs first, but skip gracefully regardless.
			}
			wp_set_object_terms($post_id, array_values(array_unique($term_ids)), self::TAXONOMY, false);
			$applied++;
		}

		$default_term = (int) get_option('default_product_cat', 0);
		foreach ($products_without_collections as $shopify_product_id) {
			$post_id = Shopify_Bridge_Product_Sync::find_post_id((string) $shopify_product_id);
			if (!$post_id) {
				continue;
			}
			wp_set_object_terms($post_id, $default_term ? [$default_term] : [], self::TAXONOMY, false);
			$applied++;
		}

		self::recount_terms();
		return $applied;
	}

	/**
	 * wp_set_object_terms() clears a product_cat term's cached product count
	 * but doesn't recompute it — WooCommerce replaces the default WordPress
	 * count callback with its own (_wc_term_recount, which also factors in
	 * stock/visibility), and that only runs through the normal product-save
	 * flow, not when terms are assigned programmatically like here. Without
	 * this, every category assigned by this class would show "0 products" in
	 * wp-admin even though the assignment itself is correct.
	 *
	 * _wc_term_recount() writes the new count straight to wp_term_taxonomy via
	 * $wpdb, bypassing wp_update_term() — the function WordPress normally
	 * relies on to also invalidate the term object cache. On a host with a
	 * persistent object cache (this store: Kinsta, Redis) the term objects
	 * already cached earlier in this same request (e.g. by upsert_term()
	 * creating/updating them) keep their stale pre-recount count — usually 0
	 * — until something else happens to evict them, which is exactly the
	 * "collegati ma il count resta a 0" symptom reported against this store.
	 * clean_term_cache() forces every subsequent get_terms()/get_term() read,
	 * in this request and after, to hit the DB and see the real number.
	 */
	private static function recount_terms() {
		if (!function_exists('wc_recount_all_terms')) {
			return;
		}
		wc_recount_all_terms();

		$term_ids = get_terms([
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'ids',
		]);
		if (!is_wp_error($term_ids) && $term_ids) {
			clean_term_cache($term_ids, self::TAXONOMY);
		}
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
		self::resolve_parent_term_id($term_id, $collection, $client);

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

		self::recount_terms();
		return $term_id;
	}

	/** Real-time counterpart for collections/delete — removes the matching term, which WordPress automatically detaches from every product it was assigned to. */
	public static function delete_term_for_collection($shopify_collection_id) {
		$term_id = self::find_term_id((string) $shopify_collection_id);
		if (!$term_id) {
			return false;
		}
		$result = wp_delete_term($term_id, self::TAXONOMY);
		self::recount_terms();
		return $result;
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
	 * A Shopify collection can build its membership from other collections
	 * (Collection Sources API: Collection.sources returns a
	 * CollectionSubCollectionsSource with a `collections` list) — confirmed
	 * against a live store, since Shopify's own docs disagreed with
	 * themselves on the exact type/field names at the time this was
	 * written. Each sub-collection listed there becomes a WordPress child
	 * category of the collection being resolved here. Requires an extra
	 * GraphQL call per collection (REST has no concept of this at all),
	 * pinned to API version 2026-07 regardless of what's configured
	 * elsewhere (see get_collection_sub_collections()) — a GraphQL error
	 * here is logged and simply means no hierarchy for that collection, not
	 * a sync failure.
	 *
	 * Best-effort on ordering too: a sub-collection not yet synced as a term
	 * is skipped rather than created early (it has no title/slug/image to
	 * create it correctly with here) — sync_all() calls this in a dedicated
	 * second pass after every collection already exists as a term specifically
	 * to avoid that; the real-time webhook path (sync_single_collection) can't
	 * make that guarantee, but self-corrects at the next reconciliation.
	 *
	 * $known_term_ids is an optional shopify_id(string) => term_id map built
	 * by the caller from what it already upserted this run — checked before
	 * falling back to the DB (find_term_id()'s get_terms() meta query), since
	 * that lookup can lag behind writes from earlier in the very same
	 * request on a host with a persistent object cache. sync_all() always
	 * passes this; sync_single_collection() and batch sync don't have a full
	 * map available and fall back to the DB lookup (best-effort there
	 * already, per the paragraph above).
	 */
	public static function resolve_parent_term_id($term_id, array $collection, Shopify_Bridge_Shopify_Client $client, array $known_term_ids = []) {
		if (empty($collection['id'])) {
			return;
		}
		$gid        = 'gid://shopify/Collection/' . $collection['id'];
		$child_gids = $client->get_collection_sub_collections($gid);
		if (is_wp_error($child_gids)) {
			Shopify_Bridge_Logger::log('collection_hierarchy_failed', "Collezione {$collection['id']} ({$collection['title']}): " . $child_gids->get_error_message());
			return;
		}
		if (!$child_gids) {
			return; // No sub-collection sources on this one — not an error, most collections are leaves.
		}

		foreach ($child_gids as $child_gid) {
			$child_shopify_id = self::gid_to_numeric_id($child_gid);
			$child_term_id    = $child_shopify_id ? ($known_term_ids[$child_shopify_id] ?? self::find_term_id($child_shopify_id)) : null;

			if (!$child_term_id) {
				Shopify_Bridge_Logger::log(
					'collection_hierarchy_child_not_found',
					"Sotto-collezione {$child_gid} di \"{$collection['title']}\" (id {$collection['id']}) non ancora sincronizzata come categoria — verra' collegata alla prossima riconciliazione."
				);
				continue;
			}
			if ((int) $child_term_id === (int) $term_id) {
				continue;
			}

			$result = wp_update_term((int) $child_term_id, self::TAXONOMY, ['parent' => (int) $term_id]);
			if (is_wp_error($result)) {
				Shopify_Bridge_Logger::log(
					'collection_hierarchy_parent_set_failed',
					"Impossibile impostare la categoria {$child_term_id} come figlia di {$term_id} (\"{$collection['title']}\"): " . $result->get_error_message()
				);
			}
		}
	}

	private static function gid_to_numeric_id($gid) {
		return preg_match('/(\d+)$/', (string) $gid, $m) ? $m[1] : null;
	}

	/**
	 * Cleanup for duplicate categories, two generations of them:
	 *
	 * 1. Same Shopify collection mapped to several terms (bath, bath-2,
	 *    bath-3...) — produced by find_term_id() missing the existing term
	 *    (see its docblock). Every copy carries the same Shopify id meta, so
	 *    they're grouped by it: the lowest term_id (the original) is kept.
	 * 2. Older "<base-slug>-<shopify id>" twins from the previous term_exists
	 *    retry logic, where the base term may not carry the meta yet. 6+
	 *    digits so an unrelated slug like "top-10" is never touched.
	 *
	 * For each duplicate: its products and its child categories move onto
	 * the kept term, then it's deleted. Idempotent — with nothing to merge
	 * it changes nothing. Parents/children are re-checked by the next sync.
	 */
	public static function merge_duplicate_terms() {
		global $wpdb;

		$merged = 0;
		$report = [];

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT tm.term_id, tm.meta_value AS shopify_id FROM {$wpdb->termmeta} tm
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
			WHERE tm.meta_key = %s AND tm.meta_value <> ''
			ORDER BY tm.term_id ASC",
			self::TAXONOMY,
			self::META_SHOPIFY_ID
		));
		$by_shopify_id = [];
		foreach ($rows as $row) {
			$by_shopify_id[$row->shopify_id][] = (int) $row->term_id;
		}
		foreach ($by_shopify_id as $term_ids) {
			$keep_id = array_shift($term_ids); // lowest term_id = the original
			foreach (array_unique($term_ids) as $duplicate_id) {
				if ($duplicate_id === $keep_id) {
					continue;
				}
				$report[] = self::merge_term_into($duplicate_id, $keep_id);
				$merged++;
			}
		}

		$terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'term_id']);
		if (!is_wp_error($terms)) {
			$by_slug = [];
			foreach ($terms as $term) {
				$by_slug[$term->slug] = $term;
			}
			foreach ($terms as $term) {
				if (!preg_match('/^(.+)-(\d{6,})$/', $term->slug, $matches)) {
					continue;
				}
				$base_term = $by_slug[$matches[1]] ?? null;
				if (!$base_term || $base_term->term_id === $term->term_id) {
					continue;
				}
				$report[] = self::merge_term_into((int) $term->term_id, (int) $base_term->term_id);
				$merged++;
			}
		}

		if ($merged) {
			self::recount_terms();
		}

		return ['merged' => $merged, 'report' => $report];
	}

	/** Moves products, child categories and the Shopify mapping from $from_id onto $into_id, then deletes $from_id. Returns a one-line report. */
	private static function merge_term_into($from_id, $into_id) {
		$from = get_term($from_id, self::TAXONOMY);
		$into = get_term($into_id, self::TAXONOMY);
		if (!$from || is_wp_error($from) || !$into || is_wp_error($into)) {
			return "Categoria {$from_id} non trovata, saltata";
		}

		$product_ids = get_objects_in_term($from_id, self::TAXONOMY);
		if (!is_wp_error($product_ids)) {
			foreach ($product_ids as $post_id) {
				wp_set_object_terms((int) $post_id, [$into_id], self::TAXONOMY, true);
			}
		}

		$children = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'parent' => $from_id, 'fields' => 'ids']);
		if (!is_wp_error($children)) {
			foreach ($children as $child_id) {
				if ((int) $child_id !== $into_id) {
					wp_update_term((int) $child_id, self::TAXONOMY, ['parent' => $into_id]);
				}
			}
		}

		$shopify_id = get_term_meta($from_id, self::META_SHOPIFY_ID, true);
		if ($shopify_id && !get_term_meta($into_id, self::META_SHOPIFY_ID, true)) {
			update_term_meta($into_id, self::META_SHOPIFY_ID, $shopify_id);
		}

		wp_delete_term($from_id, self::TAXONOMY);
		return "\"{$from->name}\" ({$from->slug}) unito in \"{$into->name}\" ({$into->slug})";
	}

	/**
	 * Direct SQL on purpose, not get_terms(): for product_cat, WooCommerce's
	 * pre_get_terms hook forces a menu_order sort by rewriting the meta query
	 * (older versions overwrite meta_key with 'order' outright), and on a
	 * persistent object cache the term query result can also be stale. Either
	 * way this lookup came back empty for categories that existed, so
	 * upsert_term() created the collection again — and since sub-categories
	 * sit under a parent, WordPress's same-name check at the top level never
	 * caught it: bath, bath-2, bath-3... one more per sync. Lowest term_id
	 * wins, so with leftover duplicates the original is always the one used.
	 */
	private static function find_term_id($shopify_id) {
		global $wpdb;
		$term_id = $wpdb->get_var($wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
			WHERE tm.meta_key = %s AND tm.meta_value = %s
			ORDER BY tm.term_id ASC LIMIT 1",
			self::TAXONOMY,
			self::META_SHOPIFY_ID,
			(string) $shopify_id
		));
		return $term_id ? (int) $term_id : null;
	}

	/** Public: also called one collection at a time by Shopify_Bridge_Batch_Sync. */
	public static function collect_members(Shopify_Bridge_Shopify_Client $client, $collection_id) {
		$ids    = [];
		$cursor = null;
		$gid    = 'gid://shopify/Collection/' . $collection_id;

		do {
			$page = $client->list_collection_products($gid, $cursor);
			if (is_wp_error($page)) {
				return $page;
			}
			foreach ($page['items'] as $product) {
				if (!empty($product['id'])) {
					$ids[] = (string) $product['id'];
				}
			}
			$cursor = $page['next_page'];
		} while ($cursor);

		return $ids;
	}
}
