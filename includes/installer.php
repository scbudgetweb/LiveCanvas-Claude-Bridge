<?php
/**
 * Connect / Disconnect: installs the shared runtime + launchd hub, registers this site, writes Claude Code config.
 *
 * Every command is a fixed argument array run without a shell (lccb_run); nothing from the request reaches it.
 * Callers must have checked manage_options, the nonce and lccb_env_ok().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_SERVICE_LABEL', 'com.lc-claude-bridge.hub' );
define( 'LCCB_READONLY_TOOLS', array(
	'mcp__livecanvas__lc_get_context',
	'mcp__livecanvas__lc_read_html',
	'mcp__livecanvas__lc_read_css',
	'mcp__livecanvas__lc_read_js',
	'mcp__livecanvas__lc_select',
	'mcp__livecanvas__lc_site_context',
	'mcp__livecanvas__lc_screenshot',
	'mcp__livecanvas__lc_inspect',
	// Site-level reads and previews (previews never write; lc_apply_change does, and still asks).
	'mcp__livecanvas__lc_pages_list',
	'mcp__livecanvas__lc_page_read',
	'mcp__livecanvas__lc_page_create',
	'mcp__livecanvas__lc_page_update',
	'mcp__livecanvas__lc_partial_read',
	'mcp__livecanvas__lc_partial_update',
	'mcp__livecanvas__lc_templates_list',
	'mcp__livecanvas__lc_template_read',
	'mcp__livecanvas__lc_template_upsert',
	'mcp__livecanvas__lc_audit_list',
	'mcp__livecanvas__lc_audit_restore',
	'mcp__livecanvas__lc_tokens_get',
	'mcp__livecanvas__lc_tokens_update',
	'mcp__livecanvas__lc_css_recompile',
	'mcp__livecanvas__lc_media_list',
) );
define( 'LCCB_HUB_PORT', defined( 'LC_CLAUDE_BRIDGE_PORT' ) ? (int) LC_CLAUDE_BRIDGE_PORT : 8770 );

function lccb_plist_path( $label = LCCB_SERVICE_LABEL ) {
	return lccb_home_dir() . '/Library/LaunchAgents/' . $label . '.plist';
}

function lccb_runtime_current() {
	return lccb_support_dir() . '/runtime/current';
}

function lccb_log_file() {
	return lccb_home_dir() . '/Library/Logs/lc-claude-bridge.log';
}

// ───────────────────────── Finding node + claude ─────────────────────────

/** @return string[] newest first */
function lccb_versioned_bins( $versions_dir, $bin ) {
	$dirs = glob( $versions_dir . '/v*', GLOB_ONLYDIR ) ?: array();
	usort( $dirs, function ( $a, $b ) {
		return version_compare( ltrim( basename( $b ), 'v' ), ltrim( basename( $a ), 'v' ) );
	} );
	return array_map( function ( $d ) use ( $bin ) {
		return $d . '/bin/' . $bin;
	}, $dirs );
}

/** @return array{path: string, version: string}|null */
function lccb_find_node() {
	$home       = lccb_home_dir();
	$settings   = lccb_settings();
	$candidates = array_merge(
		array_filter( array( $settings['node_path'] ) ),
		lccb_versioned_bins( $home . '/Library/Application Support/Herd/config/nvm/versions/node', 'node' ),
		lccb_versioned_bins( $home . '/.nvm/versions/node', 'node' ),
		array( '/opt/homebrew/bin/node', '/usr/local/bin/node' )
	);
	foreach ( $candidates as $path ) {
		if ( ! is_file( $path ) || ! is_executable( $path ) ) {
			continue;
		}
		$r = lccb_run( array( $path, '--version' ) );
		if ( 0 === $r['code'] && preg_match( '/^v(\d+)\./', $r['out'], $m ) && (int) $m[1] >= 18 ) {
			return array( 'path' => $path, 'version' => $r['out'] );
		}
	}
	return null;
}

