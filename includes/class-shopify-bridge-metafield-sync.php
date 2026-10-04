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
 *
 * ARBITRARY NESTING: GraphQL fragments cannot reference themselves (a
 * fragment spread cycle is invalid per the spec), so a single query can't
 * expand a metaobject that references another metaobject that references
 * another metaobject... to unlimited depth. Instead, the main query expands
 * exactly one level inline (cheap, no extra request, covers every field in
 * use today); the moment a sub-field turns out to reference ANOTHER
 * metaobject (single or list), resolve_metaobject() below fetches *that*
 * metaobject's own fields with a dedicated follow-up GraphQL call and
 * recurses — so a future field can nest metaobjects as deep as the data
 * actually goes, not as deep as this file happens to hardcode. A depth cap
 * and a per-sync cache guard against runaway cost and reference cycles.
 */
class Shopify_Bridge_Metafield_Sync {

	const META_PREFIX = '_ns_bridge_cf_';
	const NAMESPACE_ = 'custom';

	/** Safety cap on metaobject-references-metaobject recursion depth — guards against a cyclic reference or an unexpectedly deep tree burning API calls forever. */
	const MAX_METAOBJECT_DEPTH = 6;

	/**
	 * Max items fetched per list (top-level metafield list, or a list nested
	 * inside a metaobject). Shopify caps a single GraphQL query at 1000
	 * "cost" points; every `references(first: N)` connection counts toward
	 * it, and with 15 fields plus nested lists the cost compounds fast — a
	 * real product once hit cost 1441 with this at 50. 10 is generous for
	 * every section in use today (nobody needs 10+ routine tabs or FAQ
	 * entries); raise it only if a real list genuinely needs more, and
	 * re-check the query cost (Shopify-GraphQL-Cost-Debug: 1 request header)
	 * after doing so rather than guessing.
	 */
	const MAX_LIST_ITEMS = 10;

	/**
	 * Shopify can take a few seconds to propagate a newly-saved metaobject
	 * reference through the index that `reference`/`references` reads from —
	 * the metafield's raw `value` (the GID list) updates instantly, but the
	 * connection can still resolve to null nodes for a short window right
	 * after saving. Our webhook fires near-instantly too, so it can race
	 * ahead of that index. When a list field has GIDs in `value` but resolves
	 * to zero items, that's the signature of this race rather than "nothing
	 * was attached" — retry a few times with backoff instead of giving up.
	 */
	const RETRY_HOOK           = 'ns_bridge_retry_metafield_sync';
	const MAX_RETRIES          = 3;
	const RETRY_DELAY_SECONDS  = 30;

	/**
	 * key => shape. Shape drives how the raw GraphQL value is turned into
	 * something PHP/Elementor can use directly; it isn't Shopify's own type
	 * name for the field.
	 *
	 * Confirmed 1:1 against the live "Product metafield definitions" list
	 * (screenshots, Oct 2026). product_subtitle and also_considered are
	 * confirmed; the_science_text, benefits_intro_text, ingredients_intro_text,
	 * clinical_title, clinical_image, clinical_description, routine_title are
	 * still best-guess keys for the remaining 7 simple fields whose exact
	 * `custom.*` key wasn't screenshotted yet — everything else
	 * (how_to_use_steps, clinical_results, routine_tabs, press_quote,
	 * actives_accordion, faqs) is the real key, confirmed field-by-field. Fix
	 * the guessed ones here (single line) once confirmed; nothing else needs
	 * to change.
	 */
	const FIELDS = [
		'product_subtitle'       => 'text',               // CONFIRMED — "01 Hero - Subtitle"
		'the_science_text'       => 'richtext',            // GUESS — "02 The Science - Text"
		'benefits_intro_text'    => 'richtext',             // GUESS — "03 Benefits - Intro Text"
		'ingredients_intro_text' => 'richtext',             // GUESS — "04 Ingredients - Intro Text"
		'how_to_use_steps'       => 'metaobject_list',      // CONFIRMED — Product Accordion
		'clinical_title'         => 'text',                 // GUESS — "06 Clinical - Title"
		'clinical_results'       => 'metaobject_list',      // CONFIRMED — Clinical Result
		'clinical_image'         => 'image',                // GUESS — "08 Clinical - Image"
		'clinical_description'   => 'richtext',             // GUESS — "09 Clinical - Description"
		'routine_title'          => 'text',                 // GUESS — "10 Routine - Title"
		'routine_tabs'           => 'metaobject_list',      // CONFIRMED — Routine Tab (nested products list per tab)
		'press_quote'            => 'metaobject_list',      // CONFIRMED — Press Quote
		'actives_accordion'      => 'metaobject_list',      // CONFIRMED — Product Accordion (same type as how_to_use_steps)
		'also_considered'        => 'product_list',         // CONFIRMED — "14 Also Considered - Products"
		'faqs'                   => 'metaobject_list',      // CONFIRMED — FAQ Item
	];

