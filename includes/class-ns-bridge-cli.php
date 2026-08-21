<?php
defined('ABSPATH') || exit;

/**
 * WP-CLI commands, meant to be driven by a real system crontab entry rather
 * than relying on WP-Cron's traffic-triggered pseudo-cron (see README).
 *
 *   wp ns-bridge reconcile
 *   wp ns-bridge register-webhooks
 */
class NS_Bridge_CLI {

	public static function reconcile($args, $assoc_args) {
		$stats = NS_Bridge_Reconciliation::run();
		WP_CLI::success('Riconciliazione completata: ' . wp_json_encode($stats));
	}

	public static function register_webhooks($args, $assoc_args) {
		$result = NS_Bridge_Webhook_Registrar::ensure_registered();
		WP_CLI::success('Webhook Shopify: ' . wp_json_encode($result));
	}
}

WP_CLI::add_command('ns-bridge reconcile', [NS_Bridge_CLI::class, 'reconcile']);
WP_CLI::add_command('ns-bridge register-webhooks', [NS_Bridge_CLI::class, 'register_webhooks']);