/** @return array{path: string, version: string}|null */
function lccb_find_claude( $node = null ) {
	$home       = lccb_home_dir();
	$settings   = lccb_settings();
	$candidates = array_filter( array(
		$settings['claude_path'],
		'/opt/homebrew/bin/claude',
		'/usr/local/bin/claude',
		$home . '/.local/bin/claude',
		$home . '/.claude/local/claude',
		$home . '/.npm-global/bin/claude',
		$node ? dirname( $node['path'] ) . '/claude' : '',
	) );
	foreach ( array_unique( $candidates ) as $path ) {
		if ( ! is_file( $path ) || ! is_executable( $path ) ) {
			continue;
		}
		// npm installs are `#!/usr/bin/env node` scripts, so give it a PATH that includes node.
		$r = lccb_run( array( $path, '--version' ), array( 'PATH' => lccb_exec_path( $node, null ), 'HOME' => $home ) );
		if ( 0 === $r['code'] && false !== stripos( $r['out'], 'claude code' ) ) {
			return array( 'path' => $path, 'version' => $r['out'] );
		}
	}
	return null;
}

/**
 * The PATH your own login shell uses, so Claude's Bash tool finds the same php / mysql / wp / composer
 * as your Terminal (Herd puts its own bin folder there). Read once per request with a 5s cap; [] on failure.
 *
 * @return string[]
 */
function lccb_login_shell_path() {
	static $dirs = null;
	if ( null !== $dirs ) {
		return $dirs;
	}
	$dirs  = array();
	$user  = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() ) : null;
	$shell = $user && ! empty( $user['shell'] ) && is_executable( $user['shell'] ) ? $user['shell'] : '/bin/zsh';
	$env   = array( 'HOME' => lccb_home_dir(), 'USER' => $user ? $user['name'] : '', 'TERM' => 'dumb', 'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin' );
	$proc  = @proc_open( array( $shell, '-ilc', 'printf "__SCPATH__%s__END__" "$PATH"' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, lccb_home_dir(), $env );
	if ( ! is_resource( $proc ) ) {
		return $dirs;
	}
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	$out      = '';
	$deadline = microtime( true ) + 5;
	while ( microtime( true ) < $deadline && false === strpos( $out, '__END__' ) ) {
		$read = array( $pipes[1] );
		$w    = null;
		$e    = null;
		if ( stream_select( $read, $w, $e, 0, 200000 ) ) {
			$chunk = fread( $pipes[1], 8192 );
			if ( false === $chunk || ( '' === $chunk && feof( $pipes[1] ) ) ) {
				break;
			}
			$out .= $chunk;
		}
	}
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_terminate( $proc );
	proc_close( $proc );
	if ( preg_match( '/__SCPATH__(.*?)__END__/s', $out, $m ) ) {
		foreach ( explode( ':', $m[1] ) as $dir ) {
			$dir = rtrim( $dir, '/' );
			if ( '' !== $dir && 0 === strpos( $dir, '/' ) && is_dir( $dir ) ) {
				$dirs[] = $dir;
			}
		}
	}
	return $dirs;
}

/** PHP CLI for runtime/wp-run.php: Herd's first, then Homebrew / system. @return array{path: string, version: string}|null */
function lccb_find_php() {
	$candidates = array(
		lccb_home_dir() . '/Library/Application Support/Herd/bin/php',
		'/opt/homebrew/bin/php',
		'/usr/local/bin/php',
		'/usr/bin/php',
	);
	foreach ( $candidates as $path ) {
		if ( ! is_file( $path ) || ! is_executable( $path ) ) {
			continue;
		}
		$r = lccb_run( array( $path, '-r', 'echo PHP_VERSION;' ), array( 'PATH' => dirname( $path ) . ':/usr/bin:/bin', 'HOME' => lccb_home_dir() ) );
		if ( 0 === $r['code'] && preg_match( '/^\d+\.\d+/', $r['out'] ) ) {
			return array( 'path' => $path, 'version' => $r['out'] );
		}
	}
	return null;
}

