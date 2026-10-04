<?php
defined('ABSPATH') || exit;

/**
 * Completes Shopify's standard OAuth install handshake.
 *
 * Discovered the hard way: a client_credentials grant (what
 * Shopify_Bridge_Shopify_Client uses for every ongoing API call) only works
 * once Shopify considers the app "installed" on the store — releasing a Dev
 * Dashboard app version with the right scopes is NOT enough by itself.
 * Clicking "Install app" in the Dev Dashboard starts the classic redirect
 * flow (Shopify -> our Application URL -> Shopify's consent screen -> our
 * redirect/callback URL), and without an endpoint to receive that last leg
 * the handshake never completes — every client_credentials token request
 * then fails with "Oauth error app_not_installed", no matter how many times
 * you click Install or how correct the configured scopes are.
 *
 * This class is that missing last leg. It doesn't need to be used for
 * anything ongoing afterwards (client_credentials stays the only thing the
 * rest of the plugin calls) — its only job is to make Shopify mark the app
 * installed, once, so client_credentials starts working.
 *
 * Dev Dashboard app configuration needed for this to be reachable:
 *   Application URL:            https://<this site>/wp-json/ns-bridge/v1/install
 *   Allowed redirection URL(s): https://<this site>/wp-json/ns-bridge/v1/oauth/callback
 */
class Shopify_Bridge_OAuth_Install {

	/**
	 * Matches what Shopify_Bridge_Settings documents as needed: read_products
	 * + read_inventory for ongoing sync, read_metaobjects for the dynamic
	 * product-page fields, and the three write-scopes/definitions scopes only
	 * for the one-time "Crea definizioni su Shopify" button (removable after).
	 * Dev Dashboard apps have their real scope grant driven by the released
	 * version's own configuration — this is sent for a standards-compliant
	 * authorize request, but Shopify may just use the version's scopes
	 * regardless of what's listed here.
	 */
	const SCOPES = 'read_products,read_inventory,read_metaobjects,write_products,write_metaobject_definitions,read_metaobject_definitions';

	const STATE_PREFIX = 'ns_bridge_oauth_state_';

