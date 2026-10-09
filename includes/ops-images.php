<?php
/**
 * The image pipeline: audit every image a page or the site uses, convert/resize to WebP or AVIF (as new media items,
 * with every reference rewritten), crop copies, let Claude look at an image and write its alt text, and find
 * openly licensed stock photos on Openverse.
 *
 * Writes are previewed, then applied and audited. Optimising never touches the original files, so undo is exact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_IMG_HEAVY       = 307200;  // 300 KB
const LCCB_IMG_VERY_HEAVY  = 819200;  // 800 KB
const LCCB_IMG_VIEW_EDGE   = 1200;    // long edge when showing Claude an image
const LCCB_OPENVERSE       = 'https://api.openverse.org/v1/images/';

function lccb_img_admin_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
}

// ───────────────────────── Finding images ─────────────────────────

/** Posts whose markup can reference images: LiveCanvas pages, partials, templates and sections. */
function lccb_img_hosts() {
	$hosts = lccb_section_hosts();
	foreach ( get_posts( array( 'post_type' => 'lc_section', 'post_status' => 'any', 'posts_per_page' => 200 ) ) as $s ) {
		$hosts[] = $s;
	}
	return $hosts;
}

function lccb_img_abs( $url ) {
	$url = trim( html_entity_decode( (string) $url ) );
	if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
		return '';
	}
	if ( 0 === strpos( $url, '//' ) ) {
		return set_url_scheme( $url );
	}
	if ( '/' === $url[0] ) {
		return home_url( $url );
	}
	return $url;
}

/** Attachment id for any URL of it (full, -scaled, or a -WxH size). */
function lccb_img_attachment_id( $url ) {
	static $cache = array();
	$url = preg_replace( '/[?#].*$/', '', $url );
	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}
	$id = attachment_url_to_postid( $url );
	if ( ! $id ) {
		$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url ) );
	}
	if ( ! $id ) {
		$id = attachment_url_to_postid( preg_replace( '/(?=\.\w+$)/', '-scaled', preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url ), 1 ) );
	}
	return $cache[ $url ] = (int) $id;
}

/** Every image reference in some markup. */
function lccb_img_refs( $html ) {
	$refs = array();
	if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
		foreach ( $m[0] as $i => $tag ) {
			$attr = function ( $name ) use ( $tag ) {
				return preg_match( '/\s' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $am ) ? ( isset( $am[3] ) && '' !== $am[3] ? $am[3] : $am[2] ) : null;
			};
			$src = $attr( 'src' ) ?: $attr( 'data-src' );
			if ( ! $src ) {
				continue;
			}
			$refs[] = array(
				'url'     => lccb_img_abs( $src ),
				'kind'    => 'img',
				'tag'     => $tag,
				'alt'     => $attr( 'alt' ),
				'loading' => $attr( 'loading' ),
				'sized'   => null !== $attr( 'width' ) && null !== $attr( 'height' ),
				'index'   => $i,
			);
			foreach ( explode( ',', (string) $attr( 'srcset' ) ) as $cand ) {
				$u = lccb_img_abs( preg_split( '/\s+/', trim( $cand ) )[0] );
				if ( $u ) {
					$refs[] = array( 'url' => $u, 'kind' => 'srcset' );
				}
			}
		}
	}
	if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+\.(?:jpe?g|png|gif|webp|avif))(?:\?[^\'")]*)?[\'"]?\s*\)/i', $html, $m ) ) {
		foreach ( $m[1] as $u ) {
			$refs[] = array( 'url' => lccb_img_abs( $u ), 'kind' => 'background' );
		}
	}
	return array_values( array_filter( $refs, function ( $r ) {
		return '' !== $r['url'];
	} ) );
}

function lccb_img_file_info( $att_id ) {
	$file = get_attached_file( $att_id );
	if ( ! $file || ! is_file( $file ) ) {
		return null;
	}
	$size = @getimagesize( $file );
	return array(
		'file'   => $file,
		'bytes'  => filesize( $file ),
		'width'  => $size ? $size[0] : null,
		'height' => $size ? $size[1] : null,
		'mime'   => $size ? $size['mime'] : get_post_mime_type( $att_id ),
	);
}

// ───────────────────────── Audit ─────────────────────────

/**
 * $args: scope page|site; id (a saved page); html (live HTML of the open page) + label; measured: {url: max rendered px}.
 */
