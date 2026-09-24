<?php
defined('ABSPATH') || exit;

/**
 * Renders the "Acquista su Shopify" button/variant-picker for the current
 * product, reusing NS_Bridge_Frontend::render_buy_on_shopify() as-is.
 *
 * Exists as its own widget instead of relying on Elementor Pro's native
 * WooCommerce "Add to Cart" widget: Elementor Pro's dedicated product
 * widgets tend to call WooCommerce's template functions directly rather
 * than going through the woocommerce_single_product_summary action —
 * which is exactly the hook NS_Bridge_Frontend uses to swap the native
 * add-to-cart form for the Shopify buy button. Using that native widget
 * in an Elementor template risks silently showing no buy button at all
 * (or, worse, no protection against a customer accidentally landing back
 * on a WooCommerce cart flow). This widget calls the replacement directly,
 * so it always works regardless of Elementor internals.
 */
class NS_Bridge_Widget_Buy_Button extends \Elementor\Widget_Base {

	public function get_name() {
		return 'ns_bridge_buy_button';
	}

	public function get_title() {
		return 'NS Bridge — Acquista su Shopify';
	}

	public function get_icon() {
		return 'eicon-cart-medium';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {}

	protected function render() {
		global $product;

		if (!$product instanceof WC_Product) {
			$post_id = get_the_ID();
			$product = $post_id ? wc_get_product($post_id) : null;
		}

		if (!$product) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">NS Bridge — Acquista su Shopify: nessun prodotto WooCommerce in questo contesto.</p>';
			}
			return;
		}

		NS_Bridge_Frontend::render_buy_on_shopify();
	}
}
