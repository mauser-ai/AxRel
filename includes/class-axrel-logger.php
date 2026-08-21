<?php
defined('ABSPATH') || exit;

class Axrel_Logger {

	const OPTION_KEY = 'axrel_sync_log';
	const MAX_ENTRIES = 100;

	public static function log($event, $message, $context = null) {
		error_log(sprintf('[AxRel] %s: %s', $event, $message));

		$entries = get_option(self::OPTION_KEY, []);
		$entries[] = [
			'time'    => current_time('mysql'),
			'event'   => $event,
			'message' => $message,
		];
		if (count($entries) > self::MAX_ENTRIES) {
			$entries = array_slice($entries, -self::MAX_ENTRIES);
		}
		update_option(self::OPTION_KEY, $entries, false);
	}

	public static function recent($limit = 20) {
		return array_slice(array_reverse(get_option(self::OPTION_KEY, [])), 0, $limit);
	}
}
