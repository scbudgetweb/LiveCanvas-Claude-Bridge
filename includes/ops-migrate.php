<?php
/**
 * Migrate from an old site: crawl it politely, extract each page's content and SEO data into a local store,
 * import its images in bulk, and build a redirect map (old URL → new page) to deploy with the new site.
 *
 * The crawl is stored in uploads/lccb-migrations/<host>/crawl.json (local only), so it can be re-read without
 * crawling again. Crawling runs in time-boxed batches: a big site is finished by calling the scan again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_MIG_UA          = 'Mozilla/5.0 (compatible; LC-Claude-Bridge site migration; +https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge)';
const LCCB_MIG_BUDGET_SECS = 80;
const LCCB_MIG_PAUSE_MS    = 250;
const LCCB_MIG_SOCIAL      = '/(facebook|instagram|twitter|x|linkedin|tiktok|youtube|pinterest|threads|whatsapp)\.com|wa\.me|youtu\.be/i';

// ───────────────────────── Store ─────────────────────────

function lccb_mig_dir( $host ) {
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'lccb-migrations/' . preg_replace( '/[^a-z0-9.-]/', '', strtolower( $host ) );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		$base = dirname( $dir );
		file_put_contents( $base . '/.htaccess', "Require all denied\n" );
		file_put_contents( $base . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

function lccb_mig_load( $host ) {
	$f = lccb_mig_dir( $host ) . '/crawl.json';
	$j = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
	return is_array( $j ) ? $j : null;
}

function lccb_mig_save( array $crawl ) {
	$crawl['updated'] = gmdate( 'c' );
	file_put_contents( lccb_mig_dir( $crawl['host'] ) . '/crawl.json', wp_json_encode( $crawl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/** The crawl to use: by host, or the most recent one. */
function lccb_mig_current( $host = '' ) {
	$host = strtolower( preg_replace( '#^https?://#', '', trim( (string) $host ) ) );
	$host = preg_replace( '#/.*$#', '', $host );
	if ( $host ) {
		$c = lccb_mig_load( $host ) ?: lccb_mig_load( preg_replace( '/^www\./', '', $host ) ) ?: lccb_mig_load( 'www.' . $host );
		if ( ! $c ) {
			throw new Exception( "No crawl of $host yet: run lc_migrate_scan {url} first." );
		}
		return $c;
	}
	$up    = wp_upload_dir( null, false );
	$files = glob( trailingslashit( $up['basedir'] ) . 'lccb-migrations/*/crawl.json' ) ?: array();
	usort( $files, function ( $a, $b ) {
		return filemtime( $b ) - filemtime( $a );
	} );
	if ( ! $files ) {
		throw new Exception( 'No old site has been scanned yet: run lc_migrate_scan {url} first.' );
	}
	return json_decode( (string) file_get_contents( $files[0] ), true );
}

// ───────────────────────── Fetching ─────────────────────────

function lccb_mig_is_local_host( $host ) {
	return (bool) preg_match( '/(\.test|\.local|\.localhost)$|^localhost$/i', $host );
}

/** Public internet, or a local Herd copy of the old site (*.test). */
function lccb_mig_url_ok( $url ) {
	$p = wp_parse_url( $url );
	if ( ! $p || empty( $p['host'] ) || ! in_array( strtolower( isset( $p['scheme'] ) ? $p['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	return lccb_mig_is_local_host( $p['host'] ) || lccb_safe_remote_url( $url );
}

/** @return array{code: int, body: string, url: string, type: string, error?: string} */
function lccb_mig_get( $url, $max_bytes = 3145728 ) {
	if ( ! lccb_mig_url_ok( $url ) ) {
		return array( 'code' => 0, 'body' => '', 'url' => $url, 'type' => '', 'error' => 'not a public (or local .test) URL' );
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	$res  = wp_remote_get( $url, array(
		'timeout'             => 15,
		'redirection'         => 5,
		'user-agent'          => LCCB_MIG_UA,
		'sslverify'           => ! lccb_mig_is_local_host( $host ),
		'limit_response_size' => $max_bytes,
		'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ),
	) );
	if ( is_wp_error( $res ) ) {
		return array( 'code' => 0, 'body' => '', 'url' => $url, 'type' => '', 'error' => $res->get_error_message() );
	}
	$final = $url;
	if ( isset( $res['http_response'] ) && is_object( $res['http_response'] ) && method_exists( $res['http_response'], 'get_response_object' ) ) {
		$obj   = $res['http_response']->get_response_object();
		$final = isset( $obj->url ) ? $obj->url : $url;
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $res ),
		'body' => (string) wp_remote_retrieve_body( $res ),
		'url'  => $final,
		'type' => strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ),
	);
}

/** Canonical form for de-duplicating: no fragment, no tracking params, lowercase host, no trailing slash (except root). */
function lccb_mig_norm( $url ) {
	$p = wp_parse_url( $url );
	if ( ! $p || empty( $p['host'] ) ) {
		return '';
	}
	$path  = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
	$path  = '/' === $path ? '/' : rtrim( $path, '/' );
	$query = '';
	if ( ! empty( $p['query'] ) ) {
		parse_str( $p['query'], $q );
		foreach ( array_keys( $q ) as $k ) {
			if ( preg_match( '/^(utm_|fbclid|gclid|mc_|_ga|ref$|replytocom)/i', $k ) ) {
				unset( $q[ $k ] );
			}
		}
		ksort( $q );
		$query = $q ? '?' . http_build_query( $q ) : '';
	}
	return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . $query;
}

function lccb_mig_abs( $href, $base ) {
	$href = trim( html_entity_decode( (string) $href ) );
	if ( '' === $href || preg_match( '#^(mailto:|tel:|javascript:|data:|\#)#i', $href ) ) {
		return '';
	}
	if ( preg_match( '#^https?://#i', $href ) ) {
		return $href;
	}
	$b = wp_parse_url( $base );
	if ( 0 === strpos( $href, '//' ) ) {
		return $b['scheme'] . ':' . $href;
	}
	$origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
	if ( '/' === $href[0] ) {
		return $origin . $href;
	}
	$dir   = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
	$parts = array();
	foreach ( explode( '/', $dir . $href ) as $seg ) {
		if ( '..' === $seg ) {
			array_pop( $parts );
		} elseif ( '.' !== $seg ) {
			$parts[] = $seg;
		}
	}
	return $origin . implode( '/', $parts );
}

function lccb_mig_same_site( $url, $host ) {
	$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return preg_replace( '/^www\./', '', $h ) === preg_replace( '/^www\./', '', strtolower( $host ) );
}

function lccb_mig_crawlable( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|docx?|xlsx?|pptx?|zip|rar|mp[34]|mov|avi|webm|css|js|json|xml|txt|woff2?|ttf|eot)$/i', $path ) ) {
		return false;
	}
	return ! preg_match( '#/(wp-admin|wp-login\.php|wp-json|feed|xmlrpc\.php|cart|checkout|my-account|basket)(/|$)|/page/\d+/?$|[?&](add-to-cart|s|replytocom|share)=#i', $url );
}

// ───────────────────────── robots.txt + sitemaps ─────────────────────────

function lccb_mig_robots( $origin ) {
	$r   = lccb_mig_get( $origin . '/robots.txt', 262144 );
	$out = array( 'disallow' => array(), 'sitemaps' => array() );
	if ( 200 !== $r['code'] ) {
		return $out;
	}
	$applies = false;
	foreach ( preg_split( '/\r?\n/', $r['body'] ) as $line ) {
		$line = trim( preg_replace( '/#.*$/', '', $line ) );
		if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
			$applies = '*' === trim( $m[1] ) || false !== stripos( $m[1], 'claude' );
		} elseif ( $applies && preg_match( '/^disallow:\s*(\S+)/i', $line, $m ) ) {
			$out['disallow'][] = $m[1];
		} elseif ( preg_match( '/^sitemap:\s*(\S+)/i', $line, $m ) ) {
			$out['sitemaps'][] = $m[1];
		}
	}
	return $out;
}

