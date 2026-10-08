<?php
defined('ABSPATH') || exit;

/**
 * Thin wrapper around the Shopify Admin REST API. Used by the daily
 * reconciliation job (full catalog pull) and by the webhook registrar.
 * Real-time product data instead comes straight from webhook payloads,
 * so it never has to be fetched here.
 *
 * Since Shopify retired static admin-created app tokens (Dev Dashboard apps
 * created from January 2026 only issue a Client ID/Client secret pair), this
 * exchanges those for a short-lived Admin API access token via the OAuth
 * client credentials grant, and caches it for reuse until shortly before it
 * expires (Shopify tokens from this grant are valid 24h).
 */
class Shopify_Bridge_Shopify_Client {

	private $shop_domain;
	private $client_id;
	private $client_secret;
	private $api_version;

	public function __construct() {
		$this->shop_domain    = Shopify_Bridge_Settings::get('shop_domain');
		$this->client_id      = Shopify_Bridge_Settings::get('client_id');
		$this->client_secret  = Shopify_Bridge_Settings::get('client_secret');
		$this->api_version    = Shopify_Bridge_Settings::get('api_version') ?: '2024-10';
	}

	public function is_configured() {
		return $this->shop_domain !== '' && $this->client_id !== '' && $this->client_secret !== '';
	}

	/**
	 * Forces the next request to fetch a fresh Admin API access token instead
	 * of reusing the cached one. A token obtained via the client_credentials
	 * grant carries the scopes granted to the app *at the moment it was
	 * issued* — changing the client secret or adding an Admin API scope on
	 * the Dev Dashboard doesn't retroactively upgrade an already-cached
	 * token, so without this a stale token (fetched before a scope change)
	 * keeps being reused silently for up to 23h. Call this whenever the
	 * stored Shopify credentials change.
	 */
	public static function clear_cached_token() {
		$shop_domain = Shopify_Bridge_Settings::get('shop_domain');
		$client_id   = Shopify_Bridge_Settings::get('client_id');
		delete_transient('ns_bridge_access_token_' . md5($shop_domain . '|' . $client_id));
	}

	private function base_url($api_version_override = null) {
		return "https://{$this->shop_domain}/admin/api/" . ($api_version_override ?: $this->api_version);
	}

	/**
	 * Client credentials grant: POST client_id + client_secret to Shopify's
	 * OAuth token endpoint, cache the resulting access token (23h, a little
	 * under Shopify's 24h expiry so we never use a stale one), and reuse it
	 * across requests instead of re-authenticating every call.
	 */
	private function get_access_token() {
		if (!$this->is_configured()) {
			return new WP_Error('ns_bridge_shopify_not_configured', "Credenziali Shopify (dominio, Client ID, Client secret) non configurate.");
		}

		$cache_key = 'ns_bridge_access_token_' . md5($this->shop_domain . '|' . $this->client_id);
		$cached    = get_transient($cache_key);
		if ($cached) {
			return $cached;
		}

		$response = wp_remote_post("https://{$this->shop_domain}/admin/oauth/access_token", [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body'    => [
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			],
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code($response);
		$raw_body  = wp_remote_retrieve_body($response);
		$body      = json_decode($raw_body, true);

		if ($code >= 400 || empty($body['access_token'])) {
			// $body is only useful when Shopify actually returned JSON; when
			// it didn't (an HTML error/WAF page, a redirect, an empty
			// response...), json_decode() silently gives null and hides the
			// real reason — fall back to the raw response text in that case.
			$detail = $body !== null ? wp_json_encode($body) : substr($raw_body, 0, 500);
			return new WP_Error('ns_bridge_oauth_failed', "Scambio token OAuth con Shopify fallito (HTTP {$code}): {$detail}", $body);
		}

		set_transient($cache_key, $body['access_token'], 23 * HOUR_IN_SECONDS);

		return $body['access_token'];
	}

	private function request($method, $path, $args = []) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) {
			return $token;
		}

		$url = $this->base_url() . $path;

