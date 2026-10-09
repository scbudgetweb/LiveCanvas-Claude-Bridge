<?php
/**
 * Server-side operations for Claude's livecanvas tools, run through runtime/wp-run.php (CLI) by the MCP server.
 * Every op returns a plain array (JSON-encoded by the runner). Callers have already checked the site is local,
 * connected, and that we're acting as an administrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array */
function lccb_ops_dispatch( $command, array $args ) {
	$ops = array(
		'context'         => 'lccb_op_context',
		'brief'           => 'lccb_op_brief',
		'pages_list'      => 'lccb_op_pages_list',
		'page_read'       => 'lccb_op_page_read',
		'page_create'     => 'lccb_op_page_create',
		'page_update'     => 'lccb_op_page_update',
		'partial_read'    => 'lccb_op_partial_read',
		'partial_update'  => 'lccb_op_partial_update',
		'templates_list'  => 'lccb_op_templates_list',
		'template_read'   => 'lccb_op_template_read',
		'template_upsert' => 'lccb_op_template_upsert',
		'audit_list'      => 'lccb_op_audit_list',
		'audit_restore'   => 'lccb_op_audit_restore',
		'apply_change'    => 'lccb_op_apply_change',
		'editor_url'      => 'lccb_op_editor_url',
		'tokens_get'      => 'lccb_op_tokens_get',
		'tokens_update'   => 'lccb_op_tokens_update',
		'media_list'      => 'lccb_op_media_list',
		'media_import'    => 'lccb_op_media_import',
		'lint'            => 'lccb_op_lint',
		'html_templates'  => 'lccb_op_html_templates',
		'html_template_scan' => 'lccb_op_html_template_scan',
		'html_template_read' => 'lccb_op_html_template_read',
		'html_template_assets' => 'lccb_op_html_template_assets',
		'html_template_locate' => 'lccb_op_html_template_locate',
		'migrate_scan'    => 'lccb_op_migrate_scan',
		'migrate_site'    => 'lccb_op_migrate_site',
		'migrate_page'    => 'lccb_op_migrate_page',
		'media_import_batch' => 'lccb_op_media_import_batch',
		'redirect_map'    => 'lccb_op_redirect_map',
		'image_audit'     => 'lccb_op_image_audit',
		'image_optimise'  => 'lccb_op_image_optimise',
		'image_crop'      => 'lccb_op_image_crop',
		'media_read'      => 'lccb_op_media_read',
		'media_update'    => 'lccb_op_media_update',
		'stock_search'    => 'lccb_op_stock_search',
		'stock_import'    => 'lccb_op_stock_import',
		'qa'              => 'lccb_op_qa',
		'qa_targets'      => 'lccb_op_qa_targets',
		'sections_list'   => 'lccb_op_sections_list',
		'section_read'    => 'lccb_op_section_read',
		'section_usage'   => 'lccb_op_section_usage',
		'section_create'  => 'lccb_op_section_create',
		'section_update'  => 'lccb_op_section_update',
		'section_replace_inline' => 'lccb_op_section_replace_inline',
	);
	if ( ! isset( $ops[ $command ] ) ) {
		throw new Exception( "Unknown command: $command" );
	}
	return call_user_func( $ops[ $command ], $args );
}

// ───────────────────────── Helpers ─────────────────────────

function lccb_editor_url( $post_id ) {
	return add_query_arg( 'lc_action_launch_editing', '1', get_permalink( $post_id ) );
}

/** is_* meta flags set to 1 on a LiveCanvas partial or dynamic template (its role / display conditions). */
function lccb_flag_meta( $post_id ) {
	$flags = array();
	foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
		if ( 0 === strpos( $key, 'is_' ) && isset( $values[0] ) && '1' === (string) $values[0] ) {
			$flags[] = $key;
		}
	}
	return $flags;
}

function lccb_plugin_version( $file ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$path = WP_PLUGIN_DIR . '/' . $file;
	return is_file( $path ) ? get_plugin_data( $path, false, false )['Version'] : null;
}

// ───────────────────────── context ─────────────────────────

