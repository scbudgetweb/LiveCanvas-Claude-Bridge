# Third-party notices

LiveCanvas Claude Bridge is licensed under GPL-2.0-or-later. The release zip bundles the libraries below, each under its own licence. The full licence text for each is in its folder: `runtime/node_modules/<package>/` for runtime libraries, and the original package for the browser libraries copied into `assets/vendor/`.

## Browser (assets/vendor/)

| Library | Version | Licence | Source |
|---|---|---|---|
| @xterm/xterm | 6.0.0 | MIT | https://github.com/xtermjs/xterm.js |
| @xterm/addon-fit | 0.11.0 | MIT | https://github.com/xtermjs/xterm.js |
| @xterm/addon-web-links | 0.12.0 | MIT | https://github.com/xtermjs/xterm.js |
| marked | 18.1.0 | MIT | https://github.com/markedjs/marked |
| DOMPurify | 3.4.16 | MPL-2.0 OR Apache-2.0 (used under Apache-2.0) | https://github.com/cure53/DOMPurify |
| modern-screenshot | 4.7.0 | MIT | https://github.com/qq15725/modern-screenshot |

## Runtime (runtime/node_modules/)

| Library | Version | Licence | Source |
|---|---|---|---|
| @modelcontextprotocol/sdk | 1.32.1 | MIT | https://github.com/modelcontextprotocol/typescript-sdk |
| node-pty | 1.1.0 | MIT | https://github.com/microsoft/node-pty |
| ws | 8.22.0 | MIT | https://github.com/websockets/ws |
| zod | 3.25.76 | MIT | https://github.com/colinhacks/zod |

These pull in further dependencies of their own, all under permissive licences (MIT, ISC, BSD-2-Clause, BSD-3-Clause). Each one's licence file is in its folder under `runtime/node_modules/`.

## Not bundled

Claude Code isn't included. The plugin uses whatever copy of Claude Code you've installed yourself, under Anthropic's terms. LiveCanvas isn't included either.
