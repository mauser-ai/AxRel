<?php
defined('ABSPATH') || exit;

/**
 * Thin wrapper around the Shopify Admin REST API. Used by the daily
 * reconciliation job (full catalog pull) and by the webhook registrar.
 * Real-time product data instead comes straight from webhook payloads,
 * so it never has to be fetched here.
 */
class Axrel_Shopify_Client {

	private $shop_domain;
	private $access_token;
	private $api_version;

	public function __construct() {
		$this->shop_domain  = Axrel_Settings::get('shop_domain');
		$this->access_token = Axrel_Settings::get('admin_token');
		$this->api_version  = Axrel_Settings::get('api_version') ?: '2024-10';
	}

	public function is_configured() {
		return $this->shop_domain !== '' && $this->access_token !== '';
	}

	private function base_url() {
		return "https://{$this->shop_domain}/admin/api/{$this->api_version}";
	}

	private function request($method, $path, $args = []) {
		$url = $this->base_url() . $path;

		$response = wp_remote_request($url, array_merge([
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'X-Shopify-Access-Token' => $this->access_token,
				'Content-Type'           => 'application/json',
			],
		], $args));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400) {
			return new WP_Error('axrel_shopify_api_error', "Shopify API error {$code} on {$path}", $body);
		}

		return [
			'body'    => $body,
			'headers' => wp_remote_retrieve_headers($response),
		];
	}

	/**
	 * Fetches one page of products (any status: active/archived/draft).
	 * Pass the previous response's next_page cursor to continue; Shopify's
	 * cursor pagination requires page_info to be the only filter param once set.
	 */
	public function list_products($page_info = null, $limit = 50) {
		$query = $page_info
			? ['limit' => $limit, 'page_info' => $page_info]
			: ['limit' => $limit, 'status' => 'any'];

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
		return $result['body']['shop'] ?? new WP_Error('axrel_shopify_api_error', 'Risposta inattesa da Shopify');
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