/** Everything Claude needs to know about the site's stack, design system and structure, in one call. */
function lccb_op_context( array $args ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$theme   = wp_get_theme();
	$parent  = $theme->parent();
	$active  = (array) get_option( 'active_plugins', array() );
	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( in_array( $file, $active, true ) ) {
			$plugins[] = array( 'name' => $data['Name'], 'version' => $data['Version'], 'file' => $file );
		}
	}
	$has = function ( $needle ) use ( $active ) {
		foreach ( $active as $file ) {
			if ( false !== stripos( $file, $needle ) ) {
				return true;
			}
		}
		return false;
	};

	// Design system (Picostrap stores Bootstrap SCSS variables as SCSSvar_* theme mods).
	$mods   = (array) get_theme_mods();
	$tokens = array();
	foreach ( $mods as $key => $value ) {
		if ( 0 === strpos( (string) $key, 'SCSSvar_' ) && '' !== $value && null !== $value ) {
			$tokens[ substr( $key, 8 ) ] = $value;
		}
	}
	$bundle      = get_stylesheet_directory() . '/css-output/bundle.css';
	$global_css  = (string) wp_get_custom_css();
	$global_js   = function_exists( 'lc_get_partial_by_cf' ) ? (string) lc_get_partial_by_cf( 'is_global_js' ) : '';
	$is_picostrap = false !== stripos( $theme->get_template(), 'picostrap' );

	// LiveCanvas pages.
	$pages = array();
	foreach ( get_posts( array(
		'post_type'      => 'any',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'meta_key'       => '_lc_livecanvas_enabled',
		'meta_value'     => '1',
		'posts_per_page' => 200,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
	) ) as $p ) {
		$pages[] = array(
			'id'       => $p->ID,
			'type'     => $p->post_type,
			'title'    => $p->post_title,
			'slug'     => $p->post_name,
			'status'   => $p->post_status,
			'url'      => get_permalink( $p ),
			'modified' => $p->post_modified,
			'chars'    => strlen( $p->post_content ),
		);
	}

	// Partials (header / footer / global JS …) and dynamic templates.
	$partials = array();
	foreach ( get_posts( array( 'post_type' => 'lc_partial', 'post_status' => 'any', 'posts_per_page' => 100 ) ) as $p ) {
		$partials[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'roles' => lccb_flag_meta( $p->ID ), 'chars' => strlen( $p->post_content ), 'modified' => $p->post_modified );
	}
	$templates = array();
	foreach ( get_posts( array( 'post_type' => 'lc_dynamic_template', 'post_status' => 'any', 'posts_per_page' => 100, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $p ) {
		$templates[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'conditions' => lccb_flag_meta( $p->ID ), 'menu_order' => $p->menu_order, 'chars' => strlen( $p->post_content ), 'modified' => $p->post_modified );
	}

	// Menus.
	$locations = get_nav_menu_locations();
	$menus     = array();
	foreach ( wp_get_nav_menus() as $menu ) {
		$menus[] = array(
			'id'        => $menu->term_id,
			'name'      => $menu->name,
			'items'     => (int) $menu->count,
			'locations' => array_keys( array_filter( $locations, function ( $id ) use ( $menu ) { return (int) $id === (int) $menu->term_id; } ) ),
		);
	}

	return array(
		'site'      => array(
			'name'       => get_bloginfo( 'name' ),
			'tagline'    => get_bloginfo( 'description' ),
			'url'        => home_url( '/' ),
			'language'   => get_locale(),
			'permalinks' => (string) get_option( 'permalink_structure' ),
			'front_page' => 'page' === get_option( 'show_on_front' ) ? array( 'page_on_front' => (int) get_option( 'page_on_front' ), 'page_for_posts' => (int) get_option( 'page_for_posts' ) ) : 'latest posts',
			'root'       => untrailingslashit( ABSPATH ),
		),
		'stack'     => array(
			'wordpress'    => get_bloginfo( 'version' ),
			'php'          => PHP_VERSION,
			'livecanvas'   => lccb_plugin_version( 'livecanvas/livecanvas-plugin-index.php' ),
			'theme'        => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'folder' => $theme->get_stylesheet(), 'dir' => get_stylesheet_directory() ),
			'parent_theme' => $parent ? array( 'name' => $parent->get( 'Name' ), 'version' => $parent->get( 'Version' ), 'folder' => $parent->get_stylesheet() ) : null,
			'picostrap'    => $is_picostrap,
			'windpress'    => $has( 'windpress' ),
			'picowind'     => false !== stripos( $theme->get_template(), 'picowind' ),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'acf'          => class_exists( 'ACF' ),
			'multilingual' => $has( 'polylang' ) ? 'polylang' : ( $has( 'sitepress' ) ? 'wpml' : null ),
			'plugins'      => $plugins,
		),
		'design'    => array(
			'scss_variables'  => $tokens,
			'fonts'           => array(
				'headings' => isset( $mods['headings_font_object'] ) ? $mods['headings_font_object'] : null,
				'body'     => isset( $mods['body_font_object'] ) ? $mods['body_font_object'] : null,
			),
			'css_bundle'      => is_file( $bundle ) ? array( 'path' => $bundle, 'bytes' => filesize( $bundle ), 'version' => isset( $mods['css_bundle_version_number'] ) ? $mods['css_bundle_version_number'] : null ) : null,
			'global_css_chars' => strlen( $global_css ),
			'global_js_chars'  => strlen( $global_js ),
			'conventions'     => lccb_class_conventions( $global_css, $pages ),
		),
		'structure' => array(
			'livecanvas_pages' => $pages,
			'partials'         => $partials,
			'templates'        => $templates,
			'menus'            => $menus,
			'menu_locations'   => get_registered_nav_menus(),
		),
		'how_to'    => array(
			'edit_open_page'   => 'lc_read_html / lc_edit_html (the page open in the builder; not saved until lc_save)',
			'edit_global_css'  => 'lc_read_css / lc_edit_css (WordPress Additional CSS)',
			'see_the_result'   => 'lc_screenshot (any width) and lc_inspect (rendered HTML + computed styles + matching CSS rules)',
			'editor_url'       => 'append ?lc_action_launch_editing=1 to a LiveCanvas page URL',
		),
	);
}

