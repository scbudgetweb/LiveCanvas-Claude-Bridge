<?php
/**
 * Quality pass and launch checklist (lc_qa): SEO, performance, links, forms and site basics, checked on the
 * rendered front end, merged with accessibility results (axe-core, run in the builder tab) into one prioritised
 * fix list. Launch mode adds the go-live checklist and writes launch-report.md in the site root.
 *
 * Read-only, apart from that report file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_QA_MAX_PAGES     = 40;
const LCCB_QA_MAX_INTERNAL  = 150;
const LCCB_QA_MAX_EXTERNAL  = 40;
const LCCB_QA_PRIORITY      = array( 'critical' => 0, 'should' => 1, 'nice' => 2 );

// Pages fetched for QA hide the admin bar, so they're checked as visitors see them.
add_filter( 'show_admin_bar', function ( $show ) {
	return isset( $_GET['lccb_qa'] ) ? false : $show; // phpcs:ignore WordPress.Security.NonceVerification
} );

// ───────────────────────── Which pages ─────────────────────────

/** @return array<array{id: int, title: string, url: string, status: string, qa_url: string}> */
function lccb_qa_pages( array $args ) {
	$scope = isset( $args['scope'] ) ? $args['scope'] : 'page';
	if ( 'site' === $scope ) {
		$posts = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'posts_per_page' => LCCB_QA_MAX_PAGES, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
		// LiveCanvas pages first; then the rest.
		usort( $posts, function ( $a, $b ) {
			return (int) ( '1' !== get_post_meta( $a->ID, '_lc_livecanvas_enabled', true ) ) - (int) ( '1' !== get_post_meta( $b->ID, '_lc_livecanvas_enabled', true ) );
		} );
	} else {
		$posts = array( lccb_require_post( isset( $args['id'] ) ? $args['id'] : 0, array( 'page', 'post' ) ) );
	}
	return array_map( function ( $p ) {
		$url = 'publish' === $p->post_status ? get_permalink( $p ) : get_preview_post_link( $p );
		return array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p ), 'status' => $p->post_status, 'qa_url' => add_query_arg( 'lccb_qa', '1', $url ) );
	}, $posts );
}

function lccb_op_qa_targets( array $args ) {
	return array( 'pages' => lccb_qa_pages( $args ) );
}

/** Fetch a page of this site as a visitor (or, for drafts, as an admin with the admin bar hidden). */
function lccb_qa_fetch( $url, $auth = false ) {
	$headers = array();
	if ( $auth ) {
		$u    = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		$exp  = time() + 600;
		$jar  = array();
		if ( $u ) {
			$jar[] = LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $u[0]->ID, $exp, 'logged_in' ) );
			$jar[] = ( is_ssl() || 0 === strpos( home_url(), 'https' ) ? SECURE_AUTH_COOKIE : AUTH_COOKIE ) . '=' . rawurlencode( wp_generate_auth_cookie( $u[0]->ID, $exp, 0 === strpos( home_url(), 'https' ) ? 'secure_auth' : 'auth' ) );
		}
		$headers['Cookie'] = implode( '; ', $jar );
	}
	$t0  = microtime( true );
	$res = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 5, 'sslverify' => false, 'headers' => $headers, 'user-agent' => LCCB_MIG_UA ) );
	if ( is_wp_error( $res ) ) {
		return array( 'code' => 0, 'body' => '', 'error' => $res->get_error_message(), 'ms' => 0, 'headers' => array() );
	}
	return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ), 'ms' => (int) ( 1000 * ( microtime( true ) - $t0 ) ), 'headers' => wp_remote_retrieve_headers( $res ) );
}

// ───────────────────────── Findings ─────────────────────────

function lccb_qa_add( array &$f, $priority, $check, $where, $issue, $fix, $group = null ) {
	$f[] = array_filter( array( 'priority' => $priority, 'check' => $check, 'where' => $where, 'issue' => $issue, 'fix' => $fix, 'group' => $group ) );
}

/** Issues that recur on many pages become one finding listing the pages (e.g. "No meta description on 9 pages"). */
function lccb_qa_group( array $f, $page_count ) {
	$groups = array();
	foreach ( $f as $i => $x ) {
		if ( ! empty( $x['group'] ) ) {
			$groups[ $x['group'] ][] = $i;
		}
	}
	$titles = array(
		'no-desc'   => 'No meta description',
		'title-len' => 'Title shorter than 30 or longer than 65 characters',
		'og'        => 'Missing Open Graph title/image (how pages look when shared)',
		'blocking'  => 'Many render-blocking stylesheets/scripts in <head>',
		'no-lazy'   => 'Images below the top without loading="lazy"',
		'no-size'   => 'Images without width/height (the page jumps while they load)',
		'desc-len'  => 'Meta description outside 70-165 characters',
		'no-alt'    => 'Images without an alt attribute',
		'canonical' => 'No canonical link',
		'lang'      => 'No lang attribute on <html>',
	);
	foreach ( $groups as $g => $idx ) {
		if ( count( $idx ) < ( 0 === strpos( $g, 'axe-' ) ? 2 : 3 ) ) {
			continue;
		}
		$first = $f[ $idx[0] ];
		$names = array();
		foreach ( $idx as $i ) {
			$names[] = preg_replace( '/^"([^"]+)".*$/', '$1', $f[ $i ]['where'] );
			unset( $f[ $i ] );
		}
		$f[] = array(
			'priority' => $first['priority'],
			'check'    => $first['check'],
			'where'    => count( $idx ) . ( $page_count ? " of $page_count" : '' ) . ' pages: ' . implode( ', ', array_slice( $names, 0, 8 ) ) . ( count( $names ) > 8 ? ', …' : '' ),
			'issue'    => isset( $titles[ $g ] ) ? $titles[ $g ] . '.' : preg_replace( '/^(.+?) \(\d+ elements?, (\w+)\): /', '$1 ($2), e.g.: ', $first['issue'] ),
			'fix'      => $first['fix'],
		);
	}
	return array_values( array_map( function ( $x ) {
		unset( $x['group'] );
		return $x;
	}, $f ) );
}

