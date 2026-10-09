<?php
/**
 * Start from an HTML template: scan a local folder or .zip, read its sections as LiveCanvas-ready HTML
 * (converted to Bootstrap 5 where that's mechanical), and import its assets so the site keeps working
 * after this plugin is removed (CSS/JS/fonts in the child theme + a managed enqueue block; images in the media library).
 *
 * The template itself is never modified. A zip is extracted under uploads/lccb-template-src/; a folder is linked there,
 * so the builder tab can render the original pages (same origin) for visual comparison.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_TPL_SRC_DIR    = 'lccb-template-src';
const LCCB_TPL_SKIP_DIRS  = array( 'node_modules', '.git', '__macosx', 'documentation', 'docs', 'src', 'ajax', 'master', 'php', '.idea', '.vscode', 'psd', 'figma' );
const LCCB_TPL_MAX_FILES  = 40000;
const LCCB_TPL_DETAIL_MAX = 40;
const LCCB_TPL_BLOCK_START = '// lccb:template-assets:start';
const LCCB_TPL_BLOCK_END   = '// lccb:template-assets:end';

// ───────────────────────── Locating a template ─────────────────────────

function lccb_tpl_src_root() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . LCCB_TPL_SRC_DIR;
}

function lccb_tpl_src_url() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['baseurl'] ) . LCCB_TPL_SRC_DIR;
}

function lccb_tpl_slug( $path ) {
	$path = rtrim( $path, '/' );
	$name = preg_replace( '/\.zip$/i', '', basename( $path ) );
	if ( preg_match( '/^(template|templates|html|dist|site|www|public|build|src|theme)$/i', $name ) ) {
		$name = basename( dirname( $path ) ) . '-' . $name; // ~/sarah/template → "sarah-template"
	}
	$name = preg_replace( '/^themeforest-[A-Za-z0-9]+-/', '', $name );
	$slug = sanitize_title( $name );
	return substr( $slug ? $slug : 'template', 0, 40 );
}

/**
 * Resolve a user-given folder or zip to {name, slug, source, root (folder holding the HTML pages), url}.
 * @throws Exception
 */
function lccb_tpl_resolve( $path ) {
	$path = trim( (string) $path );
	if ( '' === $path ) {
		throw new Exception( 'Give the template\'s folder or .zip path on this Mac.' );
	}
	if ( 0 === strpos( $path, '~/' ) ) {
		$path = lccb_home_dir() . substr( $path, 1 );
	} elseif ( '/' !== $path[0] && function_exists( 'lccb_tpl_library_lookup' ) ) {
		$path = lccb_tpl_library_lookup( $path ); // a template's name in the library
	}
	$real = realpath( $path );
	if ( ! $real || ! is_readable( $real ) ) {
		throw new Exception( "Can't find or read $path." );
	}
	$home = lccb_home_dir();
	if ( 0 !== strpos( $real, $home . '/' ) ) {
		throw new Exception( 'Templates must be somewhere in your home folder.' );
	}
	$lib_name = function_exists( 'lccb_tpl_library_name_for' ) ? lccb_tpl_library_name_for( $real ) : null;
	$slug     = $lib_name ? $lib_name : lccb_tpl_slug( $real );
	$src      = $real;
	if ( is_file( $real ) ) {
		if ( ! preg_match( '/\.zip$/i', $real ) ) {
			throw new Exception( 'That file isn\'t a .zip. Give a folder or a .zip.' );
		}
		$src = lccb_tpl_extract( $real, $slug );
	}
	$root = lccb_tpl_html_root( $src );
	if ( ! $root ) {
		throw new Exception( 'No .html pages found in that template.' );
	}
	$link = lccb_tpl_link( $slug, $root );
	return array(
		'name'   => basename( $real ),
		'slug'   => $slug,
		'source' => $real,
		'root'   => $root,
		'url'    => $link ? lccb_tpl_src_url() . '/' . $slug : null,
	);
}

/** Extract a zip once (and a single zip nested inside it, as ThemeForest downloads often are). */
function lccb_tpl_extract( $zip, $slug ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		throw new Exception( 'PHP\'s zip extension is missing; unzip the template and give the folder instead.' );
	}
	$dest = lccb_tpl_src_root() . '/_zip/' . $slug . '-' . substr( md5( $zip . filemtime( $zip ) ), 0, 8 );
	if ( ! is_dir( $dest ) ) {
		lccb_tpl_unzip( $zip, $dest );
		$found = lccb_tpl_html_root( $dest );
		if ( ! $found ) {
			$inner = glob( $dest . '/{,*/}*.zip', GLOB_BRACE );
			if ( $inner && 1 === count( $inner ) ) {
				lccb_tpl_unzip( $inner[0], $dest . '/_inner' );
			}
		}
	}
	return $dest;
}

function lccb_tpl_unzip( $zip, $dest ) {
	$za = new ZipArchive();
	if ( true !== $za->open( $zip ) ) {
		throw new Exception( 'Couldn\'t open that zip.' );
	}
	$total = 0;
	for ( $i = 0; $i < $za->numFiles; $i++ ) {
		$st   = $za->statIndex( $i );
		$name = $st['name'];
		if ( false !== strpos( $name, '..' ) || '/' === $name[0] || preg_match( '/^[a-z]:/i', $name ) ) {
			$za->close();
			throw new Exception( 'That zip contains unsafe paths; unzip it yourself and give the folder.' );
		}
		$total += $st['size'];
	}
	if ( $total > 1536 * 1024 * 1024 ) {
		$za->close();
		throw new Exception( 'That zip unpacks to more than 1.5 GB; unzip it yourself and give the folder with the HTML.' );
	}
	wp_mkdir_p( $dest );
	$ok = $za->extractTo( $dest );
	$za->close();
	if ( ! $ok ) {
		throw new Exception( 'Couldn\'t extract that zip.' );
	}
}

/** The folder with the most .html pages (shallowest wins a tie). */
function lccb_tpl_html_root( $dir ) {
	$counts = array();
	foreach ( lccb_tpl_walk( $dir, 4 ) as $f ) {
		if ( preg_match( '/\.html?$/i', $f ) ) {
			$d            = dirname( $f );
			$counts[ $d ] = ( isset( $counts[ $d ] ) ? $counts[ $d ] : 0 ) + 1;
		}
	}
	if ( ! $counts ) {
		return null;
	}
	uksort( $counts, function ( $a, $b ) use ( $counts ) {
		return $counts[ $b ] - $counts[ $a ] ?: substr_count( $a, '/' ) - substr_count( $b, '/' );
	} );
	return array_keys( $counts )[0];
}

/** Files under a folder, skipping build/source/doc folders. */
function lccb_tpl_walk( $dir, $max_depth = 8, $skip = true ) {
	$out   = array();
	$stack = array( array( $dir, 0 ) );
	while ( $stack && count( $out ) < LCCB_TPL_MAX_FILES ) {
		list( $d, $depth ) = array_pop( $stack );
		$items             = @scandir( $d );
		if ( ! $items ) {
			continue;
		}
		foreach ( $items as $it ) {
			if ( '.' === $it[0] ) {
				continue;
			}
			$p = $d . '/' . $it;
			if ( is_dir( $p ) ) {
				if ( $depth < $max_depth && ( ! $skip || ! in_array( strtolower( $it ), LCCB_TPL_SKIP_DIRS, true ) ) && '_zip' !== $it ) {
					$stack[] = array( $p, $depth + 1 );
				}
			} else {
				$out[] = $p;
			}
		}
	}
	return $out;
}

/** Link the template's HTML folder into uploads, so the builder can render its pages on this origin. */
function lccb_tpl_link( $slug, $root ) {
	$base = lccb_tpl_src_root();
	wp_mkdir_p( $base );
	if ( ! is_file( $base . '/index.php' ) ) {
		file_put_contents( $base . '/index.php', "<?php // Silence.\n" );
	}
	$link = $base . '/' . $slug;
	if ( is_link( $link ) ) {
		if ( readlink( $link ) === $root ) {
			return true;
		}
		unlink( $link );
	} elseif ( file_exists( $link ) ) {
		return realpath( $link ) === realpath( $root );
	}
	return @symlink( $root, $link );
}

/**
 * Delete uploads/lccb-template-src without ever following a link: the folder links to the user's own template folders.
 * @return bool
 */
function lccb_tpl_remove_src() {
	$base = lccb_tpl_src_root();
	if ( ! file_exists( $base ) && ! is_link( $base ) ) {
		return true;
	}
	$rm = function ( $path ) use ( &$rm ) {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return @unlink( $path );
		}
		foreach ( (array) @scandir( $path ) as $it ) {
			if ( '.' !== $it && '..' !== $it ) {
				$rm( $path . '/' . $it );
			}
		}
		return @rmdir( $path );
	};
	return $rm( $base );
}

/** The template's preview URL for a page (sets up the link if needed). */
function lccb_op_html_template_locate( array $args ) {
	$tpl  = lccb_tpl_resolve( isset( $args['path'] ) ? $args['path'] : '' );
	$file = lccb_tpl_page_file( $tpl, isset( $args['page'] ) ? $args['page'] : 'index.html' );
	if ( ! $tpl['url'] ) {
		throw new Exception( 'Couldn\'t link the template into uploads for previewing.' );
	}
	$rel = lccb_tpl_rel( $tpl, $file );
	return array( 'url' => $tpl['url'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ), 'page' => $rel );
}

/** A page path inside the template root (refuses anything outside it). */
function lccb_tpl_page_file( array $tpl, $page ) {
	$page = ltrim( str_replace( '\\', '/', (string) $page ), '/' );
	$file = realpath( $tpl['root'] . '/' . ( '' === $page ? 'index.html' : $page ) );
	if ( ! $file || 0 !== strpos( $file, realpath( $tpl['root'] ) . '/' ) || ! is_file( $file ) ) {
		throw new Exception( "No page \"$page\" in the template (paths are relative to {$tpl['root']})." );
	}
	return $file;
}

function lccb_tpl_rel( array $tpl, $file ) {
	return ltrim( substr( $file, strlen( realpath( $tpl['root'] ) ) ), '/' );
}

