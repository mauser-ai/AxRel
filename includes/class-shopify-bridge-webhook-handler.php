<?php
defined('ABSPATH') || exit;

/**
 * Receives Shopify's real-time webhooks (products/create|update|delete) at
 * /wp-json/ns-bridge/v1/webhook. Auth is HMAC (per Shopify's webhook
 * spec), not WP REST auth, so the route is public and validated manually.
 */
class Shopify_Bridge_Webhook_Handler {

	public static function register_routes() {
		register_rest_route('ns-bridge/v1', '/webhook', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function handle(WP_REST_Request $request) {
		$raw_body    = $request->get_body();
		$hmac_header = $request->get_header('x-shopify-hmac-sha256');
		$topic       = $request->get_header('x-shopify-topic');
		$shop_domain = $request->get_header('x-shopify-shop-domain');
		$ip          = self::client_ip();

		// Only failed-signature attempts count against the limit, so a
		// legitimate burst from Shopify (correctly signed) is never throttled.
		if ($ip && self::too_many_failures($ip)) {
			return new WP_REST_Response(['error' => 'too many requests'], 429);
		}

		if (!self::verify_hmac($raw_body, $hmac_header)) {
			if ($ip) {
				self::register_failure($ip);
			}
			Shopify_Bridge_Logger::log('webhook_invalid_signature', $topic ?: 'unknown topic');
			return new WP_REST_Response(['error' => 'invalid signature'], 401);
		}

		$expected_shop_domain = Shopify_Bridge_Settings::get('shop_domain');
		if ($expected_shop_domain !== '' && $shop_domain !== $expected_shop_domain) {
			Shopify_Bridge_Logger::log('webhook_unexpected_shop', (string) $shop_domain);
			return new WP_REST_Response(['error' => 'unexpected shop domain'], 401);
		}

		$payload = json_decode($raw_body, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
			return new WP_REST_Response(['error' => 'invalid json'], 400);
		}

		switch ($topic) {
			case 'products/create':
			case 'products/update':
				$result = Shopify_Bridge_Product_Sync::upsert($payload);
				if (is_wp_error($result)) {
					Shopify_Bridge_Logger::log('webhook_upsert_failed', $result->get_error_message());
					return new WP_REST_Response(['error' => $result->get_error_message()], 500);
				}
				Shopify_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify product ' . ($payload['id'] ?? '?') . ' — WP post ' . $result);

				// Metafields/metaobjects aren't in the webhook payload — an
				// extra GraphQL round trip is needed. Best-effort: a failure
				// here doesn't fail the webhook, since the core product data
				// (title/price/variants/images) already synced correctly.
				if (!empty($payload['id'])) {
					$mf_result = Shopify_Bridge_Metafield_Sync::sync_for_product($result, (string) $payload['id'], new Shopify_Bridge_Shopify_Client());
					if (is_wp_error($mf_result)) {
						Shopify_Bridge_Logger::log('webhook_metafield_sync_failed', $mf_result->get_error_message());
					}
				}
				break;

			case 'products/delete':
				if (!empty($payload['id'])) {
					Shopify_Bridge_Product_Sync::delete((string) $payload['id']);
					Shopify_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify product ' . $payload['id']);
				}
				break;

			case 'collections/create':
			case 'collections/update':
				if (empty($payload['id'])) {
					break;
				}
				$result = Shopify_Bridge_Collection_Sync::sync_single_collection(new Shopify_Bridge_Shopify_Client(), $payload);
				if (is_wp_error($result)) {
					Shopify_Bridge_Logger::log('webhook_collection_sync_failed', $result->get_error_message());
					return new WP_REST_Response(['error' => $result->get_error_message()], 500);
				}
				Shopify_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify collection ' . $payload['id']);
				break;

			case 'collections/delete':
				if (!empty($payload['id'])) {
					Shopify_Bridge_Collection_Sync::delete_term_for_collection((string) $payload['id']);
					Shopify_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify collection ' . $payload['id']);
				}
				break;

			// A metaobject entry (e.g. one FAQ item, one "How to Use" step)
			// changed or was deleted on its own — no product was touched, so
			// no products/update webhook ever fires for this. Re-sync every
			// product whose fields reference this metaobject's GID (tracked
			// in Shopify_Bridge_Metafield_Sync::META_METAOBJECT_REF) so its
			// new content — or, on delete, its absence — actually lands on
			// WordPress instead of waiting for the next reconciliation.
			case 'metaobjects/update':
			case 'metaobjects/delete':
				if (!empty($payload['admin_graphql_api_id'])) {
					$resynced = Shopify_Bridge_Metafield_Sync::resync_products_referencing_metaobject(
						$payload['admin_graphql_api_id'],
						new Shopify_Bridge_Shopify_Client()
					);
					Shopify_Bridge_Logger::log('webhook_processed', $topic . ' — metaobject ' . $payload['admin_graphql_api_id'] . " — {$resynced} prodotti risincronizzati");
				}
				break;

			default:
				return new WP_REST_Response(['error' => 'unhandled topic'], 400);
		}

		return new WP_REST_Response(['ok' => true], 200);
	}

	private static function verify_hmac($raw_body, $hmac_header) {
		$secret = Shopify_Bridge_Settings::get('client_secret');
		if (!$hmac_header || $secret === '') {
			return false;
		}
		$computed = base64_encode(hash_hmac('sha256', $raw_body, $secret, true));
		// hash_equals for a timing-safe comparison against a forged signature.
		return hash_equals($computed, $hmac_header);
	}

	/**
	 * REMOTE_ADDR only (never X-Forwarded-For/X-Real-IP): those headers are
	 * trivially spoofable by the client unless a reverse proxy is configured
	 * to strip and re-set them, which we can't assume here.
	 */
	private static function client_ip() {
		return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	private static function rate_limit_key($ip) {
		return 'ns_bridge_whf_' . md5($ip);
	}

	private static function too_many_failures($ip) {
		return (int) get_transient(self::rate_limit_key($ip)) >= 20;
	}

	private static function register_failure($ip) {
		$key = self::rate_limit_key($ip);
		set_transient($key, (int) get_transient($key) + 1, 5 * MINUTE_IN_SECONDS);
	}
}
