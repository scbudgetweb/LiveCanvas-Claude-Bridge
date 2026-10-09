<?php
/**
 * Design-system linter (lc_lint): flags what drifts from the site's own system, each finding with a suggested fix.
 *
 * Markup: inline styles, hard-coded colours, off-scale spacing, heading order, more than one h1, images without
 * alt/size, empty links, duplicate ids, and classes that aren't defined anywhere or used elsewhere (often typos).
 * Global CSS: hard-coded colours that aren't the site's tokens, and spacing off Bootstrap's scale.
 *
 * Read-only. The selection and open-page scopes pass the builder's live HTML/CSS in; the rest is read from the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_LINT_PER_RULE = 15;

// Bootstrap 5's spacer scale ($spacer = 1rem): utility step => [rem, px].
const LCCB_LINT_SCALE = array( 0 => 0, 1 => 0.25, 2 => 0.5, 3 => 1, 4 => 1.5, 5 => 3 );

// Classes that come from scripts, plugins or WordPress rather than a stylesheet we can read.
const LCCB_LINT_CLASS_ALLOW = '/^(bi|fa[srlbd]?|lc|wp|js|is|has|aos|animate|swiper|glightbox|forminator|wpcf7|woocommerce|menu|current|page|post|sub|screen-reader|lazy|lazyload|skip|sr|editable|alignwide|alignfull|alignleft|alignright|aligncenter)([-_].*)?$/i';

const LCCB_LINT_RULES = array(
	'duplicate_id'     => array( 'error', 'Duplicate id' ),
	'img_no_alt'       => array( 'error', 'Image without alt' ),
	'empty_link'       => array( 'error', 'Link or button with no accessible name' ),
	'multiple_h1'      => array( 'error', 'More than one h1' ),
	'no_h1'            => array( 'warning', 'No h1 on the page' ),
	'heading_skip'     => array( 'warning', 'Heading level skipped' ),
	'inline_style'     => array( 'warning', 'Inline style' ),
	'hardcoded_colour' => array( 'warning', 'Colour that isn\'t a site token' ),
	'unknown_class'    => array( 'warning', 'Class not defined or used anywhere else' ),
	'img_no_size'      => array( 'warning', 'Image without width/height' ),
	'offscale_spacing' => array( 'info', 'Spacing off Bootstrap\'s scale' ),
);

// ───────────────────────── Entry point ─────────────────────────

function lccb_op_lint( array $args ) {
	$scope = isset( $args['scope'] ) ? $args['scope'] : 'page';
	$ctx   = lccb_lint_context( isset( $args['css'] ) ? (string) $args['css'] : null );
	$found = array();
	$units = array();

	if ( isset( $args['html'] ) ) {
		// Live HTML from the builder (selection, or the open page).
		$label   = isset( $args['label'] ) ? (string) $args['label'] : 'the builder';
		$units[] = $label;
		lccb_lint_html( (string) $args['html'], $label, $ctx, $found, 'selection' !== $scope );
		if ( 'selection' !== $scope ) {
			lccb_lint_css( $ctx['global_css'], 'Global CSS', $ctx, $found );
			$units[] = 'Global CSS';
		}
	} elseif ( 'site' === $scope ) {
		foreach ( lccb_lint_site_posts() as $p ) {
			$label   = lccb_lint_post_label( $p );
			$units[] = $label;
			lccb_lint_html( $p->post_content, $label, $ctx, $found, 'page' === $p->post_type || 'post' === $p->post_type );
		}
		lccb_lint_css( $ctx['global_css'], 'Global CSS', $ctx, $found );
		$units[] = 'Global CSS';
	} else {
		$p       = lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'page', 'post', 'lc_partial', 'lc_dynamic_template', 'lc_section' ) );
		$label   = lccb_lint_post_label( $p );
		$units[] = $label;
		lccb_lint_html( $p->post_content, $label, $ctx, $found, in_array( $p->post_type, array( 'page', 'post' ), true ) );
	}

	return lccb_lint_report( $found, $units, $scope );
}

function lccb_lint_site_posts() {
	$pages = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'meta_key'       => '_lc_livecanvas_enabled',
		'meta_value'     => '1',
		'posts_per_page' => 200,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
	) );
	$parts = get_posts( array( 'post_type' => array( 'lc_partial', 'lc_dynamic_template' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 100 ) );
	$parts = array_filter( $parts, function ( $p ) {
		return ! get_post_meta( $p->ID, 'is_global_js', true );
	} );
	return array_merge( $pages, $parts );
}

function lccb_lint_post_label( $p ) {
	$roles = 'lc_partial' === $p->post_type ? lccb_flag_meta( $p->ID ) : array();
	$kind  = $roles ? str_replace( 'is_', '', $roles[0] ) : ( 'lc_dynamic_template' === $p->post_type ? 'template' : $p->post_type );
	return sprintf( '%s "%s" (#%d)', $kind, $p->post_title, $p->ID );
}

// ───────────────────────── What "the site's system" is ─────────────────────────

function lccb_lint_context( $live_css = null ) {
	$global_css = null !== $live_css ? $live_css : (string) wp_get_custom_css();
	$known      = array();
	$sheets     = array( $global_css );
	$bundle     = function_exists( 'lccb_bundle_path' ) ? lccb_bundle_path() : '';
	if ( $bundle && is_file( $bundle ) ) {
		$sheets[] = (string) file_get_contents( $bundle );
	} else {
		foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
			foreach ( (array) glob( $dir . '/{,css/,css-output/,assets/css/}*.css', GLOB_BRACE ) as $f ) {
				if ( is_file( $f ) && filesize( $f ) < 3 * 1024 * 1024 ) {
					$sheets[] = (string) file_get_contents( $f );
				}
			}
		}
	}
	// Template assets imported into the child theme (Phase B) count as the site's own CSS too.
	foreach ( (array) glob( get_stylesheet_directory() . '/template-assets/*/*.css' ) as $f ) {
		if ( is_file( $f ) && filesize( $f ) < 3 * 1024 * 1024 ) {
			$sheets[] = (string) file_get_contents( $f );
		}
	}
	foreach ( $sheets as $css ) {
		if ( preg_match_all( '/\.(-?[a-zA-Z_][\w-]*)/', $css, $m ) ) {
			foreach ( $m[1] as $c ) {
				$known[ $c ] = true;
			}
		}
	}

	// Class usage across every LiveCanvas page and partial: a class used in two places is a deliberate hook.
	$usage = array();
	foreach ( lccb_lint_site_posts() as $p ) {
		if ( preg_match_all( '/\bclass\s*=\s*["\']([^"\']*)["\']/i', $p->post_content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/\s+/', trim( $list ) ) as $c ) {
					if ( '' !== $c ) {
						$usage[ $c ] = ( isset( $usage[ $c ] ) ? $usage[ $c ] : 0 ) + 1;
					}
				}
			}
		}
	}

	return array(
		'global_css' => $global_css,
		'known'      => $known,
		'usage'      => $usage,
		'palette'    => lccb_lint_palette( $global_css ),
	);
}