/** A local URL's file on disk (for sizes), or null. */
function lccb_qa_local_file( $url ) {
	$url  = preg_replace( '/[?#].*$/', '', (string) $url );
	$home = untrailingslashit( home_url() );
	if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
		$url = $home . $url;
	}
	if ( 0 !== strpos( $url, $home ) ) {
		return null;
	}
	$path = ABSPATH . ltrim( substr( $url, strlen( $home ) ), '/' );
	return is_file( $path ) ? $path : null;
}

// ───────────────────────── Per page: SEO + performance ─────────────────────────

function lccb_qa_page( array $page, array $fetched, array &$f, array &$site ) {
	$where = "\"{$page['title']}\" ({$page['url']})";
	if ( 200 !== $fetched['code'] ) {
		lccb_qa_add( $f, 'critical', 'basics', $where, 'The page returns HTTP ' . $fetched['code'] . ( isset( $fetched['error'] ) ? ' (' . $fetched['error'] . ')' : '' ), 'Check it loads for visitors.' );
		return;
	}
	$html = $fetched['body'];
	$rec  = lccb_mig_extract( $html, $page['url'] );
	$site['titles'][ (string) $rec['title'] ][]         = $where;
	$site['descriptions'][ (string) $rec['description'] ][] = $where;

	// SEO.
	$title = (string) $rec['title'];
	$tlen  = mb_strlen( $title );
	if ( '' === $title ) {
		lccb_qa_add( $f, 'critical', 'seo', $where, 'No <title>.', 'Set an SEO title (SEO plugin, or the page title).' );
	} elseif ( $tlen < 30 || $tlen > 65 ) {
		lccb_qa_add( $f, 'should', 'seo', $where, "Title is $tlen characters: \"$title\"", 'Aim for 30-60 characters: what the page is about, then the brand.', 'title-len' );
	}
	$desc = (string) $rec['description'];
	if ( '' === $desc ) {
		lccb_qa_add( $f, 'should', 'seo', $where, 'No meta description.', 'Write a 120-155 character description (needs an SEO plugin such as Yoast, Rank Math or SEOPress).', 'no-desc' );
	} elseif ( mb_strlen( $desc ) < 70 || mb_strlen( $desc ) > 165 ) {
		lccb_qa_add( $f, 'nice', 'seo', $where, 'Meta description is ' . mb_strlen( $desc ) . ' characters.', 'Aim for 120-155 characters.', 'desc-len' );
	}
	$h1 = substr_count( strtolower( implode( "\n", preg_grep( '/^h1: /', $rec['headings'] ) ) ), 'h1: ' );
	if ( 0 === $h1 ) {
		lccb_qa_add( $f, 'should', 'seo', $where, 'No h1 heading.', 'Make the main title the h1.' );
	} elseif ( $h1 > 1 ) {
		lccb_qa_add( $f, 'should', 'seo', $where, "$h1 h1 headings.", 'Keep one h1; make the others h2 (style them with a class if they must look the same).' );
	}
	if ( ! empty( $rec['robots'] ) && false !== stripos( $rec['robots'], 'noindex' ) ) {
		lccb_qa_add( $f, 'critical', 'seo', $where, 'This published page is set to noindex: search engines will drop it.', 'Remove noindex in the SEO plugin\'s settings for this page (unless it\'s deliberate, e.g. a thank-you page).' );
	}
	if ( empty( $rec['canonical'] ) ) {
		lccb_qa_add( $f, 'nice', 'seo', $where, 'No canonical link.', 'An SEO plugin adds it automatically.', 'canonical' );
	} elseif ( untrailingslashit( preg_replace( '/[?#].*$/', '', $rec['canonical'] ) ) !== untrailingslashit( preg_replace( '/[?#].*$/', '', $page['url'] ) ) && 'publish' === $page['status'] ) {
		lccb_qa_add( $f, 'should', 'seo', $where, "Canonical points elsewhere: {$rec['canonical']}", 'Make the canonical this page\'s own URL unless it\'s a deliberate duplicate.' );
	}
	if ( empty( $rec['og']['title'] ) || empty( $rec['og']['image'] ) ) {
		lccb_qa_add( $f, 'nice', 'seo', $where, 'Missing Open Graph ' . implode( ' and ', array_filter( array( empty( $rec['og']['title'] ) ? 'title' : '', empty( $rec['og']['image'] ) ? 'image' : '' ) ) ) . ' (how the page looks when shared).', 'Set a social image in the SEO plugin (a site-wide default is fine).', 'og' );
	}
	if ( empty( $rec['lang'] ) ) {
		lccb_qa_add( $f, 'nice', 'seo', $where, 'No lang attribute on <html>.', 'The theme should output language_attributes().', 'lang' );
	}

	// Performance: weight, render-blocking, images, fonts.
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp       = new DOMXPath( $dom );
	$weight   = array( 'html' => strlen( $html ), 'css' => 0, 'js' => 0, 'img' => 0 );
	$largest  = array();
	$blocking = 0;
	foreach ( $xp->query( '//link[@rel="stylesheet"]' ) as $l ) {
		$file = lccb_qa_local_file( $l->getAttribute( 'href' ) );
		if ( $file ) {
			$weight['css']                         += filesize( $file );
			$largest[ basename( $file ) ]           = filesize( $file );
		}
		if ( 'head' === strtolower( $l->parentNode->nodeName ) && ! preg_match( '/print/', $l->getAttribute( 'media' ) ) ) {
			$blocking++;
		}
	}
	foreach ( $xp->query( '//script[@src]' ) as $s ) {
		$file = lccb_qa_local_file( $s->getAttribute( 'src' ) );
		if ( $file ) {
			$weight['js']                 += filesize( $file );
			$largest[ basename( $file ) ]  = filesize( $file );
		}
		if ( 'head' === strtolower( $s->parentNode->nodeName ) && ! $s->hasAttribute( 'async' ) && ! $s->hasAttribute( 'defer' ) && 'module' !== $s->getAttribute( 'type' ) ) {
			$blocking++;
		}
	}
	$imgs    = 0;
	$no_lazy = 0;
	$no_size = 0;
	foreach ( $xp->query( '//body//img' ) as $img ) {
		$imgs++;
		$file = lccb_qa_local_file( $img->getAttribute( 'src' ) );
		if ( $file ) {
			$weight['img']                += filesize( $file );
			$largest[ basename( $file ) ]  = filesize( $file );
		}
		if ( $imgs > 2 && 'lazy' !== strtolower( $img->getAttribute( 'loading' ) ) ) {
			$no_lazy++;
		}
		if ( ! $img->getAttribute( 'width' ) || ! $img->getAttribute( 'height' ) ) {
			$no_size++;
		}
	}
	$total = array_sum( $weight );
	arsort( $largest );
	$site['weights'][ $where ] = $total;
	if ( $total > 3 * 1048576 ) {
		lccb_qa_add( $f, 'should', 'performance', $where, 'Page weight about ' . size_format( $total ) . ' (HTML, CSS, JS, images). Largest: ' . implode( ', ', array_map( function ( $k, $v ) {
			return "$k " . size_format( $v );
		}, array_slice( array_keys( $largest ), 0, 3 ), array_slice( $largest, 0, 3 ) ) ), 'Optimise the largest images (lc_image_audit / lc_image_optimise) and drop unused scripts.' );
	} elseif ( $total > 1572864 ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, 'Page weight about ' . size_format( $total ) . '.', 'Under 1.5 MB keeps mobile loads quick: see lc_image_audit.' );
	}
	if ( $blocking > 6 ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, "$blocking render-blocking stylesheets/scripts in <head>.", 'Defer non-critical scripts; a caching/optimisation plugin can combine CSS.', 'blocking' );
	}
	if ( $no_lazy ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, "$no_lazy image(s) below the top without loading=\"lazy\".", 'Add loading="lazy" to images that aren\'t near the top.', 'no-lazy' );
	}
	if ( $no_size ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, "$no_size image(s) without width/height (the page jumps while they load).", 'Add width and height attributes with the real pixel size.', 'no-size' );
	}
	if ( $fetched['ms'] > 1500 ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, "Server took {$fetched['ms']} ms to send the page locally.", 'Check for slow plugins; a page cache helps on the live server.' );
	}
	$families = 0;
	foreach ( $xp->query( '//link[contains(@href,"fonts.googleapis.com")]' ) as $l ) {
		$families += count( lccb_tpl_google_families( $l->getAttribute( 'href' ) ) ) ?: substr_count( $l->getAttribute( 'href' ), 'family=' );
	}
	if ( $families > 3 ) {
		lccb_qa_add( $f, 'nice', 'performance', $where, "$families Google Font families.", 'Two or three families are plenty; each costs a download. Consider hosting them locally (also simpler for GDPR).' );
	}

	// Images without alt (all of them, rendered: includes shortcodes, header, footer).
	$no_alt = 0;
	foreach ( $xp->query( '//body//img[not(@alt)]' ) as $_ ) {
		$no_alt++;
	}
	if ( $no_alt ) {
		lccb_qa_add( $f, 'should', 'accessibility', $where, "$no_alt image(s) without an alt attribute.", 'lc_image_audit lists them; lc_media_read + lc_media_update writes the alt text.', 'no-alt' );
	}

	// Collect links and forms for the site-wide checks.
	foreach ( $xp->query( '//a[@href]' ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		$site['links'][] = array( 'href' => $href, 'abs' => lccb_mig_abs( $href, $page['url'] ), 'on' => $where, 'page' => $page['url'] );
	}
	$ids = array();
	foreach ( $xp->query( '//*[@id]' ) as $n ) {
		$ids[ $n->getAttribute( 'id' ) ] = true;
	}
	$site['ids'][ untrailingslashit( preg_replace( '/[?#].*$/', '', $page['url'] ) ) ] = $ids;
	$site['html'][] = $html;
	lccb_qa_forms( $xp, $where, $f, $site );
}

