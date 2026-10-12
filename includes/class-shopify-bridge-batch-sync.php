<?php
defined('ABSPATH') || exit;

/**
 * Resumable, step-by-step alternative to Shopify_Bridge_Reconciliation::run()
 * for anything that touches every product: the initial import, or a forced
 * full update after a plugin update ($force — re-applies products even when
 * unchanged on Shopify). The products phase does one page of up to
 * PRODUCTS_PER_STEP products per request, so no single HTTP request carries
 * unbounded work — safe against the PHP/web-server execution time (or
 * memory) limits a one-shot run over the whole catalog hits on managed
 * hosting. The categories phase is then a single step running exactly the
 * same pass as the reconciliation (Shopify_Bridge_Reconciliation::
 * sync_categories()), so both paths assign categories identically —
 * including collections built from sub-collections and the hierarchy.
 *
 * For people without SSH/WP-CLI access to `wp shopify-bridge reconcile
 * [--force]`. Day to day, products arrive via webhooks, with the daily
 * reconciliation as the safety net.
 *
 * State is a single wp_option, so progress survives closing the browser
 * tab between steps — resuming just means clicking "Elabora prossimo
 * blocco" again later.
 */
class Shopify_Bridge_Batch_Sync {

	const OPTION_KEY = 'ns_bridge_batch_state';
	const STATUSES = ['active', 'draft', 'archived'];
	// Each product now costs 2 API calls (REST upsert + GraphQL metafield
	// fetch) instead of 1, so this is lower than before to keep the same
	// per-step safety margin against execution-time limits.
	const PRODUCTS_PER_STEP = 5;

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
			'force'                  => false,
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

	/**
	 * Processes exactly one bounded step and persists progress before
	 * returning. $force only matters when this call starts a new run; a run
	 * already in progress keeps the mode it was started with.
	 */
	public static function run_next_step($force = false) {
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		if (!class_exists('WC_Product_Variable')) {
			return ['error' => 'woocommerce_missing'];
		}

		$client = new Shopify_Bridge_Shopify_Client();
		if (!$client->is_configured()) {
			return ['error' => 'not_configured'];
		}

		$state = self::get_state();

		if ($state['phase'] === 'idle' || $state['phase'] === 'done') {
			// Fresh start (a finished run left in place also starts over).
			$state = self::default_state();
			$state['phase']      = 'products';
			$state['started_at'] = current_time('mysql');
			$state['force']      = (bool) $force;
		}

		try {
			if ($state['phase'] === 'products') {
				self::step_products($client, $state);
			} elseif ($state['phase'] === 'categories') {
				self::step_categories($client, $state);
			}
		} catch (Throwable $e) {
			// Most PHP 7+ fatal errors (TypeError, calling a method on the
			// wrong thing, ...) are Throwable and catchable — surface the
			// exact cause into our own log instead of letting it take down
			// the whole request as WordPress's opaque "critical error"
			// screen. A true memory-exhaustion fatal can't be caught this
			// way and would still show that screen; if it keeps happening
			// even after this, that's the likely culprit.
			$state['errors']++;
			Shopify_Bridge_Logger::log(
				'batch_step_exception',
				sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine())
			);
		}

		update_option(self::OPTION_KEY, $state, false);

		if ($state['phase'] === 'done') {
			self::finalize($state);
		}

		return $state;
	}

	private static function step_products(Shopify_Bridge_Shopify_Client $client, array &$state) {
		if ($state['status_index'] >= count(self::STATUSES)) {
			$state['phase'] = 'categories';
			return;
		}

		$status = self::STATUSES[$state['status_index']];
		$page   = $client->list_products($status, $state['page_info'], self::PRODUCTS_PER_STEP);

		if (is_wp_error($page)) {
			$state['errors']++;
			Shopify_Bridge_Logger::log('batch_page_failed', $page->get_error_message());
			// Don't get stuck retrying the same failing page forever.
			$state['status_index']++;
			$state['page_info'] = null;
			return;
		}

		foreach ($page['products'] as $product) {
			$state['seen_ids'][] = (string) $product['id'];
			$outcome = Shopify_Bridge_Reconciliation::sync_one_product($product, $client, $state['force'], 'batch');
			$state['created_or_updated'] += $outcome['synced'] ? 1 : 0;
			$state['errors']             += $outcome['errors'];
		}

		if ($page['next_page']) {
			$state['page_info'] = $page['next_page'];
		} else {
			$state['status_index']++;
			$state['page_info'] = null;
		}
	}

	/** Single step: the same full category pass as the reconciliation — see the class docblock. */
	private static function step_categories(Shopify_Bridge_Shopify_Client $client, array &$state) {
		$category_stats       = Shopify_Bridge_Reconciliation::sync_categories($client, $state['seen_ids']);
		$state['categories']  = $category_stats['collections'];
		$state['errors']     += $category_stats['errors'];
		$state['phase']       = 'done';
	}

	private static function finalize(array $state) {
		$unpublished = Shopify_Bridge_Reconciliation::unpublish_missing_products($state['seen_ids']);

		$stats = [
			'created_or_updated' => $state['created_or_updated'],
			'unpublished'        => $unpublished,
			'categories'         => $state['categories'],
			'errors'             => $state['errors'],
			'ran_at'             => current_time('mysql'),
		];

		update_option('ns_bridge_last_reconciliation', $stats, false);
		Shopify_Bridge_Logger::log('batch_complete', wp_json_encode($stats));

		$to      = get_option('admin_email');
		$subject = sprintf('[%s] Shopify Bridge: sincronizzazione a blocchi completata', get_bloginfo('name'));
		$body    = ($state['force'] ? "Aggiornamento forzato a blocchi completato.\n\n" : "Sincronizzazione a blocchi completata.\n\n")
			. "Prodotti creati/aggiornati: {$stats['created_or_updated']}\n"
			. "Prodotti rimossi da Shopify (impostati a bozza): {$stats['unpublished']}\n"
			. "Categorie sincronizzate: {$stats['categories']}\n"
			. "Errori: {$stats['errors']}\n";

		wp_mail($to, $subject, $body);
	}
}