function lccb_op_image_audit( array $args ) {
	$scope    = isset( $args['scope'] ) ? $args['scope'] : 'page';
	$measured = isset( $args['measured'] ) && is_array( $args['measured'] ) ? $args['measured'] : array();
	$sources  = array();
	if ( isset( $args['html'] ) ) {
		$sources[] = array( 'where' => isset( $args['label'] ) ? (string) $args['label'] : 'the open page', 'html' => (string) $args['html'] );
	} elseif ( 'site' === $scope ) {
		foreach ( lccb_img_hosts() as $p ) {
			$sources[] = array( 'where' => sprintf( '%s "%s" (#%d)', $p->post_type, $p->post_title, $p->ID ), 'html' => $p->post_content );
		}
		$sources[] = array( 'where' => 'Global CSS', 'html' => (string) wp_get_custom_css() );
	} else {
		$p         = lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'page', 'post', 'lc_partial', 'lc_dynamic_template', 'lc_section' ) );
		$sources[] = array( 'where' => sprintf( '%s "%s" (#%d)', $p->post_type, $p->post_title, $p->ID ), 'html' => $p->post_content );
	}

	$images = array();
	foreach ( $sources as $src ) {
		$first_img = true;
		foreach ( lccb_img_refs( $src['html'] ) as $r ) {
			$url = preg_replace( '/[?#].*$/', '', $r['url'] );
			$aid = lccb_img_attachment_id( $url );
			$key = $aid ? 'id:' . $aid : $url; // one row per media item, whichever of its sizes a page uses
			if ( ! isset( $images[ $key ] ) ) {
				$images[ $key ] = array( 'url' => $url, 'urls' => array(), 'uses' => array(), 'kinds' => array() );
			}
			$images[ $key ]['urls'][ $url ] = true;
			$images[ $key ]['kinds'][ $r['kind'] ] = true;
			if ( 'img' === $r['kind'] ) {
				$images[ $key ]['uses'][] = array( 'where' => $src['where'], 'alt' => $r['alt'], 'loading' => $r['loading'], 'sized' => $r['sized'], 'first' => $first_img );
				$first_img                = false;
			} elseif ( 'background' === $r['kind'] ) {
				$images[ $key ]['uses'][] = array( 'where' => $src['where'], 'background' => true );
			}
		}
	}

	$out    = array();
	$totals = array( 'images' => 0, 'bytes' => 0, 'savable' => 0 );
	foreach ( $images as $key => $im ) {
		if ( ! $im['uses'] ) {
			continue; // only seen in a srcset: covered by its main image
		}
		$totals['images']++;
		$aid    = 0 === strpos( $key, 'id:' ) ? (int) substr( $key, 3 ) : 0;
		$info   = $aid ? lccb_img_file_info( $aid ) : null;
		$local  = 0 === strpos( $im['url'], home_url() );
		$issues = array();
		if ( $aid && $info ) {
			// Pages may use smaller sizes of the attachment: judge the largest file actually served.
			$served = null;
			$up     = wp_get_upload_dir();
			$shown  = 0;
			foreach ( array_keys( $im['urls'] ) as $u ) {
				$path = 0 === strpos( $u, $up['baseurl'] ) ? $up['basedir'] . substr( $u, strlen( $up['baseurl'] ) ) : null;
				if ( $path && is_file( $path ) && ( ! $served || filesize( $path ) > $served['bytes'] ) ) {
					$sz     = @getimagesize( $path );
					$served = array( 'file' => $path, 'bytes' => filesize( $path ), 'width' => $sz ? $sz[0] : null, 'height' => $sz ? $sz[1] : null, 'mime' => $sz ? $sz['mime'] : $info['mime'] );
				}
				$shown = max( $shown, isset( $measured[ $u ] ) ? (int) $measured[ $u ] : 0 );
			}
			$served           = $served ?: $info;
			$totals['bytes'] += $served['bytes'];
			if ( $served['bytes'] > LCCB_IMG_VERY_HEAVY ) {
				$issues[] = array( 'high', 'very heavy: ' . size_format( $served['bytes'] ) );
			} elseif ( $served['bytes'] > LCCB_IMG_HEAVY ) {
				$issues[] = array( 'medium', 'heavy: ' . size_format( $served['bytes'] ) );
			}
			if ( $shown && $served['width'] > 2.5 * $shown && $served['width'] - 2 * $shown > 300 ) { // well beyond what retina needs
				$issues[] = array( 'medium', "{$served['width']}px wide but shown at most {$shown}px (2× for retina is " . ( 2 * $shown ) . 'px)' );
			} elseif ( ! $shown && $served['width'] > 2560 ) {
				$issues[] = array( 'medium', "{$served['width']}px wide (more than any screen needs)" );
			}
			if ( in_array( $served['mime'], array( 'image/jpeg', 'image/png' ), true ) && $served['bytes'] > 81920 ) {
				$issues[] = array( 'low', strtoupper( str_replace( 'image/', '', $served['mime'] ) ) . ': WebP would typically be 25-50% smaller' );
				$totals['savable'] += (int) ( $served['bytes'] * 0.35 );
			}
		} elseif ( $local ) {
			$issues[] = array( 'medium', 'not in the media library (file not found or added by FTP)' );
		} else {
			$issues[] = array( 'medium', 'hotlinked from another site: import it (lc_media_import) so it can\'t disappear' );
		}
		$alt_att = $aid ? (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) : '';
		foreach ( $im['uses'] as $u ) {
			if ( ! empty( $u['background'] ) ) {
				continue;
			}
			if ( null === $u['alt'] ) {
				$issues[] = array( 'high', "no alt attribute on {$u['where']}" . ( $alt_att ? " (the media library has: \"$alt_att\")" : '' ) );
			}
			if ( ! $u['sized'] ) {
				$issues[] = array( 'low', "no width/height on {$u['where']} (layout shift)" );
			}
			if ( ! $u['first'] && 'lazy' !== strtolower( (string) $u['loading'] ) ) {
				$issues[] = array( 'low', "not lazy-loaded on {$u['where']} (add loading=\"lazy\" unless it's near the top)" );
			}
		}
		$sev = array_map( function ( $i ) {
			return $i[0];
		}, $issues );
		$out[] = array_filter( array(
			'url'       => $im['url'],
			'sizes_used' => count( $im['urls'] ) > 1 ? array_map( 'basename', array_keys( $im['urls'] ) ) : null,
			'id'        => $aid ?: null,
			'file'      => isset( $served ) && $aid && $info ? sprintf( '%s, %d×%d, %s', str_replace( 'image/', '', $served['mime'] ), $served['width'], $served['height'], size_format( $served['bytes'] ) ) : null,
			'shown_at'  => ! empty( $shown ) ? $shown . 'px max' : null,
			'alt'       => $alt_att ?: null,
			'used_on'   => array_values( array_unique( wp_list_pluck( $im['uses'], 'where' ) ) ),
			'issues'    => $issues ? array_map( function ( $i ) {
				return "[{$i[0]}] {$i[1]}";
			}, $issues ) : null,
			'_rank'     => in_array( 'high', $sev, true ) ? 0 : ( in_array( 'medium', $sev, true ) ? 1 : ( $issues ? 2 : 3 ) ),
		), function ( $v ) {
			return null !== $v;
		} );
		unset( $served, $shown );
	}
	usort( $out, function ( $a, $b ) {
		return $a['_rank'] - $b['_rank'];
	} );
	$with_issues = count( array_filter( $out, function ( $i ) {
		return $i['_rank'] < 3;
	} ) );
	foreach ( $out as &$o ) {
		unset( $o['_rank'] );
	}
	unset( $o );
	return array(
		'scope'   => isset( $args['html'] ) ? $sources[0]['where'] : $scope,
		'summary' => sprintf( '%d image(s), %s served, %d with issues; converting JPEG/PNG to WebP would save roughly %s', $totals['images'], size_format( $totals['bytes'] ), $with_issues, size_format( $totals['savable'] ) ),
		'measured' => $measured ? 'displayed sizes measured in the builder at 1440 and 390px' : 'displayed sizes not measured (audit the page open in the builder to compare file size with display size)',
		'images'  => array_slice( $out, 0, 80 ),
		'next'    => 'Fix: lc_image_optimise {ids, format: "webp", max_width} for heavy/oversized/legacy images (rewrites every reference, keeps originals); lc_media_read + lc_media_update for missing alt text; add width/height and loading="lazy" in the markup with lc_edit_html.',
	);
}

