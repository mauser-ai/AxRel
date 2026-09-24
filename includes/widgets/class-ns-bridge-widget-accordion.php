<?php
defined('ABSPATH') || exit;

/** Renders custom.complex_accordions as native <details>/<summary> — expand/collapse with zero JS. */
class NS_Bridge_Widget_Accordion extends NS_Bridge_Elementor_Widget_Base {

	public function get_name() {
		return 'ns_bridge_accordion';
	}

	public function get_title() {
		return 'NS Bridge — Accordion';
	}

	public function get_icon() {
		return 'eicon-accordion';
	}

	protected function meta_field_key() {
		return 'complex_accordions';
	}

	protected function wrapper_class() {
		return 'ns-bridge-accordion';
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('title_tag', [
			'label'   => 'Tag titolo',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'h3',
			'options' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'],
		]);
		$this->end_controls_section();
	}

	protected function render_item(array $item, $index) {
		$tag = $this->get_settings_for_display('title_tag');
		$tag = in_array($tag, ['h2', 'h3', 'h4'], true) ? $tag : 'h3';

		echo '<details class="ns-bridge-accordion-item">';
		echo '<summary class="ns-bridge-accordion-title"><' . $tag . '>' . esc_html($item['title'] ?? '') . '</' . $tag . '></summary>';
		echo '<div class="ns-bridge-accordion-content">' . wp_kses_post($item['content'] ?? '') . '</div>';
		echo '</details>';
	}
}
