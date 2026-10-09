#!/usr/bin/env node
/**
 * LC Claude Bridge — hub (one per Mac, run by launchd).
 *
 * A single loopback WebSocket server that routes everything by site:
 *
 *   role=editor    bridge.js in a LiveCanvas builder tab   (Origin must equal the site's origin)
 *   role=terminal  terminal.js — the CLAUDE tab's xterm     (Origin must equal the site's origin)
 *   role=chat      chat.js — the CHAT tab (Claude Code headless/streaming, see chat.js)
 *   role=mcp       a Claude Code session's livecanvas MCP server (no Origin: browsers can't pose as one)
 *
 * Every connection needs ?site=<id> (registered in config.json by WordPress → Tools › Claude Code)
 * and the shared token (?token= for browsers, x-lccb-token header for Node clients).
 * Tool calls from an MCP client only ever reach editor tabs of the same site.
 *
 * GET /health (x-lccb-token header) → JSON status, used by the WordPress settings page.
 */
import { createServer } from "node:http";
import { createRequire } from "node:module";
import { readFileSync } from "node:fs";
import { WebSocketServer } from "ws";
import { loadConfig, readToken, tokenMatches, log } from "./lib.js";
import { createChat } from "./chat.js";

const require = createRequire(import.meta.url);
const pty = require("node-pty");
const VERSION = JSON.parse(readFileSync(new URL("./package.json", import.meta.url))).version;
const TIMEOUT_MS = 15000;
const SLOW_CMDS = new Set(["screenshot", "css_recompile", "responsive_check", "template_shot", "compare", "measure_images", "axe_run"]); // whole-page captures and SCSS compiles take a while
const BUFFER_LIMIT = 256 * 1024;

const boot = loadConfig();
if (!readToken()) {
	log("No token file. Open Tools › Claude Code in WordPress and click Connect.");
	process.exit(1);
}

// ───────────────────────── State (all keyed by site id) ─────────────────────────

const editors = new Map(); // site → { socket, info, lastSelection }
const terminals = new Map(); // site → { pty, buffer, clients:Set, state, code, cols, rows }
const chat = createChat({
	loadConfig,
	log,
	notifyEditor: (site, msg) => { const e = editors.get(site); if (e) send(e.socket, msg); },
});
const pending = new Map(); // hubId → { mcp, origId, site, cmd, timer }
let seq = 0;

const send = (socket, msg) => { if (socket.readyState === 1) socket.send(JSON.stringify(msg)); };

// ───────────────────────── Editor tabs ─────────────────────────

function acceptEditor(socket, site) {
	const prev = editors.get(site);
	if (prev && prev.socket !== socket) {
		send(prev.socket, { event: "superseded" });
		try { prev.socket.close(); } catch (_) {}
	}
	const entry = { socket, info: null, lastSelection: null };
	editors.set(site, entry);
	log(`[${site}] editor tab connected`);

	socket.on("message", (data) => {
		let msg;
		try { msg = JSON.parse(data.toString()); } catch (_) { return; }
		if (msg.event === "hello") { entry.info = msg; return; }
		if (msg.event === "selection") { entry.lastSelection = msg.selector; return; }
		if (msg.event === "checkpoint_restored") { chat.noteRestore(site, msg); return; }
		const p = msg.id && pending.get(msg.id);
		if (!p || p.site !== site) return;
		pending.delete(msg.id);
		clearTimeout(p.timer);
		let result = msg.result;
		if (msg.ok && p.cmd === "context") result = { ...result, bridge: { lastSelectionEvent: entry.lastSelection, connectedTab: entry.info } };
		send(p.mcp, msg.ok ? { id: p.origId, ok: true, result } : { id: p.origId, ok: false, error: msg.error || "Unknown error from editor" });
	});

	socket.on("close", () => {
		if (editors.get(site) === entry) editors.delete(site);
		for (const [id, p] of pending) {
			if (p.site === site && p.editor === socket) {
				clearTimeout(p.timer);
				send(p.mcp, { id: p.origId, ok: false, error: "Editor tab disconnected mid-request" });
				pending.delete(id);
			}
		}
		log(`[${site}] editor tab disconnected`);
	});
}

// ───────────────────────── MCP clients ─────────────────────────