// ───────────────────────── Optimise (convert / resize) ─────────────────────────

/** URL → URL for an attachment and all its sizes, to the new attachment's nearest equivalent. */
function lccb_img_url_map( $old_id, $new_id ) {
	$map     = array();
	$old_url = wp_get_attachment_url( $old_id );
	$new_url = wp_get_attachment_url( $new_id );
	$map[ $old_url ] = $new_url;
	$old_meta = wp_get_attachment_metadata( $old_id );
	$new_meta = wp_get_attachment_metadata( $new_id );
	$base_old = trailingslashit( dirname( $old_url ) );
	$base_new = trailingslashit( dirname( $new_url ) );
	if ( ! empty( $old_meta['original_image'] ) ) {
		$map[ $base_old . $old_meta['original_image'] ] = $new_url; // the pre-"-scaled" original
	}
	$reverse = array( $new_url => $old_url );
	foreach ( (array) ( isset( $old_meta['sizes'] ) ? $old_meta['sizes'] : array() ) as $name => $s ) {
		$target = isset( $new_meta['sizes'][ $name ] ) ? $base_new . $new_meta['sizes'][ $name ]['file'] : $new_url;
		$map[ $base_old . $s['file'] ] = $target;
		if ( isset( $new_meta['sizes'][ $name ] ) ) {
			$reverse[ $target ] = $base_old . $s['file']; // same-named sizes swap back exactly
		}
	}
	// Longest first, so "photo.jpg" never replaces inside "photo-768x512.jpg".
	return array( 'map' => $map, 'reverse' => $reverse );
}

function lccb_img_sort_longest( array $map ) {
	uksort( $map, function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	} );
	return $map;
}

/** Apply a URL map (and wp-image-ID classes) to text; also catches root-relative uploads URLs. */
function lccb_img_rewrite( $text, array $map, array $ids = array() ) {
	$home = untrailingslashit( home_url() );
	$pairs = array();
	foreach ( $map as $from => $to ) {
		$pairs[ $from ] = $to;
		if ( 0 === strpos( $from, $home ) ) {
			$pairs[ substr( $from, strlen( $home ) ) ] = substr( $to, strlen( $home ) );
		}
	}
	$text = strtr( $text, $pairs );
	foreach ( $ids as $old => $new ) {
		$text = preg_replace( '/\bwp-image-' . (int) $old . '\b/', 'wp-image-' . (int) $new, $text );
	}
	return $text;
}

function lccb_img_targets( array $args ) {
	$ids = array_map( 'intval', (array) ( isset( $args['ids'] ) ? $args['ids'] : array() ) );
	foreach ( (array) ( isset( $args['urls'] ) ? $args['urls'] : array() ) as $u ) {
		$id = lccb_img_attachment_id( lccb_img_abs( $u ) );
		if ( ! $id ) {
			throw new Exception( "$u isn't in the media library: import it first." );
		}
		$ids[] = $id;
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		throw new Exception( 'Pass ids (attachment ids) or urls of the images.' );
	}
	if ( count( $ids ) > 30 ) {
		throw new Exception( 'At most 30 images per batch.' );
	}
	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			throw new Exception( "#$id isn't an image in the media library." );
		}
	}
	return $ids;
}

/** Encode an image to a temp file the way apply will. @return array{tmp: string, width: int, height: int, bytes: int, mime: string} */
function lccb_img_encode( $file, $format, $max_width, $quality ) {
	lccb_img_admin_includes();
	$ed = wp_get_image_editor( $file );
	if ( is_wp_error( $ed ) ) {
		throw new Exception( basename( $file ) . ': ' . $ed->get_error_message() );
	}
	$size = $ed->get_size();
	if ( $max_width && $size['width'] > $max_width ) {
		$ed->resize( $max_width, null, false );
	}
	$ed->set_quality( $quality );
	$mime = 'keep' === $format ? wp_check_filetype( $file )['type'] : 'image/' . $format;
	if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
		throw new Exception( "This server's image library can't write $mime." );
	}
	$ext = array( 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif' )[ $mime ];
	$tmp = wp_tempnam( 'lccb-img' ) . '.' . $ext;
	$res = $ed->save( $tmp, $mime );
	if ( is_wp_error( $res ) ) {
		throw new Exception( basename( $file ) . ': ' . $res->get_error_message() );
	}
	return array( 'tmp' => $res['path'], 'width' => $res['width'], 'height' => $res['height'], 'bytes' => filesize( $res['path'] ), 'mime' => $mime, 'ext' => $ext );
}

