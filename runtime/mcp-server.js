#!/usr/bin/env node
/**
 * LC Claude Bridge — livecanvas MCP server (one per Claude Code session).
 *
 * Claude Code ──stdio──▶ this ──ws (role=mcp, site, token)──▶ hub.js ──▶ the site's LiveCanvas builder tab
 *
 * Started from the site's .mcp.json (written by WordPress → Tools › Claude Code → Connect) as:
 *   node …/runtime/current/mcp-server.js --site <siteId>
 *
 * stdout belongs to the MCP protocol: log to stderr only.
 */
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { WebSocket } from "ws";
import { z } from "zod";
import { basename } from "node:path";
import { loadConfig, readToken, log as baseLog } from "./lib.js";

const argSite = process.argv.indexOf("--site");
const SITE = argSite > -1 ? process.argv[argSite + 1] : basename(process.cwd());
const TIMEOUT_MS = 20000;
const log = (...a) => baseLog(`[livecanvas:${SITE}]`, ...a);

// ───────────────────────── Hub connection ─────────────────────────

let hub = null;
let hubError = null;
let retry = 1000;
let seq = 0;
const pending = new Map();

function connectHub() {
	const token = readToken();
	const { port } = loadConfig();
	if (!token) {
		hubError = "This Mac isn't set up yet. In WordPress open Tools › Claude Code and click Connect.";
		return setTimeout(connectHub, 5000);
	}
	const socket = new WebSocket(`ws://127.0.0.1:${port}/?role=mcp&site=${encodeURIComponent(SITE)}`, { headers: { "x-lccb-token": token } });
	let done = false;
	const fail = () => {
		if (done) return;
		done = true;
		if (hub === socket) hub = null;
		for (const [id, p] of pending) { clearTimeout(p.timer); p.reject(new Error("Lost connection to the LC Claude Bridge hub")); pending.delete(id); }
		setTimeout(connectHub, retry);
		retry = Math.min(retry * 2, 15000);
	};
	socket.on("open", () => { hub = socket; hubError = null; retry = 1000; log("connected to hub"); });
	socket.on("message", (data) => {
		let msg;
		try { msg = JSON.parse(data.toString()); } catch (_) { return; }
		const p = msg.id && pending.get(msg.id);
		if (!p) return;
		pending.delete(msg.id);
		clearTimeout(p.timer);
		msg.ok ? p.resolve(msg.result) : p.reject(new Error(msg.error || "Unknown error from editor"));
	});
	socket.on("unexpected-response", (req, res) => {
		hubError = {
			401: "The hub rejected this session's token. Click Connect again in WordPress → Tools › Claude Code.",
			404: `Site "${SITE}" isn't connected. In its WordPress admin open Tools › Claude Code and click Connect.`,
		}[res.statusCode] || `The hub rejected this session (HTTP ${res.statusCode}).`;
		log(hubError);
		req.destroy(); // with this listener attached, ws never emits "close" on its own
		fail();
	});
	socket.on("error", (err) => {
		if (!hubError || hub === null) hubError = `The LC Claude Bridge hub isn't running (${err.code || err.message}). Open Tools › Claude Code in WordPress and click Connect.`;
		fail();
	});
	socket.on("close", fail);
}

function call(cmd, args = {}) {
	if (!hub || hub.readyState !== 1) return Promise.reject(new Error(hubError || "Connecting to the LC Claude Bridge hub. Try again in a moment."));
	const id = `m${++seq}`;
	return new Promise((resolve, reject) => {
		const timer = setTimeout(() => { pending.delete(id); reject(new Error(`No answer to '${cmd}' within ${TIMEOUT_MS / 1000}s`)); }, TIMEOUT_MS);
		pending.set(id, { resolve, reject, timer });
		hub.send(JSON.stringify({ id, cmd, args }));
	});
}

connectHub();

// ───────────────────────── MCP side ─────────────────────────

