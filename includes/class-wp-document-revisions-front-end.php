<?php
/**
 * Helper class for WP_Document_Revisions that registers shortcodes, etc. for use on the front-end.
 *
 * @since 1.2
 * @package WP_Document_Revisions
 */

/**
 * WP Document Revisions Front End.
 */
class WP_Document_Revisions_Front_End {

	/**
	 * The Parent WP_Document_Revisions instance.
	 *
	 * @var $parent
	 */
	public static $parent;

	/**
	 * The Singleton instance.
	 *
	 * @var $instance
	 */
	public static $instance;

	/**
	 * Array of accepted shortcode keys and default values.
	 *
	 * @var $shortcode_defaults
	 */
	public $shortcode_defaults = array(
		'id'          => null,
		'numberposts' => null,
		'summary'     => false,
		'new_tab'     => true,
	);

	/**
	 *  Registers front end hooks.
	 *
	 * @param Object $instance The WP Document Revisions instance.
	 */
	public function __construct( &$instance = null ) {

		self::$instance = &$this;

		// create or store parent instance.
		if ( is_null( $instance ) ) {
			self::$parent = new WP_Document_Revisions();
		} else {
			self::$parent = &$instance;
		}

		add_shortcode( 'document_revisions', array( &$this, 'revisions_shortcode' ) );
		add_shortcode( 'documents', array( &$this, 'documents_shortcode' ) );
		add_filter( 'document_shortcode_atts', array( &$this, 'shortcode_atts_hyphen_filter' ) );

		// Add blocks. Done after wp_loaded so that the taxonomies have been defined.
		add_action( 'wp_loaded', array( &$this, 'documents_shortcode_blocks' ), 100 );

		// Queue up JS (low priority to be at end).
		add_action( 'wp_enqueue_scripts', array( &$this, 'enqueue_front' ), 50 );

	}


	/**
	 * Provides support to call functions of the parent class natively.
	 *
	 * @since 1.2
	 * @param function $function the function to call.
	 * @param array    $args the arguments to pass to the function.
	 * @returns mixed the result of the function
	 */
	public function __call( $function, $args ) {
		return call_user_func_array( array( &self::$parent, $function ), $args );
	}


	/**
	 * Provides support to call properties of the parent class natively.
	 *
	 * @since 1.2
	 * @param string $name the property to fetch.
	 * @returns mixed the property's value
	 */
	public function __get( $name ) {
		return WP_Document_Revisions::$$name;
	}