// ───────────────────────── Forms ─────────────────────────

function lccb_qa_forms( DOMXPath $xp, $where, array &$f, array &$site ) {
	foreach ( $xp->query( '//form' ) as $form ) {
		$cls = ' ' . $form->getAttribute( 'class' ) . ' ' . $form->getAttribute( 'id' ) . ' ';
		if ( preg_match( '/search/i', $cls ) || $xp->query( './/input[@type="search" or @name="s"]', $form )->length ) {
			continue;
		}
		if ( preg_match( '#wp-comments-post\.php|wp-login\.php#', $form->getAttribute( 'action' ) ) || preg_match( '/commentform|loginform/', $cls ) ) {
			continue; // WordPress's own comment and login forms
		}
		if ( preg_match( '/forminator-module-(\d+)|forminator-custom-form-(\d+)/', $cls, $m ) || $form->getAttribute( 'data-form-id' ) ) {
			$id = (int) ( ! empty( $m[1] ) ? $m[1] : ( ! empty( $m[2] ) ? $m[2] : $form->getAttribute( 'data-form-id' ) ) );
			$site['forms'][ 'forminator:' . $id ][] = $where;
		} elseif ( false !== strpos( $cls, 'wpcf7' ) ) {
			$id = $xp->query( './/input[@name="_wpcf7"]', $form );
			$site['forms'][ 'cf7:' . ( $id->length ? (int) $id->item( 0 )->getAttribute( 'value' ) : 0 ) ][] = $where;
		} elseif ( preg_match( '/wpforms|gform|fluentform|ninja-forms|frm_forms|elementor-form|mc4wp/', $cls, $pm ) ) {
			$site['forms'][ $pm[0] . ':?' ][] = $where;
		} else {
			$action = $form->getAttribute( 'action' );
			$site['forms'][ 'plain:' . ( $action ?: '(no action)' ) ][] = $where;
		}
	}
}

