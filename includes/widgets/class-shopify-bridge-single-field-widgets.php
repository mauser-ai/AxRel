<?php
defined('ABSPATH') || exit;

/**
 * One tiny concrete widget per single-value metafield (the ones that
 * aren't a repeatable list, so don't already have their own widget like
 * Accordion or FAQ) — each just points Shopify_Bridge_Single_Field_Widget_Base
 * at its FIELDS key, so it shows up in the Elementor widget panel under
 * its own name with only the controls relevant to its type, instead of
 * one generic widget with a "pick a field" dropdown.
 *
 * Matches the live Shopify "Product metafield definitions" list (Oct 2026).
 * Keys marked GUESS in Shopify_Bridge_Metafield_Sync::FIELDS are placeholders
 * pending confirmation — fix the field_key() return value here (and the
 * matching line in FIELDS) once confirmed, nothing else changes.
 */

class Shopify_Bridge_Widget_Hero_Subtitle extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_product_subtitle'; }
	public function get_title() { return 'Shopify Bridge — Hero Subtitle'; }
	public function get_icon() { return 'eicon-t-letter'; }
	protected function field_key() { return 'product_subtitle'; }
}

class Shopify_Bridge_Widget_The_Science_Text extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_the_science_text'; }
	public function get_title() { return 'Shopify Bridge — The Science'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'the_science_text'; }
}

class Shopify_Bridge_Widget_Benefits_Intro_Text extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_benefits_intro_text'; }
	public function get_title() { return 'Shopify Bridge — Benefits Intro'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'benefits_intro_text'; }
}

class Shopify_Bridge_Widget_Ingredients_Intro_Text extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_ingredients_intro_text'; }
	public function get_title() { return 'Shopify Bridge — Ingredients Intro'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'ingredients_intro_text'; }
}

class Shopify_Bridge_Widget_Clinical_Title extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_clinical_title'; }
	public function get_title() { return 'Shopify Bridge — Clinical Title'; }
	public function get_icon() { return 'eicon-t-letter'; }
	protected function field_key() { return 'clinical_title'; }
}

class Shopify_Bridge_Widget_Clinical_Image extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_clinical_image'; }
	public function get_title() { return 'Shopify Bridge — Clinical Image'; }
	public function get_icon() { return 'eicon-image'; }
	protected function field_key() { return 'clinical_image'; }
}

class Shopify_Bridge_Widget_Clinical_Description extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_clinical_description'; }
	public function get_title() { return 'Shopify Bridge — Clinical Description'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'clinical_description'; }
}

class Shopify_Bridge_Widget_Routine_Title extends Shopify_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'shopify_bridge_routine_title'; }
	public function get_title() { return 'Shopify Bridge — Routine Title'; }
	public function get_icon() { return 'eicon-t-letter'; }
	protected function field_key() { return 'routine_title'; }
}