function lccb_mig_allowed( $url, array $disallow ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
	foreach ( $disallow as $rule ) {
		$re = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $rule, '#' ) ) . '#';
		if ( preg_match( $re, $path ) ) {
			return false;
		}
	}
	return true;
}

/** Page URLs from the site's sitemap(s), following sitemap indexes. */
function lccb_mig_sitemap_urls( $origin, array $hints, $limit ) {
	$queue = array_merge( $hints, array( $origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml' ) );
	$seen  = array();
	$urls  = array();
	$used  = array();
	while ( $queue && count( $seen ) < 25 && count( $urls ) < $limit * 3 ) {
		$sm = array_shift( $queue );
		if ( isset( $seen[ $sm ] ) ) {
			continue;
		}
		$seen[ $sm ] = true;
		$r           = lccb_mig_get( $sm, 5242880 );
		if ( 200 !== $r['code'] || false === stripos( $r['body'], '<loc' ) ) {
			continue;
		}
		$used[] = $sm;
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $r['body'], $m );
		$is_index = false !== stripos( $r['body'], '<sitemapindex' );
		foreach ( $m[1] as $loc ) {
			$loc = html_entity_decode( $loc );
			if ( $is_index ) {
				// Skip sitemaps of attachments, tags, authors: they're not pages to migrate.
				if ( ! preg_match( '/(attachment|tag|author|category|product_tag|format)[-_]?sitemap|users|taxonom/i', $loc ) ) {
					$queue[] = $loc;
				}
			} else {
				$urls[] = $loc;
			}
		}
		if ( $urls && ! $is_index && count( $used ) >= 1 && ! $queue ) {
			break;
		}
	}
	return array( 'urls' => array_values( array_unique( $urls ) ), 'sitemaps' => $used );
}

// ───────────────────────── Extracting one page ─────────────────────────

/** Visible text, with a space between elements so "<span>Sarah</span><small>Academy</small>" doesn't run together. */
function lccb_mig_text( DOMNode $n ) {
	$parts = array();
	foreach ( ( new DOMXPath( $n->ownerDocument ) )->query( './/text()', $n ) as $t ) {
		$parts[] = $t->nodeValue;
	}
	$text = $parts ? implode( ' ', $parts ) : $n->textContent;
	$text = preg_replace( '/\s+([,.;:!?)])/u', '$1', preg_replace( '/\s+/u', ' ', $text ) );
	return trim( $text );
}

function lccb_mig_extract( $html, $url ) {
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( ( preg_match( '/<meta[^>]+charset/i', substr( $html, 0, 4096 ) ) ? '' : '<?xml encoding="utf-8"?>' ) . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp   = new DOMXPath( $dom );
	$meta = function ( $expr ) use ( $xp ) {
		$n = $xp->query( $expr );
		return $n && $n->length ? trim( $n->item( 0 )->nodeValue ) : null;
	};
	$rec = array(
		'title'       => $meta( '//title' ),
		'description' => $meta( '//meta[translate(@name,"DESCRIPTION","description")="description"]/@content' ),
		'canonical'   => $meta( '//link[@rel="canonical"]/@href' ),
		'robots'      => $meta( '//meta[translate(@name,"ROBOTS","robots")="robots"]/@content' ),
		'lang'        => $meta( '//html/@lang' ),
		'og'          => array_filter( array(
			'title'       => $meta( '//meta[@property="og:title"]/@content' ),
			'description' => $meta( '//meta[@property="og:description"]/@content' ),
			'image'       => $meta( '//meta[@property="og:image"]/@content' ),
			'type'        => $meta( '//meta[@property="og:type"]/@content' ),
		) ),
		'published'   => $meta( '//meta[@property="article:published_time"]/@content' ),
	);

	// Structured data.
	$jsonld = array();
	foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
		$j = json_decode( trim( $s->textContent ), true );
		if ( is_array( $j ) ) {
			$jsonld[] = $j;
		}
	}
	$types = array();
	array_walk_recursive( $jsonld, function ( $v, $k ) use ( &$types ) {
		if ( '@type' === $k && is_string( $v ) ) {
			$types[ $v ] = true;
		}
	} );
	$rec['schema_types'] = array_keys( $types );
	$rec['jsonld']       = $jsonld ? substr( wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 6000 ) : null;

	// Outline.
	$rec['headings'] = array();
	foreach ( $xp->query( '//h1|//h2|//h3' ) as $h ) {
		$t = lccb_mig_text( $h );
		if ( '' !== $t && count( $rec['headings'] ) < 60 ) {
			$rec['headings'][] = $h->tagName . ': ' . mb_substr( $t, 0, 140 );
		}
	}

	// Navigation (first nav-ish block), links, contact links.
	$nav = $xp->query( '//header//nav | //nav[contains(@class,"main") or contains(@class,"primary") or @role="navigation"] | //nav' );
	$rec['nav'] = array();
	if ( $nav && $nav->length ) {
		foreach ( $xp->query( './/a[@href]', $nav->item( 0 ) ) as $a ) {
			$abs = lccb_mig_abs( $a->getAttribute( 'href' ), $url );
			$t   = lccb_mig_text( $a );
			if ( $abs && '' !== $t && count( $rec['nav'] ) < 40 ) {
				$rec['nav'][] = array( 'text' => mb_substr( $t, 0, 60 ), 'url' => $abs );
			}
		}
	}
	$internal = array();
	$external = array();
	$contact  = array( 'emails' => array(), 'phones' => array(), 'social' => array() );
	foreach ( $xp->query( '//a[@href]' ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		if ( 0 === stripos( $href, 'mailto:' ) ) {
			$contact['emails'][ strtolower( preg_replace( '/\?.*$/', '', substr( $href, 7 ) ) ) ] = true;
			continue;
		}
		if ( 0 === stripos( $href, 'tel:' ) ) {
			$contact['phones'][ preg_replace( '/[^\d+]/', '', substr( $href, 4 ) ) ] = true;
			continue;
		}
		$abs = lccb_mig_abs( $href, $url );
		if ( ! $abs ) {
			continue;
		}
		if ( lccb_mig_same_site( $abs, (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$internal[ $abs ] = true;
		} else {
			$external[ $abs ] = true;
			if ( preg_match( LCCB_MIG_SOCIAL, (string) wp_parse_url( $abs, PHP_URL_HOST ) ) ) {
				$contact['social'][ preg_replace( '#/+$#', '', $abs ) ] = true;
			}
		}
	}
	$rec['links_internal'] = array_slice( array_keys( $internal ), 0, 300 );
	$rec['links_external'] = array_slice( array_keys( $external ), 0, 60 );
	$rec['contact']        = array_map( 'array_keys', $contact );

	// Forms.
	$rec['forms'] = array();
	foreach ( $xp->query( '//form' ) as $i => $f ) {
		$cls    = $f->getAttribute( 'class' ) . ' ' . $f->getAttribute( 'id' );
		$fields = array();
		foreach ( $xp->query( './/input|.//select|.//textarea', $f ) as $in ) {
			$type = strtolower( $in->getAttribute( 'type' ) ?: $in->tagName );
			if ( in_array( $type, array( 'hidden', 'submit', 'button', 'reset', 'image' ), true ) ) {
				continue;
			}
			$label = '';
			if ( $in->getAttribute( 'id' ) ) {
				$l     = $xp->query( '//label[@for="' . $in->getAttribute( 'id' ) . '"]' );
				$label = $l && $l->length ? lccb_mig_text( $l->item( 0 ) ) : '';
			}
			$label    = $label ?: ( $in->getAttribute( 'aria-label' ) ?: $in->getAttribute( 'placeholder' ) );
			$fields[] = array_filter( array( 'name' => $in->getAttribute( 'name' ), 'type' => $type, 'label' => mb_substr( $label, 0, 80 ), 'required' => $in->hasAttribute( 'required' ) || false !== strpos( $in->getAttribute( 'class' ), 'required' ) ?: null ) );
		}
		if ( ! $fields || ( 1 === count( $fields ) && in_array( $fields[0]['type'], array( 'search' ), true ) ) || preg_match( '/search/i', $cls ) ) {
			continue;
		}
		$plugin = preg_match( '/(wpforms|gform|wpcf7|forminator|elementor-form|fluentform|ninja-forms|formidable|hs-form|mc4wp|mailchimp)/i', $cls . ' ' . $f->getAttribute( 'action' ), $pm ) ? strtolower( $pm[1] ) : null;
		$rec['forms'][] = array_filter( array( 'action' => lccb_mig_abs( $f->getAttribute( 'action' ), $url ) ?: null, 'method' => strtolower( $f->getAttribute( 'method' ) ?: 'get' ), 'plugin' => $plugin, 'fields' => $fields ) );
	}

	// Images (content + backgrounds + og:image).
	$imgs = array();
	foreach ( $xp->query( '//img' ) as $img ) {
		$src = $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'data-lazy-src' ) ?: $img->getAttribute( 'src' );
		if ( $img->getAttribute( 'srcset' ) || $img->getAttribute( 'data-srcset' ) ) {
			$best = 0;
			foreach ( explode( ',', $img->getAttribute( 'data-srcset' ) ?: $img->getAttribute( 'srcset' ) ) as $cand ) {
				$bits = preg_split( '/\s+/', trim( $cand ) );
				$w    = isset( $bits[1] ) ? (int) $bits[1] : 0;
				if ( $w > $best ) {
					$best = $w;
					$src  = $bits[0];
				}
			}
		}
		$abs = lccb_mig_abs( $src, $url );
		if ( $abs && ! preg_match( '/\.svg(\?|$)|data:|gravatar|pixel|spacer|1x1|facebook\.com\/tr/i', $abs ) && ! isset( $imgs[ $abs ] ) ) {
			$imgs[ $abs ] = array_filter( array( 'src' => $abs, 'alt' => trim( $img->getAttribute( 'alt' ) ) ?: null, 'width' => (int) $img->getAttribute( 'width' ) ?: null, 'height' => (int) $img->getAttribute( 'height' ) ?: null ) );
		}
	}
	foreach ( $xp->query( '//*[contains(@style,"url(")]' ) as $n ) {
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+)/i', $n->getAttribute( 'style' ), $m ) ) {
			foreach ( $m[1] as $u ) {
				$abs = lccb_mig_abs( $u, $url );
				if ( $abs && ! isset( $imgs[ $abs ] ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $abs ) ) {
					$imgs[ $abs ] = array( 'src' => $abs, 'background' => true );
				}
			}
		}
	}
	if ( ! empty( $rec['og']['image'] ) ) {
		$abs = lccb_mig_abs( $rec['og']['image'], $url );
		if ( $abs && ! isset( $imgs[ $abs ] ) ) {
			$imgs[ $abs ] = array( 'src' => $abs, 'og' => true );
		}
	}
	$rec['images'] = array_slice( array_values( $imgs ), 0, 150 );

	// Main content as blocks, without the chrome.
	foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//svg|//template|//header|//footer|//nav|//aside|//*[@role="navigation" or @role="banner" or @role="contentinfo" or @aria-hidden="true"]|//*[contains(@class,"cookie") or contains(@id,"cookie") or contains(@class,"gdpr") or contains(@class,"modal") or contains(@class,"popup") or contains(@class,"skip-link") or contains(@class,"screen-reader") or contains(@class,"sr-only")]' ) ) as $n ) {
		if ( $n->parentNode ) {
			$n->parentNode->removeChild( $n );
		}
	}
	$main = null;
	foreach ( array( '//main', '//*[@role="main"]', '//article', '//*[@id="content"]', '//*[contains(@class,"entry-content")]', '//*[contains(@class,"site-content")]', '//body' ) as $q ) {
		$r = $xp->query( $q );
		if ( $r && $r->length && strlen( lccb_mig_text( $r->item( 0 ) ) ) > 80 ) {
			$main = $r->item( 0 );
			break;
		}
	}
	$blocks = array();
	if ( $main ) {
		lccb_mig_blocks( $main, $url, $blocks );
	}
	$rec['blocks']     = array_slice( $blocks, 0, 400 );
	$rec['word_count'] = str_word_count( implode( ' ', array_map( function ( $b ) {
		return isset( $b['text'] ) ? $b['text'] : ( isset( $b['items'] ) ? implode( ' ', $b['items'] ) : '' );
	}, $blocks ) ) );
	return $rec;
}

