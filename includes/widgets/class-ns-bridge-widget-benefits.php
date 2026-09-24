<?php
defined('ABSPATH') || exit;

/** Renders custom.product_benefits — one card per Product Benefit metaobject. */
class NS_Bridge_Widget_Benefits extends NS_Bridge_Elementor_Widget_Base {

	public function get_name() {
		return 'ns_bridge_benefits';
	}

	public function get_title() {
		return 'NS Bridge — Benefits';
	}

	public function get_icon() {
		return 'eicon-info-circle-o';
	}

	protected function meta_field_key() {
		return 'product_benefits';
	}

	protected function wrapper_class() {
		return 'ns-bridge-benefits';
	}

	protected function register_controls() {}

	protected function render_item(array $item, $index) {
		echo '<div class="ns-bridge-benefit">';
		if (!empty($item['image'])) {
			printf('<img class="ns-bridge-benefit-image" src="%s" alt="%s" loading="lazy">', esc_url($item['image']), esc_attr($item['title'] ?? ''));
		}
		printf('<h4 class="ns-bridge-benefit-title">%s</h4>', esc_html($item['title'] ?? ''));
		echo '<div class="ns-bridge-benefit-description">' . wp_kses_post($item['description'] ?? '') . '</div>';
		echo '</div>';
	}
}
