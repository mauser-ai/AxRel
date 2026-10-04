<?php
defined('ABSPATH') || exit;

/**
 * Pulls the real Shopify metafield/metaobject structure (as defined on the
 * live store under Impostazioni > Dati personalizzati — confirmed field by
 * field with the client, not guessed from the original brief) and stores it
 * as WordPress custom fields on the matching WooCommerce product, for the
 * Elementor widgets in includes/widgets/ to read.
 *
 * Shopify webhooks don't include custom metafields in their payload, so
 * this always runs as a *supplementary* GraphQL fetch right after the
 * existing REST-based product upsert (webhook, reconciliation, or batch
 * sync) — it never touches title/price/variants/images, which stay on the
 * proven REST path.
 *
 * All single-value fields (text, rich text, single image/video) become one
 * postmeta key each: _ns_bridge_cf_{key}. Repeatable sections (How to Use,
 * Clinical Results, Routine Tabs, Press Quotes, Benefits/Actives Accordion,
 * FAQ) become one postmeta key holding a JSON array of items.
 */
class Shopify_Bridge_Metafield_Sync {

	const META_PREFIX = '_ns_bridge_cf_';
	const NAMESPACE_ = 'custom';

	/**
	 * key => shape. Shape drives how the raw GraphQL value is turned into
	 * something PHP/Elementor can use directly; it isn't Shopify's own type
	 * name for the field.
	 *
	 * Confirmed 1:1 against the live "Product metafield definitions" list
	 * (screenshots, Oct 2026): hero_subtitle, the_science_text,
	 * benefits_intro_text, ingredients_intro_text, clinical_title,
	 * clinical_image, clinical_description, routine_title,
	 * also_considered_products are our best-guess keys for the 9 simple
	 * fields whose exact `custom.*` key wasn't screenshotted yet — everything
	 * else (how_to_use_steps, clinical_results, routine_tabs, press_quote,
	 * actives_accordion, faqs) is the real key, confirmed field-by-field.
	 * Fix the 9 guessed ones here (single line) once confirmed; nothing else
	 * needs to change.
	 */
	const FIELDS = [
		'hero_subtitle'            => 'text',            // GUESS — "01 Hero - Subtitle"
		'the_science_text'         => 'richtext',         // GUESS — "02 The Science - Text"
		'benefits_intro_text'      => 'richtext',         // GUESS — "03 Benefits - Intro Text"
		'ingredients_intro_text'   => 'richtext',         // GUESS — "04 Ingredients - Intro Text"
		'how_to_use_steps'         => 'metaobject_list',  // CONFIRMED — Product Accordion
		'clinical_title'           => 'text',             // GUESS — "06 Clinical - Title"
		'clinical_results'         => 'metaobject_list',  // CONFIRMED — Clinical Result
		'clinical_image'           => 'image',            // GUESS — "08 Clinical - Image"
		'clinical_description'     => 'richtext',         // GUESS — "09 Clinical - Description"
		'routine_title'            => 'text',             // GUESS — "10 Routine - Title"
		'routine_tabs'             => 'metaobject_list',  // CONFIRMED — Routine Tab (nested products list per tab)
		'press_quote'              => 'metaobject_list',  // CONFIRMED — Press Quote
		'actives_accordion'        => 'metaobject_list',  // CONFIRMED — Product Accordion (same type as how_to_use_steps)
		'also_considered_products' => 'product_list',     // GUESS — "14 Also Considered - Products"
		'faqs'                     => 'metaobject_list',  // CONFIRMED — FAQ Item
	];

	public static function meta_key($field_key) {
		return self::META_PREFIX . $field_key;
	}

	public static function sync_for_product($post_id, $shopify_product_id, Shopify_Bridge_Shopify_Client $client) {
		$gid  = 'gid://shopify/Product/' . $shopify_product_id;
		$data = $client->graphql(self::build_query(), ['id' => $gid]);

		if (is_wp_error($data)) {
			Shopify_Bridge_Logger::log('metafield_sync_failed', $data->get_error_message());
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
								... on MediaImage { image { url altText } }
								... on Video { sources { url } }
								... on GenericFile { url }
							}
							references(first: 50) {
								nodes {
									__typename
									... on Product { id handle }
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
				return Shopify_Bridge_Rich_Text::to_html($metafield['value'] ?? '');

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
	 * Generic across every metaobject type used in the product page (How to
	 * Use step, Clinical Result, Routine Tab, Press Quote, Accordion item,
	 * FAQ item, ...) — no per-type field list hardcoded, since a metaobject's
	 * `fields` connection already gives every sub-field as key/value(/
	 * reference/references). Per sub-field:
	 * - a File reference (image/video/generic) resolves to ['url','type']
	 * - a list of Product references (e.g. Routine Tab's "products") resolves
	 *   to an array of WordPress post IDs, same as a top-level product_list
	 * - anything else is rich-text-or-plain text, auto-detected
	 */
	private static function extract_metaobject_list($metafield) {
		$items = [];

		foreach ($metafield['references']['nodes'] ?? [] as $node) {
			if (($node['__typename'] ?? '') !== 'Metaobject') {
				continue;
			}
			$items[] = self::extract_metaobject_fields($node['fields'] ?? []);
		}

		return $items;
	}

	private static function extract_metaobject_fields(array $fields) {
		$entry = [];

		foreach ($fields as $field) {
			$key = $field['key'] ?? '';
			if ($key === '') {
				continue;
			}

			$ref_type = $field['reference']['__typename'] ?? '';
			if ($ref_type === 'MediaImage') {
				$entry[$key] = ['type' => 'image', 'url' => esc_url_raw($field['reference']['image']['url'] ?? '')];
				continue;
			}
			if ($ref_type === 'Video') {
				$entry[$key] = ['type' => 'video', 'url' => esc_url_raw($field['reference']['sources'][0]['url'] ?? '')];
				continue;
			}
			if ($ref_type === 'GenericFile') {
				$entry[$key] = ['type' => 'file', 'url' => esc_url_raw($field['reference']['url'] ?? '')];
				continue;
			}

			$ref_nodes = $field['references']['nodes'] ?? [];
			if ($ref_nodes && ($ref_nodes[0]['__typename'] ?? '') === 'Product') {
				$entry[$key] = self::resolve_product_ids($ref_nodes);
				continue;
			}

			$raw = $field['value'] ?? '';
			$entry[$key] = Shopify_Bridge_Rich_Text::looks_like_rich_text($raw)
				? Shopify_Bridge_Rich_Text::to_html($raw)
				: sanitize_textarea_field($raw);
		}

		return $entry;
	}

	private static function extract_product_list($metafield) {
		return self::resolve_product_ids($metafield['references']['nodes'] ?? []);
	}

	private static function resolve_product_ids(array $nodes) {
		$post_ids = [];
		foreach ($nodes as $node) {
			if (($node['__typename'] ?? '') !== 'Product') {
				continue;
			}
			$shopify_id = self::gid_to_numeric_id($node['id'] ?? '');
			$post_id    = $shopify_id ? Shopify_Bridge_Product_Sync::find_post_id($shopify_id) : null;
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