/** Walk content in document order, emitting headings, paragraphs, lists, quotes, images, buttons, embeds, tables. */
function lccb_mig_blocks( DOMNode $node, $url, array &$out ) {
	static $block_tags = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'img', 'iframe', 'table', 'form', 'figure', 'video' );
	foreach ( $node->childNodes as $c ) {
		if ( count( $out ) >= 400 ) {
			return;
		}
		if ( XML_TEXT_NODE === $c->nodeType ) {
			$t = trim( preg_replace( '/\s+/u', ' ', $c->textContent ) );
			if ( mb_strlen( $t ) > 25 ) {
				$out[] = array( 'type' => 'p', 'text' => mb_substr( $t, 0, 2000 ) );
			}
			continue;
		}
		if ( XML_ELEMENT_NODE !== $c->nodeType ) {
			continue;
		}
		$tag = strtolower( $c->tagName );
		if ( ! in_array( $tag, $block_tags, true ) ) {
			// A button-like link stands on its own; otherwise descend into containers.
			if ( 'a' === $tag && preg_match( '/\b(btn|button|cta)\b/i', $c->getAttribute( 'class' ) . ' ' . $c->getAttribute( 'role' ) ) && '' !== lccb_mig_text( $c ) ) {
				$out[] = array( 'type' => 'button', 'text' => mb_substr( lccb_mig_text( $c ), 0, 80 ), 'href' => lccb_mig_abs( $c->getAttribute( 'href' ), $url ) );
				continue;
			}
			lccb_mig_blocks( $c, $url, $out );
			continue;
		}
		switch ( $tag ) {
			case 'p':
				$t = lccb_mig_text( $c );
				if ( '' !== $t ) {
					$out[] = array( 'type' => 'p', 'text' => mb_substr( $t, 0, 2000 ) );
				}
				foreach ( $c->getElementsByTagName( 'img' ) as $img ) {
					lccb_mig_blocks_img( $img, $url, $out );
				}
				break;
			case 'ul':
			case 'ol':
				$items = array();
				foreach ( $c->childNodes as $li ) {
					if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->tagName ) && '' !== lccb_mig_text( $li ) ) {
						$items[] = mb_substr( lccb_mig_text( $li ), 0, 400 );
					}
				}
				if ( $items ) {
					$out[] = array( 'type' => $tag, 'items' => array_slice( $items, 0, 60 ) );
				}
				break;
			case 'blockquote':
				$out[] = array( 'type' => 'quote', 'text' => mb_substr( lccb_mig_text( $c ), 0, 2000 ) );
				break;
			case 'img':
				lccb_mig_blocks_img( $c, $url, $out );
				break;
			case 'figure':
			case 'video':
				foreach ( $c->getElementsByTagName( 'img' ) as $img ) {
					lccb_mig_blocks_img( $img, $url, $out );
				}
				$cap = $c->getElementsByTagName( 'figcaption' );
				if ( $cap->length ) {
					$out[] = array( 'type' => 'caption', 'text' => lccb_mig_text( $cap->item( 0 ) ) );
				}
				if ( 'video' === $tag ) {
					$out[] = array( 'type' => 'embed', 'src' => lccb_mig_abs( $c->getAttribute( 'src' ) ?: ( $c->getElementsByTagName( 'source' )->length ? $c->getElementsByTagName( 'source' )->item( 0 )->getAttribute( 'src' ) : '' ), $url ) );
				}
				break;
			case 'iframe':
				$src = lccb_mig_abs( $c->getAttribute( 'src' ) ?: $c->getAttribute( 'data-src' ), $url );
				if ( $src ) {
					$out[] = array( 'type' => 'embed', 'src' => $src );
				}
				break;
			case 'table':
				$rows = array();
				foreach ( $c->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = array();
					foreach ( $tr->childNodes as $td ) {
						if ( XML_ELEMENT_NODE === $td->nodeType ) {
							$cells[] = mb_substr( lccb_mig_text( $td ), 0, 200 );
						}
					}
					if ( $cells && count( $rows ) < 40 ) {
						$rows[] = $cells;
					}
				}
				if ( $rows ) {
					$out[] = array( 'type' => 'table', 'rows' => $rows );
				}
				break;
			case 'form':
				$out[] = array( 'type' => 'form' );
				break;
			default:
				$t = lccb_mig_text( $c );
				if ( '' !== $t ) {
					$out[] = array( 'type' => $tag, 'text' => mb_substr( $t, 0, 300 ) );
				}
		}
	}
}