// ───────────────────────── Parsing ─────────────────────────

function lccb_tpl_dom( $html ) {
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	if ( ! preg_match( '/<meta[^>]+charset/i', $html ) ) {
		$html = '<?xml encoding="utf-8"?>' . $html;
	}
	$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	return $dom;
}

function lccb_tpl_kids( DOMNode $n ) {
	$out = array();
	foreach ( $n->childNodes as $c ) {
		if ( XML_ELEMENT_NODE === $c->nodeType && ! in_array( strtolower( $c->tagName ), array( 'script', 'style', 'noscript', 'template', 'link', 'meta' ), true ) ) {
			$out[] = $c;
		}
	}
	return $out;
}

function lccb_tpl_has_class( DOMElement $el, $re ) {
	return (bool) preg_match( $re, ' ' . $el->getAttribute( 'class' ) . ' ' );
}

/** Header, main and footer of a template page. */
function lccb_tpl_regions( DOMDocument $dom ) {
	$xp     = new DOMXPath( $dom );
	$body   = $dom->getElementsByTagName( 'body' )->item( 0 );
	$q      = function ( $expr ) use ( $xp, $body ) {
		$r = $xp->query( $expr, $body );
		return $r && $r->length ? $r->item( 0 ) : null;
	};
	$header = $q( './/header[1]' ) ?: $q( './/*[@id="header" or @role="banner"]' );
	$footer = $q( '(.//footer)[last()]' ) ?: $q( './/*[@id="footer" or @role="contentinfo"]' );
	$main   = $q( './/main' ) ?: $q( './/*[@role="main"]' ) ?: $q( './/*[@id="main" or contains(concat(" ", normalize-space(@class), " "), " main ")]' ) ?: $q( './/*[contains(concat(" ", normalize-space(@class), " "), " content-wrapper ") or @id="content" or @id="wrapper"]' );
	if ( ! $main ) {
		$main = $body;
	}
	// Descend through wrappers: a lone content child (div.wrapper > div.content > sections…), or, when there's no
	// main element, the one child that holds the header/footer (body > div.page > header, sections, footer).
	for ( $i = 0; $i < 4; $i++ ) {
		$kids    = lccb_tpl_kids( $main );
		$content = array_values( array_filter( $kids, function ( $k ) use ( $header, $footer ) {
			foreach ( array( $header, $footer ) as $hf ) {
				if ( $hf && ( $k === $hf || lccb_tpl_contains( $k, $hf ) ) ) {
					return false;
				}
			}
			return true;
		} ) );
		// A wrapper that holds the header/footer together with the content (body > div.page > header, sections…).
		$wrappers = array_values( array_filter( $kids, function ( $k ) use ( $header, $footer ) {
			return ( $header && lccb_tpl_contains( $k, $header ) ) || ( $footer && lccb_tpl_contains( $k, $footer ) );
		} ) );
		if ( 1 === count( $wrappers ) && count( lccb_tpl_kids( $wrappers[0] ) ) > 1 ) {
			$main = $wrappers[0];
			continue;
		}
		if ( 1 === count( $content ) && ! in_array( strtolower( $content[0]->tagName ), array( 'section', 'article' ), true ) && count( lccb_tpl_kids( $content[0] ) ) > 1 ) {
			$main = $content[0];
			continue;
		}
		break;
	}
	return array( 'body' => $body, 'header' => $header, 'main' => $main, 'footer' => $footer );
}

function lccb_tpl_contains( DOMNode $a, DOMNode $b ) {
	for ( $n = $b->parentNode; $n; $n = $n->parentNode ) {
		if ( $n === $a ) {
			return true;
		}
	}
	return false;
}

/** The page's content sections (in order). */
function lccb_tpl_sections( array $r ) {
	$out = array();
	foreach ( lccb_tpl_kids( $r['main'] ) as $k ) {
		if ( $k === $r['header'] || $k === $r['footer'] || ( $r['header'] && lccb_tpl_contains( $k, $r['header'] ) ) || ( $r['footer'] && lccb_tpl_contains( $k, $r['footer'] ) ) ) {
			continue;
		}
		if ( $r['header'] && lccb_tpl_contains( $r['header'], $k ) ) {
			continue;
		}
		if ( lccb_tpl_has_class( $k, '/\s(modal|offcanvas|d-none|hidden|sr-only|visually-hidden|preloader|loader|back-to-top|scroll-to-top|progress-wrap)\s/i' ) || 'true' === $k->getAttribute( 'aria-hidden' ) ) {
			continue;
		}
		if ( '' === trim( $k->textContent ) && ! $k->getElementsByTagName( 'img' )->length ) {
			continue;
		}
		$out[] = $k;
	}
	return $out;
}

/** A CSS selector for an element that a browser resolves the same way (ids, else tag:nth-of-type from body). */
function lccb_tpl_selector( DOMElement $el ) {
	$parts = array();
	for ( $n = $el; $n && XML_ELEMENT_NODE === $n->nodeType && 'html' !== strtolower( $n->tagName ); $n = $n->parentNode ) {
		$tag = strtolower( $n->tagName );
		if ( 'body' === $tag ) {
			array_unshift( $parts, 'body' );
			break;
		}
		$id = $n->getAttribute( 'id' );
		if ( $id && preg_match( '/^[A-Za-z][\w-]*$/', $id ) ) {
			array_unshift( $parts, '#' . $id );
			break;
		}
		$i = 1;
		for ( $s = $n->previousSibling; $s; $s = $s->previousSibling ) {
			if ( XML_ELEMENT_NODE === $s->nodeType && strtolower( $s->tagName ) === $tag ) {
				$i++;
			}
		}
		array_unshift( $parts, "$tag:nth-of-type($i)" );
	}
	return implode( ' > ', $parts );
}

function lccb_tpl_outline_item( DOMElement $el, $i ) {
	$h = null;
	foreach ( array( 'h1', 'h2', 'h3', 'h4' ) as $t ) {
		$l = $el->getElementsByTagName( $t );
		if ( $l->length ) {
			$h = trim( preg_replace( '/\s+/', ' ', $l->item( 0 )->textContent ) );
			break;
		}
	}
	$text = trim( preg_replace( '/\s+/', ' ', $el->textContent ) );
	return array_filter( array(
		'index'    => $i,
		'selector' => lccb_tpl_selector( $el ),
		'tag'      => strtolower( $el->tagName ),
		'id'       => $el->getAttribute( 'id' ) ?: null,
		'classes'  => trim( $el->getAttribute( 'class' ) ) ?: null,
		'heading'  => $h ? mb_substr( $h, 0, 80 ) : null,
		'text'     => $h ? null : ( $text ? mb_substr( $text, 0, 80 ) : null ),
		'images'   => $el->getElementsByTagName( 'img' )->length ?: null,
		'chars'    => strlen( $el->ownerDocument->saveHTML( $el ) ),
	), function ( $v ) {
		return null !== $v;
	} );
}

// ───────────────────────── Scan ─────────────────────────

const LCCB_TPL_LIBS = array(
	'bootstrap'        => '/bootstrap(\.bundle)?(\.min)?\.(css|js)|bootstrap@|\/bootstrap\//i',
	'jquery'           => '/jquery(-\d[\d.]*)?(\.min)?\.js|jquery@|\/jquery\//i',
	'swiper'           => '/swiper/i',
	'owl.carousel'     => '/owl\.carousel/i',
	'slick'            => '/slick/i',
	'aos'              => '/\baos(\.min)?\.(css|js)|\/aos\//i',
	'glightbox'        => '/glightbox/i',
	'magnific-popup'   => '/magnific/i',
	'isotope'          => '/isotope/i',
	'gsap'             => '/gsap|scrolltrigger/i',
	'animate.css'      => '/animate(\.compat)?(\.min)?\.css/i',
	'wow'              => '/\bwow(\.min)?\.js/i',
	'font-awesome'     => '/font-?awesome|fontawesome/i',
	'bootstrap-icons'  => '/bootstrap-icons/i',
	'unicons'          => '/unicons/i',
	'lightgallery'     => '/lightgallery/i',
	'plyr'             => '/plyr/i',
	'tailwind (CDN)'   => '/cdn\.tailwindcss\.com|tailwindcss@/i',
	'revolution slider' => '/rs-plugin|revolution/i',
);

