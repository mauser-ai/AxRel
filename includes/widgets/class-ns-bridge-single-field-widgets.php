<?php
defined('ABSPATH') || exit;

/**
 * One tiny concrete widget per single-value metafield (the ones that
 * aren't a repeatable list, so don't already have their own widget like
 * Accordion or FAQ) — each just points NS_Bridge_Single_Field_Widget_Base
 * at its FIELDS key, so it shows up in the Elementor widget panel under
 * its own name with only the controls relevant to its type, instead of
 * one generic widget with a "pick a field" dropdown.
 */

class NS_Bridge_Widget_The_Science extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_the_science'; }
	public function get_title() { return 'NS Bridge — The Science'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'the_science'; }
}

class NS_Bridge_Widget_Benefits_Intro extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_benefits_intro'; }
	public function get_title() { return 'NS Bridge — Benefits intro'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'benefits_intro'; }
}

class NS_Bridge_Widget_Ingredients_Intro extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_ingredients_intro'; }
	public function get_title() { return 'NS Bridge — Ingredients intro'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'ingredients_intro'; }
}

class NS_Bridge_Widget_Complex_Title extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_complex_title'; }
	public function get_title() { return 'NS Bridge — Complex titolo'; }
	public function get_icon() { return 'eicon-t-letter'; }
	protected function field_key() { return 'complex_title'; }
}

class NS_Bridge_Widget_Complex_Description extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_complex_description'; }
	public function get_title() { return 'NS Bridge — Complex descrizione'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'complex_description'; }
}

class NS_Bridge_Widget_Complex_Image extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_complex_image'; }
	public function get_title() { return 'NS Bridge — Complex immagine'; }
	public function get_icon() { return 'eicon-image'; }
	protected function field_key() { return 'complex_image'; }
}

class NS_Bridge_Widget_Complex_Video extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_complex_video'; }
	public function get_title() { return 'NS Bridge — Complex video'; }
	public function get_icon() { return 'eicon-video-camera'; }
	protected function field_key() { return 'complex_video'; }
}

class NS_Bridge_Widget_Complex_Image_2 extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_complex_image_2'; }
	public function get_title() { return 'NS Bridge — Complex immagine 2'; }
	public function get_icon() { return 'eicon-image'; }
	protected function field_key() { return 'complex_image_2'; }
}

class NS_Bridge_Widget_Clinical_Title extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_clinical_title'; }
	public function get_title() { return 'NS Bridge — Clinical titolo'; }
	public function get_icon() { return 'eicon-t-letter'; }
	protected function field_key() { return 'clinical_title'; }
}

class NS_Bridge_Widget_Clinical_Description extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_clinical_description'; }
	public function get_title() { return 'NS Bridge — Clinical descrizione'; }
	public function get_icon() { return 'eicon-text'; }
	protected function field_key() { return 'clinical_description'; }
}

class NS_Bridge_Widget_Clinical_Image extends NS_Bridge_Single_Field_Widget_Base {
	public function get_name() { return 'ns_bridge_clinical_image'; }
	public function get_title() { return 'NS Bridge — Clinical immagine'; }
	public function get_icon() { return 'eicon-image'; }
	protected function field_key() { return 'clinical_image'; }
}
