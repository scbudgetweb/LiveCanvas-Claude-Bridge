<?php
/**
 * Cleanup mode: this copy is running somewhere that isn't a local Mac (e.g. a migrated staging/production site).
 *
 * Nothing else of the plugin is loaded. We only offer to remove the plugin and the files it left in the site
 * (.mcp.json entry, .claude/settings.local.json entries, options), from an admin notice and from Tools › Claude Code.
 * The result is shown in the same request (the plugin is gone afterwards), like Duplicator's post-install cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_CLEANUP_PAGE', 'lc-claude-bridge' );

/** What this copy left in the site, so the notice can say what will be removed. */
function lccb_leftovers() {
	$root  = lccb_site_root();
	$found = array();
	$mcp   = lccb_read_json( $root . '/.mcp.json' );
	if ( isset( $mcp['mcpServers']['livecanvas'] ) ) {
		$found[] = '.mcp.json (livecanvas entry)';
	}
	$settings = lccb_read_json( $root . '/.claude/settings.local.json' );
	$allow = (array) ( isset( $settings['permissions']['allow'] ) ? $settings['permissions']['allow'] : array() );
	if ( in_array( 'livecanvas', (array) ( isset( $settings['enabledMcpjsonServers'] ) ? $settings['enabledMcpjsonServers'] : array() ), true )
		|| preg_grep( '/^mcp__livecanvas__/', $allow ) ) {
		$found[] = '.claude/settings.local.json (livecanvas entries)';
	}
	if ( false !== get_option( LCCB_BINDING_OPTION, false ) || false !== get_option( LCCB_SETTINGS_OPTION, false ) ) {
		$found[] = 'plugin settings in the database';
	}
	if ( is_file( $root . '/CLAUDE.md' ) && false !== strpos( (string) file_get_contents( $root . '/CLAUDE.md' ), '<!-- lccb:start' ) ) {
		$found[] = 'CLAUDE.md (site brief block)';
	}
	$found[] = 'the plugin folder (wp-content/plugins/' . basename( LCCB_DIR ) . ')';
	return $found;
}

function lccb_cleanup_form( $button_class = 'button button-primary' ) {
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Remove LC Claude Bridge and the files it left in this site? This cannot be undone.');">
		<input type="hidden" name="action" value="lccb_cleanup">
		<?php wp_nonce_field( 'lccb_cleanup' ); ?>
		<button type="submit" class="<?php echo esc_attr( $button_class ); ?>">Remove LC Claude Bridge and its leftover files</button>
	</form>
	<?php
	return ob_get_clean();
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'delete_plugins' ) || ( isset( $_GET['page'] ) && LCCB_CLEANUP_PAGE === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	?>
	<div class="notice notice-warning">
		<p><strong>LC Claude Bridge</strong> is a local-development plugin and is switched off on this server: it only runs on a local Mac. It looks like this site was copied from a dev install, so it brought some leftovers with it.</p>
		<p><?php echo lccb_cleanup_form(); // phpcs:ignore WordPress.Security.EscapeOutput -- built above with escaping ?> <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . LCCB_CLEANUP_PAGE ) ); ?>">See what will be removed</a></p>
	</div>
	<?php
} );