	public static function register_routes() {
		register_rest_route('ns-bridge/v1', '/install', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'handle_install'],
			'permission_callback' => '__return_true',
		]);
		register_rest_route('ns-bridge/v1', '/oauth/callback', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'handle_callback'],
			'permission_callback' => '__return_true',
		]);
	}

	/** Entry point: Shopify redirects here (as the app's "Application URL") to start the install. */
	public static function handle_install(WP_REST_Request $request) {
		$params = $request->get_query_params();
		$shop   = $params['shop'] ?? '';
		$secret = Shopify_Bridge_Settings::get('client_secret');
		$client_id = Shopify_Bridge_Settings::get('client_id');

		if ($shop === '' || $secret === '' || $client_id === '') {
			return self::error_response('Credenziali Shopify non configurate su WordPress (dominio, Client ID, Client secret).');
		}
		if (!self::verify_query_hmac($params, $secret)) {
			Shopify_Bridge_Logger::log('oauth_install_invalid_hmac', 'shop=' . $shop);
			return self::error_response('Firma non valida sulla richiesta di installazione.');
		}
		$expected_shop = Shopify_Bridge_Settings::get('shop_domain');
		if ($expected_shop !== '' && $shop !== $expected_shop) {
			Shopify_Bridge_Logger::log('oauth_install_unexpected_shop', (string) $shop);
			return self::error_response('Dominio negozio inatteso.');
		}

		$state = wp_generate_password(32, false);
		set_transient(self::STATE_PREFIX . $state, $shop, 10 * MINUTE_IN_SECONDS);

		$redirect_uri = rest_url('ns-bridge/v1/oauth/callback');
		$authorize_url = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
			'client_id'    => $client_id,
			'scope'        => self::SCOPES,
			'redirect_uri' => $redirect_uri,
			'state'        => $state,
		]);

		Shopify_Bridge_Logger::log('oauth_install_redirect', 'shop=' . $shop);
		wp_redirect($authorize_url);
		exit;
	}

	/** Shopify redirects here after the merchant approves (or denies) the consent screen. */
	public static function handle_callback(WP_REST_Request $request) {
		$params = $request->get_query_params();
		$shop   = $params['shop'] ?? '';
		$code   = $params['code'] ?? '';
		$state  = $params['state'] ?? '';
		$secret = Shopify_Bridge_Settings::get('client_secret');
		$client_id = Shopify_Bridge_Settings::get('client_id');

		if (!self::verify_query_hmac($params, $secret)) {
			Shopify_Bridge_Logger::log('oauth_callback_invalid_hmac', 'shop=' . $shop);
			return self::error_response('Firma non valida sulla risposta di Shopify.');
		}

		$state_key      = self::STATE_PREFIX . $state;
		$expected_shop  = get_transient($state_key);
		if ($state === '' || $expected_shop === false || $expected_shop !== $shop) {
			Shopify_Bridge_Logger::log('oauth_callback_invalid_state', 'shop=' . $shop);
			return self::error_response('Sessione di installazione scaduta o non valida — riprova cliccando di nuovo "Install app" dal Dev Dashboard.');
		}
		delete_transient($state_key);

		if ($code === '') {
			Shopify_Bridge_Logger::log('oauth_callback_denied', 'shop=' . $shop);
			return self::error_response('Installazione annullata (nessun codice di autorizzazione ricevuto).');
		}

		// Exchanging the code is what actually makes Shopify mark the app
		// installed on this store — the resulting token itself is discarded,
		// since every ongoing call in this plugin uses the separate
		// client_credentials grant instead.
		$response = wp_remote_post("https://{$shop}/admin/oauth/access_token", [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body'    => [
				'client_id'     => $client_id,
				'client_secret' => $secret,
				'code'          => $code,
			],
		]);

		if (is_wp_error($response)) {
			Shopify_Bridge_Logger::log('oauth_callback_exchange_failed', $response->get_error_message());
			return self::error_response('Scambio del codice di autorizzazione fallito: ' . esc_html($response->get_error_message()));
		}

		$code_http = wp_remote_retrieve_response_code($response);
		$raw_body  = wp_remote_retrieve_body($response);
		$body      = json_decode($raw_body, true);

		if ($code_http >= 400 || empty($body['access_token'])) {
			$detail = $body !== null ? wp_json_encode($body) : substr($raw_body, 0, 500);
			Shopify_Bridge_Logger::log('oauth_callback_exchange_failed', "HTTP {$code_http}: {$detail}");
			return self::error_response("Scambio del codice di autorizzazione fallito (HTTP {$code_http}): " . esc_html($detail));
		}

		// This freshly-granted install may have changed what's allowed —
		// the next client_credentials call should request a brand new token
		// rather than reuse whatever (possibly scope-stale) one is cached.
		Shopify_Bridge_Shopify_Client::clear_cached_token();

		Shopify_Bridge_Logger::log('oauth_install_completed', 'shop=' . $shop);

		echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:3em;text-align:center;">'
			. '<h1>Installazione completata</h1>'
			. '<p>L\'app &egrave; ora installata su ' . esc_html($shop) . '. Puoi tornare a WordPress e verificare la connessione.</p>'
			. '</body></html>';
		exit;
	}

	private static function error_response($message) {
		echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:3em;text-align:center;">'
			. '<h1>Installazione non riuscita</h1><p>' . esc_html($message) . '</p>'
			. '</body></html>';
		exit;
	}

	/**
	 * Shopify's documented algorithm for the query-string HMAC it attaches to
	 * every redirect in this flow — distinct from the raw-POST-body HMAC
	 * used for webhooks (see Shopify_Bridge_Webhook_Handler::verify_hmac).
	 */
	private static function verify_query_hmac(array $params, $secret) {
		if ($secret === '' || empty($params['hmac'])) {
			return false;
		}
		$hmac = $params['hmac'];
		unset($params['hmac'], $params['signature']);
		ksort($params);

		$pairs = [];
		foreach ($params as $key => $value) {
			$key   = str_replace(['%', '&', '='], ['%25', '%26', '%3D'], (string) $key);
			$value = str_replace(['%', '&', '='], ['%25', '%26', '%3D'], (string) $value);
			$pairs[] = "{$key}={$value}";
		}
		$computed = hash_hmac('sha256', implode('&', $pairs), $secret);

		return hash_equals($computed, (string) $hmac);
	}
}
