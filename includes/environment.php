<?php
/**
 * Environment checks and machine binding.
 *
 * Nothing that travels with a backup can enable this plugin:
 *  1. lccb_env_ok()        → must be macOS, the site must live in the PHP user's home folder and use a local hostname.
 *                             Production hosting fails this, and the plugin deactivates itself.
 *  2. lccb_token()         → the shared secret lives in ~/Library/Application Support/lc-claude-bridge, never in the site.
 *  3. lccb_is_connected()  → the stored binding is an HMAC of (this Mac's hardware UUID | ABSPATH | home_url) keyed by
 *                             the token, so a migrated, moved or restored-elsewhere copy no longer matches.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_BINDING_OPTION', 'lccb_binding' );
define( 'LCCB_SETTINGS_OPTION', 'lccb_settings' );

function lccb_home_dir() {
	if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
		$user = posix_getpwuid( posix_geteuid() );
		if ( $user && ! empty( $user['dir'] ) ) {
			return rtrim( $user['dir'], '/' );
		}
	}
	return rtrim( (string) getenv( 'HOME' ), '/' );
}

function lccb_support_dir() {
	return lccb_home_dir() . '/Library/Application Support/lc-claude-bridge';
}

function lccb_site_id() {
	return basename( untrailingslashit( ABSPATH ) );
}

function lccb_site_root() {
	return untrailingslashit( ABSPATH );
}

/** scheme://host[:port] of the site, i.e. the Origin header its builder tabs send. */
function lccb_origin() {
	$parts  = wp_parse_url( home_url() );
	$origin = $parts['scheme'] . '://' . $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$origin .= ':' . $parts['port'];
	}
	return $origin;
}

/**
 * Layer 1: is this a local Mac dev install? Cheap enough to run on every request.
 *
 * @return array<string, array{ok: bool, label: string}>
 */
function lccb_env_checks() {
	$home = lccb_home_dir();
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$local_host = 'localhost' === $host
		|| (bool) preg_match( '/\.(test|local|localhost)$/', $host )
		|| 'local' === wp_get_environment_type()
		|| ( defined( 'LC_CLAUDE_BRIDGE' ) && LC_CLAUDE_BRIDGE );

	return array(
		'darwin'     => array(
			'ok'    => 'Darwin' === PHP_OS_FAMILY,
			'label' => 'Running on macOS (' . PHP_OS_FAMILY . ')',
		),
		'home'       => array(
			'ok'    => '' !== $home && 0 === strpos( lccb_site_root() . '/', $home . '/' ),
			'label' => 'Site folder is inside your home folder',
		),
		'local_host' => array(
			'ok'    => $local_host,
			'label' => 'Local address (' . $host . ')',
		),
	);
}

function lccb_env_ok() {
	if ( defined( 'LC_CLAUDE_BRIDGE' ) && ! LC_CLAUDE_BRIDGE ) {
		return false;
	}
	foreach ( lccb_env_checks() as $check ) {
		if ( ! $check['ok'] ) {
			return false;
		}
	}
	return true;
}

/** Layer 2: the shared secret, outside the web root. '' if this Mac was never set up. */
function lccb_token() {
	$file = lccb_support_dir() . '/token';
	if ( ! is_readable( $file ) ) {
		return '';
	}
	$token = trim( (string) file_get_contents( $file ) );
	return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
}

/**
 * Run a fixed command (array form: no shell, nothing interpolated).
 *
 * @return array{code: int, out: string, err: string}
 */
function lccb_run( array $cmd, ?array $env = null, $cwd = null ) {
	$proc = @proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env );
	if ( ! is_resource( $proc ) ) {
		return array( 'code' => -1, 'out' => '', 'err' => 'proc_open failed' );
	}
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array( 'code' => (int) $code, 'out' => trim( (string) $out ), 'err' => trim( (string) $err ) );
}

/** This Mac's hardware UUID (read live, never cached on disk, so a copied support folder doesn't carry it). */
function lccb_machine_uuid() {
	static $uuid = null;
	if ( null === $uuid ) {
		$r    = lccb_run( array( '/usr/sbin/ioreg', '-rd1', '-c', 'IOPlatformExpertDevice' ) );
		$uuid = preg_match( '/"IOPlatformUUID"\s*=\s*"([0-9A-F-]+)"/i', $r['out'], $m ) ? $m[1] : '';
	}
	return $uuid;
}

function lccb_binding_value() {
	$token = lccb_token();
	$uuid  = lccb_machine_uuid();
	if ( '' === $token || '' === $uuid ) {
		return '';
	}
	return hash_hmac( 'sha256', $uuid . '|' . lccb_site_root() . '|' . home_url(), $token );
}

/**
 * Layer 3: connected = local Mac + token present + binding matches this machine/folder/URL.
 * Only called where it matters (LiveCanvas editor pages, the settings page) because it shells out to ioreg.
 */
function lccb_is_connected() {
	static $connected = null;
	if ( null === $connected ) {
		$stored    = (string) get_option( LCCB_BINDING_OPTION, '' );
		$expected  = lccb_env_ok() ? lccb_binding_value() : '';
		$connected = '' !== $stored && '' !== $expected && hash_equals( $expected, $stored );
	}
	return $connected;
}

/** True when a binding exists but no longer matches (migrated, moved, restored on another Mac, token rotated). */
function lccb_binding_is_stale() {
	return '' !== (string) get_option( LCCB_BINDING_OPTION, '' ) && ! lccb_is_connected();
}

function lccb_settings() {
	return wp_parse_args( (array) get_option( LCCB_SETTINGS_OPTION, array() ), array( 'node_path' => '', 'claude_path' => '' ) );
}
