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
		const limit = cmd === "screenshot" || cmd === "template_shot" ? 65000 : ["css_recompile", "responsive_check", "compare"].includes(cmd) ? 125000 : TIMEOUT_MS;
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

// ───────────────────────── Checking the work: responsive check + design-system lint ─────────────────────────

server.registerTool("lc_responsive_check", {
	description: "Check the page open in the builder at several widths (default 390, 768, 1200, 1440): resizes the preview, runs layout detectors (sideways scroll and what causes it, elements wider than the viewport, overlapping text, tap targets under 44×44, text under 12px, images overflowing their container or without width/height, content cut off by overflow: hidden), and returns the issues grouped across widths plus ONE composite image with every width side by side and problems outlined (red = high, amber = medium, blue = low). Run it after visual or layout changes, before telling the user you're done, and fix the high ones. Use `selector` (rendered preview selector) to check one section closely.",
	inputSchema: {
		widths: z.array(z.number().int().min(320).max(2560)).max(6).optional().describe("Widths in CSS px. Default [390, 768, 1200, 1440]."),
		selector: z.string().optional().describe("Check just this element of the rendered preview (e.g. '#scHero', 'main#lc-main > section:nth-of-type(3)'). Omit for the whole page."),
		images: z.enum(["composite", "each", "none"]).optional().describe("composite (default): one side-by-side image; each: one image per width (sharper, more tokens); none: issues only"),
		max_height: z.number().int().min(600).max(12000).optional().describe("Whole-page captures stop this far down (CSS px, default 2400). Detectors always check the whole page."),
	},
}, async (args) => {
	const r = await call("responsive_check", args);
	const content = (r.images || []).map((img) => ({ type: "image", data: img.data, mimeType: img.media_type }));
	const { images, ...rest } = r;
	rest.images = (images || []).map((img) => `${img.label}: ${img.width}×${img.height}px`);
	content.push({ type: "text", text: JSON.stringify(rest, null, 2) });
	return { content };
});

server.registerTool("lc_lint", {
	description: "Lint against the site's own design system, with a suggested fix for each finding: inline styles (and the Bootstrap utility that does the same), hard-coded colours that aren't the site's tokens/custom properties (with the matching var()), spacing off Bootstrap's scale, heading order and h1 count, images without alt/width/height, empty or icon-only links, duplicate ids, and classes that aren't defined anywhere or used elsewhere (typos). scope: 'selection' = the element open in the builder's HTML editor (or `selector`); 'page' = the page open in the builder including unsaved changes, or a saved page/partial/template by `id`; 'site' = every LiveCanvas page, partial and template plus Global CSS (saved versions). Read-only.",
	inputSchema: {
		scope: z.enum(["selection", "page", "site"]).optional().describe("Default 'page'"),
		selector: z.string().optional().describe("For scope 'selection': a builder selector (as from lc_get_context). Omit for the current selection."),
		id: z.number().int().optional().describe("For scope 'page': lint this saved page/partial/template instead of the one open in the builder"),
	},
}, async ({ scope = "page", selector, id }) => {
	if (scope === "site" || (scope === "page" && id)) return json(await wpRun("lint", { scope, id }));
	let live;
	try {
		const ctx = await call("context", {});
		const html = await call("read_html", { selector: scope === "selection" ? selector : "main#lc-main" });
		const css = await call("read_css", {});
		live = { html: html.html, css: css.css, label: scope === "selection" ? `selection ${html.selector} on "${ctx.post.title}"` : `open page "${ctx.post.title}" (#${ctx.post.id}, unsaved changes included)` };
	} catch (err) {
		throw new Error(`${err.message} To lint without the builder, use scope 'page' with an id, or scope 'site'.`);
	}
	return json(await wpRun("lint", { scope, ...live }));
});

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

