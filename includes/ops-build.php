<?php
/**
 * Site-building ops: pages, header/footer partials, dynamic templates, and restoring audited changes.
 *
 * Every write is two steps:
 *   1. a "plan" op (lc_page_create, lc_page_update, …) works out the change WITHOUT writing anything and returns a
 *      readable preview plus a preview_id (kept 15 minutes);
 *   2. lc_apply_change {preview_id} applies it, but only if the target hasn't changed since the preview
 *      (fingerprint check), and records a before-copy in the audit log so it can be restored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_PREVIEW_TTL', 15 * MINUTE_IN_SECONDS );
define( 'LCCB_EDITOR_ACTIVE_SECS', 120 );

// ───────────────────────── Helpers ─────────────────────────

function lccb_fingerprint( $snapshot ) {
	return null === $snapshot ? 'new' : md5( wp_json_encode( $snapshot ) );
}

/** Is this post open in a LiveCanvas editor right now (the editor pings while open)? */
function lccb_open_in_builder( $post_id ) {
	$ts = (int) get_post_meta( $post_id, '_lc_last_activity_timestamp', true );
	return $ts && ( time() - $ts ) < LCCB_EDITOR_ACTIVE_SECS;
}

/** LiveCanvas stores the inner HTML of main#lc-main, starting with a newline. Accept either form from Claude. */
function lccb_lc_content( $html ) {
	$html = (string) $html;
	if ( preg_match( '#^\s*<main\b[^>]*\bid=["\']lc-main["\'][^>]*>(.*)</main>\s*$#is', $html, $m ) ) {
		$html = $m[1];
	}
	return "\n" . trim( $html, "\n" ) . "\n";
}

/** Exact find/replace like the builder's lc_edit_* tools. */
function lccb_replace_exact( $text, $old, $new, $all ) {
	if ( '' === (string) $old ) {
		throw new Exception( 'old_string must not be empty.' );
	}
	$count = substr_count( $text, $old );
	if ( 0 === $count ) {
		throw new Exception( 'old_string not found. Read the current content first (whitespace and indentation must match exactly).' );
	}
	if ( $count > 1 && ! $all ) {
		throw new Exception( "old_string matches $count times. Add surrounding context to make it unique, or set replace_all." );
	}
	return $all ? str_replace( $old, $new, $text ) : implode( $new, explode( $old, $text, 2 ) );
}