function lccb_op_html_template_scan( array $args ) {
	$tpl   = lccb_tpl_resolve( isset( $args['path'] ) ? $args['path'] : '' );
	$files = lccb_tpl_walk( $tpl['root'] );
	$html  = array();
	foreach ( $files as $f ) {
		if ( preg_match( '/\.html?$/i', $f ) ) {
			$html[] = $f;
		}
	}
	sort( $html );
	$filter = isset( $args['filter'] ) ? strtolower( trim( (string) $args['filter'] ) ) : '';
	$picked = $filter ? array_values( array_filter( $html, function ( $f ) use ( $tpl, $filter ) {
		return false !== strpos( strtolower( lccb_tpl_rel( $tpl, $f ) ), $filter );
	} ) ) : $html;

	// Page groups, so an 800-page template is navigable.
	$groups = array();
	foreach ( $html as $f ) {
		$base = preg_replace( '/\.html?$/i', '', basename( $f ) );
		$tok  = explode( '-', $base );
		$key  = ( in_array( $tok[0], array( 'demo', 'elements', 'shop', 'blog', 'portfolio', 'page', 'pages' ), true ) && isset( $tok[1] ) ) ? $tok[0] . '-' . $tok[1] : $tok[0];
		$groups[ $key ] = ( isset( $groups[ $key ] ) ? $groups[ $key ] : 0 ) + 1;
	}
	arsort( $groups );

	// Detail for the pages in view (index first).
	usort( $picked, function ( $a, $b ) {
		return ( preg_match( '/(^|\/)index\.html?$/i', $b ) <=> preg_match( '/(^|\/)index\.html?$/i', $a ) ) ?: strcmp( $a, $b );
	} );
	$detail    = array_slice( $picked, 0, (int) ( isset( $args['limit'] ) ? max( 1, min( 200, $args['limit'] ) ) : LCCB_TPL_DETAIL_MAX ) );
	$pages     = array();
	$css_use   = array();
	$js_use    = array();
	$classes   = array();
	$headers   = array();
	$footers   = array();
	$inline_css = '';
	$fonts_g   = array();
	$markers   = array( 'data-bs-' => 0, 'data-toggle' => 0, 'data-plugin' => 0 );
	foreach ( $detail as $f ) {
		$src = (string) file_get_contents( $f );
		$dom = lccb_tpl_dom( $src );
		$r   = lccb_tpl_regions( $dom );
		$sec = lccb_tpl_sections( $r );
		$t   = $dom->getElementsByTagName( 'title' )->item( 0 );
		$pages[] = array(
			'page'     => lccb_tpl_rel( $tpl, $f ),
			'title'    => $t ? trim( $t->textContent ) : '',
			'sections' => count( $sec ),
			'outline'  => count( $detail ) <= 12 ? array_map( function ( $s ) {
				return isset( $s['heading'] ) ? $s['heading'] : ( isset( $s['text'] ) ? $s['text'] : $s['tag'] );
			}, array_map( 'lccb_tpl_outline_item', $sec, array_keys( $sec ) ) ) : null,
		);
		foreach ( $dom->getElementsByTagName( 'link' ) as $l ) {
			if ( false !== stripos( $l->getAttribute( 'rel' ), 'stylesheet' ) || ( 'style' === $l->getAttribute( 'as' ) ) ) {
				$href = $l->getAttribute( 'href' );
				if ( preg_match( '#fonts\.googleapis\.com#', $href ) ) {
					$fonts_g = array_merge( $fonts_g, lccb_tpl_google_families( $href ) );
				} elseif ( $href ) {
					$key             = lccb_tpl_asset_key( $tpl, $f, $href );
					$css_use[ $key ] = ( isset( $css_use[ $key ] ) ? $css_use[ $key ] : 0 ) + 1;
				}
			}
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $s ) {
			if ( $s->getAttribute( 'src' ) ) {
				$key            = lccb_tpl_asset_key( $tpl, $f, $s->getAttribute( 'src' ) );
				$js_use[ $key ] = ( isset( $js_use[ $key ] ) ? $js_use[ $key ] : 0 ) + 1;
			}
		}
		foreach ( $dom->getElementsByTagName( 'style' ) as $st ) {
			$inline_css .= $st->textContent . "\n";
		}
		if ( preg_match_all( '/\bclass\s*=\s*"([^"]*)"/i', $src, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/\s+/', trim( $list ) ) as $c ) {
					if ( '' !== $c ) {
						$classes[ $c ] = ( isset( $classes[ $c ] ) ? $classes[ $c ] : 0 ) + 1;
					}
				}
			}
		}
		foreach ( array_keys( $markers ) as $mk ) {
			$markers[ $mk ] += substr_count( $src, $mk );
		}
		if ( $r['header'] ) {
			$h             = md5( lccb_tpl_normalise( $dom->saveHTML( $r['header'] ) ) );
			$headers[ $h ] = ( isset( $headers[ $h ] ) ? $headers[ $h ] : 0 ) + 1;
		}
		if ( $r['footer'] ) {
			$h             = md5( lccb_tpl_normalise( $dom->saveHTML( $r['footer'] ) ) );
			$footers[ $h ] = ( isset( $footers[ $h ] ) ? $footers[ $h ] : 0 ) + 1;
		}
	}
	arsort( $css_use );
	arsort( $js_use );

	// Assets in the template folder.
	$images = array();
	$fonts  = array();
	$img_bytes = 0;
	foreach ( lccb_tpl_walk( $tpl['root'], 8, false ) as $f ) {
		if ( preg_match( '#/(vendor|vendors|node_modules|plugins|libs?)/#i', substr( $f, strlen( $tpl['root'] ) ) ) ) {
			continue;
		}
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $f ) ) {
			$sz        = (int) @filesize( $f );
			$img_bytes += $sz;
			$images[]  = array( lccb_tpl_rel( $tpl, $f ), $sz );
		} elseif ( preg_match( '/\.(woff2?|ttf|otf|eot)$/i', $f ) ) {
			$fonts[] = lccb_tpl_rel( $tpl, $f );
		}
	}
	usort( $images, function ( $a, $b ) {
		return $b[1] - $a[1];
	} );

	$framework = lccb_tpl_detect_framework( $tpl, $classes, array_keys( $css_use ), array_keys( $js_use ), $markers );
	$own_css   = lccb_tpl_own_css( $tpl, array_keys( $css_use ) );
	$tokens    = lccb_tpl_tokens( $own_css['css'] . "\n" . $inline_css, $fonts_g );

	$libs = array();
	foreach ( array_merge( array_keys( $css_use ), array_keys( $js_use ) ) as $a ) {
		foreach ( LCCB_TPL_LIBS as $lib => $re ) {
			if ( preg_match( $re, $a ) ) {
				$libs[ $lib ] = true;
			}
		}
	}

	$warnings = array();
	if ( 'bootstrap' === $framework['name'] && isset( $framework['version'] ) && version_compare( $framework['version'], '5', '<' ) ) {
		$warnings[] = "Bootstrap {$framework['version']}: lc_html_template_read converts the common Bootstrap 4 classes and data-toggle attributes to Bootstrap 5; check components (forms, cards, badges) visually.";
	}
	if ( isset( $libs['jquery'] ) || $markers['data-plugin'] ) {
		$warnings[] = 'Uses jQuery plugins' . ( $markers['data-plugin'] ? ' (data-plugin-* attributes)' : '' ) . '. WordPress\'s own jQuery can run them if you import that JS; otherwise prefer rebuilding those parts with Bootstrap 5 components or small vanilla JS.';
	}
	if ( count( $html ) > count( $detail ) ) {
		$warnings[] = count( $html ) . ' pages in total; details below are for ' . count( $detail ) . ( $filter ? " matching \"$filter\"" : '' ) . '. Use `filter` (e.g. a page-group name) to look at others.';
	}

	$checked = count( $detail );
	$common  = function ( array $h ) use ( $checked ) {
		if ( ! $h ) {
			return 'none found';
		}
		$top = max( $h );
		return 1 === $checked ? 'found (one page checked)' : ( $top > 1 ? "the same on $top of $checked pages" : 'differs per page' );
	};

	return array(
		'template'   => array( 'name' => $tpl['name'], 'slug' => $tpl['slug'], 'html_root' => $tpl['root'], 'preview_url' => $tpl['url'] ),
		'framework'  => $framework,
		'strategies' => lccb_tpl_strategies( $framework ),
		'pages'      => array(
			'total'   => count( $html ),
			'groups'  => count( $html ) > 15 ? array_slice( $groups, 0, 30, true ) : null,
			'details' => $pages,
		),
		'header'     => $common( $headers ),
		'footer'     => $common( $footers ),
		'css'        => array_slice( lccb_tpl_asset_list( $css_use ), 0, 30 ),
		'js'         => array_slice( lccb_tpl_asset_list( $js_use ), 0, 30 ),
		'libraries'  => array_keys( $libs ),
		'own_css'    => $own_css['files'],
		'design_tokens' => $tokens,
		'fonts'      => array( 'google' => array_values( array_unique( $fonts_g ) ), 'files' => count( $fonts ) ),
		'images'     => array(
			'count'   => count( $images ),
			'total'   => size_format( $img_bytes ),
			'largest' => array_map( function ( $i ) {
				return $i[0] . ' (' . size_format( $i[1] ) . ')';
			}, array_slice( $images, 0, 6 ) ),
		),
		'warnings'   => $warnings,
		'next'       => 'Pick the page(s) to rebuild, then lc_html_template_read {path, page} for its section outline and lc_html_template_read {path, page, section} for LiveCanvas-ready HTML. Map design_tokens with lc_tokens_update, import assets with lc_html_template_assets, and check each rebuilt section with lc_compare.',
	);
}

/** Markup with per-page state (active nav item, aria-current) removed, for spotting a shared header/footer. */
function lccb_tpl_normalise( $html ) {
	$html = preg_replace( '/\s+aria-current="[^"]*"/', '', $html );
	$html = preg_replace( '/\b(active|current|current-menu-item|is-active|selected)\b/', '', $html );
	return preg_replace( array( '/\s+/', '/\s+"/', '/="\s+/' ), array( ' ', '"', '="' ), $html );
}

function lccb_tpl_google_families( $href ) {
	$out = array();
	$q   = wp_parse_url( html_entity_decode( $href ), PHP_URL_QUERY );
	if ( ! $q ) {
		return $out;
	}
	foreach ( explode( '&', $q ) as $pair ) {
		if ( 0 === strpos( $pair, 'family=' ) ) {
			foreach ( explode( '|', urldecode( substr( $pair, 7 ) ) ) as $fam ) {
				$out[] = trim( preg_replace( '/:.*$/', '', $fam ) );
			}
		}
	}
	return $out;
}

/** A stylesheet/script reference as "relative/path.css" (local) or the URL (remote). */
function lccb_tpl_asset_key( array $tpl, $page_file, $ref ) {
	$ref = html_entity_decode( trim( $ref ) );
	if ( preg_match( '#^(https?:)?//#i', $ref ) ) {
		return ( 0 === strpos( $ref, '//' ) ? 'https:' : '' ) . $ref;
	}
	$ref  = preg_replace( '/[?#].*$/', '', $ref );
	$file = realpath( dirname( $page_file ) . '/' . $ref );
	return $file && 0 === strpos( $file, realpath( $tpl['root'] ) . '/' ) ? lccb_tpl_rel( $tpl, $file ) : 'missing: ' . $ref;
}

function lccb_tpl_asset_list( array $use ) {
	$out = array();
	foreach ( $use as $k => $n ) {
		$out[] = $k . ( $n > 1 ? " ($n pages)" : '' );
	}
	return $out;
}