function lccb_op_image_optimise( array $args ) {
	$ids     = lccb_img_targets( $args );
	$format  = isset( $args['format'] ) ? (string) $args['format'] : 'webp';
	$quality = isset( $args['quality'] ) ? max( 40, min( 95, (int) $args['quality'] ) ) : 82;
	$max_w   = isset( $args['max_width'] ) ? max( 200, (int) $args['max_width'] ) : 2560;
	if ( ! in_array( $format, array( 'webp', 'avif', 'keep' ), true ) ) {
		throw new Exception( 'format must be webp, avif or keep.' );
	}
	$lines = array();
	$plan  = array();
	$saved = 0;
	foreach ( $ids as $id ) {
		$file = wp_get_original_image_path( $id ) ?: get_attached_file( $id );
		$enc  = lccb_img_encode( $file, $format, $max_w, $quality );
		@unlink( $enc['tmp'] );
		$cur  = lccb_img_file_info( $id );
		$saved += max( 0, $cur['bytes'] - $enc['bytes'] );
		$lines[] = sprintf( '#%d %s: %s %d×%d %s → %s %d×%d %s (%s)', $id, basename( $cur['file'] ), str_replace( 'image/', '', $cur['mime'] ), $cur['width'], $cur['height'], size_format( $cur['bytes'] ), $enc['ext'], $enc['width'], $enc['height'], size_format( $enc['bytes'] ), $enc['bytes'] < $cur['bytes'] ? '-' . round( 100 - 100 * $enc['bytes'] / max( 1, $cur['bytes'] ) ) . '%' : 'not smaller' );
		$plan[]  = array( 'id' => $id, 'file' => $file );
	}
	// Where they're used (references get rewritten on apply).
	$used = array();
	$open = array();
	foreach ( lccb_img_hosts() as $h ) {
		foreach ( $ids as $id ) {
			$base = preg_replace( '/(-scaled)?\.\w+$/', '', basename( (string) get_attached_file( $id ) ) );
			if ( false !== strpos( $h->post_content, $base ) || preg_match( '/\bwp-image-' . $id . '\b/', $h->post_content ) ) {
				$used[ $h->ID ] = sprintf( '%s "%s" (#%d)', $h->post_type, $h->post_title, $h->ID );
				if ( lccb_open_in_builder( $h->ID ) ) {
					$open[ $h->ID ] = $used[ $h->ID ];
				}
			}
		}
	}
	if ( $open ) {
		throw new Exception( 'These use the images and are open in the builder right now: ' . implode( ', ', $open ) . '. Save and open another page (lc_open_page), then optimise, so the builder doesn\'t overwrite the rewritten URLs.' );
	}
	$id_prev = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id_prev, array(
		'tool' => 'lc_image_optimise', 'kind' => 'image_optimise', 'target_type' => 'image_optimise', 'target_id' => 0,
		'summary' => sprintf( 'Optimise %d image(s) to %s', count( $ids ), 'keep' === $format ? 'their format' : strtoupper( $format ) ),
		'before' => null, 'after' => null, 'fingerprint' => 'img', 'items' => $plan, 'format' => $format, 'quality' => $quality, 'max_width' => $max_w,
	), LCCB_PREVIEW_TTL );
	return array(
		'preview_id'   => $id_prev,
		'applied'      => false,
		'summary'      => sprintf( 'Optimise %d image(s): about %s smaller in total', count( $ids ), size_format( $saved ) ),
		'changes'      => array_merge( $lines, array( 'Each becomes a NEW media item (with all its sizes); originals are kept.', $used ? 'Rewrite references on: ' . implode( ', ', $used ) . ' (+ Global CSS if it uses them)' : 'Not used in any LiveCanvas page, partial or Global CSS yet.' ) ),
		'content_diff' => '',
		'warnings'     => array(),
		'next'         => 'Nothing written. Call lc_apply_change with this preview_id.',
	);
}

function lccb_img_apply_optimise( array $plan, $preview_id, $restored_from ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$new_ids = array();
	$map     = array();
	$reverse = array();
	$id_map  = array();
	try {
		foreach ( $plan['items'] as $it ) {
			$enc  = lccb_img_encode( $it['file'], $plan['format'], $plan['max_width'], $plan['quality'] );
			$dir  = dirname( get_attached_file( $it['id'] ) );
			$name = wp_unique_filename( $dir, preg_replace( '/(-scaled)?\.\w+$/', '', basename( $it['file'] ) ) . '.' . $enc['ext'] );
			$dest = $dir . '/' . $name;
			rename( $enc['tmp'], $dest );
			$old  = get_post( $it['id'] );
			$nid  = wp_insert_attachment( array( 'post_mime_type' => $enc['mime'], 'post_title' => $old->post_title, 'post_excerpt' => $old->post_excerpt, 'post_content' => $old->post_content, 'post_status' => 'inherit' ), $dest, 0, true );
			if ( is_wp_error( $nid ) ) {
				@unlink( $dest );
				throw new Exception( $nid->get_error_message() );
			}
			$new_ids[] = (int) $nid;
			wp_update_attachment_metadata( $nid, wp_generate_attachment_metadata( $nid, $dest ) );
			update_post_meta( $nid, '_wp_attachment_image_alt', get_post_meta( $it['id'], '_wp_attachment_image_alt', true ) );
			update_post_meta( $nid, '_lccb_optimised_from', $it['id'] );
			$m               = lccb_img_url_map( $it['id'], $nid );
			$map             = array_merge( $map, $m['map'] );
			$reverse         = array_merge( $reverse, $m['reverse'] );
			$id_map[ $it['id'] ] = $nid;
		}
	} catch ( Exception $e ) {
		foreach ( $new_ids as $nid ) {
			wp_delete_attachment( $nid, true );
		}
		throw new Exception( 'Optimising failed and was rolled back: ' . $e->getMessage() );
	}
	$changed = lccb_img_rewrite_everywhere( lccb_img_sort_longest( $map ), $id_map );
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( $plan['tool'], 'image_optimise', 0, $plan['summary'], null, array( 'new' => $new_ids, 'map' => $map, 'reverse' => $reverse, 'ids' => $id_map, 'posts' => array_keys( $changed['posts'] ), 'css' => $changed['css'] ), 'claude', $restored_from );
	return array(
		'applied'   => true,
		'live'      => 'Written now: new media items, and references updated on the pages below (no lc_save needed). Reload the builder preview to see them.',
		'new_ids'   => $id_map,
		'rewritten' => array_values( $changed['posts'] ) ?: 'no pages used them',
		'global_css' => $changed['css'] ? 'updated' : null,
		'audit_id'  => $audit_id,
		'undo'      => "lc_audit_restore {\"id\": $audit_id} puts every reference back and deletes the new files (originals were never touched).",
	);
}

/** Rewrite image URLs in every host post and Global CSS. @return array{posts: array<int, string>, css: bool} */
function lccb_img_rewrite_everywhere( array $map, array $id_map ) {
	$posts = array();
	foreach ( lccb_img_hosts() as $h ) {
		$next = lccb_img_rewrite( $h->post_content, $map, $id_map );
		if ( $next !== $h->post_content ) {
			wp_update_post( wp_slash( array( 'ID' => $h->ID, 'post_content' => $next ) ) );
			$posts[ $h->ID ] = sprintf( '%s "%s" (#%d)', $h->post_type, $h->post_title, $h->ID );
		}
	}
	$css     = (string) wp_get_custom_css();
	$new_css = lccb_img_rewrite( $css, $map );
	if ( $new_css !== $css ) {
		wp_update_custom_css_post( $new_css );
	}
	return array( 'posts' => $posts, 'css' => $new_css !== $css );
}

