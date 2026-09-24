<?php
defined('ABSPATH') || exit;

/**
 * Registers the NS Bridge widget category + widgets with Elementor, so the
 * repeatable sections from the "Guida Shopify Product Page" brief (Accordion,
 * Clinical Results, Benefits, Ingredients, FAQ, Related Products) can be
 * dragged into the single reusable Elementor product template it describes.
 * Hooked unconditionally in the main plugin file — elementor/widgets/register
 * and elementor/elements/categories_registered simply never fire if
 * Elementor isn't active, so no guard is needed here.
 */
class NS_Bridge_Elementor {

	public static function register_category($elements_manager) {
		$elements_manager->add_category('ns-bridge', [
			'title' => 'NS Bridge',
			'icon'  => 'eicon-integration',
		]);
	}

	public static function register_widgets($widgets_manager) {
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-elementor-widget-base.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-accordion.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-clinical-results.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-benefits.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-ingredients.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-faq.php';
		require_once NSBRIDGE_PLUGIN_DIR . 'includes/widgets/class-ns-bridge-widget-related-products.php';

		$widgets_manager->register(new NS_Bridge_Widget_Accordion());
		$widgets_manager->register(new NS_Bridge_Widget_Clinical_Results());
		$widgets_manager->register(new NS_Bridge_Widget_Benefits());
		$widgets_manager->register(new NS_Bridge_Widget_Ingredients());
		$widgets_manager->register(new NS_Bridge_Widget_Faq());
		$widgets_manager->register(new NS_Bridge_Widget_Related_Products());
	}

	public static function enqueue_styles() {
		wp_enqueue_style(
			'ns-bridge-widgets',
			plugins_url('assets/css/ns-bridge-widgets.css', NSBRIDGE_PLUGIN_FILE),
			[],
			filemtime(NSBRIDGE_PLUGIN_DIR . 'assets/css/ns-bridge-widgets.css')
		);
	}
}
