<?php
/**
 * Plugin Name: LC Claude Bridge
 * Plugin URI:  https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge
 * Description: Unofficial, local-development only. Not affiliated with Anthropic or LiveCanvas. Connects this site to Claude Code on your Mac: livecanvas MCP tools plus CC Terminal and CC Chat tabs in the LiveCanvas code editor. Refuses to run anywhere that isn't a local Mac install, and offers one-click removal of itself and its leftovers there.
 * Version:     0.13.0
 * Author:      Shaun Corness
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_VERSION', '0.13.0' );
define( 'LCCB_FILE', __FILE__ );
define( 'LCCB_DIR', plugin_dir_path( __FILE__ ) );
define( 'LCCB_URL', plugin_dir_url( __FILE__ ) );
define( 'LCCB_PAGE', 'lc-claude-bridge' ); // Tools › Claude Code (used by admin, REST and cleanup)

require_once LCCB_DIR . 'includes/environment.php';

register_activation_hook( __FILE__, function () {
	if ( lccb_env_ok() ) {
		return;
	}
	$failed = array();
	foreach ( lccb_env_checks() as $check ) {
		if ( ! $check['ok'] ) {
			$failed[] = $check['label'];
		}
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die(
		'<p><strong>LC Claude Bridge</strong> is a local-development plugin and can only be activated on a local Mac install.</p><p>Failed: ' . esc_html( implode( '; ', $failed ) ) . '</p>',
		'LC Claude Bridge',
		array( 'back_link' => true )
	);
} );

if ( ! lccb_env_ok() ) {
	// Not a local Mac (e.g. a migrated staging/production copy): cleanup mode only. Nothing reaches the editor or
	// the front end; admins get a notice and a one-click "remove the plugin and its leftover files".
	require_once LCCB_DIR . 'includes/installer.php'; // constants + file helpers only, no hooks
	if ( is_admin() ) {
		require_once LCCB_DIR . 'includes/site-brief.php';
		require_once LCCB_DIR . 'includes/audit.php';
		require_once LCCB_DIR . 'includes/cleanup.php';
		require_once LCCB_DIR . 'includes/ops-template.php'; // functions only: the safe remover for template previews
	}
	return;
}

require_once LCCB_DIR . 'includes/installer.php';
require_once LCCB_DIR . 'includes/audit.php';
require_once LCCB_DIR . 'includes/ops.php';
require_once LCCB_DIR . 'includes/ops-build.php';
require_once LCCB_DIR . 'includes/ops-design.php';
require_once LCCB_DIR . 'includes/ops-lint.php';
require_once LCCB_DIR . 'includes/ops-template.php';
require_once LCCB_DIR . 'includes/ops-template-assets.php';
require_once LCCB_DIR . 'includes/ops-template-library.php';
require_once LCCB_DIR . 'includes/ops-sections.php';
require_once LCCB_DIR . 'includes/ops-migrate.php';
require_once LCCB_DIR . 'includes/ops-images.php';
require_once LCCB_DIR . 'includes/activity.php';
require_once LCCB_DIR . 'includes/site-brief.php';
require_once LCCB_DIR . 'includes/editor-integration.php';
require_once LCCB_DIR . 'includes/migration-exclusions.php';
if ( is_admin() ) {
	require_once LCCB_DIR . 'includes/admin-page.php';
}