function lccb_mig_blocks_img( DOMElement $img, $url, array &$out ) {
	$src = lccb_mig_abs( $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'data-lazy-src' ) ?: $img->getAttribute( 'src' ), $url );
	if ( $src && ! preg_match( '/\.svg(\?|$)|data:|pixel|spacer|1x1/i', $src ) ) {
		$out[] = array_filter( array( 'type' => 'img', 'src' => $src, 'alt' => trim( $img->getAttribute( 'alt' ) ) ?: null ) );
	}
}

// ───────────────────────── Scan (crawl) ─────────────────────────

function lccb_op_migrate_scan( array $args ) {
	$start = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
	if ( '' !== $start && ! preg_match( '#^https?://#i', $start ) ) {
		$start = 'https://' . $start;
	}
	$crawl = null;
	if ( $start ) {
		$host  = strtolower( (string) wp_parse_url( $start, PHP_URL_HOST ) );
		$crawl = empty( $args['fresh'] ) ? lccb_mig_load( $host ) : null;
	} else {
		$crawl = lccb_mig_current();
	}
	if ( ! $crawl ) {
		if ( ! lccb_mig_url_ok( $start ) ) {
			throw new Exception( 'Give the old site\'s public URL (or a local .test copy).' );
		}
		$home = lccb_mig_get( $start );
		if ( $home['code'] < 200 || $home['code'] >= 400 ) {
			throw new Exception( "Couldn't load $start: " . ( isset( $home['error'] ) ? $home['error'] : 'HTTP ' . $home['code'] ) );
		}
		$p      = wp_parse_url( $home['url'] );
		$origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$robots = lccb_mig_robots( $origin );
		$max    = isset( $args['max_pages'] ) ? max( 1, min( 500, (int) $args['max_pages'] ) ) : 100;
		$sm     = lccb_mig_sitemap_urls( $origin, $robots['sitemaps'], $max );
		$queue  = array( lccb_mig_norm( $home['url'] ) );
		foreach ( $sm['urls'] as $u ) {
			if ( lccb_mig_same_site( $u, $p['host'] ) && lccb_mig_crawlable( $u ) ) {
				$queue[] = lccb_mig_norm( $u );
			}
		}
		$crawl = array(
			'host'      => strtolower( $p['host'] ),
			'origin'    => $origin,
			'start'     => $home['url'],
			'started'   => gmdate( 'c' ),
			'status'    => 'running',
			'max_pages' => $max,
			'sitemaps'  => $sm['sitemaps'],
			'disallow'  => $robots['disallow'],
			'queue'     => array_values( array_unique( $queue ) ),
			'seen'      => array(),
			'pages'     => array(),
			'skipped'   => array(),
		);
	} elseif ( ! empty( $args['max_pages'] ) ) {
		$crawl['max_pages'] = max( count( $crawl['pages'] ), min( 500, (int) $args['max_pages'] ) );
		if ( count( $crawl['pages'] ) < $crawl['max_pages'] && $crawl['queue'] ) {
			$crawl['status'] = 'running';
		}
	}

	$t0      = microtime( true );
	$fetched = 0;
	while ( 'running' === $crawl['status'] && $crawl['queue'] && count( $crawl['pages'] ) < $crawl['max_pages'] && ( microtime( true ) - $t0 ) < LCCB_MIG_BUDGET_SECS ) {
		$url = array_shift( $crawl['queue'] );
		if ( isset( $crawl['seen'][ $url ] ) ) {
			continue;
		}
		$crawl['seen'][ $url ] = true;
		if ( ! lccb_mig_allowed( $url, $crawl['disallow'] ) ) {
			$crawl['skipped'][] = array( 'url' => $url, 'why' => 'robots.txt' );
			continue;
		}
		if ( $fetched ) {
			usleep( LCCB_MIG_PAUSE_MS * 1000 );
		}
		$r = lccb_mig_get( $url );
		$fetched++;
		if ( 200 !== $r['code'] || false === strpos( $r['type'], 'html' ) ) {
			$crawl['skipped'][] = array( 'url' => $url, 'why' => $r['code'] ? 'HTTP ' . $r['code'] . ( 200 === $r['code'] ? ' ' . $r['type'] : '' ) : ( isset( $r['error'] ) ? $r['error'] : 'failed' ) );
			continue;
		}
		$final = lccb_mig_norm( $r['url'] );
		if ( $final !== $url ) {
			$crawl['redirects'][ $url ] = $final;
			if ( isset( $crawl['pages'][ $final ] ) || ! lccb_mig_same_site( $final, $crawl['host'] ) ) {
				continue;
			}
			$crawl['seen'][ $final ] = true;
		}
		$rec = lccb_mig_extract( $r['body'], $r['url'] );
		if ( ! empty( $rec['robots'] ) && false !== stripos( $rec['robots'], 'noindex' ) ) {
			$rec['noindex'] = true;
		}
		$crawl['pages'][ $final ] = $rec;
		foreach ( $rec['links_internal'] as $l ) {
			$n = lccb_mig_norm( $l );
			if ( $n && ! isset( $crawl['seen'][ $n ] ) && lccb_mig_crawlable( $n ) && lccb_mig_same_site( $n, $crawl['host'] ) ) {
				$crawl['queue'][] = $n;
			}
		}
		$crawl['queue'] = array_values( array_unique( $crawl['queue'] ) );
	}
	if ( ! $crawl['queue'] || count( $crawl['pages'] ) >= $crawl['max_pages'] ) {
		$crawl['status'] = 'done';
		lccb_mig_mark_shared( $crawl );
	}
	lccb_mig_save( $crawl );

	$done = 'done' === $crawl['status'];
	return array(
		'site'       => $crawl['origin'],
		'status'     => $done ? 'done' : 'partial',
		'pages'      => count( $crawl['pages'] ),
		'this_call'  => $fetched . ' request(s) in ' . round( microtime( true ) - $t0 ) . 's',
		'queued'     => count( $crawl['queue'] ),
		'max_pages'  => $crawl['max_pages'],
		'sitemaps'   => $crawl['sitemaps'] ?: 'none found (followed links from the home page)',
		'skipped'    => array_slice( $crawl['skipped'], -15 ),
		'stored_in'  => str_replace( lccb_site_root() . '/', '', lccb_mig_dir( $crawl['host'] ) ) . '/crawl.json',
		'next'       => $done
			? 'Crawl complete. lc_migrate_site for the overview (page tree, page types, navigation, contact details), lc_migrate_page {url} for one page\'s content. ' . ( count( $crawl['queue'] ) ? 'More pages were found than max_pages: call lc_migrate_scan {url, max_pages} with a higher limit to continue.' : '' )
			: 'Not finished yet: call lc_migrate_scan again (no arguments needed) to continue where it stopped.',
	);
}