function acceptMcp(socket, site) {
	log(`[${site}] MCP session connected`);
	socket.on("message", (data) => {
		let msg;
		try { msg = JSON.parse(data.toString()); } catch (_) { return; }
		if (!msg.id || !msg.cmd) return;
		const editor = editors.get(site);
		if (!editor || editor.socket.readyState !== 1) {
			return send(socket, {
				id: msg.id, ok: false,
				error: "No LiveCanvas editor connected for this site. Open a page in the LiveCanvas builder " +
					"(the 'Claude connected' pill should be green) and try again.",
			});
		}
		const hubId = `h${++seq}`;
		const limit = msg.cmd === "axe_run" ? 600000 : ["css_recompile", "responsive_check", "compare"].includes(msg.cmd) ? 120000 : SLOW_CMDS.has(msg.cmd) ? 60000 : TIMEOUT_MS;
		const timer = setTimeout(() => {
			pending.delete(hubId);
			send(socket, { id: msg.id, ok: false, error: `Editor did not answer '${msg.cmd}' within ${limit / 1000}s` });
		}, limit);
		pending.set(hubId, { mcp: socket, editor: editor.socket, origId: msg.id, site, cmd: msg.cmd, timer });
		send(editor.socket, { id: hubId, cmd: msg.cmd, args: msg.args || {} });
	});
	socket.on("close", () => {
		for (const [id, p] of pending) if (p.mcp === socket) { clearTimeout(p.timer); pending.delete(id); }
		log(`[${site}] MCP session disconnected`);
	});
}

// ───────────────────────── Terminals (Claude Code in a PTY) ─────────────────────────
// Protocol (JSON): client → {t:"i",d} {t:"resize",cols,rows} {t:"start"} {t:"restart"}
//                  hub    → {t:"replay",d} {t:"o",d} {t:"status",state,code?}

function terminalFor(site) {
	let s = terminals.get(site);
	if (!s) {
		s = { pty: null, buffer: "", clients: new Set(), state: "exited", code: undefined, cols: 120, rows: 32 };
		terminals.set(site, s);
	}
	return s;
}

function broadcast(s, msg) { for (const c of s.clients) send(c, msg); }

function append(s, chunk) {
	s.buffer += chunk;
	if (s.buffer.length > BUFFER_LIMIT) s.buffer = s.buffer.slice(-BUFFER_LIMIT);
}

function startClaude(site, s) {
	const config = loadConfig();
	const siteConf = config.sites[site];
	if (!siteConf || !config.claudePath) {
		broadcast(s, { t: "o", d: "\r\n[Site not connected. Open Tools › Claude Code in WordPress and click Connect.]\r\n" });
		return;
	}
	const env = {
		HOME: process.env.HOME,
		USER: process.env.USER,
		LOGNAME: process.env.LOGNAME || process.env.USER,
		SHELL: process.env.SHELL || "/bin/zsh",
		PATH: config.path,
		LANG: process.env.LANG || "en_US.UTF-8",
		TERM: "xterm-256color",
		COLORTERM: "truecolor",
	};
	const p = pty.spawn(config.claudePath, [], { name: "xterm-256color", cols: s.cols, rows: s.rows, cwd: siteConf.root, env });
	s.pty = p;
	s.state = "running";
	s.buffer = "";
	log(`[${site}] started claude (pid ${p.pid}) in ${siteConf.root}`);
	broadcast(s, { t: "replay", d: "" });
	broadcast(s, { t: "status", state: "running" });

	p.onData((d) => { append(s, d); broadcast(s, { t: "o", d }); });
	p.onExit(({ exitCode }) => {
		if (s.pty !== p) return; // replaced by a restart
		s.pty = null;
		s.state = "exited";
		s.code = exitCode;
		log(`[${site}] claude exited (${exitCode})`);
		const note = `\r\n\x1b[2m[Claude Code session ended (${exitCode}). Press Enter to start a new one.]\x1b[0m\r\n`;
		append(s, note);
		broadcast(s, { t: "o", d: note });
		broadcast(s, { t: "status", state: "exited", code: exitCode });
	});
}

function killClaude(s) {
	const p = s.pty;
	s.pty = null;
	if (p) try { p.kill(); } catch (_) {}
}

