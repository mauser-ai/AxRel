<?php
defined('ABSPATH') || exit;

/**
 * Resumable, click-by-click alternative to NS_Bridge_Reconciliation::run()
 * for the initial full-catalog backfill. Each step does a small, fixed
 * amount of work — one page of up to PRODUCTS_PER_STEP products during the
 * products phase, one collection during the categories phase — so a single
 * HTTP request never has unbounded work in it, safe against the PHP/web-
 * server execution time (or memory) limits that a huge one-shot
 * reconciliation can hit when nothing is cached yet. (A single collection
 * with an unusually large product membership is still fetched in one step;
 * that hasn't come up in practice, unlike the unbounded "all products" or
 * "all collections at once" cases this was built to fix.)
 *
 * Meant to be run once for the initial import (when someone doesn't have
 * SSH/WP-CLI access to run `wp ns-bridge reconcile` instead). After that,
 * new/changed products arrive in real time via webhooks, with the regular
 * daily/manual reconciliation as the ongoing safety net.
 *
 * State is a single wp_option, so progress survives closing the browser
 * tab between steps — resuming just means clicking "Elabora prossimo
 * blocco" again later.
 */
class NS_Bridge_Batch_Sync {

	const OPTION_KEY = 'ns_bridge_batch_state';
	const STATUSES = ['active', 'draft', 'archived'];
	const COLLECTION_KINDS = ['custom', 'smart'];
	const PRODUCTS_PER_STEP = 10;

	private static function default_state() {
		return [
			'phase'                  => 'idle', // idle | products | categories | done
			'status_index'           => 0,
			'page_info'              => null,
			'created_or_updated'     => 0,
			'errors'                 => 0,
			'categories'             => 0,
			'seen_ids'               => [],
			'started_at'             => null,
			// Categories phase: processes one collection per step, refilling
			// this queue a page at a time from list_custom/smart_collections.
			'collection_kind_index'  => 0,
			'collection_page_info'   => null,
			'collection_queue'       => [],
			'product_terms'          => [], // shopify_product_id => [term_id, ...]
		];
	}

	public static function get_state() {
		return array_merge(self::default_state(), get_option(self::OPTION_KEY, []));
	}

	public static function reset() {
		delete_option(self::OPTION_KEY);
	}

	public static function is_in_progress() {
		$phase = self::get_state()['phase'];
		return $phase !== 'idle' && $phase !== 'done';
	}

	/** Processes exactly one bounded step and persists progress before returning. */
	public static function run_next_step() {
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		if (!class_exists('WC_Product_Variable')) {
			return ['error' => 'woocommerce_missing'];
		}

		$client = new NS_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			return ['error' => 'not_configured'];
		}

		$state = self::get_state();

		if ($state['phase'] === 'idle') {
			$state = self::default_state();
			$state['phase']      = 'products';
			$state['started_at'] = current_time('mysql');
		} elseif ($state['phase'] === 'done') {
			// A finished run left in place; starting again begins fresh.
			$state = self::default_state();
			$state['phase']      = 'products';
			$state['started_at'] = current_time('mysql');
		}

		if ($state['phase'] === 'products') {
			self::step_products($client, $state);
		} elseif ($state['phase'] === 'categories') {
			self::step_categories($client, $state);
		}

		update_option(self::OPTION_KEY, $state, false);

		if ($state['phase'] === 'done') {
			self::finalize($state);
		}

