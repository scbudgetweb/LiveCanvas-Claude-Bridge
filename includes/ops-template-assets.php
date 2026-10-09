<?php
/**
 * Import an HTML template's assets so the site keeps working without this plugin:
 *   - its own CSS/JS/fonts (and the files its CSS references) → child theme template-assets/<slug>/, same relative paths
 *   - a managed enqueue block in the child theme's functions.php that loads them (plain PHP, no plugin needed)
 *   - images used by the chosen pages → the media library, with a template-path → URL map for lc_html_template_read
 * Previewed first; applying is audited, and undo removes exactly what was added.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_TPL_MANIFEST_OPTION = 'lccb_tpl_enqueue';
const LCCB_TPL_MAX_IMAGES      = 200;

// Bootstrap comes from Picostrap; jQuery from WordPress.
const LCCB_TPL_PROVIDED = '/(^|\/)(bootstrap(\.bundle)?(\.min)?\.(css|js)|jquery(-\d[\d.]*)?(\.min)?\.js|jquery-migrate[\w.-]*\.js|popper(\.min)?\.js)$|cdn\.jsdelivr\.net\/npm\/bootstrap@|code\.jquery\.com/i';

function lccb_tpl_assets_dir( $slug ) {
	return get_stylesheet_directory() . '/template-assets/' . $slug;
}

/** Everything a set of pages needs. */
function lccb_tpl_collect( array $tpl, array $pages ) {
	$css     = array();
	$js      = array();
	$remote  = array( 'css' => array(), 'js' => array() );
	$images  = array();
	$skipped = array();
	$jquery  = false;
	foreach ( $pages as $page ) {
		$file = lccb_tpl_page_file( $tpl, $page );
		$dom  = lccb_tpl_dom( (string) file_get_contents( $file ) );
		foreach ( $dom->getElementsByTagName( 'link' ) as $l ) {
			if ( false === stripos( $l->getAttribute( 'rel' ), 'stylesheet' ) && 'style' !== $l->getAttribute( 'as' ) ) {
				continue;
			}
			$key = lccb_tpl_asset_key( $tpl, $file, $l->getAttribute( 'href' ) );
			if ( preg_match( '#fonts\.googleapis\.com#', $key ) ) {
				continue; // fonts go through Picostrap's fonts_header_code
			}
			if ( preg_match( LCCB_TPL_PROVIDED, $key ) ) {
				$skipped[ $key ] = 'provided by the theme';
			} elseif ( 0 === strpos( $key, 'http' ) ) {
				$remote['css'][ $key ] = true;
			} elseif ( 0 !== strpos( $key, 'missing:' ) ) {
				$css[ $key ] = true;
			}
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $sc ) {
			if ( ! $sc->getAttribute( 'src' ) ) {
				continue;
			}
			$key = lccb_tpl_asset_key( $tpl, $file, $sc->getAttribute( 'src' ) );
			if ( preg_match( '/jquery/i', $key ) || preg_match( '/plugins(\.min)?\.js$/', $key ) ) {
				$jquery = true;
			}
			if ( preg_match( LCCB_TPL_PROVIDED, $key ) ) {
				$skipped[ $key ] = 'provided by ' . ( preg_match( '/jquery/i', $key ) ? 'WordPress' : 'the theme' );
			} elseif ( 0 === strpos( $key, 'http' ) ) {
				$remote['js'][ $key ] = true;
			} elseif ( 0 !== strpos( $key, 'missing:' ) ) {
				$js[ $key ] = true;
			}
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( $body ) {
			foreach ( lccb_tpl_image_refs( $body ) as $ref ) {
				$key = lccb_tpl_asset_key( $tpl, $file, $ref );
				if ( 0 !== strpos( $key, 'http' ) && 0 !== strpos( $key, 'missing:' ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $key ) ) {
					$images[ $key ] = true;
				}
			}
		}
	}
	// Files the CSS pulls in (fonts, background images, @imports), keeping their relative paths.
	$extra = array();
	$queue = array_keys( $css );
	$seen  = array();
	while ( $queue ) {
		$rel = array_shift( $queue );
		if ( isset( $seen[ $rel ] ) ) {
			continue;
		}
		$seen[ $rel ] = true;
		$f            = $tpl['root'] . '/' . $rel;
		if ( ! is_file( $f ) ) {
			continue;
		}
		$text = (string) file_get_contents( $f );
		if ( preg_match_all( '/@import\s+(?:url\()?\s*[\'"]?([^\'")\s;]+)/i', $text, $m ) ) {
			foreach ( $m[1] as $imp ) {
				$k = lccb_tpl_asset_key( $tpl, $f, $imp );
				if ( 0 !== strpos( $k, 'http' ) && 0 !== strpos( $k, 'missing:' ) ) {
					$extra[ $k ] = true;
					$queue[]     = $k;
				}
			}
		}
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $text, $m ) ) {
			foreach ( $m[1] as $u ) {
				if ( preg_match( '#^(data:|https?:|//|\#)#i', $u ) ) {
					continue;
				}
				$k = lccb_tpl_asset_key( $tpl, $f, $u );
				if ( 0 !== strpos( $k, 'missing:' ) && 0 !== strpos( $k, 'http' ) ) {
					$extra[ $k ] = true;
				}
			}
		}
	}
	return array(
		'css'     => array_keys( $css ),
		'js'      => array_keys( $js ),
		'remote'  => array( 'css' => array_keys( $remote['css'] ), 'js' => array_keys( $remote['js'] ) ),
		'extra'   => array_values( array_diff( array_keys( $extra ), array_keys( $css ) ) ),
		'images'  => array_keys( $images ),
		'skipped' => $skipped,
		'jquery'  => $jquery,
	);
}

