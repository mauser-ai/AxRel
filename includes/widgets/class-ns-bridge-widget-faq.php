<?php
defined('ABSPATH') || exit;

/** Renders custom.product_faqs as native <details>/<summary> — same zero-JS pattern as the accordion. */
class NS_Bridge_Widget_Faq extends NS_Bridge_Elementor_Widget_Base {

	public function get_name() {
		return 'ns_bridge_faq';
	}

	public function get_title() {
		return 'NS Bridge — FAQ';
	}

	public function get_icon() {
		return 'eicon-help-o';
	}

	protected function meta_field_key() {
		return 'product_faqs';
	}

	protected function wrapper_class() {
		return 'ns-bridge-faq';
	}

	protected function register_controls() {}

	protected function render_item(array $item, $index) {
		echo '<details class="ns-bridge-faq-item">';
		echo '<summary class="ns-bridge-faq-question">' . esc_html($item['question'] ?? '') . '</summary>';
		echo '<div class="ns-bridge-faq-answer">' . wp_kses_post($item['answer'] ?? '') . '</div>';
		echo '</details>';
	}
}
