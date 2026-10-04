<?php
defined('ABSPATH') || exit;

/**
 * One-time setup: creates the 5 metaobject definitions and 18 metafield
 * definitions from the "Guida Shopify Product Page" brief directly via the
 * Shopify Admin GraphQL API, instead of clicking through the Shopify UI by
 * hand — where picking the wrong "type" (e.g. Multi-line text instead of
 * Rich text) silently breaks the sync with no error anywhere, as already
 * happened once with `the_science`.
 *
 * Idempotent: re-running it is safe. A definition that already exists is
 * reported as such, not treated as an error, so this can be triggered
 * again if it's interrupted or if new keys are added to
 * Shopify_Bridge_Metafield_Sync::FIELDS later.
 *
 * Needs the app to (temporarily) hold the write_products and
 * write_metaobject_definitions Admin API scopes — the ongoing sync in the
 * rest of the plugin only ever reads, so these can be removed again from
 * the app's scopes once this has run successfully once.
 */
class Shopify_Bridge_Setup_Definitions {

	/**
	 * metaobject "type" slug => name + field definitions (key => name/type/
	 * required). Matches the real structure confirmed field-by-field on the
	 * live store (Oct 2026) — intended for bootstrapping a FRESH/test store
	 * from scratch; on a store that already has these types (like the live
	 * one), running this just reports "gia' esistente" for each and does
	 * nothing destructive.
	 */
	const METAOBJECTS = [
		'product_accordion' => [
			'name'   => 'Product Accordion',
			'fields' => [
				'title'   => ['name' => 'Title', 'type' => 'single_line_text_field', 'required' => true],
				'content' => ['name' => 'Content', 'type' => 'rich_text_field', 'required' => true],
				'media'   => ['name' => 'Media', 'type' => 'file_reference', 'required' => true],
				'icon'    => ['name' => 'Icon', 'type' => 'file_reference', 'required' => true],
			],
		],
		'clinical_result' => [
			'name'   => 'Clinical Result',
			'fields' => [
				'prefix' => ['name' => 'Prefix', 'type' => 'single_line_text_field', 'required' => true],
				'value'  => ['name' => 'Value', 'type' => 'single_line_text_field', 'required' => true],
				'suffix' => ['name' => 'Suffix', 'type' => 'single_line_text_field', 'required' => true],
				'label'  => ['name' => 'Label', 'type' => 'single_line_text_field', 'required' => true],
			],
		],
		'routine_tab' => [
			'name'   => 'Routine Tab',
			'fields' => [
				'tab_title'       => ['name' => 'Tab Title', 'type' => 'single_line_text_field', 'required' => true],
				'tab_description' => ['name' => 'Tab Description', 'type' => 'rich_text_field', 'required' => true],
				'products'        => ['name' => 'Products', 'type' => 'list.product_reference', 'required' => true],
			],
		],
		'press_quote' => [
			'name'   => 'Press Quote',
			'fields' => [
				'quote'  => ['name' => 'Quote', 'type' => 'multi_line_text_field', 'required' => true],
				'author' => ['name' => 'Author', 'type' => 'single_line_text_field', 'required' => true],
				'logo'   => ['name' => 'Logo', 'type' => 'file_reference', 'required' => true],
			],
		],
		'faq_item' => [
			'name'   => 'FAQ Item',
			'fields' => [
				'question' => ['name' => 'Question', 'type' => 'single_line_text_field', 'required' => true],
				'answer'   => ['name' => 'Answer', 'type' => 'rich_text_field', 'required' => true],
			],
		],
	];

