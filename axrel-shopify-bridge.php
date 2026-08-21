<?php
/**
 * Plugin Name: AxRel - Shopify to WordPress Bridge
 * Description: Sincronizza i prodotti Shopify su WordPress in tempo reale via webhook, con riconciliazione giornaliera per garantire coerenza e stabilita'. WordPress resta lo storefront pubblico e indicizzabile, Shopify il commerce engine; il checkout resta sempre su Shopify.
 * Version: 0.1.0
 * Text Domain: axrel-shopify-bridge
 */

defined('ABSPATH') || exit;

define('AXREL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AXREL_PLUGIN_FILE', __FILE__);

require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-settings.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-logger.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-shopify-client.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-product-sync.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-webhook-handler.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-webhook-registrar.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-reconciliation.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-cron.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-post-type.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-seo.php';
require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-admin-page.php';

if (defined('WP_CLI') && WP_CLI) {
	require_once AXREL_PLUGIN_DIR . 'includes/class-axrel-cli.php';
}

register_activation_hook(__FILE__, function () {
	Axrel_Post_Type::register();
	flush_rewrite_rules();
	Axrel_Cron::activate();
});

register_deactivation_hook(__FILE__, function () {
	Axrel_Cron::deactivate();
	flush_rewrite_rules();
});

add_action('init', [Axrel_Post_Type::class, 'register']);
add_action('init', [Axrel_Cron::class, 'register']);
add_action('init', [Axrel_SEO::class, 'register']);
add_action('rest_api_init', [Axrel_Webhook_Handler::class, 'register_routes']);

add_action('admin_menu', [Axrel_Admin_Page::class, 'register_menu']);
add_action('admin_post_axrel_save_settings', [Axrel_Admin_Page::class, 'handle_save_settings']);
add_action('admin_post_axrel_test_connection', [Axrel_Admin_Page::class, 'handle_test_connection']);
add_action('admin_post_axrel_run_reconciliation', [Axrel_Admin_Page::class, 'handle_run_reconciliation']);
add_action('admin_post_axrel_register_webhooks', [Axrel_Admin_Page::class, 'handle_register_webhooks']);