function lccb_qa_forms_check( array &$f, array $site ) {
	$admin = (string) get_option( 'admin_email' );
	$host  = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	foreach ( isset( $site['forms'] ) ? $site['forms'] : array() as $key => $pages ) {
		list( $kind, $id ) = explode( ':', $key, 2 );
		$where             = implode( ', ', array_slice( array_unique( $pages ), 0, 3 ) ) . ( count( array_unique( $pages ) ) > 3 ? ' …' : '' );
		$recipients        = null;
		if ( 'forminator' === $kind ) {
			$meta = get_post_meta( (int) $id, 'forminator_form_meta', true );
			$list = array();
			foreach ( (array) ( isset( $meta['notifications'] ) ? $meta['notifications'] : array() ) as $n ) {
				$list[] = isset( $n['recipients'] ) ? (string) $n['recipients'] : '';
			}
			$recipients = implode( ',', array_filter( $list ) );
			$label      = 'Forminator form #' . $id . ' "' . get_the_title( (int) $id ) . '"';
		} elseif ( 'cf7' === $kind ) {
			$mail       = get_post_meta( (int) $id, '_mail', true );
			$recipients = is_array( $mail ) && isset( $mail['recipient'] ) ? str_replace( '[_site_admin_email]', $admin, (string) $mail['recipient'] ) : '';
			$label      = 'Contact Form 7 #' . $id;
		} elseif ( 'plain' === $kind ) {
			lccb_qa_add( $f, 'should', 'forms', $where, "A form without a form plugin (submits to $id).", 'Check where it sends data; rebuild it with the site\'s form plugin so submissions are stored and emailed.' );
			continue;
		} else {
			lccb_qa_add( $f, 'nice', 'forms', $where, "A $kind form.", 'Send a test submission and check it arrives.' );
			continue;
		}
		if ( '' === trim( (string) $recipients ) ) {
			lccb_qa_add( $f, 'critical', 'forms', $where, "$label has no notification recipient: submissions won't be emailed to anyone.", 'Set the recipient in the form\'s Email Notifications.' );
			continue;
		}
		$addrs = array_map( 'trim', explode( ',', str_replace( '{admin_email}', $admin, $recipients ) ) );
		$off   = array_filter( $addrs, function ( $a ) use ( $host ) {
			return false === stripos( $a, '@' . $host ) && false === stripos( $a, '.' . $host );
		} );
		lccb_qa_add( $f, $off ? 'should' : 'nice', 'forms', $where, "$label emails: " . implode( ', ', $addrs ) . '.', $off ? 'Check these are the client\'s addresses, not a developer\'s or a test inbox, before launch. Send a test submission.' : 'Send a test submission and check it arrives (and doesn\'t land in spam: use an SMTP plugin on the live site).' );
	}
}

// ───────────────────────── Links ─────────────────────────