		return $state;
	}

	private static function step_products(NS_Bridge_Shopify_Client $client, array &$state) {
		if ($state['status_index'] >= count(self::STATUSES)) {
			$state['phase'] = 'categories';
			return;
		}

		$status = self::STATUSES[$state['status_index']];
		$page   = $client->list_products($status, $state['page_info'], self::PRODUCTS_PER_STEP);

		if (is_wp_error($page)) {
			$state['errors']++;
			NS_Bridge_Logger::log('batch_page_failed', $page->get_error_message());
			// Don't get stuck retrying the same failing page forever.
			$state['status_index']++;
			$state['page_info'] = null;
			return;
		}

		foreach ($page['products'] as $product) {
			$state['seen_ids'][] = (string) $product['id'];
			$result = NS_Bridge_Product_Sync::upsert($product);
			if (is_wp_error($result)) {
				$state['errors']++;
				NS_Bridge_Logger::log('batch_upsert_failed', $result->get_error_message());
			} else {
				$state['created_or_updated']++;
			}
		}

		if ($page['next_page']) {
			$state['page_info'] = $page['next_page'];
		} else {
			$state['status_index']++;
			$state['page_info'] = null;
		}
	}

	/**
	 * One collection per step: upsert its term, fetch its (paginated)
	 * product membership, and record it into product_terms — mirroring how
	 * step_products() bounds each request to a small, fixed amount of work.
	 */
	private static function step_categories(NS_Bridge_Shopify_Client $client, array &$state) {
		if (!$state['collection_queue']) {
			if ($state['collection_kind_index'] >= count(self::COLLECTION_KINDS)) {
				// No collections left anywhere: apply everything we've mapped and finish.
				NS_Bridge_Collection_Sync::apply_product_terms($state['product_terms']);
				$state['phase'] = 'done';
				return;
			}

			$kind = self::COLLECTION_KINDS[$state['collection_kind_index']];
			$page = $kind === 'custom'
				? $client->list_custom_collections($state['collection_page_info'])
				: $client->list_smart_collections($state['collection_page_info']);

			if (is_wp_error($page)) {
				$state['errors']++;
				NS_Bridge_Logger::log('batch_collection_page_failed', $page->get_error_message());
				$state['collection_kind_index']++;
				$state['collection_page_info'] = null;
				return;
			}

			$state['collection_queue']     = $page['items'];
			$state['collection_page_info'] = $page['next_page'];
			if (!$state['collection_page_info']) {
				$state['collection_kind_index']++;
			}

			return; // Listing collections is its own step; process them starting next step.
		}

		$collection = array_shift($state['collection_queue']);

		$term_id = NS_Bridge_Collection_Sync::upsert_term($collection);
		if (is_wp_error($term_id)) {
			$state['errors']++;
			NS_Bridge_Logger::log('batch_collection_upsert_failed', $term_id->get_error_message());
			return;
		}
		$state['categories']++;

		$member_ids = NS_Bridge_Collection_Sync::collect_members($client, $collection['id']);
		if (is_wp_error($member_ids)) {
			$state['errors']++;
			NS_Bridge_Logger::log('batch_collection_members_failed', $member_ids->get_error_message());
			return;
		}
		foreach ($member_ids as $shopify_product_id) {
			$state['product_terms'][$shopify_product_id][] = $term_id;
		}
	}

	private static function finalize(array $state) {
		$unpublished = NS_Bridge_Reconciliation::unpublish_missing_products($state['seen_ids']);

		$stats = [
			'created_or_updated' => $state['created_or_updated'],
			'unpublished'        => $unpublished,
			'categories'         => $state['categories'],
			'errors'             => $state['errors'],
			'ran_at'             => current_time('mysql'),
		];

		update_option('ns_bridge_last_reconciliation', $stats, false);
		NS_Bridge_Logger::log('batch_complete', wp_json_encode($stats));

		$to      = get_option('admin_email');
		$subject = sprintf('[%s] NS Bridge: sincronizzazione iniziale a blocchi completata', get_bloginfo('name'));
		$body    = "Sincronizzazione iniziale a blocchi completata.\n\n"
			. "Prodotti creati/aggiornati: {$stats['created_or_updated']}\n"
			. "Prodotti rimossi da Shopify (impostati a bozza): {$stats['unpublished']}\n"
			. "Categorie sincronizzate: {$stats['categories']}\n"
			. "Errori: {$stats['errors']}\n";

		wp_mail($to, $subject, $body);
	}
}
