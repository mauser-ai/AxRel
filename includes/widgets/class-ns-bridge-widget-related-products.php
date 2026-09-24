<?php
defined('ABSPATH') || exit;

/**
 * Renders either custom.complete_your_routine or custom.something_else_products
 * — the two Shopify "related products" lists — as WooCommerce product cards
 * (image, title, price), linking to each product's own WordPress page.
 * Doesn't extend NS_Bridge_Elementor_Widget_Base: its items are plain WP
 * post IDs (already resolved by NS_Bridge_Metafield_Sync), not associative
 * arrays, and which meta key to read is itself a widget control.
 */
class NS_Bridge_Widget_Related_Products extends \Elementor\Widget_Base {

	public function get_name() {
		return 'ns_bridge_related_products';
	}

	public function get_title() {
		return 'NS Bridge — Prodotti correlati';
	}

	public function get_icon() {
		return 'eicon-products';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('source', [
			'label'   => 'Sorgente (sezione Shopify)',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'complete_your_routine',
			'options' => [
				'complete_your_routine'   => 'Complete Your Routine',
				'something_else_products' => 'Something Else?',
			],
		]);
		$this->end_controls_section();
	}

	protected function render() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$source_key = $this->get_settings_for_display('source');
		$source_key = in_array($source_key, ['complete_your_routine', 'something_else_products'], true)
			? $source_key
			: 'complete_your_routine';

		$raw = get_post_meta($post_id, NS_Bridge_Metafield_Sync::meta_key($source_key), true);
		$ids = json_decode((string) $raw, true);

		if (!is_array($ids) || !$ids) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">NS Bridge — Prodotti correlati: nessun prodotto collegato per questa sorgente.</p>';
			}
			return;
		}

		echo '<div class="ns-bridge-related-products">';
		foreach ($ids as $related_post_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($related_post_id) : null;
			if (!$product) {
				continue;
			}
			printf(
				'<a class="ns-bridge-related-product" href="%s"><span class="ns-bridge-related-product-image">%s</span><span class="ns-bridge-related-product-title">%s</span><span class="ns-bridge-related-product-price">%s</span></a>',
				esc_url(get_permalink($related_post_id)),
				wp_kses_post($product->get_image('medium')),
				esc_html($product->get_name()),
				wp_kses_post($product->get_price_html())
			);
		}
		echo '</div>';
	}
}