function lccb_img_restore_optimise_preview( array $entry ) {
	$a = $entry['after'];
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array( 'tool' => 'lc_audit_restore', 'kind' => 'image_optimise_undo', 'target_type' => 'image_optimise', 'target_id' => 0, 'summary' => "Undo #{$entry['id']}: {$entry['summary']}", 'before' => null, 'after' => null, 'fingerprint' => 'img', 'undo' => $a ), LCCB_PREVIEW_TTL );
	return array( 'preview_id' => $id, 'applied' => false, 'summary' => "Undo #{$entry['id']}", 'changes' => array( 'Point every reference back at the original images (pages, partials, sections, Global CSS)', 'Delete the ' . count( $a['new'] ) . ' optimised copies' ), 'content_diff' => '', 'warnings' => array(), 'next' => 'Call lc_apply_change with this preview_id.' );
}

function lccb_img_apply_optimise_undo( array $plan, $preview_id, $restored_from ) {
	$a       = $plan['undo'];
	$changed = lccb_img_rewrite_everywhere( lccb_img_sort_longest( $a['reverse'] ), array_flip( array_map( 'intval', $a['ids'] ) ) );
	foreach ( $a['new'] as $nid ) {
		wp_delete_attachment( $nid, true );
	}
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( 'lc_audit_restore', 'image_optimise', 0, $plan['summary'], null, array( 'restored_posts' => array_keys( $changed['posts'] ) ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'References restored and optimised copies deleted.', 'pages' => array_values( $changed['posts'] ), 'audit_id' => $audit_id );
}

// ───────────────────────── Crop ─────────────────────────

function lccb_op_image_crop( array $args ) {
	lccb_img_admin_includes();
	$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		throw new Exception( 'Pass id: an image in the media library.' );
	}
	if ( ! preg_match( '/^(\d+(?:\.\d+)?)\s*[:x\/]\s*(\d+(?:\.\d+)?)$/', (string) ( isset( $args['aspect'] ) ? $args['aspect'] : '' ), $m ) ) {
		throw new Exception( 'aspect like "16:9", "4:3", "1:1" or "3:4".' );
	}
	$ratio = (float) $m[1] / (float) $m[2];
	$file  = wp_get_original_image_path( $id ) ?: get_attached_file( $id );
	$ed    = wp_get_image_editor( $file );
	if ( is_wp_error( $ed ) ) {
		throw new Exception( $ed->get_error_message() );
	}
	$s  = $ed->get_size();
	$fx = isset( $args['focus']['x'] ) ? max( 0, min( 1, (float) $args['focus']['x'] ) ) : 0.5;
	$fy = isset( $args['focus']['y'] ) ? max( 0, min( 1, (float) $args['focus']['y'] ) ) : 0.5;
	$w  = $s['width'];
	$h  = (int) round( $w / $ratio );
	if ( $h > $s['height'] ) {
		$h = $s['height'];
		$w = (int) round( $h * $ratio );
	}
	$x     = (int) round( max( 0, min( $s['width'] - $w, $fx * $s['width'] - $w / 2 ) ) );
	$y     = (int) round( max( 0, min( $s['height'] - $h, $fy * $s['height'] - $h / 2 ) ) );
	$max_w = isset( $args['width'] ) ? max( 200, (int) $args['width'] ) : min( $w, 2560 );
	$dw    = min( $w, $max_w );
	$dh    = (int) round( $dw / $ratio );
	$ed->crop( $x, $y, $w, $h, $dw, $dh );
	$ed->set_quality( 85 );
	// A small preview so Claude can check the framing before creating the media item.
	$view = clone $ed;
	$view->resize( 900, 900, false );
	$tmp  = wp_tempnam( 'lccb-crop' ) . '.jpg';
	$saved = $view->save( $tmp, 'image/jpeg' );
	$data  = is_wp_error( $saved ) ? null : base64_encode( (string) file_get_contents( $saved['path'] ) );
	if ( ! is_wp_error( $saved ) ) {
		@unlink( $saved['path'] );
	}
	$pid = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $pid, array( 'tool' => 'lc_image_crop', 'kind' => 'image_crop', 'target_type' => 'media_batch', 'target_id' => $id, 'summary' => sprintf( 'Crop image #%d to %s', $id, $m[1] . ':' . $m[2] ), 'before' => null, 'after' => null, 'fingerprint' => 'crop', 'file' => $file, 'rect' => array( $x, $y, $w, $h, $dw, $dh ), 'aspect' => $m[1] . 'x' . $m[2] ), LCCB_PREVIEW_TTL );
	return array(
		'preview_id' => $pid,
		'applied'    => false,
		'summary'    => sprintf( 'Crop #%d (%d×%d) to %s: %d×%d from x %d, y %d, saved as %d×%d', $id, $s['width'], $s['height'], $m[1] . ':' . $m[2], $w, $h, $x, $y, $dw, $dh ),
		'changes'    => array( 'Creates a NEW media item (the original is kept).' ),
		'image'      => $data ? array( 'media_type' => 'image/jpeg', 'data' => $data ) : null,
		'next'       => 'Look at the preview image. Adjust focus {x, y} (0-1) and preview again if the subject is cut off; otherwise call lc_apply_change with this preview_id.',
	);
}