function acceptTerminal(socket, site) {
	const s = terminalFor(site);
	s.clients.add(socket);
	send(socket, { t: "replay", d: s.buffer });
	send(socket, { t: "status", state: s.state, code: s.code });

	socket.on("message", (raw) => {
		let msg;
		try { msg = JSON.parse(raw.toString()); } catch (_) { return; }
		switch (msg.t) {
			case "i":
				if (s.pty) s.pty.write(String(msg.d));
				else if (msg.d === "\r") startClaude(site, s);
				break;
			case "resize":
				s.cols = Math.max(20, Math.min(500, msg.cols | 0));
				s.rows = Math.max(5, Math.min(200, msg.rows | 0));
				if (s.pty) try { s.pty.resize(s.cols, s.rows); } catch (_) {}
				break;
			case "start":
				if (!s.pty) startClaude(site, s);
				break;
			case "restart":
				killClaude(s);
				startClaude(site, s);
				break;
		}
	});
	socket.on("close", () => s.clients.delete(socket));
}

// ───────────────────────── Server ─────────────────────────

function authorise(req) {
	const url = new URL(req.url, "http://localhost");
	const config = loadConfig();
	const token = readToken();
	const role = url.searchParams.get("role");
	const site = url.searchParams.get("site");
	const given = url.searchParams.get("token") || req.headers["x-lccb-token"];
	const origin = req.headers.origin;

	if (!tokenMatches(given, token)) return { code: 401, why: "bad token" };
	const siteConf = config.sites[site];
	if (!siteConf) return { code: 404, why: `unregistered site ${site}` };
	if (role === "editor" || role === "terminal" || role === "chat") {
		if (origin !== siteConf.origin) return { code: 403, why: `origin ${origin} ≠ ${siteConf.origin}` };
	} else if (role === "mcp") {
		if (origin) return { code: 403, why: "mcp role must not come from a browser" };
	} else {
		return { code: 400, why: `unknown role ${role}` };
	}
	return { role, site };
}

const http = createServer((req, res) => {
	const url = new URL(req.url, "http://localhost");
	if (url.pathname === "/health" && req.method === "GET") {
		if (!tokenMatches(req.headers["x-lccb-token"], readToken())) { res.writeHead(401).end(); return; }
		const config = loadConfig();
		res.writeHead(200, { "content-type": "application/json" }).end(JSON.stringify({
			ok: true,
			version: VERSION,
			pid: process.pid,
			sites: Object.keys(config.sites),
			editors: [...editors.keys()],
			// Which post each site's builder tab has open (WordPress uses it to know a page is being edited).
			open: Object.fromEntries([...editors].filter(([, e]) => e.socket.readyState === 1 && e.info && e.info.postId).map(([site, e]) => [site, Number(e.info.postId)])),
			terminals: [...terminals].filter(([, s]) => s.pty).map(([site]) => site),
			chats: chat.running(),
		}));
		return;
	}
	res.writeHead(404).end();
});

const wss = new WebSocketServer({
	server: http,
	verifyClient: ({ req }, done) => {
		const a = authorise(req);
		if (a.code) { log(`rejected: ${a.why}`); return done(false, a.code); }
		req.lccbAuth = a;
		done(true);
	},
});

wss.on("connection", (socket, req) => {
	const { role, site } = req.lccbAuth;
	socket.lccbSite = site;
	if (role === "editor") acceptEditor(socket, site);
	else if (role === "terminal") acceptTerminal(socket, site);
	else if (role === "chat") chat.accept(socket, site);
	else acceptMcp(socket, site);
});

// Disconnect (or a removed token) must take effect on live sessions too, not just new connections.
setInterval(() => {
	const config = loadConfig();
	const tokenOk = !!readToken();
	for (const [site, e] of editors) {
		if (!tokenOk || !config.sites[site]) { try { e.socket.close(); } catch (_) {} editors.delete(site); }
	}
	for (const [site, s] of terminals) {
		if (!tokenOk || !config.sites[site]) {
			killClaude(s);
			for (const c of s.clients) try { c.close(); } catch (_) {}
			terminals.delete(site);
			log(`[${site}] no longer registered: closed its sessions`);
		}
	}
	chat.sweep(tokenOk ? Object.keys(config.sites) : []);
	for (const c of wss.clients) {
		if (c.lccbSite && (!tokenOk || !config.sites[c.lccbSite])) try { c.close(); } catch (_) {}
	}
}, 5000);

http.on("error", (err) => { log(`server error: ${err.message}`); process.exit(1); });
http.listen(boot.port, "127.0.0.1", () => log(`hub ${VERSION} listening on 127.0.0.1:${boot.port} · sites: ${Object.keys(boot.sites).join(", ") || "(none)"}`));

const shutdown = () => {
	for (const s of terminals.values()) killClaude(s);
	chat.killAll();
	wss.close();
	http.close();
	process.exit(0);
};
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
