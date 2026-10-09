<?php
/**
 * LC Claude Bridge — WordPress command runner (CLI only).
 *
 *   php wp-run.php <site-id> <command> [<json-args>]
 *
 * Called by mcp-server.js for server-side tools (site context, pages, partials, …) so they work without a builder tab
 * open. Loads the registered site's WordPress, acts as the admin who connected it, and prints one JSON result after a
 * marker line, so stray output from WordPress or other plugins can't corrupt it:
 *
 *   __LCCB_RESULT__{"ok":true,"result":…}   or   __LCCB_RESULT__{"ok":false,"error":"…"}
 *
 * Refuses unless the site is registered in config.json, the plugin is active, and the site still passes the plugin's
 * local + connected checks (the same ones that gate the editor integration).
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

function lccb_run_finish( $payload ) {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	fwrite( STDOUT, "\n__LCCB_RESULT__" . json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
	exit( 0 );
}

function lccb_run_fail( $message ) {
	lccb_run_finish( array( 'ok' => false, 'error' => $message ) );
}

$site    = isset( $argv[1] ) ? $argv[1] : '';
$command = isset( $argv[2] ) ? $argv[2] : '';
$args    = isset( $argv[3] ) ? json_decode( $argv[3], true ) : array();
if ( ! is_array( $args ) ) {
	$args = array();
}

$home   = getenv( 'HOME' );
$config = json_decode( (string) @file_get_contents( $home . '/Library/Application Support/lc-claude-bridge/config.json' ), true );
if ( ! is_array( $config ) || empty( $config['sites'][ $site ] ) ) {
	lccb_run_fail( "Site \"$site\" isn't connected. In its WordPress admin open Tools › Claude Code and click Connect." );
}
$conf = $config['sites'][ $site ];
$root = rtrim( $conf['root'], '/' );
if ( ! is_file( $root . '/wp-load.php' ) ) {
	lccb_run_fail( "No WordPress install found at $root." );
}

// Make WordPress believe it's serving the site's own URL (some plugins read these during bootstrap).
$parts                     = parse_url( $conf['origin'] );
$_SERVER['HTTP_HOST']      = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['SERVER_NAME']    = $parts['host'];
$_SERVER['SERVER_PORT']    = isset( $parts['port'] ) ? (string) $parts['port'] : ( 'https' === $parts['scheme'] ? '443' : '80' );
$_SERVER['HTTPS']          = 'https' === $parts['scheme'] ? 'on' : 'off';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['PHP_SELF']       = '/index.php';

define( 'WP_USE_THEMES', false );
define( 'LCCB_CLI_RUN', true );
@ini_set( 'display_errors', '0' );
ob_start(); // swallow anything WordPress or plugins echo while loading
chdir( $root );
require $root . '/wp-load.php';

if ( ! function_exists( 'lccb_ops_dispatch' ) ) {
	lccb_run_fail( 'LC Claude Bridge isn\'t active on this site (or is in cleanup mode).' );
}
if ( ! lccb_is_connected() ) {
	lccb_run_fail( 'This site isn\'t connected on this Mac (or the connection is stale). Open Tools › Claude Code and click Connect.' );
}

$user_id = isset( $conf['userId'] ) ? (int) $conf['userId'] : 0;
if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
	$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$user_id = $admins ? (int) $admins[0] : 0;
}
if ( ! $user_id ) {
	lccb_run_fail( 'No administrator account found to act as.' );
}
wp_set_current_user( $user_id );

try {
	$result = lccb_ops_dispatch( $command, $args );
	lccb_run_finish( array( 'ok' => true, 'result' => $result ) );
} catch ( Throwable $e ) {
	lccb_run_fail( $e->getMessage() );
}
