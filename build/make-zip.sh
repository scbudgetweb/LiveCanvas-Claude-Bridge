#!/usr/bin/env bash
# Builds dist/lc-claude-bridge-<version>.zip: a self-contained plugin (prebuilt node_modules, vendored xterm).
# Upload it to any local Herd + LiveCanvas site, activate, then Tools › Claude Code › Connect.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="$(basename "$PLUGIN_DIR")"
VERSION="$(grep -o "LCCB_VERSION', '[^']*'" "$PLUGIN_DIR/lc-claude-bridge.php" | cut -d"'" -f3)"
STAGE="$(mktemp -d)"
OUT_DIR="$PLUGIN_DIR/dist"
trap 'rm -rf "$STAGE"' EXIT

echo "› Building $SLUG $VERSION"

# 1. Full install (dev deps include the browser libraries) and copy them into assets/vendor.
"$PLUGIN_DIR/build/setup.sh"

# 2. Stage a copy and prune it to production deps.
mkdir -p "$STAGE/$SLUG"
rsync -a \
	--exclude '/.git' --exclude '/dist' --exclude '/build' --exclude '.DS_Store' \
	--exclude '/mcp-server' --exclude '/terminal-daemon' \
	"$PLUGIN_DIR/" "$STAGE/$SLUG/"
(cd "$STAGE/$SLUG/runtime" && npm prune --omit=dev --no-audit --no-fund >/dev/null)
# node-pty: keep only the macOS prebuilds, make spawn-helper executable, drop sources.
rm -rf "$STAGE/$SLUG/runtime/node_modules/node-pty/prebuilds/"win32-* \
       "$STAGE/$SLUG/runtime/node_modules/node-pty/src" \
       "$STAGE/$SLUG/runtime/node_modules/node-pty/deps" \
       "$STAGE/$SLUG/runtime/node_modules/node-pty/third_party" 2>/dev/null || true
chmod +x "$STAGE/$SLUG/runtime/node_modules/node-pty/prebuilds/"darwin-*/spawn-helper

# 3. Smoke test the staged runtime: every module the hub and MCP server import must load,
#    and node-pty must be able to spawn a process.
(cd "$STAGE/$SLUG/runtime" && node --input-type=module -e '
	await import("@modelcontextprotocol/sdk/server/mcp.js");
	await import("@modelcontextprotocol/sdk/server/stdio.js");
	await import("ws");
	await import("zod");
')
(cd "$STAGE/$SLUG/runtime" && node -e '
	const pty = require("node-pty");
	const p = pty.spawn("/bin/echo", ["ok"], {});
	p.onExit(({ exitCode }) => process.exit(exitCode));
')
echo "› runtime modules + node-pty OK"

# 4. Zip.
mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
echo "› $ZIP ($(du -h "$ZIP" | cut -f1))"