/** Blocks that repeat on most pages are site chrome (newsletter boxes, CTAs, widgets): flag them as shared. */
function lccb_mig_mark_shared( array &$crawl ) {
	$n = count( $crawl['pages'] );
	if ( $n < 3 ) {
		$crawl['shared'] = array();
		return;
	}
	$freq = array();
	foreach ( $crawl['pages'] as $rec ) {
		$seen = array();
		foreach ( $rec['blocks'] as $b ) {
			$k = md5( wp_json_encode( $b ) );
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ]   = true;
				$freq[ $k ] = ( isset( $freq[ $k ] ) ? $freq[ $k ] : 0 ) + 1;
			}
		}
	}
	$shared = array();
	foreach ( $freq as $k => $c ) {
		if ( $c >= max( 3, 0.5 * $n ) ) {
			$shared[ $k ] = $c;
		}
	}
	$crawl['shared'] = $shared;
}

// ───────────────────────── Reading the crawl ─────────────────────────

function lccb_mig_path( $url ) {
	$p = wp_parse_url( $url );
	return ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
}

function lccb_mig_guess_type( $url, array $rec ) {
	$path  = strtolower( lccb_mig_path( $url ) );
	$title = strtolower( (string) $rec['title'] );
	$hay   = $path . ' ' . $title;
	if ( '/' === $path ) {
		return 'home';
	}
	$rules = array(
		'contact'  => '/contact|get-in-touch|find-us|enquir/',
		'about'    => '/about|our-story|who-we-are|meet-/',
		'legal'    => '/privacy|cookie|terms|disclaimer|gdpr|accessibility-statement|refund|returns-policy/',
		'faq'      => '/faq|questions/',
		'pricing'  => '/pric|fees|rates|packages/',
		'gallery'  => '/gallery|portfolio|before-and-after|our-work/',
		'team'     => '/team|staff|people/',
		'blog'     => '/^\/(blog|news|articles|journal)\/?$/',
		'booking'  => '/book|appointment|reserve/',
		'shop'     => '/shop|product|store/',
		'training' => '/training|course|academy|class|workshop/',
		'service'  => '/service|treatment|what-we-do|solutions/',
	);
	foreach ( $rules as $type => $re ) {
		if ( preg_match( $re, 'blog' === $type ? $path : $hay ) ) {
			return $type;
		}
	}
	if ( ! empty( $rec['published'] ) || in_array( 'BlogPosting', $rec['schema_types'], true ) || in_array( 'Article', $rec['schema_types'], true ) || preg_match( '#/\d{4}/\d{2}/#', $path ) || preg_match( '#^/(blog|news)/#', $path ) ) {
		return 'post';
	}
	return 'page';
}