/** Colour tokens: custom properties defined in Global CSS, plus Picostrap's theme colours as var(--bs-*). */
function lccb_lint_palette( $css ) {
	$palette = array();
	if ( preg_match_all( '/(--[\w-]+)\s*:\s*([^;}{]+)/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $d ) {
			$rgb = lccb_lint_parse_colour( trim( $d[2] ) );
			if ( $rgb ) {
				$palette[ 'var(' . $d[1] . ')' ] = $rgb;
			}
		}
	}
	if ( function_exists( 'lccb_is_picostrap' ) && lccb_is_picostrap() ) {
		$bs = array( 'primary', 'secondary', 'success', 'info', 'warning', 'danger', 'light', 'dark', 'body-bg', 'body-color', 'link-color' );
		foreach ( $bs as $name ) {
			$v   = get_theme_mod( 'SCSSvar_' . $name );
			$rgb = $v ? lccb_lint_parse_colour( (string) $v ) : null;
			if ( $rgb ) {
				$palette[ 'var(--bs-' . $name . ')' ] = $rgb;
			}
		}
	}
	return $palette;
}

/** #rgb, #rrggbb (with optional alpha), rgb()/rgba() in comma or space syntax. @return int[]|null [r, g, b, alpha*100] */
function lccb_lint_parse_colour( $s ) {
	$s = strtolower( trim( $s ) );
	if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $s, $m ) ) {
		$h = $m[1];
		if ( strlen( $h ) <= 4 ) {
			$h = preg_replace( '/(.)/', '$1$1', $h );
		}
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), strlen( $h ) === 8 ? (int) round( hexdec( substr( $h, 6, 2 ) ) / 2.55 ) : 100 );
	}
	if ( preg_match( '/^rgba?\(\s*([\d.]+)%?[\s,]+([\d.]+)%?[\s,]+([\d.]+)%?\s*(?:[,\/]\s*([\d.]+)(%?))?\s*\)$/', $s, $m ) ) {
		$a = isset( $m[4] ) && '' !== $m[4] ? ( '%' === $m[5] ? (float) $m[4] : (float) $m[4] * 100 ) : 100;
		return array( (int) $m[1], (int) $m[2], (int) $m[3], (int) round( $a ) );
	}
	return null;
}