add_action( 'admin_menu', function () {
	add_management_page( 'Claude Code', 'Claude Code', 'delete_plugins', LCCB_CLEANUP_PAGE, function () {
		?>
		<div class="wrap">
			<h1>LC Claude Bridge</h1>
			<div class="notice notice-warning inline"><p>This plugin only runs on a local Mac development install, so it is switched off here and does nothing. Nothing is injected into the editor or the front end.</p></div>
			<h2>Leftovers found in this site</h2>
			<ul style="list-style:disc;padding-left:20px">
				<?php foreach ( lccb_leftovers() as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>Removing them only touches what LC Claude Bridge added: other entries in <code>.mcp.json</code> or <code>.claude/</code> are kept.</p>
			<p><?php echo lccb_cleanup_form(); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		</div>
		<?php
	} );
} );

add_action( 'admin_post_lccb_cleanup', function () {
	if ( ! current_user_can( 'delete_plugins' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'lccb_cleanup' );
	$steps = lccb_cleanup();
	lccb_render_cleanup_result( $steps );
	exit;
} );

/** @return array<array{ok: bool, text: string}> */
function lccb_cleanup() {
	$steps = array();
	$root  = lccb_site_root();

	// .mcp.json: only our entry; the file goes only if nothing else is in it.
	$file = $root . '/.mcp.json';
	if ( file_exists( $file ) ) {
		$mcp = lccb_read_json( $file );
		if ( isset( $mcp['mcpServers']['livecanvas'] ) ) {
			unset( $mcp['mcpServers']['livecanvas'] );
			$only_ours = empty( $mcp['mcpServers'] ) && 1 === count( $mcp );
			$ok        = $only_ours ? @unlink( $file ) : lccb_write_json( $file, $mcp );
			$steps[]   = array( 'ok' => (bool) $ok, 'text' => $only_ours ? 'Deleted .mcp.json' : 'Removed the livecanvas entry from .mcp.json (other servers kept)' );
		}
	}

	// .claude/settings.local.json: only our entries; file and folder go only if empty afterwards.
	$dir  = $root . '/.claude';
	$file = $dir . '/settings.local.json';
	if ( file_exists( $file ) ) {
		$s = lccb_read_json( $file );
		if ( isset( $s['enabledMcpjsonServers'] ) && is_array( $s['enabledMcpjsonServers'] ) ) {
			$s['enabledMcpjsonServers'] = array_values( array_diff( $s['enabledMcpjsonServers'], array( 'livecanvas' ) ) );
			if ( ! $s['enabledMcpjsonServers'] ) {
				unset( $s['enabledMcpjsonServers'] );
			}
		}
		if ( isset( $s['permissions']['allow'] ) && is_array( $s['permissions']['allow'] ) ) {
			// Every livecanvas permission goes (incl. ones the user "always allowed"): that server no longer exists here.
			$s['permissions']['allow'] = array_values( array_filter( $s['permissions']['allow'], function ( $rule ) {
				return 0 !== strpos( (string) $rule, 'mcp__livecanvas__' );
			} ) );
			if ( ! $s['permissions']['allow'] ) {
				unset( $s['permissions']['allow'] );
			}
			if ( ! $s['permissions'] ) {
				unset( $s['permissions'] );
			}
		}
		// enableAllProjectMcpServers alone isn't worth keeping either.
		if ( array( 'enableAllProjectMcpServers' ) === array_keys( $s ) ) {
			$s = array();
		}
		if ( ! $s ) {
			$ok      = @unlink( $file );
			$steps[] = array( 'ok' => (bool) $ok, 'text' => 'Deleted .claude/settings.local.json' );
			if ( is_dir( $dir ) && 2 === count( (array) scandir( $dir ) ) ) {
				@rmdir( $dir );
				$steps[] = array( 'ok' => ! is_dir( $dir ), 'text' => 'Deleted the empty .claude folder' );
			}
		} else {
			$steps[] = array( 'ok' => (bool) lccb_write_json( $file, $s ), 'text' => 'Removed livecanvas entries from .claude/settings.local.json (your other settings kept)' );
		}
	}

	if ( is_dir( $dir ) ) {
		$steps[] = array( 'ok' => true, 'text' => 'Kept the .claude folder: it holds your own Claude Code files (e.g. your saved permissions or plans), not the plugin\'s. Delete it yourself if it shouldn\'t be on this server.' );
	}

	// CLAUDE.md: only our managed block.
	$brief = lccb_remove_site_brief();
	if ( $brief ) {
		$steps[] = array( 'ok' => true, 'text' => 'deleted' === $brief ? 'Deleted CLAUDE.md (it only contained the site brief)' : 'Removed the site brief from CLAUDE.md (your own notes kept)' );
	}

	// Database.
	lccb_audit_uninstall();
	delete_option( LCCB_BINDING_OPTION );
	delete_option( LCCB_SETTINGS_OPTION );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lccb\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lccb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$steps[] = array( 'ok' => true, 'text' => 'Removed plugin settings and the change history (audit log) from the database' );

	// The plugin itself (WordPress may need filesystem credentials on some hosts).
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$basename = plugin_basename( LCCB_FILE );
	deactivate_plugins( $basename, true );
	$deleted = delete_plugins( array( $basename ) );
	if ( true === $deleted ) {
		$steps[] = array( 'ok' => true, 'text' => 'Deactivated and deleted the plugin' );
	} else {
		$steps[] = array( 'ok' => false, 'text' => 'Deactivated the plugin, but this server didn\'t allow deleting its folder. Delete it from the Plugins screen (or remove wp-content/plugins/' . basename( LCCB_DIR ) . ' via FTP).' );
	}
	return $steps;
}

/** Shown in the same request: once the plugin is deleted nothing of it can render on the next page load. */
function lccb_render_cleanup_result( array $steps ) {
	$all_ok = ! in_array( false, wp_list_pluck( $steps, 'ok' ), true );
	$items  = '';
	foreach ( $steps as $s ) {
		$items .= '<li><span style="color:' . ( $s['ok'] ? '#00a32a' : '#d63638' ) . '">' . ( $s['ok'] ? '✓' : '✗' ) . '</span> ' . esc_html( $s['text'] ) . '</li>';
	}
	wp_die(
		'<h1>' . ( $all_ok ? 'LC Claude Bridge removed' : 'LC Claude Bridge mostly removed' ) . '</h1>' .
		'<p>' . ( $all_ok ? 'The plugin and the files it left in this site have been removed.' : 'Almost done: see the item marked ✗.' ) . '</p>' .
		'<ul style="list-style:none;padding-left:0;line-height:1.9">' . $items . '</ul>' .
		'<p><a class="button button-primary" href="' . esc_url( admin_url() ) . '">Back to the dashboard</a></p>',
		'LC Claude Bridge removed',
		array( 'response' => 200 )
	);
}
