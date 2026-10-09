<?php
defined('ABSPATH') || exit;

/** Shared "product card grid" renderer (image, title, price) for widgets that show a list of WP post IDs resolved from a Shopify product reference field. */
class Shopify_Bridge_Product_Cards {

	/**
	 * $hero_post_id (optional): the CURRENT product (the one this widget sits
	 * on), rendered first as an image-only item — no title/price, since it's
	 * the product the visitor is already looking at — on the same flex row
	 * as the rest, "+"-separated from the first real card exactly like the
	 * cards are separated from each other. Used by the Routine Tabs widget so
	 * its leftmost image always matches the page's own product instead of
	 * being a separate, uncontrolled/unrelated image.
	 *
	 * $show_dots (optional): adds a row of pagination dots below the card
	 * row, synced to its horizontal scroll position (see
	 * .ns-bridge-product-cards' scroll-snap in ns-bridge-widgets.css) — click
	 * a dot to scroll to that item, scroll to update which dot is active.
	 * Every "slide" (hero image and each product card, but not the "+"
	 * separators between them) carries a shared ns-bridge-carousel-slide
	 * class the dots script keys off, so separators never throw the count
	 * off and never get a dot of their own.
	 */
	public static function render(array $post_ids, $wrapper_class = 'ns-bridge-product-cards', $image_size = 'medium', $show_rating = true, $show_separator = false, $hero_post_id = null, $show_dots = false) {
		$uid = $show_dots ? wp_unique_id('ns-bridge-cards-') : '';

		echo '<div class="ns-bridge-product-cards-carousel">';
		printf('<div class="%s"%s>', esc_attr($wrapper_class), $uid ? ' id="' . esc_attr($uid) . '"' : '');
		$rendered = 0;

		$hero_product = $hero_post_id && function_exists('wc_get_product') ? wc_get_product($hero_post_id) : null;
		if ($hero_product) {
			printf(
				'<span class="ns-bridge-product-card-hero ns-bridge-product-card-box ns-bridge-carousel-slide">%s</span>',
				wp_kses_post($hero_product->get_image('large'))
			);
			$rendered++;
		}

		foreach ($post_ids as $post_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
			if (!$product) {
				continue;
			}
			if ($show_separator && $rendered > 0) {
				echo '<span class="ns-bridge-product-card-separator" aria-hidden="true">+</span>';
			}
			$rendered++;
			$rating_html = '';
			if ($show_rating && $product->get_rating_count() > 0) {
				$rating_html = '<span class="ns-bridge-product-card-rating">' . wc_get_rating_html($product->get_average_rating()) . '</span>';
			}
			printf(
				'<a class="ns-bridge-product-card ns-bridge-carousel-slide" href="%s"><span class="ns-bridge-product-card-image ns-bridge-product-card-box">%s</span><span class="ns-bridge-product-card-title">%s</span>%s<span class="ns-bridge-product-card-price">%s</span></a>',
				esc_url(get_permalink($post_id)),
				wp_kses_post($product->get_image($image_size)),
				esc_html($product->get_name()),
				$rating_html, // already escaped by wc_get_rating_html()
				wp_kses_post($product->get_price_html())
			);
		}
		echo '</div>';

		if ($uid && $rendered > 1) {
			echo '<div class="ns-bridge-product-cards-dots">';
			for ($i = 0; $i < $rendered; $i++) {
				// A <span role="button"> rather than a real <button>: confirmed live
				// on this store that the theme's own CSS resets native button
				// chrome (padding/border stripped on the tab buttons too), which a
				// plain span was never going to be targeted by in the first place.
				printf(
					'<span class="ns-bridge-product-cards-dot%s" role="button" tabindex="0" data-index="%d" aria-label="%s"></span>',
					$i === 0 ? ' is-active' : '',
					(int) $i,
					esc_attr(sprintf('Vai all\'elemento %d', $i + 1))
				);
			}
			echo '</div>';
			?>
			<script>
			(function () {
				var row = document.getElementById(<?php echo wp_json_encode($uid); ?>);
				if (!row) { return; }
				var dotsWrap = row.parentElement.querySelector('.ns-bridge-product-cards-dots');
				if (!dotsWrap) { return; }
				var dots   = Array.prototype.slice.call(dotsWrap.querySelectorAll('.ns-bridge-product-cards-dot'));
				var slides = Array.prototype.slice.call(row.querySelectorAll('.ns-bridge-carousel-slide'));
				if (!slides.length || !dots.length) { return; }

				function setActive(index) {
					dots.forEach(function (d, i) { d.classList.toggle('is-active', i === index); });
				}

				function nearestIndex() {
					var rowLeft = row.getBoundingClientRect().left;
					var best = 0, bestDist = Infinity;
					slides.forEach(function (s, i) {
						var dist = Math.abs(s.getBoundingClientRect().left - rowLeft);
						if (dist < bestDist) { bestDist = dist; best = i; }
					});
					return best;
				}

				var scrollTimer = null;
				row.addEventListener('scroll', function () {
					if (scrollTimer) { clearTimeout(scrollTimer); }
					scrollTimer = setTimeout(function () { setActive(nearestIndex()); }, 80);
				}, { passive: true });

				function goTo(i) {
					var s = slides[i];
					if (!s) { return; }
					var delta = s.getBoundingClientRect().left - row.getBoundingClientRect().left;
					row.scrollTo({ left: row.scrollLeft + delta, behavior: 'smooth' });
				}

				dots.forEach(function (dot, i) {
					dot.addEventListener('click', function () { goTo(i); });
					dot.addEventListener('keydown', function (e) {
						if (e.key === 'Enter' || e.key === ' ') {
							e.preventDefault();
							goTo(i);
						}
					});
				});
			})();
			</script>
			<?php
		}

		echo '</div>';
	}
}
