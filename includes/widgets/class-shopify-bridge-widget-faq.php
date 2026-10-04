<?php
defined('ABSPATH') || exit;

/** Renders custom.faqs as native <details>/<summary> — same zero-JS pattern as the accordion. */
class Shopify_Bridge_Widget_Faq extends Shopify_Bridge_Elementor_Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'ns_bridge_faq';
	}

	public function get_title() {
		return 'Shopify Bridge — FAQ';
	}

	public function get_icon() {
		return 'eicon-help-o';
	}

	protected function meta_field_key() {
		return 'faqs';
	}

	protected function wrapper_class() {
		return 'ns-bridge-faq';
	}

	protected function register_controls() {
		$this->register_text_style_section('question_style', 'Stile — Domanda', '.ns-bridge-faq-question');
		$this->register_text_style_section('answer_style', 'Stile — Risposta', '.ns-bridge-faq-answer');
		$this->register_box_style_section('item_style', 'Stile — Riquadro', '.ns-bridge-faq-item');
	}

	protected function render_item(array $item, $index) {
		echo '<details class="ns-bridge-faq-item">';
		echo '<summary class="ns-bridge-faq-question">' . esc_html($item['question'] ?? '') . '</summary>';
		echo '<div class="ns-bridge-faq-answer">' . wp_kses_post($item['answer'] ?? '') . '</div>';
		echo '</details>';
	}
}
