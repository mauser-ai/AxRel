<?php
/**
 * Plugin Name: NS Bridge
 * Description: Sincronizza i prodotti Shopify (incluse varianti/colori/prezzi) su prodotti WooCommerce in tempo reale via webhook, con riconciliazione giornaliera per garantire coerenza e stabilita'. WordPress resta lo storefront pubblico e indicizzabile, Shopify il commerce engine; il checkout resta sempre e solo su Shopify.
 * Version: 0.2.0
 * Text Domain: ns-bridge
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('NSBRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NSBRIDGE_PLUGIN_FILE', __FILE__);

require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-settings.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-logger.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-shopify-client.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-product-sync.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-webhook-handler.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-webhook-registrar.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-reconciliation.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-cron.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-seo.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-frontend.php';
require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-admin-page.php';

if (defined('WP_CLI') && WP_CLI) {
	require_once NSBRIDGE_PLUGIN_DIR . 'includes/class-ns-bridge-cli.php';
}

register_activation_hook(__FILE__, function () {
	if (class_exists('WooCommerce')) {
		// Match the SEO brief's /products/handle/ URL shape.
		$permalinks = get_option('woocommerce_permalinks', []);
		if (empty($permalinks['product_base'])) {
			$permalinks['product_base'] = 'products';
			update_option('woocommerce_permalinks', $permalinks);
		}
	}
	flush_rewrite_rules();
	NS_Bridge_Cron::activate();
});

register_deactivation_hook(__FILE__, function () {
	NS_Bridge_Cron::deactivate();
	flush_rewrite_rules();
});

add_action('admin_notices', function () {
	if (!class_exists('WooCommerce') && current_user_can('activate_plugins')) {
		echo '<div class="notice notice-error"><p><strong>NS Bridge</strong> richiede WooCommerce attivo per gestire prodotti e varianti Shopify.</p></div>';
	}
});

add_action('init', [NS_Bridge_Cron::class, 'register']);
add_action('init', [NS_Bridge_SEO::class, 'register']);
// wp_loaded runs after WooCommerce's own init hooks, so it's safe to remove/replace its default add-to-cart hook here.
add_action('wp_loaded', [NS_Bridge_Frontend::class, 'register']);
add_action('rest_api_init', [NS_Bridge_Webhook_Handler::class, 'register_routes']);

add_action('admin_menu', [NS_Bridge_Admin_Page::class, 'register_menu']);
add_action('admin_post_ns_bridge_save_settings', [NS_Bridge_Admin_Page::class, 'handle_save_settings']);
add_action('admin_post_ns_bridge_test_connection', [NS_Bridge_Admin_Page::class, 'handle_test_connection']);
add_action('admin_post_ns_bridge_run_reconciliation', [NS_Bridge_Admin_Page::class, 'handle_run_reconciliation']);
add_action('admin_post_ns_bridge_register_webhooks', [NS_Bridge_Admin_Page::class, 'handle_register_webhooks']);
