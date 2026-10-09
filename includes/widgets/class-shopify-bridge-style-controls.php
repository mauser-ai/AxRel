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

	/** Background + border + radius + shadow + padding for a container selector (card, wrapper...). $condition: optional Elementor control-condition array, so the section only shows when e.g. another control is set to a given value. */
	protected function register_box_style_section($id, $label, $selector, $condition = []) {
		$section_args = [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		];
		if ($condition) {
			$section_args['condition'] = $condition;
		}
		$this->start_controls_section($id, $section_args);

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

	/**
	 * Self-contained CONTENT section: an open/closed indicator (chevron,
	 * arrow, +/-...) for accordion-style widgets (native <details>). Two
	 * behaviours: 'rotate' (one icon, rotated 90&deg; via CSS when the
	 * nearest <details> is open) or 'swap' (two different icons, toggled by
	 * the same [open] state) — e.g. the Figma shows both: a rotating arrow
	 * for "Benefits and Actives", a +/&minus; swap for "How to Use Steps".
	 * Pair with register_arrow_style_section() and call render_arrow_control()
	 * from render()/render_item() to actually output it.
	 */
	protected function register_arrow_controls($default_position = 'before') {
		$this->start_controls_section('arrow_content_section', ['label' => 'Freccia apertura/chiusura']);

		$this->add_control('show_arrow', [
			'label'   => 'Mostra freccia',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->add_control('arrow_mode', [
			'label'     => 'Comportamento',
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'rotate',
			'options'   => ['rotate' => 'Ruota (es. da &rarr; a &darr;)', 'swap' => 'Cambia icona (es. da + a &minus;)'],
			'condition' => ['show_arrow' => 'yes'],
		]);
		$this->add_control('arrow_icon', [
			'label'     => 'Icona (chiusa)',
			'type'      => \Elementor\Controls_Manager::ICONS,
			'default'   => ['value' => 'fas fa-chevron-right', 'library' => 'fa-solid'],
			'condition' => ['show_arrow' => 'yes'],
		]);
		$this->add_control('arrow_icon_open', [
			'label'     => 'Icona (aperta)',
			'type'      => \Elementor\Controls_Manager::ICONS,
			'default'   => ['value' => 'fas fa-minus', 'library' => 'fa-solid'],
			'condition' => ['show_arrow' => 'yes', 'arrow_mode' => 'swap'],
		]);
		$this->add_control('arrow_position', [
			'label'     => 'Posizione',
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => $default_position,
			'options'   => ['before' => 'Prima del titolo', 'after' => 'Dopo il titolo'],
			'condition' => ['show_arrow' => 'yes'],
		]);

		$this->end_controls_section();
	}

	/** Self-contained STYLE section matching register_arrow_controls() above. $title_selector is whatever flex container holds the arrow + title, for the gap control. */
	protected function register_arrow_style_section($title_selector) {
		$this->start_controls_section('arrow_style_section', [
			'label'     => 'Stile — Freccia',
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => ['show_arrow' => 'yes'],
		]);
		$this->add_control('arrow_color', [
			'label'     => 'Colore freccia',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-arrow' => 'color: {{VALUE}};'],
		]);
		$this->add_responsive_control('arrow_size', [
			'label'      => 'Dimensione freccia',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 6, 'max' => 48]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-arrow svg, {{WRAPPER}} .ns-bridge-arrow i' => 'font-size: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};'],
		]);
		$this->add_responsive_control('arrow_gap', [
			'label'      => 'Spazio tra freccia e titolo',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 0, 'max' => 60]],
			'selectors'  => ['{{WRAPPER}} ' . $title_selector => 'gap: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();
	}

	/** Outputs the arrow markup per the settings registered by register_arrow_controls() — call from render()/render_item(), wherever the arrow should sit relative to the title text. */
	protected function render_arrow_control() {
		if ($this->get_settings_for_display('show_arrow') !== 'yes') {
			return;
		}
		$icon_closed = $this->get_settings_for_display('arrow_icon');
		$icon_open   = $this->get_settings_for_display('arrow_icon_open');

		if ($this->get_settings_for_display('arrow_mode') === 'swap') {
			echo '<span class="ns-bridge-arrow ns-bridge-arrow-closed">';
			\Elementor\Icons_Manager::render_icon($icon_closed, ['aria-hidden' => 'true']);
			echo '</span><span class="ns-bridge-arrow ns-bridge-arrow-open">';
			\Elementor\Icons_Manager::render_icon($icon_open, ['aria-hidden' => 'true']);
			echo '</span>';
			return;
		}
		echo '<span class="ns-bridge-arrow ns-bridge-arrow-rotate">';
		\Elementor\Icons_Manager::render_icon($icon_closed, ['aria-hidden' => 'true']);
		echo '</span>';
	}

	/** True if the arrow should render after the title text rather than before — read this from render() to decide draw order. */
	protected function arrow_goes_after() {
		return $this->get_settings_for_display('arrow_position') === 'after';
	}

	/** Width/height constraint slider, typically for an image/media element. Self-contained, same reason as above. $condition: optional Elementor control-condition array. */
	protected function register_size_control($id, $label, $selector, $css_prop = 'max-width', $max = 1000, $condition = []) {
		$section_args = [
			'label' => $label,
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		];
		if ($condition) {
			$section_args['condition'] = $condition;
		}
		$this->start_controls_section($id . '_section', $section_args);

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
