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
		$this->register_text_style_section('product_title_style', 'Stile — Nome prodotto', '.ns-bridge-product-card-title');
		$this->register_text_style_section('product_price_style', 'Stile — Prezzo', '.ns-bridge-product-card-price');
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

		echo '<div class="ns-bridge-routine-tabs-panels">';
		foreach ($items as $index => $item) {
			printf('<div class="ns-bridge-routine-tab-panel" data-index="%d" style="%s">', (int) $index, $index === 0 ? '' : 'display:none;');
			if (!empty($item['tab_description'])) {
				echo '<div class="ns-bridge-routine-tab-description">' . wp_kses_post($item['tab_description']) . '</div>';
			}
			$product_ids = is_array($item['products'] ?? null) ? $item['products'] : [];
			Shopify_Bridge_Product_Cards::render($product_ids, 'ns-bridge-product-cards');
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
