<?php

/**
 * Fired during plugin deactivation
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 * @author     Mick Mifsud <info@tropotek.com>
 */
class Tk_Listing_Importer_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate($plugin_name) {
	    if (!WP_DEBUG)
		    delete_option($plugin_name);
		wp_clear_scheduled_hook('tk_listing_import');
	}

}