/** Line diff with 2 lines of context, "- " / "+ " / "  " prefixes; long unchanged runs folded. */
function lccb_text_diff( $a, $b, $max_lines = 300 ) {
	$A = explode( "\n", (string) $a );
	$B = explode( "\n", (string) $b );
	// Trim the common prefix/suffix so typical small edits stay cheap.
	$pre = 0;
	while ( $pre < count( $A ) && $pre < count( $B ) && $A[ $pre ] === $B[ $pre ] ) {
		$pre++;
	}
	$suf = 0;
	while ( $suf < count( $A ) - $pre && $suf < count( $B ) - $pre && $A[ count( $A ) - 1 - $suf ] === $B[ count( $B ) - 1 - $suf ] ) {
		$suf++;
	}
	$a_mid = array_slice( $A, $pre, count( $A ) - $pre - $suf );
	$b_mid = array_slice( $B, $pre, count( $B ) - $pre - $suf );
	$n     = count( $a_mid );
	$m     = count( $b_mid );
	$mid   = array();
	if ( $n * $m > 250000 ) {
		foreach ( $a_mid as $l ) {
			$mid[] = array( '-', $l );
		}
		foreach ( $b_mid as $l ) {
			$mid[] = array( '+', $l );
		}
	} else {
		$L = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$L[ $i ][ $j ] = $a_mid[ $i ] === $b_mid[ $j ] ? $L[ $i + 1 ][ $j + 1 ] + 1 : max( $L[ $i + 1 ][ $j ], $L[ $i ][ $j + 1 ] );
			}
		}
		$i = 0;
		$j = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a_mid[ $i ] === $b_mid[ $j ] ) {
				$mid[] = array( ' ', $a_mid[ $i ] );
				$i++;
				$j++;
			} elseif ( $L[ $i + 1 ][ $j ] >= $L[ $i ][ $j + 1 ] ) {
				$mid[] = array( '-', $a_mid[ $i++ ] );
			} else {
				$mid[] = array( '+', $b_mid[ $j++ ] );
			}
		}
		while ( $i < $n ) {
			$mid[] = array( '-', $a_mid[ $i++ ] );
		}
		while ( $j < $m ) {
			$mid[] = array( '+', $b_mid[ $j++ ] );
		}
	}
	if ( ! $mid ) {
		return '';
	}
	$ctx_before = array_slice( $A, max( 0, $pre - 2 ), min( 2, $pre ) );
	$ctx_after  = array_slice( $A, count( $A ) - $suf, min( 2, $suf ) );
	$out        = array();
	if ( $pre > 2 ) {
		$out[] = '@@ line ' . ( $pre - 1 ) . ' @@';
	}
	foreach ( $ctx_before as $l ) {
		$out[] = '  ' . $l;
	}
	foreach ( $mid as $row ) {
		$out[] = $row[0] . ' ' . $row[1];
	}
	foreach ( $ctx_after as $l ) {
		$out[] = '  ' . $l;
	}
	if ( count( $out ) > $max_lines ) {
		$out   = array_slice( $out, 0, $max_lines );
		$out[] = '… diff truncated';
	}
	return implode( "\n", $out );
}

/** Field-level + content changes between two post snapshots, as readable text. */
function lccb_describe_changes( $before, $after ) {
	$lines  = array();
	$labels = array( 'post_title' => 'title', 'post_name' => 'slug', 'post_status' => 'status', 'post_parent' => 'parent', 'menu_order' => 'order' );
	foreach ( $labels as $field => $label ) {
		$old = $before ? $before['post'][ $field ] : null;
		$new = $after['post'][ $field ];
		if ( $old !== $new && ! ( null === $old && ( '' === $new || 0 === $new ) ) ) {
			$lines[] = sprintf( '%s: %s → %s', $label, null === $old ? '(new)' : wp_json_encode( $old ), wp_json_encode( $new ) );
		}
	}
	$old_meta = $before ? $before['meta'] : array();
	foreach ( array_unique( array_merge( array_keys( $old_meta ), array_keys( $after['meta'] ) ) ) as $key ) {
		$o = array_key_exists( $key, $old_meta ) ? $old_meta[ $key ] : null;
		$n = array_key_exists( $key, $after['meta'] ) ? $after['meta'][ $key ] : null;
		if ( (string) $o !== (string) $n ) {
			$lines[] = sprintf( 'meta %s: %s → %s', $key, null === $o ? '(none)' : wp_json_encode( $o ), null === $n ? '(removed)' : wp_json_encode( $n ) );
		}
	}
	$diff = lccb_text_diff( $before ? $before['post']['post_content'] : '', $after['post']['post_content'] );
	return array( 'fields' => $lines, 'content_diff' => $diff );
}

/** Store a planned change and return the preview Claude (and the user) sees. */
function lccb_store_preview( $tool, $kind, $target_type, $target_id, $summary, $before, $after, $warnings = array() ) {
	$id   = 'pv_' . wp_generate_password( 10, false, false );
	$plan = compact( 'tool', 'kind', 'target_type', 'target_id', 'summary', 'before', 'after' );
	$plan['fingerprint'] = lccb_fingerprint( $before );
	set_transient( 'lccb_preview_' . $id, $plan, LCCB_PREVIEW_TTL );
	$changes = 'trash' === $kind ? array( 'fields' => array( 'status: ' . wp_json_encode( $before['post']['post_status'] ) . ' → "trash" (moved to the bin; restorable)' ), 'content_diff' => '' ) : lccb_describe_changes( $before, $after );
	return array(
		'preview_id'   => $id,
		'applied'      => false,
		'summary'      => $summary,
		'target'       => array( 'type' => $target_type, 'id' => $target_id ?: null ),
		'changes'      => $changes['fields'],
		'content_diff' => $changes['content_diff'],
		'warnings'     => $warnings,
		'next'         => 'Nothing has been written. Show the user what will change, then call lc_apply_change with this preview_id to apply it (valid 15 minutes).',
	);
}

