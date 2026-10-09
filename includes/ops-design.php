<?php
/**
 * Design tokens (Picostrap SCSS variables) and the media library.
 *
 * Tokens follow the same preview → lc_apply_change → audit flow as pages. Applying backs up the current CSS bundle
 * first, so undoing a token change restores the exact previous CSS without recompiling. The recompile itself runs
 * Picostrap's own in-browser compiler from the builder tab (lc_css_recompile in bridge.js).
 *
 * Media imports write directly (Claude Code asks for approval: lc_media_import isn't pre-allowed) and are audited;
 * undoing an import deletes the attachment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ───────────────────────── Tokens ─────────────────────────

function lccb_is_picostrap() {
	return false !== stripos( wp_get_theme()->get_template(), 'picostrap' );
}

function lccb_require_picostrap() {
	if ( ! lccb_is_picostrap() ) {
		throw new Exception( 'Design tokens need a Picostrap theme (this site uses ' . wp_get_theme()->get( 'Name' ) . '). Edit Global CSS or the theme files instead.' );
	}
}

/** "primary", "$primary" and "SCSSvar_primary" all mean the theme mod SCSSvar_primary. */
function lccb_token_mod_name( $name ) {
	$name = ltrim( trim( (string) $name ), '$' );
	if ( 0 === strpos( $name, 'SCSSvar_' ) ) {
		$name = substr( $name, 8 );
	}
	if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/i', $name ) ) {
		throw new Exception( "\"$name\" isn't a valid SCSS variable name." );
	}
	return 'SCSSvar_' . $name;
}

function lccb_bundle_path() {
	return get_stylesheet_directory() . '/css-output/bundle.css';
}

function lccb_backup_dir() {
	$up  = wp_upload_dir();
	$dir = $up['basedir'] . '/lccb-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

/** Current values of the given theme mods (null = not set). */
function lccb_mods_snapshot( array $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		$v         = get_theme_mod( $k, null );
		$out[ $k ] = ( null === $v || '' === $v ) ? null : $v;
	}
	ksort( $out );
	return $out;
}

function lccb_op_tokens_get( array $args ) {
	lccb_require_picostrap();
	$mods = (array) get_theme_mods();
	$vars = array();
	foreach ( $mods as $k => $v ) {
		if ( 0 === strpos( (string) $k, 'SCSSvar_' ) && '' !== $v && null !== $v ) {
			$vars[ '$' . substr( $k, 8 ) ] = $v;
		}
	}
	ksort( $vars );
	$bundle = lccb_bundle_path();
	return array(
		'scss_variables'    => $vars,
		'fonts_header_code' => (string) get_theme_mod( 'picostrap_fonts_header_code', '' ),
		'css_bundle'        => array(
			'path'     => $bundle,
			'exists'   => is_file( $bundle ),
			'bytes'    => is_file( $bundle ) ? filesize( $bundle ) : 0,
			'modified' => is_file( $bundle ) ? gmdate( 'Y-m-d H:i:s', filemtime( $bundle ) ) . ' UTC' : null,
			'version'  => get_theme_mod( 'css_bundle_version_number' ),
		),
		'help'              => 'Any Bootstrap 5 SCSS variable can be set, e.g. $primary, $secondary, $body-bg, $body-color, $link-color, $font-family-base, $headings-font-family, $headings-font-weight, $font-size-base, $border-radius, $spacer, $enable-shadows (true/false). Only variables set here are listed; the rest use Bootstrap/Picostrap defaults. After applying a change, run lc_css_recompile. New web fonts also need their <link> in fonts_header_code.',
	);
}

