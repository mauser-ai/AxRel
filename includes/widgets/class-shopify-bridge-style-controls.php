<?php
defined('ABSPATH') || exit;

/**
 * Shared Elementor Style-tab controls, mixed into widgets via `use` so every
 * NS Bridge widget offers real native design controls (typography, color,
 * background, border, shadow, spacing) instead of fixed/hardcoded looks —
 * without re-declaring the same Group_Control boilerplate in every widget.
 *
 * Usage inside a widget's register_controls():
 *   $this->register_text_style_section('title_style', 'Stile — Titolo', '.my-title');
 *   $this->register_box_style_section('card_style', 'Stile — Scheda', '.my-card');
 *   $this->register_spacing_control('gap', 'Spaziatura tra elementi', '.my-list', 'gap');
 */
trait Shopify_Bridge_Style_Controls {

	/** Typography + text color + alignment for a text-ish selector (title, paragraph, label...). */
	protected function register_text_style_section($id, $label, $selector) {
		$this->start_controls_section($id, [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
			'name'     => $id . '_typography',
			'selector' => '{{WRAPPER}} ' . $selector,
		]);

		$this->add_control($id . '_color', [
			'label'     => 'Colore testo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} ' . $selector => 'color: {{VALUE}};'],
		]);

		$this->add_responsive_control($id . '_align', [
			'label'     => 'Allineamento',
			'type'      => \Elementor\Controls_Manager::CHOOSE,
			'options'   => [
				'left'   => ['title' => 'Sinistra', 'icon' => 'eicon-text-align-left'],
				'center' => ['title' => 'Centro', 'icon' => 'eicon-text-align-center'],
				'right'  => ['title' => 'Destra', 'icon' => 'eicon-text-align-right'],
			],
			'selectors' => ['{{WRAPPER}} ' . $selector => 'text-align: {{VALUE}};'],
		]);

		$this->end_controls_section();
	}

	/** Background + border + radius + shadow + padding for a container selector (card, wrapper...). */
	protected function register_box_style_section($id, $label, $selector) {
		$this->start_controls_section($id, [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_group_control(\Elementor\Group_Control_Background::get_type(), [
			'name'     => $id . '_background',
			'types'    => ['classic', 'gradient'],
			'selector' => '{{WRAPPER}} ' . $selector,
		]);

		$this->add_responsive_control($id . '_padding', [
			'label'      => 'Padding',
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', '%', 'em'],
			'selectors'  => ['{{WRAPPER}} ' . $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
		]);

		$this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
			'name'     => $id . '_border',
			'selector' => '{{WRAPPER}} ' . $selector,
		]);

		$this->add_responsive_control($id . '_radius', [
			'label'      => 'Raggio angoli',
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', '%'],
			'selectors'  => ['{{WRAPPER}} ' . $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
		]);

		$this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
			'name'     => $id . '_shadow',
			'selector' => '{{WRAPPER}} ' . $selector,
		]);

		$this->end_controls_section();
	}

	/**
	 * A single responsive slider control for gaps/margins on a given selector
	 * + CSS property. Self-contained (opens/closes its own Style section) so
	 * it's safe to call on its own, not just chained after another
	 * register_*_section() call.
	 */
	protected function register_spacing_control($id, $label, $selector, $css_prop = 'gap', $max = 100) {
		$this->start_controls_section($id . '_section', [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_responsive_control($id, [
			'label'      => $label,
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px', 'em', '%'],
			'range'      => ['px' => ['min' => 0, 'max' => $max]],
			'selectors'  => ['{{WRAPPER}} ' . $selector => $css_prop . ': {{SIZE}}{{UNIT}};'],
		]);

		$this->end_controls_section();
	}

	/** Number-of-columns select, turning a stacked list into a responsive grid (e.g. a 2 or 3-column accordion/FAQ layout). Self-contained, same reason as above. */
	protected function register_columns_control($id, $label, $selector, $max = 4) {
		$options = ['1' => '1 (lista verticale)'];
		for ($n = 2; $n <= $max; $n++) {
			$options[(string) $n] = (string) $n;
		}

		$this->start_controls_section($id . '_section', [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_responsive_control($id, [
			'label'     => $label,
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => '1',
			'options'   => $options,
			'selectors' => [
				'{{WRAPPER}} ' . $selector => 'display: grid; grid-template-columns: repeat({{VALUE}}, 1fr); align-items: start;',
			],
		]);

		$this->end_controls_section();
	}

	/** Width/height constraint slider, typically for an image/media element. Self-contained, same reason as above. */
	protected function register_size_control($id, $label, $selector, $css_prop = 'max-width', $max = 1000) {
		$this->start_controls_section($id . '_section', [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_responsive_control($id, [
			'label'      => $label,
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px', '%'],
			'range'      => ['px' => ['min' => 0, 'max' => $max], '%' => ['min' => 0, 'max' => 100]],
			'selectors'  => ['{{WRAPPER}} ' . $selector => $css_prop . ': {{SIZE}}{{UNIT}};'],
		]);

		$this->end_controls_section();
	}
}