/** The token closest to a colour. @return array [token, distance] */
function lccb_lint_nearest_token( array $rgb, array $palette ) {
	$best = null;
	$dist = INF;
	foreach ( $palette as $token => $p ) {
		$d = sqrt( pow( $rgb[0] - $p[0], 2 ) + pow( $rgb[1] - $p[1], 2 ) + pow( $rgb[2] - $p[2], 2 ) );
		if ( $d < $dist ) {
			$dist = $d;
			$best = $token;
		}
	}
	return array( $best, $dist );
}

function lccb_lint_colour_fix( $colour, array $ctx ) {
	$rgb = lccb_lint_parse_colour( $colour );
	if ( ! $rgb || ! $ctx['palette'] ) {
		return $ctx['palette'] ? null : 'Define it once as a custom property in Global CSS (e.g. --brand-…) and use var() everywhere.';
	}
	list( $token, $d ) = lccb_lint_nearest_token( $rgb, $ctx['palette'] );
	$use = $rgb[3] < 100 ? sprintf( 'color-mix(in srgb, %s %d%%, transparent)', $token, $rgb[3] ) : $token;
	if ( $d < 1 ) {
		return "Use $use (same colour).";
	}
	if ( $d < 18 ) {
		return "Almost $token: use $use.";
	}
	return "Not one of the site's colours (nearest is $token). Use a token, or add a new custom property if it's really new.";
}

// ───────────────────────── Spacing ─────────────────────────

/** @return int|null the Bootstrap spacer step for a length, or null if it's off the scale */
function lccb_lint_spacer_step( $len ) {
	if ( ! preg_match( '/^(-?[\d.]+)(px|rem)$/', $len, $m ) ) {
		return null;
	}
	$rem = abs( 'px' === $m[2] ? (float) $m[1] / 16 : (float) $m[1] );
	foreach ( LCCB_LINT_SCALE as $step => $v ) {
		if ( abs( $rem - $v ) < 0.001 ) {
			return $step;
		}
	}
	return null;
}

function lccb_lint_nearest_step( $len ) {
	preg_match( '/^(-?[\d.]+)(px|rem)$/', $len, $m );
	$rem  = abs( 'px' === $m[2] ? (float) $m[1] / 16 : (float) $m[1] );
	$best = 0;
	foreach ( LCCB_LINT_SCALE as $step => $v ) {
		if ( abs( $rem - $v ) < abs( $rem - LCCB_LINT_SCALE[ $best ] ) ) {
			$best = $step;
		}
	}
	return $best;
}

/** Off-scale lengths in a margin/padding/gap value (calc(), var() and clamp() are left alone). */
function lccb_lint_offscale( $value ) {
	if ( false !== strpos( $value, '(' ) ) {
		return array();
	}
	$off = array();
	foreach ( preg_split( '/\s+/', trim( str_replace( '!important', '', $value ) ) ) as $part ) {
		if ( preg_match( '/^-?[\d.]+(px|rem)$/', $part ) && '0px' !== $part && null === lccb_lint_spacer_step( $part ) ) {
			$off[] = $part;
		}
	}
	return $off;
}