function lccb_qa_links( array &$f, array $site ) {
	$home      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$internal  = array();
	$external  = array();
	$checked   = array();
	foreach ( isset( $site['ids'] ) ? $site['ids'] : array() as $url => $_ ) {
		$checked[ $url ] = 200; // pages already fetched
	}
	foreach ( isset( $site['links'] ) ? $site['links'] : array() as $l ) {
		$href = $l['href'];
		if ( 0 === stripos( $href, 'mailto:' ) ) {
			$addr = rawurldecode( preg_replace( '/\?.*$/', '', substr( $href, 7 ) ) );
			if ( ! is_email( $addr ) ) {
				lccb_qa_add( $f, 'should', 'links', $l['on'], "Malformed email link: $href", 'Use mailto:name@example.com.' );
			}
			continue;
		}
		if ( 0 === stripos( $href, 'tel:' ) ) {
			if ( strlen( preg_replace( '/\D/', '', $href ) ) < 7 ) {
				lccb_qa_add( $f, 'should', 'links', $l['on'], "Malformed phone link: $href", 'Use tel:+441234567890 (international format, no spaces).' );
			}
			continue;
		}
		if ( '' === $href || '#' === $href || 0 === stripos( $href, 'javascript:' ) ) {
			continue;
		}
		if ( ! $l['abs'] ) {
			continue;
		}
		$frag = (string) wp_parse_url( $l['abs'], PHP_URL_FRAGMENT );
		$bare = untrailingslashit( preg_replace( '/[?#].*$/', '', $l['abs'] ) );
		if ( lccb_mig_same_site( $l['abs'], $home ) ) {
			if ( $frag && isset( $site['ids'][ $bare ] ) && ! isset( $site['ids'][ $bare ][ $frag ] ) && ! preg_match( '/^(top|!|\/)/', $frag ) ) {
				lccb_qa_add( $f, 'should', 'links', $l['on'], "Link to #$frag, but there's no element with id=\"$frag\" on " . ( $bare === untrailingslashit( preg_replace( '/[?#].*$/', '', $l['page'] ) ) ? 'this page' : $bare ) . '.', 'Fix the anchor or add the id to the target section.' );
			}
			if ( ! preg_match( '#/(wp-admin|wp-login\.php|feed)|\.(jpe?g|png|webp|gif|pdf|zip)$#i', $bare ) ) {
				$internal[ $bare ][] = $l['on'];
			}
		} elseif ( preg_match( '#^https?://#i', $l['abs'] ) ) {
			$external[ $bare ][] = $l['on'];
		}
	}
	$n = 0;
	foreach ( $internal as $url => $on ) {
		if ( isset( $checked[ $url ] ) || $n++ >= LCCB_QA_MAX_INTERNAL ) {
			continue;
		}
		$r = lccb_qa_fetch( $url );
		if ( $r['code'] >= 400 || 0 === $r['code'] ) {
			lccb_qa_add( $f, 'critical', 'links', implode( ', ', array_slice( array_unique( $on ), 0, 3 ) ), "Broken link: $url (HTTP {$r['code']})", 'Point it at the right page, or create the page / add a redirect.' );
		}
	}
	$n = 0;
	foreach ( $external as $url => $on ) {
		if ( $n++ >= LCCB_QA_MAX_EXTERNAL ) {
			break;
		}
		usleep( 150000 );
		$res  = wp_remote_head( $url, array( 'timeout' => 10, 'redirection' => 5, 'user-agent' => LCCB_MIG_UA ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 403, 405, 429, 999 ), true ) || 0 === $code ) {
			$res  = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 5, 'user-agent' => LCCB_MIG_UA, 'limit_response_size' => 65536 ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		}
		if ( 404 === $code || 410 === $code || ( 0 === $code && is_wp_error( $res ) && preg_match( '/resolve|could not resolve|not known/i', $res->get_error_message() ) ) ) {
			lccb_qa_add( $f, 'should', 'links', implode( ', ', array_slice( array_unique( $on ), 0, 3 ) ), "External link is dead: $url (" . ( $code ?: 'site not found' ) . ')', 'Update or remove it.' );
		}
	}
	return array( 'internal' => count( $internal ), 'external' => min( count( $external ), LCCB_QA_MAX_EXTERNAL ) );
}

// ───────────────────────── Site basics ─────────────────────────

function lccb_qa_active_plugins() {
	return implode( ' ', (array) get_option( 'active_plugins', array() ) );
}

