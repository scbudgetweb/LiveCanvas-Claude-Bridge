<?php
/**
 * The section library: LiveCanvas's own lc_section posts, embedded in pages with
 * <div lc-helper="shortcode" class="live-shortcode">[lc_get_post slug="…" post_type="lc_section"]</div>.
 * List, read, create and update sections, find where they're used, and swap inline copies on pages for the shortcode.
 * Writes are previewed, then applied and audited like the other site-level tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lccb_section_find( array $args ) {
	if ( ! empty( $args['id'] ) ) {
		return lccb_require_post( $args['id'], array( 'lc_section' ) );
	}
	if ( ! empty( $args['slug'] ) ) {
		$p = get_posts( array( 'name' => sanitize_title( $args['slug'] ), 'post_type' => 'lc_section', 'post_status' => 'any', 'posts_per_page' => 1 ) );
		if ( $p ) {
			return $p[0];
		}
		throw new Exception( "No section with slug \"{$args['slug']}\"." );
	}
	throw new Exception( 'Give the section\'s id or slug.' );
}

function lccb_section_embed( $slug ) {
	return '<div lc-helper="shortcode" class="live-shortcode">[lc_get_post slug="' . esc_attr( $slug ) . '" post_type="lc_section"]</div>';
}

function lccb_section_summary( $p ) {
	$types = wp_get_post_terms( $p->ID, 'lc_section_type', array( 'fields' => 'names' ) );
	return array(
		'id'     => $p->ID,
		'title'  => $p->post_title,
		'slug'   => $p->post_name,
		'status' => $p->post_status,
		'types'  => is_wp_error( $types ) ? array() : $types,
		'chars'  => strlen( $p->post_content ),
		'embed'  => lccb_section_embed( $p->post_name ),
	);
}

/** Posts that can hold sections: LiveCanvas pages, partials, templates and other sections. */
function lccb_section_hosts() {
	$pages = get_posts( array( 'post_type' => 'any', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'meta_key' => '_lc_livecanvas_enabled', 'meta_value' => '1', 'posts_per_page' => 300 ) );
	$parts = get_posts( array( 'post_type' => array( 'lc_partial', 'lc_dynamic_template' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 200 ) );
	$seen  = array();
	$out   = array();
	foreach ( array_merge( $pages, $parts ) as $p ) {
		if ( ! isset( $seen[ $p->ID ] ) && 'lc_section' !== $p->post_type ) {
			$seen[ $p->ID ] = true;
			$out[]          = $p;
		}
	}
	return $out;
}

/** Shortcode embeds of a section in some content. */
function lccb_section_embeds_in( $content, $p ) {
	$n = 0;
	if ( preg_match_all( '/\[lc_get_post\b([^\]]*)\]/', $content, $m ) ) {
		foreach ( $m[1] as $attrs ) {
			$a = shortcode_parse_atts( $attrs );
			if ( ! is_array( $a ) ) {
				continue;
			}
			$type_ok = empty( $a['post_type'] ) || 'lc_section' === $a['post_type'];
			if ( ( isset( $a['id'] ) && (int) $a['id'] === $p->ID ) || ( isset( $a['slug'] ) && $a['slug'] === $p->post_name && $type_ok ) ) {
				$n++;
			}
		}
	}
	return $n;
}

// ───────────────────────── Matching inline copies ─────────────────────────

/** Comparable form of some markup: whitespace between and inside tags collapsed. */
function lccb_section_norm( $html ) {
	$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
	$html = preg_replace( '/>\s+</', '><', $html );
	$html = preg_replace( '/\s+/', ' ', $html );
	return trim( $html );
}

/**
 * Every element in an HTML string with its [start, end) byte span, found with a tag-depth scan (no re-serialising,
 * so a match can be replaced in the original text exactly).
 */
function lccb_section_spans( $html ) {
	static $void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'path', 'circle', 'rect', 'line', 'polygon', 'polyline', 'ellipse', 'use', 'stop' );
	$spans = array();
	$stack = array();
	$len   = strlen( $html );
	$pos   = 0;
	while ( $pos < $len && preg_match( '/<!--.*?-->|<(\/?)([a-zA-Z][\w:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$start = $m[0][1];
		$end   = $start + strlen( $m[0][0] );
		$pos   = $end;
		if ( 0 === strpos( $m[0][0], '<!--' ) ) {
			continue;
		}
		$tag = strtolower( $m[2][0] );
		if ( '/' === $m[1][0] ) {
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ][0] === $tag ) {
					$spans[] = array( 'tag' => $tag, 'start' => $stack[ $i ][1], 'end' => $end, 'depth' => $i );
					array_splice( $stack, $i );
					break;
				}
			}
			continue;
		}
		if ( in_array( $tag, $void, true ) || '/' === substr( rtrim( $m[3][0] ), -1 ) ) {
			continue;
		}
		if ( 'script' === $tag || 'style' === $tag ) {
			$close = stripos( $html, '</' . $tag, $end );
			$close = false === $close ? $len : strpos( $html, '>', $close ) + 1;
			$spans[] = array( 'tag' => $tag, 'start' => $start, 'end' => $close, 'depth' => count( $stack ) );
			$pos     = $close;
			continue;
		}
		$stack[] = array( $tag, $start );
	}
	return $spans;
}

