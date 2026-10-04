<?php
defined('ABSPATH') || exit;

/**
 * Renders the "Acquista su Shopify" button/variant-picker for the current
 * product, reusing Shopify_Bridge_Frontend::render_buy_on_shopify() as-is.
 *
 * Exists as its own widget instead of relying on Elementor Pro's native
 * WooCommerce "Add to Cart" widget: Elementor Pro's dedicated product
 * widgets tend to call WooCommerce's template functions directly rather
 * than going through the woocommerce_single_product_summary action —
 * which is exactly the hook Shopify_Bridge_Frontend uses to swap the native
 * add-to-cart form for the Shopify buy button. Using that native widget
 * in an Elementor template risks silently showing no buy button at all
 * (or, worse, no protection against a customer accidentally landing back
 * on a WooCommerce cart flow). This widget calls the replacement directly,
 * so it always works regardless of Elementor internals.
 */
class Shopify_Bridge_Widget_Buy_Button extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'ns_bridge_buy_button';
	}

	public function get_title() {
		return 'Shopify Bridge — Acquista su Shopify';
	}

	public function get_icon() {
		return 'eicon-cart-medium';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	/**
	 * The markup itself (.ns-bridge-buy-on-shopify, .ns-bridge-attribute,
	 * .ns-bridge-variant-buy) comes from Shopify_Bridge_Frontend — this
	 * widget only adds Elementor Style-tab controls targeting those classes,
	 * same pattern as every other widget's style controls, without touching
	 * the markup/JS that makes the Shopify redirect + variant picker work.
	 */
	protected function register_controls() {
		$this->start_controls_section('button_style', [
			'label' => 'Stile — Pulsante',
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);
		$this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
			'name'     => 'button_typography',
			'selector' => '{{WRAPPER}} .ns-bridge-buy-on-shopify',
		]);
		$this->start_controls_tabs('button_state_tabs');

		$this->start_controls_tab('button_state_normal', ['label' => 'Normale']);
		$this->add_control('button_color', [
			'label'     => 'Colore testo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-buy-on-shopify' => 'color: {{VALUE}};'],
		]);
		$this->add_group_control(\Elementor\Group_Control_Background::get_type(), [
			'name'     => 'button_background',
			'types'    => ['classic', 'gradient'],
			'selector' => '{{WRAPPER}} .ns-bridge-buy-on-shopify',
		]);
		$this->end_controls_tab();

		$this->start_controls_tab('button_state_hover', ['label' => 'Al passaggio del mouse']);
		$this->add_control('button_color_hover', [
			'label'     => 'Colore testo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-buy-on-shopify:hover' => 'color: {{VALUE}};'],
		]);
		$this->add_group_control(\Elementor\Group_Control_Background::get_type(), [
			'name'     => 'button_background_hover',
			'types'    => ['classic', 'gradient'],
			'selector' => '{{WRAPPER}} .ns-bridge-buy-on-shopify:hover',
		]);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control('button_padding', [
			'label'      => 'Padding',
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', '%', 'em'],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-buy-on-shopify' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
		]);
		$this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
			'name'     => 'button_border',
			'selector' => '{{WRAPPER}} .ns-bridge-buy-on-shopify',
		]);
		$this->add_responsive_control('button_radius', [
			'label'      => 'Raggio angoli',
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', '%'],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-buy-on-shopify' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
		]);
		$this->add_control('button_full_width', [
			'label'     => 'Larghezza piena (100%)',
			'type'      => \Elementor\Controls_Manager::SWITCHER,
			'selectors' => ['{{WRAPPER}} .ns-bridge-buy-on-shopify' => 'display: block; width: 100%; text-align: center;'],
		]);
		$this->end_controls_section();

		$this->register_text_style_section('select_style', 'Stile — Selettore variante', '.ns-bridge-attribute');

		$this->start_controls_section('label_style', [
			'label' => 'Stile — Etichetta variante',
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);
		$this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
			'name'     => 'label_typography',
			'selector' => '{{WRAPPER}} .ns-bridge-variant-buy label',
		]);
		$this->add_control('label_color', [
			'label'     => 'Colore',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-variant-buy label' => 'color: {{VALUE}};'],
		]);
		$this->end_controls_section();

		$this->register_spacing_control('field_spacing', 'Spazio tra i campi', '.ns-bridge-variant-buy', 'gap');
	}

	protected function render() {
		global $product;

		if (!$product instanceof WC_Product) {
			$post_id = get_the_ID();
			$product = $post_id ? wc_get_product($post_id) : null;
		}

		if (!$product) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<p style="opacity:.6;padding:1em;border:1px dashed currentColor;">Shopify Bridge — Acquista su Shopify: nessun prodotto WooCommerce in questo contesto.</p>';
			}
			return;
		}

		Shopify_Bridge_Frontend::render_buy_on_shopify();
	}
}