function lccb_op_migrate_site( array $args ) {
	$crawl = lccb_mig_current( isset( $args['host'] ) ? $args['host'] : '' );
	$pages = array();
	$types = array();
	$nav   = array();
	$c     = array( 'emails' => array(), 'phones' => array(), 'social' => array() );
	$imgs  = array();
	$forms = 0;
	foreach ( $crawl['pages'] as $url => $rec ) {
		$type          = lccb_mig_guess_type( $url, $rec );
		$types[ $type ] = ( isset( $types[ $type ] ) ? $types[ $type ] : 0 ) + 1;
		$pages[]       = array_filter( array(
			'path'   => lccb_mig_path( $url ),
			'type'   => $type,
			'title'  => $rec['title'],
			'h1'     => ( $h = preg_grep( '/^h1: /', $rec['headings'] ) ) ? substr( reset( $h ), 4 ) : null,
			'words'  => $rec['word_count'],
			'images' => count( $rec['images'] ) ?: null,
			'forms'  => count( $rec['forms'] ) ?: null,
			'noindex' => ! empty( $rec['noindex'] ) ?: null,
		) );
		foreach ( $rec['nav'] as $n ) {
			$k         = $n['text'] . '|' . lccb_mig_path( $n['url'] );
			$nav[ $k ] = ( isset( $nav[ $k ] ) ? $nav[ $k ] : 0 ) + 1;
		}
		foreach ( $c as $k => $_ ) {
			foreach ( $rec['contact'][ $k ] as $v ) {
				$c[ $k ][ $v ] = true;
			}
		}
		foreach ( $rec['images'] as $im ) {
			$imgs[ $im['src'] ] = true;
		}
		$forms += count( $rec['forms'] );
	}
	usort( $pages, function ( $a, $b ) {
		return strcmp( $a['path'], $b['path'] );
	} );
	arsort( $nav );
	$n_pages = max( 1, count( $crawl['pages'] ) );
	$menu    = array();
	foreach ( $nav as $k => $count ) {
		if ( $count >= max( 1, 0.5 * $n_pages ) && count( $menu ) < 25 ) {
			list( $text, $path ) = explode( '|', $k, 2 );
			$menu[]              = "$text → $path";
		}
	}
	// Business details from schema.org, if the old site had them.
	$business = null;
	foreach ( $crawl['pages'] as $rec ) {
		if ( $rec['jsonld'] && preg_match( '/"@type":"(LocalBusiness|Organization|BeautySalon|HealthAndBeautyBusiness|MedicalBusiness|ProfessionalService|Store)"/', $rec['jsonld'] ) ) {
			$business = $rec['jsonld'];
			break;
		}
	}
	return array_filter( array(
		'site'      => $crawl['origin'],
		'crawled'   => count( $crawl['pages'] ) . ' page(s), ' . $crawl['status'] . ', ' . substr( $crawl['updated'], 0, 16 ),
		'types'     => $types,
		'pages'     => $pages,
		'menu'      => $menu,
		'contact'   => array_filter( array_map( 'array_keys', $c ) ),
		'business_schema' => $business ? substr( $business, 0, 2500 ) : null,
		'images'    => count( $imgs ) . ' unique',
		'forms'     => $forms ?: null,
		'redirects_seen' => ! empty( $crawl['redirects'] ) ? count( $crawl['redirects'] ) : null,
		'next'      => 'Propose the new sitemap to the user (keep, merge or drop pages; legal pages usually come from a plugin). Then for each page: lc_migrate_page {url} for its content, lc_media_import_batch for its images, build it (lc_page_create, using the site\'s own sections and styles), and finally lc_redirect_map.',
	) );
}

function lccb_op_migrate_page( array $args ) {
	$url   = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
	$crawl = lccb_mig_current( preg_match( '#^https?://#', $url ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : ( isset( $args['host'] ) ? $args['host'] : '' ) );
	$key   = preg_match( '#^https?://#', $url ) ? lccb_mig_norm( $url ) : lccb_mig_norm( $crawl['origin'] . '/' . ltrim( $url, '/' ) );
	if ( ! isset( $crawl['pages'][ $key ] ) && isset( $crawl['redirects'][ $key ] ) ) {
		$key = $crawl['redirects'][ $key ];
	}
	if ( ! isset( $crawl['pages'][ $key ] ) ) {
		throw new Exception( 'That page isn\'t in the crawl. lc_migrate_site lists the crawled pages (use their path).' );
	}
	$rec    = $crawl['pages'][ $key ];
	$shared = isset( $crawl['shared'] ) ? $crawl['shared'] : array();
	$own    = array();
	$cut    = 0;
	foreach ( $rec['blocks'] as $b ) {
		if ( isset( $shared[ md5( wp_json_encode( $b ) ) ] ) ) {
			$cut++;
		} else {
			$own[] = $b;
		}
	}
	$map = get_option( 'lccb_mig_media_' . $crawl['host'], array() );
	foreach ( $own as &$b ) {
		if ( 'img' === $b['type'] && isset( $map[ $b['src'] ] ) ) {
			$b['imported'] = $map[ $b['src'] ];
		}
	}
	unset( $b );
	$images = array_map( function ( $im ) use ( $map ) {
		if ( isset( $map[ $im['src'] ] ) ) {
			$im['imported'] = $map[ $im['src'] ];
		}
		return $im;
	}, $rec['images'] );
	return array_filter( array(
		'url'           => $key,
		'type'          => lccb_mig_guess_type( $key, $rec ),
		'seo'           => array_filter( array( 'title' => $rec['title'], 'description' => $rec['description'], 'canonical' => $rec['canonical'], 'og' => $rec['og'] ?: null, 'robots' => $rec['robots'] ) ),
		'headings'      => $rec['headings'],
		'content'       => $own,
		'shared_blocks_removed' => $cut ?: null,
		'images'        => $images,
		'forms'         => $rec['forms'] ?: null,
		'contact'       => array_filter( $rec['contact'] ) ?: null,
		'schema'        => $rec['jsonld'],
		'internal_links' => count( $rec['links_internal'] ),
		'next'          => 'Rebuild it in the site\'s design system (its own sections, classes and tokens), keeping the meaning and the SEO title/description. Import its images first with lc_media_import_batch {urls} so you can use media-library URLs (they appear here as "imported" afterwards). Forms: recreate them with the site\'s form plugin rather than copying markup.',
	) );
}

// ───────────────────────── Images in bulk ─────────────────────────

function lccb_op_media_import_batch( array $args ) {
	$urls = array_values( array_unique( array_filter( array_map( 'trim', (array) ( isset( $args['urls'] ) ? $args['urls'] : array() ) ) ) ) );
	if ( ! $urls ) {
		throw new Exception( 'Pass urls: the image URLs to import (lc_migrate_page lists a page\'s images).' );
	}
	if ( count( $urls ) > 60 ) {
		throw new Exception( count( $urls ) . ' images: import at most 60 per batch.' );
	}
	$alts = isset( $args['alts'] ) && is_array( $args['alts'] ) ? $args['alts'] : array();
	// Alt text the old site used for each image, from the crawl.
	$from_source = ! isset( $args['alt_from_source'] ) || ! empty( $args['alt_from_source'] );
	$known_alts  = array();
	if ( $from_source ) {
		try {
			$crawl = lccb_mig_current( (string) wp_parse_url( $urls[0], PHP_URL_HOST ) );
			foreach ( $crawl['pages'] as $rec ) {
				foreach ( $rec['images'] as $im ) {
					if ( ! empty( $im['alt'] ) && ! isset( $known_alts[ $im['src'] ] ) ) {
						$known_alts[ $im['src'] ] = $im['alt'];
					}
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Not from a crawled site: no source alt text.
		}
	}
	$todo     = array();
	$existing = array();
	$bad      = array();
	foreach ( $urls as $u ) {
		if ( ! lccb_mig_url_ok( $u ) ) {
			$bad[] = $u;
			continue;
		}
		$have = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lccb_source_url', 'meta_value' => $u, 'posts_per_page' => 1, 'fields' => 'ids' ) );
		if ( $have ) {
			$existing[ $u ] = wp_get_attachment_url( $have[0] );
			continue;
		}
		$todo[] = array( 'url' => $u, 'alt' => isset( $alts[ $u ] ) ? (string) $alts[ $u ] : ( isset( $known_alts[ $u ] ) ? $known_alts[ $u ] : '' ) );
	}
	if ( ! $todo ) {
		return array( 'applied' => false, 'nothing_to_do' => true, 'already_imported' => $existing, 'refused' => $bad ?: null );
	}
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array(
		'tool' => 'lc_media_import_batch', 'kind' => 'media_batch', 'target_type' => 'media', 'target_id' => 0,
		'summary' => sprintf( 'Import %d image(s) from %s', count( $todo ), (string) wp_parse_url( $todo[0]['url'], PHP_URL_HOST ) ),
		'before' => null, 'after' => null, 'fingerprint' => 'batch', 'items' => $todo,
	), LCCB_PREVIEW_TTL );
	return array(
		'preview_id'   => $id,
		'applied'      => false,
		'summary'      => sprintf( 'Import %d image(s) into the media library', count( $todo ) ),
		'changes'      => array_map( function ( $t ) {
			return basename( (string) wp_parse_url( $t['url'], PHP_URL_PATH ) ) . ( $t['alt'] ? ' (alt: "' . mb_substr( $t['alt'], 0, 60 ) . '")' : ' (no alt text: write one)' );
		}, $todo ),
		'content_diff' => '',
		'warnings'     => array_merge( $existing ? array( count( $existing ) . ' already imported (reused, not downloaded again)' ) : array(), $bad ? array( 'Refused (not public URLs): ' . implode( ', ', $bad ) ) : array() ),
		'next'         => 'Nothing downloaded yet. Call lc_apply_change with this preview_id. Images without alt text: add it after with lc_media_update or in the media library.',
	);
}

function lccb_mig_apply_media_batch( array $plan, $preview_id, $restored_from ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ids    = array();
	$map    = array();
	$failed = array();
	foreach ( $plan['items'] as $it ) {
		$host = (string) wp_parse_url( $it['url'], PHP_URL_HOST );
		$tmp  = lccb_mig_is_local_host( $host ) ? lccb_mig_download_local( $it['url'] ) : download_url( $it['url'], 30 );
		if ( is_wp_error( $tmp ) ) {
			$failed[] = $it['url'] . ': ' . $tmp->get_error_message();
			continue;
		}
		if ( ! @getimagesize( $tmp ) ) {
			@unlink( $tmp );
			$failed[] = $it['url'] . ': not an image';
			continue;
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $it['url'], PHP_URL_PATH ) ) ) ?: 'image.jpg';
		$aid  = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
		if ( is_file( $tmp ) ) {
			@unlink( $tmp );
		}
		if ( is_wp_error( $aid ) ) {
			$failed[] = $it['url'] . ': ' . $aid->get_error_message();
			continue;
		}
		update_post_meta( $aid, '_lccb_source_url', $it['url'] );
		if ( '' !== $it['alt'] ) {
			update_post_meta( $aid, '_wp_attachment_image_alt', sanitize_text_field( $it['alt'] ) );
		}
		$ids[]              = (int) $aid;
		$map[ $it['url'] ] = wp_get_attachment_url( $aid );
	}
	if ( $map ) {
		$key = 'lccb_mig_media_' . strtolower( (string) wp_parse_url( $plan['items'][0]['url'], PHP_URL_HOST ) );
		update_option( $key, array_merge( (array) get_option( $key, array() ), $map ), false );
	}
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = $ids ? lccb_audit_record( 'lc_media_import_batch', 'media_batch', 0, $plan['summary'], null, array( 'attachments' => $ids, 'map' => $map ), 'claude', $restored_from ) : null;
	return array(
		'applied'  => (bool) $ids,
		'live'     => 'Added to the media library now.',
		'imported' => $map,
		'failed'   => $failed ?: null,
		'audit_id' => $audit_id,
		'undo'     => $audit_id ? "lc_audit_restore {\"id\": $audit_id} previews deleting these images again." : null,
	);
}