/**
 * Most-used class-name prefixes (e.g. "sc-", "btn-") and custom properties, so Claude reuses the site's own
 * conventions instead of inventing new ones.
 */
function lccb_class_conventions( $global_css, array $pages ) {
	$counts = array();
	$html   = '';
	foreach ( array_slice( $pages, 0, 40 ) as $p ) {
		$html .= get_post_field( 'post_content', $p['id'], 'raw' );
	}
	if ( preg_match_all( '/class="([^"]+)"/', $html, $m ) ) {
		foreach ( $m[1] as $list ) {
			foreach ( preg_split( '/\s+/', trim( $list ) ) as $cls ) {
				if ( preg_match( '/^([a-z]{1,8}-)[a-z0-9]/i', $cls, $pm ) ) {
					$counts[ $pm[1] ] = ( isset( $counts[ $pm[1] ] ) ? $counts[ $pm[1] ] : 0 ) + 1;
				}
			}
		}
	}
	arsort( $counts );
	$custom_classes = array();
	if ( preg_match_all( '/\.([a-z][a-z0-9_-]{2,})/i', $global_css, $cm ) ) {
		$custom_classes = array_slice( array_keys( array_count_values( $cm[1] ) ), 0, 60 );
	}
	$props = array();
	if ( preg_match_all( '/(--[a-z0-9-]+)\s*:/i', $global_css, $vm ) ) {
		$props = array_values( array_unique( $vm[1] ) );
	}
	return array(
		'class_prefixes_in_pages' => array_slice( $counts, 0, 15, true ),
		'classes_in_global_css'   => $custom_classes,
		'custom_properties'       => array_slice( $props, 0, 60 ),
	);
}

// ───────────────────────── brief (CLAUDE.md) ─────────────────────────

function lccb_op_brief( array $args ) {
	$path = lccb_write_site_brief();
	return array( 'written' => $path );
}
