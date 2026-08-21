<?php
defined('ABSPATH') || exit;

/**
 * WP-CLI commands, meant to be driven by a real system crontab entry rather
 * than relying on WP-Cron's traffic-triggered pseudo-cron (see README).
 *
 *   wp axrel reconcile
 *   wp axrel register-webhooks
 */
class Axrel_CLI {

	public static function reconcile($args, $assoc_args) {
		$stats = Axrel_Reconciliation::run();
		WP_CLI::success('Riconciliazione completata: ' . wp_json_encode($stats));
	}

	public static function register_webhooks($args, $assoc_args) {
		$result = Axrel_Webhook_Registrar::ensure_registered();
		WP_CLI::success('Webhook Shopify: ' . wp_json_encode($result));
	}
}

WP_CLI::add_command('axrel reconcile', [Axrel_CLI::class, 'reconcile']);
WP_CLI::add_command('axrel register-webhooks', [Axrel_CLI::class, 'register_webhooks']);