// ───────────────────────── Inline styles → utilities ─────────────────────────

function lccb_lint_utility_for( $prop, $value, array $ctx ) {
	$v     = strtolower( trim( str_replace( '!important', '', $value ) ) );
	$sides = array( 'margin' => 'm', 'padding' => 'p' );
	if ( preg_match( '/^(margin|padding)(?:-(top|right|bottom|left))?$/', $prop, $m ) ) {
		$axis = isset( $m[2] ) ? array( 'top' => 't', 'right' => 'e', 'bottom' => 'b', 'left' => 's' )[ $m[2] ] : '';
		if ( 'auto' === $v && 'margin' === $m[1] ) {
			return '.' . $sides[ $m[1] ] . $axis . '-auto';
		}
		$step = lccb_lint_spacer_step( $v );
		if ( null === $step && preg_match( '/^-?[\d.]+(px|rem)$/', $v ) ) {
			return sprintf( '.%s%s-%d (nearest step, %srem)', $sides[ $m[1] ], $axis, lccb_lint_nearest_step( $v ), LCCB_LINT_SCALE[ lccb_lint_nearest_step( $v ) ] );
		}
		return null !== $step ? '.' . $sides[ $m[1] ] . $axis . '-' . $step : null;
	}
	if ( 'gap' === $prop && null !== lccb_lint_spacer_step( $v ) ) {
		return '.gap-' . lccb_lint_spacer_step( $v );
	}
	$simple = array(
		'text-align'      => array( 'left' => '.text-start', 'center' => '.text-center', 'right' => '.text-end' ),
		'display'         => array( 'none' => '.d-none', 'block' => '.d-block', 'flex' => '.d-flex', 'inline-block' => '.d-inline-block', 'grid' => '.d-grid', 'inline-flex' => '.d-inline-flex' ),
		'font-weight'     => array( '300' => '.fw-light', '400' => '.fw-normal', 'normal' => '.fw-normal', '500' => '.fw-medium', '600' => '.fw-semibold', '700' => '.fw-bold', 'bold' => '.fw-bold' ),
		'font-style'      => array( 'italic' => '.fst-italic' ),
		'text-transform'  => array( 'uppercase' => '.text-uppercase', 'lowercase' => '.text-lowercase', 'capitalize' => '.text-capitalize' ),
		'justify-content' => array( 'center' => '.justify-content-center', 'space-between' => '.justify-content-between', 'flex-end' => '.justify-content-end', 'flex-start' => '.justify-content-start' ),
		'align-items'     => array( 'center' => '.align-items-center', 'flex-start' => '.align-items-start', 'flex-end' => '.align-items-end' ),
		'flex-direction'  => array( 'column' => '.flex-column', 'row' => '.flex-row' ),
		'flex-wrap'       => array( 'wrap' => '.flex-wrap' ),
		'position'        => array( 'relative' => '.position-relative', 'absolute' => '.position-absolute', 'sticky' => '.position-sticky' ),
		'overflow'        => array( 'hidden' => '.overflow-hidden' ),
		'width'           => array( '100%' => '.w-100', '50%' => '.w-50', '75%' => '.w-75', '25%' => '.w-25', 'auto' => '.w-auto' ),
		'height'          => array( '100%' => '.h-100', 'auto' => '.h-auto' ),
		'border-radius'   => array( '50%' => '.rounded-circle', '0' => '.rounded-0' ),
	);
	if ( isset( $simple[ $prop ][ $v ] ) ) {
		return $simple[ $prop ][ $v ];
	}
	if ( in_array( $prop, array( 'color', 'background-color', 'background', 'border-color' ), true ) && lccb_lint_parse_colour( $v ) ) {
		list( $token, $d ) = $ctx['palette'] ? lccb_lint_nearest_token( lccb_lint_parse_colour( $v ), $ctx['palette'] ) : array( null, INF );
		if ( $d < 18 && preg_match( '/^var\(--bs-(primary|secondary|success|info|warning|danger|light|dark)\)$/', $token, $tm ) ) {
			return ( 'color' === $prop ? '.text-' : '.bg-' ) . $tm[1];
		}
		return $d < 18 ? "a class in Global CSS using $token" : null;
	}
	return null;
}

