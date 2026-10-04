<?php
defined('ABSPATH') || exit;

/**
 * Registers the Shopify Bridge widget category + widgets with Elementor, so the
 * repeatable sections from the "Guida Shopify Product Page" brief (Accordion,
 * Clinical Results, Benefits, Ingredients, FAQ, Related Products) can be
 * dragged into the single reusable Elementor product template it describes.
 * Hooked unconditionally in the main plugin file — elementor/widgets/register
 * and elementor/elements/categories_registered simply never fire if
 * Elementor isn't active, so no guard is needed here.
 */
class Shopify_Bridge_Elementor {

	public static function register_category($elements_manager) {
		$elements_manager->add_category('ns-bridge', [
			'title' => 'Shopify Bridge',
			'icon'  => 'eicon-integration',
		]);
	}

	public static function register_widgets($widgets_manager) {
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-style-controls.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-product-cards.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-elementor-widget-base.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-accordion.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-clinical-results.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-faq.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-press-quote.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-routine-tabs.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-also-considered.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-buy-button.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-product-gallery.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-widget-side-nav.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-single-field-widget-base.php';
		require_once SHOPIFYBRIDGE_PLUGIN_DIR . 'includes/widgets/class-shopify-bridge-single-field-widgets.php';

		$widgets_manager->register(new Shopify_Bridge_Widget_Buy_Button());
		$widgets_manager->register(new Shopify_Bridge_Widget_Product_Gallery());
		$widgets_manager->register(new Shopify_Bridge_Widget_Side_Nav());
		$widgets_manager->register(new Shopify_Bridge_Widget_Accordion());
		$widgets_manager->register(new Shopify_Bridge_Widget_Clinical_Results());
		$widgets_manager->register(new Shopify_Bridge_Widget_Faq());
		$widgets_manager->register(new Shopify_Bridge_Widget_Press_Quote());
		$widgets_manager->register(new Shopify_Bridge_Widget_Routine_Tabs());
		$widgets_manager->register(new Shopify_Bridge_Widget_Also_Considered());
		$widgets_manager->register(new Shopify_Bridge_Widget_Hero_Subtitle());
		$widgets_manager->register(new Shopify_Bridge_Widget_The_Science_Text());
		$widgets_manager->register(new Shopify_Bridge_Widget_Benefits_Intro_Text());
		$widgets_manager->register(new Shopify_Bridge_Widget_Ingredients_Intro_Text());
		$widgets_manager->register(new Shopify_Bridge_Widget_Clinical_Title());
		$widgets_manager->register(new Shopify_Bridge_Widget_Clinical_Image());
		$widgets_manager->register(new Shopify_Bridge_Widget_Clinical_Description());
		$widgets_manager->register(new Shopify_Bridge_Widget_Routine_Title());
	}

	public static function enqueue_styles() {
		wp_enqueue_style(
			'ns-bridge-widgets',
			plugins_url('assets/css/ns-bridge-widgets.css', SHOPIFYBRIDGE_PLUGIN_FILE),
			[],
			filemtime(SHOPIFYBRIDGE_PLUGIN_DIR . 'assets/css/ns-bridge-widgets.css')
		);
	}
}