/** Spans of $content that are copies of the section's markup (outermost only). Near misses are reported separately. */
function lccb_section_copies( $content, $section_html ) {
	$target = lccb_section_norm( $section_html );
	if ( '' === $target ) {
		return array( 'exact' => array(), 'similar' => array() );
	}
	preg_match( '/^<([a-z][\w-]*)([^>]*)>/i', $target, $tm );
	$t_tag   = $tm ? strtolower( $tm[1] ) : '';
	$t_class = $tm && preg_match( '/class="([^"]*)"/', $tm[2], $cm ) ? $cm[1] : '';
	$exact   = array();
	$similar = array();
	foreach ( lccb_section_spans( $content ) as $sp ) {
		if ( $sp['tag'] !== $t_tag ) {
			continue;
		}
		$chunk = substr( $content, $sp['start'], $sp['end'] - $sp['start'] );
		$norm  = lccb_section_norm( $chunk );
		if ( $norm === $target ) {
			$exact[] = $sp;
		} elseif ( $t_class && false !== strpos( substr( $norm, 0, 300 ), 'class="' . $t_class . '"' ) && abs( strlen( $norm ) - strlen( $target ) ) < 0.2 * strlen( $target ) ) {
			similar_text( substr( $norm, 0, 4000 ), substr( $target, 0, 4000 ), $pct );
			if ( $pct >= 80 ) {
				$similar[] = $sp + array( 'similarity' => round( $pct ) );
			}
		}
	}
	$outer = function ( array $list ) {
		return array_values( array_filter( $list, function ( $a ) use ( $list ) {
			foreach ( $list as $b ) {
				if ( $b !== $a && $b['start'] <= $a['start'] && $b['end'] >= $a['end'] ) {
					return false;
				}
			}
			return true;
		} ) );
	};
	return array( 'exact' => $outer( $exact ), 'similar' => $outer( $similar ) );
}

// ───────────────────────── Ops ─────────────────────────

