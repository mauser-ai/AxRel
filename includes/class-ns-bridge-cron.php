<?php
defined('ABSPATH') || exit;

/**
 * WP-Cron fallback for the daily reconciliation. Fires even if nobody sets
 * up a real system crontab, but is best complemented by one (see README) —
 * WP-Cron only runs on incoming site traffic, which a low-traffic storefront
 * can't guarantee at a fixed hour.
 */
class NS_Bridge_Cron {

	const HOOK = 'ns_bridge_daily_reconciliation';

	public static function activate() {
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::HOOK);
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook(self::HOOK);
	}

	public static function register() {
		add_action(self::HOOK, [NS_Bridge_Reconciliation::class, 'run']);
	}
}
