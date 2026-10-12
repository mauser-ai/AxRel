<?php
defined('ABSPATH') || exit;

/**
 * Daily safety net: pulls the full Shopify catalog and reconciles it against
 * WordPress. Catches anything a webhook missed (delivery failure, downtime,
 * a webhook that was never registered) and unpublishes products WordPress
 * still has but Shopify no longer does. Always ends with an admin report.
 */
class Shopify_Bridge_Reconciliation {

	/**
	 * $force: re-apply every product even when Shopify's updated_at isn't
	 * newer than what's stored. Needed once after a plugin update that changes
	 * how products are mapped (stock, tags...) — otherwise the out-of-order
	 * webhook guard in Product_Sync::upsert() skips every unchanged product
	 * and the fix never reaches them. Metafields are re-synced either way.
	 */
	public static function run($force = false) {
		// A full catalog pull (products + images + collections) can run well
		// past PHP's default execution time limit, especially the first time
		// (nothing is cached yet). This only helps when the cap is PHP's own
		// ini setting — a hard limit enforced by the web server/PHP-FPM pool
		// (common on managed hosting) can't be lifted from inside the script;
		// `wp shopify-bridge reconcile` via WP-CLI has no such constraint and is
		// the reliable path for a large catalog (see README).
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		ignore_user_abort(true);

		if (!class_exists('WC_Product_Variable')) {
			Shopify_Bridge_Logger::log('reconciliation_skipped', "WooCommerce non e' attivo");
			return ['error' => 'woocommerce_missing'];
		}

		$client = new Shopify_Bridge_Shopify_Client();

		if (!$client->is_configured()) {
			Shopify_Bridge_Logger::log('reconciliation_skipped', 'Shopify credentials not configured');
			return ['error' => 'not_configured'];
		}

		$stats = ['created_or_updated' => 0, 'unpublished' => 0, 'categories' => 0, 'errors' => 0];
		$seen_shopify_ids = [];

		// products.json has no 'any' status wildcard — only active/draft/archived
		// are recognized, so the full catalog means walking all three.
		foreach (['active', 'draft', 'archived'] as $status) {
			$page_info = null;

			do {
				$page = $client->list_products($status, $page_info);
				if (is_wp_error($page)) {
					$stats['errors']++;
					Shopify_Bridge_Logger::log('reconciliation_page_failed', $page->get_error_message());
					break;
				}

				foreach ($page['products'] as $product) {
					$seen_shopify_ids[] = (string) $product['id'];
					$outcome = self::sync_one_product($product, $client, $force, 'reconciliation');
					$stats['created_or_updated'] += $outcome['synced'] ? 1 : 0;
					$stats['errors']             += $outcome['errors'];
				}

				$page_info = $page['next_page'];
			} while ($page_info);
		}

		$stats['unpublished'] = self::unpublish_missing_products($seen_shopify_ids);

		// Categories run after products so category assignment always lands
		// on WooCommerce products that already exist.
		$category_stats       = self::sync_categories($client, $seen_shopify_ids);
		$stats['categories']  = $category_stats['collections'];
		$stats['errors']     += $category_stats['errors'];

		$stats['ran_at'] = current_time('mysql');

		update_option('ns_bridge_last_reconciliation', $stats, false);
		self::notify_admin($stats);
		Shopify_Bridge_Logger::log('reconciliation_complete', wp_json_encode($stats));

		return $stats;
	}

