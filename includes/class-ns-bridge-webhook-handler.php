<?php
defined('ABSPATH') || exit;

/**
 * Receives Shopify's real-time webhooks (products/create|update|delete) at
 * /wp-json/ns-bridge/v1/webhook. Auth is HMAC (per Shopify's webhook
 * spec), not WP REST auth, so the route is public and validated manually.
 */
class NS_Bridge_Webhook_Handler {

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
			NS_Bridge_Logger::log('webhook_invalid_signature', $topic ?: 'unknown topic');
			return new WP_REST_Response(['error' => 'invalid signature'], 401);
		}

		$expected_shop_domain = NS_Bridge_Settings::get('shop_domain');
		if ($expected_shop_domain !== '' && $shop_domain !== $expected_shop_domain) {
			NS_Bridge_Logger::log('webhook_unexpected_shop', (string) $shop_domain);
			return new WP_REST_Response(['error' => 'unexpected shop domain'], 401);
		}

		$payload = json_decode($raw_body, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
			return new WP_REST_Response(['error' => 'invalid json'], 400);
		}

		switch ($topic) {
			case 'products/create':
			case 'products/update':
				$result = NS_Bridge_Product_Sync::upsert($payload);
				if (is_wp_error($result)) {
					NS_Bridge_Logger::log('webhook_upsert_failed', $result->get_error_message());
					return new WP_REST_Response(['error' => $result->get_error_message()], 500);
				}
				NS_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify product ' . ($payload['id'] ?? '?') . ' — WP post ' . $result);
				break;

			case 'products/delete':
				if (!empty($payload['id'])) {
					NS_Bridge_Product_Sync::delete((string) $payload['id']);
					NS_Bridge_Logger::log('webhook_processed', $topic . ' — Shopify product ' . $payload['id']);
				}
				break;

			default:
				return new WP_REST_Response(['error' => 'unhandled topic'], 400);
		}

		return new WP_REST_Response(['ok' => true], 200);
	}

	private static function verify_hmac($raw_body, $hmac_header) {
		$secret = NS_Bridge_Settings::get('client_secret');
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
