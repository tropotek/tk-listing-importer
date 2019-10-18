<?php

if ( !class_exists( 'WP_Importer' ) ) return;


/**
 * Version number for the export format.
 * Bump this when something changes that might affect compatibility.
 * @since 2.5.0
 */
class Tk_Import extends WP_Importer  {

    protected static $instance = null;

    protected $startTime = 0;

    protected $lockFile = '';

	protected $log = true;

	protected $messages = array();

	protected $plugin_name = '';

	/**
	 * @var wpdb|null
	 */
	protected $wpdb= null;



	protected $max_wxr_version = 1.2; // max. supported WXR version

	protected $id; // WXR attachment ID

	// information to import from WXR file
	protected $version;
	protected $authors = array();
	protected $posts = array();
	protected $terms = array();
	protected $categories = array();
	protected $tags = array();
	protected $base_url = '';

	// mappings from old information to new
	protected $processed_authors = array();
	protected $author_mapping = array();
	protected $processed_terms = array();
	protected $processed_posts = array();
	protected $post_orphans = array();
	protected $processed_menu_items = array();
	protected $menu_item_orphans = array();
	protected $missing_menu_items = array();

	protected $fetch_attachments = true;
	protected $url_remap = array();
	protected $featured_images = array();


	public function __construct()
	{
		parent::__construct();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->plugin_name = TK_LISTING_IMPORTER_NAME;
		$this->lockFile = ABSPATH . 'wp-content/uploads/tk-importer.lock';
		$this->imageLockFile = ABSPATH . 'wp-content/uploads/tk-importer_images.lock';
	}

    /**
     * @return Tk_Import|null
     */
	public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getDuration($format = true)
    {
        $time = (microtime(true) - $this->startTime);
        if ($format) {
            $time = gmdate("H:i:s", $time);
        }
        return $time;
    }

    /**
	 * @param string $file
	 * @param bool $deleteExistingListings
	 *
	 * @throws Exception
	 */
	public function import($file, $deleteExistingListings = false)
	{
	    $this->startTime = microtime(true);

	    if (!function_exists('post_exists')) {
            throw new Exception(__('Import Error: post_exists() function not found.'));
        }
        if (is_file($this->lockFile)) {
            $this->log("ERROR: Lock File Exists: " . $this->lockFile);
            throw new Exception(__('Import already running, you can manually clear the locks if you are sure no import is running.'));
        }
        file_put_contents($this->lockFile, time());

		$this->log( '--> Importer Running: ' . $file );

		// This is the only way for now,
		// if not deleting existing we will skip posts as there is no easy way to update posts.
		// however we will only download media files that do not exist, this means old media will not be cleaned up.
		// TODO: Work out a way to delete un-used media files.
		if ($deleteExistingListings) {
            //$this->delete_post($this->getImportUser());
            $this->delete_post();
        }

		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start( $file );

		//$this->get_author_mapping();

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_tags();
		$this->process_terms();
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		// update incorrect/missing information in the DB
		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		$this->import_end();

		$this->clearLocks();
	}

	public function clearLocks()
    {
        if (is_file($this->lockFile))
            @unlink($this->lockFile);
    }

    public function hasLock()
    {
        return is_file($this->lockFile);
    }

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout( $val ) {
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}


	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing////
	 *
	 * @throws Exception
	 */
	function import_start($file) {
		if ( !is_file($file) && filter_var($file, FILTER_VALIDATE_URL) === FALSE) {
			throw new Exception(__( 'The file does not exist, please try again.', $this->plugin_name ));
		}
		$import_data = $this->parse($file);
		if (is_wp_error($import_data)) {
			throw new Exception($import_data->get_error_message());
		}

		$this->version = $import_data['version'];
		$this->get_authors_from_import( $import_data );
		$this->posts = $import_data['posts'];
		$this->terms = $import_data['terms'];
		$this->categories = $import_data['categories'];
		$this->tags = $import_data['tags'];
		$this->base_url = esc_url( $import_data['base_url'] );

		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);

