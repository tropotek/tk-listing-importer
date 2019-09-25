<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 * @author     Mick Mifsud <info@tropotek.com>
 */
class Tk_Listing_Importer_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'tk-listing-importer',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
