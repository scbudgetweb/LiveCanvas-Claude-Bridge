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
import { basename, dirname, join } from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
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
		const limit = cmd === "screenshot" ? 65000 : TIMEOUT_MS;
		const timer = setTimeout(() => { pending.delete(id); reject(new Error(`No answer to '${cmd}' within ${limit / 1000}s`)); }, limit);
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

// ───────────────────────── Server-side ops (no builder needed): runtime/wp-run.php via PHP CLI ─────────────────────────

const RUNNER = join(dirname(fileURLToPath(import.meta.url)), "wp-run.php");

function wpRun(command, args = {}, timeoutMs = 60000) {
	const config = loadConfig();
	const php = config.phpPath;
	if (!php) return Promise.reject(new Error("PHP CLI not configured. Click Re-check & repair in WordPress › Tools › Claude Code."));
	return new Promise((resolve, reject) => {
		const child = spawn(php, [RUNNER, SITE, command, JSON.stringify(args)], {
			cwd: (config.sites[SITE] || {}).root || process.cwd(),
			env: { HOME: process.env.HOME, USER: process.env.USER, PATH: config.path || process.env.PATH, LANG: "en_US.UTF-8" },
			stdio: ["ignore", "pipe", "pipe"],
		});
		let out = "", err = "";
		const timer = setTimeout(() => { child.kill(); reject(new Error(`WordPress command '${command}' timed out`)); }, timeoutMs);
		child.stdout.on("data", (d) => (out += d));
		child.stderr.on("data", (d) => (err += d));
		child.on("error", (e) => { clearTimeout(timer); reject(e); });
		child.on("close", (code) => {
			clearTimeout(timer);
			const i = out.lastIndexOf("__LCCB_RESULT__");
			if (i < 0) return reject(new Error(`WordPress command '${command}' failed (exit ${code}): ${(err || out).trim().slice(-400)}`));
			let r;
			try { r = JSON.parse(out.slice(i + 15).trim()); } catch (e) { return reject(new Error("Unreadable result from WordPress")); }
			r.ok ? resolve(r.result) : reject(new Error(r.error || "WordPress command failed"));
		});
	});
}

function serverTool(name, description, inputSchema, command, format = json) {
	server.registerTool(name, { description, inputSchema }, async (args) => format(await wpRun(command, args)));
}

// Screenshots come back from the bridge as { media_type, data }; MCP returns them as image content Claude can see.
const imageResult = (r) => ({
	content: [
		{ type: "image", data: r.data, mimeType: r.media_type },
		{ type: "text", text: `Screenshot: ${r.width}×${r.height}px${r.viewport ? ` (preview width ${r.viewport}px)` : ""}${r.note ? ". " + r.note : ""}` },
	],
});

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

serverTool(
	"lc_site_context",
	"Everything about this WordPress site in one call (works without the builder open): WordPress/PHP/LiveCanvas/theme versions, Picostrap/WindPress/WooCommerce/ACF, design tokens (Picostrap SCSS variables, fonts, CSS bundle), class-name conventions and custom properties used on the site, all LiveCanvas pages, header/footer/global-JS partials, dynamic templates and menus. Use it before building something new so you reuse the site's own system.",
	{},
	"context"
);

tool(
	"lc_screenshot",
	"Screenshot the LiveCanvas preview so you can SEE the result of your changes (needs the builder open). Use it after visual edits, and check mobile with width 412 (tablet 768, desktop 1200 or 1440). area: 'visible' = what's in the viewport now, 'page' = the whole page top to bottom, 'element' = just the element matching `selector` (rendered preview selector, e.g. '#scHero' or '.sc-btn-pair'). The preview width is restored afterwards.",
	{
		area: z.enum(["visible", "page", "element"]).optional().describe("Default 'visible'"),
		width: z.number().int().min(320).max(2560).optional().describe("Preview width in CSS px for this capture (e.g. 412 mobile). Omit to keep the current width."),
		selector: z.string().optional().describe("CSS selector in the rendered preview (required for area 'element'; for 'visible' it's scrolled into view first)"),
	},
	"screenshot",
	imageResult
);

tool(
	"lc_inspect",
	"Inspect the RENDERED preview (needs the builder open), including shortcode/plugin output that lc_read_html can't see (e.g. Forminator forms): the element's outer HTML, size/position, key computed styles, children, and the CSS rules that currently match it (selector + stylesheet), so you can write overrides that actually win. Selectors are for the rendered preview (classes/ids work best).",
	{
		selector: z.string().describe("CSS selector in the rendered preview, e.g. '.forminator-ui .forminator-button'"),
		depth: z.number().int().min(0).max(3).optional().describe("How many levels of children to summarise (default 1)"),
		all: z.boolean().optional().describe("Report every match (up to 10) instead of the first"),
	},
	"inspect"
);

// ───────────────────────── Site building: pages, header/footer, templates (preview → apply, audited) ─────────────────────────