		do_action( 'import_start' );
	}


	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {
		wp_import_cleanup( $this->id );

		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$this->log(__( 'All done.', $this->plugin_name ));
		//echo '<p>' . __( 'All done.', $this->plugin_name ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', $this->plugin_name ) . '</a>' . '</p>';

		do_action( 'import_end' );
	}

    protected function bytes2Str($size)
    {
        $unit=array('b','K','M','G','T','P');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	function process_posts() {
		$this->posts = apply_filters( 'wp_import_posts', $this->posts );
		$valid_post_types = array('listings', 'attachment');

		foreach ( $this->posts as $post ) {
            gc_collect_cycles();

			$post = apply_filters( 'wp_import_post_data_raw', $post );

			if ( ! post_type_exists( $post['post_type'] ) ) {
				$this->log(
					sprintf( __( 'Failed to import &#8220;%s&#8221;: Invalid post type %s', $this->plugin_name ),
						esc_html($post['post_title']), esc_html($post['post_type']) )
				);
				do_action( 'wp_import_post_exists', $post );
				continue;
			}

			if ( isset( $this->processed_posts[$post['post_id']] ) && ! empty( $post['post_id'] ) )
				continue;

			if ( $post['status'] == 'auto-draft' )
				continue;

			if ( 'nav_menu_item' == $post['post_type'] ) {
				$this->process_menu_item( $post );
				continue;
			}

			if (!in_array($post['post_type'], $valid_post_types)) {
				// invalid post type
				continue;
			}

            $this->log(
                '['.$this->getDuration().'] [' . $this->bytes2Str(memory_get_usage(true)) . '][ID: '. $post['post_id'] .'][' . $post['post_type'] . '] ' . $post['post_name']
            );

			$post_type_object = get_post_type_object( $post['post_type'] );
			$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );


			/**
			 * Filter ID of the existing post corresponding to post currently importing.
			 *
			 * Return 0 to force the post to be imported. Filter the ID to be something else
			 * to override which existing post is mapped to the imported post.
			 *
			 * @see post_exists()
			 * @since 0.6.2
			 *
			 * @param int   $post_exists  Post ID, or 0 if post did not exist.
			 * @param array $post         The post array to be inserted.
			 */
			$post_exists = apply_filters( 'wp_import_existing_post', $post_exists, $post );

			if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
				$this->log(
					sprintf( __('%s &#8220;%s&#8221; already exists.', $this->plugin_name), $post_type_object->labels->singular_name, esc_html($post['post_title']) )
				);
				$comment_post_ID = $post_id = $post_exists;
				$this->processed_posts[ intval( $post['post_id'] ) ] = intval( $post_exists );
			} else {
				$post_parent = (int) $post['post_parent'];
				if ( $post_parent ) {
					// if we already know the parent, map it to the new local ID
					if ( isset( $this->processed_posts[$post_parent] ) ) {
						$post_parent = $this->processed_posts[$post_parent];
						// otherwise record the parent for later
					} else {
						$this->post_orphans[intval($post['post_id'])] = $post_parent;
						$post_parent = 0;
					}
				}

				// map the post author
                $author = $this->getImportUserId();

				$postdata = array(
					'import_id' => $post['post_id'], 'post_author' => $author, 'post_date' => $post['post_date'],
					'post_date_gmt' => $post['post_date_gmt'], 'post_content' => $post['post_content'],
					'post_excerpt' => $post['post_excerpt'], 'post_title' => $post['post_title'],
					'post_status' => $post['status'], 'post_name' => $post['post_name'],
					'comment_status' => $post['comment_status'], 'ping_status' => $post['ping_status'],
					'guid' => $post['guid'], 'post_parent' => $post_parent, 'menu_order' => $post['menu_order'],
					'post_type' => $post['post_type'], 'post_password' => $post['post_password']
				);

				$original_post_ID = $post['post_id'];
				$postdata = apply_filters( 'wp_import_post_data_processed', $postdata, $post );
				$postdata = wp_slash( $postdata );
				if ( 'attachment' == $postdata['post_type'] ) {
					$remote_url = ! empty($post['attachment_url']) ? $post['attachment_url'] : $post['guid'];
					// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
					// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
					$postdata['upload_date'] = $post['post_date'];
					if ( isset( $post['postmeta'] ) ) {
						foreach( $post['postmeta'] as $meta ) {
							if ( $meta['key'] == '_wp_attached_file' ) {
								if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $meta['value'], $matches ) )
									$postdata['upload_date'] = $matches[0];
								break;
							}
						}
					}
					$comment_post_ID = $post_id = $this->process_attachment( $postdata, $remote_url );
				} else {
					$comment_post_ID = $post_id = wp_insert_post( $postdata, true );
					do_action( 'wp_import_insert_post', $post_id, $original_post_ID, $postdata, $post );
				}

				if ( is_wp_error( $post_id ) ) {
					$this->log(
						sprintf( __( 'Failed to import %s &#8220;%s&#8221;', $this->plugin_name ),
							$post_type_object->labels->singular_name, esc_html($post['post_title']) )
					);
					if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
						$this->log('    : ' . $post_id->get_error_message());
					continue;
				}

				if ( $post['is_sticky'] == 1 )
					stick_post( $post_id );
			}

			// map pre-import ID to local ID
			$this->processed_posts[intval($post['post_id'])] = (int) $post_id;

			if ( ! isset( $post['terms'] ) )
				$post['terms'] = array();

			$post['terms'] = apply_filters( 'wp_import_post_terms', $post['terms'], $post_id, $post );

			// add categories, tags and other terms
			if ( ! empty( $post['terms'] ) ) {
				$terms_to_set = array();
				foreach ( $post['terms'] as $term ) {
					// back compat with WXR 1.0 map 'tag' to 'post_tag'
					$taxonomy = ( 'tag' == $term['domain'] ) ? 'post_tag' : $term['domain'];
					$term_exists = term_exists( $term['slug'], $taxonomy );
					$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
					if ( ! $term_id ) {
						$t = wp_insert_term( $term['name'], $taxonomy, array( 'slug' => $term['slug'] ) );
						if ( ! is_wp_error( $t ) ) {
							$term_id = $t['term_id'];
							do_action( 'wp_import_insert_term', $t, $term, $post_id, $post );
						} else {
							$this->log(
								sprintf( __( 'Failed to import %s %s', $this->plugin_name ), esc_html($taxonomy), esc_html($term['name']) )
							);
							if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
								$this->log( '    : ' . $t->get_error_message());
							do_action( 'wp_import_insert_term_failed', $t, $term, $post_id, $post );
							continue;
						}
					}
					$terms_to_set[$taxonomy][] = intval( $term_id );
				}

				foreach ( $terms_to_set as $tax => $ids ) {
					$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
					do_action( 'wp_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $post );
				}
				unset( $post['terms'], $terms_to_set );
			}

			if ( ! isset( $post['comments'] ) )
				$post['comments'] = array();

			$post['comments'] = apply_filters( 'wp_import_post_comments', $post['comments'], $post_id, $post );

			// add/update comments
			if ( ! empty( $post['comments'] ) ) {
				$num_comments = 0;
				$inserted_comments = array();
				foreach ( $post['comments'] as $comment ) {
					$comment_id	= $comment['comment_id'];
					$newcomments[$comment_id]['comment_post_ID']      = $comment_post_ID;
					$newcomments[$comment_id]['comment_author']       = $comment['comment_author'];
					$newcomments[$comment_id]['comment_author_email'] = $comment['comment_author_email'];
					$newcomments[$comment_id]['comment_author_IP']    = $comment['comment_author_IP'];
					$newcomments[$comment_id]['comment_author_url']   = $comment['comment_author_url'];
					$newcomments[$comment_id]['comment_date']         = $comment['comment_date'];
					$newcomments[$comment_id]['comment_date_gmt']     = $comment['comment_date_gmt'];
					$newcomments[$comment_id]['comment_content']      = $comment['comment_content'];
					$newcomments[$comment_id]['comment_approved']     = $comment['comment_approved'];
					$newcomments[$comment_id]['comment_type']         = $comment['comment_type'];
					$newcomments[$comment_id]['comment_parent'] 	  = $comment['comment_parent'];
					$newcomments[$comment_id]['commentmeta']          = isset( $comment['commentmeta'] ) ? $comment['commentmeta'] : array();
					if ( isset( $this->processed_authors[$comment['comment_user_id']] ) )
						$newcomments[$comment_id]['user_id'] = $this->processed_authors[$comment['comment_user_id']];
				}
				ksort( $newcomments );

				foreach ( $newcomments as $key => $comment ) {
					// if this is a new post we can skip the comment_exists() check
					if ( ! $post_exists || ! comment_exists( $comment['comment_author'], $comment['comment_date'] ) ) {
						if ( isset( $inserted_comments[$comment['comment_parent']] ) )
							$comment['comment_parent'] = $inserted_comments[$comment['comment_parent']];
						$comment = wp_slash( $comment );
						$comment = wp_filter_comment( $comment );
						$inserted_comments[$key] = wp_insert_comment( $comment );
						do_action( 'wp_import_insert_comment', $inserted_comments[$key], $comment, $comment_post_ID, $post );
						foreach( $comment['commentmeta'] as $meta ) {
							$value = maybe_unserialize( $meta['value'] );
							add_comment_meta( $inserted_comments[$key], $meta['key'], $value );
						}
						$num_comments++;
					}
				}
				unset( $newcomments, $inserted_comments, $post['comments'] );
			}

			if ( ! isset( $post['postmeta'] ) )
				$post['postmeta'] = array();

			$post['postmeta'] = apply_filters( 'wp_import_post_meta', $post['postmeta'], $post_id, $post );
			// add/update post meta
			if ( ! empty( $post['postmeta'] ) ) {
				foreach ( $post['postmeta'] as $meta ) {
					$key = apply_filters( 'import_post_meta_key', $meta['key'], $post_id, $post );
					$value = false;

					if ( '_edit_last' == $key ) {
						if ( isset( $this->processed_authors[intval($meta['value'])] ) )
							$value = $this->processed_authors[intval($meta['value'])];
						else
							$key = false;
					}
					if ( $key ) {
						// export gets meta straight from the DB so could have a serialized string
						if ( ! $value )
							$value = maybe_unserialize( $meta['value'] );

						add_post_meta( $post_id, $key, $value );
						do_action( 'import_post_meta', $post_id, $key, $value );

						// if the post has a featured image, take note of this in case of remap
						if ( '_thumbnail_id' == $key )
							$this->featured_images[$post_id] = (int) $value;
					}
				}
			}
		}
		unset( $this->posts );
	}

	protected function delete_post($post_type = 'listings')
	{
        // Delete parent posts
        $result = $this->wpdb->query(
            $this->wpdb->prepare( "
            DELETE posts, pt, pm
            FROM {$this->wpdb->posts} posts
            LEFT JOIN {$this->wpdb->term_relationships} pt ON pt.object_id = posts.ID
            LEFT JOIN {$this->wpdb->postmeta} pm ON pm.post_id = posts.ID
            WHERE posts.post_type = %s
            ",
                $post_type
            )
        );

//        // Delete orphaned post
//        $r = $this->wpdb->query("
//DELETE p, pp
//FROM {$this->wpdb->posts} p
//LEFT JOIN {$this->wpdb->posts} pp ON (pp.post_parent > 0 AND p.ID = pp.post_parent)
//WHERE pp.ID IS NULL
//");
//        // Delete orphaned metadata
//        $r = $this->wpdb->query("
//DELETE pm
//FROM {$this->wpdb->postmeta} pm
//LEFT JOIN {$this->wpdb->posts} wp ON wp.ID = pm.post_id
//WHERE wp.ID IS NULL
//");

		return $result!==false;

	}

	/**
	 * Create new terms based on import information
	 *
	 * Doesn't create a term its slug already exists
	 */
	function process_terms() {
		$this->terms = apply_filters( 'wp_import_terms', $this->terms );

		if ( empty( $this->terms ) )
			return;

		foreach ( $this->terms as $term ) {
			// if the term already exists in the correct taxonomy leave it alone
			$term_id = term_exists( $term['slug'], $term['term_taxonomy'] );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($term['term_id']) )
					$this->processed_terms[intval($term['term_id'])] = (int) $term_id;
				continue;
			}

			if ( empty( $term['term_parent'] ) ) {
				$parent = 0;
			} else {
				$parent = term_exists( $term['term_parent'], $term['term_taxonomy'] );
				if ( is_array( $parent ) ) $parent = $parent['term_id'];
			}
			$term = wp_slash( $term );
			$description = isset( $term['term_description'] ) ? $term['term_description'] : '';
			$termarr = array( 'slug' => $term['slug'], 'description' => $description, 'parent' => intval($parent) );

			$id = wp_insert_term( $term['term_name'], $term['term_taxonomy'], $termarr );
			if ( ! is_wp_error( $id ) ) {
				if ( isset($term['term_id']) )
					$this->processed_terms[intval($term['term_id'])] = $id['term_id'];
			} else {
				$this->log(
					sprintf( __( 'Failed to import %s %s', $this->plugin_name ), esc_html($term['term_taxonomy']), esc_html($term['term_name']) )
				);
				if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
					$this->log('    : ' . $id->get_error_message());
				continue;
			}
			$this->process_termmeta( $term, $id['term_id'] );
		}

		unset( $this->terms );
	}

	/**
	 * Create new post tags based on import information
	 *
	 * Doesn't create a tag if its slug already exists
	 */
	function process_tags() {
		$this->tags = apply_filters( 'wp_import_tags', $this->tags );

		if ( empty( $this->tags ) )
			return;

		foreach ( $this->tags as $tag ) {
			// if the tag already exists leave it alone
			$term_id = term_exists( $tag['tag_slug'], 'post_tag' );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($tag['term_id']) )
					$this->processed_terms[intval($tag['term_id'])] = (int) $term_id;
				continue;
			}

			$tag = wp_slash( $tag );
			$tag_desc = isset( $tag['tag_description'] ) ? $tag['tag_description'] : '';
			$tagarr = array( 'slug' => $tag['tag_slug'], 'description' => $tag_desc );

			$id = wp_insert_term( $tag['tag_name'], 'post_tag', $tagarr );
			if ( ! is_wp_error( $id ) ) {
				if ( isset($tag['term_id']) )
					$this->processed_terms[intval($tag['term_id'])] = $id['term_id'];
			} else {
				$this->log(
					sprintf( __( 'Failed to import post tag %s', $this->plugin_name ), esc_html($tag['tag_name']) )
				);
				if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
					$this->log('    : ' . $id->get_error_message());
				continue;
			}
			$this->process_termmeta( $tag, $id['term_id'] );
		}
		unset( $this->tags );
	}

	/**
	 * Create new categories based on import information
	 *
	 * Doesn't create a new category if its slug already exists
	 */
	function process_categories() {
		$this->categories = apply_filters( 'wp_import_categories', $this->categories );

		if ( empty( $this->categories ) )
			return;

		foreach ( $this->categories as $cat ) {
			// if the category already exists leave it alone
			$term_id = term_exists( $cat['category_nicename'], 'category' );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($cat['term_id']) )
					$this->processed_terms[intval($cat['term_id'])] = (int) $term_id;
				continue;
			}

			$category_parent = empty( $cat['category_parent'] ) ? 0 : category_exists( $cat['category_parent'] );
			$category_description = isset( $cat['category_description'] ) ? $cat['category_description'] : '';
			$catarr = array(
				'category_nicename' => $cat['category_nicename'],
				'category_parent' => $category_parent,
				'cat_name' => $cat['cat_name'],
				'category_description' => $category_description
			);
			$catarr = wp_slash( $catarr );

			$id = wp_insert_category( $catarr );
			if ( ! is_wp_error( $id ) ) {
				if ( isset($cat['term_id']) )
					$this->processed_terms[intval($cat['term_id'])] = $id;
			} else {
				$this->log(
					sprintf( __( 'Failed to import category %s', $this->plugin_name ), esc_html($cat['category_nicename']) )
				);
				if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
					$this->log('    : ' . $id->get_error_message());
				continue;
			}

			$this->process_termmeta( $cat, $id['term_id'] );
		}

		unset( $this->categories );
	}
	/**
	 * Add metadata to imported term.
	 *
	 * @since 0.6.2
	 *
	 * @param array $term    Term data from WXR import.
	 * @param int   $term_id ID of the newly created term.
	 */
	protected function process_termmeta( $term, $term_id ) {
		if ( ! isset( $term['termmeta'] ) ) {
			$term['termmeta'] = array();
		}

		/**
		 * Filters the metadata attached to an imported term.
		 *
		 * @since 0.6.2
		 *
		 * @param array $termmeta Array of term meta.
		 * @param int   $term_id  ID of the newly created term.
		 * @param array $term     Term data from the WXR import.
		 */
		$term['termmeta'] = apply_filters( 'wp_import_term_meta', $term['termmeta'], $term_id, $term );

		if ( empty( $term['termmeta'] ) ) {
			return;
		}

		foreach ( $term['termmeta'] as $meta ) {
			/**
			 * Filters the meta key for an imported piece of term meta.
			 *
			 * @since 0.6.2
			 *
			 * @param string $meta_key Meta key.
			 * @param int    $term_id  ID of the newly created term.
			 * @param array  $term     Term data from the WXR import.
			 */
			$key = apply_filters( 'import_term_meta_key', $meta['key'], $term_id, $term );
			if ( ! $key ) {
				continue;
			}

			// Export gets meta straight from the DB so could have a serialized string
			$value = maybe_unserialize( $meta['value'] );

			add_term_meta( $term_id, $key, $value );

			/**
			 * Fires after term meta is imported.
			 *
			 * @since 0.6.2
			 *
			 * @param int    $term_id ID of the newly created term.
			 * @param string $key     Meta key.
			 * @param mixed  $value   Meta value.
			 */
			do_action( 'import_term_meta', $term_id, $key, $value );
		}
	}

	protected function getImportUserId()
    {
        $options = get_option($this->plugin_name);
        $importUserId = (int)get_current_user_id();
        if (!empty($options['userId'])) {
            $importUserId = (int)$options['userId'];
        }
        return $importUserId;
    }

	/**
	 * Retrieve authors from parsed WXR data
	 *
	 * Uses the provided author information from WXR 1.1 files
	 * or extracts info from each post for WXR 1.0 files
	 *
	 * @param array $import_data Data returned by a WXR parser
	 */
	function get_authors_from_import( $import_data ) {
		if ( ! empty( $import_data['authors'] ) ) {
			$this->authors = $import_data['authors'];
			// no author information, grab it from the posts
		} else {
			foreach ( $import_data['posts'] as $post ) {
				$login = sanitize_user( $post['post_author'], true );
				if ( empty( $login ) ) {
					$this->log(
						sprintf( __( 'Failed to import author %s. Their posts will be attributed to the current user.', $this->plugin_name ), esc_html( $post['post_author'] ) )
					);
					continue;
				}

				if ( ! isset($this->authors[$login]) )
					$this->authors[$login] = array(
						'author_login' => $login,
						'author_display_name' => $post['post_author']
					);
			}
		}
	}

	/**
	 * Attempt to create a new menu item from import data
	 *
	 * Fails for draft, orphaned menu items and those without an associated nav_menu
	 * or an invalid nav_menu term. If the post type or term object which the menu item
	 * represents doesn't exist then the menu item will not be imported (waits until the
	 * end of the import to retry again before discarding).
	 *
	 * @param array $item Menu item details from WXR file
	 */
	function process_menu_item( $item ) {
		// skip draft, orphaned menu items
		if ( 'draft' == $item['status'] )
			return;

		$menu_slug = false;
		if ( isset($item['terms']) ) {
			// loop through terms, assume first nav_menu term is correct menu
			foreach ( $item['terms'] as $term ) {
				if ( 'nav_menu' == $term['domain'] ) {
					$menu_slug = $term['slug'];
					break;
				}
			}
		}

		// no nav_menu term associated with this menu item
		if ( ! $menu_slug ) {
			$this->log(
				__( 'Menu item skipped due to missing menu slug', $this->plugin_name )
			);
			return;
		}

		$menu_id = term_exists( $menu_slug, 'nav_menu' );
		if ( ! $menu_id ) {
			$this->log(
				sprintf( __( 'Menu item skipped due to invalid menu slug: %s', $this->plugin_name ), esc_html( $menu_slug ) )
			);
			return;
		} else {
			$menu_id = is_array( $menu_id ) ? $menu_id['term_id'] : $menu_id;
		}

		foreach ( $item['postmeta'] as $meta )
			${$meta['key']} = $meta['value'];

		if ( 'taxonomy' == $_menu_item_type && isset( $this->processed_terms[intval($_menu_item_object_id)] ) ) {
			$_menu_item_object_id = $this->processed_terms[intval($_menu_item_object_id)];
		} else if ( 'post_type' == $_menu_item_type && isset( $this->processed_posts[intval($_menu_item_object_id)] ) ) {
			$_menu_item_object_id = $this->processed_posts[intval($_menu_item_object_id)];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			$this->missing_menu_items[] = $item;
			return;
		}

		if ( isset( $this->processed_menu_items[intval($_menu_item_menu_item_parent)] ) ) {
			$_menu_item_menu_item_parent = $this->processed_menu_items[intval($_menu_item_menu_item_parent)];
		} else if ( $_menu_item_menu_item_parent ) {
			$this->menu_item_orphans[intval($item['post_id'])] = (int) $_menu_item_menu_item_parent;
			$_menu_item_menu_item_parent = 0;
		}

		// wp_update_nav_menu_item expects CSS classes as a space separated string
		$_menu_item_classes = maybe_unserialize( $_menu_item_classes );
		if ( is_array( $_menu_item_classes ) )
			$_menu_item_classes = implode( ' ', $_menu_item_classes );

		$args = array(
			'menu-item-object-id' => $_menu_item_object_id,
			'menu-item-object' => $_menu_item_object,
			'menu-item-parent-id' => $_menu_item_menu_item_parent,
			'menu-item-position' => intval( $item['menu_order'] ),
			'menu-item-type' => $_menu_item_type,
			'menu-item-title' => $item['post_title'],
			'menu-item-url' => $_menu_item_url,
			'menu-item-description' => $item['post_content'],
			'menu-item-attr-title' => $item['post_excerpt'],
			'menu-item-target' => $_menu_item_target,
			'menu-item-classes' => $_menu_item_classes,
			'menu-item-xfn' => $_menu_item_xfn,
			'menu-item-status' => $item['status']
		);

		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $id && ! is_wp_error( $id ) )
			$this->processed_menu_items[intval($item['post_id'])] = (int) $id;
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	public function process_attachment( $post, $url ) {
		if ( ! $this->fetch_attachments )
			return new WP_Error( 'attachment_processing_error',
				__( 'Fetching attachments is not enabled', $this->plugin_name ) );

		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) )
			$url = rtrim( $this->base_url, '/' ) . $url;

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) )
			return $upload;

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __('Invalid file type', $this->plugin_name) );

		$post['guid'] = $upload['url'];

		// as per wp-admin/includes/upload.php
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		// remap resized image URLs, works by stripping the extension and remapping the URL stub.
		if ( preg_match( '!^image/!', $info['type'] ) ) {
			$parts = pathinfo( $url );
			$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

			$parts_new = pathinfo( $upload['url'] );
			$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

			$this->url_remap[$parts['dirname'] . '/' . $name] = $parts_new['dirname'] . '/' . $name_new;
		}

		return $post_id;
	}



    /**
     * Create a file in the upload folder with given content.
     *
     * If there is an error, then the key 'error' will exist with the error message.
     * If success, then the key 'file' will have the unique file path, the 'url' key
     * will have the link to the new file. and the 'error' key will be set to false.
     *
     * This function will not move an uploaded file to the upload folder. It will
     * create a new file with the content in $bits parameter. If you move the upload
     * file, read the content of the uploaded file, and then you can give the
     * filename and content to this function, which will add it to the upload
     * folder.
     *
     * The permissions will be set on the new file automatically by this function.
     *
     * @since 2.0.0
     *
     * @param string       $name       Filename.
     * @param null|string  $deprecated Never used. Set to null.
     * @param mixed        $bits       File content
     * @param string       $time       Optional. Time formatted in 'yyyy/mm'. Default null.
     * @return array
     */
    protected function wp_upload_bits( $name, $deprecated, $bits, $time = null ) {
        if ( ! empty( $deprecated ) ) {
            _deprecated_argument( __FUNCTION__, '2.0.0' );
        }

        if ( empty( $name ) ) {
            return array( 'error' => __( 'Empty filename' ) );
        }

        $wp_filetype = wp_check_filetype( $name );
        if ( ! $wp_filetype['ext'] && ! current_user_can( 'unfiltered_upload' ) ) {
            return array( 'error' => __( 'Sorry, this file type is not permitted for security reasons.' ) );
        }

        $upload = wp_upload_dir( $time );

        if ( $upload['error'] !== false ) {
            return $upload;
        }

        /**
         * Filters whether to treat the upload bits as an error.
         *
         * Passing a non-array to the filter will effectively short-circuit preparing
         * the upload bits, returning that value instead.
         *
         * @since 3.0.0
         *
         * @param mixed $upload_bits_error An array of upload bits data, or a non-array error to return.
         */
        $upload_bits_error = apply_filters(
            'wp_upload_bits',
            array(
                'name' => $name,
                'bits' => $bits,
                'time' => $time,
            )
        );
        if ( ! is_array( $upload_bits_error ) ) {
            $upload['error'] = $upload_bits_error;
            return $upload;
        }

        $filename = $name;
        //$filename = wp_unique_filename( $upload['path'], $name );

        $new_file = $upload['path'] . "/$filename";
        if ( ! wp_mkdir_p( dirname( $new_file ) ) ) {
            if ( 0 === strpos( $upload['basedir'], ABSPATH ) ) {
                $error_path = str_replace( ABSPATH, '', $upload['basedir'] ) . $upload['subdir'];
            } else {
                $error_path = wp_basename( $upload['basedir'] ) . $upload['subdir'];
            }

            $message = sprintf(
                /* translators: %s: directory path */
                __( 'Unable to create directory %s. Is its parent directory writable by the server?' ),
                $error_path
            );
            return array( 'error' => $message );
        }

        $ifp = @ fopen( $new_file, 'wb' );
        if ( ! $ifp ) {
            return array( 'error' => sprintf( __( 'Could not write file %s' ), $new_file ) );
        }
        @fwrite( $ifp, $bits );
        fclose( $ifp );
        clearstatcache();

        // Set correct file permissions
        $stat  = @ stat( dirname( $new_file ) );
        $perms = $stat['mode'] & 0007777;
        $perms = $perms & 0000666;
        @ chmod( $new_file, $perms );
        clearstatcache();

        // Compute the URL
        $url = $upload['url'] . "/$filename";

        /** This filter is documented in wp-admin/includes/file.php */
        return apply_filters(
            'wp_handle_upload',
            array(
                'file'  => $new_file,
                'url'   => $url,
                'type'  => $wp_filetype['type'],
                'error' => false,
            ),
            'sideload'
        );
    }

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file($url, $post)
    {
        // extract the file name and extension from the url
        $file_name = basename( $url );

        $newPath = ABSPATH . '/wp-content/uploads/' . $post['upload_date'] . '/' . $file_name;
        $newUrl  = WP_CONTENT_URL . '/uploads/' . $post['upload_date'] . '/' . $file_name;
        $canCopy = $this->canCopyFile($url, $newPath);

        $headers = array();
        $upload = array(
            'url' => $newUrl,
            'file' => $newPath
        );
        if ($canCopy) {

            $this->log('Copying File: ' . $url);

            // get placeholder file in the upload dir with a unique, sanitized filename
            $upload = $this->wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
            if ( $upload['error'] )
                return new WP_Error( 'upload_dir_error', $upload['error'] );

            // fetch the remote url and write it to the placeholder file
            $remote_response = wp_safe_remote_get($url, array(
                'timeout' => 300,
                'stream' => true,
                'filename' => $upload['file'],
            ));

            $headers = wp_remote_retrieve_headers($remote_response);
            // request failed
            if (!$headers) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', __('Remote server did not respond', 'wordpress-importer'));
            }

            $remote_response_code = wp_remote_retrieve_response_code($remote_response);
            // make sure the fetch was successful
            if ($remote_response_code != '200') {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', sprintf(__('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($remote_response_code), get_status_header_desc($remote_response_code)));
            }

            $filesize = filesize($upload['file']);
            if (isset($headers['content-length']) && $filesize != $headers['content-length']) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', __('Remote file is incorrect size', 'wordpress-importer'));
            }

            if (0 == $filesize) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', __('Zero size file downloaded', 'wordpress-importer'));
            }

            $max_size = (int)$this->max_attachment_size();
            if (!empty($max_size) && $filesize > $max_size) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', sprintf(__('Remote file is too large, limit is %s', 'wordpress-importer'), size_format($max_size)));
            }
        }

        // keep track of the old and new urls so we can substitute them later
        $this->url_remap[$url] = $upload['url'];
        $this->url_remap[$post['guid']] = $upload['url']; // r13735, really needed?
        // keep track of the destination if the remote url is redirected somewhere else
        if ( isset($headers['x-final-location']) && $headers['x-final-location'] != $url )
            $this->url_remap[$headers['x-final-location']] = $upload['url'];

        return $upload;
	}


    protected function canCopyFile($src, $dst)
    {
        if (!is_file($dst)) {
            clearstatcache();
            return true;
        } else {
            $srcSize = $this->getRemoteFilesize($src);
            clearstatcache();
            $dstSize = filesize($dst);
            clearstatcache();
            if ($dstSize != $srcSize) return true;
        }
        clearstatcache();
        return false;
    }

    /**
     *  Get the file size of any remote resource (using get_headers()),
     *  either in bytes or - default - as human-readable formatted string.
     *
     *  @author  Stephan Schmitz <eyecatchup@gmail.com>
     *  @license MIT <http://eyecatchup.mit-license.org/>
     *  @url     <https://gist.github.com/eyecatchup/f26300ffd7e50a92bc4d>
     *
     *  @param   string   $url          Takes the remote object's URL.
     *  @param   boolean  $formatSize   Whether to return size in bytes or formatted.
     *  @param   boolean  $useHead      Whether to use HEAD requests. If false, uses GET.
     *  @return  string                 Returns human-readable formatted size
     *                                  or size in bytes (default: formatted).
     */
    protected function getRemoteFilesize($url, $formatSize = true, $useHead = true)
    {
        static $regex = '/^Content-Length: *+\K\d++$/im';
        if (!$fp = @fopen($url, 'rb')) {
            return false;
        }
        if (
            isset($http_response_header) &&
            preg_match($regex, implode("\n", $http_response_header), $matches)
        ) {
            return (int)$matches[0];
        }
        $size = strlen(stream_get_contents($fp));
        fclose($fp);
        return $size;
    }

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}


	/**
	 * Attempt to associate posts and menu items with previously missing parents
	 *
	 * An imported post's parent may not have been imported when it was first created
	 * so try again. Similarly for child menu items and menu items which were missing
	 * the object (e.g. post) they represent in the menu
	 */
	function backfill_parents() {
		// find parents for post orphans
		foreach ( $this->post_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = false;
			if ( isset( $this->processed_posts[$child_id] ) )
				$local_child_id = $this->processed_posts[$child_id];
			if ( isset( $this->processed_posts[$parent_id] ) )
				$local_parent_id = $this->processed_posts[$parent_id];

			if ( $local_child_id && $local_parent_id ) {
				$this->wpdb->update( $this->wpdb->posts, array( 'post_parent' => $local_parent_id ), array( 'ID' => $local_child_id ), '%d', '%d' );
				clean_post_cache( $local_child_id );
			}
		}

		// all other posts/terms are imported, retry menu items with missing associated object
		$missing_menu_items = $this->missing_menu_items;
		foreach ( $missing_menu_items as $item )
			$this->process_menu_item( $item );

		// find parents for menu item orphans
		foreach ( $this->menu_item_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = 0;
			if ( isset( $this->processed_menu_items[$child_id] ) )
				$local_child_id = $this->processed_menu_items[$child_id];
			if ( isset( $this->processed_menu_items[$parent_id] ) )
				$local_parent_id = $this->processed_menu_items[$parent_id];

			if ( $local_child_id && $local_parent_id )
				update_post_meta( $local_child_id, '_menu_item_menu_item_parent', (int) $local_parent_id );
		}
	}

	/**
	 * Use stored mapping information to update old attachment URLs
	 */
	function backfill_attachment_urls() {
		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array(&$this, 'cmpr_strlen') );

		foreach ( $this->url_remap as $from_url => $to_url ) {
			// remap urls in post_content
			$this->wpdb->query( $this->wpdb->prepare("UPDATE {$this->wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)", $from_url, $to_url) );
			// remap enclosure urls
			$result = $this->wpdb->query( $this->wpdb->prepare("UPDATE {$this->wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key='enclosure'", $from_url, $to_url) );
		}
	}

	/**
	 * Update _thumbnail_id meta to new, imported attachment IDs
	 */
	function remap_featured_images() {
		// cycle through posts that have a featured image
		foreach ( $this->featured_images as $post_id => $value ) {
			if ( isset( $this->processed_posts[$value] ) ) {
				$new_id = $this->processed_posts[$value];
				// only update if there's a difference
				if ( $new_id != $value )
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
			}
		}
	}

	/**
	 * Parse a WXR file
	 *
	 * @param string $file Path to WXR file for parsing
	 * @return array Information gathered from the WXR file
	 */
	function parse( $file ) {
		$parser = new Tk_Parser();
		return $parser->parse( $file );
	}








	public function getMessages()
	{
		return $this->messages;
	}

	/**
	 * @param string|mixed $msg
	 */
	protected function log($msg)
	{
		$this->messages[] = $msg;
		if ($this->log) {
			if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
			if (WP_DEBUG)
			    error_log($msg);
		}
	}


	protected function file_get_contents_curl($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, 0);
		//curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.1) Gecko/20090615 Firefox/3.5');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
		curl_setopt($ch, CURLOPT_URL, $url);
		$data = curl_exec($ch);
		//error_log(curl_error($ch));
		curl_close($ch);
		return $data;
	}
}