function lccb_tpl_is_vendor( $rel ) {
	if ( preg_match( '#^https?://#', $rel ) || 0 === strpos( $rel, 'missing:' ) ) {
		return true;
	}
	if ( preg_match( '#(^|/)(vendor|vendors|libs?|plugins|node_modules)/#i', $rel ) || preg_match( '/\.min\.(css|js)$/i', $rel ) ) {
		return true;
	}
	foreach ( LCCB_TPL_LIBS as $re ) {
		if ( preg_match( $re, $rel ) ) {
			return true;
		}
	}
	return false;
}

/** The template's own (non-library) CSS. */
function lccb_tpl_own_css( array $tpl, array $css ) {
	$out   = '';
	$files = array();
	foreach ( $css as $rel ) {
		if ( lccb_tpl_is_vendor( $rel ) ) {
			continue;
		}
		$f = $tpl['root'] . '/' . $rel;
		if ( is_file( $f ) && filesize( $f ) < 4 * 1024 * 1024 ) {
			$out    .= file_get_contents( $f ) . "\n";
			$files[] = $rel . ' (' . size_format( filesize( $f ) ) . ')';
		}
	}
	return array( 'css' => $out, 'files' => $files );
}

function lccb_tpl_detect_framework( array $tpl, array $classes, array $css, array $js, array $markers ) {
	$score = array( 'bootstrap' => 0, 'tailwind' => 0, 'bulma' => 0, 'foundation' => 0, 'uikit' => 0 );
	$ev    = array_fill_keys( array_keys( $score ), array() );
	$all   = implode( ' ', array_merge( $css, $js ) );
	$version = null;
	foreach ( $css as $rel ) {
		if ( preg_match( LCCB_TPL_LIBS['bootstrap'], $rel ) ) {
			$score['bootstrap'] += 5;
			$ev['bootstrap'][]   = "loads $rel";
			if ( preg_match( '/bootstrap@(\d+(\.\d+)*)/', $rel, $vm ) ) {
				$version = $vm[1];
			} elseif ( is_file( $tpl['root'] . '/' . $rel ) ) {
				$head = (string) file_get_contents( $tpl['root'] . '/' . $rel, false, null, 0, 600 );
				if ( preg_match( '/Bootstrap\s+v?(\d+(\.\d+)*)/i', $head, $vm ) ) {
					$version = $vm[1];
				}
			}
		}
		if ( preg_match( '/tailwind/i', $rel ) ) {
			$score['tailwind'] += 4;
			$ev['tailwind'][]   = "loads $rel";
		}
		if ( preg_match( '/bulma/i', $rel ) ) {
			$score['bulma'] += 5;
			$ev['bulma'][]   = "loads $rel";
		}
		if ( preg_match( '/foundation/i', $rel ) ) {
			$score['foundation'] += 5;
			$ev['foundation'][]   = "loads $rel";
		}
		if ( preg_match( '/uikit/i', $rel ) ) {
			$score['uikit'] += 5;
			$ev['uikit'][]   = "loads $rel";
		}
		// Compiled Tailwind announces itself in the stylesheet header.
		if ( ! lccb_tpl_is_vendor( $rel ) || preg_match( '/style\.css$/', $rel ) ) {
			$f = $tpl['root'] . '/' . $rel;
			if ( is_file( $f ) && preg_match( '/tailwindcss v(\d+[\d.]*)/', (string) file_get_contents( $f, false, null, 0, 400 ), $tm ) ) {
				$score['tailwind'] += 6;
				$ev['tailwind'][]   = "$rel is compiled Tailwind v{$tm[1]}";
			}
		}
	}
	if ( preg_match( '/cdn\.tailwindcss\.com/', $all ) ) {
		$score['tailwind'] += 6;
		$ev['tailwind'][]   = 'Tailwind Play CDN';
	}
	foreach ( array( dirname( $tpl['root'] ), $tpl['root'] ) as $d ) {
		if ( glob( $d . '/tailwind.config.*' ) ) {
			$score['tailwind'] += 4;
			$ev['tailwind'][]   = 'tailwind.config';
		}
		if ( is_file( $d . '/package.json' ) && preg_match( '/"(@tailwindcss\/[\w-]+|tailwindcss)"/', (string) file_get_contents( $d . '/package.json' ) ) ) {
			$score['tailwind'] += 3;
			$ev['tailwind'][]   = 'package.json depends on tailwindcss';
		}
	}
	$tw = 0;
	$bs = 0;
	foreach ( $classes as $c => $n ) {
		if ( preg_match( '/^(!?)(sm|md|lg|xl|2xl|hover|focus):|^-?[mp][xytblr]?-\[|\[[^\]]+\]$|^(text|bg|border)-(gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}$|^(items|justify|content|self)-(start|end|center|between|around|stretch)$|^(grid-cols|col-span|space-[xy]|divide-[xy]|rounded-(md|xl|2xl|3xl)|shadow-(md|xl|2xl)|leading|tracking|w-\d|h-\d|max-w|min-h)-?/', $c ) ) {
			$tw += $n;
		}
		if ( preg_match( '/^(row|container(-fluid)?|col(-(sm|md|lg|xl|xxl))?(-\d+|-auto)?|btn(-[a-z-]+)?|navbar(-[a-z-]+)?|card(-[a-z-]+)?|d-(none|flex|block)|(m|p)[trblxy]?-[0-5]|justify-content-\w+|align-items-\w+|text-(start|end|center|muted)|fw-\w+|g-\d|gx-\d|gy-\d)$/', $c ) ) {
			$bs += $n;
		}
		if ( preg_match( '/^(columns|column|is-(half|one-third|two-thirds|[1-9]|primary|link|info)|has-text-\w+|hero-body|navbar-burger)$/', $c ) ) {
			$score['bulma'] += min( 3, $n ) * 0.2;
		}
		if ( preg_match( '/^(grid-x|grid-y|cell|small-\d+|medium-\d+|large-\d+|top-bar|callout)$/', $c ) ) {
			$score['foundation'] += min( 3, $n ) * 0.2;
		}
		if ( 0 === strpos( $c, 'uk-' ) ) {
			$score['uikit'] += min( 3, $n ) * 0.2;
		}
	}
	if ( $tw > 30 ) {
		$score['tailwind'] += min( 10, $tw / 40 );
		$ev['tailwind'][]   = "$tw Tailwind-style utility classes (responsive prefixes, arbitrary values…)";
	}
	if ( $bs > 30 ) {
		$score['bootstrap'] += min( 8, $bs / 40 );
		$ev['bootstrap'][]   = "$bs Bootstrap classes (row/col-*, btn, utilities)";
	}
	if ( $markers['data-bs-'] ) {
		$score['bootstrap'] += 2;
		$ev['bootstrap'][]   = 'data-bs-* attributes (Bootstrap 5)';
		$version             = $version ?: '5';
	} elseif ( $markers['data-toggle'] ) {
		$score['bootstrap'] += 1;
		$ev['bootstrap'][]   = 'data-toggle attributes (Bootstrap 4 or older)';
		$version             = $version ?: '4';
	}
	arsort( $score );
	$name = key( $score );
	$top  = current( $score );
	if ( $top < 2 ) {
		return array( 'name' => 'custom', 'confidence' => 'medium', 'evidence' => array( 'No CSS framework detected: hand-written CSS.' ), 'mixed' => null );
	}
	$second = array_slice( $score, 1, 1, true );
	$mixed  = current( $second ) >= 3 ? key( $second ) : null;
	$out    = array(
		'name'       => $name,
		'confidence' => $top >= 10 ? 'high' : ( $top >= 5 ? 'medium' : 'low' ),
		'evidence'   => array_slice( array_unique( $ev[ $name ] ), 0, 6 ),
	);
	if ( 'bootstrap' === $name && $version ) {
		$out['version'] = $version;
	}
	if ( $mixed ) {
		$out['also'] = $mixed . ': ' . implode( '; ', array_slice( array_unique( $ev[ $mixed ] ), 0, 3 ) );
	}
	return $out;
}

function lccb_tpl_strategies( array $fw ) {
	$native = defined( 'WINDPRESS_VERSION' ) || in_array( 'windpress/windpress.php', (array) get_option( 'active_plugins', array() ), true ) || false !== stripos( (string) get_template(), 'picowind' );
	if ( 'bootstrap' === $fw['name'] ) {
		return array( 'recommended' => 'bootstrap', 'note' => 'Same framework as Picostrap: sections drop straight in. Import the template\'s own CSS (not its Bootstrap) with lc_html_template_assets, or port the parts you need into Global CSS.' );
	}
	$opts = array(
		'convert' => 'Rewrite each section with Bootstrap 5 (lc_html_template_read {strategy: "convert"} does the mechanical mapping; you finish the rest and check with lc_compare). Leanest result, matches the site\'s system.',
		'scoped'  => 'Keep the template\'s CSS, scoped under .tpl-' . '<slug> so it can\'t clash with Bootstrap (lc_html_template_assets {scope: true}; sections get the wrapper class). Fastest, but heavier CSS and two systems.',
	);
	if ( 'tailwind' === $fw['name'] ) {
		$opts['native'] = $native ? 'This site runs WindPress/Picowind: keep the Tailwind classes as they are and map the template\'s theme onto the site\'s Tailwind config.' : 'Not available: needs WindPress or Picowind on this site.';
	}
	return array( 'recommended' => $native && 'tailwind' === $fw['name'] ? 'native' : 'convert', 'options' => $opts );
}

