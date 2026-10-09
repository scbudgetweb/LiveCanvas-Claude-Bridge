# LiveCanvas Claude Bridge

Unofficial WordPress plugin that connects the [LiveCanvas](https://livecanvas.com) page builder to [Claude Code](https://claude.com/claude-code) on your Mac. Chat with Claude, or use a full terminal, right inside the LiveCanvas code editor. Claude edits your page, Global CSS and Global JS live, with one-click rollback.

**Local development only.** The plugin refuses to run anywhere that isn't a local Mac install, so it's safe to leave in a site you later migrate (see [Safety](#safety)).

> Not affiliated with, endorsed by or supported by Anthropic or LiveCanvas. "Claude" and "Claude Code" are trademarks of Anthropic. "LiveCanvas" is a trademark of its owners.

## What you get

- **CC Chat tab:** a chat panel in the LiveCanvas code editor, similar to the Claude Code extension for VS Code.
  - Replies stream in as Markdown.
  - Every action is shown as a tool row, with diffs for edits.
  - Allow or Deny Claude's actions right in the row.
  - Stop a reply with the Stop button or Esc.
  - Pick the model and permission mode.
  - Images: paste, drag and drop, 📎, or 📷 to capture the LiveCanvas preview (the visible area, the whole page, or a pixel-exact Chrome capture).
  - History: resume any earlier conversation for the site, including ones started in VS Code or a terminal.
  - A context meter, a prompt-cache countdown, per-reply token and cache stats, and your plan's 5-hour and weekly usage.
- **CC Terminal tab:** the full, interactive Claude Code terminal, next to Global JS. The session keeps running when you reload the page.
- **livecanvas tools for Claude:** Claude reads and edits the *open, unsaved* builder:
  - page HTML, scoped to the element you've selected
  - Global CSS and Global JS
  - changes go through LiveCanvas's own editor, so the preview updates live and LiveCanvas's undo and History panel keep working
  - nothing is saved until you click Save, or ask Claude to save
- **Rollback:** before Claude's first change in each reply, the plugin saves a checkpoint of the page HTML, Global CSS and Global JS. **"↺ Restore to before this"** on your messages puts everything back. It also rewinds files Claude edited, such as theme files, using Claude Code's own file checkpoints.
- **Works across sites:** one shared background service on your Mac serves every connected site. Each site only ever reaches its own builder.

It uses your own Claude Code installation and login. There's no API key, and the plugin never sees your credentials.

## Requirements

- macOS (Apple Silicon or Intel)
- [Claude Code](https://claude.com/claude-code), installed and logged in
- Node.js 18 or newer
- WordPress with LiveCanvas
- A local site whose files live in your home folder, at a local address such as `.test`, `.local` or `localhost`. That means:
  - **[Herd](https://herd.laravel.com):** recommended and tested
  - **Valet:** should work
  - **Local (by Flywheel):** should work
  - **MAMP:** only if the site folder is inside your home folder
  - **Docker-based tools** (DDEV, wp-env): not supported, because PHP runs in Linux there
- Chrome or Firefox for the builder. Safari may block the local connection.

## Install

1. Download `lc-claude-bridge-x.y.z.zip` from [Releases](../../releases).
2. In WordPress, go to Plugins › Add New › Upload Plugin, upload the zip and activate it.
3. Go to **Tools › Claude Code** and click **Connect**. Connect:
   - finds Node and Claude Code
   - installs the shared runtime and a secret token in `~/Library/Application Support/lc-claude-bridge/`
   - starts the background service, using launchd
   - registers the site
   - writes the site's `.mcp.json`
   - binds the site to this Mac
4. Open a page in LiveCanvas. A green "Claude connected" pill appears in the toolbar, and the **CC Terminal** and **CC Chat** tabs appear in the code editor.

You can also run `claude` in the site's folder from a terminal or VS Code. It gets the same livecanvas tools.

## How it works

```
LiveCanvas builder ── bridge.js   (role=editor)   ─┐
                   ├─ terminal.js (role=terminal) ─┤  ws://127.0.0.1:8770
                   └─ chat.js     (role=chat)     ─┤  hub.js (launchd, one per Mac): routes by site,
Claude Code ── stdio ── mcp-server.js (role=mcp) ──┘  runs `claude` for the CC Terminal / CC Chat tabs
```

- The **hub** listens on your Mac only (`127.0.0.1`). Every connection needs the site's token. Browser connections must also come from the site's own address.
- **CC Chat** runs Claude Code in headless mode (`claude -p --input-format stream-json …`). **CC Terminal** runs it in a real terminal.
- **Edits go through LiveCanvas's own editor**, so the preview, undo and History panel behave exactly as if you'd typed them.

## Safety

Nothing that travels with a backup can switch the plugin on:

1. **The environment is checked on every request:**
   - PHP must be running on macOS
   - the site folder must be inside your home folder
   - the address must be local (or `WP_ENVIRONMENT_TYPE=local`)
2. **The token never lives in the site.** It's stored in `~/Library/Application Support/lc-claude-bridge/`, readable by your user only.
3. **The site is bound to this Mac.** Connect stores a fingerprint of this Mac's hardware ID, the site folder and the site URL, signed with the token. A migrated, moved or restored copy doesn't match, so it stays dormant and asks to be re-linked.
4. **Migrated copies get a cleanup button.** On a non-local server, the plugin injects nothing. Admins get a notice offering **Remove LC Claude Bridge and its leftover files**, which removes:
   - its `.mcp.json` entry
   - its entries in `.claude/settings.local.json`
   - its settings
   - the plugin itself

   Your own Claude Code files are kept.

Only administrators see the CC tabs. See [SECURITY.md](SECURITY.md) for the full model and how to report issues.

**Before going live:** in Tools › Claude Code, click Disconnect, then delete the plugin.

## Known limits

- **CC Chat and History** rely on Claude Code's streaming format and session files, which aren't formally documented. They were verified with Claude Code 2.1.286, and a future update may break them. **CC Terminal** keeps working regardless.
- **The bridge** uses LiveCanvas's internal editor functions, so a LiveCanvas update could break it too.
- **Rollback:**
  - It changes the builder only. If you'd already saved, save again to roll the live page back.
  - File rewind covers edits Claude made with its Edit and Write tools, for messages sent in CC Chat. Changes made by shell commands, such as database edits with `wp`, aren't tracked.
- **Preview capture** (📷 "Visible preview" and "Whole page") re-renders the page in the browser, so it can differ slightly from what you see. "Exact screenshot", or pasting your own, is pixel-perfect.
- macOS only.

## Troubleshooting

- **Tools › Claude Code** shows each check. **Re-check & repair** re-runs Connect.
- **Hub log:** `~/Library/Logs/lc-claude-bridge.log`
- **The pill stays grey, or a tab says "Hub not reachable":** click Re-check & repair.
- **Claude's shell commands use the wrong PHP or can't find `mysql`:** click Re-check & repair, which copies your login shell's PATH with Herd's tools first. Then start a New chat.
- **Port 8770 is taken:** add `define( 'LC_CLAUDE_BRIDGE_PORT', 8771 );` to `wp-config.php`, then use *Advanced › Reinstall runtime & restart hub*.
- **Uninstall completely:** Disconnect on each site (the last one removes the background service), delete the plugin, then delete `~/Library/Application Support/lc-claude-bridge/`.

## Development

```bash
git clone https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge.git wp-content/plugins/lc-claude-bridge
cd wp-content/plugins/lc-claude-bridge
./build/setup.sh        # installs runtime/ dependencies, copies browser libraries into assets/vendor/
./build/make-zip.sh     # builds dist/lc-claude-bridge-<version>.zip (production dependencies only, smoke-tested)
```

The version number lives in two places, `lc-claude-bridge.php` (`LCCB_VERSION`) and `runtime/package.json`, and they should always match. Connect reinstalls the runtime and restarts the hub when the version changes.

| Folder | What's in it |
|---|---|
| `lc-claude-bridge.php`, `includes/` | WordPress side: environment checks, Connect, the settings page, editor injection, cleanup mode |
| `assets/` | Browser side: `bridge.js` (status pill and editor commands), `terminal.js`, `chat*.js` |
| `runtime/` | Node side, copied to `~/Library/Application Support/lc-claude-bridge/runtime/<version>` on Connect: `hub.js`, `chat.js`, `mcp-server.js` |
| `build/` | `setup.sh` and `make-zip.sh` |

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). Bundled third-party libraries keep their own licences; see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
