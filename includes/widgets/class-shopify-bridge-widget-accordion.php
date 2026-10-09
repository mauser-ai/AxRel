<?php
defined('ABSPATH') || exit;

/**
 * Renders either custom.how_to_use_steps or custom.actives_accordion — both
 * are lists of the same "Product Accordion" metaobject type (title, content,
 * media, icon), just used in two different sections of the page. A control
 * picks the source, same pattern as the related-products widget.
 *
 * Each item carries its own media (image or video, per the metaobject's
 * generic "Media" file field), shown one of two ways (media_layout control):
 * "shared" swaps a single panel next to the list to the open item's media,
 * matching the "changing image when I click on accordion" behaviour from the
 * desktop design — but on narrow viewports the list and that panel stack
 * vertically (see .ns-bridge-accordion-wrap), so by the time someone opens
 * the 3rd or 4th item on mobile the panel has scrolled out of view; "inline"
 * instead renders each item's own media inside that item, right after its
 * description, which is what stays reachable on mobile/tablet-portrait.
 * Typical setup: two instances of this widget, one per layout, shown/hidden
 * per breakpoint via Elementor's own native responsive visibility controls
 * (Advanced tab) — not something this plugin needs to build, Elementor
 * already does it.
 *
 * Vanilla JS, native <details>/<summary> — the shared-panel media swap just
 * listens to the browser's own "toggle" event, no framework.
 */
