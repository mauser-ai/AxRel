<?php
defined('ABSPATH') || exit;

/**
 * Pulls the Shopify metafields/metaobjects documented in the "Guida
 * completa ai campi Shopify" brief and stores them as WordPress custom
 * fields on the matching WooCommerce product, for the Elementor widgets in
 * class-ns-bridge-elementor.php to read.
 *
 * Shopify webhooks don't include custom metafields in their payload, so
 * this always runs as a *supplementary* GraphQL fetch right after the
 * existing REST-based product upsert (webhook, reconciliation, or batch
 * sync) — it never touches title/price/variants/images, which stay on the
 * proven REST path.
 *
 * All single-value fields (text, rich text, single image/video) become one
 * postmeta key each: _ns_bridge_cf_{key}. Repeatable sections (Accordions,
 * Clinical Results, Benefits, Ingredients, FAQ, the two related-product
 * lists) become one postmeta key holding a JSON array of items.
 */
class NS_Bridge_Metafield_Sync {

	const META_PREFIX = '_ns_bridge_cf_';
	const NAMESPACE_ = 'custom';

	/**
	 * key => shape. Shape drives how the raw GraphQL value is turned into
	 * something PHP/Elementor can use directly; it isn't Shopify's own type
	 * name for the field.
	 */
	const FIELDS = [
		'the_science'             => 'richtext',
		'benefits_intro'          => 'richtext',
		'ingredients_intro'       => 'richtext',
		'complex_title'           => 'text',
		'complex_description'     => 'richtext',
		'complex_image'           => 'image',
		'complex_video'           => 'video',
		'complex_image_2'         => 'image',
		'complex_accordions'      => 'metaobject_list',
		'clinical_title'          => 'text',
		'clinical_description'    => 'richtext',
		'clinical_image'          => 'image',
		'clinical_results'        => 'metaobject_list',
		'complete_your_routine'   => 'product_list',
		'product_benefits'        => 'metaobject_list',
		'product_ingredients'     => 'metaobject_list',
		'something_else_products' => 'product_list',
		'product_faqs'            => 'metaobject_list',
	];

	public static function meta_key($field_key) {
		return self::META_PREFIX . $field_key;
	}

	public static function sync_for_product($post_id, $shopify_product_id, NS_Bridge_Shopify_Client $client) {
		$gid  = 'gid://shopify/Product/' . $shopify_product_id;
		$data = $client->graphql(self::build_query(), ['id' => $gid]);

		if (is_wp_error($data)) {
			NS_Bridge_Logger::log('metafield_sync_failed', $data->get_error_message());
			return $data;
		}

		$product = $data['product'] ?? null;
		if (!$product) {
			return;
		}

		$keys = array_keys(self::FIELDS);
		foreach ($keys as $index => $key) {
			$metafield = $product['mf' . $index] ?? null;
			$value     = self::extract_value(self::FIELDS[$key], $metafield);
			update_post_meta($post_id, self::meta_key($key), $value);
		}

		return true;
	}

	private static function build_query() {
		$selections = [];
		foreach (array_keys(self::FIELDS) as $index => $key) {
			$selections[] = 'mf' . $index . ': metafield(namespace: "' . self::NAMESPACE_ . '", key: "' . $key . '") { ...metafieldValue }';
		}
		$selection_str = implode("\n\t\t\t\t", $selections);

		return <<<GRAPHQL
		query GetProductMetafields(\$id: ID!) {
			product(id: \$id) {
				{$selection_str}
			}
		}
		fragment metafieldValue on Metafield {
			key
			type
			value
			reference {
				__typename
				... on MediaImage {
					image { url altText }
				}
				... on Video {
					sources { url }
				}
				... on GenericFile {
					url
				}
			}
			references(first: 50) {
				nodes {
					__typename
					... on Metaobject {
						fields {
							key
							value
							reference {
								__typename
								... on MediaImage {
									image { url altText }
								}
							}
						}
					}
					... on Product {
						id
						handle
					}
				}
			}
		}
		GRAPHQL;
	}

	private static function extract_value($shape, $metafield) {
		if (!$metafield) {
			return in_array($shape, ['metaobject_list', 'product_list'], true) ? wp_json_encode([]) : '';
		}

		switch ($shape) {
			case 'text':
				return sanitize_text_field($metafield['value'] ?? '');

			case 'richtext':
				return NS_Bridge_Rich_Text::to_html($metafield['value'] ?? '');

			case 'image':
				return esc_url_raw($metafield['reference']['image']['url'] ?? '');

			case 'video':
				$ref = $metafield['reference'] ?? [];
				if (!empty($ref['sources'][0]['url'])) {
					return esc_url_raw($ref['sources'][0]['url']);
				}
				return esc_url_raw($ref['url'] ?? '');

			case 'metaobject_list':
				return wp_json_encode(self::extract_metaobject_list($metafield));

			case 'product_list':
				return wp_json_encode(self::extract_product_list($metafield));

			default:
				return '';
		}
	}

	/**
	 * Generic across all 5 metaobject types (Accordion, Clinical Result,
	 * Benefit, Ingredient, FAQ) — no per-type field list needed, since a
	 * metaobject's `fields` connection already gives every field as
	 * key/value. A field holding a rich-text JSON tree is detected and
	 * converted to HTML; anything else (including Clinical Result's plain
	 * multi-line "description") is kept as sanitized plain text.
	 */
	private static function extract_metaobject_list($metafield) {
		$items = [];

		foreach ($metafield['references']['nodes'] ?? [] as $node) {
			if (($node['__typename'] ?? '') !== 'Metaobject') {
				continue;
			}

			$entry = [];
			foreach ($node['fields'] ?? [] as $field) {
				$key = $field['key'] ?? '';
				if ($key === '') {
					continue;
				}

				if (!empty($field['reference']['image']['url'])) {
					$entry[$key] = esc_url_raw($field['reference']['image']['url']);
					continue;
				}

				$raw = $field['value'] ?? '';
				$entry[$key] = NS_Bridge_Rich_Text::looks_like_rich_text($raw)
					? NS_Bridge_Rich_Text::to_html($raw)
					: sanitize_textarea_field($raw);
			}

			$items[] = $entry;
		}

		return $items;
	}

	private static function extract_product_list($metafield) {
		$post_ids = [];

		foreach ($metafield['references']['nodes'] ?? [] as $node) {
			if (($node['__typename'] ?? '') !== 'Product') {
				continue;
			}
			$shopify_id = self::gid_to_numeric_id($node['id'] ?? '');
			$post_id    = $shopify_id ? NS_Bridge_Product_Sync::find_post_id($shopify_id) : null;
			if ($post_id) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
	}

	private static function gid_to_numeric_id($gid) {
		return preg_match('/(\d+)$/', (string) $gid, $m) ? $m[1] : null;
	}
}
