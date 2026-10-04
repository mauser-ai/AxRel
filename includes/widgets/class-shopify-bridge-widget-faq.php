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
		$this->register_range_control();
		$this->register_arrow_controls('after');
		$this->register_text_style_section('question_style', 'Stile — Domanda', '.ns-bridge-faq-question');
		$this->register_text_style_section('answer_style', 'Stile — Risposta', '.ns-bridge-faq-answer');
		$this->register_arrow_style_section('.ns-bridge-faq-question');
		$this->register_box_style_section('container_style', 'Stile — Riquadro generale (sfondo)', '.ns-bridge-faq');
		$this->register_box_style_section('item_style', 'Stile — Riquadro voce (es. divisore sotto ogni domanda)', '.ns-bridge-faq-item');
		$this->register_columns_control('columns', 'Colonne (es. 3 come nel design)', '.ns-bridge-faq', 3);
		$this->register_spacing_control('item_spacing', 'Spazio tra le colonne', '.ns-bridge-faq', 'gap');
	}

	protected function render_item(array $item, $index) {
		$arrow_after = $this->arrow_goes_after();
		echo '<details class="ns-bridge-faq-item">';
		echo '<summary class="ns-bridge-faq-question">';
		if (!$arrow_after) {
			$this->render_arrow_control();
		}
		echo '<span>' . esc_html($item['question'] ?? '') . '</span>';
		if ($arrow_after) {
			$this->render_arrow_control();
		}
		echo '</summary>';
		echo '<div class="ns-bridge-faq-answer">' . wp_kses_post($item['answer'] ?? '') . '</div>';
		echo '</details>';
	}
}