		$response = wp_remote_request($url, array_merge([
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'X-Shopify-Access-Token' => $token,
				'Content-Type'           => 'application/json',
			],
		], $args));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400) {
			return new WP_Error('ns_bridge_shopify_api_error', "Shopify API error {$code} on {$path}", $body);
		}

		return [
			'body'    => $body,
			'headers' => wp_remote_retrieve_headers($response),
		];
	}

	/**
	 * GraphQL Admin API — needed for anything REST has no equivalent for
	 * (metaobjects, and resolving metafield references to real values/URLs).
	 * Product/variant/webhook sync intentionally stays on REST (proven,
	 * already working); this is additive, not a replacement.
	 *
	 * $api_version_override pins a single call to a specific Admin API
	 * version instead of the site's configured one — for a field that only
	 * exists on a newer version than the merchant may have set (e.g.
	 * Collection.sources, 2026-07+) without changing what version every
	 * other call on this client uses, since that's unverified territory for
	 * everything else this plugin does.
	 */
	public function graphql($query, array $variables = [], $api_version_override = null) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) {
			return $token;
		}

		$response = wp_remote_post($this->base_url($api_version_override) . '/graphql.json', [
			'timeout' => 20,
			'headers' => [
				'X-Shopify-Access-Token' => $token,
				'Content-Type'           => 'application/json',
			],
			// An empty PHP array encodes to JSON `[]`, but Shopify's GraphQL
			// endpoint requires `variables` to be an object (`{}`) even when
			// there are none — every prior caller always passed at least one
			// variable, so this never surfaced until a variable-less query.
			'body' => wp_json_encode(['query' => $query, 'variables' => $variables ?: new stdClass()]),
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400) {
			return new WP_Error('ns_bridge_shopify_graphql_error', "Shopify GraphQL error HTTP {$code}: " . wp_json_encode($body), $body);
		}
		if (!empty($body['errors'])) {
			return new WP_Error('ns_bridge_shopify_graphql_error', 'Shopify GraphQL error: ' . wp_json_encode($body['errors']));
		}

		return $body['data'] ?? [];
	}

	/**
	 * Fetches one page of products for a single status. Shopify's REST
	 * products.json only recognizes 'active', 'draft' and 'archived' as
	 * status values — there is no 'any' wildcard, despite that being a
	 * reasonable assumption; passing an unrecognized value silently matches
	 * nothing rather than erroring, so the caller must walk all three
	 * statuses to see the full catalog (see Shopify_Bridge_Reconciliation::run()).
	 * Pass the previous response's next_page cursor to continue; Shopify's
	 * cursor pagination requires page_info to be the only filter param once set.
	 */
	public function list_products($status, $page_info = null, $limit = 50) {
		$query = $page_info
			? ['limit' => $limit, 'page_info' => $page_info]
			: ['limit' => $limit, 'status' => $status];

		$result = $this->request('GET', '/products.json?' . http_build_query($query));
		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'products'  => $result['body']['products'] ?? [],
			'next_page' => $this->extract_next_page_info($result['headers']),
		];
	}

	private function extract_next_page_info($headers) {
		$link = $headers['link'] ?? $headers['Link'] ?? '';
		if (!$link || !preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/', $link, $m)) {
			return null;
		}
		return urldecode($m[1]);
	}

	public function list_webhooks() {
		$result = $this->request('GET', '/webhooks.json?limit=250');
		return is_wp_error($result) ? $result : ($result['body']['webhooks'] ?? []);
	}

	/** Manually-curated collections. */
	public function list_custom_collections($page_info = null, $limit = 50) {
		return $this->list_collections_of_type('custom_collections', $page_info, $limit);
	}

	/** Rule-based (automated) collections. */
	public function list_smart_collections($page_info = null, $limit = 50) {
		return $this->list_collections_of_type('smart_collections', $page_info, $limit);
	}

	private function list_collections_of_type($resource, $page_info = null, $limit = 50) {
		$query = $page_info ? ['limit' => $limit, 'page_info' => $page_info] : ['limit' => $limit];

		$result = $this->request('GET', "/{$resource}.json?" . http_build_query($query));
		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'items'     => $result['body'][$resource] ?? [],
			'next_page' => $this->extract_next_page_info($result['headers']),
		];
	}

	/**
	 * Products belonging to a collection — works for both custom and smart
	 * collections (Shopify resolves smart collection membership for you).
	 */
	public function list_collection_products($collection_id, $page_info = null, $limit = 250) {
		$query = $page_info ? ['limit' => $limit, 'page_info' => $page_info] : ['limit' => $limit];

		$result = $this->request('GET', "/collections/{$collection_id}/products.json?" . http_build_query($query));
		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'items'     => $result['body']['products'] ?? [],
			'next_page' => $this->extract_next_page_info($result['headers']),
		];
	}

	/** Used by the settings page to verify domain + token are valid. */
	public function get_shop() {
		$result = $this->request('GET', '/shop.json');
		if (is_wp_error($result)) {
			return $result;
		}
		return $result['body']['shop'] ?? new WP_Error('ns_bridge_shopify_api_error', 'Risposta inattesa da Shopify');
	}

	public function create_webhook($topic, $address) {
		return $this->request('POST', '/webhooks.json', [
			'body' => wp_json_encode([
				'webhook' => [
					'topic'   => $topic,
					'address' => $address,
					'format'  => 'json',
				],
			]),
		]);
	}

	/**
	 * The metaobjects/create|update|delete topics are GraphQL-only — the
	 * REST /webhooks.json endpoint rejects them outright ("Could not find
	 * the webhook topic"), and they require a `filter` (type:{handle}) that
	 * the REST resource has no field for anyway. $topic here is the
	 * SCREAMING_SNAKE_CASE WebhookSubscriptionTopic enum value (e.g.
	 * METAOBJECTS_UPDATE), not the slash-form topic string used elsewhere in
	 * this codebase for REST.
	 */
	public function create_webhook_graphql($topic, $address, $filter = null) {
		$query = <<<'GRAPHQL'
		mutation ($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
			webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
				webhookSubscription { id topic filter }
				userErrors { field message }
			}
		}
		GRAPHQL;

		$input = ['uri' => $address];
		if ($filter !== null) {
			$input['filter'] = $filter;
		}

		$data = $this->graphql($query, ['topic' => $topic, 'webhookSubscription' => $input]);
		if (is_wp_error($data)) {
			return $data;
		}

		$errors = $data['webhookSubscriptionCreate']['userErrors'] ?? [];
		if ($errors) {
			return new WP_Error('ns_bridge_shopify_webhook_graphql_error', wp_json_encode($errors));
		}

		return $data['webhookSubscriptionCreate']['webhookSubscription'] ?? [];
	}

	/** Lists existing GraphQL-managed subscriptions for the given topics, so registration can stay idempotent (same reasoning as list_webhooks() for the REST ones). */
	public function list_webhook_subscriptions_graphql(array $topics) {
		$query = <<<'GRAPHQL'
		query ($topics: [WebhookSubscriptionTopic!]) {
			webhookSubscriptions(first: 100, topics: $topics) {
				nodes {
					id
					topic
					filter
					endpoint {
						__typename
						... on WebhookHttpEndpoint { callbackUrl }
					}
				}
			}
		}
		GRAPHQL;

		$data = $this->graphql($query, ['topics' => $topics]);
		if (is_wp_error($data)) {
			return $data;
		}

		return $data['webhookSubscriptions']['nodes'] ?? [];
	}

	/**
	 * Sub-collection GIDs a collection's sources reference (Collection
	 * Sources API) — i.e. the "collections nested inside this collection"
	 * from the merchant's point of view. `sources` and `collections` are
	 * both plain lists, not connections (no first/nodes) — confirmed
	 * against a live store's schema, since Shopify's own docs disagreed
	 * with themselves on this at the time this was written. Pinned to API
	 * version 2026-07 (the first version with this field) regardless of
	 * what the site has configured for every other call — see graphql()'s
	 * $api_version_override.
	 */
	public function get_collection_sub_collections($collection_gid) {
		$query = <<<'GRAPHQL'
		query ($id: ID!) {
			collection(id: $id) {
				sources {
					__typename
					... on CollectionSubCollectionsSource {
						collections { id }
					}
				}
			}
		}
		GRAPHQL;

		$data = $this->graphql($query, ['id' => $collection_gid], '2026-07');
		if (is_wp_error($data)) {
			return $data;
		}

		$child_gids = [];
		foreach ($data['collection']['sources'] ?? [] as $source) {
			if (($source['__typename'] ?? '') !== 'CollectionSubCollectionsSource') {
				continue;
			}
			foreach ($source['collections'] ?? [] as $child) {
				if (!empty($child['id'])) {
					$child_gids[] = $child['id'];
				}
			}
		}

		return $child_gids;
	}
}
