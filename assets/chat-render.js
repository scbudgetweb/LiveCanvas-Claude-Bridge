/**
 * LC Claude Bridge — CC Chat rendering helpers: Markdown, diffs, tool presentation, per-reply stats.
 * Exposes window.lccbChatRender. No network, no state: chat.js owns those.
 */
(function () {
	"use strict";

	// ───────────────────────── DOM ─────────────────────────

	function h(tag, attrs, ...children) {
		const el = document.createElement(tag);
		for (const [k, v] of Object.entries(attrs || {})) {
			if (k === "class") el.className = v;
			else if (k === "text") el.textContent = v;
			else if (k === "html") el.innerHTML = v;
			else if (k.startsWith("on")) el.addEventListener(k.slice(2), v);
			else if (v !== undefined && v !== null && v !== false) el.setAttribute(k, v === true ? "" : v);
		}
		for (const c of children) if (c !== null && c !== undefined && c !== false) el.append(c);
		return el;
	}

	function copyButton(getText) {
		const btn = h("button", { type: "button", class: "lccb-chat-copy", title: "Copy" }, "Copy");
		btn.addEventListener("click", (e) => {
			e.preventDefault();
			e.stopPropagation();
			navigator.clipboard.writeText(getText()).then(() => {
				btn.textContent = "Copied";
				setTimeout(() => (btn.textContent = "Copy"), 1200);
			});
		});
		return btn;
	}

	// ───────────────────────── Markdown ─────────────────────────

	let purifyHooked = false;

	function markdown(el, text) {
		if (window.marked && window.DOMPurify) {
			if (!purifyHooked) {
				// Links from Claude open in a new tab, never inside the builder.
				window.DOMPurify.addHook("afterSanitizeAttributes", (node) => {
					if (node.tagName === "A" && node.getAttribute("href")) {
						node.setAttribute("target", "_blank");
						node.setAttribute("rel", "noopener noreferrer");
					}
				});
				purifyHooked = true;
			}
			el.innerHTML = window.DOMPurify.sanitize(window.marked.parse(text || "", { gfm: true, breaks: false }));
			el.querySelectorAll("pre").forEach((pre) => {
				if (pre.querySelector(".lccb-chat-copy")) return;
				const code = pre.querySelector("code") || pre;
				pre.classList.add("lccb-chat-code");
				pre.append(copyButton(() => code.textContent));
			});
		} else {
			el.textContent = text || "";
			el.classList.add("is-plain");
		}
	}

	// ───────────────────────── Line diff ─────────────────────────

	/** LCS line diff → [{t:" "|"-"|"+", s}] (falls back to all-removed/all-added for very large inputs). */
	function lineDiff(a, b) {
		const A = String(a || "").split("\n");
		const B = String(b || "").split("\n");
		const n = A.length, m = B.length;
		if (n * m > 400000) return [...A.map((s) => ({ t: "-", s })), ...B.map((s) => ({ t: "+", s }))];
		const w = m + 1;
		const L = new Uint32Array((n + 1) * w);
		for (let i = n - 1; i >= 0; i--) {
			for (let j = m - 1; j >= 0; j--) {
				L[i * w + j] = A[i] === B[j] ? L[(i + 1) * w + j + 1] + 1 : Math.max(L[(i + 1) * w + j], L[i * w + j + 1]);
			}
		}
		const out = [];
		let i = 0, j = 0;
		while (i < n && j < m) {
			if (A[i] === B[j]) { out.push({ t: " ", s: A[i] }); i++; j++; }
			else if (L[(i + 1) * w + j] >= L[i * w + j + 1]) out.push({ t: "-", s: A[i++] });
			else out.push({ t: "+", s: B[j++] });
		}
		while (i < n) out.push({ t: "-", s: A[i++] });
		while (j < m) out.push({ t: "+", s: B[j++] });
		return out;
	}

	function diffView(oldText, newText, { context = 2 } = {}) {
		const rows = lineDiff(oldText, newText);
		const keep = rows.map((r) => r.t !== " ");
		// Show `context` unchanged lines around each change; fold the rest.
		const show = rows.map((_, i) => {
			for (let k = Math.max(0, i - context); k <= Math.min(rows.length - 1, i + context); k++) if (keep[k]) return true;
			return false;
		});
		const added = rows.filter((r) => r.t === "+").length;
		const removed = rows.filter((r) => r.t === "-").length;
		const box = h("div", { class: "lccb-chat-diff" });
		let folded = 0;
		const flush = () => {
			if (folded) box.append(h("div", { class: "lccb-chat-diff-fold", text: `⋯ ${folded} unchanged line${folded > 1 ? "s" : ""}` }));
			folded = 0;
		};
		rows.forEach((r, i) => {
			if (!show[i]) { folded++; return; }
			flush();
			box.append(h("div", { class: "lccb-chat-diff-line " + (r.t === "+" ? "is-add" : r.t === "-" ? "is-del" : "is-ctx") },
				h("span", { class: "lccb-chat-diff-sign", text: r.t }),
				h("span", { class: "lccb-chat-diff-text", text: r.s })));
		});
		flush();
		return { el: box, added, removed };
	}

	function codeView(text, { maxLines = 40, lang = "" } = {}) {
		const lines = String(text || "").split("\n");
		const wrap = h("div", { class: "lccb-chat-codeview" });
		const pre = h("pre", { class: "lccb-chat-code", "data-lang": lang });
		const render = (all) => { pre.textContent = all ? text : lines.slice(0, maxLines).join("\n"); };
		render(false);
		pre.append(copyButton(() => String(text || "")));
		wrap.append(pre);
		if (lines.length > maxLines) {
			const more = h("button", { type: "button", class: "lccb-chat-more", text: `Show all ${lines.length} lines` });
			more.addEventListener("click", () => { render(true); pre.append(copyButton(() => String(text || ""))); more.remove(); });
			wrap.append(more);
		}
		return wrap;
	}

	// ───────────────────────── Tools ─────────────────────────

	function splitName(name) {
		const m = /^mcp__(.+?)__(.+)$/.exec(name || "");
		return m ? { server: m[1], tool: m[2] } : { server: null, tool: name || "" };
	}

	const LC_LABELS = {
		lc_get_context: "Read page context",
		lc_read_html: "Read HTML",
		lc_select: "Select element",
		lc_edit_html: "Edit HTML",
		lc_write_html: "Replace HTML",
		lc_read_css: "Read Global CSS",
		lc_edit_css: "Edit Global CSS",
		lc_write_css: "Replace Global CSS",
		lc_read_js: "Read Global JS",
		lc_edit_js: "Edit Global JS",
		lc_write_js: "Replace Global JS",
		lc_save: "Save page",
	};

	const id = (i) => (i.id ? "#" + i.id : "");
	const SITE_TOOLS = {
		lc_site_context: ["Read site context", () => ""],
		lc_screenshot: ["Screenshot", (i) => [i.area || "visible", i.width ? i.width + "px" : "", i.selector || ""].filter(Boolean).join(" · ")],
		lc_inspect: ["Inspect", (i) => i.selector],
		lc_responsive_check: ["Responsive check", (i) => [(i.widths || [390, 768, 1200, 1440]).join("/") + "px", i.selector || ""].filter(Boolean).join(" · ")],
		lc_html_templates: ["Template library", (i) => i.find || ""],
		lc_html_template_scan: ["Scan template", (i) => (i.path || "").split("/").pop() + (i.filter ? " · " + i.filter : "")],
		lc_html_template_read: ["Read template", (i) => [i.page || "index.html", i.section !== undefined ? "section " + i.section : "", i.strategy && i.strategy !== "auto" ? i.strategy : ""].filter(Boolean).join(" · ")],
		lc_html_template_assets: ["Preview template import", (i) => (i.pages || []).join(", "), "preview"],
		lc_html_template_preview: ["Template screenshot", (i) => [i.page || "index.html", i.section !== undefined ? "section " + i.section : "", (i.width || 1200) + "px"].filter(Boolean).join(" · ")],
		lc_compare: ["Compare with template", (i) => [i.preview_selector, (i.widths || [1200, 390]).join("/") + "px"].join(" · ")],
		lc_migrate_scan: ["Crawl old site", (i) => [i.url || "continue", i.max_pages ? "max " + i.max_pages : ""].filter(Boolean).join(" · ")],
		lc_migrate_site: ["Old site overview", (i) => i.host || ""],
		lc_migrate_page: ["Old page", (i) => i.url],
		lc_media_import_batch: ["Preview image import", (i) => (i.urls || []).length + " image(s)", "preview"],
		lc_redirect_map: ["Redirect map", (i) => i.format || "redirection-csv"],
		lc_image_audit: ["Image audit", (i) => [i.scope || "page", i.id ? "#" + i.id : ""].filter(Boolean).join(" · ")],
		lc_image_optimise: ["Preview image optimise", (i) => [(i.ids || i.urls || []).length + " image(s)", i.format || "webp", i.max_width ? "≤" + i.max_width + "px" : ""].filter(Boolean).join(" · "), "preview"],
		lc_image_crop: ["Preview crop", (i) => "#" + i.id + " · " + i.aspect, "preview"],
		lc_media_read: ["Look at image", (i) => (i.id ? "#" + i.id : (i.url || "").split("/").pop())],
		lc_media_update: ["Preview image details", (i) => (i.updates ? i.updates.length + " image(s)" : "#" + i.id), "preview"],
		lc_stock_search: ["Stock photos", (i) => i.query],
		lc_stock_import: ["Import stock photo", (i) => i.id, "apply"],
		lc_qa: [(i) => (i.launch ? "Launch check" : "Quality check"), (i) => [i.scope || "page", i.id ? "#" + i.id : "", (i.checks || []).join("/")].filter(Boolean).join(" · ")],
		lc_sections_list: ["List sections", () => ""],
		lc_section_read: ["Read section", (i) => i.slug || id(i)],
		lc_section_usage: ["Section usage", (i) => i.slug || id(i)],
		lc_section_create: ["Preview new section", (i) => i.title, "preview"],
		lc_section_update: ["Preview section change", (i) => i.slug || id(i), "preview"],
		lc_section_replace_inline: ["Preview section → shortcode", (i) => String(i.section), "preview"],
		lc_lint: ["Lint", (i) => [i.scope || "page", i.id ? "#" + i.id : i.selector || ""].filter(Boolean).join(" · ")],
		lc_pages_list: ["List pages", (i) => i.search || i.status || ""],
		lc_page_read: ["Read page", id],
		lc_page_create: ["Preview new page", (i) => i.title, "preview"],
		lc_page_update: ["Preview page change", id, "preview"],
		lc_partial_read: ["Read partial", (i) => i.type || id(i)],
		lc_partial_update: ["Preview partial change", (i) => i.type || id(i), "preview"],
		lc_templates_list: ["List templates", () => ""],
		lc_template_read: ["Read template", id],
		lc_template_upsert: ["Preview template", (i) => i.title || id(i), "preview"],
		lc_audit_list: ["Change history", () => ""],
		lc_audit_restore: ["Preview undo", id, "preview"],
		lc_apply_change: ["Apply change", (i) => i.preview_id, "apply"],
		lc_open_page: ["Open in builder", (i) => id(i) + (i.discard ? " · discard unsaved" : "")],
		lc_tokens_get: ["Read design tokens", () => ""],
		lc_tokens_update: ["Preview design tokens", (i) => Object.keys(i.set || {}).concat((i.unset || []).map((u) => "−" + u)).join(", "), "preview"],
		lc_css_recompile: ["Recompile theme CSS", () => "Picostrap"],
		lc_media_list: ["List images", (i) => i.search || ""],
		lc_media_import: ["Import image", (i) => i.url || (i.path || "").split("/").pop(), "apply"],
	};

	/**
	 * How to show a tool call: a short label + argument, and an optional detail body (diff, code, list).
	 * @return {{label: string, arg: string, body: Node|null, kind: string, stats?: string}}
	 */
	function presentTool(name, input, ctx) {
		input = input || {};
		const { server, tool } = splitName(name);
		const rel = (p) => (ctx && ctx.relPath ? ctx.relPath(p) : p);

		if (server === "livecanvas" && SITE_TOOLS[tool]) {
			const [label, argOf, kind] = SITE_TOOLS[tool];
			return { label: typeof label === "function" ? label(input) : label, arg: argOf(input) || "", body: null, kind: kind || "read" };
		}
		if (server === "livecanvas" && LC_LABELS[tool]) {
			const label = LC_LABELS[tool];
			const arg = input.selector || (tool.endsWith("_css") ? "Global CSS" : tool.endsWith("_js") ? "Global JS" : tool === "lc_get_context" || tool === "lc_save" ? "" : "current selection");
			if (/^lc_edit_/.test(tool)) {
				const d = diffView(input.old_string, input.new_string);
				return { label, arg: arg + (input.replace_all ? " · all matches" : ""), body: d.el, kind: "edit", stats: `+${d.added} −${d.removed}` };
			}
			if (/^lc_write_/.test(tool)) {
				const text = input.html || input.css || input.js || "";
				return { label, arg, body: codeView(text), kind: "write", stats: `${text.split("\n").length} lines` };
			}
			return { label, arg, body: null, kind: "read" };
		}

		switch (tool) {
			case "Edit": {
				const d = diffView(input.old_string, input.new_string);
				return { label: "Edit", arg: rel(input.file_path) + (input.replace_all ? " · all matches" : ""), body: d.el, kind: "edit", stats: `+${d.added} −${d.removed}` };
			}
			case "MultiEdit": {
				const box = h("div");
				let add = 0, del = 0;
				(input.edits || []).forEach((e) => { const d = diffView(e.old_string, e.new_string); add += d.added; del += d.removed; box.append(d.el); });
				return { label: "Edit", arg: rel(input.file_path), body: box, kind: "edit", stats: `+${add} −${del}` };
			}
			case "Write":
				return { label: "Write", arg: rel(input.file_path), body: codeView(input.content), kind: "write", stats: `${String(input.content || "").split("\n").length} lines` };
			case "Read":
				return { label: "Read", arg: rel(input.file_path) + (input.offset ? ` · from line ${input.offset}` : ""), body: null, kind: "read" };
			case "Bash":
				return { label: "Run", arg: input.description || "", body: codeView("$ " + (input.command || ""), { maxLines: 12 }), kind: "bash" };
			case "Grep":
				return { label: "Search", arg: `${input.pattern || ""}${input.path ? " in " + rel(input.path) : ""}${input.glob ? " (" + input.glob + ")" : ""}`, body: null, kind: "read" };
			case "Glob":
				return { label: "Find files", arg: input.pattern || "", body: null, kind: "read" };
			case "WebFetch":
				return { label: "Fetch", arg: input.url || "", body: null, kind: "read" };
			case "WebSearch":
				return { label: "Web search", arg: input.query || "", body: null, kind: "read" };
			case "TodoWrite": {
				const list = h("ul", { class: "lccb-chat-todos" },
					...(input.todos || []).map((t) => h("li", { "data-status": t.status, text: t.content || t.activeForm || "" })));
				return { label: "Plan", arg: `${(input.todos || []).filter((t) => t.status === "completed").length}/${(input.todos || []).length} done`, body: list, kind: "todo" };
			}
			case "Task":
			case "Agent":
				return { label: "Subagent", arg: input.description || "", body: input.prompt ? codeView(input.prompt, { maxLines: 8 }) : null, kind: "agent" };
		}
		const keys = Object.keys(input);
		return {
			label: server ? `${server} · ${tool}` : tool,
			arg: keys.length ? String(input[keys.find((k) => typeof input[k] === "string")] || "").slice(0, 120) : "",
			body: keys.length ? codeView(JSON.stringify(input, null, 2), { maxLines: 20 }) : null,
			kind: "other",
		};
	}

	/** Tools that are plumbing, not something the user needs to see. */
	function isHiddenTool(name) {
		return name === "ToolSearch";
	}

	function resultText(content) {
		if (typeof content === "string") return content;
		if (Array.isArray(content)) return content.map((c) => (c.type === "text" ? c.text : c.type === "image" ? "[image]" : c.type === "tool_reference" ? `[${c.tool_name}]` : `[${c.type}]`)).join("\n");
		return content == null ? "" : JSON.stringify(content);
	}

	function resultView(content, isError) {
		const wrap = h("div", { class: "lccb-chat-result" + (isError ? " is-error" : "") });
		wrap.append(h("div", { class: "lccb-chat-result-label", text: isError ? "Error" : "Result" }));
		// Screenshots from lc_screenshot: show the image Claude saw.
		const images = Array.isArray(content) ? content.filter((c) => c.type === "image" && c.source && c.source.data) : [];
		images.forEach((img) => wrap.append(h("img", { class: "lccb-chat-result-img", src: `data:${img.source.media_type};base64,${img.source.data}`, alt: "Screenshot" })));
		// Claude Code adds a "[Image: source: <temp file>]" line for each image it saved: the image itself is shown above.
		const text = resultText(Array.isArray(content) ? content.filter((c) => c.type !== "image") : content)
			.replace(/^\[Image: source: [^\]\n]+\]\s*$/gm, "")
			.replace(/^\s+|\s+$/g, "");
		if (!text && !images.length) return null;
		if (text) {
			// Previews from the site tools carry a ```diff block: colour it.
			const m = /^([\s\S]*?)```diff\n([\s\S]*?)\n```([\s\S]*)$/.exec(text);
			if (m) {
				if (m[1].trim()) wrap.append(codeView(m[1].trim(), { maxLines: 14 }));
				const box = h("div", { class: "lccb-chat-diff" });
				m[2].split("\n").forEach((line) => {
					const t = line[0] === "+" ? "is-add" : line[0] === "-" ? "is-del" : line.startsWith("@@") || line.startsWith("…") ? "is-fold" : "is-ctx";
					if (t === "is-fold") return box.append(h("div", { class: "lccb-chat-diff-fold", text: line }));
					box.append(h("div", { class: "lccb-chat-diff-line " + t }, h("span", { class: "lccb-chat-diff-sign", text: line[0] === "+" || line[0] === "-" ? line[0] : " " }), h("span", { class: "lccb-chat-diff-text", text: line.slice(2) })));
				});
				wrap.append(box);
				if (m[3].trim()) wrap.append(codeView(m[3].trim(), { maxLines: 14 }));
			} else {
				wrap.append(codeView(text, { maxLines: 14 }));
			}
		}
		return wrap;
	}

	/** Previews and screenshots are worth seeing without a click. */
	function resultOpensRow(content) {
		const text = resultText(Array.isArray(content) ? content.filter((c) => c.type !== "image") : content);
		return (Array.isArray(content) && content.some((c) => c.type === "image")) || /```diff|^PREVIEW/.test(text);
	}

	// ───────────────────────── Stats ─────────────────────────

	function fmtTokens(n) {
		n = Number(n) || 0;
		if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M";
		if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, "") + "k";
		return String(n);
	}

	function fmtSecs(ms) {
		if (!ms && ms !== 0) return "";
		return ms < 10000 ? (ms / 1000).toFixed(1) + "s" : Math.round(ms / 1000) + "s";
	}

	/** Usage numbers for one reply (a `result` event). */
	function replyUsage(result) {
		const u = result.usage || {};
		const fresh = u.input_tokens || 0;
		const read = u.cache_read_input_tokens || 0;
		const written = u.cache_creation_input_tokens || 0;
		const totalIn = fresh + read + written;
		const cc = u.cache_creation || {};
		return {
			fresh, read, written, totalIn,
			written1h: cc.ephemeral_1h_input_tokens || 0,
			written5m: cc.ephemeral_5m_input_tokens || 0,
			out: u.output_tokens || 0,
			thinking: (u.output_tokens_details && u.output_tokens_details.thinking_tokens) || 0,
			hit: totalIn ? read / totalIn : 0,
			cost: result.total_cost_usd || 0,
			ttft: result.ttft_ms,
			duration: result.duration_ms,
			turns: result.num_turns,
		};
	}

	/** @param {object} result  @param {{cost?: number}} [opts] cost: this reply's own cost (result.total_cost_usd is cumulative per process) */
	function statsLine(result, opts) {
		const stopped = result.subtype === "error_during_execution";
		const status = stopped ? "Stopped" : result.is_error ? "Error" : "Done";
		const u = replyUsage(result);
		if (opts && typeof opts.cost === "number") u.cost = opts.cost;
		const summary = [
			status,
			fmtSecs(u.duration),
			u.ttft ? `first token ${fmtSecs(u.ttft)}` : "",
			u.totalIn ? `${fmtTokens(u.totalIn)} in` : "",
			u.out ? `${fmtTokens(u.out)} out` : "",
			u.totalIn ? `cache ${Math.round(u.hit * 100)}%` : "",
			u.cost ? `≈$${u.cost.toFixed(u.cost < 0.1 ? 3 : 2)}` : "",
		].filter(Boolean).join(" · ");

		const row = (k, v, note) => h("div", { class: "lccb-chat-stats-row" }, h("span", { text: k }), h("strong", { text: v }), note ? h("em", { text: note }) : null);
		const detail = h("div", { class: "lccb-chat-stats-detail" },
			row("Fresh input", fmtTokens(u.fresh) + " tokens"),
			row("Cache read", fmtTokens(u.read) + " tokens", u.totalIn ? `${Math.round(u.hit * 100)}% of input came from the prompt cache` : ""),
			row("Cache written", fmtTokens(u.written) + " tokens", u.written ? (u.written1h ? "1-hour cache" : "5-minute cache") : ""),
			row("Output", fmtTokens(u.out) + " tokens", u.thinking ? `incl. ${fmtTokens(u.thinking)} thinking` : ""),
			row("Time", fmtSecs(u.duration), u.ttft ? `first token after ${fmtSecs(u.ttft)}` : ""),
			u.turns ? row("Model calls", String(u.turns)) : null,
			u.cost ? row("API-equivalent cost", "$" + u.cost.toFixed(4), "not billed on your subscription") : null);

		const el = h("details", { class: "lccb-chat-stats" + (stopped || result.is_error ? " is-warn" : "") }, h("summary", { text: summary }), detail);
		return el;
	}

	window.lccbChatRender = { h, markdown, diffView, codeView, presentTool, isHiddenTool, resultView, resultOpensRow, resultText, statsLine, replyUsage, fmtTokens, fmtSecs, copyButton };
})();