function lccb_qa_basics( array &$f, array $site, $launch ) {
	$plugins = lccb_qa_active_plugins();
	$html    = implode( "\n", isset( $site['html'] ) ? array_slice( $site['html'], 0, 3 ) : array() );
	if ( ! get_option( 'site_icon' ) && false === stripos( $html, 'rel="icon"' ) ) {
		lccb_qa_add( $f, 'should', 'basics', 'site', 'No site icon (favicon).', 'Appearance › Customise › Site Identity › Site Icon (512×512).' );
	}
	$r404 = lccb_qa_fetch( home_url( '/lccb-qa-check-' . wp_generate_password( 6, false ) . '/' ) );
	if ( 404 !== $r404['code'] ) {
		lccb_qa_add( $f, 'critical', 'basics', 'site', "A missing page returns HTTP {$r404['code']} instead of 404 (search engines see it as a real page).", 'Check permalinks and any plugin/template that catches all URLs.' );
	} elseif ( ! get_posts( array( 'post_type' => 'lc_dynamic_template', 'meta_key' => 'is_404', 'post_status' => 'publish', 'numberposts' => 1 ) ) && ! locate_template( '404.php' ) ) {
		lccb_qa_add( $f, 'nice', 'basics', 'site', 'No designed 404 page.', 'Make a LiveCanvas dynamic template with the is_404 condition (lc_template_upsert), with search and links to key pages.' );
	}
	$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( ! $privacy || 'publish' !== get_post_status( $privacy ) ) {
		$candidate = get_page_by_path( 'privacy-policy' );
		if ( $candidate && 'publish' === $candidate->post_status ) {
			lccb_qa_add( $f, 'nice', 'basics', 'site', "\"{$candidate->post_title}\" is published but not selected as the privacy policy page.", 'Select it in Settings › Privacy (WordPress links to it from comment and registration forms).' );
		} else {
			lccb_qa_add( $f, 'should', 'basics', 'site', 'No published privacy policy page.', 'Publish one and select it in Settings › Privacy (needed under UK GDPR when you collect any personal data, e.g. a contact form).' );
		}
	}
	$consent  = preg_match( '/cookie-law-info|cookieyes|complianz|cookie-notice|gdpr-cookie-compliance|cookiebot|borlabs|real-cookie-banner|iubenda|termly/i', $plugins . ' ' . $html );
	$analytics = preg_match( '/googletagmanager\.com|gtag\(|google-analytics\.com|plausible\.io|usefathom|matomo|clarity\.ms|fbq\(/i', $html );
	if ( $analytics && ! $consent ) {
		lccb_qa_add( $f, 'critical', 'basics', 'site', 'Analytics/tracking runs but no cookie consent tool was found.', 'UK/EU law needs consent before non-essential cookies: add a consent plugin (e.g. Complianz, CookieYes) or a cookieless analytics tool.' );
	} elseif ( ! $consent ) {
		lccb_qa_add( $f, 'nice', 'basics', 'site', 'No cookie consent tool found.', 'Only needed if the site sets non-essential cookies (analytics, embeds, pixels): check before launch.' );
	}
	if ( ! $analytics ) {
		lccb_qa_add( $f, $launch ? 'should' : 'nice', 'basics', 'site', 'No analytics found on the pages.', 'Add analytics if the client wants visitor stats (Google Analytics via Site Kit, or cookieless like Plausible/Fathom).' );
	}
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		lccb_qa_add( $f, $launch ? 'critical' : 'nice', 'basics', 'wp-config.php', 'WP_DEBUG is on' . ( defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY ? ' (display off)' : ' (errors can show to visitors)' ) . '.', 'Turn it off on the live site.' );
	}
	if ( in_array( get_option( 'blogdescription' ), array( 'Just another WordPress site', '' ), true ) ) {
		lccb_qa_add( $f, 'nice', 'basics', 'site', 'Tagline is empty or the WordPress default.', 'Settings › General › Tagline (some themes and SEO titles use it).' );
	}
	foreach ( array( 'hello-world' => 'post', 'sample-page' => 'page' ) as $slug => $type ) {
		$p = get_page_by_path( $slug, OBJECT, $type );
		if ( $p && 'publish' === $p->post_status ) {
			lccb_qa_add( $f, 'should', 'basics', 'site', "WordPress's default \"{$p->post_title}\" $type is still published.", 'Delete it (it shows up in search results and sitemaps).' );
		}
	}
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		lccb_qa_add( $f, 'should', 'seo', 'site', 'Plain permalinks (?p=123).', 'Settings › Permalinks › Post name.' );
	}
	if ( in_array( get_option( 'timezone_string' ), array( '', 'UTC' ), true ) && 0 === (int) get_option( 'gmt_offset' ) ) {
		lccb_qa_add( $f, 'nice', 'basics', 'site', 'Timezone is UTC.', 'Settings › General › Timezone: London (affects post dates and form timestamps).' );
	}
	if ( ! preg_match( '/wordpress-seo|seo-by-rank-math|wp-seopress|all-in-one-seo|the-seo-framework|slim-seo/i', $plugins ) ) {
		lccb_qa_add( $f, 'should', 'seo', 'site', 'No SEO plugin: there\'s nowhere to set meta descriptions or social images.', 'Install one (Yoast, Rank Math, SEOPress or The SEO Framework).' );
	}
	$sitemap = lccb_qa_fetch( home_url( '/wp-sitemap.xml' ) );
	if ( 200 !== $sitemap['code'] && 200 !== lccb_qa_fetch( home_url( '/sitemap_index.xml' ) )['code'] ) {
		lccb_qa_add( $f, 'should', 'seo', 'site', 'No XML sitemap found.', 'WordPress makes one at /wp-sitemap.xml unless something disabled it; SEO plugins add their own.' );
	}
	$robots = lccb_qa_fetch( home_url( '/robots.txt' ) );
	if ( 200 === $robots['code'] && preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $robots['body'] ) && get_option( 'blog_public' ) ) {
		lccb_qa_add( $f, 'critical', 'seo', 'site', 'robots.txt blocks the whole site (Disallow: /).', 'Fix robots.txt before launch.' );
	}
	if ( ! preg_match( '/wp-rocket|w3-total-cache|litespeed-cache|wp-super-cache|wp-fastest-cache|cache-enabler|sg-cachepress|breeze|hummingbird|autoptimize|perfmatters|flying-press/i', $plugins ) ) {
		lccb_qa_add( $f, 'nice', 'performance', 'site', 'No caching/optimisation plugin.', 'Many hosts cache at server level; otherwise add one on the live site (e.g. LiteSpeed Cache on LiteSpeed hosts, WP Super Cache).' );
	}
	if ( $launch && ! preg_match( '/wp-mail-smtp|fluent-smtp|post-smtp|easy-wp-smtp|wp-ses|mailgun|sendgrid|brevo/i', $plugins ) && ! empty( $site['forms'] ) ) {
		lccb_qa_add( $f, 'should', 'forms', 'site', 'No SMTP plugin: form emails sent with PHP mail() often land in spam.', 'Install WP Mail SMTP or FluentSMTP on the live site and connect the client\'s mail service.' );
	}
}

