<?php
defined('ABSPATH') || exit;

/**
 * Converts Shopify's rich_text_field JSON AST into HTML. Shopify's
 * "Rich text" metafield type does NOT store HTML — it stores a proprietary
 * JSON tree (root -> paragraph/list/heading/link/text nodes, with
 * bold/italic flags on text nodes) — and there's no server-side "give me
 * HTML" resolver for it in the Admin API, so this has to happen on our side.
 */
class NS_Bridge_Rich_Text {

	public static function to_html($json_value) {
		$data = json_decode((string) $json_value, true);
		if (!is_array($data) || empty($data['children']) || !is_array($data['children'])) {
			return '';
		}
		return self::render_nodes($data['children']);
	}

	/** True if $value looks like a rich-text JSON tree rather than plain text. */
	public static function looks_like_rich_text($value) {
		$decoded = json_decode((string) $value, true);
		return is_array($decoded) && ($decoded['type'] ?? '') === 'root';
	}

	private static function render_nodes(array $nodes) {
		$html = '';
		foreach ($nodes as $node) {
			$html .= self::render_node($node);
		}
		return $html;
	}

	private static function render_node($node) {
		if (!is_array($node)) {
			return '';
		}

		$type     = $node['type'] ?? '';
		$children = (isset($node['children']) && is_array($node['children'])) ? self::render_nodes($node['children']) : '';

		switch ($type) {
			case 'paragraph':
				return '<p>' . $children . '</p>';

			case 'heading':
				$level = max(1, min(6, (int) ($node['level'] ?? 2)));
				return "<h{$level}>{$children}</h{$level}>";

			case 'list':
				$tag = (($node['listType'] ?? 'unordered') === 'ordered') ? 'ol' : 'ul';
				return "<{$tag}>{$children}</{$tag}>";

			case 'list-item':
				return '<li>' . $children . '</li>';

			case 'link':
				return '<a href="' . esc_url($node['url'] ?? '#') . '">' . $children . '</a>';

			case 'text':
				$text = esc_html($node['value'] ?? '');
				if (!empty($node['bold'])) {
					$text = '<strong>' . $text . '</strong>';
				}
				if (!empty($node['italic'])) {
					$text = '<em>' . $text . '</em>';
				}
				return $text;

			default:
				return $children;
		}
	}
}