function lccb_require_post( $id, $types ) {
	$p = get_post( (int) $id );
	if ( ! $p || ! in_array( $p->post_type, (array) $types, true ) ) {
		throw new Exception( "No " . implode( '/', (array) $types ) . " with ID $id." );
	}
	return $p;
}

function lccb_refuse_if_open( $post_id, $what ) {
	if ( lccb_open_in_builder( $post_id ) ) {
		throw new Exception( "$what #$post_id is open in the LiveCanvas builder right now. Edit it there with lc_read_html / lc_edit_html (then lc_save), or close the builder and try again, so the two don't overwrite each other." );
	}
}

/** Apply html / old_string+new_string edits from args onto a snapshot's content. */
function lccb_apply_content_args( array &$snap, array $args ) {
	if ( isset( $args['html'] ) ) {
		$snap['post']['post_content'] = lccb_lc_content( $args['html'] );
	} elseif ( isset( $args['old_string'] ) ) {
		$snap['post']['post_content'] = lccb_replace_exact( $snap['post']['post_content'], $args['old_string'], isset( $args['new_string'] ) ? (string) $args['new_string'] : '', ! empty( $args['replace_all'] ) );
	}
}

// ───────────────────────── Pages ─────────────────────────

function lccb_page_summary( $p ) {
	return array(
		'id'         => $p->ID,
		'type'       => $p->post_type,
		'title'      => $p->post_title,
		'slug'       => $p->post_name,
		'status'     => $p->post_status,
		'parent'     => (int) $p->post_parent,
		'livecanvas' => '1' === (string) get_post_meta( $p->ID, '_lc_livecanvas_enabled', true ),
		'url'        => get_permalink( $p ),
		'editor_url' => lccb_editor_url( $p->ID ),
		'modified'   => $p->post_modified,
		'open_in_builder' => lccb_open_in_builder( $p->ID ),
	);
}

function lccb_op_pages_list( array $args ) {
	$q = array(
		'post_type'      => 'page',
		'post_status'    => ! empty( $args['status'] ) ? $args['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => 200,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
	);
	if ( ! empty( $args['search'] ) ) {
		$q['s'] = $args['search'];
	}
	return array_map( 'lccb_page_summary', get_posts( $q ) );
}

function lccb_op_page_read( array $args ) {
	$p = lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'page', 'post' ) );
	return lccb_page_summary( $p ) + array(
		'template' => get_post_meta( $p->ID, '_wp_page_template', true ),
		'html'     => $p->post_content,
		'note'     => lccb_open_in_builder( $p->ID ) ? 'Open in the builder: the builder may have newer, unsaved content (use lc_read_html for that).' : 'Saved content (inside main#lc-main).',
	);
}

