<?php
defined('ABSPATH') || exit;

/**
 * Registers this site's webhook endpoint with Shopify for the topics we
 * care about. Idempotent: matches on topic + address, so re-running it
 * (e.g. after a domain change) only creates what's missing.
 */
class NS_Bridge_Webhook_Registrar {

	const TOPICS = ['products/create', 'products/update', 'products/delete'];

	public static function ensure_registered() {
		$client  = new NS_Bridge_Shopify_Client();
		$address = rest_url('ns-bridge/v1/webhook');
		$results = [];

		if (!$client->is_configured()) {
			return ['error' => 'not_configured'];
		}

		$existing = $client->list_webhooks();
		if (is_wp_error($existing)) {
			return ['error' => $existing->get_error_message()];
		}

		foreach (self::TOPICS as $topic) {
			$already = array_filter($existing, function ($webhook) use ($topic, $address) {
				return $webhook['topic'] === $topic && $webhook['address'] === $address;
			});

			if ($already) {
				$results[$topic] = 'already_registered';
				continue;
			}

			$response = $client->create_webhook($topic, $address);
			$results[$topic] = is_wp_error($response) ? 'error: ' . $response->get_error_message() : 'registered';
		}

		return $results;
	}
}
