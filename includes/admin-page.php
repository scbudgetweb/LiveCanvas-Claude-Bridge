<?php
/**
 * Tools › Claude Code: status checklist, Connect / Disconnect, manual binary paths, go-live checklist.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_PAGE', 'lc-claude-bridge' );

add_action( 'admin_menu', function () {
	add_management_page( 'Claude Code', 'Claude Code', 'manage_options', LCCB_PAGE, 'lccb_render_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( LCCB_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . LCCB_PAGE ) ) . '">Connect</a>' );
	return $links;
} );

// Remind admins when a binding exists but no longer matches (migrated / moved / other Mac).
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) && LCCB_PAGE === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( lccb_binding_is_stale() ) {
		echo '<div class="notice notice-warning"><p><strong>LC Claude Bridge</strong> was connected on another machine, folder or address, so it is switched off here. <a href="' . esc_url( admin_url( 'tools.php?page=' . LCCB_PAGE ) ) . '">Re-link it</a> if this is your dev copy.</p></div>';
	}
} );

function lccb_redirect_back( $result = null ) {
	if ( null !== $result ) {
		set_transient( 'lccb_result_' . get_current_user_id(), $result, 120 );
	}
	wp_safe_redirect( admin_url( 'tools.php?page=' . LCCB_PAGE ) );
	exit;
}

function lccb_guard( $action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( $action );
	if ( ! lccb_env_ok() ) {
		wp_die( 'LC Claude Bridge only runs on a local Mac development install.', 403 );
	}
}

add_action( 'admin_post_lccb_connect', function () {
	lccb_guard( 'lccb_connect' );
	$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in lccb_guard
	lccb_redirect_back( array( 'title' => $force ? 'Reinstall' : 'Connect' ) + lccb_connect( $force ) );
} );

add_action( 'admin_post_lccb_disconnect', function () {
	lccb_guard( 'lccb_disconnect' );
	$remaining = lccb_disconnect();
	lccb_redirect_back( array(
		'title' => 'Disconnect',
		'ok'    => true,
		'steps' => array(
			array( 'ok' => true, 'text' => 'Site unregistered, .mcp.json entry and binding removed' ),
			array( 'ok' => true, 'text' => $remaining ? "Hub left running for {$remaining} other site(s)" : 'Last site: hub service removed' ),
		),
	) );
} );

add_action( 'admin_post_lccb_paths', function () {
	lccb_guard( 'lccb_paths' );
	$clean = function ( $key, $bin ) {
		$path = isset( $_POST[ $key ] ) ? trim( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		// Only accept an absolute path to an executable actually named node / claude.
		if ( '' === $path || ( 0 === strpos( $path, '/' ) && basename( $path ) === $bin && is_file( $path ) && is_executable( $path ) ) ) {
			return $path;
		}
		return null;
	};
	$node   = $clean( 'node_path', 'node' );
	$claude = $clean( 'claude_path', 'claude' );
	$steps  = array();
	if ( null === $node || null === $claude ) {
		$steps[] = array( 'ok' => false, 'text' => 'Paths must be absolute paths to executables named "node" and "claude". Nothing saved.' );
	} else {
		update_option( LCCB_SETTINGS_OPTION, array( 'node_path' => $node, 'claude_path' => $claude ), false );
		$steps[] = array( 'ok' => true, 'text' => 'Paths saved. Click Connect to apply.' );
	}
	lccb_redirect_back( array( 'title' => 'Paths', 'ok' => null !== $node && null !== $claude, 'steps' => $steps ) );
} );

/** @return array<array{ok: bool, label: string, detail?: string}> */
function lccb_status_rows() {
	$rows = array();
	foreach ( lccb_env_checks() as $check ) {
		$rows[] = array( 'ok' => $check['ok'], 'label' => $check['label'] );
	}
	$node   = lccb_find_node();
	$claude = lccb_find_claude( $node );
	$health = lccb_health();

	$rows[] = array( 'ok' => (bool) $node, 'label' => 'Node 18+', 'detail' => $node ? "{$node['version']} · {$node['path']}" : 'Not found (set the path below)' );
	$rows[] = array( 'ok' => (bool) $claude, 'label' => 'Claude Code', 'detail' => $claude ? "{$claude['version']} · {$claude['path']}" : 'Not found (set the path below)' );
	$rows[] = array( 'ok' => '' !== lccb_token(), 'label' => 'Secret token (outside the web root)' );
	$rows[] = array(
		'ok'     => LCCB_VERSION === lccb_installed_runtime_version(),
		'label'  => 'Shared runtime',
		'detail' => lccb_installed_runtime_version() ? 'Installed ' . lccb_installed_runtime_version() . ' (plugin ' . LCCB_VERSION . ')' : 'Not installed',
	);
	$rows[] = array(
		'ok'     => (bool) $health,
		'label'  => 'Hub service',
		'detail' => $health ? "Running {$health['version']} on 127.0.0.1:" . LCCB_HUB_PORT . ' · sites: ' . implode( ', ', (array) $health['sites'] ) : 'Not running',
	);
	$rows[] = array( 'ok' => $health && in_array( lccb_site_id(), (array) $health['sites'], true ), 'label' => 'This site registered', 'detail' => lccb_site_id() . ' · ' . lccb_origin() );
	$rows[] = array( 'ok' => lccb_claude_config_ok(), 'label' => 'Claude Code config (.mcp.json)', 'detail' => lccb_site_root() . '/.mcp.json' );
	$rows[] = array(
		'ok'     => lccb_is_connected(),
		'label'  => 'Bound to this Mac, folder and address',
		'detail' => lccb_binding_is_stale() ? 'Stale: this copy was connected elsewhere. Click Connect to re-link.' : '',
	);
	return $rows;
}