/** Likely design tokens from the template's own CSS. */
function lccb_tpl_tokens( $css, array $google ) {
	$css    = preg_replace( '#/\*.*?\*/#s', '', $css );
	$vars   = array();
	if ( preg_match_all( '/(--[\w-]+)\s*:\s*([^;}{]+)/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $d ) {
			if ( preg_match( '/^--(bs|tw)-|-(-?\d+|rgba-\d+|rgb)$/', $d[1] ) ) {
				continue; // framework internals and shade ladders (--primary-100, --primary-rgba-20…)
			}
			$val = trim( $d[2] );
			if ( preg_match( '/^--(font-weight|text|spacing|breakpoint|container|leading|tracking|ease|animate|blur|perspective|aspect|default|shadow|inset-shadow|drop-shadow|swiper)/', $d[1] ) || preg_match( '/^(ui-|system-ui|-apple-system)/', $val ) ) {
				continue; // Tailwind/library defaults, not the template's design
			}
			if ( count( $vars ) < 30 && ( lccb_lint_parse_colour( $val ) || preg_match( '/font|radius|family/i', $d[1] ) ) ) {
				$vars[ $d[1] ] = trim( $d[2] );
			}
		}
	}
	$colours = array();
	if ( preg_match_all( '/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgba?\([^)]*\)/', $css, $m ) ) {
		foreach ( $m[0] as $c ) {
			$rgb = lccb_lint_parse_colour( $c );
			if ( ! $rgb || $rgb[3] < 100 ) {
				continue;
			}
			$hex             = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
			$colours[ $hex ] = ( isset( $colours[ $hex ] ) ? $colours[ $hex ] : 0 ) + 1;
		}
	}
	arsort( $colours );
	// Saturated colours are brand candidates; greys are text/background candidates.
	$brand = array();
	$neutral = array();
	foreach ( $colours as $hex => $n ) {
		list( $r, $g, $b ) = sscanf( $hex, '#%02x%02x%02x' );
		$sat = max( $r, $g, $b ) - min( $r, $g, $b );
		if ( $sat > 40 ) {
			$brand[] = "$hex (×$n)";
		} elseif ( ! in_array( $hex, array( '#ffffff', '#000000' ), true ) ) {
			$neutral[] = "$hex (×$n)";
		}
	}
	$fonts = array();
	if ( preg_match_all( '/font-family\s*:\s*([^;}{]+)/i', $css, $m ) ) {
		foreach ( $m[1] as $f ) {
			$first = trim( explode( ',', $f )[0], " \t\"'" );
			$first = preg_match( '/^var\((--[\w-]+)/', $first, $vm ) && isset( $vars[ $vm[1] ] ) ? trim( explode( ',', $vars[ $vm[1] ] )[0], " \t\"'" ) : $first;
			if ( '' !== $first && ! preg_match( '/^(inherit|initial|var\(|-apple-system|system-ui|sans-serif|serif|monospace)|icon|awesome|unicons|bootstrap-icons|simple-line|linea|feather|themify|material/i', $first ) ) {
				$fonts[ $first ] = ( isset( $fonts[ $first ] ) ? $fonts[ $first ] : 0 ) + 1;
			}
		}
	}
	arsort( $fonts );
	$heading_font = null;
	if ( preg_match( '/(^|[\s,}])h1[^{]*\{[^}]*font-family\s*:\s*([^;}]+)/i', $css, $hm ) ) {
		$heading_font = trim( explode( ',', $hm[2] )[0], " \t\"'" );
		if ( preg_match( '/^var\((--[\w-]+)/', $heading_font, $vm ) && isset( $vars[ $vm[1] ] ) ) {
			$heading_font = trim( explode( ',', $vars[ $vm[1] ] )[0], " \t\"'" );
		}
	}
	$radius = array();
	if ( preg_match_all( '/border-radius\s*:\s*([\d.]+(px|rem))/i', $css, $m ) ) {
		$radius = array_count_values( $m[1] );
		arsort( $radius );
	}
	return array_filter( array(
		'custom_properties' => $vars ?: null,
		'brand_colours'     => array_slice( $brand, 0, 6 ) ?: null,
		'neutral_colours'   => array_slice( $neutral, 0, 6 ) ?: null,
		'fonts'             => array_slice( array_keys( $fonts ), 0, 5 ) ?: ( $google ? array_values( array_unique( $google ) ) : null ),
		'heading_font'      => $heading_font,
		'border_radius'     => $radius ? key( $radius ) : null,
		'map_to_picostrap'  => 'Suggested lc_tokens_update: primary = the main brand colour, body-color / body-bg = the darkest text grey and the page background, font-family-base = the body font, headings-font-family = the heading font (add its Google Fonts <link> to fonts_header_code), border-radius = the common radius. Then lc_css_recompile.',
	) );
}

// ───────────────────────── Read a page / section ─────────────────────────

function lccb_op_html_template_read( array $args ) {
	$tpl  = lccb_tpl_resolve( isset( $args['path'] ) ? $args['path'] : '' );
	$file = lccb_tpl_page_file( $tpl, isset( $args['page'] ) ? $args['page'] : 'index.html' );
	$rel  = lccb_tpl_rel( $tpl, $file );
	$dom  = lccb_tpl_dom( (string) file_get_contents( $file ) );
	$r    = lccb_tpl_regions( $dom );
	$secs = lccb_tpl_sections( $r );
	$page_url = $tpl['url'] ? $tpl['url'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ) : null;

	$section = isset( $args['section'] ) ? $args['section'] : null;
	if ( null === $section || '' === $section ) {
		$t = $dom->getElementsByTagName( 'title' )->item( 0 );
		return array(
			'page'     => $rel,
			'title'    => $t ? trim( $t->textContent ) : '',
			'url'      => $page_url,
			'header'   => $r['header'] ? lccb_tpl_outline_item( $r['header'], 'header' ) : null,
			'sections' => array_map( 'lccb_tpl_outline_item', $secs, array_keys( $secs ) ),
			'footer'   => $r['footer'] ? lccb_tpl_outline_item( $r['footer'], 'footer' ) : null,
			'next'     => 'lc_html_template_read {path, page, section: <index | "header" | "footer">} returns that section as LiveCanvas-ready HTML.',
		);
	}
	if ( 'header' === $section || 'footer' === $section ) {
		$el = $r[ $section ];
		if ( ! $el ) {
			throw new Exception( "No $section found on $rel." );
		}
	} elseif ( is_numeric( $section ) && isset( $secs[ (int) $section ] ) ) {
		$el = $secs[ (int) $section ];
	} else {
		throw new Exception( "No section $section on $rel (it has " . count( $secs ) . ', numbered from 0).' );
	}

	$page_fw  = lccb_tpl_page_framework( $tpl, $file, $dom );
	$scan_fw  = isset( $args['framework'] ) ? (string) $args['framework'] : $page_fw['name'];
	$is_bs4   = 'bootstrap' === $page_fw['name'] && isset( $page_fw['version'] ) && version_compare( $page_fw['version'], '5', '<' );
	$strategy = isset( $args['strategy'] ) ? (string) $args['strategy'] : 'auto';
	$map      = get_option( 'lccb_tpl_map_' . $tpl['slug'], array() );
	$selector = lccb_tpl_selector( $el );
	$clean    = lccb_tpl_clean( $el, $tpl, $file, is_array( $map ) ? $map : array() );
	$html     = $dom->saveHTML( $el );

	$out = array(
		'page'      => $rel,
		'section'   => $section,
		'selector'  => $selector,
		'url'       => $page_url,
		'chars'     => strlen( $html ),
	);
	$out['framework'] = $scan_fw;
	if ( 'convert' === $strategy || ( 'auto' === $strategy && in_array( $scan_fw, array( 'tailwind', 'bulma', 'foundation' ), true ) ) ) {
		$conv               = lccb_tpl_convert( $el );
		$html               = $dom->saveHTML( $el );
		$out['converted']   = array( 'mapped' => $conv['mapped'], 'still_to_convert' => array_slice( $conv['unmapped'], 0, 60, true ) );
		$out['next']        = 'Classes under still_to_convert have no mechanical Bootstrap 5 equivalent: replace them (utilities, a site class, or a small rule in Global CSS), then build it with lc_edit_html and check it against the original with lc_compare.';
	} elseif ( 'scoped' === $strategy ) {
		$el->setAttribute( 'class', trim( $el->getAttribute( 'class' ) . ' tpl-' . $tpl['slug'] ) );
		$html        = $dom->saveHTML( $el );
		$out['next'] = 'Wrapped in .tpl-' . $tpl['slug'] . ': its styles come from the scoped template CSS (lc_html_template_assets {scope: true}).';
	} else {
		$bs4  = $is_bs4 ? lccb_tpl_bs4_to_bs5( $el ) : array();
		$html = $dom->saveHTML( $el );
		$cls  = lccb_tpl_section_classes( $el );
		if ( $bs4 ) {
			$out['bootstrap4_converted'] = $bs4;
		}
		$out['template_classes'] = $cls['template'] ? array_slice( $cls['template'], 0, 40 ) : null;
		$out['next']             = 'Add it with lc_write_html / lc_edit_html (or lc_page_create for a new page). template_classes come from the template\'s CSS: import it with lc_html_template_assets, or restyle them with the site\'s own classes. Then check with lc_compare.';
	}
	$out['assets'] = $clean;
	$out['html']   = lccb_tpl_pretty( $html );
	return $out;
}

/** The framework one page uses (same detector as the scan, on just this page). */
function lccb_tpl_page_framework( array $tpl, $file, DOMDocument $dom ) {
	$css = array();
	$js  = array();
	foreach ( $dom->getElementsByTagName( 'link' ) as $l ) {
		if ( false !== stripos( $l->getAttribute( 'rel' ), 'stylesheet' ) && $l->getAttribute( 'href' ) ) {
			$css[] = lccb_tpl_asset_key( $tpl, $file, $l->getAttribute( 'href' ) );
		}
	}
	foreach ( $dom->getElementsByTagName( 'script' ) as $sc ) {
		if ( $sc->getAttribute( 'src' ) ) {
			$js[] = lccb_tpl_asset_key( $tpl, $file, $sc->getAttribute( 'src' ) );
		}
	}
	$src     = (string) file_get_contents( $file );
	$classes = array();
	if ( preg_match_all( '/\bclass\s*=\s*"([^"]*)"/i', $src, $m ) ) {
		foreach ( $m[1] as $list ) {
			foreach ( preg_split( '/\s+/', trim( $list ) ) as $c ) {
				if ( '' !== $c ) {
					$classes[ $c ] = ( isset( $classes[ $c ] ) ? $classes[ $c ] : 0 ) + 1;
				}
			}
		}
	}
	return lccb_tpl_detect_framework( $tpl, $classes, $css, $js, array( 'data-bs-' => substr_count( $src, 'data-bs-' ), 'data-toggle' => substr_count( $src, 'data-toggle' ), 'data-plugin' => 0 ) );
}

