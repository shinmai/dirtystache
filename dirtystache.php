<?php
/*
Plugin Name: DustPress
Plugin URI: http://www.geniem.com
Description: Dust.js templating system for WordPress
Author: Miika Arponen & Ville Siltala / Geniem Oy
Author URI: http://www.geniem.com
License: GPLv3
Version: 1.6.5
*/

final class DustPress {

	// Singleton DustPress instance
	private static $instance;

	// Instance of DustPHP
	public $dust;

	// Main model
	private $model;

	// This is where the data will be stored
	private $data;

	// DustPress settings
	private $settings;

	// Is DustPress disabled?
	public $disabled;

	// Paths for locating files
	private $paths;

	// Registered custom ajax functions
	private $ajax_functions;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
            self::$instance = new DustPress();
        }
        return self::$instance;
	}

	/**
	*  Constructor for DustPress core class.
	*
	*  @type	function
	*  @date	10/8/2015
	*  @since	0.2.0
	*
	*  @param	N/A
	*  @return	N/A
	*/

	protected function __construct() {

		$this->register_autoloaders();

		// Create a DustPHP instance
		$this->dust = new Dust\Dust();

		// Set paths for where to look for partials and models
		$this->paths = [
			get_stylesheet_directory(),
			get_template_directory()
		];

		$this->paths = array_values( array_unique( $this->paths ) );

        // Find and include Dust helpers from DustPress plugin
        $paths = [
            __DIR__ . '/helpers',
        ];

        foreach( $paths as $path ) {
            if ( is_readable( $path ) ) {
                foreach( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
                    if ( is_readable( $file ) && '.php' === substr( $file, -4, 4 ) ) {
                        require_once( $file );
                    }
                }
            }
        }

		// Add create_instance to right action hook if we are not on the admin side
		if ( $this->want_autoload() ) {
			add_filter( 'template_include', [ $this, 'create_instance' ] );
		}
		else if ( $this->is_dustpress_ajax() ) {
			add_filter( 'template_include', [ $this, 'create_ajax_instance' ] );
		}

		// Initialize settings
		add_action( 'init', [ $this, 'init_settings' ] );

		return;
	}

	/**
	*  This function creates the instance of the main model that is defined by the WordPress template
	*
	*  @type	function
	*  @date	19/3/2015
	*  @since	0.0.1
	*
	*  @param   N/A
	*  @return	N/A
	*/
	public function create_instance() {
		global $post;

		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = $post->ID;
		}
		else {
			$post_id = null;
		}

		// Filter for wanted post ID
		$new_post = apply_filters( "dustpress/router", $post_id );

		// If developer wanted a post ID, make it happen
		if ( ! is_null( $new_post ) ) {
			$post = get_post( $new_post );

			setup_postdata( $post );
		}

		// Initialize an array for debugging.
		$debugs = [];

		// Get current template name tidied up a bit.
		$template = $this->get_template_filename( $debugs );

		$template = apply_filters( "dustpress/template", $template );

		if ( ! defined("DOING_AJAX") && ! $this->disabled ) {
			// If class exists with the template's name, create new instance with it.
			// We do not throw error if the class does not exist, to ensure that you can still create
			// templates in traditional style if needed.
			if ( class_exists ( $template ) ) {
				$this->model = new $template();

				$this->model->fetch_data();

				do_action( 'dustpress/model_list', array_keys( (array) $this->model->get_submodels() ) );

				$template_override = $this->model->get_template();

				$partial = $template_override ? $template_override : strtolower( $this->camelcase_to_dashed( $template ) );

				$this->render( [ "partial" => $partial, "main" => true ] );
			}
			else {
				die("DustPress error: No suitable model found. One of these is required: ". implode(", ", $debugs));
			}
		}
	}

	/**
	*  This function gets current template's filename and returns without extension or WP-template prefixes such as page- or single-.
	*
	*  @type	function
	*  @date	19/3/2015
	*  @since	0.0.1
	*
	*  @param	N/A
	*  @return	$filename (string)
	*/

	private function get_template_filename( &$debugs = array() ) {
		global $post;

		if ( is_object( $post ) && isset( $post->ID ) ) {
			$page_template = get_post_meta( $post->ID, '_wp_page_template', true );

			if ( $page_template ) {
				$array = explode( "/", $page_template );

				$template = array_pop( $array );

				// strip out .php
				$template = str_replace( ".php", "", $template );

				// strip out page-, single-
				$template = str_replace( "page-", "", $template );
				$template = str_replace( "single-", "", $template );

				if ( $template == "default" ) $template = "page";
			}
			else {
				$template = "default";
			}
		}
		else {
			$template = "default";
		}

		if ( is_front_page() ) {
			$hierarchy = [
				"is_front_page" => [
				    "FrontPage"
				]
			];
		}

		if ( is_home() ) {
			$hierarchy["is_home"] = [
			    "Home"
			];
		}

		if ( is_page() ) {
			$hierarchy["is_page"] = [
				"Page" . $this->dashed_to_camelcase( $template ),
				"Page" . $this->dashed_to_camelcase( $post->post_name ),
				"Page" . $post->ID,
				"Page"
			];
		}

		if ( is_category() ) {
			$cat = get_category( get_query_var('cat') );

			$hierarchy["is_category"] = [
				"Category" . $this->dashed_to_camelcase( $cat->slug ),
				"Category" . $cat->term_id,
				"Category",
				"Archive"
			];
		}

		if ( is_tag() ) {
			$term_id = get_queried_object()->term_id;
			$term = get_term_by( "id", $term_id, "post_tag" );

			$hierarchy["is_tag"] = [
				"Tag" . $this->dashed_to_camelcase( $term->slug ),
				"Tag",
				"Archive"
			];
		}

		if ( is_tax() ) {
			$term_id = get_queried_object()->term_id;
			$term = get_term_by( "id", $term_id, get_query_var('taxonomy') );

			$hierarchy["is_tax"] = [
				"Taxonomy" . $this->dashed_to_camelcase( get_query_var('taxonomy') ) . $this->dashed_to_camelcase($term->slug),
				"Taxonomy" . $this->dashed_to_camelcase( get_query_var('taxonomy') ),
				"Taxonomy",
				"Archive"
			];
		}

		if ( is_author() ) {
			$author = get_user_by( 'slug', get_query_var( 'author_name' ) );

			$hierarchy["is_author"] = [
				"Author" . $this->dashed_to_camelcase( $author->user_nicename ),
				"Author" . $author->ID,
				"Author",
				"Archive"
			];
		}

		$hierarchy["is_search"] = [
			"Search"
		];


		$hierarchy["is_404"] = [
			"Error404"
		];

		if ( is_attachment() ) {
			$mime_type = get_post_mime_type( get_the_ID() );

			$hiearchy["is_attachment"] = [
				function() use ( $mime_type ) {
					if ( preg_match( "/^image/", $mime_type ) && class_exists("Image") ) {
						return "Image";
					}
					else {
						return false;
					}
				},
				function() use ( $mime_type ) {
					if ( preg_match( "/^video/", $mime_type ) && class_exists("Video") ) {
						return "Video";
					}
					else {
						return false;
					}
				},
				function() use ( $mime_type ) {
					if ( preg_match( "/^application/", $mime_type ) && class_exists("Application") ) {
						return "Application";
					}
					else {
						return false;
					}
				},
				function() use ( $mime_type ) {
					if ( $mime_type == "text/plain" ) {
						if ( class_exists( "Text" ) ) {
							return "Text";
						}
						else if ( class_exists( "Plain" ) ) {
							return "Plain";
						}
						else if ( class_exists( "TextPlain" ) ) {
							return "TextPlain";
						}
						else {
							return false;
						}
					}
					else {
						return false;
					}
				},
				"Attachment",
				"SingleAttachment",
				"Single"
			];
		}

		if ( is_single() ) {
			$type = get_post_type();

			$hierarchy["is_single"] = [
				"Single" . $this->dashed_to_camelcase( $template ),
				"Single" . $this->dashed_to_camelcase( $type ),
				"Single"
			];
		}

		if ( is_archive() ) {
			// Double check just to keep the function structure.
			$hierarchy["is_archive"] = [
				function() {
					$post_types = get_post_types();

					foreach ( $post_types as $type ) {
						if ( is_post_type_archive( $type ) ) {
							if ( class_exists( "Archive" . $this->dashed_to_camelcase( $type ) ) ) {
								return "Archive" . $this->dashed_to_camelcase( $type );
							}
							else if ( class_exists("Archive") ) {
								return "Archive";
							}
							else {
								return false;
							}
						}
					}

					return false;
				}
			];
		}

		if ( is_date() ) {

			// Double check just to keep the function structure.
			$hierarchy["is_date"] = [
				function() {
					if ( class_exists( "Date" ) ) {
						return "Date";
					}
					else if ( class_exists( "Archive" ) ) {
						return "Archive";
					}
					else {
						return false;
					}
				}
			];
		}

		// I don't think you really want to do this.
		$hierarchy = apply_filters( "dustpress/template_hierarchy", $hierarchy );

		foreach( $hierarchy as $level => $keys ) {
			if ( true === call_user_func ( $level ) ) {
				foreach( $keys as $key => $value ) {
					if ( is_integer( $key ) ) {
						if ( is_string( $value ) ) {
							$debugs[] = $value;
							if ( class_exists( $value ) ) {
								return $value;
							}
						}
						else if ( is_callable( $value ) ) {
							$value = call_user_func( $value );
							$debugs[] = $value;
							if( is_string( $value ) && class_exists( $value ) ) {
								return $value;
							}
						}
					}
					else if ( is_string( $key ) ) {
						if ( class_exists( $key ) ) {
							if( is_string( $value ) ) {
								$debugs[] = $value;
								if ( class_exists( $value ) ) {
									return $value;
								}
							}
							else if ( is_callable( $value ) ) {
								$debugs[] = $value;
								return call_user_func( $value );
							}
						}
					}
					else if ( true === $key or is_callable( $key ) ) {
						if ( true === call_user_func( $key ) ) {
							if( is_string( $value ) ) {
								$debugs[] = $value;
								if ( class_exists( $value ) ) {
									return $value;
								}
							}
							else if ( is_callable( $value ) ) {
								$debugs[] = $value;
								return call_user_func( $value );
							}
						}
					}
				}
			}
		}

		$debugs[] = "Index";
		return "Index";
	}

	/**
	*  This function populates the data collection with essential data
	*
	*  @type	function
	*  @date	17/3/2015
	*  @since	0.0.1
	*
	*  @param	N/A
	*  @return	N/A
	*/
	private function populate_data_collection() {
		$wp_data = array();

		// Insert Wordpress blog info data to collection
		$infos = array( "name","description","wpurl","url","admin_email","charset","version","html_type","is_rtl","language","stylesheet_url","stylesheet_directory","template_url","template_directory","pingback_url","atom_url","rdf_url","rss_url","rss2_url","comments_atom_url","comments_rss2_url","url" );

		if ( $this->is_dustpress_ajax() ) {
			$remove_infos = array( "wpurl", "admin_email", "version", "user" );

			$remove_infos = apply_filters( "dustpress/ajax/remove_wp", $remove_infos );

			$infos = array_diff( $infos, $remove_infos );
		}

		foreach ( $infos as $info ) {
			$wp_data[ $info ] = get_bloginfo( $info );
		}

		// Insert user info to collection

		$currentuser = wp_get_current_user();

		if ( 0 === $currentuser->ID ) {
			$wp_data["loggedin"] = false;
		}
		else {
			$wp_data["loggedin"] = true;
			$wp_data["user"] = $currentuser->data;
			$wp_data["user"]->roles = $currentuser->roles;
			unset( $wp_data["user"]->user_pass );
		}

		// Insert WP title to collection
		ob_start();
		wp_title();
		$wp_data["title"] = ob_get_clean();

		// Insert admin ajax url
		$wp_data["admin_ajax_url"] = admin_url( 'admin-ajax.php' );

		// Insert current page permalink
		$wp_data["permalink"] = get_permalink();

		// Insert body classes
		$wp_data["body_class"] = get_body_class();

		// Return collection after filters
		return apply_filters( "dustpress/data/wp", $wp_data );
	}

	/**
	*  This function will render the given data in selected format
	*
	*  @type	function
	*  @date	17/3/2015
	*  @since	0.0.1
	*
	*  @param	$partial (string)
	*  @param	$data (N/A)
	*  @param	$type (string)
	*  @return	true/false (boolean)
	*/
	public function render( $args = array() ) {
		global 	$dustpress;
				$hash;

		$defaults = [
			"data" => false,
			"type" => "default",
			"echo" => true,
			"main" => false,
		];

		if ( is_array( $args ) ) {
			if ( ! isset( $args["partial"] ) ) {
				die("<p><b>DustPress error:</b> No partial is given to the render function.</p>");
			}
		}

		$options = array_merge( $defaults, (array)$args );

		extract( $options );

		if ( "default" == $type && ! get_option('dustpress_default_format' ) ) {
			$type = "html";
		}
		else if ( "default" == $type && get_option('dustpress_default_format' ) ) {
			$type = get_option('dustpress_default_format');
		}

		if ( $this->get_setting('json_url') && isset( $_GET['json'] ) ) {
			$type = 'json';
		}

		if ( $this->get_setting('json_headers') && isset( $_SERVER['HTTP_ACCEPT'] ) && $_SERVER['HTTP_ACCEPT'] == 'application/json' ) {
			$type = 'json';
		}

		$types = array(
			"html" => function( $data, $partial, $dust ) {

				try {
					if ( apply_filters( "dustpress/cache/partials", false ) && apply_filters( "dustpress/cache/partials/" . $partial, true ) ) {
						if ( ! ( $compiled = wp_cache_get( $partial, "dustpress/partials" ) ) ) {
							$compiled = $dust->compileFile( $partial );

							wp_cache_set( $partial, $compiled, "dustpress/partials" );
						}
					}
					else {
						$compiled = $dust->compileFile( $partial );
					}
				}
				catch ( Exception $e ) {
					die( "DustPress error: ". $e->getMessage() );
				}

				if ( apply_filters( "dustpress/cache/rendered", false ) && apply_filters( "dustpress/cache/rendered/" . $partial, true ) ) {
					$data_hash = sha1( serialize( $compiled ) . serialize( $data ) );

					$cache_time = apply_filters( "dustpress/settings/partial/" . $partial, $this->get_setting("rendered_expire_time") );

					if ( ! ( $rendered = wp_cache_get( $data_hash, "dustpress/rendered" ) ) ) {
						$rendered = $dust->renderTemplate( $compiled, $data );

						wp_cache_set( $data_hash, $rendered, "dustpress/rendered", $cache_time );
					}
				}
				else {
					$rendered = $dust->renderTemplate( $compiled, $data );
				}

				return $rendered;
			},
			"json" => function( $data, $partial, $dust ) {
				try {
					$output = json_encode( $data );
				}
				catch ( Exception $e ) {
					die( "JSON encode error: ". $e->getMessage() );
				}

				return $output;
			}
		);

		$types = apply_filters( 'dustpress/formats', $types );

		if ( ! $data ) {
			$this->model->data = (array)$this->model->data;

			$this->model->data['WP'] = $this->populate_data_collection();
		}

		// Ensure we have a DustPHP instance.
		if ( isset( $this->dust ) ) {
			$dust = $this->dust;
		}
		else {
			die("DustPress error: Something very unexpected happened: there is no DustPHP.");
		}

		$dust->helpers = apply_filters( 'dustpress/helpers', $dust->helpers );

		// Fetch Dust partial by given name. Throw error if there is something wrong.
		try {
			$template = $this->get_template( $partial );

			$helpers = $this->prerender( $partial );

			$this->prerun_helpers( $helpers );
		}
		catch ( Exception $e ) {
			die( "DustPress error: ". $e->getMessage() );
		}

		if ( $data ) {
			$render_data = apply_filters( 'dustpress/data', $data );
		}
		else {
			$render_data = apply_filters( 'dustpress/data', $this->model->data );
			$render_data = apply_filters( 'dustpress/data/main', $render_data );
		}

		$this->dust->includedDirectories = $this->get_template_paths('partials');

		// Create output with wanted format.
		$output = call_user_func_array( $types[$type], array( $render_data, $template, $dust ) );

		// Filter output
		$output = apply_filters( 'dustpress/output', $output, $options );

		// Do something with the data after rendering
		apply_filters( "dustpress/data/after_render", $render_data );

		if ( $echo ) {
			if ( empty ( strlen( $output ) ) ) {
				$error = true;
				echo "DustPress warning: empty output.";
			}
			else {
				echo $output;
			}
		}
		else {
			return $output;
		}

		if ( isset( $error ) ) {
			return false;
		}
		else {
			return true;
		}
	}

	/**
	*  This function checks whether the given partial exists and returns the contents of the file as a string
	*
	*  @type	function
	*  @date	17/3/2015
	*  @since	0.0.1
	*
	*  @param	$partial (string)
	*  @return	$template (string)
	*/
	private function get_template( $partial ) {
		// Check if we have received an absolute path.
		if ( file_exists( $partial ) )
			return $partial;
		else {
			$templatefile =  $partial . '.dust';

			$templatepaths = $this->get_template_paths("partials");

			foreach ( $templatepaths as $templatepath ) {
				if ( is_readable( $templatepath ) ) {
					foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $templatepath ) ) as $file ) {
						if ( basename( $file ) == $templatefile ) {
							if ( is_readable( $file ) ) {
								return $file;
							}
						}
					}
				}
			}

			// If we could not find such template.
			throw new Exception( "Error loading template file: " . $partial, 1 );

			return false;
		}
	}

	/**
	*  This function initializes DustPress settings with default values
	*
	*  @type    function
	*  @date    01/04/2016
	*  @since   0.4.0
	*
	*  @return  N/A
	*/

	public function init_settings() {
		$this->settings = [
			"cache" => false,
			"debug_data_block_name" => "Helper data",
			"rendered_expire_time" => 7*60*60*24,
			"json_url" => false,
			"json_headers" => false,
		];

		// loop through the settings and execute possible filters from functions
		foreach ( $this->settings as $key => $value ) {
			$this->settings[ $key ] = apply_filters( "dustpress/settings/". $key, $value );
		}

		// A hook to prevent DustPress error to appear in Yoast's sitemap
		add_filter( 'wpseo_build_sitemap_post_type', array( $this, 'disable' ), 1, 1 );

		// A hook to prevent DustPress error to appear when using WP Rest API
		add_action( 'rest_api_init', array( $this, 'disable' ), 1, 1 );

		// A hook to prevent DustPress error to appear when generating robots.txt
		add_action( 'do_robotstxt', array( $this, 'disable' ), 1, 1 );

		return null;
	}

	/**
	*  This function returns DustPress setting for specific key.
	*
	*  @type	function
	*  @date	29/01/2016
	*  @since	0.3.1
	*
	*  @return	$setting (any)
	*/

	public function get_setting( $key ) {
		return apply_filters( "dustpress/settings/" . $key, isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null );
	}

	/**
	*  Returns true if we are on login or register page.
	*
	*  @type	function
	*  @date	9/4/2015
	*  @since	0.0.7
	*
	*  @param	N/A
	*  @return	true/false (boolean)
	*/

	public function is_login_page() {
	    return in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) );
	}

	/**
	*  This function returns given string converted from CamelCase to camel-case
	*  (or probably camel_case or somethinge else, if wanted).
	*
	*  @type	function
	*  @date	15/6/2015
	*  @since	0.1.0
	*
	*  @param	$string (string)
	*  @param   $char (string)
	*  @return	(string)
	*/
	public function camelcase_to_dashed( $string, $char = "-" ) {
		preg_match_all( '!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $string, $matches );
		$results = $matches[0];
		foreach ( $results as &$match ) {
	    	$match = $match == strtoupper( $match ) ? strtolower( $match ) : lcfirst( $match );
		}

	 	return implode( $char, $results );
	}

	/**
	*  This function returns given string converted from camel-case to CamelCase
	*  (or probably camel_case or somethinge else, if wanted).
	*
	*  @type	function
	*  @date	1/10/2016
	*  @since	1.2.9
	*
	*  @param	$string (string)
	*  @param   $char (string)
	*  @return	(string)
	*/
	public function dashed_to_camelcase( $string, $char = "-" ) {
		$string = str_replace( $char, " ", $string );
		$string = str_replace( " ", "", ucwords( $string ) );

		return $string;
	}

	/**
	*  This function determines if we want to autoload and render the model or not.
	*
	*  @type	function
	*  @date	10/8/2015
	*  @since	0.2.0
	*
	*  @return	(boolean)
	*/
	private function want_autoload() {
		$conditions = [
			function() {
				return ! $this->is_dustpress_ajax();
			},
			function() {
				return ! is_admin();
			},
			function() {
				return ! $this->is_login_page();
			},
			function() {
				return ! defined( "WP_CLI" );
			},
			function() {
				return ( php_sapi_name() !== "cli" );
			},
			function() {
				return ! defined( "DOING_AJAX" );
			},
			function() {
				if ( defined( 'WP_USE_THEMES' ) ) {
					return WP_USE_THEMES !== false;
				} else {
					return false;
				}
			},
			function() {
				return ! ( strpos( $_SERVER['REQUEST_URI'], '/feed' ) !== false );
			},
			function() {
				return ! isset( $_GET['_wpcf7_is_ajax_call'] );
			},
			function() {
				return ! isset( $_POST['_wpcf7_is_ajax_call'] );
			},
			function() {
				return ! isset( $_POST['gform_ajax'] );
			},
			function() {
				return ! isset( $_POST['dustpress_comments_ajax'] );
			}
		];

		$conditions = apply_filters( "dustpress/want_autoload", $conditions );

		foreach( $conditions as $condition ) {
			if ( is_callable( $condition ) ) {
				if ( false === $condition() ) {
					return false;
				}
			}
			else {
				if ( ! is_null( $condition ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	*  This function determines if we are on a DustPress AJAX call or not.
	*
	*  @type	function
	*  @date	17/12/2015
	*  @since	0.3.0
	*
	*  @return	(boolean)
	*/
	private function is_dustpress_ajax() {
		if ( isset( $_POST["dustpress_data"] ) ) {
			return true;
		}
		else {
			return false;
		}
	}

	/**
	 */
	public function register_ajax_function( $key, $callable ) {
		$this->ajax_functions[ $key ] = $callable;
	}

	/**
	*  This function does lots of AJAX stuff with the parameters from the JS side.
	*
	*  @type	function
	*  @date	17/12/2015
	*  @since	0.3.0
	*
	*  @param   N/A
	*  @return	N/A
	*/
	public function create_ajax_instance() {
		global $post;

		if ( isset( $_POST["dustpress_data"] ) ) {
			$request_data = $_POST["dustpress_data"];
		}
		else {
			die( json_encode( [ "error" => "Something went wrong. There was no dustpress_data present at the request." ] ) );
		}

		if ( ! empty( $request_data['token'] ) && ! empty( $_COOKIE['dpjs_token'] ) ) {
			$token = ( $request_data['token'] === $_COOKIE['dpjs_token'] );
		}
		else {
			$token = false;
		}

		if ( ! $token ) {
			die( json_encode( [ "error" => "CSRF token mismatch." ] ) );
		}

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$runs = [];

		// Get the args
		if ( ! empty( $request_data["args"] ) ) {
			$args = $request_data["args"];
		}
		else {
			$args = [];
		}

		if ( ! preg_match( '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\/]*$/', $request_data["path"] ) ) {
			die( json_encode( [ "error" => "AJAX call path contains illegal characters." ] ) );
		}

		// Check if the data we got from the JS side has a function path
		if ( isset( $request_data["path"] ) ) {
			// If the path is set as a custom ajax function key, run the custom function
			if ( isset( $this->ajax_functions[ $request_data["path"] ] ) ) {
				$data = call_user_func_array( $this->ajax_functions[ $request_data["path"] ], [ $args ] );

				if ( isset( $request_data['partial'] ) ) {
					$partial = $request_data['partial'];
				}

				if ( empty( $partial ) ) {
					die( wp_json_encode( [ "success" => $data ] ) );
				}
				else {
					$html = $this->render( [ "partial" => $partial, "data" => $data, "echo" => false ] );

					if ( method_exists('\DustPress\Debugger', 'use_debugger') && \DustPress\Debugger::use_debugger() ) {
						$response = [ "success" => $html, "data" => $data ];
					}
					else {
						$response = [ "success" => $html ];
					}

					if ( method_exists('\DustPress\Debugger', 'use_debugger') && \DustPress\Debugger::use_debugger() ) {
						$response["debug"] = $data;
					}

					die( wp_json_encode( $response ) );
				}
			}
			else {
				$path = explode( "/", $request_data["path"] );

				if ( count( $path ) > 2 ) {
					die( json_encode( [ "error" => "AJAX call did not have a proper function path defined (syntax: model/function)." ] ) );
				}
				else if ( count( $path ) == 2 ) {
					if ( strlen( $path[0] ) == 0 || strlen( $path[1] ) == 0 ) {
						die( json_encode( [ "error" => "AJAX call did not have a proper function path defined (syntax: model/function)." ] ) );
					}

					$model = $path[0];

					$functions = explode( ",", $path[1] );

					foreach( $functions as $function ) {
						$runs[] = $function;
					}
				}
			}
		}

		// If there was not model defined in JS call, use the one we already are in.
		if ( ! $model ) {
			// Get current template name
			$model = $this->get_template_filename();

			$model = apply_filters( "dustpress/template", $model );
		}

		// If render is set true, set the model's default template to be used.
		if ( isset( $request_data["render"] ) && $request_data["render"] === "true" ) {
			$partial = strtolower( $this->camelcase_to_dashed( $model ) );
		}

		// Do we want tidy output or not?
		if ( isset( $request_data["tidy"] ) ) {
			if ( $request_data["tidy"] === "false" ) {
				$tidy = false;
			}
			else {
				$tidy = true;
			}
		}
		else {
			$tidy = false;
		}

		// Get the possible defined partial and possible override the default template.
		if ( isset( $request_data["partial"] ) && strlen( $request_data["partial"] ) > 0 ) {
			$partial = $request_data["partial"];
		}

		if ( class_exists( $model ) ) {
			$instance = new $model( $args );

			// Get the data
			$instance->fetch_data( $functions, $tidy );

			// If we don't want to render, json-encode and return just the data
			if ( empty( $partial ) ) {
				die( wp_json_encode( [ "success" => $instance->data ] ) );
			}
			else {
				$template_override = $instance->get_template();

				$partial = $template_override ? $template_override : strtolower( $this->camelcase_to_dashed( $partial ) );

				if ( $tidy && is_array( $functions ) && count( $functions ) === 1 ) {

					$data = $instance->data->{$functions[0]};

					$html = $this->render( [ "partial" => $partial, "data" => $data, "echo" => false ] );
				}
				else {
					$data = $instance->data;
					$html = $this->render( [ "partial" => $partial, "data" => $data, "echo" => false ] );
				}

				if ( method_exists('\DustPress\Debugger', 'use_debugger') && \DustPress\Debugger::use_debugger() ) {
					$response = [ "success" => $html, "data" => $data ];
				}
				else {
					$response = [ "success" => $html ];
				}

				if ( method_exists('\DustPress\Debugger', 'use_debugger') && \DustPress\Debugger::use_debugger() ) {
					$response["debug"] = $data;
				}

				die( wp_json_encode( $response ) );
			}
		}
		else {
			die( wp_json_encode( [ "error" => "Model '". $model ."' does not exist." ] ) );
		}
	}

	/**
	*  This function loops through the wanted partial and finds all helpers that are used.
	*  It is used recursively.
	*
	*  @type	function
	*  @date	17/12/2015
	*  @since	0.3.0
	*
	*  @param   $partial (string)
	*  @param   $already (array|string) (optional)
	*  @return	$helpers (array|string)
	*/
	public function prerender( $partial, $already = [] ) {
		$filename = $this->get_prerender_file( $partial );

		if ( $filename == false ) return;

		$file = file_get_contents( $filename );

		if ( in_array( $file, $already) ) {
			return;
		}

		$already[] = $file;

		$helpers = [];

		// Get helpers
		preg_match_all("/\{@(\w+)/", $file, $findings);

		$helpers = array_merge( $helpers, array_unique( $findings[1] ) );

		// Get includes
		preg_match_all("/\{>[\"']?([-a-zA-z0-9\/]+)?/", $file, $includes);

		foreach( $includes[1] as $include ) {
			$incl_explode = explode("/", $include);

			$include = array_pop( $incl_explode );

			$include_helpers = $this->prerender( $include, $already );

			if ( is_array( $include_helpers ) ) {
				$helpers = array_merge( $helpers, array_unique( $include_helpers ) );
			}
		}

		if ( is_array( $helpers ) ) {
			return array_unique( $helpers );
		}
		else {
			return [];
		}
	}

	/**
	*  This function is used to get a template file to prerender.
	*
	*  @type	function
	*  @date	17/12/2015
	*  @since	0.3.0
	*
	*  @param   $partial (string)
	*  @return	$file (string)
	*/
	public function get_prerender_file( $partial ) {
		$templatefile =  $partial . '.dust';

		$templatepaths = $this->get_template_paths("partials");

		foreach ( $templatepaths as $templatepath ) {
			if ( is_readable( $templatepath ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $templatepath ) ) as $file ) {
					if ( strpos( $file, $templatefile ) !== false ) {
						if ( is_readable( $file ) ) {
							return $file;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	*  This function executes dummy runs through all wanted helpers to enqueue scripts they need.
	*
	*  @type	function
	*  @date	17/12/2015
	*  @since	0.3.0
	*
	*  @param   $helpers (array|string)
	*  @return	N/A
	*/
	public function prerun_helpers( $helpers ) {
		if ( is_array( $helpers ) ) {
			$dummyEvaluator = new Dust\Evaluate\Evaluator( $this->dust );
			$dummyChunk = new Dust\Evaluate\Chunk( $dummyEvaluator );
			$dummyContext = new Dust\Evaluate\Context( $dummyEvaluator );
			$dummySection = new Dust\Ast\Section( null );
			$dummyBodies = new Dust\Evaluate\Bodies( $dummySection );
			$dummyParameters = new Dust\Evaluate\Parameters( $dummyEvaluator, $dummyContext );

			foreach( $this->dust->helpers as $name => $helper ) {
				if ( in_array( $name, $helpers) ) {
					if ( ( $helper instanceof \Closure ) || ( $helper instanceof \DustPress\Helper ) ) {
						$dummyBodies->dummy = true;
						call_user_func( $helper, $dummyChunk, $dummyContext, $dummyBodies, $dummyParameters );
					}
				}
			}
		}
	}

	/**
	*  This function disables DustPress from doing pretty much anything.
	*
	*  @type	function
	*  @date	02/06/2016
	*  @since	0.3.3
	*
	*  @param   $param (mixed)
	*  @return	$param
	*/
	public function disable( $param = null ) {
		$this->disabled = true;

		return $param;
	}

	/**
	*  This function adds a helper.
	*
	*  @type	function
	*  @date	08/06/2016
	*  @since	0.4.0
	*
	*  @param   $param (mixed)
	*  @return	$param
	*/
	public function add_helper( $name, $instance ) {
		$this->dust->helpers[ $name ] = $instance;
	}

	/**
	 *  This function adds autoloaders for the classes
	 *
	 *  @type 	function
	 *  @date 	08/06/2016
	 *  @since  0.04.0
	 *
	 *  @param  N/A
	 *  @return N/A
	 */
	private function register_autoloaders() {
		// Autoload DustPHP classes
		spl_autoload_register( function ( $class ) {

		    // project-specific namespace prefix
		    $prefix = 'Dust\\';

		    // base directory for the namespace prefix
		    $base_dir = dirname( __FILE__ ) . '/dust/';

		    // does the class use the namespace prefix?
		    $len = strlen( $prefix );
		    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		        // no, move to the next registered autoloader
		        return;
		    }

		    // get the relative class name
		    $relative_class = substr( $class, $len );

		    // replace the namespace prefix with the base directory, replace namespace
		    // separators with directory separators in the relative class name, append
		    // with .php
		    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		    // if the file exists, require it
		    if ( file_exists( $file ) ) {
		        require $file;
		    }
		});

		// Autoload DustPress classes
		spl_autoload_register( function( $class ) {
			$paths = $this->get_template_paths("models");

			$paths[] = dirname( __FILE__ );

			if ( $class == "DustPress\Query" ) {
				$class = "classes/query";
			}
			elseif ( $class == "DustPress\Model" ) {
				$class = "classes/model";
			}
			elseif ( $class == "DustPress\Helper" ) {
				$class = "classes/helper";
			}
			elseif ( $class == "DustPress\Data" ) {
				$class = "classes/data";
			}
			else {
				$class = $this->camelcase_to_dashed( $class, "-" );
			}

			$filename = strtolower( $class ) .".php";

			foreach ( $paths as $path ) {
				if ( is_readable( $path ) ) {
					foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) ) as $file ) {
						if ( strpos( $file, "/" . $filename ) ) {
							if ( is_readable( $file ) ) {
								require_once( $file );
								return;
							}
						}
					}
				}
				else {
					if ( dirname( __FILE__ ) ."/models" !== $path ) {
						die("DustPress error: Your theme does not have required directory ". $path);
					}
				}
			}
		});
	}

	/**
	 *  This function returns the paths from which to look for models or partials
	 *
	 *  @type	function
	 *  @date	17/06/2016
	 *  @since	0.4.0.4
	 *
	 *  @param  string $append [models|partials]
	 *  @return array  list of paths to look in
	 */
	private function get_template_paths( $append ) {
		$templatepaths = $this->paths;

		if ( isset( $append ) ) {
			array_walk( $templatepaths, function( &$path ) use ( $append ) {
				$path .= "/" . $append;
			});
		}

		$templatepaths[] = dirname( __FILE__ ) ."/". $append;

		$tag = $append ? "dustpress/" . $append : false;

		if ( $tag ) {
			$return = apply_filters( $tag, $templatepaths );
		}

		return $return;
	}
}

// Global function that returns the DustPress singleton
function dustpress() {
	return DustPress::instance();
}