	/**
	 * Callback to display revisions.
	 *
	 * @param array $atts attributes passed via short code.
	 * @returns string a UL with the revisions
	 * @since 1.2
	 */
	public function revisions_shortcode( $atts ) {

		// change attribute number into numberposts (for backward compatibility).
		if ( array_key_exists( 'number', $atts ) && ! array_key_exists( 'numberposts', $atts ) ) {
			$atts['numberposts'] = $atts['number'];
			unset( $atts['number'] );
		}

		// normalize args.
		$atts = shortcode_atts( $this->shortcode_defaults, $atts, 'document' );
		foreach ( array_keys( $this->shortcode_defaults ) as $key ) {
			$$key = isset( $atts[ $key ] ) ? (int) $atts[ $key ] : null;
		}

		// do not show output to users that do not have the read_document_revisions capability.
		if ( ! current_user_can( 'read_document_revisions' ) ) {
			return '';
		}

		// get revisions.
		$revisions = $this->get_revisions( $id );

		// show a limited number of revisions.
		if ( null !== $numberposts ) {
			$revisions = array_slice( $revisions, 0, (int) $numberposts );
		}

		if ( isset( $atts['summary'] ) ) {
			$atts_summary = filter_var( $atts['summary'], FILTER_VALIDATE_BOOLEAN );
		} else {
			$atts_summary = false;
		}

		if ( isset( $atts['new_tab'] ) ) {
			$atts_new_tab = filter_var( $atts['new_tab'], FILTER_VALIDATE_BOOLEAN );
		} else {
			$atts_new_tab = false;
		}

		// buffer output to return rather than echo directly.
		ob_start();
		?>
		<ul class="revisions document-<?php echo esc_attr( $id ); ?>">
		<?php
		// loop through each revision.
		foreach ( $revisions as $revision ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<li class="revision revision-<?php echo esc_attr( $revision->ID ); ?>" >
				<?php
				// html - string not to be translated.
				printf( '<a href="%1$s" title="%2$s" id="%3$s" class="timestamp"', esc_url( get_permalink( $revision->ID ) ), esc_attr( $revision->post_modified ), esc_html( strtotime( $revision->post_modified ) ) );
				echo ( $atts_new_tab ? ' target="_blank"' : '' );
				printf( '>%s</a> <span class="agoby">', esc_html( human_time_diff( strtotime( $revision->post_modified_gmt ), time() ) ) );
				esc_html_e( 'ago by', 'wp-document-revisions' );
				printf( '</span> <span class="author">%s</span>', esc_html( get_the_author_meta( 'display_name', $revision->post_author ) ) );
				echo ( $atts_summary ? '<br/>' . esc_html( $revision->post_excerpt ) : '' );
				?>
			</li>
			<?php
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		</ul>
		<?php
		// grab buffer contents and clear.
		$output = ob_get_contents();
		ob_end_clean();
		return $output;
	}


	/**
	 * Shortcode to query for documents.
	 * Called from shortcode sirectly.
	 *
	 * @since 3.3
	 * @param array $atts shortcode attributes.
	 * @return string the shortcode output
	 */
	public function documents_shortcode( $atts ) {

		// Only need to do something if workflow_state points to post_status.
		if ( 'workflow_state' !== self::$parent->taxonomy_key() ) {
			if ( in_array( 'workflow_state', $atts, true ) ) {
				$atts['post_status'] = $atts['workflow_state'];
				unset( $atts['workflow_state'] );
			}
		}

		return self::documents_shortcode_int( $atts );
	}


	/**
	 * Shortcode to query for documents.
	 * Takes most standard WP_Query parameters (must be int or string, no arrays)
	 * See get_documents in wp-document-revisions.php for more information.
	 *
	 * This is the original documents_shortcode function but an added layer for sorting
	 * reuse of workflow_state when EditLlow or PublishPressi is used.
	 *
	 * @since 1.2
	 * @param array $atts shortcode attributes.
	 * @return string the shortcode output
	 */
	public function documents_shortcode_int( $atts ) {

		$defaults = array(
			'orderby' => 'modified',
			'order'   => 'DESC',
		);

		// list of all string or int based query vars (because we are going through shortcode)
		// via http://codex.wordpress.org/Class_Reference/WP_Query#Parameters.
		$keys = array(
			'author',
			'author_name',
			'author__in',
			'author__not_in',
			'cat',
			'category_name',
			'category__and',
			'category__in',
			'category__not_in',
			'tag',
			'tag_id',
			'tag__and',
			'tag__in',
			'tag__not_in',
			'tag_slug__and',
			'tag_slug__in',
			'tax_query',
			's',
			'p',
			'name',
			'title',
			'page_id',
			'pagename',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'post__in',
			'post__not_in',
			'post_name__in',
			'has_password',
			'post_password',
			'post_status',
			'numberposts',
			'year',
			'monthnum',
			'w',
			'day',
			'hour',
			'minute',
			'second',
			'm',
			'date_query',
			'meta_key',
			'meta_value',
			'meta_value_num',
			'meta_compare',
			'meta_query',
			// Presentation attributes (will be dealt with before getting documents).
			'show_edit',
			'new_tab',
		);

		foreach ( $keys as $key ) {
			$defaults[ $key ] = null;
		}

		// allow querying by custom taxonomy.
		$taxs = $this->get_taxonomy_details();
		foreach ( $taxs['taxos'] as $tax ) {
			$defaults[ $tax['query'] ] = null;
		}

		// show_edit and new_tab may be entered without name (implies value true)
		// convert to name value pair.
		if ( isset( $atts[0] ) ) {
			$atts[ $atts[0] ] = true;
			unset( $atts[0] );
		}
		if ( isset( $atts[1] ) ) {
			$atts[ $atts[1] ] = true;
			unset( $atts[1] );
		}

		// Presentation attributes may be set as false, so process before array_filter and remove.
		if ( isset( $atts['show_edit'] ) ) {
			$atts_show_edit = filter_var( $atts['show_edit'], FILTER_VALIDATE_BOOLEAN );
			unset( $atts['show_edit'] );
		} else {
			// Want to know if there was a shortcode as it will override.
			$atts_show_edit = null;
		}

		if ( isset( $atts['new_tab'] ) ) {
			$atts_new_tab = filter_var( $atts['new_tab'], FILTER_VALIDATE_BOOLEAN );
			unset( $atts['new_tab'] );
		} else {
			$atts_new_tab = false;
		}

		/**
		 * Filters the Document shortcode attributes.
		 *
		 * @param array $atts attributes set on the shortcode.
		 */
		$atts = apply_filters( 'document_shortcode_atts', $atts );

		// default arguments, can be overriden by shortcode attributes.
		// note that the filter shortcode_atts_document is also available to filter the attributes.
		$atts = shortcode_atts( $defaults, $atts, 'document' );

		$atts = array_filter( $atts );

		$documents = $this->get_documents( $atts );

		// Determine whether to output edit option - shortcode value will override.
		if ( is_null( $atts_show_edit ) ) {
			// check whether to show update option. Default - only administrator role.
			$show_edit = false;
			$user      = wp_get_current_user();
			if ( $user->ID > 0 ) {
				// logged on user only.
				$roles = (array) $user->roles;
				if ( in_array( 'administrator', $roles, true ) ) {
					$show_edit = true;
				}
			}
			/**
			 * Filters the controlling option to display an edit option against each document.
			 *
			 * By default, only logged-in administrators be able to have an edit option.
			 * The user will also need to be able to edit the individual document before it is displayed.
			 *
			 * @since 3.2.0
			 *
			 * @param boolean $show_edit default value.
			 */
			$show_edit = apply_filters( 'document_shortcode_show_edit', $show_edit );
		} else {
			$show_edit = $atts_show_edit;
		}

		// buffer output to return rather than echo directly.
		ob_start();
		?>
		<ul class="documents">
		<?php
		// loop through found documents.
		foreach ( $documents as $document ) {
			?>
			<li class="document document-<?php echo esc_attr( $document->ID ); ?>">
			<a href="<?php echo esc_url( get_permalink( $document->ID ) ); ?>"
				<?php echo ( $atts_new_tab ? ' target="_blank"' : '' ); ?>>
				<?php echo esc_html( get_the_title( $document->ID ) ); ?>
			</a>
			<?php
			if ( $show_edit && current_user_can( 'edit_document', $document->ID ) ) {
				$link = add_query_arg(
					array(
						'post'   => $document->ID,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				);
				echo '&nbsp;&nbsp;<a class="document-mod" href="' . esc_attr( $link ) . '">[' . esc_html__( 'Edit', 'wp-document-revisions' ) . ']</a>';
			}
			?>
			</li>
		<?php } ?>
		</ul>
		<?php
		// grab buffer contents and clear.
		$output = ob_get_contents();
		ob_end_clean();
		return $output;

	}

	/**
	 * Shortcode can have CSS on any page.
	 *
	 * @since 3.2.0
	 */
	public function enqueue_front() {

		$wpdr = self::$parent;

		// enqueue CSS for shortcode.
		wp_enqueue_style( 'wp-document-revisions-front', plugins_url( '/css/style-front.css', __DIR__ ), null, $wpdr->version );

	}


	/**
	 * Provides workaround for taxonomies with hyphens in their name
	 * User should replace hyphen with underscope and plugin will compensate.
	 *
	 * @param Array $atts shortcode attributes.
	 * @return Array modified shortcode attributes
	 */
	public function shortcode_atts_hyphen_filter( $atts ) {

		foreach ( (array) $atts as $k => $v ) {

			if ( strpos( $k, '_' ) === false ) {
				continue;
			}

			$alt = str_replace( '_', '-', $k );

			if ( ! taxonomy_exists( $alt ) ) {
				continue;
			}

			$atts[ $alt ] = $v;
			unset( $atts[ $k ] );
		}

		return $atts;
	}

	/**
	 * Register WP Document Revisions block category.
	 *
	 * @since 3.3.0
	 * @param Array   $categories Block categories available.
	 * @param WP_Post $post       Post for which the block is to be available.
	 */
	public function wpdr_block_categories( $categories, $post ) {

		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'wpdr-category',
					'title' => __( 'WP Document Revisions', 'wp-document-revisions' ),
				),
			)
		);
	}


	/**
	 * Register revisions-shortcode block
	 *
	 * @since 3.3.0
	 */
	public function documents_shortcode_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			// Gutenberg is not active, e.g. Old WP version installed.
			return;
		}

		// add the plugin category.
		add_filter( 'block_categories', array( $this, 'wpdr_block_categories' ), 10, 2 );

		register_block_type(
			'wp-document-revisions/documents-shortcode',
			array(
				'editor_script'   => 'wpdr-documents-shortcode-editor',
				'render_callback' => array( $this, 'wpdr_documents_shortcode_display' ),
				'attributes'      => array(
					'taxonomy_0'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'term_0'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'taxonomy_1'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'term_1'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'taxonomy_2'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'term_2'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'numberposts' => array(
						'type'    => 'number',
						'default' => 5,
					),
					'orderby'     => array(
						'type' => 'string',
					),
					'order'       => array(
						'type' => 'string',
					),
					'show_edit'   => array(
						'type' => 'string',
					),
					'new_tab'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'freeform'    => array(
						'type' => 'string',
					),
				),
			)
		);

		register_block_type(
			'wp-document-revisions/revisions-shortcode',
			array(
				'editor_script'   => 'wpdr-revisions-shortcode-editor',
				'render_callback' => array( $this, 'wpdr_revisions_shortcode_display' ),
				'attributes'      => array(
					'id'          => array(
						'type'    => 'number',
						'default' => 1,
					),
					'numberposts' => array(
						'type'    => 'number',
						'default' => 5,
					),
					'summary'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'new_tab'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// register scripts.
		$dir      = dirname( __DIR__ );
		$suffix   = ( WP_DEBUG ) ? '.dev' : '';
		$index_js = 'js/wpdr-documents-shortcode' . $suffix . '.js';
		wp_register_script(
			'wpdr-documents-shortcode-editor',
			plugins_url( $index_js, __DIR__ ),
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
				'wp-server-side-render',
				'wp-i18n',
			),
			filemtime( "$dir/$index_js" ),
			true
		);

		// Add supplementary script for additional information.
		// document CPT has no default taxonomies, need to look up in wp_taxonomies.
		// Ensure taxonomies are set.
		$taxonomies = $this->get_taxonomy_details();
		wp_localize_script( 'wpdr-documents-shortcode-editor', 'wpdr_data', $taxonomies );

		$index_js = 'js/wpdr-revisions-shortcode' . $suffix . '.js';
		wp_register_script(
			'wpdr-revisions-shortcode-editor',
			plugins_url( $index_js, __DIR__ ),
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
				'wp-server-side-render',
				'wp-i18n',
			),
			filemtime( "$dir/$index_js" ),
			true
		);

		// set translations.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wpdr-documents-shortcode-editor', 'wp-document-revisions' );
			wp_set_script_translations( 'wpdr-revisions-shortcode-editor', 'wp-document-revisions' );
		}
	}

	/**
	 * Flattened taxonomy term list.
	 *
	 * @var array $tax_terms array of terms.
	 */
	private static $tax_terms = array();


	/**
	 * Get taxonomy structure.
	 *
	 * @param String  $taxonomy Taxonomy name.
	 * @param Integer $parent   parent term.
	 * @param Integer $level    level in hierarchy.
	 * @since 3.3.0
	 */
	private function get_taxonomy_hierarchy( $taxonomy, $parent = 0, $level = 0 ) {
		// get all direct descendants of the $parent.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'parent'     => $parent,
			)
		);
		// go through all the direct descendants of $parent, and recurse their children.
		// this creates a treewalk in simple array format.
		foreach ( $terms as $term ) {
			// Mis-use term_group to hold level.
			$term->term_group  = $level;
			self::$tax_terms[] = $term;
			// recurse to get the direct descendants of "this" term.
			$this->get_taxonomy_hierarchy( $taxonomy, $term->term_id, $level + 1 );
		}
	}

	/**
	 * Get taxonomy names for documents (use cache).
	 *
	 * @return Array Taxonomy names for documents
	 * @since 3.3.0
	 */
	public function get_taxonomy_details() {
		$taxonomy_details = wp_cache_get( 'wpdr_document_taxonomies' );

		if ( false === $taxonomy_details ) {
			// build and create cache entry. Get name only to allow easier filtering.
			$taxos = get_object_taxonomies( 'document' );
			// Make sure 'workflow_state' is in the list. With EF/PP it uses the post_status taxonomy.
			if ( ! in_array( 'workflow_state', (array) $taxos, true ) ) {
				$taxos[] = 'workflow_state';
			}

			sort( $taxos );

			/**
			 * Filters the Document taxonomies (allowing users to select the first three for the block widget.
			 *
			 * @param array $taxonomies taxonomies available for selection in the list block.
			 */
			$taxos = apply_filters( 'document_block_taxonomies', $taxos );

			$taxonomy_elements = array();
			// Has workflow_state been mangled? Note. set here as it could be filtered out.
			$wf_efpp = 0;
			foreach ( $taxos as $taxonomy ) {
				// Find the terms.
				$terms    = array();
				$terms[0] = array(
					0,  // value.
					__( 'No selection', 'wp-document-revisions' ),  // label.
					'',  // underscore-separated slug.
				);
				// Look up taxonomy.
				if ( 'workflow_state' === $taxonomy && 'workflow_state' !== self::$parent->taxonomy_key() ) {
					// EF/PP - Mis-use of 'post_status' taxonomy.
					$tax        = get_taxonomy( self::$parent->taxonomy_key() );
					$tax->label = 'Post Status';
					$wf_efpp    = 1;
				} else {
					$tax = get_taxonomy( $taxonomy );
				}

				// Hierarchical or flat taxonomy ?
				if ( $tax->hierarchical ) {
					self::$tax_terms = array();
					// Get hierarchical list.
					$this->get_taxonomy_hierarchy( $taxonomy );
				} else {
					self::$tax_terms = get_terms(
						array(
							'taxonomy'     => $tax->name,
							'hide_empty'   => false,
							'hierarchical' => false,
						)
					);
				}
				foreach ( self::$tax_terms as $terms_obj ) {
					$indent  = ( $tax->hierarchical ? str_repeat( ' ', $terms_obj->term_group ) : '' );
					$terms[] = array(
						$terms_obj->term_id,
						$indent . $terms_obj->name,
						str_replace( '-', '_', $terms_obj->slug ), // Used for block<-> shortcode conversion.
					);
				}

				// Will use Query_var not (necessarily) the slug.
				$taxonomy_elements[] = array(
					'slug'  => $tax->name,
					'query' => ( empty( $tax->query_var ) ? $tax->name : $tax->query_var ),
					'label' => $tax->label,
					'terms' => $terms,
				);
			}
			$taxonomy_details = array(
				'stmax'   => count( $taxonomy_elements ),
				'wf_efpp' => $wf_efpp,
				'taxos'   => $taxonomy_elements,
			);

			wp_cache_set( 'wpdr_document_taxonomies', $taxonomy_details, '', ( WP_DEBUG ? 10 : 120 ) );
		}

		return $taxonomy_details;
	}

	/**
	 * Server side block to render the documents list.
	 *
	 * @param array $atts shortcode attributes.
	 * @returns string a UL with the revisions
	 * @since 3.3.0
	 */
	public function wpdr_documents_shortcode_display( $atts ) {
		// get instance of global class.
		global $wpdr, $wpdr_fe;

		// sanity check.
		// do not show output to users that do not have the read_document capability.
		if ( ! current_user_can( 'read_documents' ) ) {
			return esc_html__( 'You are not authorized to read this data', 'wp-document-revisions' );
		}

		$atts = shortcode_atts(
			array(
				'taxonomy_0'  => '',
				'term_0'      => 0,
				'taxonomy_1'  => '',
				'term_1'      => 0,
				'taxonomy_2'  => '',
				'term_2'      => 0,
				'numberposts' => 5,
				'orderby'     => '',
				'order'       => 'ASC',
				'show_edit'   => '',
				'new_tab'     => true,
				'freeform'    => '',
			),
			$atts,
			'document'
		);

		// Remove attribute if not an over-ride.
		if ( 0 === strlen( $atts['show_edit'] ) ) {
			unset( $atts['show_edit'] );
		}

		// Remove new_tab if false.
		if ( empty( $atts['new_tab'] ) ) {
			unset( $atts['new_tab'] );
		}

		// Deal with explicit taxonomomies.
		if ( empty( $atts['taxonomy_0'] ) || empty( $atts['term_0'] ) ) {
			null;
		} else {
			// create atts in the appropriate form tax->query_var = term slug.
			$term                        = get_term( $atts['term_0'] );
			$atts[ $atts['taxonomy_0'] ] = $term->slug;
		}
		unset( $atts['taxonomy_0'] );
		unset( $atts['term_0'] );

		if ( empty( $atts['taxonomy_1'] ) || empty( $atts['term_1'] ) ) {
			null;
		} else {
			// create atts in the appropriate form tax->query_var = term slug.
			$term                        = get_term( $atts['term_1'] );
			$atts[ $atts['taxonomy_1'] ] = $term->slug;
		}
		unset( $atts['taxonomy_1'] );
		unset( $atts['term_1'] );

		if ( empty( $atts['taxonomy_2'] ) || empty( $atts['term_2'] ) ) {
			null;
		} else {
			// create atts in the appropriate form tax->query_var = term slug).
			$term                        = get_term( $atts['term_2'] );
			$atts[ $atts['taxonomy_2'] ] = $term->slug;
		}
		unset( $atts['taxonomy_2'] );
		unset( $atts['term_2'] );

		// deal with freeform attributes.
		if ( ! empty( $atts['freeform'] ) ) {
			$freeform = shortcode_parse_atts( $atts['freeform'] );
			$atts     = array_merge( $freeform, $atts );
		}
		unset( $atts['freeform'] );

		// if empty orderby attribute, then order is not relevant.
		if ( empty( $atts['orderby'] ) ) {
			unset( $atts['orderby'] );
			unset( $atts['order'] );
		}

		$output = $wpdr_fe->documents_shortcode_int( $atts );
		return $output;
	}


	/**
	 * Server side block to render the revisions list.
	 *
	 * @param array $atts shortcode attributes.
	 * @returns string a UL with the revisions
	 * @since 3.3.0
	 */
	public function wpdr_revisions_shortcode_display( $atts ) {
		// get instance of global class.
		global $wpdr, $wpdr_fe;

		$atts = shortcode_atts(
			array(
				'id'          => 0,
				'numberposts' => 5,
				'summary'     => false,
				'new_tab'     => true,
			),
			$atts,
			'document'
		);

		// sanity check.
		// do not show output to users that do not have the read_document_revisions capability.
		if ( ! current_user_can( 'read_document_revisions' ) ) {
			return esc_html__( 'You are not authorized to read this data', 'wp-document-revisions' );
		}

		// Check it is a document.
		if ( ! $wpdr->verify_post_type( $atts['id'] ) ) {
			return esc_html__( 'This is not a valid document.', 'wp-document-revisions' );
		}

		$output  = '<p class="document-title document-' . esc_attr( $atts['id'] ) . '">' . get_the_title( $atts['id'] ) . '</p>';
		$output .= $wpdr_fe->revisions_shortcode( $atts );
		return $output;
	}
}