function lccb_img_apply_crop( array $plan, $preview_id, $restored_from ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ed = wp_get_image_editor( $plan['file'] );
	if ( is_wp_error( $ed ) ) {
		throw new Exception( $ed->get_error_message() );
	}
	list( $x, $y, $w, $h, $dw, $dh ) = $plan['rect'];
	$ed->crop( $x, $y, $w, $h, $dw, $dh );
	$ed->set_quality( 85 );
	$dir  = dirname( get_attached_file( $plan['target_id'] ) );
	$ext  = pathinfo( $plan['file'], PATHINFO_EXTENSION );
	$name = wp_unique_filename( $dir, preg_replace( '/(-scaled)?\.\w+$/', '', basename( $plan['file'] ) ) . '-' . $plan['aspect'] . '.' . $ext );
	$res  = $ed->save( $dir . '/' . $name );
	if ( is_wp_error( $res ) ) {
		throw new Exception( $res->get_error_message() );
	}
	$old = get_post( $plan['target_id'] );
	$nid = wp_insert_attachment( array( 'post_mime_type' => $res['mime-type'], 'post_title' => $old->post_title . ' (' . str_replace( 'x', ':', $plan['aspect'] ) . ')', 'post_status' => 'inherit' ), $res['path'], 0, true );
	if ( is_wp_error( $nid ) ) {
		throw new Exception( $nid->get_error_message() );
	}
	wp_update_attachment_metadata( $nid, wp_generate_attachment_metadata( $nid, $res['path'] ) );
	update_post_meta( $nid, '_wp_attachment_image_alt', get_post_meta( $plan['target_id'], '_wp_attachment_image_alt', true ) );
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( 'lc_image_crop', 'media_batch', 0, $plan['summary'], null, array( 'attachments' => array( (int) $nid ), 'map' => array() ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'Added to the media library now.', 'audit_id' => $audit_id, 'undo' => "lc_audit_restore {\"id\": $audit_id} deletes it again." ) + lccb_attachment_info( $nid );
}

// ───────────────────────── Look at an image; alt text ─────────────────────────

function lccb_op_media_read( array $args ) {
	lccb_img_admin_includes();
	$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
	if ( ! $id && ! empty( $args['url'] ) ) {
		$id = lccb_img_attachment_id( lccb_img_abs( $args['url'] ) );
	}
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		throw new Exception( 'Pass id or url of an image in the media library.' );
	}
	$file = get_attached_file( $id );
	$ed   = wp_get_image_editor( $file );
	if ( is_wp_error( $ed ) ) {
		throw new Exception( $ed->get_error_message() );
	}
	$edge = isset( $args['max_edge'] ) ? max( 200, min( 1568, (int) $args['max_edge'] ) ) : LCCB_IMG_VIEW_EDGE;
	$ed->resize( $edge, $edge, false );
	$ed->set_quality( 80 );
	$tmp   = wp_tempnam( 'lccb-view' ) . '.jpg';
	$saved = $ed->save( $tmp, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		throw new Exception( $saved->get_error_message() );
	}
	$data = base64_encode( (string) file_get_contents( $saved['path'] ) );
	@unlink( $saved['path'] );
	$p    = get_post( $id );
	$info = lccb_img_file_info( $id );
	return array(
		'image' => array( 'media_type' => 'image/jpeg', 'data' => $data ),
		'id'    => $id,
		'meta'  => array_filter( array(
			'file'        => basename( $file ) . sprintf( ' (%d×%d, %s)', $info['width'], $info['height'], size_format( $info['bytes'] ) ),
			'title'       => $p->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: '(none)',
			'caption'     => $p->post_excerpt,
			'description' => $p->post_content,
		) ),
		'next'  => 'Write alt text that says what the image shows and why it\'s there (one sentence, no "image of"). Save it with lc_media_update.',
	);
}

/** Fill a missing or empty alt on <img> tags of this attachment. @return array{text: string, tags: array} */
function lccb_img_fill_alts( $html, $att_id, $alt ) {
	$urls = array_keys( lccb_img_url_map_self( $att_id ) );
	$tags = array();
	$out  = preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( $urls, $att_id, $alt, &$tags ) {
		$tag  = $m[0];
		$hit  = preg_match( '/\bwp-image-' . $att_id . '\b/', $tag );
		foreach ( $urls as $u ) {
			$hit = $hit || false !== strpos( $tag, $u ) || false !== strpos( $tag, wp_make_link_relative( $u ) );
		}
		if ( ! $hit || preg_match( '/\salt\s*=\s*("[^"]+"|\'[^\']+\')/i', $tag ) ) {
			return $tag; // not this image, or it already has a non-empty alt
		}
		$new    = preg_match( '/\salt\s*=\s*(""|\'\')/i', $tag ) ? preg_replace( '/\salt\s*=\s*(""|\'\')/i', ' alt="' . esc_attr( $alt ) . '"', $tag, 1 ) : preg_replace( '/^<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
		$tags[] = array( $tag, $new );
		return $new;
	}, $html );
	return array( 'text' => $out, 'tags' => $tags );
}

function lccb_img_url_map_self( $id ) {
	$url  = wp_get_attachment_url( $id );
	$meta = wp_get_attachment_metadata( $id );
	$base = trailingslashit( dirname( $url ) );
	$out  = array( $url => true );
	foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $s ) {
		$out[ $base . $s['file'] ] = true;
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$out[ $base . $meta['original_image'] ] = true;
	}
	return $out;
}