function lccb_op_page_create( array $args ) {
	$title = trim( (string) ( isset( $args['title'] ) ? $args['title'] : '' ) );
	if ( '' === $title ) {
		throw new Exception( 'title is required.' );
	}
	$slug     = sanitize_title( ! empty( $args['slug'] ) ? $args['slug'] : $title );
	$status   = in_array( isset( $args['status'] ) ? $args['status'] : 'draft', array( 'draft', 'publish', 'private', 'pending' ), true ) ? ( isset( $args['status'] ) ? $args['status'] : 'draft' ) : 'draft';
	$warnings = array();
	if ( get_page_by_path( $slug ) ) {
		$warnings[] = "A page with the slug \"$slug\" already exists: WordPress will add a suffix.";
	}
	$meta = array( '_lc_livecanvas_enabled' => '1' );
	if ( locate_template( 'page-templates/empty.php' ) ) {
		$meta['_wp_page_template'] = 'page-templates/empty.php'; // LiveCanvas's full-width blank template
	}
	$after = array(
		'post' => array(
			'ID'           => 0,
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => $status,
			'post_parent'  => (int) ( isset( $args['parent'] ) ? $args['parent'] : 0 ),
			'menu_order'   => (int) ( isset( $args['menu_order'] ) ? $args['menu_order'] : 0 ),
			'post_content' => lccb_lc_content( isset( $args['html'] ) ? $args['html'] : '' ),
		),
		'meta' => $meta,
	);
	return lccb_store_preview( 'lc_page_create', 'create', 'page', 0, "Create LiveCanvas page \"$title\" ($status)", null, $after, $warnings );
}

function lccb_op_page_update( array $args ) {
	$p = lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'page', 'post' ) );
	lccb_refuse_if_open( $p->ID, 'Page' );
	$before = lccb_snapshot_post( $p->ID );
	$after  = $before;
	foreach ( array( 'title' => 'post_title', 'status' => 'post_status', 'parent' => 'post_parent', 'menu_order' => 'menu_order' ) as $arg => $field ) {
		if ( isset( $args[ $arg ] ) ) {
			$after['post'][ $field ] = in_array( $field, array( 'post_parent', 'menu_order' ), true ) ? (int) $args[ $arg ] : (string) $args[ $arg ];
		}
	}
	if ( isset( $args['slug'] ) ) {
		$after['post']['post_name'] = sanitize_title( $args['slug'] );
	}
	if ( 'trash' === $after['post']['post_status'] ) {
		return lccb_store_preview( 'lc_page_update', 'trash', 'page', $p->ID, "Move page \"{$p->post_title}\" to the bin", $before, $before );
	}
	lccb_apply_content_args( $after, $args );
	if ( $after === $before ) {
		throw new Exception( 'Nothing to change: pass title, slug, status, parent, menu_order, html, or old_string/new_string.' );
	}
	return lccb_store_preview( 'lc_page_update', 'update', 'page', $p->ID, "Update page \"{$p->post_title}\" (#{$p->ID})", $before, $after );
}

// ───────────────────────── Partials (header / footer / global JS) ─────────────────────────

function lccb_partial_role( $args ) {
	$type = isset( $args['type'] ) ? $args['type'] : '';
	$map  = array( 'header' => 'is_header', 'footer' => 'is_footer', 'global_js' => 'is_global_js' );
	return isset( $map[ $type ] ) ? $map[ $type ] : null;
}

function lccb_find_partial( array $args ) {
	if ( ! empty( $args['id'] ) ) {
		return lccb_require_post( $args['id'], array( 'lc_partial' ) );
	}
	$role = lccb_partial_role( $args );
	if ( ! $role ) {
		throw new Exception( 'Pass type (header, footer, global_js) or the partial id.' );
	}
	$posts = get_posts( array( 'post_type' => 'lc_partial', 'meta_key' => $role, 'meta_value' => '1', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'DESC', 'numberposts' => 1 ) );
	return $posts ? $posts[0] : null;
}

function lccb_op_partial_read( array $args ) {
	$p = lccb_find_partial( $args );
	if ( ! $p ) {
		return array( 'exists' => false, 'note' => 'No such partial yet. lc_partial_update with html creates it.' );
	}
	return array(
		'exists'     => true,
		'id'         => $p->ID,
		'title'      => $p->post_title,
		'roles'      => lccb_flag_meta( $p->ID ),
		'status'     => $p->post_status,
		'editor_url' => lccb_editor_url( $p->ID ),
		'open_in_builder' => lccb_open_in_builder( $p->ID ),
		'html'       => $p->post_content,
	);
}

