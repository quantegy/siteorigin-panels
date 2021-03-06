<?php

/**
 * Class SiteOrigin_Panels_Admin
 *
 * Handles all the admin and database interactions.
 */
class SiteOrigin_Panels_Admin {

	const LAYOUT_URL = 'http://layouts.siteorigin.com/';

	function __construct() {

		add_action( 'plugin_action_links_siteorigin-panels/siteorigin-panels.php', array(
			$this,
			'plugin_action_links'
		) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_init', array( $this, 'save_home_page' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		add_action( 'after_switch_theme', array( $this, 'update_home_on_theme_change' ) );

		// Enqueuing admin scripts
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_print_scripts-post.php', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_print_scripts-appearance_page_so_panels_home_page', array(
			$this,
			'enqueue_admin_scripts'
		) );
		add_action( 'admin_print_scripts-widgets.php', array( $this, 'enqueue_admin_scripts' ) );

		// Enqueue the admin styles
		add_action( 'admin_print_styles-post-new.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-post.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-appearance_page_so_panels_home_page', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-widgets.php', array( $this, 'enqueue_admin_styles' ) );

		// The help tab
		add_action( 'load-page.php', array( $this, 'add_help_tab' ), 12 );
		add_action( 'load-post-new.php', array( $this, 'add_help_tab' ), 12 );
		add_action( 'load-appearance_page_so_panels_home_page', array( $this, 'add_help_tab' ), 12 );

		add_action( 'customize_controls_print_footer_scripts', array( $this, 'js_templates' ) );
		add_filter( 'get_post_metadata', array( $this, 'view_post_preview' ), 10, 3 );

		// Register all the admin actions
		add_action( 'wp_ajax_so_panels_builder_content', array( $this, 'action_builder_content' ) );
		add_action( 'wp_ajax_so_panels_widget_form', array( $this, 'action_widget_form' ) );
		add_action( 'wp_ajax_so_panels_layouts_query', array( $this, 'action_get_prebuilt_layouts' ) );
		add_action( 'wp_ajax_so_panels_get_layout', array( $this, 'action_get_prebuilt_layout' ) );
		add_action( 'wp_ajax_so_panels_import_layout', array( $this, 'action_import_layout' ) );
		add_action( 'wp_ajax_so_panels_export_layout', array( $this, 'action_export_layout' ) );
		add_action( 'wp_ajax_so_panels_live_editor_preview', array( $this, 'action_live_editor_preview' ) );

		// Initialize the additional admin classes.
		SiteOrigin_Panels_Admin_Widget_Dialog::single();
		SiteOrigin_Panels_Admin_Widgets_Bundle::single();
	}

	/**
	 * @return SiteOrigin_Panels_Admin
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Check if this is an admin page.
	 *
	 * @return mixed|void
	 */
	static function is_admin() {
		$screen         = get_current_screen();
		$is_panels_page = ( $screen->base == 'post' && in_array( $screen->id, siteorigin_panels_setting( 'post-types' ) ) ) || $screen->base == 'appearance_page_so_panels_home_page' || $screen->base == 'widgets' || $screen->base == 'customize';

		return apply_filters( 'siteorigin_panels_is_admin_page', $is_panels_page );
	}

	/**
	 * Add action links to the plugin list for Page Builder.
	 *
	 * @param $links
	 *
	 * @return array
	 */
	function plugin_action_links( $links ) {
		unset( $links['edit'] );
		$links[] = '<a href="http://siteorigin.com/threads/plugin-page-builder/">' . __( 'Support Forum', 'siteorigin-panels' ) . '</a>';
		$links[] = '<a href="http://siteorigin.com/page-builder/#newsletter">' . __( 'Newsletter', 'siteorigin-panels' ) . '</a>';

		return $links;
	}

	/**
	 * Callback to register the Page Builder Metaboxes
	 */
	function add_meta_boxes() {
		foreach ( siteorigin_panels_setting( 'post-types' ) as $type ) {
			add_meta_box(
				'so-panels-panels',
				__( 'Page Builder', 'siteorigin-panels' ),
				array( $this, 'render_meta_boxes' ),
				$type,
				'advanced',
				'high'
			);
		}
	}

	/**
	 * Render a panel metabox.
	 *
	 * @param $post
	 */
	function render_meta_boxes( $post ) {
		$panels_data = $this->get_current_admin_panels_data();
		include plugin_dir_path( __FILE__ ) . '../tpl/metabox-panels.php';
	}

	/**
	 * Save the panels data
	 *
	 * @param $post_id
	 * @param $post
	 *
	 * @action save_post
	 */
	function save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( empty( $_POST['_sopanels_nonce'] ) || ! wp_verify_nonce( $_POST['_sopanels_nonce'], 'save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['panels_data'] ) ) {
			return;
		}

		if ( ! wp_is_post_revision( $post_id ) ) {
			$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
			$panels_data['widgets'] = $this->process_raw_widgets( $panels_data['widgets'] );
			$panels_data            = SiteOrigin_Panels_Styles::single()->sanitize_all( $panels_data );
			$panels_data            = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $post, $post_id );

			if ( ! empty( $panels_data['widgets'] ) || ! empty( $panels_data['grids'] ) ) {
				update_post_meta( $post_id, 'panels_data', $panels_data );
			} else {
				// There are no widgets or rows, so delete the panels data
				delete_post_meta( $post_id, 'panels_data' );
			}
		} else {
			// When previewing, we don't need to wp_unslash the panels_data post variable.
			$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
			$panels_data['widgets'] = $this->process_raw_widgets( $panels_data['widgets'] );
			$panels_data            = SiteOrigin_Panels_Styles::single()->sanitize_all( $panels_data );
			$panels_data            = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $post, $post_id );

			// Because of issue #20299, we are going to save the preview into a different variable so we don't overwrite the actual data.
			// https://core.trac.wordpress.org/ticket/20299
			if ( ! empty( $panels_data['widgets'] ) ) {
				update_post_meta( $post_id, '_panels_data_preview', $panels_data );
			} else {
				delete_post_meta( $post_id, '_panels_data_preview' );
			}
		}
	}

	/**
	 * Enqueue the panels admin scripts
	 *
	 * @param string $prefix
	 * @param bool $force Should we force the enqueues
	 *
	 * @action admin_print_scripts-post-new.php
	 * @action admin_print_scripts-post.php
	 * @action admin_print_scripts-appearance_page_so_panels_home_page
	 */
	function enqueue_admin_scripts( $prefix = '', $force = false ) {
		$screen = get_current_screen();
		if ( $force || self::is_admin() ) {
			// Media is required for row styles
			wp_enqueue_media();
			wp_enqueue_script( 'so-panels-admin', plugin_dir_url( __FILE__ ) . '../js/siteorigin-panels' . SITEORIGIN_PANELS_VERSION_SUFFIX . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array(
				'jquery',
				'jquery-ui-resizable',
				'jquery-ui-sortable',
				'jquery-ui-draggable',
				'underscore',
				'backbone',
				'plupload',
				'plupload-all'
			), SITEORIGIN_PANELS_VERSION, true );
			add_action( 'admin_footer', array( $this, 'js_templates' ) );

			$widgets = $this->get_widgets();

			$directory_enabled = get_user_meta( get_current_user_id(), 'so_panels_directory_enabled', true );

			wp_localize_script( 'so-panels-admin', 'panelsOptions', array(
				'ajaxurl'                   => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'panels_action', '_panelsnonce' ),
				'widgets'                   => $widgets,
				'widget_dialog_tabs'        => apply_filters( 'siteorigin_panels_widget_dialog_tabs', array(
					0 => array(
						'title'  => __( 'All Widgets', 'siteorigin-panels' ),
						'filter' => array(
							'installed' => true,
							'groups'    => ''
						)
					)
				) ),
				'row_layouts'               => apply_filters( 'siteorigin_panels_row_layouts', array() ),
				'directory_enabled'         => ! empty( $directory_enabled ),
				'copy_content'              => siteorigin_panels_setting( 'copy-content' ),

				// Settings for the contextual menu
				'contextual'                => array(
					// Developers can change which widgets are displayed by default using this filter
					'default_widgets' => apply_filters( 'siteorigin_panels_contextual_default_widgets', array(
						'SiteOrigin_Widget_Editor_Widget',
						'SiteOrigin_Widget_Button_Widget',
						'SiteOrigin_Widget_Image_Widget',
						'SiteOrigin_Panels_Widgets_Layout',
					) )
				),

				// General localization messages
				'loc'                       => array(
					'missing_widget'       => array(
						'title'       => __( 'Missing Widget', 'siteorigin-panels' ),
						'description' => __( "Page Builder doesn't know about this widget.", 'siteorigin-panels' ),
					),
					'time'                 => array(
						// TRANSLATORS: Number of seconds since
						'seconds' => __( '%d seconds', 'siteorigin-panels' ),
						// TRANSLATORS: Number of minutes since
						'minutes' => __( '%d minutes', 'siteorigin-panels' ),
						// TRANSLATORS: Number of hours since
						'hours'   => __( '%d hours', 'siteorigin-panels' ),

						// TRANSLATORS: A single second since
						'second'  => __( '%d second', 'siteorigin-panels' ),
						// TRANSLATORS: A single minute since
						'minute'  => __( '%d minute', 'siteorigin-panels' ),
						// TRANSLATORS: A single hour since
						'hour'    => __( '%d hour', 'siteorigin-panels' ),

						// TRANSLATORS: Time ago - eg. "1 minute before".
						'ago'     => __( '%s before', 'siteorigin-panels' ),
						'now'     => __( 'Now', 'siteorigin-panels' ),
					),
					'history'              => array(
						// History messages
						'current'           => __( 'Current', 'siteorigin-panels' ),
						'revert'            => __( 'Original', 'siteorigin-panels' ),
						'restore'           => __( 'Version restored', 'siteorigin-panels' ),
						'back_to_editor'    => __( 'Converted to editor', 'siteorigin-panels' ),

						// Widgets
						// TRANSLATORS: Message displayed in the history when a widget is deleted
						'widget_deleted'    => __( 'Widget deleted', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is added
						'widget_added'      => __( 'Widget added', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is edited
						'widget_edited'     => __( 'Widget edited', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is duplicated
						'widget_duplicated' => __( 'Widget duplicated', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget position is changed
						'widget_moved'      => __( 'Widget moved', 'siteorigin-panels' ),

						// Rows
						// TRANSLATORS: Message displayed in the history when a row is deleted
						'row_deleted'       => __( 'Row deleted', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is added
						'row_added'         => __( 'Row added', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is edited
						'row_edited'        => __( 'Row edited', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row position is changed
						'row_moved'         => __( 'Row moved', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is duplicated
						'row_duplicated'    => __( 'Row duplicated', 'siteorigin-panels' ),

						// Cells
						'cell_resized'      => __( 'Cell resized', 'siteorigin-panels' ),

						// Prebuilt
						'prebuilt_loaded'   => __( 'Prebuilt layout loaded', 'siteorigin-panels' ),
					),

					// general localization
					'prebuilt_loading'     => __( 'Loading prebuilt layout', 'siteorigin-panels' ),
					'confirm_use_builder'  => __( "Would you like to copy this editor's existing content to Page Builder?", 'siteorigin-panels' ),
					'confirm_stop_builder' => __( "Would you like to clear your Page Builder content and revert to using the standard visual editor?", 'siteorigin-panels' ),
					// TRANSLATORS: This is the title for a widget called "Layout Builder"
					'layout_widget'        => __( 'Layout Builder Widget', 'siteorigin-panels' ),
					// TRANSLATORS: A standard confirmation message
					'dropdown_confirm'     => __( 'Are you sure?', 'siteorigin-panels' ),
					// TRANSLATORS: When a layout file is ready to be inserted. %s is the filename.
					'ready_to_insert'      => __( '%s is ready to insert.', 'siteorigin-panels' ),

					// Everything for the contextual menu
					'contextual'           => array(
						'add_widget_below' => __( 'Add Widget Below', 'siteorigin-panels' ),
						'add_widget_cell'  => __( 'Add Widget to Cell', 'siteorigin-panels' ),
						'search_widgets'   => __( 'Search Widgets', 'siteorigin-panels' ),

						'add_row' => __( 'Add Row', 'siteorigin-panels' ),
						'column'  => __( 'Column', 'siteorigin-panels' ),

						'widget_actions'   => __( 'Widget Actions', 'siteorigin-panels' ),
						'widget_edit'      => __( 'Edit Widget', 'siteorigin-panels' ),
						'widget_duplicate' => __( 'Duplicate Widget', 'siteorigin-panels' ),
						'widget_delete'    => __( 'Delete Widget', 'siteorigin-panels' ),

						'row_actions'   => __( 'Row Actions', 'siteorigin-panels' ),
						'row_edit'      => __( 'Edit Row', 'siteorigin-panels' ),
						'row_duplicate' => __( 'Duplicate Row', 'siteorigin-panels' ),
						'row_delete'    => __( 'Delete Row', 'siteorigin-panels' ),
					),
					'draft'                => __( 'Draft', 'siteorigin-panels' ),
				),
				'plupload'                  => array(
					'max_file_size'       => wp_max_upload_size() . 'b',
					'url'                 => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'panels_action', '_panelsnonce' ),
					'flash_swf_url'       => includes_url( 'js/plupload/plupload.flash.swf' ),
					'silverlight_xap_url' => includes_url( 'js/plupload/plupload.silverlight.xap' ),
					'filter_title'        => __( 'Page Builder layouts', 'siteorigin-panels' ),
					'error_message'       => __( 'Error uploading or importing file.', 'siteorigin-panels' ),
				),
				'wpColorPickerOptions'      => apply_filters( 'siteorigin_panels_wpcolorpicker_options', array() ),
				'prebuiltDefaultScreenshot' => plugin_dir_url( __FILE__ ) . 'css/images/prebuilt-default.png',
			) );

			if ( $screen->base != 'widgets' ) {
				// Render all the widget forms. A lot of widgets use this as a chance to enqueue their scripts
				$original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null; // Make sure widgets don't change the global post.
				foreach ( $GLOBALS['wp_widget_factory']->widgets as $class => $widget_obj ) {
					ob_start();
					$return = $widget_obj->form( array() );
					do_action_ref_array( 'in_widget_form', array( &$widget_obj, &$return, array() ) );
					ob_clean();
				}
				$GLOBALS['post'] = $original_post;
			}

			// This gives panels a chance to enqueue scripts too, without having to check the screen ID.
			if ( $screen->base != 'widgets' && $screen->base != 'customize' ) {
				do_action( 'siteorigin_panel_enqueue_admin_scripts' );
				do_action( 'sidebar_admin_setup' );
			}
		}
	}

	/**
	 * Enqueue the admin panel styles
	 *
	 * @param string $prefix
	 * @param bool $force Should we force the enqueue
	 *
	 * @action admin_print_styles-post-new.php
	 * @action admin_print_styles-post.php
	 */
	function enqueue_admin_styles( $prefix = '', $force = false ) {
		if ( $force || self::is_admin() ) {
			wp_enqueue_style( 'so-panels-admin', plugin_dir_url( __FILE__ ) . '../css/admin.css', array( 'wp-color-picker' ), SITEORIGIN_PANELS_VERSION );
			do_action( 'siteorigin_panel_enqueue_admin_styles' );
		}
	}

	/**
	 * Add a help tab to pages that include a Page Builder interface.
	 *
	 * @param $prefix
	 */
	function add_help_tab( $prefix ) {
		$screen = get_current_screen();
		if (
			( $screen->base == 'post' && ( in_array( $screen->id, siteorigin_panels_setting( 'post-types' ) ) || $screen->id == '' ) )
			|| ( $screen->id == 'appearance_page_so_panels_home_page' )
		) {
			$screen->add_help_tab( array(
				'id'       => 'panels-help-tab', //unique id for the tab
				'title'    => __( 'Page Builder', 'siteorigin-panels' ), //unique visible title for the tab
				'callback' => array( $this, 'help_tab_content' )
			) );
		}
	}

	/**
	 * Display the content for the help tab.
	 */
	function help_tab_content() {
		include plugin_dir_path( __FILE__ ) . '../tpl/help.php';
	}

	/**
	 * Get the Page Builder data for the current admin page.
	 *
	 * @return array
	 */
	function get_current_admin_panels_data() {
		$screen = get_current_screen();

		// Localize the panels with the panels data
		if ( $screen->base == 'appearance_page_so_panels_home_page' ) {
			$home_page_id = get_option( 'page_on_front' );
			if ( empty( $home_page_id ) ) {
				$home_page_id = get_option( 'siteorigin_panels_home_page_id' );
			}

			$panels_data = ! empty( $home_page_id ) ? get_post_meta( $home_page_id, 'panels_data', true ) : null;

			if ( is_null( $panels_data ) ) {
				// Load the default layout
				$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

				$home_name   = siteorigin_panels_setting( 'home-page-default' ) ? siteorigin_panels_setting( 'home-page-default' ) : 'home';
				$panels_data = ! empty( $layouts[ $home_name ] ) ? $layouts[ $home_name ] : current( $layouts );
			} elseif ( empty( $panels_data ) ) {
				// The current page_on_front isn't using page builder
				return false;
			}

			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, 'home' );
		} else {
			global $post;
			$panels_data = get_post_meta( $post->ID, 'panels_data', true );
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post->ID );
		}

		if ( empty( $panels_data ) ) {
			$panels_data = array();
		}

		return $panels_data;
	}

	/**
	 * Save home page
	 */
	function save_home_page() {
		if ( ! isset( $_POST['_sopanels_home_nonce'] ) || ! wp_verify_nonce( $_POST['_sopanels_home_nonce'], 'save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['panels_data'] ) ) {
			return;
		}

		// Check that the home page ID is set and the home page exists
		$page_id = get_option( 'page_on_front' );
		if ( empty( $page_id ) ) {
			$page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		$post_content = wp_unslash( $_POST['post_content'] );

		if ( ! $page_id || get_post_meta( $page_id, 'panels_data', true ) == '' ) {
			// Lets create a new page
			$page_id = wp_insert_post( array(
				// TRANSLATORS: This is the default name given to a user's home page
				'post_title'     => __( 'Home Page', 'siteorigin-panels' ),
				'post_status'    => ! empty( $_POST['siteorigin_panels_home_enabled'] ) ? 'publish' : 'draft',
				'post_type'      => 'page',
				'post_content'   => $post_content,
				'comment_status' => 'closed',
			) );
			update_option( 'page_on_front', $page_id );
			update_option( 'siteorigin_panels_home_page_id', $page_id );

			// Action triggered when creating a new home page through the custom home page interface
			do_action( 'siteorigin_panels_create_home_page', $page_id );
		} else {
			// `wp_insert_post` does it's own sanitization, but it seems `wp_update_post` doesn't.
			$post_content = sanitize_post_field( 'post_content', $post_content, $page_id, 'db' );

			// Update the post with changed content to save revision if necessary.
			wp_update_post( array( 'ID' => $page_id, 'post_content' => $post_content ) );
		}

		$page = get_post( $page_id );

		// Save the updated page data
		$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
		$panels_data['widgets'] = $this->process_raw_widgets( $panels_data['widgets'] );
		$panels_data            = SiteOrigin_Panels_Styles::single()->sanitize_all( $panels_data );
		$panels_data            = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $page, $page_id );

		update_post_meta( $page_id, 'panels_data', $panels_data );

		$template      = get_post_meta( $page_id, '_wp_page_template', true );
		$home_template = siteorigin_panels_setting( 'home-template' );
		if ( ( $template == '' || $template == 'default' ) && ! empty( $home_template ) ) {
			// Set the home page template
			update_post_meta( $page_id, '_wp_page_template', $home_template );
		}

		if ( ! empty( $_POST['siteorigin_panels_home_enabled'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );
			update_option( 'siteorigin_panels_home_page_id', $page_id );
			wp_publish_post( $page_id );
		} else {
			// We're disabling this home page
			update_option( 'show_on_front', 'posts' );

			// Change the post status to draft
			$post = get_post( $page_id );
			if ( $post->post_status != 'draft' ) {
				global $wpdb;

				$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post->ID ) );
				clean_post_cache( $post->ID );

				$old_status        = $post->post_status;
				$post->post_status = 'draft';
				wp_transition_post_status( 'draft', $old_status, $post );

				do_action( 'edit_post', $post->ID, $post );
				do_action( "save_post_{$post->post_type}", $post->ID, $post, true );
				do_action( 'save_post', $post->ID, $post, true );
				do_action( 'wp_insert_post', $post->ID, $post, true );
			}
		}
	}

	/**
	 * After the theme is switched, change the template on the home page if the theme supports home page functionality.
	 */
	function update_home_on_theme_change() {
		$page_id = get_option( 'page_on_front' );
		if ( empty( $page_id ) ) {
			$page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		if ( siteorigin_panels_setting( 'home-page' ) && siteorigin_panels_setting( 'home-template' ) && $page_id && get_post_meta( $page_id, 'panels_data', true ) !== '' ) {
			// Lets update the home page to use the home template that this theme supports
			update_post_meta( $page_id, '_wp_page_template', siteorigin_panels_setting( 'home-template' ) );
		}
	}

	/**
	 * @return array|mixed|void
	 */
	function get_widgets() {
		global $wp_widget_factory;
		$widgets = array();
		foreach ( $wp_widget_factory->widgets as $class => $widget_obj ) {
			$widgets[ $class ] = array(
				'class'       => $class,
				'title'       => ! empty( $widget_obj->name ) ? $widget_obj->name : __( 'Untitled Widget', 'siteorigin-panels' ),
				'description' => ! empty( $widget_obj->widget_options['description'] ) ? $widget_obj->widget_options['description'] : '',
				'installed'   => true,
				'groups'      => array(),
			);

			// Get Page Builder specific widget options
			if ( isset( $widget_obj->widget_options['panels_title'] ) ) {
				$widgets[ $class ]['panels_title'] = $widget_obj->widget_options['panels_title'];
			}
			if ( isset( $widget_obj->widget_options['panels_groups'] ) ) {
				$widgets[ $class ]['groups'] = $widget_obj->widget_options['panels_groups'];
			}
			if ( isset( $widget_obj->widget_options['panels_icon'] ) ) {
				$widgets[ $class ]['icon'] = $widget_obj->widget_options['panels_icon'];
			}

		}

		// Other plugins can manipulate the list of widgets. Possibly to add recommended widgets
		$widgets = apply_filters( 'siteorigin_panels_widgets', $widgets );

		// Sort the widgets alphabetically
		uasort( $widgets, array( $this, 'widgets_sorter' ) );

		return $widgets;
	}

	/**
	 * Sorts widgets for get_widgets function by title
	 *
	 * @param $a
	 * @param $b
	 *
	 * @return int
	 */
	function widgets_sorter( $a, $b ) {
		if ( empty( $a['title'] ) ) {
			return - 1;
		}
		if ( empty( $b['title'] ) ) {
			return 1;
		}

		return $a['title'] > $b['title'] ? 1 : - 1;
	}

	/**
	 * Process raw widgets that have come from the Page Builder front end.
	 *
	 * @param $widgets
	 *
	 * @return array
	 */
	function process_raw_widgets( $widgets ) {
		if ( empty( $widgets ) || ! is_array( $widgets ) ) {
			return array();
		}

		global $wp_widget_factory;

		for ( $i = 0; $i < count( $widgets ); $i ++ ) {
			if ( ! is_array( $widgets[ $i ] ) ) {
				continue;
			}

			if ( is_array( $widgets[ $i ] ) ) {
				$info = (array) ( is_array( $widgets[ $i ]['panels_info'] ) ? $widgets[ $i ]['panels_info'] : $widgets[ $i ]['info'] );
			} else {
				$info = array();
			}
			unset( $widgets[ $i ]['info'] );

			if ( ! empty( $info['raw'] ) ) {
				if ( isset( $wp_widget_factory->widgets[ $info['class'] ] ) && method_exists( $info['class'], 'update' ) ) {
					$the_widget = $wp_widget_factory->widgets[ $info['class'] ];
					$instance   = $the_widget->update( $widgets[ $i ], $widgets[ $i ] );
					$instance   = apply_filters( 'widget_update_callback', $instance, $widgets[ $i ], $widgets[ $i ], $the_widget );

					$widgets[ $i ] = $instance;
					unset( $info['raw'] );
				}
			}

			$info['class']                = addslashes( $info['class'] );
			$widgets[ $i ]['panels_info'] = $info;
		}

		return $widgets;
	}

	/**
	 * Add all the footer JS templates.
	 */
	function js_templates() {
		include plugin_dir_path( __FILE__ ) . '../tpl/js-templates.php';
	}

	/**
	 * @param $value
	 * @param $post_id
	 * @param $meta_key
	 *
	 * @return mixed
	 */
	function view_post_preview( $value, $post_id, $meta_key ) {
		if ( $meta_key == 'panels_data' && is_preview() && current_user_can( 'edit_post', $post_id ) ) {
			$panels_preview = get_post_meta( $post_id, '_panels_data_preview' );

			return ! empty( $panels_preview ) ? $panels_preview : $value;
		}

		return $value;
	}

	/**
	 * Render a widget form with all the Page Builder specific fields
	 *
	 * @param string $widget The class of the widget
	 * @param array $instance Widget values
	 * @param bool $raw
	 * @param string $widget_number
	 *
	 * @return mixed|string The form
	 */
	function render_form( $widget, $instance = array(), $raw = false, $widget_number = '{$id}' ) {
		global $wp_widget_factory;

		// This is a chance for plugins to replace missing widgets
		$the_widget = ! empty( $wp_widget_factory->widgets[ $widget ] ) ? $wp_widget_factory->widgets[ $widget ] : false;
		$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget );

		if ( empty( $the_widget ) || ! is_a( $the_widget, 'WP_Widget' ) ) {
			$widgets = $this->get_widgets();

			if ( ! empty( $widgets[ $widget ] ) && ! empty( $widgets[ $widget ]['plugin'] ) ) {
				// We know about this widget, show a form about installing it.
				$install_url = siteorigin_panels_plugin_activation_install_url( $widgets[ $widget ]['plugin']['slug'], $widgets[ $widget ]['plugin']['name'] );
				$form        =
					'<div class="panels-missing-widget-form">' .
					'<p>' .
					preg_replace(
						array(
							'/1\{ *(.*?) *\}/',
							'/2\{ *(.*?) *\}/',
						),
						array(
							'<a href="' . $install_url . '" target="_blank">$1</a>',
							'<strong>$1</strong>'
						),
						sprintf(
							__( 'You need to install 1{%1$s} to use the widget 2{%2$s}.', 'siteorigin-panels' ),
							$widgets[ $widget ]['plugin']['name'],
							$widget
						)
					) .
					'</p>' .
					'<p>' . __( "Save and reload this page to start using the widget after you've installed it.", 'siteorigin-panels' ) . '</p>' .
					'</div>';
			} else {
				// This widget is missing, so show a missing widgets form.
				$form =
					'<div class="panels-missing-widget-form"><p>' .
					preg_replace(
						array(
							'/1\{ *(.*?) *\}/',
							'/2\{ *(.*?) *\}/',
						),
						array(
							'<strong>$1</strong>',
							'<a href="https://siteorigin.com/thread/" target="_blank">$1</a>'
						),
						sprintf(
							__( 'The widget 1{%1$s} is not available. Please try locate and install the missing plugin. Post on the 2{support forums} if you need help.', 'siteorigin-panels' ),
							esc_html( $widget )
						)
					) .
					'</p></div>';
			}

			// Allow other themes and plugins to change the missing widget form
			return apply_filters( 'siteorigin_panels_missing_widget_form', $form, $widget, $instance );
		}

		if ( $raw ) {
			$instance = $the_widget->update( $instance, $instance );
		}

		$the_widget->id     = 'temp';
		$the_widget->number = $widget_number;

		ob_start();
		$return = $the_widget->form( $instance );
		do_action_ref_array( 'in_widget_form', array( &$the_widget, &$return, $instance ) );
		$form = ob_get_clean();

		// Convert the widget field naming into ones that Page Builder uses
		$exp  = preg_quote( $the_widget->get_field_name( '____' ) );
		$exp  = str_replace( '____', '(.*?)', $exp );
		$form = preg_replace( '/' . $exp . '/', 'widgets[' . preg_replace( '/\$(\d)/', '\\\$$1', $widget_number ) . '][$1]', $form );

		$form = apply_filters( 'siteorigin_panels_widget_form', $form, $widget, $instance );

		// Add all the information fields
		return $form;
	}

	/**
	 * Should we display premium teasers.
	 *
	 * @return bool
	 */
	public static function display_teaser() {
		return
			siteorigin_panels_setting( 'display-teaser' ) &&
			apply_filters( 'siteorigin_premium_upgrade_teaser', true ) &&
			! defined( 'SITEORIGIN_PREMIUM_VERSION' );
	}

	/**
	 * @return string
	 */
	public static function premium_url(){
		$ref = apply_filters( 'siteorigin_premium_affiliate_id', '' );
		$url = 'https://siteorigin.com/downloads/premium/?featured_plugin=siteorigin-panels';

		if( $ref ) {
			$url = add_query_arg( 'ref', urlencode( $ref ), $url );
		}

		return $url;
	}



	///////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//  ADMIN AJAX ACTIONS
	///////////////////////////////////////////////////////////////////////////////////////////////////////////////////


	/**
	 * Get builder content based on the submitted panels_data.
	 */
	function action_builder_content() {
		header( 'content-type: text/html' );

		if ( ! current_user_can( 'edit_post', $_POST['post_id'] ) ) {
			wp_die();
		}

		if ( empty( $_POST['post_id'] ) || empty( $_POST['panels_data'] ) ) {
			echo '';
			wp_die();
		}

		// echo the content
		$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
		$panels_data['widgets'] = $this->process_raw_widgets( $panels_data['widgets'] );
		$panels_data            = SiteOrigin_Panels_Styles::single()->sanitize_all( $panels_data );
		echo SiteOrigin_Panels_Renderer::single()->render( intval( $_POST['post_id'] ), false, $panels_data );

		wp_die();
	}

	/**
	 * Display a widget form with the provided data
	 */
	function action_widget_form() {
		if ( empty( $_REQUEST['widget'] ) ) {
			wp_die();
		}
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		$request = array_map( 'stripslashes_deep', $_REQUEST );

		$widget   = $request['widget'];
		$instance = ! empty( $request['instance'] ) ? json_decode( $request['instance'], true ) : array();

		$form = $this->render_form( $widget, $instance, $_REQUEST['raw'] == 'true' );
		$form = apply_filters( 'siteorigin_panels_ajax_widget_form', $form, $widget, $instance );

		echo $form;
		wp_die();
	}

	/**
	 * Gets all the prebuilt layouts
	 */
	function action_get_prebuilt_layouts() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		// Get any layouts that the current user could edit.
		header( 'content-type: application/json' );

		$type   = ! empty( $_REQUEST['type'] ) ? $_REQUEST['type'] : 'directory';
		$search = ! empty( $_REQUEST['search'] ) ? trim( strtolower( $_REQUEST['search'] ) ) : '';
		$page   = ! empty( $_REQUEST['page'] ) ? intval( $_REQUEST['page'] ) : 1;

		$return = array(
			'title' => '',
			'items' => array()
		);
		if ( $type == 'prebuilt' ) {
			$return['title'] = __( 'Theme Defined Layouts', 'siteorigin-panels' );

			// This is for theme bundled prebuilt directories
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

			foreach ( $layouts as $id => $vals ) {
				if ( ! empty( $search ) && strpos( strtolower( $vals['name'] ), $search ) === false ) {
					continue;
				}

				$return['items'][] = array(
					'title'       => $vals['name'],
					'id'          => $id,
					'type'        => 'prebuilt',
					'description' => isset( $vals['description'] ) ? $vals['description'] : '',
					'screenshot'  => ! empty( $vals['screenshot'] ) ? $vals['screenshot'] : ''
				);
			}

			$return['max_num_pages'] = 1;
		} elseif ( $type == 'directory' ) {
			$return['title'] = __( 'Layouts Directory', 'siteorigin-panels' );

			// This is a query of the prebuilt layout directory
			$query = array();
			if ( ! empty( $search ) ) {
				$query['search'] = $search;
			}
			$query['page'] = $page;

			$url      = add_query_arg( $query, self::LAYOUT_URL . 'wp-admin/admin-ajax.php?action=query_layouts' );
			$response = wp_remote_get( $url );

			if ( is_array( $response ) && $response['response']['code'] == 200 ) {
				$results = json_decode( $response['body'], true );
				if ( ! empty( $results ) && ! empty( $results['items'] ) ) {
					foreach ( $results['items'] as $item ) {
						$screenshot_url = add_query_arg( 'screenshot_preview', 1, $item['preview'] );
						$item['id']         = $item['slug'];
						$item['screenshot'] = 'http://s.wordpress.com/mshots/v1/' . urlencode( $screenshot_url ) . '?w=400';
						$item['type']       = 'directory';
						$return['items'][]  = $item;
					}
				}

				$return['max_num_pages'] = $results['max_num_pages'];
			}
		} elseif ( strpos( $type, 'clone_' ) !== false ) {
			// Check that the user can view the given page types
			$post_type = str_replace( 'clone_', '', $type );

			$return['title'] = sprintf( __( 'Clone %s', 'siteorigin-panels' ), esc_html( ucfirst( $post_type ) ) );

			global $wpdb;
			$user_can_read_private = ( $post_type == 'post' && current_user_can( 'read_private_posts' ) || ( $post_type == 'page' && current_user_can( 'read_private_pages' ) ) );
			$include_private       = $user_can_read_private ? "OR posts.post_status = 'private' " : "";

			// Select only the posts with the given post type that also have panels_data
			$results     = $wpdb->get_results( "
			SELECT SQL_CALC_FOUND_ROWS DISTINCT ID, post_title, meta.meta_value
			FROM {$wpdb->posts} AS posts
			JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
			WHERE
				posts.post_type = '" . esc_sql( $post_type ) . "'
				AND meta.meta_key = 'panels_data'
				" . ( ! empty( $search ) ? 'AND posts.post_title LIKE "%' . esc_sql( $search ) . '%"' : '' ) . "
				AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' " . $include_private . ")
			ORDER BY post_date DESC
			LIMIT 16 OFFSET " . intval( ( $page - 1 ) * 16 ) );
			$total_posts = $wpdb->get_var( "SELECT FOUND_ROWS();" );

			foreach ( $results as $result ) {
				$thumbnail         = get_the_post_thumbnail_url( $result->ID, array( 400, 300 ) );
				$return['items'][] = array(
					'id'         => $result->ID,
					'title'      => $result->post_title,
					'type'       => $type,
					'screenshot' => ! empty( $thumbnail ) ? $thumbnail : ''
				);
			}

			$return['max_num_pages'] = ceil( $total_posts / 16 );

		} else {
			// An invalid type. Display an error message.
		}

		// Add the search part to the title
		if ( ! empty( $search ) ) {
			$return['title'] .= __( ' - Results For:', 'siteorigin-panels' ) . ' <em>' . esc_html( $search ) . '</em>';
		}

		echo json_encode( $return );

		wp_die();
	}

	/**
	 * Ajax handler to get an individual prebuilt layout
	 */
	function action_get_prebuilt_layout() {
		if ( empty( $_REQUEST['type'] ) ) {
			wp_die();
		}
		if ( empty( $_REQUEST['lid'] ) ) {
			wp_die();
		}
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		header( 'content-type: application/json' );

		if ( $_REQUEST['type'] == 'prebuilt' ) {
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
			if ( empty( $layouts[ $_REQUEST['lid'] ] ) ) {
				// Display an error message
				wp_die();
			}

			$layout = $layouts[ $_REQUEST['lid'] ];
			if ( isset( $layout['name'] ) ) {
				unset( $layout['name'] );
			}

			$layout = apply_filters( 'siteorigin_panels_prebuilt_layout', $layout );
			$layout = apply_filters( 'siteorigin_panels_data', $layout );

			echo json_encode( $layout );
			wp_die();
		}
		if ( $_REQUEST['type'] == 'directory' ) {
			$response = wp_remote_get(
				self::LAYOUT_URL . 'layout/' . urlencode( $_REQUEST['lid'] ) . '/?action=download'
			);

			// var_dump($response['body']);
			if ( $response['response']['code'] == 200 ) {
				// For now, we'll just pretend to load this
				echo $response['body'];
				wp_die();
			} else {
				// Display some sort of error message
			}
		} elseif ( current_user_can( 'edit_post', $_REQUEST['lid'] ) ) {
			$panels_data = get_post_meta( $_REQUEST['lid'], 'panels_data', true );
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data );
			echo json_encode( $panels_data );
			wp_die();
		}
	}

	/**
	 * Ajax handler to import a layout
	 */
	function action_import_layout() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		if ( ! empty( $_FILES['panels_import_data']['tmp_name'] ) ) {
			header( 'content-type:application/json' );
			$json = file_get_contents( $_FILES['panels_import_data']['tmp_name'] );
			@unlink( $_FILES['panels_import_data']['tmp_name'] );
			echo $json;
		}
		wp_die();
	}

	/**
	 * Export a given layout as a JSON file.
	 */
	function action_export_layout() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		header( 'content-type: application/json' );
		header( 'Content-Disposition: attachment; filename=layout-' . date( 'dmY' ) . '.json' );

		$export_data = wp_unslash( $_POST['panels_export_data'] );
		echo $export_data;

		wp_die();
	}

	/**
	 * Preview in the live editor when there is no public view of the item
	 */
	function action_live_editor_preview() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'live-editor-preview' ) ) {
			wp_die();
		}

		include plugin_dir_path( __FILE__ ) . '../tpl/live-editor-preview.php';

		exit();
	}
}