serverTool("lc_audit_list", "List recent site-level changes applied through lc_apply_change or lc_media_import (newest first), each with an audit id usable with lc_audit_restore.", { limit: z.number().int().optional(), target_type: z.enum(["page", "partial", "template", "tokens", "media", "section", "template_assets"]).optional(), target_id: z.number().int().optional() }, "audit_list");
serverTool("lc_audit_restore", "Plan undoing an audited change: puts the item back exactly as it was before that change (or moves it to the bin if that change created it)." + PREVIEW_NOTE, { id: z.number().int() }, "audit_restore", previewText);

server.registerTool("lc_apply_change", {
	description: "Apply a change previewed by lc_page_create / lc_page_update / lc_partial_update / lc_template_upsert / lc_tokens_update / lc_audit_restore. It writes to the site IMMEDIATELY (unlike builder edits, there's no Save step). Refuses if the target changed since the preview. The previous version is kept in the audit log (undo with lc_audit_restore).",
	inputSchema: { preview_id: z.string() },
}, async (args) => {
	const r = await wpRun("apply_change", args);
	// A token undo puts the old CSS bundle back: point the open preview at it (best effort).
	if (r && r.refresh_preview_css) {
		try { await call("refresh_css", {}); r.preview_css_refreshed = true; } catch (_) { r.preview_css_refreshed = false; }
	}
	return json(r);
});

// ───────────────────────── Start from an HTML template ─────────────────────────

const tplPath = z.string().describe("The template's folder or .zip on this Mac (e.g. ~/Downloads/porto.zip or ~/projects/porto/HTML). Never modified.");

serverTool("lc_html_template_scan", "Scan a bought/downloaded HTML template (folder or .zip): its CSS framework (Bootstrap and version, Tailwind, Bulma, Foundation, UIkit or hand-written, with evidence) and the recommended strategy, pages (grouped when there are hundreds) with section outlines, shared header/footer, CSS/JS and recognised libraries, fonts, images, likely design tokens to map onto Picostrap, and warnings (jQuery plugins, Bootstrap 4). Read-only.", {
	path: tplPath,
	filter: z.string().optional().describe("Only detail pages whose path contains this (e.g. 'demo-beauty-salon'); use the groups from a first scan"),
	limit: z.number().int().optional().describe("How many pages to detail (default 40)"),
}, "html_template_scan");

serverTool("lc_html_template_read", "Read a template page. Without `section`: its header, footer and numbered content sections (with selectors). With `section` (index, 'header' or 'footer'): that part as LiveCanvas-ready HTML: scripts and handlers stripped, lazy images un-lazied, links between template pages set to #, images pointing at the media library once imported (else at a local preview copy), Bootstrap 4 → 5 renamed. strategy 'convert' maps Tailwind/Bulma/Foundation classes to Bootstrap 5 where it's mechanical and lists what's left for you; 'scoped' adds the .tpl-<slug> wrapper class. Read-only.", {
	path: tplPath,
	page: z.string().optional().describe("Page path relative to the template's HTML folder (default index.html)"),
	section: z.union([z.number().int(), z.enum(["header", "footer"])]).optional(),
	strategy: z.enum(["auto", "bootstrap", "convert", "scoped"]).optional().describe("auto (default): convert non-Bootstrap templates, keep Bootstrap ones as they are"),
}, "html_template_read");

serverTool("lc_html_template_assets", "Plan importing what the given template pages need, so the site keeps working after this plugin is removed: the template's own CSS/JS (and the fonts/images its CSS references) copied into the child theme's template-assets/<slug>/, a managed enqueue block in the child theme's functions.php (CDN libraries enqueued by URL; Bootstrap and jQuery come from the theme/WordPress), and the pages' images into the media library (de-duplicated). scope: true prefixes the template's own CSS with .tpl-<slug> so it can't clash with Bootstrap." + PREVIEW_NOTE + " Undo removes exactly what was added.", {
	path: tplPath,
	pages: z.array(z.string()).describe("Template pages you're building from, e.g. [\"index.html\", \"about.html\"]"),
	include: z.array(z.enum(["css", "js", "fonts", "images"])).optional().describe("Default all four"),
	scope: z.boolean().optional(),
	exclude: z.array(z.string()).optional().describe("Skip files whose path contains any of these (e.g. 'revolution', 'demo')"),
}, "html_template_assets", previewText);