function lccb_op_media_update( array $args ) {
	$updates = isset( $args['updates'] ) && is_array( $args['updates'] ) ? $args['updates'] : array( $args );
	$fill    = ! isset( $args['fill_page_alts'] ) || ! empty( $args['fill_page_alts'] );
	$items   = array();
	$lines   = array();
	foreach ( $updates as $u ) {
		$id = (int) ( isset( $u['id'] ) ? $u['id'] : 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			throw new Exception( "#$id isn't in the media library." );
		}
		$p      = get_post( $id );
		$before = array( 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'title' => $p->post_title, 'caption' => $p->post_excerpt, 'description' => $p->post_content );
		$after  = $before;
		foreach ( array( 'alt', 'title', 'caption', 'description' ) as $k ) {
			if ( isset( $u[ $k ] ) ) {
				$after[ $k ] = 'alt' === $k ? sanitize_text_field( $u[ $k ] ) : (string) $u[ $k ];
			}
		}
		foreach ( $after as $k => $v ) {
			if ( $v !== $before[ $k ] ) {
				$lines[] = sprintf( '#%d %s: %s → %s', $id, $k, '' === $before[ $k ] ? '(empty)' : '"' . mb_substr( $before[ $k ], 0, 80 ) . '"', '"' . mb_substr( $v, 0, 120 ) . '"' );
			}
		}
		$pages = array();
		if ( $fill && '' !== $after['alt'] ) {
			foreach ( lccb_img_hosts() as $h ) {
				$r = lccb_img_fill_alts( $h->post_content, $id, $after['alt'] );
				if ( $r['tags'] ) {
					if ( lccb_open_in_builder( $h->ID ) ) {
						throw new Exception( "\"{$h->post_title}\" (#{$h->ID}) uses this image without alt text and is open in the builder: add the alt there with lc_edit_html, or open another page and try again." );
					}
					$pages[ $h->ID ] = count( $r['tags'] );
					$lines[]         = sprintf( '  fill alt on %d <img> in %s "%s" (#%d)', count( $r['tags'] ), $h->post_type, $h->post_title, $h->ID );
				}
			}
		}
		$items[] = array( 'id' => $id, 'before' => $before, 'after' => $after, 'fill' => $fill && '' !== $after['alt'] );
	}
	if ( ! $lines ) {
		throw new Exception( 'Nothing would change.' );
	}
	$pid = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $pid, array( 'tool' => 'lc_media_update', 'kind' => 'media_meta', 'target_type' => 'media_meta', 'target_id' => 0, 'summary' => count( $items ) > 1 ? 'Update ' . count( $items ) . ' images (alt text, titles)' : "Update image #{$items[0]['id']}", 'before' => null, 'after' => null, 'fingerprint' => 'meta', 'items' => $items ), LCCB_PREVIEW_TTL );
	return array( 'preview_id' => $pid, 'applied' => false, 'summary' => 'Update image details' . ( $fill ? ' and fill missing alt attributes on pages' : '' ), 'changes' => $lines, 'content_diff' => '', 'warnings' => array(), 'next' => 'Nothing written. Call lc_apply_change with this preview_id.' );
}

function lccb_img_apply_meta( array $plan, $preview_id, $restored_from ) {
	$filled = array();
	foreach ( $plan['items'] as $it ) {
		lccb_img_set_meta( $it['id'], $it['after'] );
		if ( $it['fill'] ) {
			foreach ( lccb_img_hosts() as $h ) {
				$r = lccb_img_fill_alts( $h->post_content, $it['id'], $it['after']['alt'] );
				if ( $r['tags'] ) {
					wp_update_post( wp_slash( array( 'ID' => $h->ID, 'post_content' => $r['text'] ) ) );
					foreach ( $r['tags'] as $t ) {
						$filled[] = array( 'post' => $h->ID, 'from' => $t[0], 'to' => $t[1] );
					}
				}
			}
		}
	}
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( $plan['tool'], 'media_meta', 0, $plan['summary'], array( 'items' => array_map( function ( $i ) {
		return array( 'id' => $i['id'], 'values' => $i['before'] );
	}, $plan['items'] ) ), array( 'items' => array_map( function ( $i ) {
		return array( 'id' => $i['id'], 'values' => $i['after'] );
	}, $plan['items'] ), 'filled' => $filled ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'Saved now (media library' . ( $filled ? ' and page markup' : '' ) . ').', 'alts_filled_on_pages' => count( $filled ), 'audit_id' => $audit_id, 'undo' => "lc_audit_restore {\"id\": $audit_id} previews undoing this." );
}

function lccb_img_set_meta( $id, array $v ) {
	update_post_meta( $id, '_wp_attachment_image_alt', $v['alt'] );
	wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $v['title'], 'post_excerpt' => $v['caption'], 'post_content' => $v['description'] ) ) );
}

function lccb_img_restore_meta_preview( array $entry ) {
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array( 'tool' => 'lc_audit_restore', 'kind' => 'media_meta_undo', 'target_type' => 'media_meta', 'target_id' => 0, 'summary' => "Undo #{$entry['id']}: {$entry['summary']}", 'before' => null, 'after' => null, 'fingerprint' => 'meta', 'restore' => $entry['before']['items'], 'filled' => isset( $entry['after']['filled'] ) ? $entry['after']['filled'] : array() ), LCCB_PREVIEW_TTL );
	return array( 'preview_id' => $id, 'applied' => false, 'summary' => "Undo #{$entry['id']}", 'changes' => array( 'Put back the previous alt/title/caption on ' . count( $entry['before']['items'] ) . ' image(s)', count( isset( $entry['after']['filled'] ) ? $entry['after']['filled'] : array() ) . ' alt attribute(s) filled on pages are taken out again' ), 'content_diff' => '', 'warnings' => array(), 'next' => 'Call lc_apply_change with this preview_id.' );
}

