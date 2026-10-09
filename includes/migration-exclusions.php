<?php
/**
 * Keep this dev-only plugin out of site exports where the migration tool offers a hook for it.
 * Best effort: even if it does travel, environment.php keeps it dormant (and self-deactivating) off this Mac.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// All-in-One WP Migration.
add_filter( 'ai1wm_exclude_plugins_from_export', function ( $exclude ) {
	$exclude   = (array) $exclude;
	$exclude[] = basename( LCCB_DIR );
	return $exclude;
} );
add_filter( 'ai1wm_exclude_content_from_export', function ( $exclude ) {
	$exclude   = (array) $exclude;
	$exclude[] = 'plugins' . DIRECTORY_SEPARATOR . basename( LCCB_DIR );
	return $exclude;
} );