// ───────────────────────── Markup ─────────────────────────

function lccb_lint_add( array &$found, $rule, $where, $message, $fix = null, $snippet = null ) {
	$found[] = array_filter( array(
		'rule'     => $rule,
		'severity' => LCCB_LINT_RULES[ $rule ][0],
		'where'    => $where,
		'element'  => $snippet,
		'problem'  => $message,
		'fix'      => $fix,
	), function ( $v ) {
		return null !== $v && '' !== $v;
	} );
}

/** The element's opening tag as it appears in the source, as a search handle for lc_read_html / lc_edit_html. */
function lccb_lint_tag( DOMElement $el ) {
	$attrs = '';
	foreach ( $el->attributes as $a ) {
		$attrs .= ' ' . $a->name . '="' . $a->value . '"';
	}
	$tag = '<' . $el->tagName . $attrs . '>';
	return strlen( $tag ) > 180 ? substr( $tag, 0, 177 ) . '…>' : $tag;
}

function lccb_lint_html( $html, $where, array $ctx, array &$found, $is_page ) {
	if ( '' === trim( $html ) ) {
		return;
	}
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><div id="lccb-lint-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp   = new DOMXPath( $dom );
	$root = $dom->getElementById( 'lccb-lint-root' );
	if ( ! $root ) {
		return;
	}

	// Duplicate ids.
	$ids = array();
	foreach ( $xp->query( './/*[@id]', $root ) as $el ) {
		$ids[ $el->getAttribute( 'id' ) ][] = $el;
	}
	foreach ( $ids as $id => $els ) {
		if ( count( $els ) > 1 && '' !== $id ) {
			lccb_lint_add( $found, 'duplicate_id', $where, sprintf( 'id="%s" is used %d times; ids must be unique (anchors, labels and scripts pick the first).', $id, count( $els ) ), 'Rename the copies, or use a class for styling.', lccb_lint_tag( $els[1] ) );
		}
	}

	// Headings.
	$h1   = 0;
	$last = 0;
	foreach ( $xp->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root ) as $hd ) {
		$level = (int) substr( $hd->tagName, 1 );
		if ( 1 === $level && ++$h1 > 1 ) {
			lccb_lint_add( $found, 'multiple_h1', $where, 'A second h1: "' . lccb_lint_text( $hd ) . '".', 'Keep one h1 per page (usually the hero title); make this an h2 and style it with a class (e.g. .display-4 or .h1) if it must look the same.', lccb_lint_tag( $hd ) );
		}
		if ( $last && $level > $last + 1 ) {
			lccb_lint_add( $found, 'heading_skip', $where, sprintf( 'h%d follows h%d: "%s".', $level, $last, lccb_lint_text( $hd ) ), sprintf( 'Use h%d and style it with a class (e.g. .h%d) if it should look smaller.', $last + 1, $level ), lccb_lint_tag( $hd ) );
		}
		$last = $level;
	}
	if ( $is_page && 0 === $h1 && false === stripos( $html, '[lc_get_post' ) ) {
		lccb_lint_add( $found, 'no_h1', $where, 'This page has no h1.', 'Make the main title (usually in the hero) the h1.' );
	}

	// Images.
	foreach ( $xp->query( './/img', $root ) as $img ) {
		if ( ! $img->hasAttribute( 'alt' ) ) {
			lccb_lint_add( $found, 'img_no_alt', $where, 'Image without an alt attribute: ' . basename( (string) $img->getAttribute( 'src' ) ), 'Describe the image in alt="…", or use alt="" if it is purely decorative.', lccb_lint_tag( $img ) );
		}
		if ( ! $img->hasAttribute( 'width' ) || ! $img->hasAttribute( 'height' ) ) {
			lccb_lint_add( $found, 'img_no_size', $where, 'Image without width and height: the page jumps while it loads.', 'Add width="…" height="…" with its real pixel size (CSS still controls the displayed size). lc_media_list gives the sizes.', lccb_lint_tag( $img ) );
		}
	}

	// Links and buttons with no accessible name.
	foreach ( $xp->query( './/a|.//button', $root ) as $a ) {
		if ( '' !== lccb_lint_text( $a ) || $a->getAttribute( 'aria-label' ) || $a->getAttribute( 'aria-labelledby' ) || $a->getAttribute( 'title' ) ) {
			continue;
		}
		$named = false;
		foreach ( $xp->query( './/img[@alt!=""]|.//*[@aria-label]|.//*[contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")]', $a ) as $_ ) {
			$named = true;
		}
		if ( ! $named ) {
			$icon = $xp->query( './/*[contains(@class, "bi-") or contains(@class, "fa-")]', $a );
			lccb_lint_add( $found, 'empty_link', $where, $icon->length ? 'Icon-only ' . $a->tagName . ': screen readers announce nothing useful.' : 'Empty ' . $a->tagName . '.', $icon->length ? 'Add aria-label="…" saying where it goes (e.g. "Instagram").' : 'Give it visible text, or aria-label="…" saying what it does.', lccb_lint_tag( $a ) );
		}
	}

	// Inline styles: hard-coded colours, off-scale spacing, and the utility class that would do the same.
	foreach ( $xp->query( './/*[@style]', $root ) as $el ) {
		$style = (string) $el->getAttribute( 'style' );
		if ( '' === trim( $style ) || preg_match( '/^\s*(--[\w-]+\s*:[^;]*;?\s*)+$/', $style ) ) {
			continue; // custom-property-only styles are a deliberate pattern
		}
		$utils = array();
		$rest  = array();
		foreach ( lccb_lint_declarations( $style ) as $d ) {
			list( $prop, $val ) = $d;
			if ( 'background-image' === $prop || ( 'background' === $prop && false !== stripos( $val, 'url(' ) ) ) {
				continue; // a per-element background image is a legitimate inline style
			}
			foreach ( lccb_lint_colours_in( $val ) as $c ) {
				$fix = lccb_lint_colour_fix( $c, $ctx );
				lccb_lint_add( $found, 'hardcoded_colour', $where, "Inline $prop: $c.", $fix, lccb_lint_tag( $el ) );
			}
			$u = lccb_lint_utility_for( $prop, $val, $ctx );
			if ( $u ) {
				$utils[] = "$prop: $val → $u";
			} elseif ( 0 !== strpos( $prop, '--' ) ) {
				$rest[] = "$prop: $val";
			}
		}
		if ( $utils || $rest ) {
			$fix = $utils ? 'Use ' . implode( '; ', $utils ) . '.' : '';
			if ( $rest ) {
				$fix .= ( $fix ? ' ' : '' ) . 'Move ' . implode( '; ', array_slice( $rest, 0, 4 ) ) . ' into a class in Global CSS (reuse an existing one if it matches).';
			}
			lccb_lint_add( $found, 'inline_style', $where, 'style="' . ( strlen( $style ) > 120 ? substr( $style, 0, 117 ) . '…' : $style ) . '"', $fix, lccb_lint_tag( $el ) );
		}
	}

	// SVG fills/strokes with fixed colours.
	foreach ( $xp->query( './/*[@fill or @stroke]', $root ) as $el ) {
		foreach ( array( 'fill', 'stroke' ) as $attr ) {
			$v = (string) $el->getAttribute( $attr );
			if ( $v && lccb_lint_parse_colour( $v ) ) {
				lccb_lint_add( $found, 'hardcoded_colour', $where, "SVG $attr=\"$v\".", 'Use ' . $attr . '="currentColor" and set the colour with a text class. ' . ( lccb_lint_colour_fix( $v, $ctx ) ?: '' ), lccb_lint_tag( $el ) );
			}
		}
	}

	// Classes that aren't defined anywhere and aren't used anywhere else.
	$reported = array();
	foreach ( $xp->query( './/*[@class]', $root ) as $el ) {
		foreach ( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) as $c ) {
			if ( '' === $c || isset( $reported[ $c ] ) || isset( $ctx['known'][ $c ] ) || preg_match( LCCB_LINT_CLASS_ALLOW, $c ) ) {
				continue;
			}
			if ( isset( $ctx['usage'][ $c ] ) && $ctx['usage'][ $c ] > 1 ) {
				continue;
			}
			$reported[ $c ] = true;
			$near           = lccb_lint_similar_class( $c, $ctx['known'] );
			lccb_lint_add( $found, 'unknown_class', $where, ".$c isn't defined in the theme CSS or Global CSS, and isn't used anywhere else on the site.", $near ? "Typo for .$near?" : 'Remove it, or define it in Global CSS if a script relies on it.', lccb_lint_tag( $el ) );
		}
	}
}