function lccb_tpl_image_refs( DOMElement $root ) {
	$refs = array();
	$xp   = new DOMXPath( $root->ownerDocument );
	foreach ( $xp->query( './/*[@src or @data-src or @data-lazy-src or @srcset or @data-srcset or @poster or @style or @data-bg or @data-background or @data-image-src or @href]', $root ) as $n ) {
		foreach ( array( 'src', 'data-src', 'data-lazy-src', 'poster', 'data-bg', 'data-background', 'data-image-src' ) as $a ) {
			if ( $n->getAttribute( $a ) ) {
				$refs[] = $n->getAttribute( $a );
			}
		}
		if ( 'a' === strtolower( $n->tagName ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $n->getAttribute( 'href' ) ) ) {
			$refs[] = $n->getAttribute( 'href' ); // lightbox targets
		}
		foreach ( array( 'srcset', 'data-srcset' ) as $a ) {
			foreach ( array_filter( explode( ',', $n->getAttribute( $a ) ) ) as $part ) {
				$refs[] = preg_split( '/\s+/', trim( $part ) )[0];
			}
		}
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+)/i', $n->getAttribute( 'style' ), $m ) ) {
			$refs = array_merge( $refs, $m[1] );
		}
	}
	return array_unique( array_filter( $refs ) );
}

// ───────────────────────── Preview ─────────────────────────