function lccb_img_apply_meta_undo( array $plan, $preview_id, $restored_from ) {
	foreach ( $plan['restore'] as $it ) {
		lccb_img_set_meta( $it['id'], $it['values'] );
	}
	$by_post = array();
	foreach ( $plan['filled'] as $f ) {
		$by_post[ $f['post'] ][] = $f;
	}
	foreach ( $by_post as $pid => $list ) {
		$p = get_post( $pid );
		if ( ! $p ) {
			continue;
		}
		$text = $p->post_content;
		foreach ( $list as $f ) {
			$text = str_replace( $f['to'], $f['from'], $text );
		}
		if ( $text !== $p->post_content ) {
			wp_update_post( wp_slash( array( 'ID' => $pid, 'post_content' => $text ) ) );
		}
	}
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( 'lc_audit_restore', 'media_meta', 0, $plan['summary'], null, array( 'restored' => count( $plan['restore'] ) ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'Restored now.', 'audit_id' => $audit_id );
}

// ───────────────────────── Stock photos (Openverse) ─────────────────────────

function lccb_op_stock_search( array $args ) {
	$q = trim( (string) ( isset( $args['query'] ) ? $args['query'] : '' ) );
	if ( '' === $q ) {
		throw new Exception( 'Pass a query, e.g. "beauty salon interior".' );
	}
	$params = array( 'q' => $q, 'page_size' => 12, 'page' => max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) ), 'mature' => 'false' );
	$lic    = isset( $args['license'] ) ? $args['license'] : 'commercial';
	if ( 'all' !== $lic ) {
		$params['license_type'] = 'modification' === $lic ? 'commercial,modification' : 'commercial';
	}
	if ( ! empty( $args['orientation'] ) && in_array( $args['orientation'], array( 'wide', 'tall', 'square' ), true ) ) {
		$params['aspect_ratio'] = $args['orientation'];
	}
	$res = wp_remote_get( add_query_arg( $params, LCCB_OPENVERSE ), array( 'timeout' => 20, 'user-agent' => LCCB_MIG_UA ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		throw new Exception( 'Openverse didn\'t answer: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}
	$data    = json_decode( wp_remote_retrieve_body( $res ), true );
	$results = isset( $data['results'] ) ? array_slice( $data['results'], 0, 12 ) : array();
	if ( ! $results ) {
		return array( 'results' => array(), 'next' => 'Nothing found: try broader or different words.' );
	}
	$list = array();
	foreach ( $results as $i => $r ) {
		$list[] = array(
			'n'       => $i + 1,
			'id'      => $r['id'],
			'title'   => mb_substr( (string) $r['title'], 0, 80 ),
			'size'    => ( $r['width'] && $r['height'] ) ? $r['width'] . '×' . $r['height'] : null,
			'license' => strtoupper( 'cc0' === $r['license'] || 'pdm' === $r['license'] ? $r['license'] : 'CC ' . $r['license'] . ' ' . $r['license_version'] ),
			'creator' => $r['creator'],
			'source'  => $r['foreign_landing_url'],
		);
	}
	$sheet = lccb_img_contact_sheet( array_map( function ( $r ) {
		return $r['thumbnail'];
	}, $results ) );
	return array(
		'image'   => $sheet ? array( 'media_type' => 'image/jpeg', 'data' => $sheet ) : null,
		'query'   => $q,
		'total'   => isset( $data['result_count'] ) ? $data['result_count'] : count( $list ),
		'results' => $list,
		'note'    => 'Numbers in the image match n. All are openly licensed (Creative Commons / public domain); CC BY and BY-SA need visible credit, which lc_stock_import stores in the caption. Check the source page before using people\'s faces commercially.',
		'next'    => 'lc_stock_import {id, alt} to add one to the media library.',
	);
}

/** A 4×3 grid of thumbnails, numbered, as base64 JPEG. */
function lccb_img_contact_sheet( array $thumbs ) {
	if ( ! class_exists( 'Imagick' ) && ! function_exists( 'imagecreatetruecolor' ) ) {
		return null;
	}
	$cw  = 300;
	$ch  = 220;
	$gap = 8;
	$cols = 4;
	$rows = (int) ceil( count( $thumbs ) / $cols );
	$img  = imagecreatetruecolor( $cols * $cw + ( $cols + 1 ) * $gap, $rows * $ch + ( $rows + 1 ) * $gap );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 238, 238, 238 ) );
	$white = imagecolorallocate( $img, 255, 255, 255 );
	$dark  = imagecolorallocate( $img, 20, 20, 20 );
	foreach ( $thumbs as $i => $url ) {
		$x   = $gap + ( $i % $cols ) * ( $cw + $gap );
		$y   = $gap + intdiv( $i, $cols ) * ( $ch + $gap );
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'user-agent' => LCCB_MIG_UA, 'redirection' => 5 ) );
		$src = ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ? @imagecreatefromstring( wp_remote_retrieve_body( $res ) ) : false;
		if ( $src ) {
			// Cover the cell, centred.
			$sw    = imagesx( $src );
			$sh    = imagesy( $src );
			$scale = max( $cw / $sw, $ch / $sh );
			$tw    = (int) ( $cw / $scale );
			$th    = (int) ( $ch / $scale );
			imagecopyresampled( $img, $src, $x, $y, (int) ( ( $sw - $tw ) / 2 ), (int) ( ( $sh - $th ) / 2 ), $cw, $ch, $tw, $th );
			imagedestroy( $src );
		}
		imagefilledrectangle( $img, $x, $y, $x + 34, $y + 26, $dark );
		imagestring( $img, 5, $x + ( $i + 1 >= 10 ? 7 : 12 ), $y + 5, (string) ( $i + 1 ), $white );
	}
	ob_start();
	imagejpeg( $img, null, 78 );
	imagedestroy( $img );
	return base64_encode( (string) ob_get_clean() );
}

function lccb_op_stock_import( array $args ) {
	$id = preg_replace( '/[^a-f0-9-]/', '', strtolower( (string) ( isset( $args['id'] ) ? $args['id'] : '' ) ) );
	if ( '' === $id ) {
		throw new Exception( 'Pass the Openverse id from lc_stock_search.' );
	}
	$res = wp_remote_get( LCCB_OPENVERSE . $id . '/', array( 'timeout' => 20, 'user-agent' => LCCB_MIG_UA ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		throw new Exception( 'Couldn\'t look that image up on Openverse.' );
	}
	$r = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $r['url'] ) ) {
		throw new Exception( 'Openverse has no file for that image.' );
	}
	$name   = sanitize_title( mb_substr( (string) $r['title'], 0, 60 ) ) ?: 'stock-photo';
	$result = lccb_op_media_import( array(
		'url'      => $r['url'],
		'title'    => $r['title'],
		'alt'      => isset( $args['alt'] ) ? (string) $args['alt'] : (string) $r['title'],
		'filename' => $name,
	) );
	// Credit: required for CC BY / BY-SA, good manners for the rest.
	$credit = isset( $r['attribution'] ) ? (string) $r['attribution'] : '';
	wp_update_post( array( 'ID' => $result['id'], 'post_excerpt' => $credit ) );
	update_post_meta( $result['id'], '_lccb_source_url', $r['foreign_landing_url'] );
	update_post_meta( $result['id'], '_lccb_license', $r['license'] . ' ' . $r['license_version'] );
	$result['credit']  = $credit;
	$result['license'] = $r['license'] . ' ' . $r['license_version'] . ' (' . $r['license_url'] . ')';
	$result['next']    = in_array( $r['license'], array( 'by', 'by-sa' ), true ) ? 'This licence requires visible credit: show the caption (credit) near the image or on an image credits page.' : 'No credit is legally required for this licence, but it\'s stored in the caption.';
	return $result;
}