function lccb_op_partial_update( array $args ) {
	$p = lccb_find_partial( $args );
	if ( ! $p ) {
		$role = lccb_partial_role( $args );
		if ( ! $role || ! isset( $args['html'] ) ) {
			throw new Exception( 'That partial doesn\'t exist yet: pass type and html to create it.' );
		}
		$titles = array( 'is_header' => 'Header', 'is_footer' => 'Footer', 'is_global_js' => 'Global JavaScript Code' );
		$after  = array(
			'post' => array( 'ID' => 0, 'post_type' => 'lc_partial', 'post_title' => $titles[ $role ], 'post_name' => sanitize_title( $titles[ $role ] ), 'post_status' => 'publish', 'post_parent' => 0, 'menu_order' => 0, 'post_content' => 'is_global_js' === $role ? (string) $args['html'] : lccb_lc_content( $args['html'] ) ),
			'meta' => array( $role => '1' ),
		);
		return lccb_store_preview( 'lc_partial_update', 'create', 'partial', 0, 'Create the ' . $titles[ $role ] . ' partial', null, $after );
	}
	lccb_refuse_if_open( $p->ID, 'Partial' );
	$before = lccb_snapshot_post( $p->ID );
	$after  = $before;
	$is_js  = in_array( 'is_global_js', lccb_flag_meta( $p->ID ), true );
	if ( isset( $args['html'] ) && $is_js ) {
		$after['post']['post_content'] = (string) $args['html']; // JS is stored as-is
	} else {
		lccb_apply_content_args( $after, $args );
	}
	if ( isset( $args['title'] ) ) {
		$after['post']['post_title'] = (string) $args['title'];
	}
	if ( $after === $before ) {
		throw new Exception( 'Nothing to change: pass html, or old_string/new_string.' );
	}
	$warnings = $is_js ? array( 'This is the saved Global JS. If a builder tab has unsaved Global JS changes, saving there will overwrite this.' ) : array();
	return lccb_store_preview( 'lc_partial_update', 'update', 'partial', $p->ID, "Update partial \"{$p->post_title}\" (#{$p->ID})", $before, $after, $warnings );
}

// ───────────────────────── Dynamic templates ─────────────────────────

/** Condition keys LiveCanvas's templating understands (see livecanvas/modules/templating*.php). */
function lccb_valid_condition( $key ) {
	return (bool) preg_match( '/^is_(single_[a-z0-9_-]+(__in_[a-z0-9_-]+_[a-z0-9_-]+)?|archive_for_post_type_[a-z0-9_-]+|archive_for_tax_[a-z0-9_-]+(__[a-z0-9_-]+)?|archive_author|archive_date|archive_[a-z0-9_-]+|blog_posts_index|front_page|search|404|shop_page|cart_page|checkout_page|account_page|post_loop)$/', (string) $key );
}

function lccb_op_templates_list( array $args ) {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'lc_dynamic_template', 'post_status' => 'any', 'posts_per_page' => 200, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $p ) {
		$out[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status, 'conditions' => lccb_flag_meta( $p->ID ), 'menu_order' => (int) $p->menu_order, 'editor_url' => lccb_editor_url( $p->ID ), 'modified' => $p->post_modified );
	}
	return array(
		'templates' => $out,
		'conditions_help' => 'is_single_<post_type> (optionally is_single_<type>__in_<taxonomy>_<term>), is_archive_for_post_type_<type>, is_archive_for_tax_<taxonomy>[__<term>], is_archive_author, is_archive_date, is_blog_posts_index, is_front_page, is_search, is_404, is_post_loop, WooCommerce: is_shop_page, is_cart_page, is_checkout_page, is_account_page. Lower menu_order wins when several match.',
	);
}

