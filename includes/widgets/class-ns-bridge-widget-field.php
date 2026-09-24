<?php
defined('ABSPATH') || exit;

/**
 * Renders any ONE of the single-value metafields (The Science, Regenerative
 * Moisture Complex title/description/image/video, Clinical Testing Results
 * title/description/image, ...) — the ones that aren't a repeatable list
 * and so don't get their own dedicated widget like Accordion or FAQ.
 *
 * Elementor's native "Custom Field" dynamic tag (which would otherwise let
 * any Text/Image widget pull a postmeta value directly) is an Elementor
 * PRO-only feature, not available in Elementor Free — this widget exists so
 * single-value fields work regardless of which edition is installed.
 */
class NS_Bridge_Widget_Field extends \Elementor\Widget_Base {

	const SINGLE_VALUE_SHAPES = ['text', 'richtext', 'image', 'video'];

	public function get_name() {
		return 'ns_bridge_field';
	}

	public function get_title() {
		return 'NS Bridge — Campo singolo';
	}

	public function get_icon() {
		return 'eicon-shortcode';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	private static function options() {
		$options = [];
		foreach (NS_Bridge_Metafield_Sync::FIELDS as $key => $shape) {
			if (in_array($shape, self::SINGLE_VALUE_SHAPES, true)) {
				$options[$key] = NS_Bridge_Metafield_Debug_Box::LABELS[$key] ?? $key;
			}
		}
		return $options;
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);

		$this->add_control('field', [
			'label'   => 'Campo Shopify',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'the_science',
			'options' => self::options(),
		]);

		$this->add_control('tag', [
			'label'   => 'Tag HTML (solo per testo semplice)',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'div',
			'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => 'Paragrafo', 'span' => 'Span', 'div' => 'Div'],
		]);

		$this->end_controls_section();
	}

	protected function render() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$key = $this->get_settings_for_display('field');
		if (!isset(NS_Bridge_Metafield_Sync::FIELDS[$key])) {
			return;
		}

		$shape = NS_Bridge_Metafield_Sync::FIELDS[$key];
		$raw   = get_post_meta($post_id, NS_Bridge_Metafield_Sync::meta_key($key), true);

		if ($raw === '' || $raw === null) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				$label = self::options()[$key] ?? $key;
				printf('<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">NS Bridge — Campo singolo: nessun valore per "%s" su questo prodotto.</p>', esc_html($label));
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
