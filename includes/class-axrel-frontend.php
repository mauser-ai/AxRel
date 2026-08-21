<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce holds the product/variant data (admin UI, price ranges,
 * structured data), but purchasing must always happen on Shopify. This
 * hides WooCommerce's native Add to Cart everywhere for synced products and
 * replaces it with an "Acquista su Shopify" link that sends the shopper
 * straight to Shopify's cart with the right variant preloaded, via
 * Shopify's own cart permalink pattern:
 * https://{domain}/cart/{variant_id}:{quantity}
 */
class Axrel_Frontend {

	public static function register() {
		add_filter('woocommerce_is_purchasable', [__CLASS__, 'filter_purchasable'], 10, 2);
		remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
		add_action('woocommerce_single_product_summary', [__CLASS__, 'render_buy_on_shopify'], 30);
		add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'filter_loop_add_to_cart_link'], 10, 2);
	}

	private static function is_synced_product($product) {
		return $product && get_post_meta($product->get_id(), Axrel_Product_Sync::META_SHOPIFY_ID, true) !== '';
	}

	public static function filter_purchasable($purchasable, $product) {
		return self::is_synced_product($product) ? false : $purchasable;
	}

	private static function cart_base_url() {
		$domain = Axrel_Settings::get('storefront_domain') ?: Axrel_Settings::get('shop_domain');
		return $domain ? "https://{$domain}/cart/" : '';
	}

	private static function simple_checkout_url($product) {
		$base = self::cart_base_url();
		if (!$base) {
			return '';
		}
		$variant_id = get_post_meta($product->get_id(), Axrel_Product_Sync::META_SHOPIFY_VARIANT_ID, true);
		return $variant_id ? $base . rawurlencode($variant_id) . ':1' : '';
	}

	public static function filter_loop_add_to_cart_link($html, $product) {
		if (!self::is_synced_product($product) || $product->is_type('variable')) {
			return $html;
		}
		$url = self::simple_checkout_url($product);
		return $url
			? sprintf('<a href="%s" class="button axrel-buy-on-shopify">Acquista su Shopify</a>', esc_url($url))
			: $html;
	}

	public static function render_buy_on_shopify() {
		global $product;

		if (!self::is_synced_product($product)) {
			return;
		}

		$base = self::cart_base_url();
		if (!$base) {
			echo '<p class="axrel-buy-warning">Dominio Shopify non configurato: impossibile generare il link di acquisto.</p>';
			return;
		}

		if ($product->is_type('variable')) {
			self::render_variable_buy_button($product, $base);
			return;
		}

		$url = self::simple_checkout_url($product);
		if (!$url) {
			echo '<p class="axrel-buy-warning">Variante Shopify non disponibile per questo prodotto.</p>';
			return;
		}
		printf(
			'<a href="%s" class="single_add_to_cart_button button alt axrel-buy-on-shopify">Acquista su Shopify</a>',
			esc_url($url)
		);
	}

	private static function render_variable_buy_button($product, $base) {
		$product_attributes = $product->get_attributes(); // slug => WC_Product_Attribute
		$variation_options  = $product->get_variation_attributes(); // slug => [option, ...]

		$variation_map = [];
		foreach ($product->get_children() as $variation_id) {
			$shopify_variant_id = get_post_meta($variation_id, Axrel_Product_Sync::META_SHOPIFY_VARIANT_ID, true);
			$variation = wc_get_product($variation_id);
			if (!$shopify_variant_id || !$variation) {
				continue;
			}
			$attributes = $variation->get_attributes(); // slug => value
			ksort($attributes);
			$variation_map[wp_json_encode($attributes)] = $shopify_variant_id;
		}

		$container_id = 'axrel-buy-' . $product->get_id();
		?>
		<div id="<?php echo esc_attr($container_id); ?>" class="axrel-variant-buy">
			<?php foreach ($variation_options as $slug => $options) :
				$label = isset($product_attributes[$slug]) ? $product_attributes[$slug]->get_name() : $slug;
				?>
				<p style="margin-bottom:.75em;">
					<label style="display:block;font-weight:600;margin-bottom:.25em;">
						<?php echo esc_html($label); ?>
					</label>
					<select class="axrel-attribute" data-attribute="<?php echo esc_attr($slug); ?>">
						<option value="">Scegli...</option>
						<?php foreach ($options as $option) : ?>
							<option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endforeach; ?>
			<a href="#" class="single_add_to_cart_button button alt axrel-buy-on-shopify" aria-disabled="true" style="pointer-events:none;opacity:.5;">
				Acquista su Shopify
			</a>
		</div>
		<script>
		(function () {
			var root = document.getElementById(<?php echo wp_json_encode($container_id); ?>);
			if (!root) { return; }
			var map = <?php echo wp_json_encode($variation_map); ?>;
			var base = <?php echo wp_json_encode($base); ?>;
			var selects = root.querySelectorAll('select.axrel-attribute');
			var link = root.querySelector('a.axrel-buy-on-shopify');

			function update() {
				var values = {};
				var complete = true;
				selects.forEach(function (select) {
					if (!select.value) { complete = false; }
					values[select.dataset.attribute] = select.value;
				});
				var sortedKeys = Object.keys(values).sort();
				var sorted = {};
				sortedKeys.forEach(function (key) { sorted[key] = values[key]; });
				var variantId = complete ? map[JSON.stringify(sorted)] : null;

				if (variantId) {
					link.href = base + encodeURIComponent(variantId) + ':1';
					link.style.pointerEvents = 'auto';
					link.style.opacity = '1';
					link.setAttribute('aria-disabled', 'false');
				} else {
					link.href = '#';
					link.style.pointerEvents = 'none';
					link.style.opacity = '.5';
					link.setAttribute('aria-disabled', 'true');
				}
			}

			selects.forEach(function (select) {
				select.addEventListener('change', update);
			});
		})();
		</script>
		<?php
	}
}