function lccb_op_html_template_assets( array $args ) {
	$tpl     = lccb_tpl_resolve( isset( $args['path'] ) ? $args['path'] : '' );
	$pages   = array_values( array_filter( (array) ( isset( $args['pages'] ) ? $args['pages'] : array() ) ) );
	$include = array_values( array_intersect( (array) ( isset( $args['include'] ) ? $args['include'] : array( 'css', 'js', 'fonts', 'images' ) ), array( 'css', 'js', 'fonts', 'images' ) ) );
	$scope   = ! empty( $args['scope'] );
	$exclude = array_filter( (array) ( isset( $args['exclude'] ) ? $args['exclude'] : array() ) );
	if ( ! $pages ) {
		throw new Exception( 'Say which template pages you\'re building from (pages: ["index.html", …]); only what they use is imported.' );
	}
	$got = lccb_tpl_collect( $tpl, $pages );
	$drop = function ( $list ) use ( $exclude ) {
		return array_values( array_filter( $list, function ( $rel ) use ( $exclude ) {
			foreach ( $exclude as $x ) {
				if ( false !== stripos( $rel, $x ) ) {
					return false;
				}
			}
			return true;
		} ) );
	};
	$css    = in_array( 'css', $include, true ) ? $drop( $got['css'] ) : array();
	$js     = in_array( 'js', $include, true ) ? $drop( $got['js'] ) : array();
	$extra  = ( in_array( 'css', $include, true ) || in_array( 'fonts', $include, true ) ) ? $drop( $got['extra'] ) : array();
	if ( ! in_array( 'fonts', $include, true ) ) {
		$extra = array_values( array_filter( $extra, function ( $r ) {
			return ! preg_match( '/\.(woff2?|ttf|otf|eot)$/i', $r );
		} ) );
	}
	$images = in_array( 'images', $include, true ) ? $drop( $got['images'] ) : array();
	if ( count( $images ) > LCCB_TPL_MAX_IMAGES ) {
		throw new Exception( count( $images ) . ' images: import fewer pages at a time (max ' . LCCB_TPL_MAX_IMAGES . ').' );
	}
	$map     = get_option( 'lccb_tpl_map_' . $tpl['slug'], array() );
	$new_img = array_values( array_filter( $images, function ( $rel ) use ( $map ) {
		return ! isset( $map[ $rel ] );
	} ) );

	$dir    = lccb_tpl_assets_dir( $tpl['slug'] );
	$copies = array();
	$bytes  = 0;
	foreach ( array_merge( $css, $js, $extra ) as $rel ) {
		$src = $tpl['root'] . '/' . $rel;
		if ( ! is_file( $src ) ) {
			continue;
		}
		$copies[] = $rel;
		$bytes   += filesize( $src );
	}

	$manifest_before = get_option( LCCB_TPL_MANIFEST_OPTION, array() );
	$manifest        = is_array( $manifest_before ) ? $manifest_before : array();
	$entry           = isset( $manifest[ $tpl['slug'] ] ) ? $manifest[ $tpl['slug'] ] : array( 'css' => array(), 'js' => array(), 'remote_css' => array(), 'remote_js' => array(), 'jquery' => false, 'scope' => false );
	$entry['css']        = array_values( array_unique( array_merge( $entry['css'], $css ) ) );
	$entry['js']         = array_values( array_unique( array_merge( $entry['js'], $js ) ) );
	$entry['remote_css'] = in_array( 'css', $include, true ) ? array_values( array_unique( array_merge( $entry['remote_css'], $drop( $got['remote']['css'] ) ) ) ) : $entry['remote_css'];
	$entry['remote_js']  = in_array( 'js', $include, true ) ? array_values( array_unique( array_merge( $entry['remote_js'], $drop( $got['remote']['js'] ) ) ) ) : $entry['remote_js'];
	$entry['jquery']     = $entry['jquery'] || ( $js && $got['jquery'] );
	$entry['scope']      = $entry['scope'] || $scope;
	$manifest[ $tpl['slug'] ] = $entry;

	$block_before = lccb_tpl_read_block();
	$block_after  = lccb_tpl_block_code( $manifest );
	$warnings     = array();
	if ( get_stylesheet_directory() === get_template_directory() ) {
		$warnings[] = 'The active theme isn\'t a child theme: files and the enqueue block go into ' . basename( get_stylesheet_directory() ) . ' and would be lost on a theme update. Use a child theme.';
	}
	if ( $js && $got['jquery'] ) {
		$warnings[] = 'The template\'s scripts use jQuery: they\'re enqueued with WordPress\'s jQuery as a dependency. Check the browser console after importing; drop scripts you don\'t need with exclude.';
	}
	if ( $scope ) {
		$warnings[] = 'Scoped: every rule in the template\'s own CSS is prefixed with .tpl-' . $tpl['slug'] . ', so it only applies inside sections with that class (lc_html_template_read {strategy: "scoped"} adds it). Library CSS (carousels etc.) is copied unchanged.';
	}

	$plan = array(
		'tool'        => 'lc_html_template_assets',
		'kind'        => 'template_assets',
		'target_type' => 'template_assets',
		'target_id'   => 0,
		'summary'     => sprintf( 'Import assets from template "%s" (%s)', $tpl['name'], implode( ', ', array_map( 'basename', $pages ) ) ),
		'slug'        => $tpl['slug'],
		'root'        => $tpl['root'],
		'copies'      => $copies,
		'own_css'     => array_values( array_filter( $css, function ( $r ) {
			return ! lccb_tpl_is_vendor( $r );
		} ) ),
		'scope'       => $scope,
		'images'      => $new_img,
		'manifest'    => $manifest,
		'manifest_before' => $manifest_before,
		'block_before'    => $block_before,
		'block_after'     => $block_after,
		'fingerprint'     => md5( (string) $block_before . wp_json_encode( $manifest_before ) ),
	);
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, $plan, LCCB_PREVIEW_TTL );

	$rel_dir = str_replace( lccb_site_root() . '/', '', $dir );
	$changes = array();
	if ( $copies ) {
		$changes[] = sprintf( 'Copy %d file(s), %s, into %s/ (same relative paths): %s', count( $copies ), size_format( $bytes ), $rel_dir, implode( ', ', array_slice( $copies, 0, 12 ) ) . ( count( $copies ) > 12 ? ', …' : '' ) );
	}
	if ( $entry['remote_css'] || $entry['remote_js'] ) {
		$changes[] = 'Load from CDN: ' . implode( ', ', array_merge( $entry['remote_css'], $entry['remote_js'] ) );
	}
	if ( $new_img ) {
		$changes[] = sprintf( 'Add %d image(s) to the media library%s', count( $new_img ), count( $images ) > count( $new_img ) ? ' (' . ( count( $images ) - count( $new_img ) ) . ' already imported)' : '' );
	}
	$changes[] = ( $block_before ? 'Update' : 'Add' ) . ' the managed enqueue block in ' . basename( get_stylesheet_directory() ) . '/functions.php';
	foreach ( $got['skipped'] as $k => $why ) {
		$changes[] = "Skip $k ($why)";
	}
	return array(
		'preview_id'   => $id,
		'applied'      => false,
		'summary'      => $plan['summary'],
		'changes'      => $changes,
		'content_diff' => lccb_text_diff( (string) $block_before, $block_after, 80 ),
		'warnings'     => $warnings,
		'next'         => 'Nothing has been written. Show the user, then call lc_apply_change with this preview_id. Afterwards lc_html_template_read returns sections with media-library image URLs.',
	);
}

