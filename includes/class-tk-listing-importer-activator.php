<?php

/**
 * Fired during plugin activation
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 * @author     Mick Mifsud <info@tropotek.com>
 */
class Tk_Listing_Importer_Activator {


	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate($plugin_name) {

        $options = get_option($plugin_name);
        $update = true;
        if (!$options) {
            $update = false;
            $options = array();
        }
        if (!isset($options['url'])) {
            $options['url'] = '';
        }
        if (!isset($options['userId'])) {
            $options['userId'] = (int)get_current_user_id();
        }
        if (!isset($options['key'])) {
            $options['key'] = '';
        }
        if (!isset($options['active'])) {
            $options['active'] = 1;
        }
        if (!isset($options['lastImport'])) {
            $options['lastImport'] = '';
        }
        //error_log(print_r($options, true));
        if ($update) {
            update_option($plugin_name, $options);
        } else {
            add_option($plugin_name, $options);
        }

		if (WP_DEBUG) {
            //wp_schedule_single_event(time()+120, 'hourly', 'tk_listing_import');
            wp_schedule_event(time()+20, 'hourly', 'tk_listing_import');
        } else {
		    // Randomize it a bit so not all site hit the server at the same time
            //wp_schedule_single_event(strtotime('tomorrow midnight')+((rand(0-8)*60*60)), 'daily', 'tk_listing_import');
            wp_schedule_event(strtotime('tomorrow midnight')+((rand(0-8)*60*60)), 'daily', 'tk_listing_import');
        }
	}

}
