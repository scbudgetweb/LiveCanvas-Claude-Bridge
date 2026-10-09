# Security

LiveCanvas Claude Bridge lets a page in your browser (the LiveCanvas editor) drive Claude Code, which can read and write files and run commands on your Mac. That power is the point of the plugin, and it's why the safety model below matters.

## Model

- **Local only.** The plugin only runs when all three hold:
  - PHP is running on macOS
  - the site's folder is inside the PHP user's home folder
  - the site uses a local address (`.test`, `.local`, `localhost`, or `WP_ENVIRONMENT_TYPE=local`)

  Elsewhere it injects nothing and only offers to remove itself.
- **Admins only.** The editor integration, the CC Terminal and CC Chat tabs, and the Tools › Claude Code page all need `manage_options`. Connect, Disconnect and the path settings are protected by WordPress nonces.
- **Loopback only.** The hub listens on `127.0.0.1` only (port 8770 by default).
- **A shared secret.** A 256-bit token is created on Connect and stored at `~/Library/Application Support/lc-claude-bridge/token` (mode 0600), outside the web root. It never goes in the database or the site's files.
- **Every connection to the hub is checked:**
  - **every connection:** it must present the token and a registered site ID
  - **browser roles** (editor, terminal, chat): the `Origin` header must match that site's registered address
  - **MCP clients:** must *not* send an `Origin`, so a web page can't pose as one
- **Site isolation.** Tool calls from one site's Claude sessions are only ever sent to that same site's builder tabs.
- **Fixed commands only.**
  - **Connect and the hub only ever start** the `claude` binary, `launchctl`, `cp`/`rm` on the plugin's own runtime folder, and `ioreg`. All are run with fixed arguments and no shell, and nothing from a request is passed into a command.
  - **The hub only starts `claude`,** and only in a registered site's folder.
- **Machine binding.** The stored "connected" state is a fingerprint (HMAC) of this Mac's hardware UUID, the site folder and the site URL, signed with the token. A copy of the site on another machine, at another URL or in another folder doesn't match, so it stays dormant.
- **Claude's own permissions still apply.** In CC Chat, every edit or command that Claude Code would normally ask about needs your approval, unless you pick a more permissive mode yourself.

## Out of scope

Anything running as your own user on your Mac can already do what Claude Code can, so the plugin doesn't try to defend against local malware. It also assumes the WordPress administrators of your *local* site are you.

## Reporting a vulnerability

Please **don't open a public issue** for security problems. Use GitHub's private vulnerability reporting instead: [report a vulnerability](https://github.com/scbudgetweb/LiveCanvas-Claude-Bridge/security/advisories/new), or go to the repository's **Security** tab › **Report a vulnerability**. Include steps to reproduce and the plugin, Claude Code and LiveCanvas versions. You should get a reply within a week.