/** Strip scripts/handlers, un-lazy images, resolve asset URLs (imported → media library, else the template preview). */
function lccb_tpl_clean( DOMElement $el, array $tpl, $page_file, array $map ) {
	$xp      = new DOMXPath( $el->ownerDocument );
	$removed = 0;
	foreach ( iterator_to_array( $xp->query( './/script|.//noscript', $el ) ) as $n ) {
		$n->parentNode->removeChild( $n );
		$removed++;
	}
	$plugins = array();
	foreach ( $xp->query( './/*', $el ) as $n ) {
		foreach ( iterator_to_array( $n->attributes ) as $a ) {
			if ( 0 === stripos( $a->name, 'on' ) ) {
				$n->removeAttribute( $a->name );
			} elseif ( preg_match( '/^data-(plugin|appear|aos|wow|parallax|lazy|sal|scroll)/i', $a->name ) ) {
				$plugins[ preg_replace( '/^(data-[a-z]+).*$/i', '$1', $a->name ) ] = true;
			}
		}
	}
	$assets = array();
	$base   = $tpl['url'];
	$fix    = function ( $ref ) use ( $tpl, $page_file, $map, $base, &$assets ) {
		$ref = trim( html_entity_decode( $ref ) );
		if ( '' === $ref || preg_match( '#^(https?:|//|data:|mailto:|tel:|\#|javascript:)#i', $ref ) ) {
			return $ref;
		}
		$clean = preg_replace( '/[?#].*$/', '', $ref );
		$file  = realpath( dirname( $page_file ) . '/' . $clean );
		if ( ! $file || 0 !== strpos( $file, realpath( $tpl['root'] ) . '/' ) ) {
			$assets[ $ref ] = 'missing';
			return $ref;
		}
		$rel = lccb_tpl_rel( $tpl, $file );
		if ( isset( $map[ $rel ] ) ) {
			$assets[ $rel ] = 'media library';
			return $map[ $rel ];
		}
		$assets[ $rel ] = 'template (not imported yet)';
		return $base ? $base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ) : $ref;
	};
	foreach ( iterator_to_array( $xp->query( './/*[@data-src or @data-lazy-src or @data-srcset or @data-bg or @data-background or @data-image-src]', $el ) ) as $n ) {
		foreach ( array( 'data-src' => 'src', 'data-lazy-src' => 'src', 'data-srcset' => 'srcset' ) as $from => $to ) {
			if ( $n->hasAttribute( $from ) && in_array( strtolower( $n->tagName ), array( 'img', 'source', 'iframe', 'video' ), true ) ) {
				$n->setAttribute( $to, $n->getAttribute( $from ) );
				$n->removeAttribute( $from );
			}
		}
		foreach ( array( 'data-bg', 'data-background', 'data-image-src' ) as $bg ) {
			if ( $n->hasAttribute( $bg ) ) {
				$style = rtrim( $n->getAttribute( 'style' ), '; ' );
				$n->setAttribute( 'style', ( $style ? $style . '; ' : '' ) . 'background-image: url(' . $n->getAttribute( $bg ) . ')' );
				$n->removeAttribute( $bg );
			}
		}
	}
	foreach ( $xp->query( './/*[@src or @poster or @srcset or @style or @href]', $el ) as $n ) {
		foreach ( array( 'src', 'poster' ) as $at ) {
			if ( $n->hasAttribute( $at ) ) {
				$n->setAttribute( $at, $fix( $n->getAttribute( $at ) ) );
			}
		}
		if ( $n->hasAttribute( 'href' ) && preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|mp4|pdf)$/i', $n->getAttribute( 'href' ) ) ) {
			$n->setAttribute( 'href', $fix( $n->getAttribute( 'href' ) ) );
		} elseif ( $n->hasAttribute( 'href' ) && preg_match( '/^[\w\/.-]+\.html?(#.*)?$/i', $n->getAttribute( 'href' ) ) ) {
			$n->setAttribute( 'href', '#' ); // links between template pages: Claude re-links them to the site's pages
		}
		if ( $n->hasAttribute( 'srcset' ) ) {
			$n->setAttribute( 'srcset', implode( ', ', array_map( function ( $part ) use ( $fix ) {
				$bits    = preg_split( '/\s+/', trim( $part ), 2 );
				$bits[0] = $fix( $bits[0] );
				return implode( ' ', $bits );
			}, explode( ',', $n->getAttribute( 'srcset' ) ) ) ) );
		}
		if ( $n->hasAttribute( 'style' ) && false !== stripos( $n->getAttribute( 'style' ), 'url(' ) ) {
			$n->setAttribute( 'style', preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ( $m ) use ( $fix ) {
				return 'url(' . $fix( $m[2] ) . ')';
			}, $n->getAttribute( 'style' ) ) );
		}
	}
	$counts = array_count_values( $assets );
	return array_filter( array(
		'scripts_removed' => $removed ?: null,
		'plugin_attributes' => $plugins ? array_keys( $plugins ) : null,
		'files'           => $assets ? array_slice( $assets, 0, 60, true ) : null,
		'summary'         => $assets ? $counts : null,
		'note'            => isset( $counts['template (not imported yet)'] ) ? 'Images still point at the template preview (local only). Import them with lc_html_template_assets {include: ["images"], pages: [...]} and read the section again to get media-library URLs.' : null,
	) );
}

/** Classes in a section that Bootstrap 5 doesn't define. */
function lccb_tpl_section_classes( DOMElement $el ) {
	$xp  = new DOMXPath( $el->ownerDocument );
	$all = array();
	foreach ( array_merge( array( $el ), iterator_to_array( $xp->query( './/*[@class]', $el ) ) ) as $n ) {
		foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'class' ) ) ) as $c ) {
			if ( '' !== $c ) {
				$all[ $c ] = true;
			}
		}
	}
	$known = lccb_tpl_bootstrap_classes();
	$tpl   = array();
	foreach ( array_keys( $all ) as $c ) {
		if ( ! isset( $known[ $c ] ) && ! preg_match( '/^(bi|fa[srlbd]?|fab|fas|far)([-\s]|$)/', $c ) ) {
			$tpl[] = $c;
		}
	}
	return array( 'template' => $tpl );
}

/** Class names defined by the site's compiled Bootstrap (Picostrap bundle), cached. */
function lccb_tpl_bootstrap_classes() {
	static $known = null;
	if ( null !== $known ) {
		return $known;
	}
	$known  = array();
	$bundle = function_exists( 'lccb_bundle_path' ) ? lccb_bundle_path() : '';
	if ( $bundle && is_file( $bundle ) && preg_match_all( '/\.(-?[a-zA-Z_][\w-]*)/', (string) file_get_contents( $bundle ), $m ) ) {
		$known = array_fill_keys( $m[1], true );
	}
	return $known;
}

function lccb_tpl_pretty( $html ) {
	return "\n" . trim( preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $html ) ) . "\n";
}

// ───────────────────────── Framework conversion ─────────────────────────

/** Bootstrap 4 → 5 renames (only the unambiguous ones). @return array of "old → new" counts */
function lccb_tpl_bs4_to_bs5( DOMElement $el ) {
	$xp      = new DOMXPath( $el->ownerDocument );
	$changes = array();
	$classes = array(
		'/^m([lr])-(sm-|md-|lg-|xl-)?(\d|auto)$/'   => function ( $m ) { return 'm' . ( 'l' === $m[1] ? 's' : 'e' ) . '-' . $m[2] . $m[3]; },
		'/^p([lr])-(sm-|md-|lg-|xl-)?(\d)$/'        => function ( $m ) { return 'p' . ( 'l' === $m[1] ? 's' : 'e' ) . '-' . $m[2] . $m[3]; },
		'/^float-(sm-|md-|lg-|xl-)?(left|right)$/'  => function ( $m ) { return 'float-' . $m[1] . ( 'left' === $m[2] ? 'start' : 'end' ); },
		'/^text-(sm-|md-|lg-|xl-)?(left|right)$/'   => function ( $m ) { return 'text-' . $m[1] . ( 'left' === $m[2] ? 'start' : 'end' ); },
		'/^font-weight-(bold|bolder|normal|light|lighter)$/' => function ( $m ) { return 'fw-' . $m[1]; },
		'/^font-italic$/'                           => function () { return 'fst-italic'; },
		'/^(sr-only)$/'                             => function () { return 'visually-hidden'; },
		'/^sr-only-focusable$/'                     => function () { return 'visually-hidden-focusable'; },
		'/^badge-pill$/'                            => function () { return 'rounded-pill'; },
		'/^badge-(primary|secondary|success|danger|warning|info|light|dark)$/' => function ( $m ) { return 'text-bg-' . $m[1]; },
		'/^no-gutters$/'                            => function () { return 'g-0'; },
		'/^custom-select$/'                         => function () { return 'form-select'; },
		'/^custom-control-input$/'                  => function () { return 'form-check-input'; },
		'/^custom-control-label$/'                  => function () { return 'form-check-label'; },
		'/^custom-(checkbox|radio|control)$/'       => function () { return 'form-check'; },
		'/^custom-switch$/'                         => function () { return 'form-check form-switch'; },
		'/^custom-range$/'                          => function () { return 'form-range'; },
		'/^custom-file-input$/'                     => function () { return 'form-control'; },
		'/^form-row$/'                              => function () { return 'row g-2'; },
		'/^rounded-(left|right)$/'                  => function ( $m ) { return 'rounded-' . ( 'left' === $m[1] ? 'start' : 'end' ); },
		'/^border-(left|right)$/'                   => function ( $m ) { return 'border-' . ( 'left' === $m[1] ? 'start' : 'end' ); },
		'/^embed-responsive$/'                      => function () { return 'ratio'; },
		'/^embed-responsive-(\d+)by(\d+)$/'         => function ( $m ) { return 'ratio-' . $m[1] . 'x' . $m[2]; },
		'/^dropdown-menu-(left|right)$/'            => function ( $m ) { return 'dropdown-menu-' . ( 'left' === $m[1] ? 'start' : 'end' ); },
		'/^close$/'                                 => function () { return 'btn-close'; },
		'/^media$/'                                 => function () { return 'd-flex'; },
		'/^media-body$/'                            => function () { return 'flex-grow-1 ms-3'; },
		'/^input-group-(append|prepend)$/'          => function () { return ''; },
	);
	foreach ( array_merge( array( $el ), iterator_to_array( $xp->query( './/*', $el ) ) ) as $n ) {
		if ( $n->hasAttribute( 'class' ) ) {
			$out = array();
			foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'class' ) ) ) as $c ) {
				$new = $c;
				foreach ( $classes as $re => $fn ) {
					if ( preg_match( $re, $c, $m ) ) {
						$new = $fn( $m );
						break;
					}
				}
				if ( $new !== $c ) {
					$key             = "$c → " . ( '' === $new ? '(removed)' : $new );
					$changes[ $key ] = ( isset( $changes[ $key ] ) ? $changes[ $key ] : 0 ) + 1;
				}
				if ( '' !== $new ) {
					$out[] = $new;
				}
			}
			$n->setAttribute( 'class', implode( ' ', array_unique( $out ) ) );
		}
		foreach ( array( 'toggle', 'target', 'dismiss', 'parent', 'ride', 'slide', 'spy', 'offset', 'placement', 'content', 'trigger' ) as $d ) {
			if ( $n->hasAttribute( 'data-' . $d ) && ! $n->hasAttribute( 'data-bs-' . $d ) && ( 'toggle' === $d || $n->hasAttribute( 'data-toggle' ) || $n->hasAttribute( 'data-bs-toggle' ) || in_array( $d, array( 'ride', 'slide', 'parent', 'dismiss' ), true ) ) ) {
				$n->setAttribute( 'data-bs-' . $d, $n->getAttribute( 'data-' . $d ) );
				$n->removeAttribute( 'data-' . $d );
				$changes[ "data-$d → data-bs-$d" ] = ( isset( $changes[ "data-$d → data-bs-$d" ] ) ? $changes[ "data-$d → data-bs-$d" ] : 0 ) + 1;
			}
		}
	}
	return $changes;
}