function lccb_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$result = get_transient( 'lccb_result_' . get_current_user_id() );
	delete_transient( 'lccb_result_' . get_current_user_id() );
	$rows      = lccb_status_rows();
	$connected = lccb_is_connected();
	$all_ok    = ! in_array( false, wp_list_pluck( $rows, 'ok' ), true );
	$settings  = lccb_settings();
	$mark      = function ( $ok ) {
		return $ok ? '<span style="color:#00a32a">●</span>' : '<span style="color:#d63638">●</span>';
	};
	?>
	<div class="wrap">
		<h1>Claude Code <span style="font-size:13px;color:#646970;font-weight:400">LC Claude Bridge <?php echo esc_html( LCCB_VERSION ); ?></span></h1>
		<p>Connects this <strong>local</strong> site to Claude Code on this Mac: the <em>livecanvas</em> MCP tools and the CC Terminal tab in the LiveCanvas code editor.</p>

		<?php if ( $result ) : ?>
			<div class="notice <?php echo $result['ok'] ? 'notice-success' : 'notice-error'; ?>">
				<p><strong><?php echo esc_html( $result['title'] ); ?>: <?php echo $result['ok'] ? 'done' : 'stopped'; ?></strong></p>
				<ul style="margin-top:0">
					<?php foreach ( $result['steps'] as $s ) : ?>
						<li><?php echo $mark( $s['ok'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $s['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<h2>Status <?php echo $all_ok ? '<span style="color:#00a32a;font-size:14px">Ready</span>' : ''; ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td style="width:24px"><?php echo $mark( $row['ok'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td style="width:300px"><?php echo esc_html( $row['label'] ); ?></td>
					<td><code style="background:none;padding:0"><?php echo esc_html( isset( $row['detail'] ) ? $row['detail'] : '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div style="display:flex;gap:8px;margin-top:16px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lccb_connect">
				<?php wp_nonce_field( 'lccb_connect' ); ?>
				<?php submit_button( $connected ? 'Re-check & repair' : 'Connect', 'primary', 'submit', false ); ?>
			</form>
			<?php if ( $connected || lccb_binding_is_stale() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Disconnect this site from Claude Code? Its CC Terminal tab session will end.');">
					<input type="hidden" name="action" value="lccb_disconnect">
					<?php wp_nonce_field( 'lccb_disconnect' ); ?>
					<?php submit_button( 'Disconnect', 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>

		<details style="max-width:900px;margin-top:24px">
			<summary style="cursor:pointer;font-weight:600">Advanced</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="lccb_paths">
				<?php wp_nonce_field( 'lccb_paths' ); ?>
				<p>Only needed if Node or Claude Code aren't found automatically. Leave blank for auto-detect.</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="lccb-node">Node path</label></th>
						<td><input id="lccb-node" name="node_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['node_path'] ); ?>" placeholder="/opt/homebrew/bin/node"></td></tr>
					<tr><th scope="row"><label for="lccb-claude">Claude Code path</label></th>
						<td><input id="lccb-claude" name="claude_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['claude_path'] ); ?>" placeholder="/opt/homebrew/bin/claude"></td></tr>
				</table>
				<?php submit_button( 'Save paths', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px" onsubmit="return confirm('Reinstall the runtime and restart the hub? Every open Claude session on every site will end.');">
				<input type="hidden" name="action" value="lccb_connect">
				<input type="hidden" name="force" value="1">
				<?php wp_nonce_field( 'lccb_connect' ); ?>
				<?php submit_button( 'Reinstall runtime & restart hub', 'secondary', 'submit', false ); ?>
			</form>
			<p>Hub log: <code><?php echo esc_html( lccb_log_file() ); ?></code></p>
		</details>

		<h2 style="margin-top:32px">Before go-live</h2>
		<ol>
			<li>Click <strong>Disconnect</strong>, then deactivate and <strong>delete</strong> this plugin.</li>
			<li>Remove <code>.mcp.json</code> and <code>.claude/</code> from the site root if your deploy copies the whole folder.</li>
			<li>Even if you forget: on a non-Mac or non-local server this plugin deactivates itself, and a migrated copy can't connect because the token and machine binding don't travel with backups.</li>
		</ol>
	</div>
	<?php
}