/** download_url() refuses local hosts; a local .test copy of the old site is fetched with wp_remote_get instead. */
function lccb_mig_download_local( $url ) {
	$r = lccb_mig_get( $url, 26214400 );
	if ( 200 !== $r['code'] ) {
		return new WP_Error( 'lccb', 'HTTP ' . $r['code'] );
	}
	$tmp = wp_tempnam( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	file_put_contents( $tmp, $r['body'] );
	return $tmp;
}

function lccb_mig_restore_media_batch_preview( array $entry ) {
	$ids = array_values( array_filter( isset( $entry['after']['attachments'] ) ? $entry['after']['attachments'] : array(), function ( $id ) {
		return (bool) get_post( $id );
	} ) );
	if ( ! $ids ) {
		throw new Exception( 'Those images are already gone from the media library.' );
	}
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array( 'tool' => 'lc_audit_restore', 'kind' => 'media_batch_undo', 'target_type' => 'media_batch', 'target_id' => 0, 'summary' => "Undo #{$entry['id']}: {$entry['summary']}", 'before' => null, 'after' => null, 'fingerprint' => 'batch', 'ids' => $ids, 'map' => isset( $entry['after']['map'] ) ? $entry['after']['map'] : array() ), LCCB_PREVIEW_TTL );
	return array( 'preview_id' => $id, 'applied' => false, 'summary' => "Undo #{$entry['id']}", 'changes' => array( 'Delete ' . count( $ids ) . ' imported image(s) from the media library (pages still using them will show broken images)' ), 'content_diff' => '', 'warnings' => array(), 'next' => 'Call lc_apply_change with this preview_id to delete them.' );
}

function lccb_mig_apply_media_batch_undo( array $plan, $preview_id, $restored_from ) {
	foreach ( $plan['ids'] as $aid ) {
		wp_delete_attachment( $aid, true );
	}
	foreach ( array_keys( $plan['map'] ) as $u ) {
		$key = 'lccb_mig_media_' . strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
		$m   = (array) get_option( $key, array() );
		unset( $m[ $u ] );
		update_option( $key, $m, false );
	}
	delete_transient( 'lccb_preview_' . $preview_id );
	$audit_id = lccb_audit_record( 'lc_audit_restore', 'media_batch', 0, $plan['summary'], null, array( 'deleted' => $plan['ids'] ), 'claude', $restored_from );
	return array( 'applied' => true, 'live' => 'Deleted from the media library now.', 'summary' => $plan['summary'], 'audit_id' => $audit_id );
}

// ───────────────────────── Redirect map ─────────────────────────