// ───────────────────────── The enqueue block (lives in the child theme) ─────────────────────────

function lccb_tpl_functions_file() {
	return get_stylesheet_directory() . '/functions.php';
}

function lccb_tpl_block_re() {
	return '/\n?' . preg_quote( LCCB_TPL_BLOCK_START, '/' ) . '.*?' . preg_quote( LCCB_TPL_BLOCK_END, '/' ) . '\n?/s';
}

/** The current block text, or null. */
function lccb_tpl_read_block() {
	$f = lccb_tpl_functions_file();
	if ( ! is_file( $f ) || ! preg_match( lccb_tpl_block_re(), (string) file_get_contents( $f ), $m ) ) {
		return null;
	}
	return trim( $m[0] );
}

function lccb_tpl_block_code( array $manifest ) {
	if ( ! $manifest ) {
		return '';
	}
	$v     = gmdate( 'Ymd-His' );
	$lines = array(
		LCCB_TPL_BLOCK_START . ' (written by LC Claude Bridge; loads CSS/JS imported from HTML templates and works without the plugin, so keep it)',
		'add_action( \'wp_enqueue_scripts\', function () {',
		"\t\$dir = get_stylesheet_directory_uri() . '/template-assets/';",
	);
	foreach ( $manifest as $slug => $e ) {
		$lines[] = "\t// $slug";
		$n       = 0;
		foreach ( array_merge( $e['remote_css'], $e['css'] ) as $css ) {
			$n++;
			$src     = 0 === strpos( $css, 'http' ) ? var_export( $css, true ) : "\$dir . " . var_export( $slug . '/' . $css, true );
			$lines[] = sprintf( "\twp_enqueue_style( %s, %s, array(), %s );", var_export( "tpl-$slug-$n", true ), $src, var_export( $v, true ) );
		}
		$prev = ! empty( $e['jquery'] ) ? "'jquery'" : '';
		$n    = 0;
		foreach ( array_merge( $e['remote_js'], $e['js'] ) as $js ) {
			$n++;
			$handle  = "tpl-$slug-js-$n";
			$src     = 0 === strpos( $js, 'http' ) ? var_export( $js, true ) : "\$dir . " . var_export( $slug . '/' . $js, true );
			$lines[] = sprintf( "\twp_enqueue_script( %s, %s, array(%s), %s, true );", var_export( $handle, true ), $src, $prev ? " $prev " : '', var_export( $v, true ) );
			$prev    = var_export( $handle, true ); // keep the template's script order
		}
	}
	$lines[] = '}, 20 );';
	$lines[] = LCCB_TPL_BLOCK_END;
	return implode( "\n", $lines );
}

