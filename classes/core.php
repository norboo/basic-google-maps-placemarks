<?php

if ( ! class_exists( 'Basic_Google_Maps_Placemarks' ) ) {
	class Basic_Google_Maps_Placemarks extends BGMP_Module {
		protected $settings, $options, $updated_options, $map_shortcode_called, $map_shortcode_categories;
		const VERSION    = '2.0.1-alpha';
		const POST_TYPE  = 'bgmp';
		const TAXONOMY   = 'bgmp-category';
		const ZOOM_MIN   = 0;
		const ZOOM_MAX   = 21;
		const DEBUG_MODE = false;

		/**
		 * Constructor
		 */
		public function __construct() {
			add_action( 'init',                     array( $this, 'init' ), 8 );                          // lower priority so that variables defined here will be available to BGMPSettings class and other init callbacks
			add_action( 'init',                     array( $this, 'upgrade' ) );
			add_action( 'init',                     array( $this, 'create_post_type' ) );
			add_action( 'init',                     array( $this, 'create_category_taxonomy' ) );
			add_action( 'after_setup_theme',        array( $this, 'add_featured_image_support' ), 11 );   // @todo add note explaining why higher priority
			add_action( 'admin_init',               array( $this, 'add_meta_boxes' ) );
			add_action( 'wp',                       array( $this, 'load_resources' ), 11 );               // @todo - should be wp_enqueue_scripts instead?	// @todo add note explaining why higher priority
			add_action( 'admin_enqueue_scripts',    array( $this, 'load_resources' ), 11 );
			add_action( 'wp_head',                  array( $this, 'output_head' ) );
			add_action( 'save_post',                array( $this, 'save_custom_fields' ) );
			add_action( 'wpmu_new_blog',            array( $this, 'activate_new_site' ) );
			add_action( 'shutdown',                 array( $this, 'shutdown' ) );

			add_filter( 'parse_query',              array( $this, 'sort_admin_view' ) );

			add_shortcode( 'bgmp-map',              array( $this, 'map_shortcode' ) );
			add_shortcode( 'bgmp-list',             array( $this, 'list_shortcode' ) );

			register_activation_hook( dirname( dirname( __FILE__ ) ) . '/basic-google-maps-placemarks.php', array( $this, 'network_activate' ) );

			$this->settings = new BGMP_Settings();
		}

		/**
		 * Performs various initialization functions
		 */
		public function init() {
			$default_options = array( 'dbVersion' => '0' );   // can't rename to conform to standard b/c of backcompat
			$this->options   = array_merge( $default_options, get_option( 'bgmp_options', array() ) );

			if ( ! is_array( $this->options ) ) {
				$this->options = $default_options;
			}

			$this->updated_options          = false;
			$this->map_shortcode_called     = false;
			$this->map_shortcode_categories = null;
		}

		/**
		 * Getter method for instance of the BGMPSettings class, used for unit testing
		 */
		public function &get_settings() {
			return $this->settings;
		}

		/**
		 * Handles extra activation tasks for MultiSite installations
		 *
		 * @param bool $network_wide True if the activation was network-wide
		 */
		public function network_activate( $network_wide ) {
			global $wpdb, $wp_version;

			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				// Enable image uploads so the 'Set Featured Image' meta box will be available
				$media_buttons = get_site_option( 'mu_media_buttons' );

				if ( version_compare( $wp_version, '3.3', "<=" ) && ( ! array_key_exists( 'image', $media_buttons ) || ! $media_buttons['image'] ) ) {
					$media_buttons['image'] = 1;
					update_site_option( 'mu_media_buttons', $media_buttons );

					/*
					@todo enqueueMessage() needs $this->options to be set, but as of v1.8 that doesn't happen until the init hook, which is after activation. It doesn't really matter anymore, though, because mu_media_buttons was removed in 3.3. http://core.trac.wordpress.org/ticket/17578 
					add_notice( sprintf(
						__( '%s has enabled uploading images network-wide so that placemark icons can be set.', 'bgmp' ),		// @todo - give more specific message, test. enqueue for network admin but not regular admins
						BGMP_NAME
					) );
					*/
				}
			}
		}

		/**
		 * Checks if the plugin was recently updated and upgrades if necessary
		 */
		public function upgrade() {
			if ( version_compare( $this->options['dbVersion'], self::VERSION, '==' ) ) {
				return;
			}

			if ( version_compare( $this->options['dbVersion'], '1.1', '<' ) ) {
				// Populate new Address field from existing coordinate fields
				$posts = get_posts( array( 'numberposts' => - 1, 'post_type' => self::POST_TYPE ) );
				if ( $posts ) {
					foreach ( $posts as $p ) {
						$address   = get_post_meta( $p->ID, 'bgmp_address', true );
						$latitude  = get_post_meta( $p->ID, 'bgmp_latitude', true );
						$longitude = get_post_meta( $p->ID, 'bgmp_longitude', true );

						if ( empty( $address ) && ! empty( $latitude ) && ! empty( $longitude ) ) {
							$address = $this->reverse_geocode( $latitude, $longitude );
							if ( $address ) {
								update_post_meta( $p->ID, 'bgmp_address', $address );
							}
						}
					}
				}
			}

			$this->options['dbVersion'] = self::VERSION;
			$this->updated_options      = true;

			// Clear WP Super Cache and W3 Total Cache
			if ( function_exists( 'wp_cache_clear_cache' ) )
				wp_cache_clear_cache();

			if ( class_exists( 'W3_Plugin_TotalCacheAdmin' ) ) {
				$w3TotalCache =& w3_instance( 'W3_Plugin_TotalCacheAdmin' );

				if ( method_exists( $w3TotalCache, 'flush_all' ) )
					$w3TotalCache->flush_all();
			}
		}

		/**
		 * Adds featured image support
		 */
		public function add_featured_image_support() {
			global $wp_version;

			// We enabled image media buttons for MultiSite on activation, but the admin may have turned it back off
			if ( version_compare( $wp_version, '3.3', "<=" ) && is_admin() && function_exists( 'is_multisite' ) && is_multisite() ) {
				// @todo this isn't DRY, similar code in network_activate()

				$media_buttons = get_site_option( 'mu_media_buttons' );

				if ( ! array_key_exists( 'image', $media_buttons ) || ! $media_buttons['image'] ) {
					add_notice( sprintf(
						__( "%s requires the Images media button setting to be enabled in order to use custom icons on markers, but it's currently turned off. If you'd like to use custom icons you can enable it on the <a href=\"%s\">Network Settings</a> page, in the Upload Settings section.", 'bgmp' ),
						BGMP_NAME,
						network_admin_url() . 'settings.php'
					), 'error' );
				}
			}

			$supported_types = get_theme_support( 'post-thumbnails' );

			if ( false === $supported_types )
				add_theme_support( 'post-thumbnails', array( self::POST_TYPE ) );
			elseif ( is_array( $supported_types ) ) {
				$supported_types[0][] = self::POST_TYPE;
				add_theme_support( 'post-thumbnails', $supported_types[0] );
			}
		}

		/**
		 * Gets all of the shortcodes in the current post
		 *
		 * @param string $content
		 * @return mixed false | array
		 */
		protected function get_shortcodes( $content ) {
			$matches = array();

			preg_match_all( '/' . get_shortcode_regex() . '/s', $content, $matches );
			if ( ! is_array( $matches ) || ! array_key_exists( 2, $matches ) )
				return false;

			return $matches;
		}

		/**
		 * Validates and cleans the map shortcode arguments
		 *
		 * @param array
		 * @return array
		 */
		protected function clean_map_shortcode_arguments( $arguments ) {
			// @todo - not doing this in settings yet, but should. want to make sure it's DRY when you do. 
			// @todo - Any errors generated in there would stack up until admin loads page, then they'll all be displayed, include  ones from geocode() etc. that's not great solution, but is there better way?
			// maybe call getmap_shortcodeArguments() when saving post so they get immediate feedback about any errors in shortcode
			// do something similar for list shortcode arguments?

			global $post;
			$error = '';

			if ( ! is_array( $arguments ) )
				return array();


			// Placemark
			if ( isset( $arguments['placemark'] ) ) {
				$pass       = true;
				$original_id = $arguments['placemark'];

				// Check for valid placemark ID
				if ( ! is_numeric( $arguments['placemark'] ) )
					$pass = false;

				$arguments['placemark'] = (int) $arguments['placemark'];

				if ( $arguments['placemark'] <= 0 )
					$pass = false;

				$placemark = get_post( $arguments['placemark'] );
				if ( ! $placemark )
					$pass = false;

				if ( ! $pass ) {
					$error = sprintf(
						__( '%s shortcode error: %s is not a valid placemark ID.', 'bgmp' ),
						BGMP_NAME,
						is_scalar( $original_id ) ? (string) $original_id : gettype( $original_id )
					);
				}

				// Check for valid coordinates
				if ( $pass ) {
					$latitude    = get_post_meta( $arguments['placemark'], 'bgmp_latitude', true );
					$longitude   = get_post_meta( $arguments['placemark'], 'bgmp_longitude', true );
					$coordinates = $this->validate_coordinates( $latitude . ',' . $longitude );

					if ( false === $coordinates ) {
						$pass  = false;
						$error = sprintf(
							__( '%s shortcode error: %s does not have a valid address.', 'bgmp' ),
							BGMP_NAME,
							(string) $original_id
						);
					}
				}


				// Remove the option if it isn't a valid placemark
				if ( ! $pass ) {
					add_notice( $error, 'error' );
					unset( $arguments['placemark'] );
				}
			}


			// Categories
			if ( isset( $arguments['categories'] ) ) {
				if ( is_string( $arguments['categories'] ) ) {
					$arguments['categories'] = explode( ',', $arguments['categories'] );
				} elseif ( ! is_array( $arguments['categories'] ) || empty( $arguments['categories'] ) ) {
					unset( $arguments['categories'] );
				}

				if ( isset( $arguments['categories'] ) && ! empty( $arguments['categories'] ) ) {
					foreach ( $arguments['categories'] as $index => $term ) {
						if ( ! term_exists( $term, self::TAXONOMY ) ) {
							unset( $arguments['categories'][$index] ); // Note - This will leave holes in the key sequence, but it doesn't look like that's a problem with the way we're using it.
							add_notice( sprintf(
								__( '%s shortcode error: %s is not a valid category.', 'bgmp' ),
								BGMP_NAME,
								$term
							), 'error' );
						}
					}
				}
			}

			// Rename width and height keys to match internal ones. Using different ones in shortcode to make it easier for user.
			if ( isset( $arguments['width'] ) ) {
				if ( is_numeric( $arguments['width'] ) && $arguments['width'] > 0 ) {
					$arguments['map_width'] = $arguments['width'];
				} else {
					add_notice( sprintf(
						__( '%s shortcode error: %s is not a valid width.', 'bgmp' ),
						BGMP_NAME,
						$arguments['width']
					), 'error' );
				}

				unset( $arguments['width'] );
			}

			if ( isset( $arguments['height'] ) && $arguments['height'] > 0 ) {
				if ( is_numeric( $arguments['height'] ) ) {
					$arguments['map_height'] = $arguments['height'];
				} else {
					add_notice( sprintf(
						__( '%s shortcode error: %s is not a valid height.', 'bgmp' ),
						BGMP_NAME,
						$arguments['height']
					), 'error' );
				}

				unset( $arguments['height'] );
			}


			// Center
			if ( isset( $arguments['center'] ) ) {
				// Note: Google's API has a daily request limit, which could be a problem when geocoding map shortcode center address each time page loads. Users could get around that by using a caching plugin, though.

				$coordinates = $this->geocode( $arguments['center'] );
				if ( $coordinates ) {
					$arguments = array_merge( $arguments, $coordinates );
				}

				unset( $arguments['center'] );
			}


			// Zoom
			if ( isset( $arguments['zoom'] ) ) {
				if ( ! is_numeric( $arguments['zoom'] ) || $arguments['zoom'] < self::ZOOM_MIN || $arguments['zoom'] > self::ZOOM_MAX ) {
					add_notice( sprintf(
						__( '%s shortcode error: %s is not a valid zoom level.', 'bgmp' ),
						BGMP_NAME,
						$arguments['zoom']
					), 'error' );

					unset( $arguments['zoom'] );
				}
			}


			// Type
			if ( isset( $arguments['type'] ) ) {
				$arguments['type'] = strtoupper( $arguments['type'] );

				if ( ! array_key_exists( $arguments['type'], $this->settings->map_types ) ) {
					add_notice( sprintf(
						__( '%s shortcode error: %s is not a valid map type.', 'bgmp' ),
						BGMP_NAME,
						$arguments['type']
					), 'error' );

					unset( $arguments['type'] );
				}
			}


			return apply_filters( 'bgmp_clean-map-shortcode-arguments-return', $arguments );
		}

		/**
		 * Checks the current post to see if they contain the map shortcode
		 *
		 * @link   http://wordpress.org/support/topic/plugin-basic-google-maps-placemarks-can-i-use-the-shortcode-on-any-php-without-assign-it-in-functionphp
		 * @return bool
		 */
		protected function map_shortcode_called() {
			global $post;

			$this->map_shortcode_called = apply_filters( 'bgmp_map_shortcode_called', $this->map_shortcode_called ); // @todo - deprecated b/c not consistent w/ shortcode naming scheme. need a way to notify people
			$this->map_shortcode_called = apply_filters( 'bgmp_map-shortcode-called', $this->map_shortcode_called );

			if ( $this->map_shortcode_called ) {
				return true;
			}

			if ( ! $post ) { // note: this needs to run after the above code, so that templates can call do_shortcode(...) from templates that don't have $post, like 404.php. See link in phpDoc @link for background.
				return false;
			}

			if ( has_shortcode( $post->post_content, 'bgmp-map' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Load CSS and JavaScript files
		 */
		public function load_resources() {
			$google_maps_language = apply_filters( 'bgmp_map-language', '' );
			if ( $google_maps_language ) {
				$google_maps_language = '&language=' . $google_maps_language;
			}

			wp_register_script(
				'google-maps',
				'http' . ( is_ssl() ? 's' : '' ) . '://ditu.google.cn/maps/api/js?sensor=false' . $google_maps_language,
				array(),
				false,
				true
			);

			wp_register_script(
				'marker-clusterer',
				plugins_url( 'includes/marker-clusterer/markerclusterer_packed.js', dirname( __FILE__ ) ),
				array(),
				'1.0',
				true
			);

			wp_register_script(
				'bgmp',
				plugins_url( 'javascript/functions.js', dirname( __FILE__ ) ),
				array( 'google-maps', 'jquery', 'underscore' ),
				self::VERSION,
				true
			);

			wp_register_style(
				'bgmp_style',   // can't rename to conform to standard b/c of backcompat
				plugins_url( 'css/style.css', dirname( __FILE__ ) ),
				false,
				self::VERSION
			);

			$this->map_shortcode_called = $this->map_shortcode_called();

			// Load front-end resources
			if ( ! is_admin() && $this->map_shortcode_called ) {
				wp_enqueue_script( 'google-maps' );

				if ( $this->settings->marker_clustering ) {
					wp_enqueue_script( 'marker-clusterer' );
				}

				wp_enqueue_script( 'bgmp' );
			}

			if ( $this->map_shortcode_called ) {
				wp_enqueue_style( 'bgmp_style' );
			}

			// Load meta box resources for settings page
			if ( isset( $_GET['page'] ) && 'bgmp_settings' == $_GET['page'] ) { // @todo better way than $_GET ?
				wp_enqueue_style( 'bgmp_style' );
				wp_enqueue_script( 'dashboard' );
			}
		}

		/**
		 * Outputs elements in the <head> section of the front-end
		 */
		public function output_head() {
			if ( $this->map_shortcode_called ) {
				do_action( 'bgmp_head-before' );
				echo $this->render_template( 'core/front-end-head.php' );
				do_action( 'bgmp_head-after' );
			}
		}

		/**
		 * Registers the custom post type
		 */
		public function create_post_type() {
			if ( ! post_type_exists( self::POST_TYPE ) ) {
				$labels = array(
					'name'               => __( 'Placemarks', 'bgmp' ),
					'singular_name'      => __( 'Placemark', 'bgmp' ),
					'add_new'            => __( 'Add New', 'bgmp' ),
					'add_new_item'       => __( 'Add New Placemark', 'bgmp' ),
					'edit'               => __( 'Edit', 'bgmp' ),
					'edit_item'          => __( 'Edit Placemark', 'bgmp' ),
					'new_item'           => __( 'New Placemark', 'bgmp' ),
					'view'               => __( 'View Placemark', 'bgmp' ),
					'view_item'          => __( 'View Placemark', 'bgmp' ),
					'search_items'       => __( 'Search Placemarks', 'bgmp' ),
					'not_found'          => __( 'No Placemarks found', 'bgmp' ),
					'not_found_in_trash' => __( 'No Placemarks found in Trash', 'bgmp' ),
					'parent'             => __( 'Parent Placemark', 'bgmp' )
				);

				$post_type_params = array(
					'labels'          => $labels,
					'singular_label'  => __( 'Placemarks', 'bgmp' ),
					'public'          => true,
					'menu_position'   => 20,
					'hierarchical'    => false,
					'capability_type' => 'post',
					'rewrite'         => array( 'slug' => 'placemarks', 'with_front' => false ),
					'query_var'       => true,
					'supports'        => array( 'title', 'editor', 'author', 'thumbnail', 'comments', 'revisions' ),
				);

				register_post_type(
					self::POST_TYPE,
					apply_filters( 'bgmp_post-type-params', $post_type_params )
				);
			}
		}

		/**
		 * Registers the category taxonomy
		 */
		public function create_category_taxonomy() {
			if ( ! taxonomy_exists( self::TAXONOMY ) ) {
				$taxonomy_params = array(
					'label'                 => __( 'Category', 'bgmp' ),
					'labels'                => array( 'name' => __( 'Categories', 'bgmp' ), 'singular_name' => __( 'Category', 'bgmp' ) ),
					'hierarchical'          => true,
					'rewrite'               => array( 'slug' => self::TAXONOMY ),
					'update_count_callback' => '_update_post_term_count'
				);

				register_taxonomy(
					self::TAXONOMY,
					self::POST_TYPE,
					apply_filters( 'bgmp_category-taxonomy-params', $taxonomy_params )
				);
			}
		}

		/**
		 * Sorts the posts by the title in the admin view posts screen
		 */
		function sort_admin_view( $query ) {
			global $pagenow;

			if ( is_admin() && 'edit.php' == $pagenow && array_key_exists( 'post_type', $_GET ) && self::POST_TYPE == $_GET['post_type'] ) {
				$query->query_vars['order']   = apply_filters( 'bgmp_admin-sort-order', 'ASC' );
				$query->query_vars['orderby'] = apply_filters( 'bgmp_admin-sort-orderby', 'title' );

				// @todo - should just have a filter on $query, or don't even need one at all, since they can filter $query directly?
			}
		}

		/**
		 * Adds meta boxes for the custom post type
		 */
		public function add_meta_boxes() {
			add_meta_box(
				'bgmp_placemark-address',
				__( 'Placemark Address', 'bgmp' ),
				array( $this, 'markup_address_fields' ),
				self::POST_TYPE,
				'normal',
				'high'
			);

			add_meta_box(
				'bgmp_placemark-z_index',
				__( 'Stacking Order', 'bgmp' ),
				array( $this, 'markup_z_index_field' ),
				self::POST_TYPE,
				'side',
				'low'
			);
		}

		/**
		 * Outputs the markup for the address fields
		 */
		public function markup_address_fields() {
			global $post;

			$variables = array(
				'address'            => get_post_meta( $post->ID, 'bgmp_address', true ),
				'latitude'           => get_post_meta( $post->ID, 'bgmp_latitude', true ),
				'longitude'          => get_post_meta( $post->ID, 'bgmp_longitude', true ),
			);

			$variables['show_geocode_results'] = $variables['address'] && ! self::validate_coordinates( $variables['address'] ) && $variables['latitude'] && $variables['longitude'] ? true : false;
			$variables['show_geocode_error']   = $variables['address'] && ( ! $variables['latitude'] || ! $variables['longitude'] ) ? true : false;

			echo $this->render_template( 'core/meta-address.php', $variables );
		}

		/**
		 * Outputs the markup for the stacking order field
		 */
		public function markup_z_index_field() {
			global $post;

			$z_index = get_post_meta( $post->ID, 'bgmp_z_index', true );
			if ( false === filter_var( $z_index, FILTER_VALIDATE_INT )) {
				$z_index = 0;
			}

			echo $this->render_template( 'core/meta-z-index.php', array( 'z_index' => $z_index ) );
		}

		/**
		 * Saves values of the the custom post type's extra fields
		 *
		 * @param int $post_id
		 */
		public function save_custom_fields( $post_id ) {
			global $post;
			$coordinates    = false;
			$ignored_actions = array( 'trash', 'untrash', 'restore' );

			// Check preconditions
			if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $ignored_actions ) ) {
				return;
			}

			if ( ! $post || $post->post_type != self::POST_TYPE || ! current_user_can( 'edit_posts' ) ) {
				return;
			}

			if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' == $post->post_status ) {
				return;
			}


			// Save address
			if ( isset( $_POST['bgmp_address'] ) ) {
				update_post_meta( $post->ID, 'bgmp_address', $_POST['bgmp_address'] );

				if ( $_POST['bgmp_address'] )
					$coordinates = $this->geocode( $_POST['bgmp_address'] );
			}

			if ( $coordinates ) {
				update_post_meta( $post->ID, 'bgmp_latitude', $coordinates['latitude'] );
				update_post_meta( $post->ID, 'bgmp_longitude', $coordinates['longitude'] );
			} else {
				update_post_meta( $post->ID, 'bgmp_latitude', '' );
				update_post_meta( $post->ID, 'bgmp_longitude', '' );
			}

			// Save z-index
			if ( isset( $_POST['bgmp_z_index'] ) ) {
				if ( false === filter_var( $_POST['bgmp_z_index'], FILTER_VALIDATE_INT ) ) {
					update_post_meta( $post->ID, 'bgmp_z_index', 0 );
					add_notice( __( 'The stacking order has to be an integer.', 'bgmp' ), 'error' );
				} else {
					update_post_meta( $post->ID, 'bgmp_z_index', $_POST['bgmp_z_index'] );
				}
			}
		}

		/**
		 * Geocodes an address
		 *
		 * @param string $address
		 * @return mixed
		 */
		public function geocode( $address ) {
			// @todo - this should be static, or better yet, broken out into an Address class

			// Bypass geocoding if already have valid coordinates
			$coordinates = self::validate_coordinates( $address );
			if ( is_array( $coordinates ) ) {
				return $coordinates;
			}

			// Geocode address and handle errors
			$request_url = add_query_arg(
				array(
					'address' => rawurlencode( $address ),
					'sensor'  => false,
				),
				'http://maps.googleapis.com/maps/api/geocode/json'
			);
			$geocode_response = wp_remote_get( esc_url_raw( $request_url ) );

			if ( is_wp_error( $geocode_response ) ) {
				add_notice( sprintf(
					__( '%s geocode error: %s', 'bgmp' ),
					BGMP_NAME,
					implode( '<br />', $geocode_response->get_error_messages() )
				), 'error' );

				return false;
			}

			// Check response code
			if ( ! isset( $geocode_response['response']['code'] ) || ! isset( $geocode_response['response']['message'] ) ) {
				add_notice( sprintf(
					__( '%s geocode error: Response code not present', 'bgmp' ),
					BGMP_NAME
				), 'error' );

				return false;
			} elseif ( $geocode_response['response']['code'] != 200 ) {
				/*
					@todo - strip content inside <style> tag. regex inappropriate, but DOMDocument doesn't exist on dev server, but does on most?. would have to wrap this in an if( class_exists() )...
					
					$responseHTML = new DOMDocument();
					$responseHTML->loadHTML( $geocode_response[ 'body' ] );
					// nordmalize it b/c doesn't have <body> tag inside?
					$this->describe( $responseHTML->saveHTML() );
				*/

				add_notice( sprintf(
					__( '<p>%s geocode error: %d %s</p> <p>Response: %s</p>', 'bgmp' ),
					BGMP_NAME,
					$geocode_response['response']['code'],
					$geocode_response['response']['message'],
					strip_tags( $geocode_response['body'] )
				), 'error' );

				return false;
			}

			// Decode response and handle errors
			$coordinates = json_decode( $geocode_response['body'] );

			if ( function_exists( 'json_last_error' ) && json_last_error() != JSON_ERROR_NONE ) {
				// @todo - Once PHP 5.3+ is more widely adopted, remove the function_exists() check here and just bump the PHP requirement to 5.3

				add_notice( sprintf( __( '%s geocode error: Response was not formatted in JSON.', 'bgmp' ), BGMP_NAME ), 'error' );
				return false;
			}

			if ( isset( $coordinates->status ) && 'REQUEST_DENIED' == $coordinates->status ) {
				add_notice( sprintf( __( '%s geocode error: Request Denied.', 'bgmp' ), BGMP_NAME ), 'error' );
				return false;
			}

			if ( ! isset( $coordinates->results ) || empty( $coordinates->results ) ) {
				add_notice( __( "That address couldn't be geocoded, please make sure that it's correct.", 'bgmp' ), "error" );
				add_notice(
					__( "Geocode response:", 'bgmp' ) . ' <pre>' . print_r( $coordinates, true ) . '</pre>',
					"error"
				);
				return false;
			}

			return array( 'latitude' => $coordinates->results[0]->geometry->location->lat, 'longitude' => $coordinates->results[0]->geometry->location->lng );
		}

		/**
		 * Checks if a given string represents a valid set of geographic coordinates
		 *
		 * Expects latitude/longitude notation, not minutes/seconds
		 *
		 * @param string $coordinates
		 * @return mixed false if any of the tests fails | an array with 'latitude' and 'longitude' keys/value pairs if all of the tests succeed
		 */
		public static function validate_coordinates( $coordinates ) {
			// @todo - some languages swap the roles of the commas and decimal point. this assumes english.

			$coordinates = str_replace( ' ', '', $coordinates );

			if ( ! $coordinates ) {
				return false;
			}

			if ( 1 != substr_count( $coordinates, ',' ) ) {
				return false;
			}

			$coordinates = explode( ',', $coordinates );
			$latitude    = $coordinates[0];
			$longitude   = $coordinates[1];

			if ( ! is_numeric( $latitude ) || $latitude < -90 || $latitude > 90 ) {
				return false;
			}

			if ( ! is_numeric( $longitude ) || $longitude < -180 || $longitude > 180 ) {
				return false;
			}

			return array( 'latitude' => $latitude, 'longitude' => $longitude );
		}

		/**
		 * Reverse-geocodes a set of coordinates
		 *
		 * Google's API has a daily request limit, but this is only called during upgrades from 1.0, so that shouldn't ever be a problem.
		 *
		 * @param string $latitude
		 * @param string $longitude
		 */
		protected function reverse_geocode( $latitude, $longitude ) {
			$request_url = add_query_arg(
				array(
					'latlng' => rawurlencode( $latitude . ',' . $longitude ),
					'sensor' => 'false',
				),
				'http://maps.googleapis.com/maps/api/geocode/json' );
			$geocode_response = wp_remote_get( $request_url );
			$address          = json_decode( $geocode_response['body'] );

			if ( is_wp_error( $geocode_response ) || empty( $address->results ) ) {
				return false;
			} else {
				return $address->results[0]->formatted_address;
			}
		}

		/**
		 * Defines the [bgmp-map] shortcode
		 *
		 * @param array $attributes Array of parameters automatically passed in by WordPress
		 * @return string The output of the shortcode
		 */
		public function map_shortcode( $attributes ) {
			if ( ! wp_script_is( 'google-maps', 'queue' ) || ! wp_script_is( 'bgmp', 'queue' ) || ! wp_style_is( 'bgmp_style', 'queue' ) ) {
				$error = sprintf(
					__( '<p class="error">%s error: JavaScript and/or CSS files aren\'t loaded. If you\'re using do_shortcode() you need to add a filter to your theme first. See <a href="%s">the FAQ</a> for details.</p>', 'bgmp' ),
					BGMP_NAME,
					'http://wordpress.org/extend/plugins/basic-google-maps-placemarks/faq/'
				);

				return $error;
			}

			$base_url = plugins_url( '', dirname( __FILE__ ) );

			if ( isset( $attributes['categories'] ) )
				$attributes['categories'] = apply_filters( 'bgmp_map_shortcode_categories', $attributes['categories'] ); // @todo - deprecated b/c 1.9 output bgmpdata in post; can now just set args in do_shortcode() . also  not consistent w/ shortcode naming scheme and have filter for all arguments now. need a way to notify people

			$attributes = apply_filters( 'bgmp_map-shortcode-arguments', $attributes ); // @todo - deprecated b/c 1.9 output bgmpdata in post...
			$attributes = $this->clean_map_shortcode_arguments( $attributes );

			ob_start();
			do_action( 'bgmp_meta-address-before' );	// @todo - deprecated b/c named incorrectly
			do_action( 'bgmp_shortcode-bgmp-map-before' );  // @todo - deprecated b/c render_template() provides ability to completely override view?
			echo $this->render_template( 'core/shortcode-bgmp-map.php', array( 'attributes' => $attributes, 'base_url' => $base_url ) );
			echo $this->render_template( 'javascript/map-info-window-content.php' );
			do_action( 'bgmp_shortcode-bgmp-map-after' );
			$output = ob_get_clean();

			$this->pass_map_data_to_javascript( $attributes );

			return $output;
		}

		/**
		 * Output all the data required for the [bgmp-map] JavaScript to run
		 *
		 * @param array $shortcode_attributes
		 */
		protected function pass_map_data_to_javascript( $shortcode_attributes ) {
			$javascript_params = new stdClass();
			$javascript_params->options = $this->get_map_options(    $shortcode_attributes );
			$javascript_params->markers = $this->get_map_placemarks( $shortcode_attributes );

			wp_localize_script( 'bgmp', 'bgmpData', $javascript_params );
		}

		/**
		 * Defines the [bgmp-list] shortcode
		 *
		 * @param array $attributes Array of parameters automatically passed in by WordPress
		 * @return string The output of the shortcode
		 */
		public function list_shortcode( $attributes ) {
			$attributes = apply_filters( 'bgmp_list-shortcode-arguments', $attributes );
			// @todo shortcode_atts()

			$params = array(
				'numberposts' => - 1,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC'
			);

			if ( isset( $attributes['categories'] ) && ! empty( $attributes['categories'] ) ) {
				// @todo - check each cat to make sure it exists? if not, print error to admin panel.
				// non-existant cats don't break the query or anything, so the only purpose for this would be to give feedback to the admin.

				$params['tax_query'] = array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => explode( ',', $attributes['categories'] )
					)
				);
			}

			$view_on_map = isset( $attributes['viewonmap'] ) && true == $attributes['viewonmap'];

			$posts = get_posts( apply_filters( 'bgmp_list-shortcode-params', $params ) );
			$posts = apply_filters( 'bgmp_list-shortcode-posts', $posts );

			if ( $posts ) {
				$output = '<ul id="' . 'bgmp_list" class="' . 'bgmp_list">'; // Note: id should be removed and everything switched to class, because there could be more than one list on a page. That would be backwards-compatability, though.

				foreach ( $posts as $p ) {
					$categories = wp_list_pluck( wp_get_post_terms( $p->ID, self::TAXONOMY ), 'slug' );
					foreach( $categories as & $category ) {
						$category = 'bgmp_category-' . $category;
					}

					$address   = get_post_meta( $p->ID, 'bgmp_address', true );
					$variables = array(
						'p'                => $p,
						'view_on_map'      => $view_on_map,
						'google_maps_url'  => add_query_arg( 'q', rawurlencode( $address ), 'https://ditu.google.cn/maps' ),
						'address'          => $address,
						'category_classes' => implode( ' ', $categories ),
					);
					$marker_html = $this->render_template( 'core/shortcode-bgmp-list-marker.php', $variables, 'always' );

					$output .= apply_filters( 'bgmp_list-marker-output', $marker_html, $p->ID );	// @todo deprecate b/c render_template has filter for this and everything else now
				}

				$output .= '</ul>';

				return $output;
			} else {
				return __( 'No Placemarks found', 'bgmp' );
			}
		}

		/**
		 * Gets map options
		 *
		 * @param array $attributes
		 * @return array array
		 */
		public function get_map_options( $attributes ) {
			$options = array(	// can't conform to style guidelines because of backcompat
				'mapWidth'              => $this->settings->map_width, // @todo move these into 'map' subarray? but then have to worry about backwards compat
				'mapHeight'             => $this->settings->map_height,
				'latitude'              => $this->settings->map_latitude,
				'longitude'             => $this->settings->map_longitude,
				'zoom'                  => $this->settings->map_zoom,
				'type'                  => $this->settings->map_type,
				'typeControl'           => $this->settings->map_type_control,
				'navigationControl'     => $this->settings->map_navigation_control,
				'infoWindowMaxWidth'    => $this->settings->map_info_window_max_width,
				'infoWindowPixelOffset' => array( 'width' => 0, 'height' => 0 ),
				'streetViewControl'     => apply_filters( 'bgmp_street-view-control', true ), // deprecated b/c of bgmp_map-options filter?
				'viewOnMapScroll'       => false,

				'clustering'            => array(
					'enabled'           => $this->settings->marker_clustering,
					'maxZoom'           => $this->settings->cluster_max_zoom,
					'gridSize'          => $this->settings->cluster_grid_size,
					'style'             => $this->settings->cluster_style,
					'styles'            => $this->get_cluster_styles(),
				),
			);

			// Reset center/zoom when only displaying single placemark
			if ( isset( $attributes['placemark'] ) && apply_filters( 'bgmp_reset-individual-map-center-zoom', true ) ) {
				$latitude    = get_post_meta( $attributes['placemark'], 'bgmp_latitude', true );
				$longitude   = get_post_meta( $attributes['placemark'], 'bgmp_longitude', true );
				$coordinates = $this->validate_coordinates( $latitude . ',' . $longitude );

				if ( false !== $coordinates ) {
					$options['latitude']  = $latitude;
					$options['longitude'] = $longitude;
					$options['zoom']      = apply_filters( 'bgmp_individual-map-default-zoom', 13 ); // deprecated b/c of bgmp_map-options filter?
				}
			}

			$options = shortcode_atts( $options, $attributes );

			return apply_filters( 'bgmp_map-options', $options );
		}

		protected function get_cluster_styles() {
			$cluster_styles = array(
				'people' => array(
					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/people35.png', dirname( __FILE__ ) ),
						'height'      => 35,
						'width'       => 35,
						'anchor'      => array( 16, 0 ),
						'textColor'   => '#ff00ff',
						'textSize'    => 10
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/people45.png', dirname( __FILE__ ) ),
						'height'      => 45,
						'width'       => 45,
						'anchor'      => array( 24, 0 ),
						'textColor'   => '#ff0000',
						'textSize'    => 11
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/people55.png', dirname( __FILE__ ) ),
						'height'      => 55,
						'width'       => 55,
						'anchor'      => array( 32, 0 ),
						'textColor'   => '#ffffff',
						'textSize'    => 12
					)
				),

				'conversation' => array(
					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/conv30.png', dirname( __FILE__ ) ),
						'height'      => 27,
						'width'       => 30,
						'anchor'      => array( 3, 0 ),
						'textColor'   => '#ff00ff',
						'textSize'    => 10
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/conv40.png', dirname( __FILE__ ) ),
						'height'      => 36,
						'width'       => 40,
						'anchor'      => array( 6, 0 ),
						'textColor'   => '#ff0000',
						'textSize'    => 11
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/conv50.png', dirname( __FILE__ ) ),
						'height'      => 50,
						'width'       => 45,
						'anchor'      => array( 8, 0 ),
						'textSize'    => 12
					)
				),

				'hearts' => array(
					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/heart30.png', dirname( __FILE__ ) ),
						'height'      => 26,
						'width'       => 30,
						'anchor'      => array( 4, 0 ),
						'textColor'   => '#ff00ff',
						'textSize'    => 10
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/heart40.png', dirname( __FILE__ ) ),
						'height'      => 35,
						'width'       => 40,
						'anchor'      => array( 8, 0 ),
						'textColor'   => '#ff0000',
						'textSize'    => 11
					),

					array(
						'url'         => plugins_url( 'includes/marker-clusterer/images/heart50.png', dirname( __FILE__ ) ),
						'height'      => 50,
						'width'       => 44,
						'anchor'      => array( 12, 0 ),
						'textSize'    => 12
					)
				)
			);

			return $cluster_styles;
		}

		/**
		 * Gets the published placemarks from the database, formats and outputs them.
		 *
		 * @param array $attributes
		 * @return string JSON-encoded array
		 */
		public function get_map_placemarks( $attributes ) {
			$placemarks = array();

			$query = array(
				'numberposts' => - 1,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish'
			);

			if ( isset( $attributes['placemark'] ) )
				$query['p'] = $attributes['placemark'];

			if ( isset( $attributes['categories'] ) && ! empty( $attributes['categories'] ) ) {
				$query['tax_query'] = array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $attributes['categories']
					)
				);
			}

			$query                = apply_filters( 'bgmp_get-placemarks-query', $query ); // @todo - filter name deprecated
			$published_placemarks = get_posts( apply_filters( 'bgmp_get-map-placemarks-query', $query ) );

			if ( $published_placemarks ) {
				foreach ( $published_placemarks as $pp ) {
					$post_id = $pp->ID;

					$categories = get_the_terms( $post_id, self::TAXONOMY );
					if ( ! is_array( $categories ) ) {
						$categories = array();
					}

					$icon        = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), apply_filters( 'bgmp_featured-icon-size', 'thumbnail' ) );
					$default_icon = apply_filters( 'bgmp_default-icon', plugins_url( 'images/default-marker.png', dirname( __FILE__ ) ), $post_id );

					$placemark = array(
						'id'         => $post_id,
						'title'      => apply_filters( 'the_title', $pp->post_title ),
						'latitude'   => get_post_meta( $post_id, 'bgmp_latitude', true ),
						'longitude'  => get_post_meta( $post_id, 'bgmp_longitude', true ),
						'details'    => apply_filters( 'the_content', $pp->post_content ), // note: don't use setup_postdata/get_the_content() in this instance -- http://lists.automattic.com/pipermail/wp-hackers/2013-January/045053.html
						'categories' => $categories,
						'icon'       => is_array( $icon ) ? $icon[0] : $default_icon,
						'z_index'     => get_post_meta( $post_id, 'bgmp_z_index', true )
					);

					$placemarks[] = apply_filters( 'bgmp_get-map-placemarks-individual-placemark', $placemark );
				}
			}

			$placemarks = apply_filters( 'bgmp_get-placemarks-return', $placemarks ); // @todo - filter name deprecated
			return apply_filters( 'bgmp_get-map-placemarks-return', $placemarks );
		}

		/**
		 * Render a template
		 *
		 * Allows parent/child themes to override the markup by placing the a file named basename( $default_template_path ) in their root folder,
		 * and also allows plugins or themes to override the markup by a filter. Themes might prefer that method if they place their templates
		 * in sub-directories to avoid cluttering the root folder. In both cases, the theme/plugin will have access to the variables so they can
		 * fully customize the output.
		 *
		 * @param  string $default_template_path The path to the template, relative to the plugin's `views` folder
		 * @param  array  $variables             An array of variables to pass into the template's scope, indexed with the variable name so that it can be extract()-ed
		 * @param  string $require               'once' to use require_once() | 'always' to use require()
		 * @return string
		 */
		public function render_template( $default_template_path = false, $variables = array(), $require = 'once' ) {
			$template_path = locate_template( basename( $default_template_path ) );
			// todo need to prefix template name w/ 'bgmp' to avoid conflicts? probably

			if ( ! $template_path ) {
				$template_path = dirname( dirname( __FILE__ ) ) . '/views/' . $default_template_path;
			}
			$template_path = apply_filters( 'bgmp_template_path', $template_path );

			if ( is_file( $template_path ) ) {
				extract( $variables );
				ob_start();

				if ( 'always' == $require ) {
					require( $template_path );
				} else {
					require_once( $template_path );
				}

				$template_content = apply_filters( 'bgmp_template_content', ob_get_clean(), $default_template_path, $template_path, $variables );
			} else {
				$template_content = '';
			}

			return $template_content;
		}

		/**
		 * Writes options to the database
		 */
		public function shutdown() {
			if ( $this->updated_options ) {
				update_option( 'bgmp_options', $this->options );
			}
		}
	} // end Basic_Google_Maps_Placemarks
}