function lccb_op_template_read( array $args ) {
	$p = lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'lc_dynamic_template' ) );
	return array( 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'conditions' => lccb_flag_meta( $p->ID ), 'menu_order' => (int) $p->menu_order, 'editor_url' => lccb_editor_url( $p->ID ), 'open_in_builder' => lccb_open_in_builder( $p->ID ), 'html' => $p->post_content );
}

function lccb_op_template_upsert( array $args ) {
	$warnings   = array();
	$conditions = isset( $args['conditions'] ) ? array_values( array_unique( array_map( 'strval', (array) $args['conditions'] ) ) ) : null;
	foreach ( (array) $conditions as $c ) {
		if ( ! lccb_valid_condition( $c ) ) {
			$warnings[] = "\"$c\" isn't a condition LiveCanvas is known to use; the template may never be applied.";
		}
	}
	if ( ! empty( $args['id'] ) ) {
		$p = lccb_require_post( $args['id'], array( 'lc_dynamic_template' ) );
		lccb_refuse_if_open( $p->ID, 'Template' );
		$before = lccb_snapshot_post( $p->ID );
		$after  = $before;
		if ( isset( $args['title'] ) ) {
			$after['post']['post_title'] = (string) $args['title'];
		}
		if ( isset( $args['menu_order'] ) ) {
			$after['post']['menu_order'] = (int) $args['menu_order'];
		}
		if ( isset( $args['status'] ) ) {
			$after['post']['post_status'] = (string) $args['status'];
		}
		if ( null !== $conditions ) {
			foreach ( array_keys( $after['meta'] ) as $key ) {
				if ( 0 === strpos( $key, 'is_' ) ) {
					unset( $after['meta'][ $key ] );
				}
			}
			foreach ( $conditions as $c ) {
				$after['meta'][ $c ] = '1';
			}
		}
		lccb_apply_content_args( $after, $args );
		if ( $after === $before ) {
			throw new Exception( 'Nothing to change.' );
		}
		return lccb_store_preview( 'lc_template_upsert', 'update', 'template', $p->ID, "Update dynamic template \"{$p->post_title}\" (#{$p->ID})", $before, $after, $warnings );
	}
	if ( empty( $args['title'] ) || ! $conditions ) {
		throw new Exception( 'A new template needs title and at least one condition.' );
	}
	$meta = array();
	foreach ( $conditions as $c ) {
		$meta[ $c ] = '1';
	}
	$after = array(
		'post' => array( 'ID' => 0, 'post_type' => 'lc_dynamic_template', 'post_title' => (string) $args['title'], 'post_name' => sanitize_title( $args['title'] ), 'post_status' => isset( $args['status'] ) ? (string) $args['status'] : 'publish', 'post_parent' => 0, 'menu_order' => (int) ( isset( $args['menu_order'] ) ? $args['menu_order'] : 0 ), 'post_content' => lccb_lc_content( isset( $args['html'] ) ? $args['html'] : '' ) ),
		'meta' => $meta,
	);
	return lccb_store_preview( 'lc_template_upsert', 'create', 'template', 0, 'Create dynamic template "' . $args['title'] . '" for ' . implode( ', ', $conditions ), null, $after, $warnings );
}

// ───────────────────────── Audit: list / restore ─────────────────────────

function lccb_op_audit_list( array $args ) {
	return lccb_audit_list( isset( $args['limit'] ) ? $args['limit'] : 30, isset( $args['target_type'] ) ? $args['target_type'] : '', isset( $args['target_id'] ) ? $args['target_id'] : 0 );
}

