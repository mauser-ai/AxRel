<?php
defined('ABSPATH') || exit;

/**
 * Receives Shopify's real-time webhooks (products/create|update|delete) at
 * /wp-json/axrel-shopify/v1/webhook. Auth is HMAC (per Shopify's webhook
 * spec), not WP REST auth, so the route is public and validated manually.
 */
class Axrel_Webhook_Handler {

	public static function register_routes() {
		register_rest_route('axrel-shopify/v1', '/webhook', [
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

		if (!self::verify_hmac($raw_body, $hmac_header)) {
			Axrel_Logger::log('webhook_invalid_signature', $topic ?: 'unknown topic');
			return new WP_REST_Response(['error' => 'invalid signature'], 401);
		}

		$expected_shop_domain = Axrel_Settings::get('shop_domain');
		if ($expected_shop_domain !== '' && $shop_domain !== $expected_shop_domain) {
			Axrel_Logger::log('webhook_unexpected_shop', (string) $shop_domain);
			return new WP_REST_Response(['error' => 'unexpected shop domain'], 401);
		}

		$payload = json_decode($raw_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_REST_Response(['error' => 'invalid json'], 400);
		}

		switch ($topic) {
			case 'products/create':
			case 'products/update':
				$result = Axrel_Product_Sync::upsert($payload);
				if (is_wp_error($result)) {
					Axrel_Logger::log('webhook_upsert_failed', $result->get_error_message());
					return new WP_REST_Response(['error' => $result->get_error_message()], 500);
				}
				break;

			case 'products/delete':
				if (!empty($payload['id'])) {
					Axrel_Product_Sync::delete((string) $payload['id']);
				}
				break;

			default:
				return new WP_REST_Response(['error' => 'unhandled topic'], 400);
		}

		return new WP_REST_Response(['ok' => true], 200);
	}

	private static function verify_hmac($raw_body, $hmac_header) {
		$secret = Axrel_Settings::get('webhook_secret');
		if (!$hmac_header || $secret === '') {
			return false;
		}
		$computed = base64_encode(hash_hmac('sha256', $raw_body, $secret, true));
		// hash_equals for a timing-safe comparison against a forged signature.
		return hash_equals($computed, $hmac_header);
	}
}
