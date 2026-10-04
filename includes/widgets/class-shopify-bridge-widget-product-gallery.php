<?php
defined('ABSPATH') || exit;

/**
 * Renders the current WooCommerce product's image + gallery as a simple
 * slider with dot navigation — the Figma hero shows a single large image
 * area with pagination dots underneath, not WooCommerce's default
 * thumbnail-strip gallery. Zero-dependency vanilla JS, same pattern as the
 * accordion's media swap: one slide shown at a time via display toggling,
 * dots just set which slide is visible.
 */
class Shopify_Bridge_Widget_Product_Gallery extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'ns_bridge_product_gallery';
	}

	public function get_title() {
		return 'Shopify Bridge — Galleria prodotto';
	}

	public function get_icon() {
		return 'eicon-slider-album';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Contenuto']);
		$this->add_control('image_size', [
			'label'   => 'Dimensione immagine',
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'large',
			'options' => ['medium' => 'Media', 'large' => 'Grande', 'full' => 'Originale'],
		]);
		$this->add_control('show_dots', [
			'label'   => 'Mostra pallini di navigazione',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->end_controls_section();

		$this->register_box_style_section('container_style', 'Stile — Riquadro galleria (sfondo)', '.ns-bridge-gallery');
		$this->register_size_control('image_max_height', 'Altezza massima immagine', '.ns-bridge-gallery-slide img', 'max-height', 1200);

		$this->start_controls_section('dots_style', [
			'label'     => 'Stile — Pallini',
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => ['show_dots' => 'yes'],
		]);
		$this->add_control('dot_color', [
			'label'     => 'Colore pallino',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-gallery-dot' => 'background-color: {{VALUE}};'],
		]);
		$this->add_control('dot_color_active', [
			'label'     => 'Colore pallino attivo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-gallery-dot.is-active' => 'background-color: {{VALUE}};'],
		]);
		$this->add_responsive_control('dot_size', [
			'label'      => 'Dimensione pallino',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 4, 'max' => 24]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-gallery-dot' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};'],
		]);
		$this->add_responsive_control('dot_gap', [
			'label'      => 'Spazio tra pallini',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 0, 'max' => 40]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-gallery-dots' => 'gap: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();
	}

	protected function render() {
		global $product;
		if (!$product instanceof WC_Product) {
			$post_id = get_the_ID();
			$product = $post_id ? wc_get_product($post_id) : null;
		}
		if (!$product) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">Shopify Bridge — Galleria prodotto: nessun prodotto WooCommerce in questo contesto.</p>';
			}
			return;
		}

		$image_ids = array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids()));
		if (!$image_ids) {
			return;
		}

		$size      = $this->get_settings_for_display('image_size');
		$show_dots = $this->get_settings_for_display('show_dots') === 'yes';
		$uid       = 'ns-bridge-gallery-' . $this->get_id();

		echo '<div class="ns-bridge-gallery" id="' . esc_attr($uid) . '">';
		echo '<div class="ns-bridge-gallery-slides">';
		foreach ($image_ids as $index => $image_id) {
			printf(
				'<div class="ns-bridge-gallery-slide" data-index="%1$d" style="%2$s">%3$s</div>',
				(int) $index,
				$index === 0 ? '' : 'display:none;',
				wp_get_attachment_image($image_id, $size, false, ['loading' => 'lazy'])
			);
		}
		echo '</div>';

		if ($show_dots && count($image_ids) > 1) {
			echo '<div class="ns-bridge-gallery-dots">';
			foreach ($image_ids as $index => $image_id) {
				printf(
					'<button type="button" class="ns-bridge-gallery-dot%s" data-index="%d" aria-label="Immagine %d"></button>',
					$index === 0 ? ' is-active' : '',
					(int) $index,
					(int) $index + 1
				);
			}
			echo '</div>';
		}
		echo '</div>';

		if (count($image_ids) > 1) {
			?>
			<script>
			(function () {
				var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
				if (!root) { return; }
				var slides = root.querySelectorAll('.ns-bridge-gallery-slide');
				var dots   = root.querySelectorAll('.ns-bridge-gallery-dot');
				dots.forEach(function (dot) {
					dot.addEventListener('click', function () {
						var index = dot.getAttribute('data-index');
						slides.forEach(function (s) { s.style.display = (s.getAttribute('data-index') === index) ? '' : 'none'; });
						dots.forEach(function (d) { d.classList.toggle('is-active', d === dot); });
					});
				});
			})();
			</script>
			<?php
		}
	}
}