async function templateSelector(path, page, section) {
	if (section === undefined || section === null) return null;
	const outline = await wpRun("html_template_read", { path, page });
	const hit = section === "header" ? outline.header : section === "footer" ? outline.footer : (outline.sections || [])[section];
	if (!hit) throw new Error(`No section ${section} on ${outline.page} (it has ${(outline.sections || []).length}, numbered from 0).`);
	return hit.selector;
}

server.registerTool("lc_html_template_preview", {
	description: "See the ORIGINAL template: renders a template page in a hidden frame of the builder tab (needs the builder open) at a given width and returns a screenshot of one section (or the page). Use it before rebuilding a section, and lc_compare after.",
	inputSchema: {
		path: tplPath,
		page: z.string().optional(),
		section: z.union([z.number().int(), z.enum(["header", "footer"])]).optional(),
		width: z.number().int().min(320).max(2560).optional().describe("Default 1200; try 390 for mobile"),
	},
}, async ({ path, page, section, width }) => {
	const loc = await wpRun("html_template_locate", { path, page });
	const selector = await templateSelector(path, page, section);
	return imageResult(await call("template_shot", { url: loc.url, selector, width: width || 1200 }));
});

server.registerTool("lc_compare", {
	description: "Check a rebuilt section against the original template section at the same width(s) (needs the builder open): returns, per width, one image (original | rebuilt | difference in red) and a pixel-difference score. Aim for 'close' (≤12%) at 1200 and 390 unless content was deliberately changed; iterate with lc_edit_html / lc_edit_css and compare again.",
	inputSchema: {
		path: tplPath,
		page: z.string().optional(),
		section: z.union([z.number().int(), z.enum(["header", "footer"])]).describe("The template section (as numbered by lc_html_template_read)"),
		preview_selector: z.string().describe("The rebuilt section in the rendered builder preview, e.g. '#about' or 'main#lc-main > section:nth-of-type(3)'"),
		widths: z.array(z.number().int().min(320).max(2560)).max(3).optional().describe("Default [1200, 390]"),
	},
}, async ({ path, page, section, preview_selector, widths }) => {
	const loc = await wpRun("html_template_locate", { path, page });
	const template_selector = await templateSelector(path, page, section);
	const r = await call("compare", { url: loc.url, template_selector, preview_selector, widths });
	const content = (r.images || []).map((img) => ({ type: "image", data: img.data, mimeType: img.media_type }));
	content.push({ type: "text", text: JSON.stringify({ widths: r.widths, note: r.note }, null, 2) });
	return { content };
});

// ───────────────────────── Section library (LiveCanvas lc_section) ─────────────────────────

const sectionRef = { id: z.number().int().optional(), slug: z.string().optional() };
serverTool("lc_sections_list", "List the reusable sections in LiveCanvas's section library (lc_section posts), with how many pages embed each and the HTML to embed one.", {}, "sections_list");
serverTool("lc_section_read", "Read a library section's HTML.", sectionRef, "section_read");
serverTool("lc_section_usage", "Where a library section is used: pages/partials that embed it by shortcode, and pages that still have an inline copy of its HTML (exact, or near-identical).", sectionRef, "section_usage");
serverTool("lc_section_create", "Plan adding a reusable section to the library (published, so the shortcode renders). Take the HTML from lc_read_html (a section of the open page) or lc_html_template_read." + PREVIEW_NOTE, {
	title: z.string(),
	html: z.string().describe("The section's HTML (one top-level element, e.g. <section>…</section>)"),
	slug: z.string().optional(),
}, "section_create", previewText);
serverTool("lc_section_update", "Plan a change to a library section: every page that embeds it changes too." + PREVIEW_NOTE, { ...sectionRef, title: z.string().optional(), ...contentEdit }, "section_update", previewText);
server.registerTool("lc_section_replace_inline", {
	description: "Plan swapping exact inline copies of a library section on pages/partials for its shortcode embed (LiveCanvas's live-shortcode wrapper), so it's edited in one place. Returns one preview per page; apply each with lc_apply_change. Pages open in the builder are skipped (do those with lc_edit_html).",
	inputSchema: { section: z.union([z.number().int(), z.string()]).describe("Section id or slug"), pages: z.array(z.number().int()).optional().describe("Only these page/partial ids") },
}, async (args) => {
	const r = await wpRun("section_replace_inline", args);
	const parts = [];
	for (const p of r.previews || []) parts.push(`PREVIEW (nothing written yet): ${p.summary}`, "```diff", p.content_diff, "```", `preview_id: ${p.preview_id}`, "");
	for (const s of r.skipped || []) parts.push("⚠ " + s);
	parts.push(r.next);
	return { content: [{ type: "text", text: parts.join("\n") }] };
});

