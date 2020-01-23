<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Tk_Listing_Importer
 * @subpackage Tk_Listing_Importer/includes
 * @author     Mick Mifsud <info@tropotek.com>
 */
class Tk_Listing_Importer {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Tk_Listing_Importer_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'TK_LISTING_IMPORTER_VERSION' ) ) {
			$this->version = TK_LISTING_IMPORTER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		if ( defined( 'TK_LISTING_IMPORTER_NAME' ) ) {
			$this->plugin_name = TK_LISTING_IMPORTER_NAME;
		} else {
			$this->plugin_name = 'tk-listing-importer';
		}

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		//$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Tk_Listing_Importer_Loader. Orchestrates the hooks of the plugin.
	 * - Tk_Listing_Importer_i18n. Defines internationalization functionality.
	 * - Tk_Listing_Importer_Admin. Defines all hooks for the admin area.
	 * - Tk_Listing_Importer_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-tk-listing-importer-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-tk-listing-importer-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-tk-listing-importer-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		//require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-tk-listing-importer-public.php';

		// Load Importer API
		require_once ABSPATH . 'wp-admin/includes/import.php';
		if ( ! class_exists( 'WP_Importer' ) ) {
			$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
			if ( file_exists( $class_wp_importer ) )
				require $class_wp_importer;
		}
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-tk-parsers.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-tk-import.php';

		$this->loader = new Tk_Listing_Importer_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Tk_Listing_Importer_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Tk_Listing_Importer_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

		$this->loader->add_action( 'tk_listing_import', $this, 'run_import' );

        if (WP_DEBUG)
            remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

	}


	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Tk_Listing_Importer_Admin( $this );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// Add menu item
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );

		// Add Settings link to the plugin
		$plugin_basename = plugin_basename( plugin_dir_path( __DIR__ ) . $this->plugin_name . '.php' );
		$this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $plugin_admin, 'add_action_links' );

		// Save/Update our plugin options
		$this->loader->add_action('admin_init', $plugin_admin, 'onInit');
		$this->loader->add_action('admin_init', $plugin_admin, 'options_update');


        $this->loader->add_filter( 'wp_import_post_data_processed', $plugin_admin, 'wp_import_post_data_processed', 10, 2 );
        $this->loader->add_filter( 'wp_import_post_meta', $plugin_admin, 'wp_import_post_meta' , 10, 3);

		//$this->loader->add_action('wp_import_post_meta', $plugin_admin, 'wp_import_post_meta');
		//$this->loader->add_action('import_post_meta', $plugin_admin, 'import_post_meta');
	}

    public function run_import()
    {
		//require_once ABSPATH . 'wp-admin/includes/post.php';  //??????
        //if (!is_admin() ) {
	    require_once( ABSPATH . 'wp-admin/includes/post.php' );
	    require_once( ABSPATH . 'wp-admin/includes/image.php' );
	    require_once( ABSPATH . 'wp-includes/post.php' );
            //require_once( ABSPATH . 'wp-admin/includes/post.php' );
        //}

        try {
            ignore_user_abort(true);
            set_time_limit (0);
            $options = get_option($this->plugin_name);
            if ($options['active']) {
                $importer = Tk_Import::getInstance();
                // WARNING: this will delete all 'listings' from the DB
                $importer->import( $this->getExporterUrl(), true);

                $options = get_option($this->plugin_name);
                $options['lastImport'] = time(); //current_time( 'mysql' );
                update_option($this->plugin_name, $options);
            }
        } catch (Exception $e) {
            Tk_Import::getInstance()->clearLocks();
            error_log($e->__toString());
        }
    }


	public function getExporterUrl()
	{
		$options = get_option($this->plugin_name);
		$url = trim($options['url'], '/') . '/';
		//error_log(print_r($options, true));
        //return sprintf('%s?tk-listing-exporter=%s', $url, urlencode($options['key']));
        return sprintf('%s?tk-listing-exporter=%s', $url, urlencode($this->encrypt($options['key'])));
	}

    public function encrypt($token)
    {
        $cipher_method = 'aes-128-ctr';
        $enc_key = openssl_digest(TK_LISTING_IMPORTER_URL_KEY, 'SHA256', TRUE);
        $enc_iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher_method));
        $crypted_token = openssl_encrypt($token, $cipher_method, $enc_key, 0, $enc_iv) . "::" . bin2hex($enc_iv);
        unset($token, $cipher_method, $enc_key, $enc_iv);
        $crypted_token = base64_encode($crypted_token);
        return $crypted_token;
    }

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
//	private function define_public_hooks() {
//0'
//		$plugin_public = new Tk_Listing_Importer_Public( $this->get_plugin_name(), $this->get_version() );
//
//		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
//		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
//
//	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Tk_Listing_Importer_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
