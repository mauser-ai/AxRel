<?php
defined('ABSPATH') || exit;

/**
 * Renders custom.routine_tabs — a variable number of tabs (Routine Tab
 * metaobject: tab_title, tab_description, products), each with its own
 * product grid. Matches the design notes "more than one, carousel" / "in
 * each tab I can have different products and number of products to show":
 * the number of tabs and the number of products per tab are both fully
 * dynamic, read straight from what's synced for this product.
 *
 * Tab switching is vanilla JS (click a tab button, show its panel, hide the
 * others) — no slider library, consistent with the rest of the plugin's
 * zero-dependency widgets.
 *
 * Each panel's card row optionally leads with the CURRENT product's own
 * image (show_hero_image) — never an unrelated/hardcoded image, always
 * whatever product this widget happens to sit on — "+"-separated from the
 * routine's own product cards exactly like those cards are separated from
 * each other, and vertically centered on the same row via the shared
 * .ns-bridge-product-cards flex container (see Shopify_Bridge_Product_Cards).
 */
class Shopify_Bridge_Widget_Routine_Tabs extends Shopify_Bridge_Elementor_Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'shopify_bridge_routine_tabs';
	}

	public function get_title() {
		return 'Shopify Bridge — Routine Tabs';
	}

	public function get_icon() {
		return 'eicon-tabs';
	}

	protected function meta_field_key() {
		return 'routine_tabs';
	}

	protected function wrapper_class() {
		return 'ns-bridge-routine-tabs-panels';
	}

	/** Unused: render() is fully overridden below (tabs need custom markup, not a flat item loop), but the base class declares this abstract. */
	protected function render_item(array $item, $index) {}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('show_rating', [
			'label'   => 'Mostra valutazione (stelle) sulle card',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->add_control('show_separator', [
			'label'   => 'Mostra separatore "+" tra le card prodotto',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->add_control('show_hero_image', [
			'label'       => 'Mostra immagine prodotto principale a sinistra',
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'yes',
			'description' => 'L\'immagine del prodotto di QUESTA pagina (non di un prodotto della routine), mostrata per prima su ogni tab.',
		]);
		$this->add_control('show_dots', [
			'label'       => 'Mostra indicatori pagina (pallini) sotto le card',
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'yes',
			'description' => 'Cliccabili, e si aggiornano scorrendo il carosello col dito.',
		]);
		$this->add_control('description_clamp', [
			'label'       => 'Limita descrizione tab a 2 righe (con "...")',
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'yes',
		]);
		$this->end_controls_section();

		$this->register_box_style_section('container_style', 'Stile — Riquadro generale (sfondo)', '.ns-bridge-routine-tabs');
		$this->register_text_style_section('tab_style', 'Stile — Pulsante tab', '.ns-bridge-routine-tab-btn');

		$this->start_controls_section('active_tab_style', [
			'label' => 'Stile — Tab attiva',
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);
		$this->add_control('active_color', [
			'label'     => 'Colore testo tab attiva',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-routine-tab-btn.is-active' => 'color: {{VALUE}};'],
		]);
		$this->add_control('active_background', [
			'label'     => 'Sfondo tab attiva',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-routine-tab-btn.is-active' => 'background-color: {{VALUE}};'],
		]);
		$this->end_controls_section();

		$this->register_text_style_section('description_style', 'Stile — Descrizione tab', '.ns-bridge-routine-tab-description');
		$this->register_spacing_control('card_spacing', 'Spazio tra le card prodotto', '.ns-bridge-product-cards', 'gap');
		$this->register_size_control('hero_image_size', 'Dimensione immagine prodotto principale', '.ns-bridge-routine-tabs-panels .ns-bridge-product-card-hero', 'max-width', 500);
		$this->register_size_control('card_image_size', 'Dimensione immagine card prodotto', '.ns-bridge-routine-tabs-panels .ns-bridge-product-card', 'max-width', 400);
		$this->register_box_style_section('card_box_style', 'Stile — Riquadro prodotto (sfondo dietro l\'immagine)', '.ns-bridge-product-card-box');
		$this->register_text_style_section('product_title_style', 'Stile — Nome prodotto', '.ns-bridge-product-card-title');
		$this->register_text_style_section('product_price_style', 'Stile — Prezzo', '.ns-bridge-product-card-price');

		$this->start_controls_section('dots_style', [
			'label'     => 'Stile — Indicatori pagina (pallini)',
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => ['show_dots' => 'yes'],
		]);
		$this->add_control('dot_color', [
			'label'     => 'Colore pallino',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-product-cards-dot' => 'background-color: {{VALUE}};'],
		]);
		$this->add_control('dot_active_color', [
			'label'     => 'Colore pallino attivo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-product-cards-dot.is-active' => 'background-color: {{VALUE}};'],
		]);
		$this->add_responsive_control('dot_size', [
			'label'      => 'Dimensione pallino',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 4, 'max' => 24]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-product-cards-dot' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();
	}

	protected function render() {
		$items = $this->get_meta_items();

		if (!$items) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">Shopify Bridge — Routine Tabs: nessuna tab per questo prodotto.</p>';
			}
			return;
		}

		$uid = 'ns-bridge-routine-tabs-' . $this->get_id();

		echo '<div class="ns-bridge-routine-tabs" id="' . esc_attr($uid) . '">';

		echo '<div class="ns-bridge-routine-tabs-nav" role="tablist">';
		foreach ($items as $index => $item) {
			printf(
				'<button type="button" class="ns-bridge-routine-tab-btn%s" data-index="%d">%s</button>',
				$index === 0 ? ' is-active' : '',
				(int) $index,
				esc_html($item['tab_title'] ?? '')
			);
		}
		echo '</div>';

		$description_class = $this->get_settings_for_display('description_clamp') === 'yes' ? ' ns-bridge-clamp-2' : '';

		echo '<div class="ns-bridge-routine-tabs-panels">';
		foreach ($items as $index => $item) {
			printf('<div class="ns-bridge-routine-tab-panel" data-index="%d" style="%s">', (int) $index, $index === 0 ? '' : 'display:none;');
			if (!empty($item['tab_description'])) {
				echo '<div class="ns-bridge-routine-tab-description' . esc_attr($description_class) . '">' . wp_kses_post($item['tab_description']) . '</div>';
			}
			$product_ids = is_array($item['products'] ?? null) ? $item['products'] : [];
			Shopify_Bridge_Product_Cards::render(
				$product_ids,
				'ns-bridge-product-cards',
				'medium',
				$this->get_settings_for_display('show_rating') === 'yes',
				$this->get_settings_for_display('show_separator') === 'yes',
				$this->get_settings_for_display('show_hero_image') === 'yes' ? get_the_ID() : null,
				$this->get_settings_for_display('show_dots') === 'yes'
			);
			echo '</div>';
		}
		echo '</div>';

		echo '</div>';
		?>
		<script>
		(function () {
			var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
			if (!root) { return; }
			var buttons = root.querySelectorAll('.ns-bridge-routine-tab-btn');
			var panels  = root.querySelectorAll('.ns-bridge-routine-tab-panel');
			buttons.forEach(function (btn) {
				btn.addEventListener('click', function () {
					var index = btn.getAttribute('data-index');
					buttons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
					panels.forEach(function (p) {
						p.style.display = (p.getAttribute('data-index') === index) ? '' : 'none';
					});
				});
			});
		})();
		</script>
		<?php
	}
}
