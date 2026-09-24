<?php
defined('ABSPATH') || exit;

/** Renders custom.clinical_results — one counter per Clinical Result metaobject. */
class NS_Bridge_Widget_Clinical_Results extends NS_Bridge_Elementor_Widget_Base {

	public function get_name() {
		return 'ns_bridge_clinical_results';
	}

	public function get_title() {
		return 'NS Bridge — Clinical Results';
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

	protected function register_controls() {}

	protected function render_item(array $item, $index) {
		printf(
			'<div class="ns-bridge-clinical-result"><span class="ns-bridge-clinical-value">%s%s%s</span><span class="ns-bridge-clinical-label">%s</span>%s</div>',
			esc_html($item['prefix'] ?? ''),
			esc_html($item['value'] ?? ''),
			esc_html($item['suffix'] ?? ''),
			esc_html($item['label'] ?? ''),
			!empty($item['description']) ? '<p class="ns-bridge-clinical-description">' . esc_html($item['description']) . '</p>' : ''
		);
	}
}