	/**
	 * metafield key => name/type, with an optional "metaobject" pointing at
	 * one of the METAOBJECTS types above for list.metaobject_reference
	 * fields. Keys match Shopify_Bridge_Metafield_Sync::FIELDS exactly. The
	 * 9 entries marked GUESS use a plausible key pending confirmation — fix
	 * here (and in FIELDS) once the real key is confirmed.
	 */
	const METAFIELDS = [
		'product_subtitle'         => ['name' => '01 Hero - Subtitle', 'type' => 'single_line_text_field'],                                                                // CONFIRMED
		'the_science_text'         => ['name' => '02 The Science - Text', 'type' => 'rich_text_field'],                                                                  // GUESS
		'benefits_intro_text'      => ['name' => '03 Benefits - Intro Text', 'type' => 'rich_text_field'],                                                               // GUESS
		'ingredients_intro_text'   => ['name' => '04 Ingredients - Intro Text', 'type' => 'rich_text_field'],                                                            // GUESS
		'how_to_use_steps'         => ['name' => '05 How to Use - Steps', 'type' => 'list.metaobject_reference', 'metaobject' => 'product_accordion'],
		'clinical_title'           => ['name' => '06 Clinical - Title', 'type' => 'single_line_text_field'],                                                             // GUESS
		'clinical_results'         => ['name' => '07 Clinical - Results Counters', 'type' => 'list.metaobject_reference', 'metaobject' => 'clinical_result'],
		'clinical_image'           => ['name' => '08 Clinical - Image', 'type' => 'file_reference'],                                                                     // GUESS
		'clinical_description'     => ['name' => '09 Clinical - Description', 'type' => 'rich_text_field'],                                                              // GUESS
		'routine_title'            => ['name' => '10 Routine - Title', 'type' => 'single_line_text_field'],                                                              // GUESS
		'routine_tabs'             => ['name' => '11 Routine - Tabs', 'type' => 'list.metaobject_reference', 'metaobject' => 'routine_tab'],
		'press_quote'              => ['name' => '12 Press - Quotes', 'type' => 'list.metaobject_reference', 'metaobject' => 'press_quote'],
		'actives_accordion'        => ['name' => '13 Benefits and Actives - Accordion', 'type' => 'list.metaobject_reference', 'metaobject' => 'product_accordion'],
		'also_considered'          => ['name' => '14 Also Considered - Products', 'type' => 'list.product_reference'],
		'faqs'                     => ['name' => '15 FAQ - Questions', 'type' => 'list.metaobject_reference', 'metaobject' => 'faq_item'],
	];

	/** @return string[] one human-readable log line per definition, in creation order. */
	public static function run(Shopify_Bridge_Shopify_Client $client) {
		$log = [];
		$metaobject_ids = [];

		foreach (self::METAOBJECTS as $type => $def) {
			[$message, $id] = self::create_metaobject_definition($client, $type, $def);
			$log[] = $message;

			if (!$id) {
				// Already existed (or the create call failed): try to resolve its id
				// anyway, so dependent metafield definitions below can still be linked.
				$id = self::find_metaobject_definition_id($client, $type);
			}
			if ($id) {
				$metaobject_ids[$type] = $id;
			}
		}

		foreach (self::METAFIELDS as $key => $def) {
			$validations = [];
			if (!empty($def['metaobject'])) {
				if (empty($metaobject_ids[$def['metaobject']])) {
					$log[] = "custom.{$key}: SALTATO — la definizione metaobject '{$def['metaobject']}' non e' disponibile (vedi errore sopra).";
					continue;
				}
				$validations[] = ['name' => 'metaobject_definition_id', 'value' => $metaobject_ids[$def['metaobject']]];
			}
			$log[] = self::create_metafield_definition($client, $key, $def, $validations);
		}

		return $log;
	}

