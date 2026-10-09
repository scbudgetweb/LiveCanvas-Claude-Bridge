<?php
/**
 * The site brief: a managed block in the site root's CLAUDE.md, so every Claude Code session on this site
 * (CC Chat, CC Terminal, VS Code, a terminal) starts knowing the stack and how to work with it.
 *
 * Only the text between the markers is ours; anything else in CLAUDE.md is never touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_BRIEF_START', '<!-- lccb:start (managed by LC Claude Bridge: edits inside this block are replaced on refresh) -->' );
define( 'LCCB_BRIEF_END', '<!-- lccb:end -->' );

function lccb_brief_path() {
	return lccb_site_root() . '/CLAUDE.md';
}

function lccb_site_brief_markdown() {
	$c   = lccb_op_context( array() );
	$s   = $c['stack'];
	$d   = $c['design'];
	$st  = $c['structure'];
	$out = array();

	$out[] = '## This site (LiveCanvas + LC Claude Bridge)';
	$out[] = '';
	$out[] = sprintf( '**%s** (%s): WordPress %s, LiveCanvas %s, theme %s%s.', $c['site']['name'], $c['site']['url'], $s['wordpress'], $s['livecanvas'] ? $s['livecanvas'] : '?', $s['theme']['name'], $s['parent_theme'] ? ' (child of ' . $s['parent_theme']['name'] . ')' : '' );
	$extras = array_filter( array(
		$s['picostrap'] ? 'Picostrap (Bootstrap 5, SCSS compiled by the theme)' : '',
		$s['picowind'] ? 'Picowind' : '',
		$s['windpress'] ? 'WindPress (Tailwind)' : '',
		$s['woocommerce'] ? 'WooCommerce' : '',
		$s['acf'] ? 'ACF' : '',
		$s['multilingual'] ? ucfirst( $s['multilingual'] ) : '',
	) );
	if ( $extras ) {
		$out[] = 'Also on the stack: ' . implode( ', ', $extras ) . '.';
	}
	$out[] = '';
	$out[] = '### Where things live';
	$out[] = '- **Page content:** LiveCanvas pages (WordPress posts with `_lc_livecanvas_enabled`). The HTML inside `main#lc-main` is what LiveCanvas edits.';
	$out[] = '- **Global CSS:** WordPress Additional CSS. **Global JS:** a LiveCanvas `lc_partial` with `is_global_js` (loaded as `type=module`).';
	$out[] = '- **Header / footer:** LiveCanvas `lc_partial` posts flagged `is_header` / `is_footer`.';
	$out[] = '- **Dynamic templates:** `lc_dynamic_template` posts; their display conditions are `is_*` meta keys (e.g. `is_single_post`).';
	if ( $s['picostrap'] ) {
		$out[] = '- **Design tokens:** Picostrap Customizer values stored as `SCSSvar_*` theme mods, compiled into `' . str_replace( $c['site']['root'] . '/', '', $s['theme']['dir'] ) . '/css-output/bundle.css`.';
	}
	$out[] = '- **Theme files:** `' . str_replace( $c['site']['root'] . '/', '', $s['theme']['dir'] ) . '`.';
	$out[] = '';
	$out[] = '### How to work on it';
	$out[] = '- Use the **livecanvas** MCP tools for builder content. Start with `lc_get_context` (what\'s open and selected), or `lc_site_context` for the whole site.';
	$out[] = '- Read before editing (`lc_read_html` / `lc_read_css`) and prefer `lc_edit_*` (exact replacements) over rewriting.';
	$out[] = '- **Check your work before saying it\'s done:** after visual or layout changes run `lc_responsive_check` (390/768/1200/1440 with layout detectors and one composite image) and fix the high-severity issues; use `lc_screenshot` for a closer look. Use `lc_inspect` to see rendered markup, computed styles and which CSS rules win, e.g. for shortcode or plugin output.';
	$out[] = '- Run `lc_lint` on what you built (scope `selection` or `page`) to catch inline styles, colours that aren\'t tokens, heading order, missing alt text and typo\'d classes.';
	$out[] = '- When the user\'s message comes with *"the user is pointing at …"*, they clicked that element in the preview: use the builder selector it gives with `lc_read_html` / `lc_edit_html`.';
	$out[] = '- Builder edits (`lc_edit_html`, `lc_edit_css`, …) are live in the preview but **not saved**. Only call `lc_save` when the user asks.';
	$out[] = '- Site-level changes (pages, header/footer, templates, design tokens, media) are previewed first, and `lc_apply_change` writes them to the site **immediately**. They don\'t need `lc_save`, and the builder\'s Save or undo doesn\'t cover them. Undo them with `lc_audit_restore`.';
	$out[] = '- If the user restores a checkpoint, re-read before editing again.';
	$lib = function_exists( 'lccb_tpl_library' ) ? lccb_tpl_library() : array();
	if ( $lib ) {
		$out[] = '- **Template library** (the user\'s HTML templates; don\'t search the disk for templates): ' . implode( ', ', array_map( function ( $t ) {
			return '`' . $t['name'] . '` (' . $t['pages'] . ' pages)';
		}, array_slice( $lib, 0, 15 ) ) ) . '. Find pages across them with `lc_html_templates {find}`; pass a template\'s name as `path`.';
	}
	$out[] = '- **Starting from an HTML template** (from the library, or a folder or .zip on this Mac): `lc_html_template_scan` → `lc_html_template_read` (sections as LiveCanvas-ready HTML, converted to Bootstrap 5 where needed) → map its tokens with `lc_tokens_update` → `lc_html_template_assets` (CSS/JS into the child theme, images into the media library) → build pages → check each section with `lc_compare`. Everything imported lives in the theme and media library, so it outlives this plugin.';
	$out[] = '- **Reusable sections** live in LiveCanvas\'s section library (`lc_sections_list`). Embed one with its shortcode rather than copying its HTML; `lc_section_replace_inline` swaps existing copies.';
	$conv = $d['conventions'];
	if ( $conv['classes_in_global_css'] || $conv['custom_properties'] ) {
		$out[] = '- **Reuse the site\'s own styles** before adding new ones.';
		if ( $conv['class_prefixes_in_pages'] ) {
			$out[] = '  - Most-used class prefixes: ' . implode( ', ', array_map( function ( $p ) { return '`' . $p . '`'; }, array_slice( array_keys( $conv['class_prefixes_in_pages'] ), 0, 8 ) ) ) . '.';
		}
		if ( $conv['custom_properties'] ) {
			$out[] = '  - Custom properties in Global CSS: ' . implode( ', ', array_map( function ( $p ) { return '`' . $p . '`'; }, array_slice( $conv['custom_properties'], 0, 12 ) ) ) . '.';
		}
	}
	if ( $d['scss_variables'] ) {
		$out[] = '';
		$out[] = '### Design tokens (Picostrap)';
		foreach ( array_slice( $d['scss_variables'], 0, 25, true ) as $k => $v ) {
			$out[] = '- `$' . $k . '`: ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) );
		}
	}
	$out[] = '';
	$out[] = '### Structure';
	$out[] = sprintf( '- %d LiveCanvas page(s): %s', count( $st['livecanvas_pages'] ), implode( ', ', array_map( function ( $p ) { return $p['title'] . ' (#' . $p['id'] . ( 'publish' !== $p['status'] ? ', ' . $p['status'] : '' ) . ')'; }, array_slice( $st['livecanvas_pages'], 0, 20 ) ) ) );
	$out[] = sprintf( '- %d partial(s): %s', count( $st['partials'] ), implode( ', ', array_map( function ( $p ) { return $p['title'] . ' [' . implode( ' ', $p['roles'] ) . ']'; }, $st['partials'] ) ) );
	$out[] = sprintf( '- %d dynamic template(s)%s', count( $st['templates'] ), $st['templates'] ? ': ' . implode( ', ', array_map( function ( $t ) { return $t['title'] . ' [' . implode( ' ', $t['conditions'] ) . ']'; }, $st['templates'] ) ) : '' );
	$out[] = '';
	$out[] = '_Generated ' . gmdate( 'Y-m-d' ) . '. Refresh it from Tools › Claude Code, or by reconnecting._';

	return implode( "\n", $out );
}

/** Write/refresh the managed block, keeping everything else in CLAUDE.md. @return string|false the path */
function lccb_write_site_brief() {
	$path    = lccb_brief_path();
	$block   = LCCB_BRIEF_START . "\n" . lccb_site_brief_markdown() . "\n" . LCCB_BRIEF_END;
	$current = is_file( $path ) ? (string) file_get_contents( $path ) : '';
	$pattern = '/' . preg_quote( '<!-- lccb:start', '/' ) . '.*?' . preg_quote( LCCB_BRIEF_END, '/' ) . '/s';
	if ( preg_match( $pattern, $current ) ) {
		$next = preg_replace( $pattern, str_replace( '$', '\\$', $block ), $current, 1 );
	} else {
		$next = rtrim( $current ) . ( '' !== trim( $current ) ? "\n\n" : '' ) . $block . "\n";
	}
	return false !== file_put_contents( $path, $next ) ? $path : false;
}

/** Remove the managed block; delete CLAUDE.md if nothing else is left in it. */
function lccb_remove_site_brief() {
	$path = lccb_brief_path();
	if ( ! is_file( $path ) ) {
		return null;
	}
	$current = (string) file_get_contents( $path );
	$next    = trim( preg_replace( '/' . preg_quote( '<!-- lccb:start', '/' ) . '.*?' . preg_quote( LCCB_BRIEF_END, '/' ) . '/s', '', $current ) );
	if ( $next === trim( $current ) ) {
		return null; // no block of ours
	}
	if ( '' === $next ) {
		return @unlink( $path ) ? 'deleted' : false;
	}
	return false !== file_put_contents( $path, $next . "\n" ) ? 'block removed' : false;
}
