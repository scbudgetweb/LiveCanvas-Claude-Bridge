# LiveCanvas Claude Bridge

[![Latest release](https://img.shields.io/github/v/release/scbudgetweb/LiveCanvas-Claude-Bridge)](https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge/releases/latest)
[![Licence: GPL v2 or later](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)
![Platform: macOS](https://img.shields.io/badge/platform-macOS-lightgrey)

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
  - **Point at it:** turn on Ask mode (⌖) and click an element in the preview, or drag a box around an area, or Alt+click at any time. Claude gets a cropped screenshot, the element's builder selector and its rendered HTML, so "make this tighter on mobile" just works.
  - History: resume any earlier conversation for the site, including ones started in VS Code or a terminal.
  - A context meter, a prompt-cache countdown, per-reply token and cache stats, and your plan's 5-hour and weekly usage.
- **CC Terminal tab:** the full, interactive Claude Code terminal, next to Global JS. The session keeps running when you reload the page.
- **livecanvas tools for Claude:** Claude reads and edits the *open, unsaved* builder:
  - page HTML, scoped to the element you've selected
  - Global CSS and Global JS
  - changes go through LiveCanvas's own editor, so the preview updates live and LiveCanvas's undo and History panel keep working
  - nothing is saved until you click Save, or ask Claude to save
- **Claude sees and understands the site:**
  - `lc_site_context`: versions, theme and Picostrap design tokens, the site's own class and custom-property conventions, pages, header and footer, templates and menus, in one call, even without the builder open.
  - `lc_screenshot`: Claude screenshots the preview at any width (e.g. 412px for mobile) to check its own work.
  - `lc_inspect`: rendered HTML, computed styles and the CSS rules that actually match, including shortcode and plugin output such as Forminator forms.
- **Claude checks its own work:**
  - `lc_responsive_check`: renders the page at 390, 768, 1200 and 1440px and runs layout detectors: sideways scrolling and its cause, elements wider than the screen, overlapping text, small tap targets (WCAG 2.5.8 and a 44px touch target on phones), text under 12px, images overflowing or missing their size, and clipped content. It returns one side-by-side image with the problems outlined and numbered.
  - `lc_lint`: checks the selection, a page or the whole site against the site's own design system: inline styles (with the Bootstrap utility that does the same), colours that aren't your tokens (with the matching `var()`), spacing off Bootstrap's scale, heading order, missing alt text and image sizes, empty links, duplicate ids, and classes that are defined nowhere (typos).
  - **An auto-generated site brief** in the site's `CLAUDE.md` (a managed block that never touches your own notes), so every Claude Code session starts out knowing the stack.
- **Site building, with preview then apply:** Claude can work on the rest of the site, even without the builder open:
  - pages: list, read, create drafts, update, move to the bin, open in the builder
  - the site-wide header, footer and saved Global JS
  - LiveCanvas dynamic templates and their display conditions

  Every change is a **preview first**: you see a diff and nothing is written. Only `lc_apply_change` writes, and Claude Code asks you to approve it. It refuses if the target changed since the preview, or if the page is open in a builder.
- **Design tokens (Picostrap):**
  - Claude reads and changes the theme's SCSS variables (colours, fonts, sizes, `$enable-*` switches) and web-font links, with a preview first.
  - `lc_css_recompile` rebuilds the theme CSS with Picostrap's own compiler, from inside your builder tab, and refreshes the preview.
  - The previous CSS bundle is backed up when a change is applied, so undo is instant and exact.
- **Media library:**
  - `lc_media_import` adds images from a URL, or from images you drop into CC Chat (they're saved to a protected inbox Claude can reach), with alt text. It returns ready-to-use responsive `<img>` HTML.
  - Imports ask for your approval and can be undone.
- **Change history with undo:**
  - Every applied site-level change is stored in an audit log with its before-copy.
  - Browse it in **Tools › Claude Code › Activity**, where each change has a before/after diff, or from the **Activity** button in CC Chat.
  - Undo any change with one click, or ask Claude (`lc_audit_restore`). Undos are themselves undoable, and Claude is told when you undo something.
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

1. Download `lc-claude-bridge-x.y.z.zip` from the [latest release](https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge/releases/latest).
2. In WordPress, go to Plugins › Add New › Upload Plugin, upload the zip and activate it.
3. Go to **Tools › Claude Code** and click **Connect**. Connect:
   - finds Node and Claude Code
   - installs the shared runtime and a secret token in `~/Library/Application Support/lc-claude-bridge/`
   - starts the background service, using launchd
   - registers the site
   - writes the site's `.mcp.json` and a site brief in `CLAUDE.md`
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

Site-level tools act through a PHP command-line runner as the administrator who connected the site. Previews write nothing; only `lc_apply_change` writes, and it's audited. Only administrators see the CC tabs. See [SECURITY.md](SECURITY.md) for the full model. Found a security problem? Please [report it privately](https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge/security/advisories/new) rather than opening an issue.

**Before going live:** in Tools › Claude Code, click Disconnect, then delete the plugin.

## Known limits

- **CC Chat and History** rely on Claude Code's streaming format and session files, which aren't formally documented. They were verified with Claude Code 2.1.286, and a future update may break them. **CC Terminal** keeps working regardless.
- **The bridge** uses LiveCanvas's internal editor functions, so a LiveCanvas update could break it too.
- **Design tokens** need a Picostrap theme. The recompile needs the builder open and an internet connection, because Picostrap's compiler runs in the browser.
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
| `runtime/` | Node side, copied to `~/Library/Application Support/lc-claude-bridge/runtime/<version>` on Connect: `hub.js`, `chat.js`, `mcp-server.js`, plus `wp-run.php` (the PHP command-line runner for server-side tools) |
| `build/` | `setup.sh` and `make-zip.sh` |

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). Bundled third-party libraries keep their own licences; see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
