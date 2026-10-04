<?php
defined('ABSPATH') || exit;

/**
 * Shared behaviour for every Shopify Bridge Elementor widget that renders a
 * repeatable list: reads the JSON list postmeta written by
 * Shopify_Bridge_Metafield_Sync off the current WooCommerce product and loops
 * over it. Concrete widgets only need to say which meta key, and how to
 * render one item.
 */
abstract class Shopify_Bridge_Elementor_Widget_Base extends \Elementor\Widget_Base {

	abstract protected function meta_field_key();
	abstract protected function render_item(array $item, $index);
	abstract protected function wrapper_class();

	public function get_categories() {
		return ['ns-bridge'];
	}

	/**
	 * Adds "Mostra dal numero __ al numero __" controls, so the SAME list
	 * (e.g. 4 Clinical Results) can be split across several widget instances
	 * placed wherever the layout needs them — e.g. 2 on the left of an
	 * image and 2 on the right, each its own Elementor column, instead of
	 * one block that always shows every item together. Call this from a
	 * concrete widget's register_controls() to opt in; without it,
	 * get_meta_items() below just returns the full list as before.
	 */
	protected function register_range_control() {
		$this->start_controls_section('range_section', ['label' => 'Selezione elementi']);

		$this->add_control('range_from', [
			'label'       => 'Mostra dal numero',
			'type'        => \Elementor\Controls_Manager::NUMBER,
			'min'         => 1,
			'default'     => 1,
			'description' => 'Posizione (1 = il primo elemento della lista).',
		]);

		$this->add_control('range_to', [
			'label'       => 'Fino al numero',
			'type'        => \Elementor\Controls_Manager::NUMBER,
			'min'         => 1,
			'description' => 'Lascia vuoto per mostrare fino all\'ultimo elemento.',
		]);

		$this->end_controls_section();
	}

	protected function get_meta_items() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return [];
		}
		$raw   = get_post_meta($post_id, Shopify_Bridge_Metafield_Sync::meta_key($this->meta_field_key()), true);
		$items = json_decode((string) $raw, true);
		$items = is_array($items) ? $items : [];

		$from_raw = $this->get_settings_for_display('range_from');
		$to_raw   = $this->get_settings_for_display('range_to');
		if ($from_raw === null && $to_raw === null) {
			return $items; // register_range_control() wasn't called on this widget — no slicing.
		}

		$from = max(1, (int) ($from_raw ?: 1));
		$to   = ($to_raw === '' || $to_raw === null) ? count($items) : (int) $to_raw;

		return array_slice($items, $from - 1, max(0, $to - $from + 1));
	}

	protected function render() {
		$items = $this->get_meta_items();

		if (!$items) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				printf(
					'<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">%s: nessun dato per questo prodotto (o non sei su una pagina prodotto).</p>',
					esc_html($this->get_title())
				);
			}
			return;
		}

		printf('<div class="%s">', esc_attr($this->wrapper_class()));
		foreach ($items as $index => $item) {
			if (is_array($item)) {
				$this->render_item($item, $index);
			}
		}
		echo '</div>';
	}
}
