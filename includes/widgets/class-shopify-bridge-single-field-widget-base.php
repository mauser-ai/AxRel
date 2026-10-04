<?php
defined('ABSPATH') || exit;

/**
 * Shared rendering for the "one widget per single-value metafield" family
 * (Shopify Bridge — The Science, Shopify Bridge — Regenerative Moisture Complex
 * titolo, ...): each concrete widget only says which FIELDS key it shows.
 * Controls stay minimal (just an HTML tag choice for plain text) — colour/
 * typography/spacing are already covered by Elementor's own Style/Advanced
 * tabs on every widget, nothing custom needed for those.
 */
abstract class Shopify_Bridge_Single_Field_Widget_Base extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	abstract protected function field_key();

	public function get_categories() {
		return ['ns-bridge'];
	}

	private function shape() {
		return Shopify_Bridge_Metafield_Sync::FIELDS[$this->field_key()] ?? 'text';
	}

	protected function register_controls() {
		$shape = $this->shape();

		if ($shape === 'text') {
			$this->start_controls_section('content_section', ['label' => 'Contenuto']);
			$this->add_control('tag', [
				'label'   => 'Tag HTML',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'div',
				'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => 'Paragrafo', 'span' => 'Span', 'div' => 'Div'],
			]);
			$this->end_controls_section();
		}

		if (in_array($shape, ['text', 'richtext'], true)) {
			$this->register_text_style_section('text_style', 'Stile — Testo', '.ns-bridge-field-text, .ns-bridge-field-richtext');
		} elseif ($shape === 'image') {
			$this->register_box_style_section('image_style', 'Stile — Immagine', '.ns-bridge-field-image');
			$this->register_size_control('image_width', 'Larghezza massima', '.ns-bridge-field-image', 'max-width');
		} elseif ($shape === 'video') {
			$this->register_box_style_section('video_style', 'Stile — Video', '.ns-bridge-field-video');
			$this->register_size_control('video_width', 'Larghezza massima', '.ns-bridge-field-video', 'max-width');
		}
	}

	protected function render() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$key   = $this->field_key();
		$shape = $this->shape();
		$raw   = get_post_meta($post_id, Shopify_Bridge_Metafield_Sync::meta_key($key), true);

		if ($raw === '' || $raw === null) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				printf('<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">%s: nessun valore per questo prodotto.</p>', esc_html($this->get_title()));
			}
			return;
		}

		switch ($shape) {
			case 'image':
				printf('<img class="ns-bridge-field-image" src="%s" alt="" loading="lazy">', esc_url($raw));
				break;

			case 'video':
				printf('<video class="ns-bridge-field-video" src="%s" controls playsinline></video>', esc_url($raw));
				break;

			case 'richtext':
				echo '<div class="ns-bridge-field-richtext">' . wp_kses_post($raw) . '</div>';
				break;

			case 'text':
			default:
				$tag = $this->get_settings_for_display('tag');
				$tag = in_array($tag, ['h1', 'h2', 'h3', 'h4', 'p', 'span', 'div'], true) ? $tag : 'div';
				printf('<%1$s class="ns-bridge-field-text">%2$s</%1$s>', $tag, esc_html($raw));
				break;
		}
	}
}