const previewText = (r) => {
	if (!r || !r.preview_id) return json(r);
	const parts = [`PREVIEW (nothing written yet): ${r.summary}`];
	if (r.changes && r.changes.length) parts.push("", ...r.changes.map((c) => "• " + c));
	if (r.content_diff) parts.push("", "```diff", r.content_diff, "```");
	if (r.warnings && r.warnings.length) parts.push("", ...r.warnings.map((w) => "⚠ " + w));
	parts.push("", `preview_id: ${r.preview_id}`, r.next);
	return { content: [{ type: "text", text: parts.join("\n") }] };
};

const contentEdit = {
	html: z.string().optional().describe("Full new content (the HTML inside main#lc-main). Prefer old_string/new_string for targeted edits."),
	old_string: z.string().optional().describe("Exact text to replace in the saved content (read it first)"),
	new_string: z.string().optional(),
	replace_all: z.boolean().optional(),
};
const PREVIEW_NOTE = " Writes nothing: returns a preview + preview_id; apply it with lc_apply_change.";

serverTool("lc_pages_list", "List the site's pages (LiveCanvas or not) with id, title, slug, status, URL, editor URL and whether each is open in the builder now.", { search: z.string().optional(), status: z.string().optional().describe("e.g. publish, draft") }, "pages_list");
serverTool("lc_page_read", "Read a page's SAVED content (the HTML inside main#lc-main) plus title/slug/status/template. For the page open in the builder use lc_read_html (it may have unsaved changes).", { id: z.number().int() }, "page_read");
serverTool("lc_page_create", "Plan a new LiveCanvas page (draft by default, LiveCanvas full-width template)." + PREVIEW_NOTE, {
	title: z.string(),
	slug: z.string().optional(),
	html: z.string().optional().describe("Initial content: sections of HTML as LiveCanvas stores them (inner HTML of main#lc-main)"),
	status: z.enum(["draft", "publish", "private", "pending"]).optional(),
	parent: z.number().int().optional(),
	menu_order: z.number().int().optional(),
}, "page_create", previewText);
serverTool("lc_page_update", "Plan changes to a saved page that is NOT open in the builder: title, slug, status (\"trash\" moves it to the bin), parent, order, and/or content." + PREVIEW_NOTE, {
	id: z.number().int(),
	title: z.string().optional(),
	slug: z.string().optional(),
	status: z.enum(["draft", "publish", "private", "pending", "trash"]).optional(),
	parent: z.number().int().optional(),
	menu_order: z.number().int().optional(),
	...contentEdit,
}, "page_update", previewText);

serverTool("lc_partial_read", "Read the site-wide header, footer or saved Global JS (LiveCanvas partials).", { type: z.enum(["header", "footer", "global_js"]).optional(), id: z.number().int().optional() }, "partial_read");
serverTool("lc_partial_update", "Plan a change to the site-wide header, footer or saved Global JS (creates the partial if it doesn't exist and html is given)." + PREVIEW_NOTE, {
	type: z.enum(["header", "footer", "global_js"]).optional(),
	id: z.number().int().optional(),
	title: z.string().optional(),
	...contentEdit,
}, "partial_update", previewText);

serverTool("lc_templates_list", "List LiveCanvas dynamic templates (single post, archive, search, WooCommerce pages…) with their display conditions, plus the condition keys LiveCanvas understands.", {}, "templates_list");
serverTool("lc_template_read", "Read a dynamic template's content and conditions.", { id: z.number().int() }, "template_read");
serverTool("lc_template_upsert", "Plan creating (no id: title + conditions required) or updating a LiveCanvas dynamic template: content, title, display conditions (is_* keys, replaces the set), menu_order (lower wins), status." + PREVIEW_NOTE, {
	id: z.number().int().optional(),
	title: z.string().optional(),
	conditions: z.array(z.string()).optional().describe("e.g. [\"is_single_post\"] or [\"is_archive_for_post_type_post\", \"is_blog_posts_index\"]"),
	menu_order: z.number().int().optional(),
	status: z.enum(["publish", "draft"]).optional(),
	...contentEdit,
}, "template_upsert", previewText);

serverTool("lc_audit_list", "List recent site-level changes applied through lc_apply_change (newest first), each with an audit id usable with lc_audit_restore.", { limit: z.number().int().optional(), target_type: z.enum(["page", "partial", "template"]).optional(), target_id: z.number().int().optional() }, "audit_list");
serverTool("lc_audit_restore", "Plan undoing an audited change: puts the item back exactly as it was before that change (or moves it to the bin if that change created it)." + PREVIEW_NOTE, { id: z.number().int() }, "audit_restore", previewText);

serverTool("lc_apply_change", "Apply a change previewed by lc_page_create / lc_page_update / lc_partial_update / lc_template_upsert / lc_audit_restore. Refuses if the target changed since the preview. The previous version is kept in the audit log (undo with lc_audit_restore).", { preview_id: z.string() }, "apply_change");

server.registerTool("lc_open_page", {
	description: "Open a page, partial or dynamic template in the LiveCanvas builder (the user's builder tab navigates there). Refuses if the builder has unsaved changes unless discard is true; ask the user before discarding.",
	inputSchema: { id: z.number().int(), discard: z.boolean().optional() },
}, async ({ id, discard }) => {
	const target = await wpRun("editor_url", { id });
	return json(await call("open_page", { url: target.editor_url, title: target.title, discard: !!discard }));
});

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
