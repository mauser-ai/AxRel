<?php
defined('ABSPATH') || exit;

/** Renders custom.product_ingredients — one entry per Product Ingredient metaobject. */
class NS_Bridge_Widget_Ingredients extends NS_Bridge_Elementor_Widget_Base {

	public function get_name() {
		return 'ns_bridge_ingredients';
	}

	public function get_title() {
		return 'NS Bridge — Ingredients';
	}

	public function get_icon() {
		return 'eicon-flask';
	}

	protected function meta_field_key() {
		return 'product_ingredients';
	}

	protected function wrapper_class() {
		return 'ns-bridge-ingredients';
	}

	protected function register_controls() {}

	protected function render_item(array $item, $index) {
		echo '<div class="ns-bridge-ingredient">';
		if (!empty($item['image'])) {
			printf('<img class="ns-bridge-ingredient-image" src="%s" alt="%s" loading="lazy">', esc_url($item['image']), esc_attr($item['name'] ?? ''));
		}
		echo '<div class="ns-bridge-ingredient-body">';
		printf(
			'<h4 class="ns-bridge-ingredient-name">%s%s</h4>',
			esc_html($item['name'] ?? ''),
			!empty($item['percentage']) ? ' <span class="ns-bridge-ingredient-percentage">' . esc_html($item['percentage']) . '</span>' : ''
		);
		if (!empty($item['subtitle'])) {
			printf('<p class="ns-bridge-ingredient-subtitle">%s</p>', esc_html($item['subtitle']));
		}
		echo '<div class="ns-bridge-ingredient-description">' . wp_kses_post($item['description'] ?? '') . '</div>';
		echo '</div></div>';
	}
}