// ───────────────────────── Launch checklist ─────────────────────────

function lccb_qa_launch_checklist() {
	$root  = lccb_site_root();
	$items = array();
	$items[] = array( 'ok' => (bool) get_option( 'blog_public' ), 'item' => 'Search engines can index the site', 'how' => get_option( 'blog_public' ) ? 'On.' : 'Off now (fine while developing). On the LIVE site: Settings › Reading › untick "Discourage search engines".' );
	$redirects = glob( $root . '/redirects-*' ) ?: array();
	$crawled   = glob( wp_get_upload_dir()['basedir'] . '/lccb-migrations/*/crawl.json' ) ?: array();
	if ( $crawled || $redirects ) {
		$items[] = array( 'ok' => (bool) $redirects, 'item' => 'Redirects from the old site', 'how' => $redirects ? 'Exported: ' . implode( ', ', array_map( 'basename', $redirects ) ) . '. Import them on the live site.' : 'An old site was crawled but no redirect map exists: run lc_redirect_map.' );
	}
	$migrator = preg_match( '/duplicator|all-in-one-wp-migration|wpvivid|updraftplus|migrate-guru|backwpup/i', lccb_qa_active_plugins(), $m );
	$items[] = array( 'ok' => (bool) $migrator, 'item' => 'Backup / migration package', 'how' => $migrator ? 'Use ' . $m[0] . ' to package the site (LC Claude Bridge is excluded from All-in-One exports automatically).' : 'Install a migration plugin (e.g. Duplicator) to move the site to live.' );
	$items[] = array( 'ok' => false, 'item' => 'Disconnect and delete LC Claude Bridge', 'how' => 'Tools › Claude Code › Disconnect, then delete the plugin. (On a non-local server it switches itself off and offers a one-click cleanup anyway.)' );
	$leftovers = array_filter( array( '.mcp.json', '.claude', 'CLAUDE.md', 'launch-report.md' ), function ( $p ) use ( $root ) {
		return file_exists( $root . '/' . $p );
	} );
	$items[] = array( 'ok' => ! $leftovers, 'item' => 'Keep local Claude files off the live server', 'how' => $leftovers ? 'Exclude ' . implode( ', ', $leftovers ) . ( $redirects ? ' and the redirects-* files (after importing them)' : '' ) . ' from the deploy (migration plugins only copy WordPress files; FTP/rsync deploys need an exclude).' : 'Nothing to exclude.' );
	$items[] = array( 'ok' => false, 'item' => 'After going live', 'how' => 'Submit the sitemap in Google Search Console, send a test form submission, check the site on a phone, and set up uptime monitoring and backups.' );
	return $items;
}

// ───────────────────────── The run ─────────────────────────

