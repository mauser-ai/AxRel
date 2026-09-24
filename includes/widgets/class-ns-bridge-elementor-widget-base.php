<?php
defined('ABSPATH') || exit;

/**
 * Shared behaviour for every NS Bridge Elementor widget that renders a
 * repeatable list: reads the JSON list postmeta written by
 * NS_Bridge_Metafield_Sync off the current WooCommerce product and loops
 * over it. Concrete widgets only need to say which meta key, and how to
 * render one item.
 */
abstract class NS_Bridge_Elementor_Widget_Base extends \Elementor\Widget_Base {

	abstract protected function meta_field_key();
	abstract protected function render_item(array $item, $index);
	abstract protected function wrapper_class();

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function get_meta_items() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return [];
		}
		$raw   = get_post_meta($post_id, NS_Bridge_Metafield_Sync::meta_key($this->meta_field_key()), true);
		$items = json_decode((string) $raw, true);
		return is_array($items) ? $items : [];
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