function lccb_lint_text( DOMNode $n ) {
	$t = trim( preg_replace( '/\s+/', ' ', $n->textContent ) );
	return strlen( $t ) > 60 ? substr( $t, 0, 57 ) . '…' : $t;
}

function lccb_lint_similar_class( $c, array $known ) {
	$best = null;
	$dist = 3;
	$len  = strlen( $c );
	foreach ( $known as $k => $_ ) {
		if ( abs( strlen( $k ) - $len ) > 2 ) {
			continue;
		}
		$d = levenshtein( $c, $k );
		if ( $d < $dist ) {
			$dist = $d;
			$best = $k;
		}
	}
	return $best;
}

// ───────────────────────── CSS ─────────────────────────

/** Declarations of a style attribute or rule body: [[prop, value], …]. */
function lccb_lint_declarations( $body ) {
	$out = array();
	foreach ( explode( ';', $body ) as $decl ) {
		$p = strpos( $decl, ':' );
		if ( false === $p ) {
			continue;
		}
		$prop = strtolower( trim( substr( $decl, 0, $p ) ) );
		$val  = trim( substr( $decl, $p + 1 ) );
		if ( '' !== $prop && '' !== $val ) {
			$out[] = array( $prop, $val );
		}
	}
	return $out;
}

/** Literal colours in a value, ignoring var() fallbacks. */
function lccb_lint_colours_in( $value ) {
	$value = preg_replace( '/var\([^()]*(\([^()]*\)[^()]*)*\)/', '', $value );
	preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/', $value, $m );
	return array_values( array_filter( $m[0], 'lccb_lint_parse_colour' ) );
}