/** PATH for the hub's claude sessions (and their MCP servers / tools). */
function lccb_exec_path( $node, $claude ) {
	$dirs = array();
	// Herd's php / mysql / composer first, so they win over e.g. Homebrew's php (Herd's bin has no node or claude).
	$herd_bin = lccb_home_dir() . '/Library/Application Support/Herd/bin';
	if ( is_dir( $herd_bin ) ) {
		$dirs[] = $herd_bin;
	}
	if ( $node ) {
		$dirs[] = dirname( $node['path'] );
	}
	if ( $claude ) {
		$dirs[] = dirname( $claude['path'] );
	}
	$dirs = array_merge( $dirs, lccb_login_shell_path(), array( '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin' ) );
	return implode( ':', array_values( array_unique( $dirs ) ) );
}

// ───────────────────────── Files ─────────────────────────

function lccb_read_json( $file ) {
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $data ) ? $data : array();
}

function lccb_write_json( $file, array $data, $mode = 0644 ) {
	if ( ! is_dir( dirname( $file ) ) ) {
		wp_mkdir_p( dirname( $file ) );
	}
	$tmp  = $file . '.tmp-' . wp_generate_password( 6, false );
	$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( false === file_put_contents( $tmp, $json ) ) {
		return false;
	}
	chmod( $tmp, $mode );
	return rename( $tmp, $file );
}

function lccb_ensure_token() {
	$dir = lccb_support_dir();
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	if ( '' !== lccb_token() ) {
		return true;
	}
	$file = $dir . '/token';
	if ( false === file_put_contents( $file, bin2hex( random_bytes( 32 ) ) . "\n" ) ) {
		return false;
	}
	return chmod( $file, 0600 );
}