const server = new McpServer(
	{ name: "livecanvas", version: "0.3.0" },
	{
		instructions:
			"Tools to read and edit the LiveCanvas page builder that is open in the user's browser on a local WordPress site " +
			"(typically picostrap5 / Bootstrap 5, with LiveCanvas markup such as editable=\"inline|rich\" attributes). " +
			"HTML is per-page and is scoped to the element currently open in the LiveCanvas HTML editor (falls back to main#lc-main). " +
			"Global CSS and Global JS are site-wide. Start with lc_get_context. Prefer the lc_edit_* tools (exact string replacement) " +
			"over the lc_write_* tools. Read before editing, because whitespace must match exactly. Reuse existing classes and custom properties " +
			"from Global CSS (follow the site's own class naming) before inventing new ones. All edits apply live but are NOT saved; " +
			"call lc_save only when the user asks.",
	}
);

const json = (v) => ({ content: [{ type: "text", text: typeof v === "string" ? v : JSON.stringify(v, null, 2) }] });

function tool(name, description, inputSchema, cmd, format = json) {
	server.registerTool(name, { description, inputSchema }, async (args) => format(await call(cmd, args)));
}

const selector = z.string().optional().describe(
	"CSS selector in the LiveCanvas doc (as returned by lc_get_context). Omit to target the element currently open in the HTML editor, or main#lc-main."
);
const editArgs = {
	old_string: z.string().describe("Exact text to replace. It must match the current content, including whitespace and tabs."),
	new_string: z.string().describe("Replacement text"),
	replace_all: z.boolean().optional().describe("Replace every occurrence instead of requiring a unique match"),
};

tool(
	"lc_get_context",
	"Get the open LiveCanvas page: post info, whether the code editor is open, which tab is active, the currently selected element's selector, and an outline of the page's top-level sections (with selectors).",
	{},
	"context"
);

tool(
	"lc_read_html",
	"Read HTML from the page. For main#lc-main this returns innerHTML; for any other selector, outerHTML. Formatted the same way as the LiveCanvas editor, so lc_edit_html's old_string can be copied from it.",
	{ selector },
	"read_html",
	(r) => json(`<!-- selector: ${r.selector} (${r.mode}) -->\n${r.html}`)
);

tool(
	"lc_select",
	"Open the LiveCanvas HTML editor on an element so the user can see what is being worked on.",
	{ selector: z.string().describe("CSS selector in the LiveCanvas doc") },
	"select"
);

tool(
	"lc_edit_html",
	"Edit page HTML by exact string replacement within the target element (see lc_read_html). Opens the HTML editor on that element and applies the change live through LiveCanvas, which updates the preview and adds an undo step. Not saved.",
	{ selector, ...editArgs },
	"edit_html"
);

tool(
	"lc_write_html",
	"Replace the target element's entire HTML (outerHTML, or innerHTML for main#lc-main). Prefer lc_edit_html for targeted changes.",
	{ selector, html: z.string().describe("Full replacement HTML") },
	"write_html"
);

tool("lc_read_css", "Read the site-wide Global CSS (WordPress Additional CSS).", {}, "read_css", (r) => json(r.css));
tool("lc_edit_css", "Edit Global CSS by exact string replacement. Applies live to the preview. Not saved.", editArgs, "edit_css");
tool("lc_write_css", "Replace the entire Global CSS. Prefer lc_edit_css.", { css: z.string() }, "write_css");

tool("lc_read_js", "Read the site-wide Global JS (LiveCanvas global script, loaded as type=module).", {}, "read_js", (r) => json(r.js));
tool("lc_edit_js", "Edit Global JS by exact string replacement. Not saved; JS takes effect in the preview after a save and reload.", editArgs, "edit_js");
tool("lc_write_js", "Replace the entire Global JS. Prefer lc_edit_js.", { js: z.string() }, "write_js");

tool(
	"lc_save",
	"Save the page in LiveCanvas (HTML, Global CSS and Global JS). Only call this when the user explicitly asks to save.",
	{},
	"save"
);

await server.connect(new StdioServerTransport());
log("MCP server ready");

const shutdown = () => process.exit(0);
process.stdin.on("close", shutdown);
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
