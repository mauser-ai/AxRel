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
 * NS_Bridge_Metafield_Sync::FIELDS later.
 *
 * Needs the app to (temporarily) hold the write_products and
 * write_metaobject_definitions Admin API scopes — the ongoing sync in the
 * rest of the plugin only ever reads, so these can be removed again from
 * the app's scopes once this has run successfully once.
 */
class NS_Bridge_Setup_Definitions {

	/** metaobject "type" slug => name + field definitions (key => name/type/required). */
	const METAOBJECTS = [
		'ns_bridge_accordion_item' => [
			'name'   => 'NS Bridge — Accordion item',
			'fields' => [
				'title'   => ['name' => 'Titolo', 'type' => 'single_line_text_field', 'required' => true],
				'content' => ['name' => 'Contenuto', 'type' => 'rich_text_field', 'required' => false],
			],
		],
		'ns_bridge_clinical_result' => [
			'name'   => 'NS Bridge — Clinical result',
			'fields' => [
				'prefix'      => ['name' => 'Prefisso', 'type' => 'single_line_text_field', 'required' => false],
				'value'       => ['name' => 'Valore', 'type' => 'single_line_text_field', 'required' => true],
				'suffix'      => ['name' => 'Suffisso', 'type' => 'single_line_text_field', 'required' => false],
				'label'       => ['name' => 'Etichetta', 'type' => 'single_line_text_field', 'required' => true],
				'description' => ['name' => 'Descrizione', 'type' => 'multi_line_text_field', 'required' => false],
			],
		],
		'ns_bridge_product_benefit' => [
			'name'   => 'NS Bridge — Product benefit',
			'fields' => [
				'image'       => ['name' => 'Immagine', 'type' => 'file_reference', 'required' => false],
				'title'       => ['name' => 'Titolo', 'type' => 'single_line_text_field', 'required' => true],
				'description' => ['name' => 'Descrizione', 'type' => 'multi_line_text_field', 'required' => false],
			],
		],
		'ns_bridge_product_ingredient' => [
			'name'   => 'NS Bridge — Product ingredient',
			'fields' => [
				'image'       => ['name' => 'Immagine', 'type' => 'file_reference', 'required' => false],
				'name'        => ['name' => 'Nome', 'type' => 'single_line_text_field', 'required' => true],
				'percentage'  => ['name' => 'Percentuale', 'type' => 'single_line_text_field', 'required' => false],
				'subtitle'    => ['name' => 'Sottotitolo', 'type' => 'single_line_text_field', 'required' => false],
				'description' => ['name' => 'Descrizione', 'type' => 'multi_line_text_field', 'required' => false],
			],
		],
		'ns_bridge_product_faq' => [
			'name'   => 'NS Bridge — Product FAQ',
			'fields' => [
				'question' => ['name' => 'Domanda', 'type' => 'single_line_text_field', 'required' => true],
				'answer'   => ['name' => 'Risposta', 'type' => 'rich_text_field', 'required' => false],
			],
		],
	];

	/**
	 * metafield key => name/type, with an optional "metaobject" pointing at
	 * one of the METAOBJECTS types above for list.metaobject_reference
	 * fields. Keys match NS_Bridge_Metafield_Sync::FIELDS exactly.
	 */
	const METAFIELDS = [
		'the_science'             => ['name' => 'The Science', 'type' => 'rich_text_field'],
		'benefits_intro'          => ['name' => 'Benefits — intro', 'type' => 'rich_text_field'],
		'ingredients_intro'       => ['name' => 'Ingredients — intro', 'type' => 'rich_text_field'],
		'complex_title'           => ['name' => 'Regenerative Moisture Complex — titolo', 'type' => 'single_line_text_field'],
		'complex_description'     => ['name' => 'Regenerative Moisture Complex — descrizione', 'type' => 'rich_text_field'],
		'complex_image'           => ['name' => 'Regenerative Moisture Complex — immagine', 'type' => 'file_reference'],
		'complex_video'           => ['name' => 'Regenerative Moisture Complex — video', 'type' => 'file_reference'],
		'complex_image_2'         => ['name' => 'Regenerative Moisture Complex — immagine 2', 'type' => 'file_reference'],
		'complex_accordions'      => ['name' => 'Regenerative Moisture Complex — accordion', 'type' => 'list.metaobject_reference', 'metaobject' => 'ns_bridge_accordion_item'],
		'clinical_title'          => ['name' => 'Clinical Testing Results — titolo', 'type' => 'single_line_text_field'],
		'clinical_description'    => ['name' => 'Clinical Testing Results — descrizione', 'type' => 'rich_text_field'],
		'clinical_image'          => ['name' => 'Clinical Testing Results — immagine', 'type' => 'file_reference'],
		'clinical_results'        => ['name' => 'Clinical Testing Results — risultati', 'type' => 'list.metaobject_reference', 'metaobject' => 'ns_bridge_clinical_result'],
		'complete_your_routine'   => ['name' => 'Complete Your Routine — prodotti', 'type' => 'list.product_reference'],
		'product_benefits'        => ['name' => 'Benefits & Ingredients — benefits', 'type' => 'list.metaobject_reference', 'metaobject' => 'ns_bridge_product_benefit'],
		'product_ingredients'     => ['name' => 'Benefits & Ingredients — ingredients', 'type' => 'list.metaobject_reference', 'metaobject' => 'ns_bridge_product_ingredient'],
		'something_else_products' => ['name' => 'Something Else? — prodotti', 'type' => 'list.product_reference'],
		'product_faqs'            => ['name' => 'FAQ', 'type' => 'list.metaobject_reference', 'metaobject' => 'ns_bridge_product_faq'],
	];

	/** @return string[] one human-readable log line per definition, in creation order. */
	public static function run(NS_Bridge_Shopify_Client $client) {
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
	private static function create_metaobject_definition(NS_Bridge_Shopify_Client $client, $type, array $def) {
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
				'type'             => $type,
				'name'             => $def['name'],
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

	private static function find_metaobject_definition_id(NS_Bridge_Shopify_Client $client, $type) {
		$query = <<<'GRAPHQL'
		query FindMetaobjectDefinition($type: String!) {
			metaobjectDefinitionByType(type: $type) { id }
		}
		GRAPHQL;

		$data = $client->graphql($query, ['type' => $type]);
		if (is_wp_error($data)) {
			return null;
		}
		return $data['metaobjectDefinitionByType']['id'] ?? null;
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
			'namespace' => NS_Bridge_Metafield_Sync::NAMESPACE_,
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