class Shopify_Bridge_Widget_Accordion extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	const SOURCES = [
		'how_to_use_steps'  => 'How to Use - Steps',
		'actives_accordion' => 'Benefits and Actives - Accordion',
	];

	public function get_name() {
		return 'ns_bridge_accordion';
	}

	public function get_title() {
		return 'Shopify Bridge — Accordion';
	}

	public function get_icon() {
		return 'eicon-accordion';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);

		$this->add_control('source', [
			'label'   => 'Sorgente (sezione Shopify)',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'how_to_use_steps',
			'options' => self::SOURCES,
		]);

		$this->add_control('title_tag', [
			'label'   => 'Tag titolo',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'h3',
			'options' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'],
		]);

		$this->add_control('show_media', [
			'label'        => 'Mostra immagine/video',
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'default'      => 'yes',
			'description'  => 'Disattiva se vuoi solo testo.',
		]);

		$this->add_control('media_layout', [
			'label'       => 'Disposizione immagine/video',
			'type'        => \Elementor\Controls_Manager::SELECT,
			'default'     => 'shared',
			'options'     => [
				'shared' => 'Pannello condiviso accanto alla lista (cambia al click) — desktop',
				'inline' => 'Dentro ogni voce, sotto la descrizione — mobile/tablet',
			],
			'condition'   => ['show_media' => 'yes'],
			'description' => 'Su mobile il pannello condiviso puo\' finire fuori dallo schermo quando apri le voci piu\' in basso: usa "Dentro ogni voce" per quel caso.',
		]);

		$this->add_control('show_icon', [
			'label'   => 'Mostra icona accanto al titolo',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);

		$this->add_control('show_number', [
			'label'       => 'Mostra numero voce (01, 02, 03...)',
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'no',
		]);

		$this->end_controls_section();

		$this->register_arrow_controls('before');

		$this->register_text_style_section('title_style', 'Stile — Titolo voce', '.ns-bridge-accordion-title');
		$this->register_text_style_section('content_style', 'Stile — Contenuto voce', '.ns-bridge-accordion-content');
		$this->register_arrow_style_section('.ns-bridge-accordion-title');
		$this->register_text_style_section('number_style', 'Stile — Numero voce', '.ns-bridge-number');
		$this->register_box_style_section('container_style', 'Stile — Riquadro accordion (sfondo generale)', '.ns-bridge-accordion');
		$this->register_box_style_section('item_style', 'Stile — Riquadro voce (es. divisore sotto ogni riga)', '.ns-bridge-accordion-item');
		$this->register_columns_control('columns', 'Colonne (es. 2 per "Benefits and Actives")', '.ns-bridge-accordion');
		$this->register_spacing_control('item_spacing', 'Spazio tra le voci', '.ns-bridge-accordion', 'gap');
		$this->register_box_style_section('media_style', 'Stile — Pannello media condiviso', '.ns-bridge-accordion-media', ['media_layout' => 'shared']);
		$this->register_size_control('media_width', 'Larghezza massima pannello media condiviso', '.ns-bridge-accordion-media', 'max-width', 1000, ['media_layout' => 'shared']);

		$this->start_controls_section('inline_media_style', [
			'label'     => 'Stile — Immagine/video dentro ogni voce',
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => ['show_media' => 'yes', 'media_layout' => 'inline'],
		]);
		$this->add_responsive_control('inline_media_direction', [
			'label'     => 'Disposizione testo/media',
			'type'      => \Elementor\Controls_Manager::CHOOSE,
			'options'   => [
				'column' => ['title' => 'Testo sopra, media sotto', 'icon' => 'eicon-arrow-down'],
				'row'    => ['title' => 'Testo e media affiancati', 'icon' => 'eicon-arrow-right'],
			],
			'default'   => 'column',
			'selectors' => ['{{WRAPPER}} .ns-bridge-accordion-content-wrap' => 'flex-direction: {{VALUE}};'],
		]);
		$this->add_responsive_control('inline_media_width', [
			'label'      => 'Larghezza massima media',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px', '%'],
			'range'      => ['px' => ['min' => 0, 'max' => 600], '%' => ['min' => 0, 'max' => 100]],
			'default'    => ['unit' => '%', 'size' => 100],
			'tablet_default' => ['unit' => '%', 'size' => 100],
			'mobile_default' => ['unit' => '%', 'size' => 100],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-accordion-item-media' => 'max-width: {{SIZE}}{{UNIT}}; flex: 0 0 {{SIZE}}{{UNIT}};'],
		]);
		$this->add_responsive_control('inline_media_gap', [
			'label'      => 'Spazio tra testo e media',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 0, 'max' => 80]],
			'default'    => ['unit' => 'px', 'size' => 16],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-accordion-content-wrap' => 'gap: {{SIZE}}{{UNIT}};'],
		]);
		$this->add_responsive_control('inline_media_radius', [
			'label'      => 'Raggio angoli media',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 0, 'max' => 60]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-accordion-item-media img, {{WRAPPER}} .ns-bridge-accordion-item-media video' => 'border-radius: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();
	}

	protected function render() {
		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$source = $this->get_settings_for_display('source');
		$source = isset(self::SOURCES[$source]) ? $source : 'how_to_use_steps';

		$raw   = get_post_meta($post_id, Shopify_Bridge_Metafield_Sync::meta_key($source), true);
		$items = json_decode((string) $raw, true);

		if (!is_array($items) || !$items) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				printf(
					'<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">Shopify Bridge — Accordion (%s): nessun elemento per questo prodotto.</p>',
					esc_html(self::SOURCES[$source])
				);
			}
			return;
		}

		$tag             = $this->get_settings_for_display('title_tag');
		$tag             = in_array($tag, ['h2', 'h3', 'h4'], true) ? $tag : 'h3';
		$show_media      = $this->get_settings_for_display('show_media') === 'yes';
		$media_layout    = $this->get_settings_for_display('media_layout') === 'inline' ? 'inline' : 'shared';
		$show_media_shared = $show_media && $media_layout === 'shared';
		$show_media_inline = $show_media && $media_layout === 'inline';
		$show_icon       = $this->get_settings_for_display('show_icon') === 'yes';
		$show_number     = $this->get_settings_for_display('show_number') === 'yes';
		$arrow_after     = $this->arrow_goes_after();
		$uid             = 'ns-bridge-accordion-' . $this->get_id();

		echo '<div class="ns-bridge-accordion-wrap" id="' . esc_attr($uid) . '">';

		if ($show_media_shared) {
			echo '<div class="ns-bridge-accordion-media">';
			foreach ($items as $index => $item) {
				$media = is_array($item['media'] ?? null) ? $item['media'] : null;
				if (empty($media['url'])) {
					continue;
				}
				$hidden = $index === 0 ? '' : 'display:none;';
				if (($media['type'] ?? '') === 'video') {
					printf(
						'<video class="ns-bridge-accordion-media-item" data-index="%1$d" style="%2$s" src="%3$s" autoplay muted loop playsinline></video>',
						(int) $index,
						esc_attr($hidden),
						esc_url($media['url'])
					);
				} else {
					printf(
						'<img class="ns-bridge-accordion-media-item" data-index="%1$d" style="%2$s" src="%3$s" alt="" loading="lazy">',
						(int) $index,
						esc_attr($hidden),
						esc_url($media['url'])
					);
				}
			}
			echo '</div>';
		}

		echo '<div class="ns-bridge-accordion">';
		foreach ($items as $index => $item) {
			printf('<details class="ns-bridge-accordion-item" data-index="%d"%s>', (int) $index, $index === 0 ? ' open' : '');
			echo '<summary class="ns-bridge-accordion-title">';

			if (!$arrow_after) {
				$this->render_arrow_control();
			}
			if ($show_number) {
				printf('<span class="ns-bridge-number">%02d.</span>', (int) $index + 1);
			}
			if ($show_icon && !empty($item['icon']['url'])) {
				printf('<img class="ns-bridge-accordion-icon" src="%s" alt="" loading="lazy">', esc_url($item['icon']['url']));
			}
			printf('<%1$s>%2$s</%1$s>', $tag, esc_html($item['title'] ?? ''));
			if ($arrow_after) {
				$this->render_arrow_control();
			}

			echo '</summary>';
			echo '<div class="ns-bridge-accordion-content-wrap">';
			echo '<div class="ns-bridge-accordion-content">' . wp_kses_post($item['content'] ?? '') . '</div>';
			if ($show_media_inline) {
				$media = is_array($item['media'] ?? null) ? $item['media'] : null;
				if (!empty($media['url'])) {
					echo '<div class="ns-bridge-accordion-item-media">';
					if (($media['type'] ?? '') === 'video') {
						printf('<video src="%s" autoplay muted loop playsinline></video>', esc_url($media['url']));
					} else {
						printf('<img src="%s" alt="" loading="lazy">', esc_url($media['url']));
					}
					echo '</div>';
				}
			}
			echo '</div>';
			echo '</details>';
		}
		echo '</div>';
		echo '</div>';

		if ($show_media_shared) {
			?>
			<script>
			(function () {
				var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
				if (!root) { return; }
				var items = root.querySelectorAll('.ns-bridge-accordion-item');
				var media = root.querySelectorAll('.ns-bridge-accordion-media-item');
				items.forEach(function (item) {
					item.addEventListener('toggle', function () {
						if (!item.open) { return; }
						items.forEach(function (other) {
							if (other !== item) { other.open = false; }
						});
						var index = item.getAttribute('data-index');
						media.forEach(function (m) {
							m.style.display = (m.getAttribute('data-index') === index) ? '' : 'none';
						});
					});
				});
			})();
			</script>
			<?php
		}
	}
}
