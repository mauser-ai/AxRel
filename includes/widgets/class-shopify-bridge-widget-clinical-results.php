<?php
defined('ABSPATH') || exit;

/** Renders custom.clinical_results — one counter per Clinical Result metaobject (prefix + value + suffix + label, no description field). */
class Shopify_Bridge_Widget_Clinical_Results extends Shopify_Bridge_Elementor_Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'ns_bridge_clinical_results';
	}

	public function get_title() {
		return 'Shopify Bridge — Clinical Results';
	}

	public function get_icon() {
		return 'eicon-counter';
	}

	protected function meta_field_key() {
		return 'clinical_results';
	}

	protected function wrapper_class() {
		return 'ns-bridge-clinical-results';
	}

	protected function register_controls() {
		$this->register_spacing_control('item_spacing', 'Spazio tra i contatori', '.ns-bridge-clinical-results', 'gap');
		$this->register_text_style_section('value_style', 'Stile — Valore', '.ns-bridge-clinical-value');
		$this->register_text_style_section('label_style', 'Stile — Etichetta', '.ns-bridge-clinical-label');
	}

	protected function render_item(array $item, $index) {
		printf(
			'<div class="ns-bridge-clinical-result"><span class="ns-bridge-clinical-value">%s%s%s</span><span class="ns-bridge-clinical-label">%s</span></div>',
			esc_html($item['prefix'] ?? ''),
			esc_html($item['value'] ?? ''),
			esc_html($item['suffix'] ?? ''),
			esc_html($item['label'] ?? '')
		);
	}
}