function lccb_op_tokens_update( array $args ) {
	lccb_require_picostrap();
	$set   = isset( $args['set'] ) && is_array( $args['set'] ) ? $args['set'] : array();
	$unset = isset( $args['unset'] ) ? (array) $args['unset'] : array();
	$after = array();
	foreach ( $set as $name => $value ) {
		$value = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : trim( (string) $value );
		if ( '' === $value || preg_match( '/[;{}]/', $value ) ) {
			throw new Exception( "Invalid value for $name: values can't be empty or contain ; { }." );
		}
		$key           = lccb_token_mod_name( $name );
		$after[ $key ] = 0 === strpos( $key, 'SCSSvar_enable-' ) ? ( in_array( strtolower( $value ), array( 'true', '1' ), true ) ? '1' : '0' ) : $value;
	}
	foreach ( $unset as $name ) {
		$after[ lccb_token_mod_name( $name ) ] = null;
	}
	if ( array_key_exists( 'fonts_header_code', $args ) ) {
		$code = (string) $args['fonts_header_code'];
		if ( preg_match( '/<script/i', $code ) ) {
			throw new Exception( 'fonts_header_code is for <link> tags only (no scripts).' );
		}
		$after['picostrap_fonts_header_code'] = '' === trim( $code ) ? null : $code;
	}
	if ( ! $after ) {
		throw new Exception( 'Pass set: {"primary": "#123456", …}, unset: ["primary"], and/or fonts_header_code.' );
	}
	ksort( $after );
	$before = lccb_mods_snapshot( array_keys( $after ) );
	if ( $before === $after ) {
		throw new Exception( 'Those values are already set.' );
	}
	$lines = array();
	foreach ( $after as $k => $v ) {
		$label   = 'picostrap_fonts_header_code' === $k ? 'fonts_header_code' : '$' . substr( $k, 8 );
		$lines[] = sprintf( '%s: %s → %s', $label, null === $before[ $k ] ? '(default)' : wp_json_encode( $before[ $k ] ), null === $v ? '(default)' : wp_json_encode( $v ) );
	}
	$id = 'pv_' . wp_generate_password( 10, false, false );
	set_transient( 'lccb_preview_' . $id, array(
		'tool'        => 'lc_tokens_update',
		'kind'        => 'tokens',
		'target_type' => 'tokens',
		'target_id'   => 0,
		'summary'     => 'Change design tokens: ' . implode( ', ', array_map( function ( $k ) { return 'picostrap_fonts_header_code' === $k ? 'fonts' : '$' . substr( $k, 8 ); }, array_keys( $after ) ) ),
		'before'      => array( 'mods' => $before ),
		'after'       => array( 'mods' => $after ),
		'fingerprint' => md5( wp_json_encode( $before ) ),
	), LCCB_PREVIEW_TTL );
	return array(
		'preview_id'   => $id,
		'applied'      => false,
		'summary'      => 'Change design tokens',
		'changes'      => $lines,
		'content_diff' => '',
		'warnings'     => array( 'After applying, run lc_css_recompile to rebuild the theme CSS (the site keeps the old CSS until then).' ),
		'next'         => 'Nothing has been written. Show the user the changes, then call lc_apply_change with this preview_id (valid 15 minutes).',
	);
}

/** Apply a tokens plan (or a tokens restore). @return array audit 'after' payload */
function lccb_apply_tokens_plan( array $plan, $restore_bundle = null ) {
	$keys    = array_keys( $plan['after']['mods'] );
	$current = lccb_mods_snapshot( $keys );
	if ( md5( wp_json_encode( $current ) ) !== $plan['fingerprint'] ) {
		throw new Exception( 'Those design tokens changed since the preview. Preview again.' );
	}
	// Keep the CSS that matches the current tokens, so undo can put it back without recompiling.
	$backup = null;
	if ( is_file( lccb_bundle_path() ) ) {
		$backup = lccb_backup_dir() . '/bundle-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.css';
		if ( ! copy( lccb_bundle_path(), $backup ) ) {
			throw new Exception( 'Could not back up the current CSS bundle; nothing changed.' );
		}
	}
	foreach ( $plan['after']['mods'] as $k => $v ) {
		if ( null === $v ) {
			remove_theme_mod( $k );
		} else {
			set_theme_mod( $k, $v );
		}
	}
	if ( $restore_bundle ) {
		if ( ! is_file( $restore_bundle ) || ! copy( $restore_bundle, lccb_bundle_path() ) ) {
			throw new Exception( 'Tokens restored, but the backed-up CSS bundle is missing. Run lc_css_recompile.' );
		}
		$ver = get_theme_mod( 'css_bundle_version_number' );
		set_theme_mod( 'css_bundle_version_number', ( is_numeric( $ver ) ? (int) $ver : 1 ) + 1 ); // cache-bust
	}
	return array( 'bundle_backup' => $backup );
}