function lccb_lint_css( $css, $where, array $ctx, array &$found ) {
	// Blank out comments, keeping offsets (and so line numbers) intact.
	$clean = preg_replace_callback( '#/\*.*?\*/#s', function ( $m ) {
		return preg_replace( '/[^\n]/', ' ', $m[0] );
	}, $css );
	if ( ! preg_match_all( '/(?<![\w-])(--[\w-]+|[a-z-]+)\s*:\s*([^;{}]+)(?=;|})/i', $clean, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return;
	}
	foreach ( $m as $d ) {
		$prop = strtolower( $d[1][0] );
		$val  = trim( $d[2][0] );
		$pos  = $d[0][1];
		if ( 0 === strpos( $prop, '--' ) || preg_match( '/shadow|filter|mask/', $prop ) ) {
			continue; // token definitions; shadows and filters use one-off translucent colours by design
		}
		$open = strrpos( substr( $clean, 0, $pos ), '{' );
		if ( false === $open ) {
			continue;
		}
		$line = substr_count( substr( $css, 0, $pos ), "\n" ) + 1;
		$before = substr( $clean, 0, $open );
		$start  = -1;
		foreach ( array( '}', '{', ';' ) as $ch ) {
			$i     = strrpos( $before, $ch );
			$start = false !== $i && $i > $start ? $i : $start;
		}
		$sel  = trim( preg_replace( '/\s+/', ' ', substr( $before, $start + 1 ) ) );
		$loc  = sprintf( '%s line %d', $where, $line );
		$val  = preg_replace( '/\s+/', ' ', $val );
		$el   = ( strlen( $sel ) > 80 ? substr( $sel, 0, 77 ) . '…' : $sel ) . ' { ' . $prop . ': ' . ( strlen( $val ) > 160 ? substr( $val, 0, 157 ) . '…' : $val ) . ' }';

		$fixes = array();
		foreach ( array_unique( lccb_lint_colours_in( $val ) ) as $c ) {
			$fix = lccb_lint_colour_fix( $c, $ctx );
			if ( $fix ) {
				$fixes[] = "$c: $fix";
			}
		}
		if ( $fixes ) {
			lccb_lint_add( $found, 'hardcoded_colour', $loc, $prop . ' uses ' . count( $fixes ) . ' literal colour' . ( count( $fixes ) > 1 ? 's' : '' ) . '.', implode( ' ', $fixes ), $el );
		}
		if ( preg_match( '/^(margin|padding)(-(top|right|bottom|left|inline|block)(-(start|end))?)?$|^(row-|column-)?gap$/', $prop ) ) {
			$off = lccb_lint_offscale( $val );
			if ( $off ) {
				$steps = array_map( function ( $o ) {
					$s = lccb_lint_nearest_step( $o );
					return "$o → " . LCCB_LINT_SCALE[ $s ] . 'rem (step ' . $s . ')';
				}, $off );
				lccb_lint_add( $found, 'offscale_spacing', $loc, "$prop: $val is off Bootstrap's spacing scale (0, .25, .5, 1, 1.5, 3rem).", 'Nearest: ' . implode( ', ', $steps ) . '. Fine to keep if it\'s deliberate; consider a custom property for repeated values.', $el );
			}
		}
	}
}

// ───────────────────────── Report ─────────────────────────

function lccb_lint_report( array $found, array $units, $scope ) {
	$order = array( 'error' => 0, 'warning' => 1, 'info' => 2 );
	usort( $found, function ( $a, $b ) use ( $order ) {
		return $order[ $a['severity'] ] - $order[ $b['severity'] ];
	} );
	// The same problem on the same element in the same place (a repeated card, a gallery) is one finding.
	$merged = array();
	foreach ( $found as $f ) {
		$key = md5( $f['rule'] . '|' . $f['where'] . '|' . ( isset( $f['element'] ) ? $f['element'] : '' ) . '|' . $f['problem'] );
		if ( isset( $merged[ $key ] ) ) {
			$merged[ $key ]['times'] = ( isset( $merged[ $key ]['times'] ) ? $merged[ $key ]['times'] : 1 ) + 1;
		} else {
			$merged[ $key ] = $f;
		}
	}
	$found  = array_values( $merged );
	$counts = array();
	$shown  = array();
	$per    = array();
	foreach ( $found as $f ) {
		$counts[ $f['rule'] ] = ( isset( $counts[ $f['rule'] ] ) ? $counts[ $f['rule'] ] : 0 ) + 1;
		if ( ( isset( $per[ $f['rule'] ] ) ? $per[ $f['rule'] ] : 0 ) < LCCB_LINT_PER_RULE ) {
			$per[ $f['rule'] ] = ( isset( $per[ $f['rule'] ] ) ? $per[ $f['rule'] ] : 0 ) + 1;
			$shown[]           = $f;
		}
	}
	$summary = array();
	foreach ( $counts as $rule => $n ) {
		$summary[] = array( 'rule' => $rule, 'severity' => LCCB_LINT_RULES[ $rule ][0], 'title' => LCCB_LINT_RULES[ $rule ][1], 'count' => $n, 'shown' => min( $n, LCCB_LINT_PER_RULE ) );
	}
	usort( $summary, function ( $a, $b ) use ( $order ) {
		return $order[ $a['severity'] ] - $order[ $b['severity'] ] ?: $b['count'] - $a['count'];
	} );
	return array(
		'scope'    => $scope,
		'checked'  => $units,
		'total'    => count( $found ),
		'summary'  => $summary,
		'findings' => $shown,
		'next'     => $found
			? 'Fix with lc_edit_html / lc_edit_css for the page open in the builder (search for the element shown), or lc_page_update / lc_partial_update for saved pages. Errors first. Ask the user before mass-changing deliberate design choices (info items, off-scale spacing).'
			: 'Nothing to fix.',
	);
}
