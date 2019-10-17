<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/admin
 * @author     Mick Mifsud <info@tropotek.com>
 */
class Tk_Listing_Importer_Admin {

	/**
	 * @var null|Tk_Listing_Importer
	 */
	private $plugin = null;

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      Tk_Listing_Importer    $plugin
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->plugin_name = $plugin->get_plugin_name();
		$this->version = $plugin->get_version();


	}

	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 *
	 * NOTE:  Alternative menu locations are available via WordPress administration menu functions.
	 *        Administration Menus: http://codex.wordpress.org/Administration_Menus
	 *
	 * @since    1.0.0
	 */
	public function add_plugin_admin_menu() {

		add_options_page( 'Import Settings', 'Listing Import',
			'manage_options', $this->plugin_name, array($this, 'display_plugin_setup_page')
		);
	}

	/**
	 * Add settings action link to the plugins page.
	 *
	 *  Documentation : https://codex.wordpress.org/Plugin_API/Filter_Reference/plugin_action_links_(plugin_file_name)
	 *
	 * @since    1.0.0
	 */
	public function add_action_links( $links ) {

		$settings_link = array(
			'<a href="' . admin_url( 'options-general.php?page=' . $this->plugin_name ) . '">' .
			__('Settings', $this->plugin_name) . '</a>',
		);
		return array_merge(  $settings_link, $links );
	}


	public function onInit() {

		if (!empty($_GET['clear-locks'])) {
            Tk_Import::getInstance()->clearLocks();
            $_SESSION['tk-listing-importer_import-success'] = array(
                __( 'Locks Successfully Deleted.', $this->plugin_name )
            );
			// Add a success message and redirect
			wp_safe_redirect('options-general.php?page=tk-listing-importer');
			exit();
        } else if (!empty($_GET['import'])) {
			if (!empty($_GET['dl'])) {  // Download xml
                error_log($this->plugin->getExporterUrl());
                wp_redirect($this->plugin->getExporterUrl());
                //wp_safe_redirect($this->plugin->getExporterUrl());
                exit();
			}
            $_SESSION['tk-listing-importer_import-run'] = true;
            // Add a success message and redirect
            wp_safe_redirect('options-general.php?page=tk-listing-importer');
            exit();
		}


		// Do this to avoid page reload re-running the importer
		if (!empty($_SESSION['tk-listing-importer_import-run'])) {
            $_SESSION['tk-listing-importer_import-run'] = null;
            unset($_SESSION['tk-listing-importer_import-run']);

            try {       // TODO: would be handy to run this as an external process...
                $this->plugin->run_import();
                $_SESSION['tk-listing-importer_import-success'] = array(
                    __( 'Import Successfully Completed.', $this->plugin_name ) . ' [Duration: '.Tk_Import::getInstance()->getDuration().']'
                );
            } catch (Exception $e) {
			    error_log($e->__toString());
			    Tk_Import::getInstance()->clearLocks();
                $_SESSION['tk-listing-importer_import-error'] = array(
                    $e->getMessage()
                );
            }
			// Add a success message and redirect
			wp_safe_redirect('options-general.php?page=tk-listing-importer');
			exit();
        }

    }

	/**
	 * Render the settings page for this plugin.
	 *
	 * @since    1.0.0
	 */
	public function display_plugin_setup_page() {

		include_once('partials/tk-listing-importer-admin-display.php');

	}


	/**
	*
	* admin/class-wp-cbf-admin.php
	*
	*/
	public function options_update() {
		register_setting($this->plugin_name, $this->plugin_name, array($this, 'validate'));

        // TODO: Add a success message and redirect
        if (isset($_SESSION['tk-listing-importer_import-success'])) {
            foreach ($_SESSION['tk-listing-importer_import-success'] as $msg) {
                add_settings_error('import-success', '', $msg, 'updated');
            }
            $_SESSION['tk-listing-importer_import-success'] = null;
            unset($_SESSION['tk-listing-importer_import-success']);
        }
        if (isset($_SESSION['tk-listing-importer_import-error'])) {
            foreach ($_SESSION['tk-listing-importer_import-error'] as $msg) {
                add_settings_error('import-error', '', $msg, 'error');
            }
            $_SESSION['tk-listing-importer_import-error'] = null;
            unset($_SESSION['tk-listing-importer_import-error']);
        }
	}

	/**
	 *
	 * admin/class-wp-cbf-admin.php
	 *
	 */
	public function validate($input) {
		// All checkboxes inputs
		$valid = array();

        $options = get_option($this->plugin_name);
		//Cleanup
		$valid['url'] = (isset($input['url']) && !empty($input['url'])) ? $input['url'] : '';
		$valid['userId'] = (!empty($input['userId'])) ? (int)$input['userId'] : (int)get_current_user_id();
		$valid['key'] = (isset($input['key']) && !empty($input['key'])) ? $input['key'] : '';
		$valid['active'] = (isset($input['active']) && !empty($input['active'])) ? $input['active'] : false;
        $valid['lastImport'] = $options['lastImport'];


		if (filter_var($valid['url'], FILTER_VALIDATE_URL) === FALSE) {
			add_settings_error(
				'url',                     // Setting title
				'importer_url_texterror',            // Error ID
				'Please enter a valid `Provider URL`',     // Error message
				'error'                         // Type of message
			);
		}
		if (empty($valid['key']) || strlen($valid['key']) > 32) {
			add_settings_error(
				'key',                     // Setting title
				'importer_key_texterror',            // Error ID
				'Please enter a valid `Security Key`',     // Error message
				'error'                         // Type of message
			);
		}

        $valid['lastImport'] = $options['lastImport'];

        //error_log(print_r($valid, true));

		return $valid;
	}






	/**
	 * Register the stylesheets for the admin area.
	 *
	 * An instance of this class should be passed to the run() function
	 * defined in Tk_Listing_Importer_Loader as all of the hooks are defined
	 * in that particular class.
	 *
	 * The Tk_Listing_Importer_Loader will then create the relationship
	 * between the defined hooks and the functions defined in this
	 * class.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/tk-listing-importer-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * An instance of this class should be passed to the run() function
	 * defined in Tk_Listing_Importer_Loader as all of the hooks are defined
	 * in that particular class.
	 *
	 * The Tk_Listing_Importer_Loader will then create the relationship
	 * between the defined hooks and the functions defined in this
	 * class.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/tk-listing-importer-admin.js', array( 'jquery' ), $this->version, false );

	}

}
