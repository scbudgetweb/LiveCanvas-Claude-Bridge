<?php
/**
 * Injects the bridge (status pill + MCP command executor), the CC Terminal and CC Chat tabs into the LiveCanvas editor.
 * Only for admins, and only while this site is connected and bound to this Mac (see environment.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fires in the LiveCanvas editor <head> (livecanvas/editor/editor.php).
add_action( 'lc_editor_header', function () {
	if ( ! current_user_can( 'manage_options' ) || ! lccb_is_connected() ) {
		return;
	}
	// Cache-bust per file, so edits during development show up on a plain reload.
	$src = function ( $file ) {
		$mtime = @filemtime( LCCB_DIR . 'assets/' . $file );
		return esc_url( LCCB_URL . 'assets/' . $file . '?v=' . LCCB_VERSION . '-' . ( $mtime ? $mtime : 0 ) );
	};
	$config = array(
		'version' => LCCB_VERSION,
		'port'    => LCCB_HUB_PORT,
		'site'    => lccb_site_id(),
		'token'   => lccb_token(),
		'axe'     => $src( 'vendor/axe.min.js' ),
	);
	?>
	<script>window.lccbConfig = <?php echo wp_json_encode( $config ); ?>;</script>
	<link rel="stylesheet" href="<?php echo $src( 'bridge.css' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>">
	<link rel="stylesheet" href="<?php echo $src( 'vendor/xterm.css' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>">
	<link rel="stylesheet" href="<?php echo $src( 'terminal.css' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>">
	<link rel="stylesheet" href="<?php echo $src( 'chat.css' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>">
	<?php // Hide any AMD loader so the vendored UMD bundles (xterm, marked, DOMPurify, modern-screenshot) register as window globals. ?>
	<script>window.__lccbAmdDefine = window.define; window.define = undefined;</script>
	<script src="<?php echo $src( 'vendor/xterm.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script src="<?php echo $src( 'vendor/addon-fit.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script src="<?php echo $src( 'vendor/addon-web-links.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script src="<?php echo $src( 'vendor/marked.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script src="<?php echo $src( 'vendor/purify.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script src="<?php echo $src( 'vendor/modern-screenshot.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script>window.define = window.__lccbAmdDefine; delete window.__lccbAmdDefine;</script>
	<script defer src="<?php echo $src( 'bridge.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script defer src="<?php echo $src( 'terminal.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script defer src="<?php echo $src( 'chat-render.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script defer src="<?php echo $src( 'chat-attach.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script defer src="<?php echo $src( 'chat-point.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<script defer src="<?php echo $src( 'chat.js' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $src ?>"></script>
	<?php
} );