function lccb_op_sections_list( array $args ) {
	$secs  = get_posts( array( 'post_type' => 'lc_section', 'post_status' => 'any', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
	$hosts = lccb_section_hosts();
	$out   = array();
	foreach ( $secs as $p ) {
		$used = 0;
		foreach ( $hosts as $h ) {
			$used += lccb_section_embeds_in( $h->post_content, $p ) ? 1 : 0;
		}
		$out[] = lccb_section_summary( $p ) + array( 'embedded_on' => $used );
	}
	return array( 'sections' => $out, 'note' => 'Embed one on a page with its `embed` HTML (lc_edit_html, or lc_page_update for saved pages). Only published sections render.' );
}

function lccb_op_section_read( array $args ) {
	$p = lccb_section_find( $args );
	return lccb_section_summary( $p ) + array( 'html' => $p->post_content );
}

function lccb_op_section_usage( array $args ) {
	$p      = lccb_section_find( $args );
	$embeds = array();
	$inline = array();
	foreach ( lccb_section_hosts() as $h ) {
		$label = sprintf( '%s "%s" (#%d)', $h->post_type, $h->post_title, $h->ID );
		if ( $n = lccb_section_embeds_in( $h->post_content, $p ) ) {
			$embeds[] = array( 'id' => $h->ID, 'where' => $label, 'times' => $n, 'open_in_builder' => lccb_open_in_builder( $h->ID ) );
		}
		$c = lccb_section_copies( $h->post_content, $p->post_content );
		if ( $c['exact'] || $c['similar'] ) {
			$inline[] = array_filter( array(
				'id'      => $h->ID,
				'where'   => $label,
				'copies'  => count( $c['exact'] ) ?: null,
				'similar' => $c['similar'] ? array_map( function ( $s ) {
					return $s['similarity'] . '% similar';
				}, $c['similar'] ) : null,
			) );
		}
	}
	return array(
		'section'       => lccb_section_summary( $p ),
		'embedded_on'   => $embeds,
		'inline_copies' => $inline,
		'next'          => $inline ? 'lc_section_replace_inline swaps exact copies for the shortcode (one preview per page). "Similar" copies differ slightly: compare them first; they may be deliberate variations.' : 'No inline copies found.',
	);
}

function lccb_op_section_create( array $args ) {
	$title = trim( (string) ( isset( $args['title'] ) ? $args['title'] : '' ) );
	$html  = trim( (string) ( isset( $args['html'] ) ? $args['html'] : '' ) );
	if ( '' === $title || '' === $html ) {
		throw new Exception( 'A section needs a title and its HTML.' );
	}
	$slug = sanitize_title( ! empty( $args['slug'] ) ? $args['slug'] : $title );
	if ( get_posts( array( 'name' => $slug, 'post_type' => 'lc_section', 'post_status' => 'any', 'posts_per_page' => 1 ) ) ) {
		throw new Exception( "A section with slug \"$slug\" already exists: update it with lc_section_update, or pick another slug." );
	}
	$after = array(
		'post' => array( 'ID' => 0, 'post_type' => 'lc_section', 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_parent' => 0, 'menu_order' => 0, 'post_content' => $html ),
		'meta' => array( '_lc_livecanvas_enabled' => '1' ),
	);
	$r         = lccb_store_preview( 'lc_section_create', 'create', 'section', 0, "Create section \"$title\"", null, $after );
	$r['next'] .= ' Then embed it with: ' . lccb_section_embed( $slug ) . ' (and lc_section_replace_inline to swap existing copies).';
	return $r;
}

function lccb_op_section_update( array $args ) {
	$p = lccb_section_find( $args );
	lccb_refuse_if_open( $p->ID, 'Section' );
	$before = lccb_snapshot_post( $p->ID );
	$after  = $before;
	if ( isset( $args['title'] ) ) {
		$after['post']['post_title'] = (string) $args['title'];
	}
	if ( isset( $args['html'] ) ) {
		$after['post']['post_content'] = trim( (string) $args['html'] );
	} elseif ( isset( $args['old_string'] ) ) {
		$after['post']['post_content'] = lccb_replace_exact( $before['post']['post_content'], $args['old_string'], isset( $args['new_string'] ) ? (string) $args['new_string'] : '', ! empty( $args['replace_all'] ) );
	}
	if ( $after === $before ) {
		throw new Exception( 'Nothing to change.' );
	}
	$used = 0;
	foreach ( lccb_section_hosts() as $h ) {
		$used += lccb_section_embeds_in( $h->post_content, $p ) ? 1 : 0;
	}
	return lccb_store_preview( 'lc_section_update', 'update', 'section', $p->ID, "Update section \"{$p->post_title}\"", $before, $after, $used ? array( "Embedded on $used page(s): they all change." ) : array() );
}

/** Swap exact inline copies of a section for its shortcode, one preview per page. */
function lccb_op_section_replace_inline( array $args ) {
	$p = lccb_section_find( isset( $args['section'] ) ? ( is_numeric( $args['section'] ) ? array( 'id' => $args['section'] ) : array( 'slug' => $args['section'] ) ) : $args );
	if ( 'publish' !== $p->post_status ) {
		throw new Exception( 'Publish the section first: LiveCanvas only renders published sections.' );
	}
	$only     = array_map( 'intval', (array) ( isset( $args['pages'] ) ? $args['pages'] : array() ) );
	$previews = array();
	$skipped  = array();
	foreach ( lccb_section_hosts() as $h ) {
		if ( $only && ! in_array( $h->ID, $only, true ) ) {
			continue;
		}
		$c = lccb_section_copies( $h->post_content, $p->post_content );
		if ( ! $c['exact'] ) {
			continue;
		}
		if ( lccb_open_in_builder( $h->ID ) ) {
			$skipped[] = "\"{$h->post_title}\" (#{$h->ID}) is open in the builder: replace it there with lc_edit_html.";
			continue;
		}
		$before  = lccb_snapshot_post( $h->ID );
		$after   = $before;
		$content = $before['post']['post_content'];
		foreach ( array_reverse( $c['exact'] ) as $sp ) {
			$content = substr( $content, 0, $sp['start'] ) . lccb_section_embed( $p->post_name ) . substr( $content, $sp['end'] );
		}
		$after['post']['post_content'] = $content;
		$type       = 'page' === $h->post_type || 'post' === $h->post_type ? 'page' : ( 'lc_partial' === $h->post_type ? 'partial' : 'template' );
		$previews[] = lccb_store_preview( 'lc_section_replace_inline', 'update', $type, $h->ID, sprintf( 'Replace %d copy(ies) of section "%s" with its shortcode on "%s"', count( $c['exact'] ), $p->post_title, $h->post_title ), $before, $after );
	}
	if ( ! $previews ) {
		return array( 'previews' => array(), 'skipped' => $skipped, 'next' => 'No exact inline copies to replace' . ( $skipped ? ' (see skipped)' : '' ) . '. lc_section_usage also lists near-identical copies.' );
	}
	return array(
		'previews' => array_map( function ( $r ) {
			return array( 'preview_id' => $r['preview_id'], 'summary' => $r['summary'], 'content_diff' => $r['content_diff'] );
		}, $previews ),
		'skipped'  => $skipped,
		'next'     => 'Nothing written yet. Apply each preview_id with lc_apply_change (each is a separate, undoable change).',
	);
}
