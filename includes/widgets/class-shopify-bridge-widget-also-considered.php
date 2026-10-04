<?php
defined('ABSPATH') || exit;

/**
 * Renders custom.also_considered_products as WooCommerce product cards
 * (image, title, price), linking to each product's own WordPress page.
 * Single source now — "Complete Your Routine" moved to the richer
 * Routine Tabs structure (class-shopify-bridge-widget-routine-tabs.php).
 */
class Shopify_Bridge_Widget_Also_Considered extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'shopify_bridge_also_considered';
	}

	public function get_title() {
		return 'Shopify Bridge — Also Considered';
	}

	public function get_icon() {
		return 'eicon-products';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('show_rating', [
			'label'   => 'Mostra valutazione (stelle)',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->add_control('show_separator', [
			'label'   => 'Mostra separatore "+" tra le card',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'no',
		]);
		$this->end_controls_section();

		$this->register_box_style_section('container_style', 'Stile — Riquadro generale (sfondo)', '.ns-bridge-product-cards');
		$this->register_spacing_control('item_spacing', 'Spazio tra le card', '.ns-bridge-product-cards', 'gap');
		$this->register_text_style_section('title_style', 'Stile — Nome prodotto', '.ns-bridge-product-card-title');
		$this->register_text_style_section('price_style', 'Stile — Prezzo', '.ns-bridge-product-card-price');
	}

	protected function render() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$raw = get_post_meta($post_id, Shopify_Bridge_Metafield_Sync::meta_key('also_considered_products'), true);
		$ids = json_decode((string) $raw, true);

		if (!is_array($ids) || !$ids) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">Shopify Bridge — Also Considered: nessun prodotto collegato per questo prodotto.</p>';
			}
			return;
		}

		Shopify_Bridge_Product_Cards::render(
			$ids,
			'ns-bridge-product-cards',
			'medium',
			$this->get_settings_for_display('show_rating') === 'yes',
			$this->get_settings_for_display('show_separator') === 'yes'
		);
	}
}
