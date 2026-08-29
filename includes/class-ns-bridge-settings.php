<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for NS Bridge configuration. A constant defined in
 * wp-config.php always wins over the DB value (more secure, not visible in
 * any admin screen); the settings page is the convenience fallback for
 * sites that don't want to touch wp-config.php.
 */
class NS_Bridge_Settings {

	const OPTION_KEY = 'ns_bridge_settings';

	const FIELDS = [
		'shop_domain'       => [
			'const'   => 'NSBRIDGE_SHOPIFY_SHOP_DOMAIN',
			'label'   => 'Dominio negozio Shopify',
			'help'    => 'Es. seedtoskin.myshopify.com',
			'type'    => 'domain',
			'default' => '',
		],
		'client_id'         => [
			'const'   => 'NSBRIDGE_SHOPIFY_CLIENT_ID',
			'label'   => 'Client ID',
			'help'    => 'Dev Dashboard Shopify -> app -> Impostazioni. Scope minimo: read_products, read_inventory',
			'type'    => 'text',
			'default' => '',
		],
		'client_secret'     => [
			'const'   => 'NSBRIDGE_SHOPIFY_CLIENT_SECRET',
			'label'   => 'Client secret',
			'help'    => 'Stessa pagina del Client ID. Usato sia per ottenere il token Admin API sia per verificare la firma dei webhook in arrivo',
			'type'    => 'password',
			'default' => '',
		],
		'api_version'       => [
			'const'   => 'NSBRIDGE_SHOPIFY_API_VERSION',
			'label'   => 'Versione Admin API',
			'help'    => 'Es. 2024-10',
			'type'    => 'text',
			'default' => '2024-10',
		],
		'storefront_domain' => [
			'const'   => 'NSBRIDGE_SHOPIFY_STOREFRONT_DOMAIN',
			'label'   => 'Dominio storefront pubblico',
			'help'    => 'Usato per il link "Acquista su Shopify"; se vuoto usa il dominio negozio',
			'type'    => 'domain',
			'default' => '',
		],
		'default_currency'  => [
			'const'   => null,
			'label'   => 'Valuta di default',
			'help'    => 'Usata solo se Shopify non specifica la valuta nel payload',
			'type'    => 'text',
			'default' => 'EUR',
		],
	];

	public static function get($key) {
		$field = self::FIELDS[$key] ?? null;
		if (!$field) {
			return '';
		}
		if (self::is_locked_by_constant($key)) {
			return constant($field['const']);
		}
		$options = get_option(self::OPTION_KEY, []);
		if (isset($options[$key]) && $options[$key] !== '') {
			return $options[$key];
		}
		return $field['default'];
	}

	public static function is_locked_by_constant($key) {
		$field = self::FIELDS[$key] ?? null;
		return $field && !empty($field['const']) && defined($field['const']) && constant($field['const']) !== '';
	}

	public static function get_stored_value($key) {
		$options = get_option(self::OPTION_KEY, []);
		return $options[$key] ?? '';
	}

	/**
	 * Saves the posted values, returning the list of field keys rejected for
	 * failing validation (currently: malformed domains) so the admin page can
	 * warn the user instead of silently keeping the previous value.
	 */
	public static function update(array $values) {
		$options  = get_option(self::OPTION_KEY, []);
		$rejected = [];

		foreach (self::FIELDS as $key => $field) {
			if (self::is_locked_by_constant($key) || !isset($values[$key])) {
				continue;
			}
			$value = sanitize_text_field(wp_unslash($values[$key]));
			// Blank password fields mean "leave unchanged", not "clear it" —
			// otherwise re-saving the form after connecting would wipe the token.
			if ($field['type'] === 'password' && $value === '') {
				continue;
			}
			if ($field['type'] === 'domain' && $value !== '' && !self::is_valid_domain($value)) {
				$rejected[] = $key;
				continue; // keep the previously stored value rather than a malformed/unsafe host
			}
			$options[$key] = $value;
		}

		update_option(self::OPTION_KEY, $options);

		return $rejected;
	}

	/**
	 * Hostname only: no scheme, no path, no whitespace. These values feed
	 * server-side HTTP calls (Admin API base URL, image sideload allowlist),
	 * so a malformed value is rejected outright rather than best-effort
	 * normalized.
	 */
	private static function is_valid_domain($value) {
		return (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i', $value);
	}

	public static function is_configured() {
		return self::get('shop_domain') !== '' && self::get('client_id') !== '' && self::get('client_secret') !== '';
	}
}