function lccb_op_audit_restore( array $args ) {
	$entry = lccb_audit_get( isset( $args['id'] ) ? $args['id'] : 0 );
	if ( ! $entry ) {
		throw new Exception( 'No audit entry with that id.' );
	}
	$target_id = (int) $entry['target_id'];
	$current   = $target_id ? lccb_snapshot_post( $target_id ) : null;
	if ( ! $current ) {
		throw new Exception( 'The changed item no longer exists.' );
	}
	lccb_refuse_if_open( $target_id, ucfirst( $entry['target_type'] ) );
	if ( null === $entry['before'] ) {
		// That change created the item: restoring it means removing it again (to the bin, so it's recoverable).
		return lccb_store_preview( 'lc_audit_restore', 'trash', $entry['target_type'], $target_id, "Undo #{$entry['id']}: move \"{$current['post']['post_title']}\" (created by that change) to the bin", $current, $current );
	}
	return lccb_store_preview( 'lc_audit_restore', 'update', $entry['target_type'], $target_id, "Undo #{$entry['id']}: restore \"{$current['post']['post_title']}\" to before \"{$entry['summary']}\"", $current, $entry['before'] );
}

// ───────────────────────── Apply ─────────────────────────

function lccb_op_apply_change( array $args ) {
	$id   = isset( $args['preview_id'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $args['preview_id'] ) : '';
	$plan = $id ? get_transient( 'lccb_preview_' . $id ) : false;
	if ( ! $plan ) {
		throw new Exception( 'That preview has expired or was already applied. Preview the change again.' );
	}
	$target_id = (int) $plan['target_id'];
	$current   = $target_id ? lccb_snapshot_post( $target_id ) : null;
	if ( lccb_fingerprint( $current ) !== $plan['fingerprint'] ) {
		delete_transient( 'lccb_preview_' . $id );
		throw new Exception( 'The target changed since this preview (someone or something edited it). Preview the change again.' );
	}
	if ( $target_id ) {
		lccb_refuse_if_open( $target_id, ucfirst( $plan['target_type'] ) );
	}

	if ( 'create' === $plan['kind'] ) {
		$post   = $plan['after']['post'];
		unset( $post['ID'] );
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) ) {
			throw new Exception( $new_id->get_error_message() );
		}
		foreach ( $plan['after']['meta'] as $key => $value ) {
			update_post_meta( $new_id, $key, wp_slash( $value ) );
		}
		$target_id = (int) $new_id;
	} elseif ( 'trash' === $plan['kind'] ) {
		if ( ! wp_trash_post( $target_id ) ) {
			throw new Exception( 'Could not move it to the bin.' );
		}
	} else {
		lccb_restore_post_snapshot( $plan['after'] );
	}
	delete_transient( 'lccb_preview_' . $id );

	$after_snap    = lccb_snapshot_post( $target_id );
	$restored_from = 'lc_audit_restore' === $plan['tool'] && preg_match( '/^Undo #(\d+)/', $plan['summary'], $m ) ? (int) $m[1] : null;
	$audit_id      = lccb_audit_record( $plan['tool'], $plan['target_type'], $target_id, $plan['summary'], $plan['before'], $after_snap, 'claude', $restored_from );

	$out = array(
		'applied'  => true,
		'summary'  => $plan['summary'],
		'audit_id' => $audit_id,
		'undo'     => "lc_audit_restore {\"id\": $audit_id} previews undoing this.",
		'id'       => $target_id,
	);
	if ( 'trash' !== $plan['kind'] ) {
		$out['url']        = get_permalink( $target_id );
		$out['editor_url'] = lccb_editor_url( $target_id );
	}
	return $out;
}

function lccb_op_editor_url( array $args ) {
	$p = get_post( (int) ( isset( $args['id'] ) ? $args['id'] : 0 ) );
	if ( ! $p ) {
		throw new Exception( 'No post with that id.' );
	}
	return array( 'id' => $p->ID, 'title' => $p->post_title, 'editor_url' => lccb_editor_url( $p->ID ), 'livecanvas' => '1' === (string) get_post_meta( $p->ID, '_lc_livecanvas_enabled', true ) || in_array( $p->post_type, array( 'lc_partial', 'lc_dynamic_template' ), true ) );
}