	public static function meta_key($field_key) {
		return self::META_PREFIX . $field_key;
	}

	public static function sync_for_product($post_id, $shopify_product_id, Shopify_Bridge_Shopify_Client $client, $retry_count = 0) {
		$gid  = 'gid://shopify/Product/' . $shopify_product_id;
		$data = $client->graphql(self::build_query(), ['id' => $gid]);

		if (is_wp_error($data)) {
			Shopify_Bridge_Logger::log('metafield_sync_failed', $data->get_error_message());
			return $data;
		}

		$product = $data['product'] ?? null;
		if (!$product) {
			$error = new WP_Error('ns_bridge_shopify_graphql_empty_product', "GraphQL non ha restituito dati per {$gid} (risposta: " . wp_json_encode($data) . ').');
			Shopify_Bridge_Logger::log('metafield_sync_failed', $error->get_error_message());
			return $error;
		}

		// One cache per product sync: a metaobject referenced from two different
		// places (or twice in a cycle) is only ever fetched once.
		$cache      = [];
		$stale_keys = [];

		$keys = array_keys(self::FIELDS);
		foreach ($keys as $index => $key) {
			$metafield = $product['mf' . $index] ?? null;
			$value     = self::extract_value(self::FIELDS[$key], $metafield, $client, $cache);
			update_post_meta($post_id, self::meta_key($key), $value);

			if (self::is_stale(self::FIELDS[$key], $metafield, $value)) {
				$stale_keys[] = $key;
			}
		}

		if ($stale_keys && $retry_count < self::MAX_RETRIES) {
			$delay = self::RETRY_DELAY_SECONDS * ($retry_count + 1);
			wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, [$post_id, $shopify_product_id, $retry_count + 1]);
			Shopify_Bridge_Logger::log(
				'metafield_sync_retry_scheduled',
				'Campi con riferimenti non ancora risolti (' . implode(', ', $stale_keys) . ') per il post ' . $post_id
					. ' — nuovo tentativo tra ' . $delay . 's (' . ($retry_count + 1) . '/' . self::MAX_RETRIES . ').'
			);
		}