	/** @return array{0: string, 1: ?string} [log message, created definition GID or null] */
	private static function create_metaobject_definition(Shopify_Bridge_Shopify_Client $client, $type, array $def) {
		$field_definitions = [];
		foreach ($def['fields'] as $key => $field) {
			$field_definitions[] = [
				'key'      => $key,
				'name'     => $field['name'],
				'type'     => $field['type'],
				'required' => !empty($field['required']),
			];
		}

		$query = <<<'GRAPHQL'
		mutation CreateMetaobjectDefinition($definition: MetaobjectDefinitionCreateInput!) {
			metaobjectDefinitionCreate(definition: $definition) {
				metaobjectDefinition { id type }
				userErrors { field message code }
			}
		}
		GRAPHQL;

		$data = $client->graphql($query, [
			'definition' => [
				// Plain (merchant-owned) type slugs collide with the "reserved for
				// another application" check on some stores — the $app: prefix
				// makes the type unambiguously owned by this app instead, which
				// Shopify always allows. access.admin keeps entries fully visible
				// and editable by the merchant in Content > Metaobjects, same as
				// a plain merchant-owned type would be.
				'type'             => self::shopify_type($type),
				'name'             => $def['name'],
				'access'           => ['admin' => 'MERCHANT_READ_WRITE'],
				'fieldDefinitions' => $field_definitions,
			],
		]);

		if (is_wp_error($data)) {
			return ["Metaobject '{$type}': errore — " . $data->get_error_message(), null];
		}

		$payload = $data['metaobjectDefinitionCreate'] ?? [];
		$id      = $payload['metaobjectDefinition']['id'] ?? null;
		$errors  = $payload['userErrors'] ?? [];

		if ($id) {
			return ["Metaobject '{$type}': creato.", $id];
		}
		if (self::is_already_exists_error($errors)) {
			return ["Metaobject '{$type}': gia' esistente, non modificato.", null];
		}
		return ["Metaobject '{$type}': errore — " . self::format_errors($errors), null];
	}

	private static function find_metaobject_definition_id(Shopify_Bridge_Shopify_Client $client, $type) {
		$query = <<<'GRAPHQL'
		query FindMetaobjectDefinition($type: String!) {
			metaobjectDefinitionByType(type: $type) { id }
		}
		GRAPHQL;

		$data = $client->graphql($query, ['type' => self::shopify_type($type)]);
		if (is_wp_error($data)) {
			return null;
		}
		return $data['metaobjectDefinitionByType']['id'] ?? null;
	}

	/** Internal slug (used as our own array key / METAFIELDS reference) -> the actual $app:-reserved Shopify type string. */
	private static function shopify_type($slug) {
		return '$app:' . $slug;
	}

	private static function create_metafield_definition($client, $key, array $def, array $validations) {
		$query = <<<'GRAPHQL'
		mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
			metafieldDefinitionCreate(definition: $definition) {
				createdDefinition { id }
				userErrors { field message code }
			}
		}
		GRAPHQL;

		$definition = [
			'name'      => $def['name'],
			'namespace' => Shopify_Bridge_Metafield_Sync::NAMESPACE_,
			'key'       => $key,
			'type'      => $def['type'],
			'ownerType' => 'PRODUCT',
		];
		if ($validations) {
			$definition['validations'] = $validations;
		}

		$data = $client->graphql($query, ['definition' => $definition]);
		if (is_wp_error($data)) {
			return "custom.{$key}: errore — " . $data->get_error_message();
		}

		$payload = $data['metafieldDefinitionCreate'] ?? [];
		$id      = $payload['createdDefinition']['id'] ?? null;
		$errors  = $payload['userErrors'] ?? [];

		if ($id) {
			return "custom.{$key}: creato.";
		}
		if (self::is_already_exists_error($errors)) {
			return "custom.{$key}: gia' esistente, non modificato.";
		}
		return "custom.{$key}: errore — " . self::format_errors($errors);
	}

	private static function is_already_exists_error(array $errors) {
		foreach ($errors as $error) {
			$code    = strtoupper((string) ($error['code'] ?? ''));
			$message = strtolower((string) ($error['message'] ?? ''));
			if ($code === 'TAKEN' || strpos($message, 'already') !== false || strpos($message, 'taken') !== false) {
				return true;
			}
		}
		return false;
	}

	private static function format_errors(array $errors) {
		if (!$errors) {
			return 'errore sconosciuto (risposta senza dettagli).';
		}
		return implode('; ', array_map(function ($e) {
			$field = !empty($e['field']) ? implode('.', (array) $e['field']) . ': ' : '';
			return $field . ($e['message'] ?? '');
		}, $errors));
	}
}