function lccb_op_qa( array $args ) {
	$launch = ! empty( $args['launch'] );
	$checks = isset( $args['checks'] ) && is_array( $args['checks'] ) && $args['checks'] ? $args['checks'] : array( 'seo', 'performance', 'links', 'forms', 'basics', 'accessibility' );
	$pages  = lccb_qa_pages( $args );
	$f      = array();
	$site   = array( 'titles' => array(), 'descriptions' => array(), 'links' => array(), 'ids' => array(), 'forms' => array(), 'html' => array(), 'weights' => array() );
	$t0     = microtime( true );
	foreach ( $pages as $p ) {
		lccb_qa_page( $p, lccb_qa_fetch( $p['qa_url'], 'publish' !== $p['status'] ), $f, $site );
	}
	if ( count( $pages ) > 1 ) {
		foreach ( array( 'titles' => 'title', 'descriptions' => 'meta description' ) as $k => $label ) {
			foreach ( $site[ $k ] as $text => $where ) {
				if ( '' !== $text && count( $where ) > 1 ) {
					lccb_qa_add( $f, 'should', 'seo', implode( ', ', $where ), 'The same ' . $label . " on " . count( $where ) . " pages: \"$text\"", 'Give each page its own.' );
				}
			}
		}
	}
	$links = in_array( 'links', $checks, true ) ? lccb_qa_links( $f, $site ) : null;
	if ( in_array( 'forms', $checks, true ) ) {
		lccb_qa_forms_check( $f, $site );
	}
	if ( in_array( 'basics', $checks, true ) && ( 'site' === ( isset( $args['scope'] ) ? $args['scope'] : 'page' ) || $launch ) ) {
		lccb_qa_basics( $f, $site, $launch );
	}
	// Accessibility results from the builder tab (axe-core), passed in by the MCP server.
	foreach ( isset( $args['a11y'] ) && is_array( $args['a11y'] ) ? $args['a11y'] : array() as $page_result ) {
		foreach ( isset( $page_result['violations'] ) ? (array) $page_result['violations'] : array() as $v ) {
			$prio = in_array( $v['impact'], array( 'critical', 'serious' ), true ) ? ( 'critical' === $v['impact'] ? 'critical' : 'should' ) : ( 'moderate' === $v['impact'] ? 'should' : 'nice' );
			lccb_qa_add( $f, $prio, 'accessibility', $page_result['where'], sprintf( '%s (%d element%s, %s): %s', $v['help'], $v['count'], 1 === $v['count'] ? '' : 's', $v['impact'], implode( ' | ', array_slice( (array) $v['targets'], 0, 3 ) ) ), $v['fix'] . ( ! empty( $v['url'] ) ? ' (' . $v['url'] . ')' : '' ), 'axe-' . $v['id'] );
		}
		if ( ! empty( $page_result['error'] ) ) {
			lccb_qa_add( $f, 'nice', 'accessibility', $page_result['where'], 'Accessibility check couldn\'t run: ' . $page_result['error'], 'Open the builder and run lc_qa again.' );
		}
	}
	$f = array_values( array_filter( $f, function ( $x ) use ( $checks ) {
		return in_array( $x['check'], $checks, true );
	} ) );
	// axe's image-alt rule covers the same ground as the server-side alt count, with selectors: keep axe's.
	$axe_ran = array_filter( isset( $args['a11y'] ) && is_array( $args['a11y'] ) ? $args['a11y'] : array(), function ( $r ) {
		return empty( $r['error'] );
	} );
	if ( $axe_ran ) {
		$f = array_values( array_filter( $f, function ( $x ) {
			return ! isset( $x['group'] ) || 'no-alt' !== $x['group'];
		} ) );
	}
	$f = lccb_qa_group( $f, count( $pages ) );
	usort( $f, function ( $a, $b ) {
		return LCCB_QA_PRIORITY[ $a['priority'] ] - LCCB_QA_PRIORITY[ $b['priority'] ] ?: strcmp( $a['check'], $b['check'] );
	} );
	$counts = array_count_values( wp_list_pluck( $f, 'priority' ) );
	$out    = array(
		'checked'   => sprintf( '%d page(s)%s in %ds', count( $pages ), $links ? ", {$links['internal']} internal and {$links['external']} external link(s)" : '', round( microtime( true ) - $t0 ) ),
		'summary'   => array( 'critical' => isset( $counts['critical'] ) ? $counts['critical'] : 0, 'should_fix' => isset( $counts['should'] ) ? $counts['should'] : 0, 'nice_to_have' => isset( $counts['nice'] ) ? $counts['nice'] : 0 ),
		'findings'  => array_slice( $f, 0, 120 ),
	);
	if ( $launch ) {
		$out['launch_checklist'] = lccb_qa_launch_checklist();
		$out['report']           = lccb_qa_write_report( $out, $pages );
	}
	$out['next'] = 'Fix critical items first. Ask before changing content or settings the user may have chosen deliberately. Builder pages: lc_edit_html; saved pages: lc_page_update; images: lc_image_*; settings: tell the user where.';
	return $out;
}

function lccb_qa_write_report( array $out, array $pages ) {
	$site  = get_bloginfo( 'name' );
	$lines = array( "# Launch report: $site", '', '_' . gmdate( 'j F Y, H:i' ) . ' UTC. ' . $out['checked'] . '. Generated by LC Claude Bridge (local only: don\'t deploy this file)._', '' );
	$s     = $out['summary'];
	$lines[] = "**{$s['critical']} critical · {$s['should_fix']} should fix · {$s['nice_to_have']} nice to have**";
	$lines[] = '';
	$lines[] = '## Go-live checklist';
	$lines[] = '';
	foreach ( $out['launch_checklist'] as $c ) {
		$lines[] = '- [' . ( $c['ok'] ? 'x' : ' ' ) . '] **' . $c['item'] . ':** ' . $c['how'];
	}
	foreach ( array( 'critical' => 'Critical', 'should' => 'Should fix', 'nice' => 'Nice to have' ) as $p => $title ) {
		$items = array_filter( $out['findings'], function ( $x ) use ( $p ) {
			return $x['priority'] === $p;
		} );
		if ( ! $items ) {
			continue;
		}
		$lines[] = '';
		$lines[] = "## $title";
		$lines[] = '';
		foreach ( $items as $x ) {
			$lines[] = "- [ ] **{$x['check']}** · {$x['where']}: {$x['issue']} _Fix: {$x['fix']}_";
		}
	}
	$lines[] = '';
	$lines[] = '## Pages checked';
	$lines[] = '';
	foreach ( $pages as $p ) {
		$lines[] = "- {$p['title']}: {$p['url']}";
	}
	$file = lccb_site_root() . '/launch-report.md';
	file_put_contents( $file, implode( "\n", $lines ) . "\n" );
	return 'launch-report.md';
}
