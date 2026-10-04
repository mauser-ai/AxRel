<?php
defined('ABSPATH') || exit;

/** Renders custom.press_quote — editorial quote(s) with author and press logo. */
class Shopify_Bridge_Widget_Press_Quote extends Shopify_Bridge_Elementor_Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'shopify_bridge_press_quote';
	}

	public function get_title() {
		return 'Shopify Bridge — Press Quote';
	}

	public function get_icon() {
		return 'eicon-blockquote';
	}

	protected function meta_field_key() {
		return 'press_quote';
	}

	protected function wrapper_class() {
		return 'ns-bridge-press-quotes';
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('only_first', [
			'label'       => 'Mostra solo la prima citazione',
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'yes',
			'description' => 'Disattiva se vuoi mostrarle tutte una sotto l\'altra.',
		]);
		$this->end_controls_section();

		$this->register_text_style_section('quote_style', 'Stile — Citazione', '.ns-bridge-press-quote-text');
		$this->register_text_style_section('author_style', 'Stile — Autore', '.ns-bridge-press-quote-author');
		$this->register_size_control('logo_width', 'Larghezza logo', '.ns-bridge-press-quote-logo', 'max-width', 400);
	}

	protected function get_meta_items() {
		$items = parent::get_meta_items();
		if ($this->get_settings_for_display('only_first') === 'yes' && $items) {
			return [$items[0]];
		}
		return $items;
	}

	protected function render_item(array $item, $index) {
		echo '<figure class="ns-bridge-press-quote">';
		if (!empty($item['logo']['url'])) {
			printf('<img class="ns-bridge-press-quote-logo" src="%s" alt="" loading="lazy">', esc_url($item['logo']['url']));
		}
		printf('<blockquote class="ns-bridge-press-quote-text">%s</blockquote>', esc_html($item['quote'] ?? ''));
		if (!empty($item['author'])) {
			printf('<figcaption class="ns-bridge-press-quote-author">%s</figcaption>', esc_html($item['author']));
		}
		echo '</figure>';
	}
}