// ───────────────────────── Media ─────────────────────────

function lccb_inbox_dir() {
	$up = wp_upload_dir();
	return $up['basedir'] . '/lccb-inbox';
}

function lccb_attachment_info( $id ) {
	$meta  = wp_get_attachment_metadata( $id );
	$sizes = array( 'full' => wp_get_attachment_url( $id ) );
	foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $name => $s ) {
		$src = wp_get_attachment_image_src( $id, $name );
		if ( $src ) {
			$sizes[ $name ] = $src[0] . " ({$s['width']}×{$s['height']})";
		}
	}
	return array(
		'id'     => (int) $id,
		'title'  => get_the_title( $id ),
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'mime'   => get_post_mime_type( $id ),
		'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'url'    => wp_get_attachment_url( $id ),
		'sizes'  => $sizes,
		'html'   => wp_get_attachment_image( $id, 'large', false, array( 'class' => 'img-fluid' ) ),
	);
}

function lccb_op_media_list( array $args ) {
	$q = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => min( 100, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 30 ) ) ), 'orderby' => 'date', 'order' => 'DESC' );
	if ( ! empty( $args['search'] ) ) {
		$q['s'] = $args['search'];
	}
	return array_map( function ( $p ) {
		$i = lccb_attachment_info( $p->ID );
		unset( $i['sizes'], $i['html'] );
		return $i;
	}, get_posts( $q ) );
}

/** Is this URL safe to fetch (http/https, not a private/loopback address unless it's this site)? */
function lccb_safe_remote_url( $url ) {
	$p = wp_parse_url( $url );
	if ( ! $p || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	if ( strtolower( $p['host'] ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return true;
	}
	$ip = gethostbyname( $p['host'] );
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

function lccb_op_media_import( array $args ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$cleanup = null;
	if ( ! empty( $args['url'] ) ) {
		$url = (string) $args['url'];
		if ( ! lccb_safe_remote_url( $url ) ) {
			throw new Exception( 'Only http(s) URLs on the public internet (or this site) can be imported.' );
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			throw new Exception( 'Download failed: ' . $tmp->get_error_message() );
		}
		$cleanup = $tmp;
		$name    = ! empty( $args['filename'] ) ? $args['filename'] : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$source  = $url;
	} elseif ( ! empty( $args['path'] ) ) {
		$real   = realpath( (string) $args['path'] );
		$inbox  = realpath( lccb_inbox_dir() );
		$root   = realpath( lccb_site_root() );
		$inside = $real && ( ( $inbox && 0 === strpos( $real, $inbox . '/' ) ) || 0 === strpos( $real, $root . '/' ) );
		if ( ! $inside || ! is_file( $real ) ) {
			throw new Exception( 'path must be a file inside this site (e.g. an image from the CC Chat inbox).' );
		}
		$tmp = wp_tempnam( basename( $real ) );
		copy( $real, $tmp ); // media_handle_sideload moves the file; keep the original
		$cleanup = $tmp;
		$name    = ! empty( $args['filename'] ) ? $args['filename'] : basename( $real );
		$source  = str_replace( $root . '/', '', $real );
	} else {
		throw new Exception( 'Pass url or path.' );
	}

	$info = @getimagesize( $tmp );
	if ( ! $info ) {
		@unlink( $cleanup );
		throw new Exception( 'That file isn\'t an image.' );
	}
	$ext = image_type_to_extension( $info[2], false );
	$name = sanitize_file_name( preg_replace( '/\.[a-z0-9]+$/i', '', $name ) ?: 'image' ) . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext );

	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, ! empty( $args['title'] ) ? (string) $args['title'] : null );
	if ( is_file( $tmp ) ) {
		@unlink( $tmp );
	}
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Import failed: ' . $id->get_error_message() );
	}
	if ( isset( $args['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
	}
	$audit = lccb_audit_record( 'lc_media_import', 'media', $id, 'Import image "' . $name . '" from ' . $source, null, array( 'attachment' => (int) $id, 'source' => $source ), 'claude' );
	return array( 'imported' => true, 'audit_id' => $audit, 'undo' => "lc_audit_restore {\"id\": $audit} previews deleting it again." ) + lccb_attachment_info( $id );
}
