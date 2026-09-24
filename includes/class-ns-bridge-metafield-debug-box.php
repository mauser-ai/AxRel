<?php
defined('ABSPATH') || exit;

/**
 * Read-only "Dati Shopify" box on the WooCommerce product edit screen,
 * showing every NS_Bridge_Metafield_Sync value currently stored for that
 * product. These are saved under postmeta keys prefixed with "_" (Shopify
 * is the only source of truth for them), so WordPress's own "Campi
 * personalizzati" box hides them entirely — without this, there would be
 * no way to see whether the metafield/metaobject sync actually worked
 * short of a direct database lookup.
 */
class NS_Bridge_Metafield_Debug_Box {

	const LABELS = [
		'the_science'             => 'The Science',
		'benefits_intro'          => 'Benefits — intro',
		'ingredients_intro'       => 'Ingredients — intro',
		'complex_title'           => 'Regenerative Moisture Complex — titolo',
		'complex_description'     => 'Regenerative Moisture Complex — descrizione',
		'complex_image'           => 'Regenerative Moisture Complex — immagine',
		'complex_video'           => 'Regenerative Moisture Complex — video',
		'complex_image_2'         => 'Regenerative Moisture Complex — immagine 2',
		'complex_accordions'      => 'Regenerative Moisture Complex — accordion',
		'clinical_title'          => 'Clinical Testing Results — titolo',
		'clinical_description'    => 'Clinical Testing Results — descrizione',
		'clinical_image'          => 'Clinical Testing Results — immagine',
		'clinical_results'        => 'Clinical Testing Results — risultati',
		'complete_your_routine'   => 'Complete Your Routine — prodotti',
		'product_benefits'        => 'Benefits & Ingredients — benefits',
		'product_ingredients'     => 'Benefits & Ingredients — ingredients',
		'something_else_products' => 'Something Else? — prodotti',
		'product_faqs'            => 'FAQ',
	];

	public static function register() {
		add_action('add_meta_boxes', [__CLASS__, 'add_box']);
	}

	public static function add_box() {
		add_meta_box(
			'ns_bridge_metafields',
			'NS Bridge — Dati Shopify (sola lettura)',
			[__CLASS__, 'render'],
			NS_Bridge_Product_Sync::POST_TYPE,
			'normal',
			'default'
		);
	}

	public static function render($post) {
		$shopify_id = get_post_meta($post->ID, NS_Bridge_Product_Sync::META_SHOPIFY_ID, true);

		if (!$shopify_id) {
			echo '<p>Questo prodotto non risulta collegato a un prodotto Shopify (nessun ID Shopify salvato).</p>';
			return;
		}

		echo '<p class="description">Valori portati da Shopify via la sync dei metafield/metaobject — modificabili solo su Shopify, qui solo a scopo di verifica.</p>';
		echo '<table class="widefat striped"><tbody>';

		foreach (NS_Bridge_Metafield_Sync::FIELDS as $key => $shape) {
			$label = self::LABELS[$key] ?? $key;
			$raw   = get_post_meta($post->ID, NS_Bridge_Metafield_Sync::meta_key($key), true);
			printf(
				'<tr><td style="width:280px;"><strong>%s</strong><br><code style="opacity:.6;">%s</code></td><td>%s</td></tr>',
				esc_html($label),
				esc_html($key),
				self::render_value($shape, $raw)
			);
		}

		echo '</tbody></table>';
	}

	private static function render_value($shape, $raw) {
		if ($raw === '' || $raw === null) {
			return '<em style="opacity:.6;">(vuoto)</em>';
		}

		switch ($shape) {
			case 'image':
				return sprintf('<img src="%s" style="max-width:120px;height:auto;display:block;">', esc_url($raw));

			case 'video':
				return sprintf('<a href="%1$s" target="_blank" rel="noopener">%1$s</a>', esc_url($raw));

			case 'richtext':
				return sprintf('<div style="max-height:120px;overflow:auto;border:1px solid #dcdcde;padding:.5em;">%s</div>', wp_kses_post($raw));

			case 'metaobject_list':
			case 'product_list':
				return self::render_list($shape, $raw);

			case 'text':
			default:
				return esc_html($raw);
		}
	}

	private static function render_list($shape, $raw) {
		$items = json_decode((string) $raw, true);
		if (!is_array($items) || !$items) {
			return '<em style="opacity:.6;">(nessun elemento)</em>';
		}

		if ($shape === 'product_list') {
			$links = array_map(function ($post_id) {
				$title = get_the_title($post_id) ?: ('#' . $post_id);
				return sprintf('<a href="%s">%s</a>', esc_url((string) get_edit_post_link($post_id)), esc_html($title));
			}, $items);
			return sprintf('%d prodotto/i: %s', count($items), implode(', ', $links));
		}

		$html = sprintf('<details><summary>%d elemento/i — mostra dettaglio</summary><pre style="max-height:200px;overflow:auto;white-space:pre-wrap;">%s</pre></details>', count($items), esc_html(wp_json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
		return $html;
	}
}
