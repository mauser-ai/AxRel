<?php
defined('ABSPATH') || exit;

/**
 * Registers this site's webhook endpoint with Shopify for the topics we
 * care about. Idempotent: matches on topic + address, so re-running it
 * (e.g. after a domain change) only creates what's missing.
 */
class Shopify_Bridge_Webhook_Registrar {

	const TOPICS = ['products/create', 'products/update', 'products/delete', 'collections/create', 'collections/update', 'collections/delete'];

	/**
	 * metaobjects/update|delete, GraphQL-only (see Shopify_Bridge_Shopify_Client::create_webhook_graphql()).
	 * Shopify requires a `type:{handle}` filter for these — there is no
	 * "any type" subscription — so one is registered per metaobject type
	 * this plugin actually reads (Shopify_Bridge_Setup_Definitions::METAOBJECTS).
	 * Only update/delete matter here: a newly *created* metaobject isn't
	 * referenced by any product yet (that happens via a product field edit,
	 * which already fires products/update), so metaobjects/create would
	 * just be dead weight.
	 */
	const METAOBJECT_GRAPHQL_TOPICS = ['METAOBJECTS_UPDATE', 'METAOBJECTS_DELETE'];

	/**
	 * The webhook URL to register with Shopify. If HTTP Basic Auth
	 * credentials are configured (for a site sitting behind a server-level
	 * password wall, e.g. a protected Kinsta staging environment), they're
	 * embedded as userinfo (https://user:pass@host/...) so Shopify's own
	 * HTTP client authenticates automatically — standard, RFC 3986 syntax,
	 * though it's the site owner's call whether their delivery pipeline
	 * accepts it; some HTTP stacks strip credentials from URLs on principle.
	 */
	public static function webhook_address() {
		$address = rest_url('ns-bridge/v1/webhook');

		$user = Shopify_Bridge_Settings::get('basic_auth_user');
		$pass = Shopify_Bridge_Settings::get('basic_auth_pass');
		if ($user === '' && $pass === '') {
			return $address;
		}

		$parts = wp_parse_url($address);
		if (empty($parts['host'])) {
			return $address;
		}

		$userinfo = rawurlencode($user) . ':' . rawurlencode($pass);
		$port     = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path     = $parts['path'] ?? '';

		return "{$parts['scheme']}://{$userinfo}@{$parts['host']}{$port}{$path}";
	}

	public static function ensure_registered() {
		$client  = new Shopify_Bridge_Shopify_Client();
		$address = self::webhook_address();
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

		$existing_metaobject_subs = $client->list_webhook_subscriptions_graphql(self::METAOBJECT_GRAPHQL_TOPICS);
		if (is_wp_error($existing_metaobject_subs)) {
			$results['metaobjects/*'] = 'error: ' . $existing_metaobject_subs->get_error_message();
			return $results;
		}

		foreach (array_keys(Shopify_Bridge_Setup_Definitions::METAOBJECTS) as $metaobject_type) {
			$filter = "type:{$metaobject_type}";

			foreach (self::METAOBJECT_GRAPHQL_TOPICS as $graphql_topic) {
				$label   = strtolower(str_replace('_', '/', $graphql_topic)) . " ({$metaobject_type})";
				$already = array_filter($existing_metaobject_subs, function ($sub) use ($graphql_topic, $filter, $address) {
					return $sub['topic'] === $graphql_topic
						&& ($sub['filter'] ?? '') === $filter
						&& ($sub['endpoint']['callbackUrl'] ?? '') === $address;
				});

				if ($already) {
					$results[$label] = 'already_registered';
					continue;
				}

				$response        = $client->create_webhook_graphql($graphql_topic, $address, $filter);
				$results[$label] = is_wp_error($response) ? 'error: ' . $response->get_error_message() : 'registered';
			}
		}

		return $results;
	}
}