const LCCB_TW_BREAKPOINTS = array( 'sm' => 'sm', 'md' => 'md', 'lg' => 'lg', 'xl' => 'xl', '2xl' => 'xxl', 'xxl' => 'xxl' );

/** Tailwind spacing (×0.25rem) → Bootstrap spacer step. */
function lccb_tw_step( $v ) {
	if ( 'px' === $v ) {
		return 1;
	}
	if ( ! is_numeric( $v ) ) {
		return null;
	}
	$v = (float) $v;
	if ( 0.0 === $v ) {
		return 0;
	}
	if ( $v <= 1 ) {
		return 1;
	}
	if ( $v <= 2.5 ) {
		return 2;
	}
	if ( $v <= 4.5 ) {
		return 3;
	}
	if ( $v <= 7 ) {
		return 4;
	}
	return 5;
}

/**
 * One utility class from Tailwind / Bulma / Foundation to Bootstrap 5, or null if there's no mechanical equivalent.
 * $bp is the Bootstrap breakpoint infix ('' or 'md-').
 */
function lccb_tpl_map_class( $c ) {
	$bp = '';
	$c  = trim( $c, '!' );
	if ( preg_match( '/^(sm|md|lg|xl|2xl|xxl):(.+)$/', $c, $m ) ) {
		$bp = LCCB_TW_BREAKPOINTS[ $m[1] ] . '-';
		$c  = trim( $m[2], '!' );
	} elseif ( false !== strpos( $c, ':' ) ) {
		return null; // hover:, focus:, before: … need CSS
	}
	static $simple = array(
		'flex' => 'd-flex', 'inline-flex' => 'd-inline-flex', 'block' => 'd-block', 'inline-block' => 'd-inline-block', 'inline' => 'd-inline', 'hidden' => 'd-none', 'grid' => 'd-grid', 'table' => 'd-table',
		'flex-col' => 'flex-column', 'flex-row' => 'flex-row', 'flex-wrap' => 'flex-wrap', 'flex-nowrap' => 'flex-nowrap', 'flex-col-reverse' => 'flex-column-reverse', 'flex-row-reverse' => 'flex-row-reverse',
		'grow' => 'flex-grow-1', 'flex-grow' => 'flex-grow-1', 'grow-0' => 'flex-grow-0', 'shrink-0' => 'flex-shrink-0', 'flex-shrink-0' => 'flex-shrink-0', 'flex-1' => 'flex-fill',
		'items-center' => 'align-items-center', 'items-start' => 'align-items-start', 'items-end' => 'align-items-end', 'items-stretch' => 'align-items-stretch', 'items-baseline' => 'align-items-baseline',
		'justify-center' => 'justify-content-center', 'justify-between' => 'justify-content-between', 'justify-start' => 'justify-content-start', 'justify-end' => 'justify-content-end', 'justify-around' => 'justify-content-around', 'justify-evenly' => 'justify-content-evenly',
		'self-center' => 'align-self-center', 'self-start' => 'align-self-start', 'self-end' => 'align-self-end', 'content-center' => 'align-content-center',
		'text-center' => 'text-center', 'text-left' => 'text-start', 'text-right' => 'text-end', 'text-start' => 'text-start', 'text-end' => 'text-end',
		'font-bold' => 'fw-bold', 'font-semibold' => 'fw-semibold', 'font-medium' => 'fw-medium', 'font-normal' => 'fw-normal', 'font-light' => 'fw-light', 'font-extrabold' => 'fw-bolder', 'italic' => 'fst-italic', 'not-italic' => 'fst-normal',
		'uppercase' => 'text-uppercase', 'lowercase' => 'text-lowercase', 'capitalize' => 'text-capitalize', 'underline' => 'text-decoration-underline', 'no-underline' => 'text-decoration-none', 'line-through' => 'text-decoration-line-through',
		'truncate' => 'text-truncate', 'whitespace-nowrap' => 'text-nowrap', 'break-words' => 'text-break',
		'text-xs' => 'small', 'text-sm' => 'small', 'text-lg' => 'fs-5', 'text-xl' => 'fs-5', 'text-2xl' => 'fs-4', 'text-3xl' => 'fs-3', 'text-4xl' => 'fs-2', 'text-5xl' => 'fs-1', 'text-6xl' => 'display-5', 'text-7xl' => 'display-4',
		'leading-none' => 'lh-1', 'leading-tight' => 'lh-sm', 'leading-snug' => 'lh-sm', 'leading-normal' => 'lh-base', 'leading-relaxed' => 'lh-lg', 'leading-loose' => 'lh-lg',
		'w-full' => 'w-100', 'w-auto' => 'w-auto', 'w-1/2' => 'w-50', 'w-1/4' => 'w-25', 'w-3/4' => 'w-75', 'h-full' => 'h-100', 'h-auto' => 'h-auto', 'min-h-screen' => 'min-vh-100', 'h-screen' => 'vh-100', 'max-w-full' => 'mw-100', 'max-h-full' => 'mh-100',
		'relative' => 'position-relative', 'absolute' => 'position-absolute', 'fixed' => 'position-fixed', 'sticky' => 'position-sticky', 'static' => 'position-static',
		'top-0' => 'top-0', 'bottom-0' => 'bottom-0', 'left-0' => 'start-0', 'right-0' => 'end-0', 'inset-0' => 'top-0 start-0 w-100 h-100',
		'overflow-hidden' => 'overflow-hidden', 'overflow-auto' => 'overflow-auto', 'overflow-visible' => 'overflow-visible', 'overflow-x-auto' => 'overflow-x-auto',
		'rounded' => 'rounded', 'rounded-sm' => 'rounded-1', 'rounded-md' => 'rounded-2', 'rounded-lg' => 'rounded-3', 'rounded-xl' => 'rounded-4', 'rounded-2xl' => 'rounded-5', 'rounded-3xl' => 'rounded-5', 'rounded-full' => 'rounded-pill', 'rounded-none' => 'rounded-0',
		'shadow' => 'shadow-sm', 'shadow-sm' => 'shadow-sm', 'shadow-md' => 'shadow', 'shadow-lg' => 'shadow-lg', 'shadow-xl' => 'shadow-lg', 'shadow-2xl' => 'shadow-lg', 'shadow-none' => 'shadow-none',
		'border' => 'border', 'border-0' => 'border-0', 'border-t' => 'border-top', 'border-b' => 'border-bottom', 'border-l' => 'border-start', 'border-r' => 'border-end',
		'list-none' => 'list-unstyled', 'text-white' => 'text-white', 'bg-white' => 'bg-white', 'text-black' => 'text-black', 'bg-black' => 'bg-black', 'bg-transparent' => 'bg-transparent',
		'object-cover' => 'object-fit-cover', 'object-contain' => 'object-fit-contain', 'pointer-events-none' => 'pe-none', 'select-none' => 'user-select-none', 'opacity-50' => 'opacity-50', 'opacity-75' => 'opacity-75', 'opacity-25' => 'opacity-25', 'opacity-0' => 'opacity-0',
		'z-10' => 'z-1', 'z-20' => 'z-2', 'z-30' => 'z-3', 'z-0' => 'z-0', 'container' => 'container', 'mx-auto' => 'mx-auto', 'ml-auto' => 'ms-auto', 'mr-auto' => 'me-auto', 'ms-auto' => 'ms-auto', 'me-auto' => 'me-auto', 'my-auto' => 'my-auto', 'mt-auto' => 'mt-auto', 'mb-auto' => 'mb-auto',
		// Bulma
		'columns' => 'row', 'column' => 'col', 'is-half' => 'col-6', 'is-one-third' => 'col-4', 'is-two-thirds' => 'col-8', 'is-one-quarter' => 'col-3', 'is-three-quarters' => 'col-9', 'is-full' => 'col-12', 'is-narrow' => 'col-auto',
		'has-text-centered' => 'text-center', 'has-text-right' => 'text-end', 'has-text-left' => 'text-start', 'has-text-weight-bold' => 'fw-bold', 'has-text-white' => 'text-white', 'is-uppercase' => 'text-uppercase', 'is-hidden' => 'd-none',
		'button' => 'btn', 'is-primary' => 'btn-primary', 'is-link' => 'btn-link', 'is-light' => 'btn-light', 'is-dark' => 'btn-dark', 'is-large' => 'btn-lg', 'is-small' => 'btn-sm', 'is-outlined' => 'btn-outline-primary', 'is-rounded' => 'rounded-pill',
		'section' => 'py-5', 'hero' => 'py-5', 'title' => 'h2', 'subtitle' => 'lead', 'notification' => 'alert', 'box' => 'card card-body', 'level' => 'd-flex justify-content-between align-items-center', 'is-vcentered' => 'align-items-center', 'is-centered' => 'justify-content-center', 'is-multiline' => 'flex-wrap',
		// Foundation
		'grid-x' => 'row', 'grid-container' => 'container', 'cell' => 'col', 'auto' => 'col', 'shrink' => 'col-auto', 'align-center' => 'justify-content-center', 'align-middle' => 'align-items-center', 'align-justify' => 'justify-content-between', 'callout' => 'alert alert-secondary', 'hollow' => 'btn-outline-primary', 'expanded' => 'w-100', 'show-for-sr' => 'visually-hidden',
	);
	if ( isset( $simple[ $c ] ) ) {
		$v = $simple[ $c ];
		if ( $bp ) {
			// Only display/flex/text-align/spacing-style utilities have responsive variants in Bootstrap.
			if ( preg_match( '/^(d|text|justify-content|align-items|align-self|flex|float|order)-/', $v ) ) {
				return preg_replace( '/^(d|text|justify-content|align-items|align-self|flex|float|order)-/', '$1-' . $bp, $v );
			}
			if ( preg_match( '/^(col)(-\d+|-auto)?$/', $v, $m ) ) {
				return 'col-' . rtrim( $bp, '-' ) . ( isset( $m[2] ) ? $m[2] : '' );
			}
			return null;
		}
		return $v;
	}
	// Fractions of 12 (and halves/thirds) inside a wrapping flex row are Bootstrap columns.
	if ( preg_match( '/^w-(\d+)\/(\d+)$/', $c, $m ) && (int) $m[2] > 0 && ( $bp || 12 === (int) $m[2] || 3 === (int) $m[2] ) ) {
		$cols = (int) round( 12 * (int) $m[1] / (int) $m[2] );
		return $cols >= 1 && $cols <= 12 ? 'col-' . $bp . $cols : null;
	}
	// The negative side margin that pairs with column padding is Bootstrap's .row.
	if ( ! $bp && preg_match( '/^(-mx-[34]|mx-\[-1[25]px\]|mx-\[-\.?75rem\])$/', $c ) ) {
		return 'row';
	}
	// Spacing: m/p, x/y/t/b/l/r/s/e, Tailwind scale.
	if ( preg_match( '/^(-?)([mp])([xytblrse]?)-(\d+(\.\d+)?|px)$/', $c, $m ) ) {
		$step = lccb_tw_step( $m[4] );
		if ( null === $step || ( '-' === $m[1] ) ) {
			return null;
		}
		$side = strtr( $m[3], array( 'l' => 's', 'r' => 'e' ) );
		return $m[2] . $side . '-' . $bp . $step;
	}
	if ( preg_match( '/^gap(-[xy])?-(\d+(\.\d+)?)$/', $c, $m ) ) {
		$step = lccb_tw_step( $m[2] );
		$pre  = array( '' => 'gap', '-x' => 'column-gap', '-y' => 'row-gap' )[ $m[1] ];
		return null !== $step ? $pre . '-' . $bp . $step : null;
	}
	// Foundation / Bulma sized columns.
	if ( preg_match( '/^(small|medium|large|xlarge)-(\d+)$/', $c, $m ) ) {
		$b = array( 'small' => '', 'medium' => 'md-', 'large' => 'lg-', 'xlarge' => 'xl-' )[ $m[1] ];
		return 'col-' . $b . $m[2];
	}
	if ( preg_match( '/^is-(\d+)(-(mobile|tablet|desktop|widescreen))?$/', $c, $m ) ) {
		$b = isset( $m[3] ) ? array( 'mobile' => '', 'tablet' => 'md-', 'desktop' => 'lg-', 'widescreen' => 'xl-' )[ $m[3] ] : '';
		return 'col-' . $b . $m[1];
	}
	return null;
}

