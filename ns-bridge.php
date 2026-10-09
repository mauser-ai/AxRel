<?php
/**
 * Plugin Name: Shopify Bridge
 * Description: Sincronizza i prodotti Shopify (varianti, prezzi, categorie, metafield/metaobject della product page) su prodotti WooCommerce in tempo reale via webhook, con riconciliazione giornaliera per garantire coerenza e stabilita'. WordPress resta lo storefront pubblico e indicizzabile, Shopify il commerce engine e l'unica fonte dati; il checkout resta sempre e solo su Shopify.
 * Version: 0.4.0
 * Text Domain: shopify-bridge
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('SHOPIFYBRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPIFYBRIDGE_PLUGIN_FILE', __FILE__);

require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-settings.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-logger.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-media.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-rich-text.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-shopify-client.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-product-sync.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-metafield-sync.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-metafield-debug-box.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-setup-definitions.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-collection-sync.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-webhook-handler.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-oauth-install.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-webhook-registrar.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-reconciliation.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-batch-sync.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-cron.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-seo.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-frontend.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-elementor.php';
require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-admin-page.php';

if (defined('WP_CLI') && WP_CLI) {
	require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/class-shopify-bridge-cli.php';
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
	Shopify_Bridge_Cron::activate();
});

register_deactivation_hook(__FILE__, function () {
	Shopify_Bridge_Cron::deactivate();
	wp_clear_scheduled_hook(Shopify_Bridge_Metafield_Sync::RETRY_HOOK);
	flush_rewrite_rules();
});

add_action('admin_notices', function () {
	if (!class_exists('WooCommerce') && current_user_can('activate_plugins')) {
		echo '<div class="notice notice-error"><p><strong>Shopify Bridge</strong> richiede WooCommerce attivo per gestire prodotti e varianti Shopify.</p></div>';
	}
});

add_action('init', [Shopify_Bridge_Cron::class, 'register']);
add_action('init', [Shopify_Bridge_SEO::class, 'register']);
// wp_loaded runs after WooCommerce's own init hooks, so it's safe to remove/replace its default add-to-cart hook here.
add_action('wp_loaded', [Shopify_Bridge_Frontend::class, 'register']);
add_action('rest_api_init', [Shopify_Bridge_Webhook_Handler::class, 'register_routes']);
add_action('rest_api_init', [Shopify_Bridge_OAuth_Install::class, 'register_routes']);
add_action(Shopify_Bridge_Metafield_Sync::RETRY_HOOK, [Shopify_Bridge_Metafield_Sync::class, 'retry'], 10, 3);

add_action('elementor/elements/categories_registered', [Shopify_Bridge_Elementor::class, 'register_category']);
add_action('elementor/widgets/register', [Shopify_Bridge_Elementor::class, 'register_widgets']);
// Priority 100: late on purpose. Confirmed live (video + screenshots on the
// real store) that the theme's own CSS was stripping our buttons/controls'
// padding, border and shape even though our selectors are classes (more
// specific than the theme's likely bare "button" reset) — with both styles
// enqueued at the same default priority, insertion order (which can go
// either way depending on exactly when the theme's own wp_enqueue_scripts
// callback is registered) decided the tie. Enqueueing this late guarantees
// our stylesheet prints after the theme's, so on any remaining specificity
// tie we win instead of leaving it to chance.
add_action('wp_enqueue_scripts', [Shopify_Bridge_Elementor::class, 'enqueue_styles'], 100);

add_action('admin_menu', [Shopify_Bridge_Admin_Page::class, 'register_menu']);
add_action('init', [Shopify_Bridge_Metafield_Debug_Box::class, 'register']);
add_action('admin_post_ns_bridge_save_settings', [Shopify_Bridge_Admin_Page::class, 'handle_save_settings']);
add_action('admin_post_ns_bridge_test_connection', [Shopify_Bridge_Admin_Page::class, 'handle_test_connection']);
add_action('admin_post_ns_bridge_test_metaobject', [Shopify_Bridge_Admin_Page::class, 'handle_test_metaobject']);
add_action('admin_post_ns_bridge_test_scopes', [Shopify_Bridge_Admin_Page::class, 'handle_test_scopes']);
add_action('admin_post_ns_bridge_setup_definitions', [Shopify_Bridge_Admin_Page::class, 'handle_setup_definitions']);
add_action('admin_post_ns_bridge_run_reconciliation', [Shopify_Bridge_Admin_Page::class, 'handle_run_reconciliation']);
add_action('admin_post_ns_bridge_register_webhooks', [Shopify_Bridge_Admin_Page::class, 'handle_register_webhooks']);
add_action('admin_post_ns_bridge_merge_duplicate_categories', [Shopify_Bridge_Admin_Page::class, 'handle_merge_duplicate_categories']);
add_action('admin_post_ns_bridge_batch_step', [Shopify_Bridge_Admin_Page::class, 'handle_batch_step']);
add_action('admin_post_ns_bridge_batch_reset', [Shopify_Bridge_Admin_Page::class, 'handle_batch_reset']);