	/**
	 * Upsert + metafield sync for one Shopify product, shared with
	 * Shopify_Bridge_Batch_Sync. Any Throwable (TypeError, a WooCommerce
	 * exception on save...) is caught and logged with file:line, so one bad
	 * product can't take down the whole run as WordPress's opaque "critical
	 * error" page — and, in batch mode, can't leave the page cursor stuck so
	 * the auto-continue retries the same failing block forever.
	 *
	 * @return array{synced: bool, errors: int}
	 */
	public static function sync_one_product(array $product, Shopify_Bridge_Shopify_Client $client, $force, $context) {
		$errors = 0;
		try {
			$result = Shopify_Bridge_Product_Sync::upsert($product, $force);
			if (is_wp_error($result)) {
				Shopify_Bridge_Logger::log($context . '_upsert_failed', $result->get_error_message());
				return ['synced' => false, 'errors' => 1];
			}

			$mf_result = Shopify_Bridge_Metafield_Sync::sync_for_product($result, (string) $product['id'], $client);
			if (is_wp_error($mf_result)) {
				$errors++;
				Shopify_Bridge_Logger::log($context . '_metafield_sync_failed', $mf_result->get_error_message());
			}
			return ['synced' => true, 'errors' => $errors];
		} catch (Throwable $e) {
			Shopify_Bridge_Logger::log(
				$context . '_product_exception',
				sprintf('Prodotto Shopify %s: %s in %s:%d', $product['id'] ?? '?', $e->getMessage(), $e->getFile(), $e->getLine())
			);
			return ['synced' => false, 'errors' => $errors + 1];
		}
	}

	/**
	 * Full collection -> category pass (GraphQL listing, so collections built
	 * from sub-collections are included; parent/child hierarchy; replace each
	 * product's categories). Shared with Shopify_Bridge_Batch_Sync so both
	 * paths assign categories identically.
	 *
	 * $seen_shopify_ids: every product seen this run. Those in no collection
	 * at all get reset — but only when the pass is trustworthy (no errors AND
	 * it actually saw collections): a failed fetch or an empty listing (e.g. a
	 * missing API scope) looks exactly like "no collections" and would
	 * otherwise wipe correct categories.
	 *
	 * @return array{collections: int, errors: int}
	 */
	public static function sync_categories(Shopify_Bridge_Shopify_Client $client, array $seen_shopify_ids) {
		$result = Shopify_Bridge_Collection_Sync::sync_all($client);
		$stats  = $result['stats'];

		$trustworthy         = $stats['errors'] === 0 && $stats['collections'] > 0;
		$without_collections = $trustworthy
			? array_values(array_diff(array_map('strval', $seen_shopify_ids), array_map('strval', array_keys($result['product_terms']))))
			: [];
		Shopify_Bridge_Collection_Sync::apply_product_terms($result['product_terms'], $without_collections);

		return ['collections' => $stats['collections'], 'errors' => $stats['errors']];
	}

	/** Shared with Shopify_Bridge_Batch_Sync, which needs the same pass at the end of its own run. */
	public static function unpublish_missing_products(array $seen_shopify_ids) {
		// post_type=product is WooCommerce's own CPT, shared with any
		// manually-created products, so this must only ever touch posts that
		// carry our Shopify id meta.
		$published = get_posts([
			'post_type'      => Shopify_Bridge_Product_Sync::POST_TYPE,
			'post_status'    => 'publish',
			'meta_key'       => Shopify_Bridge_Product_Sync::META_SHOPIFY_ID,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);

		$unpublished = 0;
		foreach ($published as $post_id) {
			$shopify_id = get_post_meta($post_id, Shopify_Bridge_Product_Sync::META_SHOPIFY_ID, true);
			if ($shopify_id && !in_array($shopify_id, $seen_shopify_ids, true)) {
				wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
				$unpublished++;
				Shopify_Bridge_Logger::log('reconciliation_unpublished', "Post {$post_id} (Shopify ID {$shopify_id}) non piu' nel catalogo Shopify");
			}
		}
		return $unpublished;
	}

	private static function notify_admin(array $stats) {
		$to      = get_option('admin_email');
		$subject = sprintf('[%s] Shopify Bridge: report sincronizzazione Shopify', get_bloginfo('name'));

		$body = "Riconciliazione giornaliera Shopify -> WordPress completata.\n\n"
			. "Prodotti creati/aggiornati: {$stats['created_or_updated']}\n"
			. "Prodotti rimossi da Shopify (impostati a bozza): {$stats['unpublished']}\n"
			. "Categorie (collezioni Shopify) sincronizzate: {$stats['categories']}\n"
			. "Errori: {$stats['errors']}\n";

		if ($stats['errors'] > 0) {
			$body .= "\nATTENZIONE: alcuni prodotti non si sono sincronizzati correttamente. Controlla il log nel pannello di amministrazione.\n";
		}

		wp_mail($to, $subject, $body);
	}
}