/** Copy plugin/runtime → support/runtime/<version>, point runtime/current at it. */
function lccb_install_runtime() {
	$src    = LCCB_DIR . 'runtime';
	$base   = lccb_support_dir() . '/runtime';
	$target = $base . '/' . LCCB_VERSION;
	if ( ! is_dir( $src . '/node_modules/node-pty' ) ) {
		return new WP_Error( 'runtime', 'The plugin is missing runtime/node_modules. Use the packaged zip (build/make-zip.sh).' );
	}
	wp_mkdir_p( $base );
	if ( is_dir( $target ) ) {
		lccb_run( array( '/bin/rm', '-rf', $target ) );
	}
	$r = lccb_run( array( '/bin/cp', '-R', $src, $target ) );
	if ( 0 !== $r['code'] ) {
		return new WP_Error( 'runtime', 'Copying the runtime failed: ' . $r['err'] );
	}
	foreach ( glob( $target . '/node_modules/node-pty/prebuilds/*/spawn-helper' ) ?: array() as $helper ) {
		chmod( $helper, 0755 );
	}
	$current = $base . '/current';
	if ( is_link( $current ) || file_exists( $current ) ) {
		unlink( $current );
	}
	if ( ! symlink( $target, $current ) ) {
		return new WP_Error( 'runtime', 'Could not create runtime/current symlink.' );
	}
	// Drop older versions (running MCP servers keep their already-loaded code).
	foreach ( glob( $base . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
		if ( basename( $dir ) !== LCCB_VERSION && basename( $dir ) !== 'current' ) {
			lccb_run( array( '/bin/rm', '-rf', $dir ) );
		}
	}
	return true;
}

function lccb_installed_runtime_version() {
	$pkg = lccb_read_json( lccb_runtime_current() . '/package.json' );
	return isset( $pkg['version'] ) ? $pkg['version'] : '';
}

function lccb_register_site( $node, $claude, $php = null ) {
	$file   = lccb_support_dir() . '/config.json';
	$config = lccb_read_json( $file );
	$config['port']       = LCCB_HUB_PORT;
	$config['claudePath'] = $claude['path'];
	$config['path']       = lccb_exec_path( $node, $claude );
	if ( $php ) {
		$config['phpPath'] = $php['path'];
	}
	$config['sites']      = isset( $config['sites'] ) && is_array( $config['sites'] ) ? $config['sites'] : array();
	$config['sites'][ lccb_site_id() ] = array(
		'root'   => lccb_site_root(),
		'origin' => lccb_origin(),
		// The admin the server-side tools act as (wp-run.php). CLI connects fall back to the first administrator.
		'userId' => get_current_user_id() ? get_current_user_id() : 0,
	);
	return lccb_write_json( $file, $config, 0600 );
}

function lccb_unregister_site() {
	$file   = lccb_support_dir() . '/config.json';
	$config = lccb_read_json( $file );
	unset( $config['sites'][ lccb_site_id() ] );
	lccb_write_json( $file, $config, 0600 );
	return empty( $config['sites'] ) ? 0 : count( $config['sites'] );
}

/** Merge the livecanvas server into the site's .mcp.json and pre-approve it in .claude/settings.local.json. */
function lccb_write_claude_config( $node ) {
	$root = lccb_site_root();
	$mcp  = lccb_read_json( $root . '/.mcp.json' );
	$mcp['mcpServers']               = isset( $mcp['mcpServers'] ) && is_array( $mcp['mcpServers'] ) ? $mcp['mcpServers'] : array();
	$mcp['mcpServers']['livecanvas'] = array(
		'command' => $node['path'],
		'args'    => array( lccb_runtime_current() . '/mcp-server.js', '--site', lccb_site_id() ),
	);
	$ok = lccb_write_json( $root . '/.mcp.json', $mcp );

	$settings_file = $root . '/.claude/settings.local.json';
	$settings      = lccb_read_json( $settings_file );
	$enabled       = isset( $settings['enabledMcpjsonServers'] ) && is_array( $settings['enabledMcpjsonServers'] ) ? $settings['enabledMcpjsonServers'] : array();
	if ( ! in_array( 'livecanvas', $enabled, true ) ) {
		$enabled[] = 'livecanvas';
	}
	$settings['enabledMcpjsonServers'] = array_values( $enabled );

	// Read-only livecanvas tools never need approval; edits and saves still ask.
	$settings['permissions']          = isset( $settings['permissions'] ) && is_array( $settings['permissions'] ) ? $settings['permissions'] : array();
	$allow                            = isset( $settings['permissions']['allow'] ) && is_array( $settings['permissions']['allow'] ) ? $settings['permissions']['allow'] : array();
	$settings['permissions']['allow'] = array_values( array_unique( array_merge( $allow, LCCB_READONLY_TOOLS ) ) );
	return $ok && lccb_write_json( $settings_file, $settings );
}

function lccb_remove_claude_config() {
	$root = lccb_site_root();
	$mcp  = lccb_read_json( $root . '/.mcp.json' );
	if ( isset( $mcp['mcpServers']['livecanvas'] ) ) {
		unset( $mcp['mcpServers']['livecanvas'] );
		if ( empty( $mcp['mcpServers'] ) && 1 === count( $mcp ) ) {
			@unlink( $root . '/.mcp.json' );
		} else {
			lccb_write_json( $root . '/.mcp.json', $mcp );
		}
	}
	$settings_file = $root . '/.claude/settings.local.json';
	$settings      = lccb_read_json( $settings_file );
	if ( isset( $settings['enabledMcpjsonServers'] ) && is_array( $settings['enabledMcpjsonServers'] ) ) {
		$settings['enabledMcpjsonServers'] = array_values( array_diff( $settings['enabledMcpjsonServers'], array( 'livecanvas' ) ) );
	}
	if ( isset( $settings['permissions']['allow'] ) && is_array( $settings['permissions']['allow'] ) ) {
		$settings['permissions']['allow'] = array_values( array_diff( $settings['permissions']['allow'], LCCB_READONLY_TOOLS ) );
	}
	if ( $settings ) {
		lccb_write_json( $settings_file, $settings );
	}
}

/** Is .mcp.json pointing at the shared runtime for this site? */
function lccb_claude_config_ok() {
	$mcp = lccb_read_json( lccb_site_root() . '/.mcp.json' );
	$lc  = isset( $mcp['mcpServers']['livecanvas'] ) ? $mcp['mcpServers']['livecanvas'] : null;
	return $lc && isset( $lc['args'] ) && array( lccb_runtime_current() . '/mcp-server.js', '--site', lccb_site_id() ) === $lc['args'];
}

// ───────────────────────── launchd ─────────────────────────

function lccb_launchctl( array $args ) {
	return lccb_run( array_merge( array( '/bin/launchctl' ), $args ) );
}

function lccb_domain() {
	return 'gui/' . posix_geteuid();
}

function lccb_remove_service( $label ) {
	lccb_launchctl( array( 'bootout', lccb_domain() . '/' . $label ) );
	if ( file_exists( lccb_plist_path( $label ) ) ) {
		unlink( lccb_plist_path( $label ) );
	}
}

function lccb_install_service( $node ) {
	$x     = function ( $s ) {
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$plist = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key><string>' . LCCB_SERVICE_LABEL . '</string>
	<key>ProgramArguments</key>
	<array>
		<string>' . $x( $node['path'] ) . '</string>
		<string>' . $x( lccb_runtime_current() . '/hub.js' ) . '</string>
	</array>
	<key>WorkingDirectory</key><string>' . $x( lccb_runtime_current() ) . '</string>
	<key>RunAtLoad</key><true/>
	<key>KeepAlive</key><true/>
	<key>ThrottleInterval</key><integer>5</integer>
	<key>StandardOutPath</key><string>' . $x( lccb_log_file() ) . '</string>
	<key>StandardErrorPath</key><string>' . $x( lccb_log_file() ) . '</string>
</dict>
</plist>
';
	wp_mkdir_p( dirname( lccb_plist_path() ) );
	if ( false === file_put_contents( lccb_plist_path(), $plist ) ) {
		return new WP_Error( 'service', 'Could not write ' . lccb_plist_path() );
	}
	lccb_launchctl( array( 'bootout', lccb_domain() . '/' . LCCB_SERVICE_LABEL ) );
	$r = lccb_launchctl( array( 'bootstrap', lccb_domain(), lccb_plist_path() ) );
	if ( 0 !== $r['code'] ) {
		// bootout is asynchronous; one retry covers the "service already loaded" race.
		usleep( 800000 );
		$r = lccb_launchctl( array( 'bootstrap', lccb_domain(), lccb_plist_path() ) );
	}
	if ( 0 !== $r['code'] ) {
		return new WP_Error(
			'service',
			'launchctl bootstrap failed (' . $r['code'] . '): ' . $r['err'] . ' — run this once in Terminal: launchctl bootstrap ' . lccb_domain() . ' "' . lccb_plist_path() . '"'
		);
	}
	return true;
}

/** @return array|null hub /health payload */
function lccb_health() {
	$token = lccb_token();
	if ( '' === $token ) {
		return null;
	}
	$res = wp_remote_get( 'http://127.0.0.1:' . LCCB_HUB_PORT . '/health', array(
		'timeout' => 2,
		'headers' => array( 'x-lccb-token' => $token ),
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? $data : null;
}

// ───────────────────────── Connect / Disconnect ─────────────────────────

/**
 * @param bool $force_reinstall Re-copy the runtime and restart the hub even if it's current (ends all open sessions).
 * @return array{ok: bool, steps: array<array{ok: bool, text: string}>}
 */
function lccb_connect( $force_reinstall = false ) {
	$steps = array();
	$step  = function ( $ok, $text ) use ( &$steps ) {
		$steps[] = array( 'ok' => (bool) $ok, 'text' => $text );
		return (bool) $ok;
	};
	$fail  = function () use ( &$steps ) {
		return array( 'ok' => false, 'steps' => $steps );
	};

	if ( ! $step( lccb_env_ok(), 'Local Mac install' ) ) {
		return $fail();
	}
	$node = lccb_find_node();
	if ( ! $step( (bool) $node, $node ? "Node {$node['version']} ({$node['path']})" : 'Node 18+ not found. Set its path below.' ) ) {
		return $fail();
	}
	$claude = lccb_find_claude( $node );
	if ( ! $step( (bool) $claude, $claude ? "{$claude['version']} ({$claude['path']})" : 'Claude Code not found. Set its path below.' ) ) {
		return $fail();
	}
	if ( ! $step( lccb_ensure_token(), 'Secret token in ~/Library/Application Support/lc-claude-bridge' ) ) {
		return $fail();
	}
	// The hub is shared by every site and re-reads config.json live, so when it's already running this
	// version, leave it alone: restarting it would end every open Claude session on every site.
	$health  = lccb_health();
	$current = $health && LCCB_VERSION === $health['version'] && LCCB_VERSION === lccb_installed_runtime_version();
	$force   = $force_reinstall || ! $current;

	if ( $force ) {
		$runtime = lccb_install_runtime();
		if ( ! $step( true === $runtime, true === $runtime ? 'Runtime ' . LCCB_VERSION . ' installed' : $runtime->get_error_message() ) ) {
			return $fail();
		}
	} else {
		$step( true, 'Runtime ' . LCCB_VERSION . ' already installed' );
	}
	$php = lccb_find_php();
	$step( (bool) $php, $php ? "PHP {$php['version']} for site tools ({$php['path']})" : 'PHP CLI not found: site-level tools (lc_site_context) will be unavailable' );
	if ( ! $step( lccb_register_site( $node, $claude, $php ), 'Registered site "' . lccb_site_id() . '" (' . lccb_origin() . ')' ) ) {
		return $fail();
	}
	if ( $force ) {
		$service = lccb_install_service( $node );
		if ( ! $step( true === $service, true === $service ? 'Hub service (launchd) started' : $service->get_error_message() ) ) {
			return $fail();
		}
	} else {
		$step( true, 'Hub service already running (left untouched so other sites\' sessions keep going)' );
	}
	if ( ! $step( lccb_write_claude_config( $node ), 'Claude Code config: .mcp.json + .claude/settings.local.json' ) ) {
		return $fail();
	}
	lccb_audit_install();
	$brief = lccb_write_site_brief();
	$step( (bool) $brief, $brief ? 'Site brief written to CLAUDE.md (managed block)' : 'Could not write CLAUDE.md' );
	update_option( LCCB_BINDING_OPTION, lccb_binding_value(), false );
	$step( lccb_is_connected(), 'Bound to this Mac, folder and address' );

	$health = null;
	for ( $i = 0; $i < 10 && ! $health; $i++ ) {
		usleep( 300000 );
		$health = lccb_health();
	}
	$step( $health && in_array( lccb_site_id(), (array) $health['sites'], true ), $health ? "Hub {$health['version']} answering on port " . LCCB_HUB_PORT : 'Hub not answering yet — check ' . lccb_log_file() );

	$all_ok = ! in_array( false, wp_list_pluck( $steps, 'ok' ), true );
	return array( 'ok' => $all_ok, 'steps' => $steps );
}

function lccb_disconnect() {
	$remaining = lccb_unregister_site();
	lccb_remove_claude_config();
	lccb_remove_site_brief();
	delete_option( LCCB_BINDING_OPTION );
	if ( 0 === $remaining ) {
		lccb_remove_service( LCCB_SERVICE_LABEL );
	}
	return $remaining;
}