/** Put a block (or nothing) into functions.php, leaving everything else as it is. */
function lccb_tpl_write_block( $block ) {
	$f       = lccb_tpl_functions_file();
	$current = is_file( $f ) ? (string) file_get_contents( $f ) : "<?php\n";
	$has     = preg_match( lccb_tpl_block_re(), $current );
	if ( '' === (string) $block ) {
		$next = $has ? preg_replace( lccb_tpl_block_re(), "\n", $current, 1 ) : $current;
	} elseif ( $has ) {
		$next = preg_replace( lccb_tpl_block_re(), "\n" . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $block ) . "\n", $current, 1 );
	} else {
		$body = rtrim( $current );
		if ( '?>' === substr( $body, -2 ) ) {
			$next = substr( $body, 0, -2 ) . "\n" . $block . "\n?>\n";
		} else {
			$next = $body . "\n\n" . $block . "\n";
		}
	}
	if ( false === file_put_contents( $f, $next ) ) {
		throw new Exception( "Couldn't write $f." );
	}
	// Never leave the site with a broken functions.php.
	$found = function_exists( 'lccb_find_php' ) ? lccb_find_php() : null;
	$php   = is_array( $found ) ? $found['path'] : $found;
	if ( $php ) {
		$r = lccb_run( array( $php, '-l', $f ), array( 'PATH' => dirname( $php ) . ':/usr/bin:/bin', 'HOME' => lccb_home_dir() ) );
		if ( 0 !== $r['code'] && false === strpos( $r['out'], 'No syntax errors' ) ) {
			file_put_contents( $f, $current );
			throw new Exception( 'The updated functions.php failed a PHP syntax check, so it was put back: ' . trim( $r['out'] . ' ' . $r['err'] ) );
		}
	}
}

// ───────────────────────── Apply / undo ─────────────────────────

