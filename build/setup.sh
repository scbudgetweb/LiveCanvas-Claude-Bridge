#!/usr/bin/env bash
# Sets up a git checkout for development: installs runtime/ dependencies (incl. dev deps)
# and copies the browser libraries into assets/vendor/. Run once after cloning, and after updating them.
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

(cd "$PLUGIN_DIR/runtime" && npm ci --no-audit --no-fund)

V="$PLUGIN_DIR/assets/vendor"
M="$PLUGIN_DIR/runtime/node_modules"
mkdir -p "$V"
cp "$M/@xterm/xterm/lib/xterm.js"                       "$V/xterm.js"
cp "$M/@xterm/xterm/css/xterm.css"                      "$V/xterm.css"
cp "$M/@xterm/addon-fit/lib/addon-fit.js"               "$V/addon-fit.js"
cp "$M/@xterm/addon-web-links/lib/addon-web-links.js"   "$V/addon-web-links.js"
cp "$M/marked/lib/marked.umd.js"                        "$V/marked.js"
cp "$M/dompurify/dist/purify.min.js"                    "$V/purify.js"
cp "$M/modern-screenshot/dist/index.js"                 "$V/modern-screenshot.js"
cp "$M/axe-core/axe.min.js"                             "$V/axe.min.js"
echo "› runtime dependencies installed, browser libraries copied to assets/vendor"
