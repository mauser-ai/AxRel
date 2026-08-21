<?php
defined('ABSPATH') || exit;

/**
 * Daily safety net: pulls the full Shopify catalog and reconciles it against
 * WordPress. Catches anything a webhook missed (delivery failure, downtime,
 * a webhook that was never registered) and unpublishes products WordPress
 * still has but Shopify no longer does. Always ends with an admin report.
 */
class Axrel_Reconciliation {

	public static function run() {
		$client = new Axrel_Shopify_Client();

		if (!$client->is_configured()) {
			Axrel_Logger::log('reconciliation_skipped', 'Shopify credentials not configured');
			return ['error' => 'not_configured'];
		}

		$stats = ['created_or_updated' => 0, 'unpublished' => 0, 'errors' => 0];
		$seen_shopify_ids = [];
		$page_info = null;

		do {
			$page = $client->list_products($page_info);
			if (is_wp_error($page)) {
				$stats['errors']++;
				Axrel_Logger::log('reconciliation_page_failed', $page->get_error_message());
				break;
			}

			foreach ($page['products'] as $product) {
				$seen_shopify_ids[] = (string) $product['id'];
				$result = Axrel_Product_Sync::upsert($product);
				if (is_wp_error($result)) {
					$stats['errors']++;
					Axrel_Logger::log('reconciliation_upsert_failed', $result->get_error_message());
				} else {
					$stats['created_or_updated']++;
				}
			}

			$page_info = $page['next_page'];
		} while ($page_info);

		$stats['unpublished'] = self::unpublish_missing_products($seen_shopify_ids);
		$stats['ran_at'] = current_time('mysql');

		update_option('axrel_last_reconciliation', $stats, false);
		self::notify_admin($stats);
		Axrel_Logger::log('reconciliation_complete', wp_json_encode($stats));

		return $stats;
	}

	private static function unpublish_missing_products(array $seen_shopify_ids) {
		$published = get_posts([
			'post_type'      => Axrel_Product_Sync::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);

		$unpublished = 0;
		foreach ($published as $post_id) {
			$shopify_id = get_post_meta($post_id, Axrel_Product_Sync::META_SHOPIFY_ID, true);
			if ($shopify_id && !in_array($shopify_id, $seen_shopify_ids, true)) {
				wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
				$unpublished++;
				Axrel_Logger::log('reconciliation_unpublished', "Post {$post_id} (Shopify ID {$shopify_id}) non piu' nel catalogo Shopify");
			}
		}
		return $unpublished;
	}

	private static function notify_admin(array $stats) {
		$to      = get_option('admin_email');
		$subject = sprintf('[%s] AxRel: report sincronizzazione Shopify', get_bloginfo('name'));

		$body = "Riconciliazione giornaliera Shopify -> WordPress completata.\n\n"
			. "Prodotti creati/aggiornati: {$stats['created_or_updated']}\n"
			. "Prodotti rimossi da Shopify (impostati a bozza): {$stats['unpublished']}\n"
			. "Errori: {$stats['errors']}\n";

		if ($stats['errors'] > 0) {
			$body .= "\nATTENZIONE: alcuni prodotti non si sono sincronizzati correttamente. Controlla il log nel pannello di amministrazione.\n";
		}

		wp_mail($to, $subject, $body);
	}
}
