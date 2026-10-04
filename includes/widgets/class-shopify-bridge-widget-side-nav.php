<?php
defined('ABSPATH') || exit;

/**
 * The sticky side nav seen in the hero Figma (wishlist + "The Science" /
 * "Benefits" / "Ingredients" anchor links, with the currently-visible
 * section highlighted). Not tied to any Shopify data — a generic
 * in-page-navigation widget, so the merchant defines the items themselves
 * (label + link) in the repeater, same as they'd set a "CSS ID" on each
 * Elementor section they want to jump to. Active-section highlighting uses
 * IntersectionObserver against whatever element each #anchor link points
 * to — vanilla JS, no scroll-spy library.
 */
class Shopify_Bridge_Widget_Side_Nav extends \Elementor\Widget_Base {

	use Shopify_Bridge_Style_Controls;

	public function get_name() {
		return 'ns_bridge_side_nav';
	}

	public function get_title() {
		return 'Shopify Bridge — Menu laterale sticky';
	}

	public function get_icon() {
		return 'eicon-nav-menu';
	}

	public function get_categories() {
		return ['ns-bridge'];
	}

	protected function register_controls() {
		$this->start_controls_section('content_section', ['label' => 'Voci di menu']);

		$repeater = new \Elementor\Repeater();
		$repeater->add_control('label', [
			'label'       => 'Etichetta',
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => 'Sezione',
			'label_block' => true,
		]);
		$repeater->add_control('link', [
			'label'       => 'Link (es. #the-science per puntare alla sezione con quel CSS ID)',
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => '#',
			'label_block' => true,
		]);

		$this->add_control('nav_items', [
			'label'       => 'Voci',
			'type'        => \Elementor\Controls_Manager::REPEATER,
			'fields'      => $repeater->get_controls(),
			'default'     => [
				['label' => 'The Science', 'link' => '#the-science'],
				['label' => 'Benefits', 'link' => '#benefits'],
				['label' => 'Ingredients', 'link' => '#ingredients'],
			],
			'title_field' => '{{{ label }}}',
		]);

		$this->end_controls_section();

		$this->start_controls_section('wishlist_section', ['label' => 'Wishlist (facoltativo)']);
		$this->add_control('show_wishlist', [
			'label'   => 'Mostra link wishlist',
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);
		$this->add_control('wishlist_label', [
			'label'     => 'Etichetta',
			'type'      => \Elementor\Controls_Manager::TEXT,
			'default'   => 'my wishlist',
			'condition' => ['show_wishlist' => 'yes'],
		]);
		$this->add_control('wishlist_link', [
			'label'     => 'Link',
			'type'      => \Elementor\Controls_Manager::TEXT,
			'default'   => '#',
			'condition' => ['show_wishlist' => 'yes'],
		]);
		$this->add_control('wishlist_icon', [
			'label'     => 'Icona',
			'type'      => \Elementor\Controls_Manager::ICONS,
			'default'   => ['value' => 'far fa-heart', 'library' => 'fa-regular'],
			'condition' => ['show_wishlist' => 'yes'],
		]);
		$this->end_controls_section();

		$this->start_controls_section('position_section', ['label' => 'Posizione']);
		$this->add_responsive_control('sticky_top', [
			'label'      => 'Distanza dall\'alto quando fissato',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 0, 'max' => 400]],
			'default'    => ['size' => 32, 'unit' => 'px'],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-side-nav' => 'top: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();

		$this->register_text_style_section('item_style', 'Stile — Voci', '.ns-bridge-side-nav-link');

		$this->start_controls_section('active_style', [
			'label' => 'Stile — Voce attiva',
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]);
		$this->add_control('active_color', [
			'label'     => 'Colore testo',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-side-nav-link.is-active' => 'color: {{VALUE}};'],
		]);
		$this->add_group_control(\Elementor\Group_Control_Background::get_type(), [
			'name'     => 'active_background',
			'types'    => ['classic'],
			'selector' => '{{WRAPPER}} .ns-bridge-side-nav-link.is-active',
		]);
		$this->add_responsive_control('active_padding', [
			'label'      => 'Padding voce attiva',
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', 'em'],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-side-nav-link.is-active' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
		]);
		$this->add_responsive_control('active_radius', [
			'label'      => 'Raggio angoli voce attiva',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px', '%'],
			'range'      => ['px' => ['min' => 0, 'max' => 60], '%' => ['min' => 0, 'max' => 50]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-side-nav-link.is-active' => 'border-radius: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();

		$this->register_spacing_control('items_gap', 'Spazio tra le voci', '.ns-bridge-side-nav', 'gap');

		$this->start_controls_section('wishlist_style', [
			'label'     => 'Stile — Wishlist',
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => ['show_wishlist' => 'yes'],
		]);
		$this->add_control('wishlist_color', [
			'label'     => 'Colore',
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => ['{{WRAPPER}} .ns-bridge-side-nav-wishlist' => 'color: {{VALUE}};'],
		]);
		$this->add_responsive_control('wishlist_icon_size', [
			'label'      => 'Dimensione icona',
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px'],
			'range'      => ['px' => ['min' => 8, 'max' => 48]],
			'selectors'  => ['{{WRAPPER}} .ns-bridge-side-nav-wishlist svg, {{WRAPPER}} .ns-bridge-side-nav-wishlist i' => 'font-size: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};'],
		]);
		$this->end_controls_section();
	}

	protected function render() {
		$items         = $this->get_settings_for_display('nav_items');
		$show_wishlist = $this->get_settings_for_display('show_wishlist') === 'yes';
		$uid           = 'ns-bridge-side-nav-' . $this->get_id();

		if (!$items && !$show_wishlist) {
			return;
		}

		echo '<nav class="ns-bridge-side-nav" id="' . esc_attr($uid) . '">';

		if ($show_wishlist) {
			printf('<a class="ns-bridge-side-nav-wishlist" href="%s">', esc_url($this->get_settings_for_display('wishlist_link') ?: '#'));
			\Elementor\Icons_Manager::render_icon($this->get_settings_for_display('wishlist_icon'), ['aria-hidden' => 'true']);
			echo '<span>' . esc_html($this->get_settings_for_display('wishlist_label')) . '</span></a>';
		}

		foreach ((array) $items as $item) {
			printf(
				'<a class="ns-bridge-side-nav-link" href="%s" data-target="%s">%s</a>',
				esc_url($item['link'] ?? '#'),
				esc_attr($item['link'] ?? ''),
				esc_html($item['label'] ?? '')
			);
		}

		echo '</nav>';
		?>
		<script>
		(function () {
			var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
			if (!root || !window.IntersectionObserver) { return; }
			var links = root.querySelectorAll('.ns-bridge-side-nav-link[data-target^="#"]');
			var targets = [];
			links.forEach(function (link) {
				var el = document.querySelector(link.getAttribute('data-target'));
				if (el) { targets.push({ link: link, el: el }); }
			});
			if (!targets.length) { return; }

			var observer = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					var match = targets.find(function (t) { return t.el === entry.target; });
					if (match && entry.isIntersecting) {
						links.forEach(function (l) { l.classList.toggle('is-active', l === match.link); });
					}
				});
			}, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

			targets.forEach(function (t) { observer.observe(t.el); });
		})();
		</script>
		<?php
	}
}
