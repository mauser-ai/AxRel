<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for AxRel configuration. A constant defined in
 * wp-config.php always wins over the DB value (more secure, not visible in
 * any admin screen); the settings page is the convenience fallback for
 * sites that don't want to touch wp-config.php.
 */
class Axrel_Settings {

	const OPTION_KEY = 'axrel_settings';

	const FIELDS = [
		'shop_domain'       => [
			'const'   => 'AXREL_SHOPIFY_SHOP_DOMAIN',
			'label'   => 'Dominio negozio Shopify',
			'help'    => 'Es. seedtoskin.myshopify.com',
			'type'    => 'text',
			'default' => '',
		],
		'admin_token'       => [
			'const'   => 'AXREL_SHOPIFY_ADMIN_TOKEN',
			'label'   => 'Admin API access token',
			'help'    => 'App personalizzata Shopify, scope minimo: read_products, read_inventory',
			'type'    => 'password',
			'default' => '',
		],
		'webhook_secret'    => [
			'const'   => 'AXREL_SHOPIFY_WEBHOOK_SECRET',
			'label'   => 'Webhook signing secret',
			'help'    => 'Usato per verificare la firma HMAC delle richieste in arrivo da Shopify',
			'type'    => 'password',
			'default' => '',
		],
		'api_version'       => [
			'const'   => 'AXREL_SHOPIFY_API_VERSION',
			'label'   => 'Versione Admin API',
			'help'    => 'Es. 2024-10',
			'type'    => 'text',
			'default' => '2024-10',
		],
		'storefront_domain' => [
			'const'   => 'AXREL_SHOPIFY_STOREFRONT_DOMAIN',
			'label'   => 'Dominio storefront pubblico',
			'help'    => 'Usato per il link "Acquista su Shopify"; se vuoto usa il dominio negozio',
			'type'    => 'text',
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

	public static function update(array $values) {
		$options = get_option(self::OPTION_KEY, []);

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
			$options[$key] = $value;
		}

		update_option(self::OPTION_KEY, $options);
	}

	public static function is_configured() {
		return self::get('shop_domain') !== '' && self::get('admin_token') !== '';
	}
}