function lccb_op_redirect_map( array $args ) {
	$crawl  = lccb_mig_current( isset( $args['host'] ) ? $args['host'] : '' );
	$format = isset( $args['format'] ) ? (string) $args['format'] : 'redirection-csv';
	$manual = isset( $args['map'] ) && is_array( $args['map'] ) ? $args['map'] : array();
	$new    = array();
	foreach ( get_posts( array( 'post_type' => array_values( get_post_types( array( 'public' => true ) ) ), 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'posts_per_page' => 1000 ) ) as $p ) {
		if ( 'attachment' === $p->post_type ) {
			continue;
		}
		$path  = lccb_mig_path( get_permalink( $p ) );
		$new[] = array( 'id' => $p->ID, 'path' => $path, 'slug' => $p->post_name, 'title' => $p->post_title, 'status' => $p->post_status );
	}
	$by_path = array( '/' => array( 'path' => '/' ) ); // the home page always exists
	foreach ( $new as $n ) {
		$by_path[ rtrim( $n['path'], '/' ) ?: '/' ] = $n;
	}
	$manual_norm = array();
	foreach ( $manual as $old => $target ) {
		$manual_norm[ rtrim( lccb_mig_path( preg_match( '#^https?://#', $old ) ? $old : $crawl['origin'] . '/' . ltrim( $old, '/' ) ), '/' ) ?: '/' ] = $target;
	}

	$rules     = array();
	$unchanged = 0;
	$unmatched = array();
	$olds      = array_keys( $crawl['pages'] );
	if ( ! empty( $crawl['redirects'] ) ) {
		$olds = array_merge( $olds, array_keys( $crawl['redirects'] ) );
	}
	foreach ( array_unique( $olds ) as $url ) {
		$old = rtrim( lccb_mig_path( $url ), '/' ) ?: '/';
		if ( isset( $manual_norm[ $old ] ) ) {
			$t = $manual_norm[ $old ];
			if ( is_numeric( $t ) && get_post( (int) $t ) ) {
				$t = lccb_mig_path( get_permalink( (int) $t ) );
			}
			$rules[ $old ] = array( 'to' => (string) $t, 'how' => 'manual' );
			continue;
		}
		if ( isset( $by_path[ $old ] ) ) {
			$unchanged++;
			continue;
		}
		$slug  = basename( $old );
		$match = null;
		foreach ( $new as $n ) {
			if ( '' !== $slug && $n['slug'] === $slug ) {
				$match = array( $n, 'same slug' );
				break;
			}
		}
		if ( ! $match ) {
			$title = isset( $crawl['pages'][ $url ]['title'] ) ? strtolower( preg_replace( '/\s*[|–—-]\s*[^|–—-]+$/u', '', (string) $crawl['pages'][ $url ]['title'] ) ) : str_replace( '-', ' ', $slug );
			$best  = 0;
			foreach ( $new as $n ) {
				similar_text( $title, strtolower( $n['title'] ), $pct );
				if ( $pct > $best ) {
					$best  = $pct;
					$match = $pct >= 72 ? array( $n, sprintf( 'title %d%% similar', $pct ) ) : null;
				}
			}
		}
		if ( $match ) {
			$rules[ $old ] = array( 'to' => $match[0]['path'], 'how' => $match[1] . ( 'publish' !== $match[0]['status'] ? ' (' . $match[0]['status'] . ')' : '' ) );
		} else {
			$unmatched[] = $old;
		}
	}
	if ( ! empty( $args['fallback'] ) && 'home' === $args['fallback'] ) {
		foreach ( $unmatched as $old ) {
			$rules[ $old ] = array( 'to' => '/', 'how' => 'fallback to home' );
		}
	}
	foreach ( $rules as $old => $r ) {
		if ( ( rtrim( $r['to'], '/' ) ?: '/' ) === $old ) {
			unset( $rules[ $old ] ); // same place: no redirect (and never a loop)
			$unchanged++;
		}
	}
	ksort( $rules );

	$rows = array();
	foreach ( $rules as $old => $r ) {
		if ( 'gone' === $r['to'] || '410' === $r['to'] ) {
			$rows[] = array( $old, '', 410 );
		} else {
			$rows[] = array( $old, $r['to'], 301 );
		}
	}
	list( $ext, $body ) = lccb_mig_redirect_format( $format, $rows );
	$file = lccb_site_root() . '/redirects-' . preg_replace( '/[^a-z0-9.-]/', '', $crawl['host'] ) . '.' . $ext;
	if ( $rows ) {
		file_put_contents( $file, $body );
	}
	return array_filter( array(
		'old_pages'  => count( array_unique( $olds ) ),
		'unchanged'  => $unchanged . ' URL(s) keep the same path (no redirect needed)',
		'redirects'  => array_map( function ( $old ) use ( $rules ) {
			return "$old → " . ( '410' === $rules[ $old ]['to'] || 'gone' === $rules[ $old ]['to'] ? '410 Gone' : $rules[ $old ]['to'] ) . " ({$rules[ $old ]['how']})";
		}, array_keys( $rules ) ),
		'unmatched'  => $unmatched ?: null,
		'file'       => $rows ? str_replace( lccb_site_root() . '/', '', $file ) : null,
		'format'     => $format,
		'next'       => ( $unmatched ? 'Unmatched old URLs need a decision: call again with map {"/old-path": <new page id or path, or "410">} (or fallback: "home"). ' : '' ) . lccb_mig_redirect_howto( $format ),
	) );
}

function lccb_mig_redirect_format( $format, array $rows ) {
	$csv = function ( array $r ) {
		return implode( ',', array_map( function ( $v ) {
			return '"' . str_replace( '"', '""', (string) $v ) . '"';
		}, $r ) );
	};
	switch ( $format ) {
		case 'htaccess':
			$out = array( '# Redirects from the old site (generated by LC Claude Bridge). Paste into .htaccess above the WordPress block.' );
			foreach ( $rows as $r ) {
				// RedirectMatch, anchored: plain Redirect matches prefixes (/about would also catch /about-us/team).
				$re    = '^' . preg_quote( str_replace( ' ', '%20', $r[0] ), null ) . '/?$';
				$out[] = 410 === $r[2] ? "RedirectMatch 410 $re" : "RedirectMatch 301 $re {$r[1]}";
			}
			return array( 'htaccess.txt', implode( "\n", $out ) . "\n" );
		case 'nginx':
			$out = array( '# Redirects from the old site (generated by LC Claude Bridge). Include inside the server { } block.' );
			foreach ( $rows as $r ) {
				$out[] = 'location = ' . $r[0] . ' { return ' . ( 410 === $r[2] ? '410' : '301 ' . $r[1] ) . '; }';
				if ( '/' !== $r[0] ) {
					$out[] = 'location = ' . $r[0] . '/ { return ' . ( 410 === $r[2] ? '410' : '301 ' . $r[1] ) . '; }';
				}
			}
			return array( 'nginx.conf', implode( "\n", $out ) . "\n" );
		case 'csv':
			$out = array( $csv( array( 'source', 'target', 'code' ) ) );
			foreach ( $rows as $r ) {
				$out[] = $csv( $r );
			}
			return array( 'csv', implode( "\n", $out ) . "\n" );
		default: // Redirection plugin (Tools › Redirection › Import/Export › CSV)
			$out = array();
			foreach ( $rows as $r ) {
				// Match the path with or without a trailing slash.
				$out[] = $csv( array( '/' === $r[0] ? '/' : '^' . preg_quote( $r[0], null ) . '/?$', 410 === $r[2] ? '' : $r[1], '/' === $r[0] ? 0 : 1, $r[2] ) );
			}
			return array( 'redirection.csv', implode( "\n", $out ) . "\n" );
	}
}

function lccb_mig_redirect_howto( $format ) {
	switch ( $format ) {
		case 'htaccess':
			return 'Paste the file\'s rules into the live site\'s .htaccess above the WordPress block.';
		case 'nginx':
			return 'Include the rules in the live server\'s nginx server block, then reload nginx.';
		case 'csv':
			return 'Generic CSV (source, target, code) for any redirect tool (Rank Math, Yoast Premium, Cloudflare bulk redirects).';
		default:
			return 'On the live site: install the Redirection plugin, then Tools › Redirection › Import/Export › Import, and choose this CSV (columns: source regex, target, regex flag, code).';
	}
}