// ───────────────────────── Design tokens (Picostrap) + CSS recompile ─────────────────────────

serverTool("lc_tokens_get", "Read the theme's design tokens: Picostrap SCSS variables that are set (colours, fonts, sizes…), the web-font <link> code, and the compiled CSS bundle's size/version.", {}, "tokens_get");
serverTool("lc_tokens_update", "Plan design-token changes (Picostrap SCSS variables, e.g. {\"primary\": \"#1f2937\", \"headings-font-family\": \"'Playfair Display', serif\"}; unset to go back to the default; fonts_header_code for the Google Fonts <link> tags). After lc_apply_change, run lc_css_recompile." + PREVIEW_NOTE, {
	set: z.record(z.union([z.string(), z.number(), z.boolean()])).optional(),
	unset: z.array(z.string()).optional(),
	fonts_header_code: z.string().optional(),
}, "tokens_update", previewText);

server.registerTool("lc_css_recompile", {
	description: "Rebuild the theme's CSS bundle from the current design tokens and SCSS files, using Picostrap's own compiler in a hidden frame of the builder tab (needs the builder open; takes ~10-60s). Then refreshes the preview's CSS. Use after applying token changes or editing the child theme's SCSS.",
	inputSchema: {},
}, async () => {
	const before = await wpRun("tokens_get");
	const origin = ((loadConfig().sites || {})[SITE] || {}).origin;
	if (!origin) throw new Error("Site origin unknown; reconnect the site.");
	const r = await call("css_recompile", { url: origin + "/?compile_sass=1&sass_nocache=1" });
	const after = await wpRun("tokens_get");
	const b = before.css_bundle, a = after.css_bundle;
	const changed = a.version !== b.version || a.modified !== b.modified || a.bytes !== b.bytes;
	if (!changed) throw new Error(`The CSS bundle wasn't rebuilt${r.feedback ? ": " + r.feedback : ""}. Check the SCSS for errors (Picostrap's compiler output above) and try again.`);
	return json({ compiled: true, seconds: r.seconds, bytes_before: b.bytes, bytes_after: a.bytes, version: a.version, preview_refreshed: r.preview_refreshed, next: "Take an lc_screenshot to check the result." });
});

// ───────────────────────── Media library ─────────────────────────

serverTool("lc_media_list", "List recent images in the media library (id, title, alt, size, URL), optionally searched.", { search: z.string().optional(), limit: z.number().int().optional() }, "media_list");
serverTool("lc_media_import", "Add an image to the WordPress media library from a URL or from a file path inside the site (images the user drops into CC Chat are saved to an inbox path given in their message). Returns the attachment id, URLs per size and ready-to-use <img> HTML. Audited: lc_audit_restore deletes it again.", {
	url: z.string().optional(),
	path: z.string().optional(),
	title: z.string().optional(),
	alt: z.string().optional().describe("Alt text (describe the image for screen readers)"),
	filename: z.string().optional(),
}, "media_import");

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