function lccb_tpl_apply_assets( array $plan, $preview_id, $restored_from ) {
	if ( md5( (string) lccb_tpl_read_block() . wp_json_encode( get_option( LCCB_TPL_MANIFEST_OPTION, array() ) ) ) !== $plan['fingerprint'] ) {
		delete_transient( 'lccb_preview_' . $preview_id );
		throw new Exception( 'The enqueue block or imported-asset list changed since this preview. Preview again.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$dir         = lccb_tpl_assets_dir( $plan['slug'] );
	$created     = array();
	$attachments = array();
	$own         = array_flip( $plan['own_css'] );
	try {
		return lccb_tpl_apply_assets_steps( $plan, $preview_id, $restored_from, $dir, $own, $created, $attachments );
	} catch ( Exception $e ) {
		// All or nothing: take back whatever this apply already did.
		foreach ( $created as $f ) {
			@unlink( $f );
		}
		foreach ( $attachments as $aid ) {
			wp_delete_attachment( $aid, true );
		}
		if ( lccb_tpl_read_block() !== $plan['block_before'] ) {
			try {
				lccb_tpl_write_block( (string) $plan['block_before'] );
			} catch ( Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}
		throw new Exception( 'Import failed and was rolled back: ' . $e->getMessage() );
	}
}

function lccb_tpl_apply_assets_steps( array $plan, $preview_id, $restored_from, $dir, array $own, array &$created, array &$attachments ) {
	foreach ( $plan['copies'] as $rel ) {
		$src  = $plan['root'] . '/' . $rel;
		$dest = $dir . '/' . $rel;
		if ( ! is_file( $src ) ) {
			continue;
		}
		wp_mkdir_p( dirname( $dest ) );
		$existed = is_file( $dest );
		if ( $plan['scope'] && isset( $own[ $rel ] ) ) {
			file_put_contents( $dest, lccb_tpl_scope_css( (string) file_get_contents( $src ), '.tpl-' . $plan['slug'] ) );
		} else {
			copy( $src, $dest );
		}
		if ( ! $existed ) {
			$created[] = $dest;
		}
	}

	$map_key     = 'lccb_tpl_map_' . $plan['slug'];
	$map_before  = get_option( $map_key, array() );
	$map         = is_array( $map_before ) ? $map_before : array();
	$failed      = array();
	foreach ( $plan['images'] as $rel ) {
		$src = $plan['root'] . '/' . $rel;
		if ( ! is_file( $src ) || isset( $map[ $rel ] ) ) {
			continue;
		}
		$tmp = wp_tempnam( basename( $src ) );
		copy( $src, $tmp );
		$aid = media_handle_sideload( array( 'name' => sanitize_file_name( basename( $src ) ), 'tmp_name' => $tmp ), 0 );
		if ( is_file( $tmp ) ) {
			@unlink( $tmp );
		}
		if ( is_wp_error( $aid ) ) {
			$failed[] = $rel . ': ' . $aid->get_error_message();
			continue;
		}
		update_post_meta( $aid, '_lccb_tpl_src', $plan['slug'] . ':' . $rel );
		$attachments[] = (int) $aid;
		$map[ $rel ]   = wp_get_attachment_url( $aid );
	}

	lccb_tpl_write_block( $plan['block_after'] );
	update_option( LCCB_TPL_MANIFEST_OPTION, $plan['manifest'], false );
	update_option( $map_key, $map, false );
	delete_transient( 'lccb_preview_' . $preview_id );

	$audit_id = lccb_audit_record( $plan['tool'], 'template_assets', 0, $plan['summary'],
		array( 'block' => $plan['block_before'], 'manifest' => $plan['manifest_before'], 'map' => $map_before, 'slug' => $plan['slug'] ),
		array( 'block' => $plan['block_after'], 'manifest' => $plan['manifest'], 'map' => $map, 'slug' => $plan['slug'], 'files' => $created, 'attachments' => $attachments ),
		'claude', $restored_from );
	return array(
		'applied'   => true,
		'live'      => 'Written to the site now (child theme files, functions.php and the media library): no lc_save needed.',
		'summary'   => $plan['summary'],
		'files'     => count( $created ),
		'images'    => count( $attachments ),
		'failed'    => $failed ?: null,
		'audit_id'  => $audit_id,
		'undo'      => "lc_audit_restore {\"id\": $audit_id} previews removing these files, images and enqueue lines again.",
		'next'      => 'Read sections again with lc_html_template_read: images now point at the media library. Reload the builder preview so the new CSS/JS load.',
	);
}

function lccb_tpl_restore_preview( array $entry ) {
	$after = $entry['after'];
	if ( ! is_array( $after ) || empty( $after['slug'] ) ) {
		throw new Exception( 'That audit entry has nothing to undo.' );
	}
	$files = array_values( array_filter( isset( $after['files'] ) ? $after['files'] : array(), 'is_file' ) );
	$atts  = array_values( array_filter( isset( $after['attachments'] ) ? $after['attachments'] : array(), function ( $id ) {
		return (bool) get_post( $id );
	} ) );
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array(
		'tool' => 'lc_audit_restore', 'kind' => 'template_assets_undo', 'target_type' => 'template_assets', 'target_id' => 0,
		'summary' => "Undo #{$entry['id']}: {$entry['summary']}", 'before' => null, 'after' => null, 'fingerprint' => 'tpl',
		'slug' => $after['slug'], 'files' => $files, 'attachments' => $atts,
		'block' => $entry['before']['block'], 'manifest' => $entry['before']['manifest'], 'map' => $entry['before']['map'],
	), LCCB_PREVIEW_TTL );
	$changes = array();
	if ( $files ) {
		$changes[] = 'Delete ' . count( $files ) . ' file(s) copied into the child theme';
	}
	if ( $atts ) {
		$changes[] = 'Delete ' . count( $atts ) . ' imported image(s) from the media library (pages still using them will show broken images)';
	}
	$changes[] = $entry['before']['block'] ? 'Put the enqueue block back as it was before' : 'Remove the enqueue block from functions.php';
	return array( 'preview_id' => $id, 'applied' => false, 'summary' => "Undo #{$entry['id']}", 'changes' => $changes, 'content_diff' => lccb_text_diff( (string) lccb_tpl_read_block(), (string) $entry['before']['block'], 80 ), 'warnings' => array(), 'next' => 'Call lc_apply_change with this preview_id to undo.' );
}

function lccb_tpl_apply_restore( array $plan, $preview_id, $restored_from ) {
	$theme = realpath( get_stylesheet_directory() );
	foreach ( $plan['files'] as $f ) {
		$real = realpath( $f );
		if ( $real && 0 === strpos( $real, $theme . '/template-assets/' ) ) {
			@unlink( $real );
		}
	}
	// Remove folders left empty, deepest first.
	$base = lccb_tpl_assets_dir( $plan['slug'] );
	if ( is_dir( $base ) ) {
		$dirs = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $p ) {
			if ( $p->isDir() ) {
				@rmdir( $p->getPathname() );
			}
		}
		@rmdir( $base );
		@rmdir( dirname( $base ) );
	}
	foreach ( $plan['attachments'] as $aid ) {
		wp_delete_attachment( $aid, true );
	}
	lccb_tpl_write_block( (string) $plan['block'] );
	update_option( LCCB_TPL_MANIFEST_OPTION, is_array( $plan['manifest'] ) ? $plan['manifest'] : array(), false );
	update_option( 'lccb_tpl_map_' . $plan['slug'], is_array( $plan['map'] ) ? $plan['map'] : array(), false );
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( 'lc_audit_restore', 'template_assets', 0, $plan['summary'], null, array( 'slug' => $plan['slug'], 'removed_files' => count( $plan['files'] ), 'removed_images' => count( $plan['attachments'] ) ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'Removed from the site now.', 'summary' => $plan['summary'], 'audit_id' => $audit_id );
}

// ───────────────────────── Scoping CSS ─────────────────────────

/**
 * Prefix every selector with $prefix, so a template's CSS only applies inside elements with that class.
 * html/body/:root become the wrapper itself. @media/@supports/@layer/@container are recursed into;
 * @font-face/@keyframes/@page/@property are left as they are. Rules nested inside a rule (CSS nesting) are relative already.
 */
function lccb_tpl_scope_css( $css, $prefix ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$out = '';
	$len = strlen( $css );
	$i   = 0;
	while ( $i < $len ) {
		$open = strpos( $css, '{', $i );
		$semi = strpos( $css, ';', $i );
		if ( false === $open ) {
			$out .= substr( $css, $i );
			break;
		}
		// A statement at-rule (@import, @charset, @layer a, b;) before the next block.
		if ( false !== $semi && $semi < $open && '@' === ltrim( substr( $css, $i, $semi - $i ) )[0] ) {
			$out .= substr( $css, $i, $semi - $i + 1 );
			$i    = $semi + 1;
			continue;
		}
		$head  = trim( substr( $css, $i, $open - $i ) );
		$close = lccb_tpl_match_brace( $css, $open );
		$body  = substr( $css, $open + 1, $close - $open - 1 );
		if ( '' !== $head && '@' === $head[0] ) {
			if ( preg_match( '/^@(media|supports|layer|container|document)\b/i', $head ) ) {
				$out .= $head . '{' . lccb_tpl_scope_css( $body, $prefix ) . '}';
			} else {
				$out .= $head . '{' . $body . '}';
			}
		} elseif ( '' !== $head ) {
			$out .= lccb_tpl_scope_selector_list( $head, $prefix ) . '{' . $body . '}';
		}
		$i = $close + 1;
	}
	return $out;
}

function lccb_tpl_match_brace( $s, $open ) {
	$depth = 0;
	$len   = strlen( $s );
	$quote = null;
	for ( $j = $open; $j < $len; $j++ ) {
		$ch = $s[ $j ];
		if ( $quote ) {
			if ( '\\' === $ch ) {
				$j++;
			} elseif ( $ch === $quote ) {
				$quote = null;
			}
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
		} elseif ( '{' === $ch ) {
			$depth++;
		} elseif ( '}' === $ch && 0 === --$depth ) {
			return $j;
		}
	}
	return $len - 1;
}

function lccb_tpl_scope_selector_list( $list, $prefix ) {
	$parts = array();
	$depth = 0;
	$cur   = '';
	foreach ( str_split( $list ) as $ch ) {
		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch || ']' === $ch ) {
			$depth--;
		}
		if ( ',' === $ch && 0 === $depth ) {
			$parts[] = $cur;
			$cur     = '';
		} else {
			$cur .= $ch;
		}
	}
	$parts[] = $cur;
	return implode( ',', array_map( function ( $sel ) use ( $prefix ) {
		$sel = trim( $sel );
		if ( '' === $sel || 0 === strpos( $sel, $prefix ) ) {
			return $sel;
		}
		if ( preg_match( '/^(from|to|\d+%)$/', $sel ) ) {
			return $sel; // keyframe steps (if a @keyframes slipped through)
		}
		// html / body / :root / :host are the wrapper itself.
		$scoped = preg_replace( '/^(html|:root|:host)(\s+body)?(?=$|[\s.:#\[>+~])/i', $prefix, $sel, 1, $n );
		if ( ! $n ) {
			$scoped = preg_replace( '/^body(?=$|[\s.:#\[>+~])/i', $prefix, $sel, 1, $n );
		}
		return $n ? $scoped : $prefix . ' ' . $sel;
	}, $parts ) );
}