		return true;
	}

	/** WP-Cron callback for RETRY_HOOK — re-runs the sync out-of-request with its own client. */
	public static function retry($post_id, $shopify_product_id, $retry_count) {
		self::sync_for_product($post_id, $shopify_product_id, new Shopify_Bridge_Shopify_Client(), $retry_count);
	}

	/**
	 * True when a metaobject_list/product_list field has GIDs attached
	 * (non-empty raw `value`) but resolved to zero items — the signature of
	 * Shopify's reference index lagging behind a just-saved attachment, not
	 * of the field genuinely being empty. Single-value shapes never race
	 * this way, so they're never "stale".
	 */
	private static function is_stale($shape, $metafield, $resolved_json) {
		if (!in_array($shape, ['metaobject_list', 'product_list'], true) || !$metafield) {
			return false;
		}
		$raw = json_decode($metafield['value'] ?? '', true);
		if (!is_array($raw) || !$raw) {
			return false;
		}
		$resolved = json_decode($resolved_json, true);
		return is_array($resolved) && count($resolved) === 0;
	}

	private static function build_query() {
		$selections = [];
		foreach (array_keys(self::FIELDS) as $index => $key) {
			$selections[] = 'mf' . $index . ': metafield(namespace: "' . self::NAMESPACE_ . '", key: "' . $key . '") { ...metafieldValue }';
		}
		$selection_str = implode("\n\t\t\t\t", $selections);
		$max           = self::MAX_LIST_ITEMS;

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
				... on MediaImage { image { url altText } }
				... on Video { sources { url } }
				... on GenericFile { url }
				... on Metaobject { id }
			}
			references(first: {$max}) {
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
								... on Metaobject { id }
							}
							references(first: {$max}) {
								nodes {
									__typename
									... on Product { id handle }
									... on Metaobject { id }
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

	/** Same shape as the "fields" selection above — used by the follow-up per-metaobject query when recursing beyond the first inline level. */
	private static function metaobject_fields_query() {
		$max = self::MAX_LIST_ITEMS;

		return <<<GRAPHQL
		query GetMetaobjectFields(\$id: ID!) {
			metaobject(id: \$id) {
				fields {
					key
					value
					reference {
						__typename
						... on MediaImage { image { url altText } }
						... on Video { sources { url } }
						... on GenericFile { url }
						... on Metaobject { id }
					}
					references(first: {$max}) {
						nodes {
							__typename
							... on Product { id handle }
							... on Metaobject { id }
						}
					}
				}
			}
		}
		GRAPHQL;
	}

	private static function extract_value($shape, $metafield, Shopify_Bridge_Shopify_Client $client, array &$cache) {
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
				return wp_json_encode(self::extract_metaobject_list($metafield, $client, $cache));

			case 'product_list':
				return wp_json_encode(self::extract_product_list($metafield));

			default:
				return '';
		}
	}

	/**
	 * Generic across every metaobject type used in the product page (How to
	 * Use step, Clinical Result, Routine Tab, Press Quote, Accordion item,
	 * FAQ item, and anything added later) — no per-type field list
	 * hardcoded, since a metaobject's `fields` connection already gives
	 * every sub-field as key/value(/reference/references).
	 */
	private static function extract_metaobject_list($metafield, Shopify_Bridge_Shopify_Client $client, array &$cache) {
		$items = [];

		foreach ($metafield['references']['nodes'] ?? [] as $node) {
			if (($node['__typename'] ?? '') !== 'Metaobject') {
				continue;
			}
			$items[] = self::extract_metaobject_fields($node['fields'] ?? [], $client, $cache, 0);
		}

		// TEMPORARY diagnostic: a list-shaped metafield resolving to zero
		// items is otherwise indistinguishable in the log from "nothing was
		// ever attached" — dump exactly what Shopify sent back for this one
		// field so we can see server-side truth instead of guessing further.
		// Safe to remove once the empty-list issue is understood.
		if (!$items) {
			Shopify_Bridge_Logger::log(
				'metafield_sync_debug_empty_list',
				'key=' . ($metafield['key'] ?? '?') . ' raw=' . wp_json_encode($metafield)
			);
		}

		return $items;
	}

	/**
	 * Per sub-field:
	 * - a File reference (image/video/generic) resolves to ['type','url']
	 * - a reference to ANOTHER metaobject (single) recurses via
	 *   resolve_metaobject() — this is what makes nesting depth-agnostic
	 * - a list of references that are themselves Metaobjects recurses the
	 *   same way, once per item
	 * - a list of Product references resolves to an array of WP post IDs
	 * - anything else is rich-text-or-plain text, auto-detected
	 */
	private static function extract_metaobject_fields(array $fields, Shopify_Bridge_Shopify_Client $client, array &$cache, $depth) {
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
			if ($ref_type === 'Metaobject') {
				$entry[$key] = self::resolve_metaobject($field['reference']['id'] ?? '', $client, $cache, $depth + 1);
				continue;
			}

			$ref_nodes = $field['references']['nodes'] ?? [];
			if ($ref_nodes) {
				$first_type = $ref_nodes[0]['__typename'] ?? '';
				if ($first_type === 'Product') {
					$entry[$key] = self::resolve_product_ids($ref_nodes);
					continue;
				}
				if ($first_type === 'Metaobject') {
					$entry[$key] = array_map(
						function ($node) use ($client, &$cache, $depth) {
							return self::resolve_metaobject($node['id'] ?? '', $client, $cache, $depth + 1);
						},
						$ref_nodes
					);
					continue;
				}
			}

			$raw = $field['value'] ?? '';
			$entry[$key] = Shopify_Bridge_Rich_Text::looks_like_rich_text($raw)
				? Shopify_Bridge_Rich_Text::to_html($raw)
				: sanitize_textarea_field($raw);
		}

		return $entry;
	}

	/**
	 * Fetches one metaobject's own fields via a dedicated GraphQL call and
	 * extracts them the same way as any inline-expanded one — recursing
	 * again for anything nested further. Memoized per sync (a metaobject
	 * referenced twice, or a reference cycle, only ever costs one request);
	 * depth-capped as a last-resort safety net.
	 */
	private static function resolve_metaobject($gid, Shopify_Bridge_Shopify_Client $client, array &$cache, $depth) {
		if ($gid === '') {
			return null;
		}
		if (array_key_exists($gid, $cache)) {
			return $cache[$gid];
		}
		if ($depth > self::MAX_METAOBJECT_DEPTH) {
			Shopify_Bridge_Logger::log('metafield_sync_depth_limit', "Annidamento troncato oltre " . self::MAX_METAOBJECT_DEPTH . " livelli per {$gid} (possibile riferimento circolare).");
			return null;
		}

		// Placeholder before the call resolves: if this exact id is reached
		// again while we're still fetching it (a direct or indirect cycle),
		// the recursive call above gets this null instead of looping forever.
		$cache[$gid] = null;

		$data = $client->graphql(self::metaobject_fields_query(), ['id' => $gid]);
		if (is_wp_error($data)) {
			Shopify_Bridge_Logger::log('metafield_sync_nested_failed', $data->get_error_message());
			return $cache[$gid];
		}

		$fields         = $data['metaobject']['fields'] ?? [];
		$resolved       = self::extract_metaobject_fields($fields, $client, $cache, $depth);
		$cache[$gid]    = $resolved;
		return $resolved;
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