/** Drop responsive variants that repeat the value already in force (pt-5 pt-md-5 pt-lg-5 → pt-5). */
function lccb_tpl_collapse_breakpoints( array $classes ) {
	$order  = array( '' => 0, 'sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4, 'xxl' => 5 );
	$groups = array();
	foreach ( $classes as $i => $c ) {
		if ( preg_match( '/^(m[tbsexy]?|p[tbsexy]?|gap|row-gap|column-gap|d|text|fw|justify-content|align-items|flex|col|order)(?:-(sm|md|lg|xl|xxl))?-([\w-]+)$/', $c, $m ) ) {
			if ( 'col' === $m[1] || ( 'flex' === $m[1] && ! in_array( $m[3], array( 'column', 'row', 'wrap', 'nowrap' ), true ) ) ) {
				continue;
			}
			$groups[ $m[1] ][ $order[ isset( $m[2] ) ? $m[2] : '' ] ] = array( $i, $m[3] );
		}
	}
	$drop = array();
	foreach ( $groups as $g ) {
		ksort( $g );
		$current = null;
		foreach ( $g as $entry ) {
			if ( null !== $current && $entry[1] === $current ) {
				$drop[ $entry[0] ] = true;
			}
			$current = $entry[1];
		}
	}
	$classes = array_values( array_diff_key( $classes, $drop ) );
	// Tailwind's "w-full lg:w-10/12" is a full-width column on phones; .row already wraps and is flex.
	if ( preg_grep( '/^col-(sm|md|lg|xl|xxl)-\d+$/', $classes ) && in_array( 'w-100', $classes, true ) ) {
		$classes = array_values( array_diff( $classes, array( 'w-100' ) ) );
		if ( ! preg_grep( '/^col(-\d+)?$/', $classes ) ) {
			array_unshift( $classes, 'col-12' );
		}
	}
	if ( in_array( 'row', $classes, true ) ) {
		$classes = array_values( array_diff( $classes, array( 'd-flex', 'flex-wrap' ) ) );
	}
	return $classes;
}

/**
 * Convert a section's classes to Bootstrap 5 where it's mechanical. Unmapped classes are KEPT (they do nothing without
 * the template CSS) and reported, so Claude finishes them by hand. Tailwind grids become .row + .col-* children.
 */
function lccb_tpl_convert( DOMElement $el ) {
	$xp       = new DOMXPath( $el->ownerDocument );
	$mapped   = array();
	$unmapped = array();
	$nodes    = array_merge( array( $el ), iterator_to_array( $xp->query( './/*[@class]', $el ) ) );
	$known    = lccb_tpl_bootstrap_classes();
	foreach ( $nodes as $n ) {
		$classes = preg_split( '/\s+/', trim( $n->getAttribute( 'class' ) ) );
		// grid grid-cols-N (md:grid-cols-M) → row + per-child col classes.
		$cols = array();
		foreach ( $classes as $c ) {
			if ( preg_match( '/^!?(?:(sm|md|lg|xl|2xl):)?!?grid-cols-(\d+)$/', $c, $m ) ) {
				$cols[ $m[1] ? LCCB_TW_BREAKPOINTS[ $m[1] ] : '' ] = (int) $m[2];
			}
		}
		$out = array();
		foreach ( $classes as $c ) {
			if ( '' === $c ) {
				continue;
			}
			if ( $cols && preg_match( '/^!?(?:(sm|md|lg|xl|2xl):)?!?(grid|grid-cols-\d+)$/', $c ) ) {
				continue;
			}
			$new = lccb_tpl_map_class( $c );
			if ( null === $new && isset( $known[ trim( $c, '!' ) ] ) ) {
				$out[] = trim( $c, '!' ); // Bootstrap defines it too (lead, btn, container…)
			} elseif ( null === $new ) {
				$out[]          = $c;
				$unmapped[ $c ] = ( isset( $unmapped[ $c ] ) ? $unmapped[ $c ] : 0 ) + 1;
			} else {
				$out[] = $new;
				if ( $new !== $c ) {
					$mapped[ "$c → $new" ] = ( isset( $mapped[ "$c → $new" ] ) ? $mapped[ "$c → $new" ] : 0 ) + 1;
				}
			}
		}
		if ( $cols ) {
			$out[] = 'row';
			$colcls = array();
			foreach ( $cols as $bp => $n_cols ) {
				$w        = max( 1, (int) floor( 12 / max( 1, $n_cols ) ) );
				$colcls[] = 'col' . ( $bp ? '-' . $bp : '' ) . '-' . $w;
			}
			if ( ! isset( $cols[''] ) ) {
				array_unshift( $colcls, 'col-12' );
			}
			foreach ( lccb_tpl_kids( $n ) as $child ) {
				$child->setAttribute( 'class', trim( $child->getAttribute( 'class' ) . ' ' . implode( ' ', $colcls ) ) );
			}
			$mapped[ 'grid-cols → row + ' . implode( ' ', $colcls ) ] = ( isset( $mapped[ 'grid-cols → row + ' . implode( ' ', $colcls ) ] ) ? $mapped[ 'grid-cols → row + ' . implode( ' ', $colcls ) ] : 0 ) + 1;
		}
		$n->setAttribute( 'class', implode( ' ', lccb_tpl_collapse_breakpoints( array_unique( preg_split( '/\s+/', implode( ' ', $out ) ) ) ) ) );
	}
	arsort( $unmapped );
	arsort( $mapped );
	return array( 'mapped' => array_slice( $mapped, 0, 40, true ), 'unmapped' => $unmapped );
}
