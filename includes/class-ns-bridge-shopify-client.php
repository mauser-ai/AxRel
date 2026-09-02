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
class NS_Bridge_Shopify_Client {

	private $shop_domain;
	private $client_id;
	private $client_secret;
	private $api_version;

	public function __construct() {
		$this->shop_domain    = NS_Bridge_Settings::get('shop_domain');
		$this->client_id      = NS_Bridge_Settings::get('client_id');
		$this->client_secret  = NS_Bridge_Settings::get('client_secret');
		$this->api_version    = NS_Bridge_Settings::get('api_version') ?: '2024-10';
	}

	public function is_configured() {
		return $this->shop_domain !== '' && $this->client_id !== '' && $this->client_secret !== '';
	}

	private function base_url() {
		return "https://{$this->shop_domain}/admin/api/{$this->api_version}";
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

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400 || empty($body['access_token'])) {
			// Common cause: the Dev Dashboard app and this store aren't in the
			// same Shopify organization — the client credentials grant only
			// works when both are under the same org.
			return new WP_Error('ns_bridge_oauth_failed', "Scambio token OAuth con Shopify fallito (HTTP {$code}). Verifica che l'app nel Dev Dashboard sia nella stessa organizzazione Shopify di questo negozio.", $body);
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
	 * Fetches one page of products for a single status. Shopify's REST
	 * products.json only recognizes 'active', 'draft' and 'archived' as
	 * status values — there is no 'any' wildcard, despite that being a
	 * reasonable assumption; passing an unrecognized value silently matches
	 * nothing rather than erroring, so the caller must walk all three
	 * statuses to see the full catalog (see NS_Bridge_Reconciliation::run()).
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
}
