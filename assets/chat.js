/**
 * LC Claude Bridge — CC Chat tab.
 *
 * A chat panel in the LiveCanvas code editor, driven by Claude Code's headless streaming mode via the hub
 * (runtime/chat.js, role=chat). Renders purely from the event stream, so a replay after reload looks the same.
 * Rendering helpers (Markdown, diffs, tool presentation, stats) live in chat-render.js.
 */
(function () {
	"use strict";

	const R = window.lccbChatRender;
	const { h } = R;
	const config = window.lccbConfig || {};
	const PANEL_ID = "lc-claude-chat";
	const TAB_ID = "chat-tab";
	const LC_PANELS = "#lc-html-editor, #lc-css-editor, #lc-js-editor";
	const MODE_LABELS = { default: "Ask before edits", acceptEdits: "Accept edits", plan: "Plan mode", bypassPermissions: "Bypass permissions" };

	let ws = null;
	let retryDelay = 1000;
	let st = { alive: false, busy: false, model: "default", mode: "default", pending: [] };
	const els = {};

	function relPath(p) {
		if (!p) return "";
		const root = st.cwd ? st.cwd.replace(/\/$/, "") + "/" : "";
		return root && p.startsWith(root) ? p.slice(root.length) : p.replace(/^\/Users\/[^/]+/, "~");
	}

	function scrollToEnd(force) {
		const log = els.log;
		if (!log) return;
		const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 140;
		if (force || nearBottom) log.scrollTop = log.scrollHeight;
	}

	// ───────────────────────── Rendering ─────────────────────────

	const live = { blocks: new Map(), msgId: null }; // the message currently streaming
	const tools = new Map(); // tool_use_id → { row, name }
	const hiddenTools = new Set();
	const approvals = new Map(); // request_id → card
	let thinkingRow = null;
	let userTurns = []; // [{el, ts, label}] for "Restore to before this"

	// Usage across the conversation (rebuilt from the event stream, so it survives reloads).
	let usage;
	function resetUsage() {
		usage = {
			replies: 0, tools: 0, fresh: 0, read: 0, written: 0, out: 0, thinking: 0, cost: 0,
			lastCum: 0, // result.total_cost_usd is cumulative per Claude process
			ctxUsed: 0, ctxWindow: 0, model: "",
			cacheTtlMs: 0, lastTs: 0,
		};
	}
	resetUsage();

	function resetLog() {
		els.log.textContent = "";
		live.blocks.clear();
		tools.clear();
		hiddenTools.clear();
		approvals.clear();
		thinkingRow = null;
		userTurns = [];
		resetUsage();
		updateMeters();
		els.log.append(h("div", { class: "lccb-chat-empty" },
			h("strong", { text: "Claude Code" }),
			h("p", { text: "Ask about this page, or ask Claude to build something. It can read and edit the open page, Global CSS and Global JS, and the site's files." })));
	}

	function append(el) {
		const empty = els.log.querySelector(".lccb-chat-empty");
		if (empty) empty.remove();
		els.log.append(el);
		scrollToEnd();
		return el;
	}

	// Streaming text is re-rendered as Markdown at most once per frame.
	function textBlock(msgId) {
		const el = append(h("div", { class: "lccb-chat-msg is-assistant lccb-chat-md", "data-msg": msgId || "" }));
		return { el, raw: "", queued: false };
	}

	function renderText(blk) {
		if (blk.queued) return;
		blk.queued = true;
		requestAnimationFrame(() => {
			blk.queued = false;
			R.markdown(blk.el, blk.raw);
			scrollToEnd();
		});
	}

	function toolRow(id, name, input) {
		if (R.isHiddenTool(name)) { hiddenTools.add(id); return null; }
		let entry = tools.get(id);
		if (!entry) {
			const row = append(h("details", { class: "lccb-chat-tool", "data-state": "running" },
				h("summary", {},
					h("span", { class: "lccb-chat-tool-icon" }),
					h("span", { class: "lccb-chat-tool-name" }),
					h("span", { class: "lccb-chat-tool-arg" }),
					h("span", { class: "lccb-chat-tool-stats" })),
				h("div", { class: "lccb-chat-tool-body" })));
			entry = { row, name, hasInput: false };
			tools.set(id, entry);
			usage.tools++;
			if (FILE_TOOLS.has(name) && userTurns.length) {
				userTurns[userTurns.length - 1].filesChanged = true;
				refreshRestoreButtons();
			}
			row.querySelector(".lccb-chat-tool-name").textContent = R.presentTool(name, {}, { relPath }).label;
		}
		if (input && !entry.hasInput && (Object.keys(input).length || name)) {
			const p = R.presentTool(name, input, { relPath });
			entry.hasInput = Object.keys(input).length > 0;
			entry.row.dataset.kind = p.kind;
			entry.row.querySelector(".lccb-chat-tool-name").textContent = p.label;
			entry.row.querySelector(".lccb-chat-tool-arg").textContent = p.arg || "";
			entry.row.querySelector(".lccb-chat-tool-stats").textContent = p.stats || "";
			const body = entry.row.querySelector(".lccb-chat-tool-body");
			body.textContent = "";
			if (p.body) body.append(p.body);
			if (p.kind === "edit" || p.kind === "todo") entry.row.open = true; // show diffs and plans without a click
		}
		return entry.row;
	}

	function render(e) {
		switch (e.type) {
			case "hub": return renderHub(e);
			case "system":
				if (e.subtype === "init") {
					st.cwd = e.cwd;
					append(h("div", { class: "lccb-chat-meta", text: `${e.model} · ${MODE_LABELS[e.permissionMode] || e.permissionMode}` }));
				}
				return;
			case "stream_event": return renderStream(e);
			case "assistant": return renderAssistant(e.message);
			case "user":
				if (Array.isArray(e.message && e.message.content)) {
					for (const c of e.message.content) {
						if (c.type !== "tool_result" || hiddenTools.has(c.tool_use_id)) continue;
						const entry = tools.get(c.tool_use_id);
						if (!entry) continue;
						entry.row.dataset.state = c.is_error ? "error" : "done";
						const res = R.resultView(c.content, c.is_error);
						if (res) entry.row.querySelector(".lccb-chat-tool-body").append(res);
						if (c.is_error || R.resultOpensRow(c.content)) entry.row.open = true;
					}
				}
				return;
			case "result": {
				live.blocks.clear();
				const cum = Number(e.total_cost_usd) || 0;
				const cost = cum >= usage.lastCum ? cum - usage.lastCum : cum; // a lower total means a new process
				usage.lastCum = cum;
				const u = R.replyUsage(e);
				usage.replies++;
				usage.fresh += u.fresh; usage.read += u.read; usage.written += u.written;
				usage.out += u.out; usage.thinking += u.thinking; usage.cost += cost;
				const mu = e.modelUsage || {};
				const win = Math.max(0, ...Object.values(mu).map((m) => Number(m.contextWindow) || 0));
				if (win) usage.ctxWindow = win;
				if (u.written1h) usage.cacheTtlMs = 60 * 60 * 1000;
				else if (u.written5m && usage.cacheTtlMs !== 60 * 60 * 1000) usage.cacheTtlMs = 5 * 60 * 1000;
				usage.lastTs = e._ts || Date.now();
				append(R.statsLine(e, { cost }));
				updateMeters();
				return;
			}
			case "rate_limit_event":
				st.rate = e.rate_limit_info;
				updateFooter();
				return updateMeters();
		}
	}

	function renderHub(e) {
		switch (e.subtype) {
			case "user": {
				const bubble = h("div", { class: "lccb-chat-msg is-user" });
				if (e.images && e.images.length) {
					bubble.append(h("div", { class: "lccb-chat-user-images" },
						...e.images.map((img) => img.thumb
							? h("img", { src: img.thumb, alt: "Attached image", title: `${img.width || "?"}×${img.height || "?"} · ${R.fmtTokens(img.bytes || 0)}B` })
							: h("span", { class: "lccb-chat-attach", text: "🖼 image" }))));
				}
				if (e.text) bubble.append(h("div", { class: "lccb-chat-user-text", text: e.text }));
				if (e._ts) {
					const wrap = h("div", { class: "lccb-chat-user-wrap" }, bubble, h("div", { class: "lccb-chat-restore-slot" }));
					userTurns.push({ el: wrap, ts: e._ts, label: e.text || "(image)", turnId: e.turnId, uuid: false, filesChanged: false });
					append(wrap);
					refreshRestoreButtons();
					scrollToEnd(true);
					return;
				}
				append(bubble);
				scrollToEnd(true);
				return;
			}
			case "thinking":
				if (e.phase === "start") {
					thinkingRow = append(h("div", { class: "lccb-chat-thinking", text: "Thinking…" }));
				} else if (thinkingRow) {
					thinkingRow.textContent = `Thought for ${Math.max(1, Math.round((e.ms || 0) / 1000))}s`;
					thinkingRow.dataset.done = "1";
					thinkingRow = null;
				}
				return;
			case "approval_request": return renderApproval(e.request);
			case "approval_resolved": {
				const card = approvals.get(e.request_id);
				if (!card) return;
				const verdict = e.decision === "deny" ? "denied" : "allowed";
				if (card.classList.contains("lccb-chat-tool")) {
					card.dataset.approval = verdict;
					if (card.dataset.kind !== "edit" && card.dataset.kind !== "todo") card.open = false; // only opened to ask
				}
				else { card.dataset.state = verdict; card.open = false; }
				const outcome = e.decision === "deny" ? `Denied${e.message && e.message !== "The user denied this action." ? ": " + e.message : ""}` : e.decision === "allow_session" ? "Allowed for this session" : "Allowed";
				const actions = card.querySelector(".lccb-chat-approval-actions");
				if (actions) actions.replaceWith(h("div", { class: "lccb-chat-approval-outcome", text: outcome }));
				return;
			}
			case "note":
				append(h("div", { class: "lccb-chat-meta is-note", text: e.text }));
				return;
			case "turn_uuid": {
				const t = userTurns.find((x) => x.turnId === e.turnId);
				if (t) { t.uuid = true; refreshRestoreButtons(); }
				return;
			}
			case "process_exit":
				usage.lastCum = 0;
				if (e.code) append(h("div", { class: "lccb-chat-meta is-warn", text: `Claude Code exited (${e.code}). ${e.stderr || ""} Send a message to resume.` }));
				return;
		}
	}

	function renderStream(se) {
		const ev = se.event;
		if (!ev) return;
		if (ev.type === "message_start") {
			live.msgId = ev.message && ev.message.id;
			if (ev.message) trackContext(ev.message.usage, ev.message.model);
		} else if (ev.type === "content_block_start") {
			const b = ev.content_block || {};
			if (b.type === "text") live.blocks.set(ev.index, { type: "text", ...textBlock(live.msgId) });
			else if (b.type === "tool_use") { toolRow(b.id, b.name, null); live.blocks.set(ev.index, { type: "tool" }); }
		} else if (ev.type === "content_block_delta") {
			const blk = live.blocks.get(ev.index);
			if (blk && blk.type === "text" && ev.delta.type === "text_delta") {
				blk.raw += ev.delta.text;
				renderText(blk);
			}
		}
	}

	// Full assistant messages are authoritative (and the only source of text during a replay).
	function trackContext(u, model) {
		if (!u) return;
		const used = (u.input_tokens || 0) + (u.cache_read_input_tokens || 0) + (u.cache_creation_input_tokens || 0);
		if (used) usage.ctxUsed = used;
		if (model) usage.model = model;
		updateMeters();
	}

	function renderAssistant(msg) {
		if (!msg || !Array.isArray(msg.content)) return;
		trackContext(msg.usage, msg.model);
		for (const c of msg.content) {
			if (c.type === "text") {
				const streamed = [...live.blocks.values()].find((b) => b.type === "text" && !b.final && b.el.dataset.msg === (msg.id || ""));
				const blk = streamed || { type: "text", ...textBlock(msg.id) };
				blk.raw = c.text;
				blk.final = true;
				R.markdown(blk.el, blk.raw);
			} else if (c.type === "tool_use") {
				toolRow(c.id, c.name, c.input);
			}
		}
		scrollToEnd();
	}

	function approvalActions(req) {
		const deny = h("input", { type: "text", class: "lccb-chat-deny-reason", placeholder: "Tell Claude why (optional), Enter to deny" });
		deny.addEventListener("keydown", (ev) => { if (ev.key === "Enter") approve(req.request_id, "deny", deny.value); });
		return h("div", { class: "lccb-chat-approval-actions" },
			h("button", { type: "button", class: "is-primary", onclick: () => approve(req.request_id, "allow") }, "Allow"),
			req.permission_suggestions && req.permission_suggestions.length
				? h("button", { type: "button", onclick: () => approve(req.request_id, "allow_session"), title: describeSuggestion(req.permission_suggestions) }, describeSuggestionShort(req.permission_suggestions))
				: null,
			h("button", { type: "button", onclick: () => approve(req.request_id, "deny", deny.value) }, "Deny"),
			deny);
	}

	function renderApproval(req) {
		if (approvals.has(req.request_id)) return;
		// Usually the tool row is already on screen: put the decision inside it (no duplicate diff).
		const entry = req.tool_use_id && tools.get(req.tool_use_id);
		if (entry) {
			toolRow(req.tool_use_id, req.tool_name, req.input);
			entry.row.dataset.approval = "pending";
			entry.row.open = true;
			entry.row.append(approvalActions(req));
			approvals.set(req.request_id, entry.row);
			scrollToEnd(true);
			return;
		}
		const p = R.presentTool(req.tool_name, req.input, { relPath });
		const card = append(h("details", { class: "lccb-chat-approval", "data-state": "pending", open: true },
			h("summary", { class: "lccb-chat-approval-title" },
				h("strong", { text: `Allow ${p.label}?` }),
				p.arg ? h("span", { class: "lccb-chat-approval-arg", text: p.arg }) : null,
				p.stats ? h("span", { class: "lccb-chat-tool-stats", text: p.stats }) : null),
			req.description && req.description !== p.arg ? h("div", { class: "lccb-chat-approval-desc", text: req.description }) : null,
			p.body ? h("div", { class: "lccb-chat-approval-body" }, p.body) : null,
			approvalActions(req)));
		approvals.set(req.request_id, card);
		scrollToEnd(true);
	}

	function describeSuggestionShort(sugs) {
		const s = sugs[0] || {};
		if (s.type === "setMode" && s.mode === "acceptEdits") return "Allow all edits this session";
		if (s.type === "addRules") return "Always allow this tool";
		return "Allow for session";
	}

	function describeSuggestion(sugs) {
		return sugs.map((s) => s.type === "setMode" ? `Switch to "${MODE_LABELS[s.mode] || s.mode}" for this session`
			: s.type === "addRules" ? `Allow ${(s.rules || []).map((r) => r.toolName + (r.ruleContent ? `(${r.ruleContent})` : "")).join(", ")} (${s.destination === "session" ? "this session" : "saved to " + s.destination})`
			: JSON.stringify(s)).join("\n");
	}

	function approve(requestId, decision, message) {
		send({ t: "approve", request_id: requestId, decision, message: message || "" });
	}

	// ───────────────────────── Context meter, cache chip, usage popover ─────────────────────────

	function updateMeters() {
		if (!els.ctx) return;
		const pct = usage.ctxWindow ? usage.ctxUsed / usage.ctxWindow : 0;
		els.ctx.hidden = !usage.ctxUsed;
		els.ctxFill.style.width = Math.min(100, Math.round(pct * 100)) + "%";
		els.ctx.dataset.level = pct > 0.85 ? "high" : pct > 0.7 ? "mid" : "ok";
		els.ctxText.textContent = usage.ctxWindow
			? `Context ${Math.round(pct * 100)}% of ${R.fmtTokens(usage.ctxWindow)}`
			: `Context ${R.fmtTokens(usage.ctxUsed)}`;
		els.ctx.title = `${R.fmtTokens(usage.ctxUsed)} tokens in context${usage.ctxWindow ? " of " + R.fmtTokens(usage.ctxWindow) : ""}. Claude Code compacts the conversation automatically when it gets close to the limit. Click for session usage.`;
		updateCacheChip();
		if (!els.usagePop.hidden) renderUsagePop();
	}

	function updateCacheChip() {
		if (!els.cache) return;
		if (!usage.lastTs || !usage.cacheTtlMs) { els.cache.hidden = true; return; }
		const left = usage.lastTs + usage.cacheTtlMs - Date.now();
		els.cache.hidden = false;
		if (left > 0) {
			const mins = Math.max(1, Math.round(left / 60000));
			els.cache.dataset.state = "warm";
			els.cache.textContent = `Cache warm · ${mins}m`;
			els.cache.title = `The prompt cache from your last message (${usage.cacheTtlMs >= 3600000 ? "1-hour" : "5-minute"} cache) is still warm, so your next message reuses it cheaply.`;
		} else {
			els.cache.dataset.state = "cold";
			els.cache.textContent = "Cache cold";
			els.cache.title = "The prompt cache has expired: your next message re-writes it, which uses more of your plan limit than a cached message.";
		}
	}
	setInterval(updateCacheChip, 30000);

	function bar(label, frac, note) {
		const pct = Math.round(Math.min(1, Math.max(0, frac || 0)) * 100);
		return h("div", { class: "lccb-chat-limit" },
			h("div", { class: "lccb-chat-limit-head" }, h("span", { text: label }), h("strong", { text: pct + "%" })),
			h("div", { class: "lccb-chat-limit-bar", "data-level": pct > 85 ? "high" : pct > 70 ? "mid" : "ok" }, h("span", { style: `width:${pct}%` })),
			note ? h("div", { class: "lccb-chat-limit-note", text: note }) : null);
	}

	function resetsIn(epochSecs) {
		if (!epochSecs) return "";
		const ms = epochSecs * 1000 - Date.now();
		if (ms <= 0) return "resets now";
		const days = Math.floor(ms / 86400000), hrs = Math.floor((ms % 86400000) / 3600000), mins = Math.round((ms % 3600000) / 60000);
		const span = days ? `${days}d ${hrs}h` : hrs ? `${hrs}h ${mins}m` : `${mins}m`;
		const when = new Date(epochSecs * 1000).toLocaleString([], { weekday: ms > 20 * 3600000 ? "short" : undefined, hour: "numeric", minute: "2-digit" });
		return `resets in ${span} (${when})`;
	}

	function renderUsagePop() {
		const totalIn = usage.fresh + usage.read + usage.written;
		const row = (k, v, note) => h("div", { class: "lccb-chat-stats-row" }, h("span", { text: k }), h("strong", { text: v }), note ? h("em", { text: note }) : null);
		const rate = st.rate || {};
		const win = rate.unifiedWindows || {};
		els.usagePop.textContent = "";
		const parts = [
			h("div", { class: "lccb-chat-pop-title", text: "This conversation" }),
			h("div", { class: "lccb-chat-stats-detail is-flat" },
				row("Context", R.fmtTokens(usage.ctxUsed) + (usage.ctxWindow ? " / " + R.fmtTokens(usage.ctxWindow) : ""), usage.model || ""),
				row("Replies", String(usage.replies), `${usage.tools} tool call${usage.tools === 1 ? "" : "s"}`),
				row("Input", R.fmtTokens(totalIn) + " tokens", `${R.fmtTokens(usage.fresh)} fresh · ${R.fmtTokens(usage.written)} cache written`),
				row("From cache", R.fmtTokens(usage.read) + " tokens", totalIn ? `${Math.round((usage.read / totalIn) * 100)}% of all input reused from the prompt cache` : ""),
				row("Output", R.fmtTokens(usage.out) + " tokens", usage.thinking ? `incl. ${R.fmtTokens(usage.thinking)} thinking` : ""),
				usage.cost ? row("API-equivalent", "$" + usage.cost.toFixed(2), "not billed on your subscription") : null),
			h("div", { class: "lccb-chat-pop-title", text: "Plan limits" }),
			win.five_hour ? bar("5-hour window", win.five_hour.utilization, resetsIn(win.five_hour.resetsAt)) : h("div", { class: "lccb-chat-limit-note", text: "Shown after your next message." }),
			win.seven_day ? bar("Weekly", win.seven_day.utilization, resetsIn(win.seven_day.resetsAt)) : null,
			rate.status && rate.status !== "allowed" ? h("div", { class: "lccb-chat-limit-warn", text: `Limit status: ${rate.status}${rate.overageStatus ? " · overage " + rate.overageStatus : ""}` }) : null];
		els.usagePop.append(...parts.filter(Boolean));
	}

	function togglePop(pop, render) {
		const open = pop.hidden;
		els.usagePop.hidden = true;
		els.historyPop.hidden = true;
		els.cpPop.hidden = true;
		els.activityPop.hidden = true;
		if (open) { render(); pop.hidden = false; }
	}

	// ───────────────────────── Checkpoints (rollback) ─────────────────────────
	// The bridge (bridge.js) snapshots page HTML / Global CSS / Global JS before the first change of each reply.

	const CP = () => window.lccbCheckpoints;
	const FILE_TOOLS = new Set(["Edit", "Write", "MultiEdit", "NotebookEdit"]); // tracked by Claude Code's file checkpoints
	const PART_NAMES = { html: "Page", css: "CSS", js: "JS" };
	let checkpoints = [];

	/** Earliest checkpoint taken at/after a message: restoring it = "before this message". */
	function checkpointAfter(ts) {
		return checkpoints.find((c) => c.ts >= ts && c.source !== "restore") || null;
	}

	function refreshRestoreButtons() {
		userTurns.forEach((t, i) => {
			const slot = t.el.querySelector(".lccb-chat-restore-slot");
			const target = checkpointAfter(t.ts);
			// Files: Claude Code rewinds everything edited after this message, if this message has its uuid.
			const files = t.uuid && userTurns.slice(i).some((x) => x.filesChanged);
			slot.textContent = "";
			if (!target && !files) return;
			const parts = target ? [...new Set(checkpoints.slice(checkpoints.indexOf(target)).flatMap((c) => c.parts))].map((p) => PART_NAMES[p]) : [];
			if (files) parts.push("files");
			slot.append(h("button", { type: "button", class: "lccb-chat-restore", title: `Put ${parts.join(" + ")} back to how they were before this message`, onclick: () => restoreTo(target, t.label, files ? t : null) }, "↺ Restore to before this"));
		});
	}

	/** target: a builder checkpoint (or null); turn: the message whose files to rewind (or null). */
	async function restoreTo(target, label, turn) {
		if (st.busy && !confirm("Claude is still working. Restore anyway? (Claude may keep editing afterwards.)")) return;
		const what = [target ? "the page HTML, Global CSS and Global JS in the builder" : "", turn ? "files Claude edited (theme files etc.)" : ""].filter(Boolean).join(" and ");
		if (!confirm(`Restore to before "${String(label).slice(0, 60)}"?\n\nThis undoes changes made after that point to ${what}. Builder changes aren't saved until you click Save${target ? ", and you can undo the builder part from Checkpoints" : ""}.`)) return;
		if (target && CP()) {
			try {
				const r = await CP().restore(target.id);
				if (!r.restored && !turn) append(h("div", { class: "lccb-chat-meta is-note", text: "Nothing to restore: the builder already matches that checkpoint." }));
			} catch (err) {
				append(h("div", { class: "lccb-chat-meta is-warn", text: "Restore failed: " + err.message }));
			}
		}
		if (turn) send({ t: "rewind_files", turnId: turn.turnId });
	}

	function renderCheckpoints() {
		els.cpPop.textContent = "";
		els.cpPop.append(h("div", { class: "lccb-chat-pop-title", text: "Checkpoints for this page" }));
		if (!checkpoints.length) {
			els.cpPop.append(h("div", { class: "lccb-chat-limit-note", text: "None yet. A checkpoint is saved automatically before Claude's first change in each reply." }));
			return;
		}
		const list = h("div", { class: "lccb-chat-history" });
		[...checkpoints].reverse().forEach((c) => {
			list.append(h("div", { class: "lccb-chat-cp-item" },
				h("div", { class: "lccb-chat-cp-text" },
					h("span", { class: "lccb-chat-history-title", text: c.source === "restore" ? c.label : `Before: ${c.label}` }),
					h("span", { class: "lccb-chat-history-meta", text: [ago(c.ts), c.parts.map((p) => PART_NAMES[p]).join(" + "), c.source === "other" ? "terminal / other session" : ""].filter(Boolean).join(" · ") })),
				h("button", { type: "button", class: "lccb-chat-btn", onclick: () => { els.cpPop.hidden = true; restoreTo(c, c.label); } }, "Restore")));
		});
		els.cpPop.append(list, h("div", { class: "lccb-chat-limit-note", text: "Restoring changes the builder only. Save afterwards to roll back the live page." }));
	}

	function watchCheckpoints(tries = 0) {
		if (CP()) {
			CP().onChange((list) => {
				checkpoints = list;
				els.cpBtn.hidden = false;
				els.cpBtn.textContent = list.length ? `Checkpoints (${list.length})` : "Checkpoints";
				refreshRestoreButtons();
				if (!els.cpPop.hidden) renderCheckpoints();
			});
		} else if (tries < 120) {
			setTimeout(() => watchCheckpoints(tries + 1), 500);
		}
	}

	// ───────────────────────── Activity (site-level changes, from the audit log) ─────────────────────────

	const restBase = () => (window.lc_editor_rest_api_url || "/wp-json/").replace(/\/?$/, "/") + "lccb/v1/";
	const restHeaders = () => ({ "X-WP-Nonce": window.lc_editor_rest_api_nonce || "", "Content-Type": "application/json" });

	async function loadActivity() {
		els.activityPop.textContent = "";
		els.activityPop.append(h("div", { class: "lccb-chat-pop-title", text: "Site changes (pages, header/footer, templates, tokens, media)" }), h("div", { class: "lccb-chat-limit-note", text: "Loading…" }));
		try {
			const res = await fetch(restBase() + "activity?limit=20", { credentials: "same-origin", headers: restHeaders() });
			if (!res.ok) throw new Error(`HTTP ${res.status}`);
			renderActivity(await res.json());
		} catch (err) {
			els.activityPop.lastChild.textContent = "Couldn't load activity: " + err.message;
		}
	}

	function renderActivity(items) {
		els.activityPop.textContent = "";
		els.activityPop.append(h("div", { class: "lccb-chat-pop-title", text: "Site changes (pages, header/footer, templates, tokens, media)" }));
		if (!items.length) {
			els.activityPop.append(h("div", { class: "lccb-chat-limit-note", text: "None yet. Changes Claude applies with lc_apply_change or lc_media_import appear here, each with Undo." }));
			return;
		}
		const list = h("div", { class: "lccb-chat-history" });
		items.forEach((it) => {
			list.append(h("div", { class: "lccb-chat-cp-item" + (it.undone ? " is-undone" : "") },
				h("div", { class: "lccb-chat-cp-text" },
					h("span", { class: "lccb-chat-history-title", text: `#${it.id} ${it.action}: ${it.summary}` }),
					h("span", { class: "lccb-chat-history-meta", text: [it.ago, it.target, it.undone ? "undone" : ""].filter(Boolean).join(" · ") })),
				h("a", { class: "lccb-chat-btn", href: it.detail_url, target: "_blank", rel: "noopener", title: "Before/after in Tools › Claude Code › Activity" }, "Details"),
				it.undone ? null : h("button", { type: "button", class: "lccb-chat-btn", onclick: () => undoActivity(it) }, "Undo")));
		});
		els.activityPop.append(list, h("div", { class: "lccb-chat-limit-note", text: "These are live on the site (not builder edits). Undo puts an item back exactly as it was before that change." }));
	}

	async function undoActivity(it) {
		if (!confirm(`Undo #${it.id}: ${it.summary}?\n\nThe item goes back exactly as it was before this change. This is live on the site straight away, and can itself be undone from Activity.`)) return;
		try {
			const res = await fetch(restBase() + `activity/${it.id}/undo`, { method: "POST", credentials: "same-origin", headers: restHeaders(), body: "{}" });
			const body = await res.json();
			if (!res.ok) throw new Error(body && body.message ? body.message : `HTTP ${res.status}`);
			if (body.refresh_preview_css && window.lccbBridge) window.lccbBridge.refreshCss();
			send({ t: "site_note", text: `The user undid change #${it.id} (${it.summary}) from the Activity list.` });
			loadActivity();
		} catch (err) {
			append(h("div", { class: "lccb-chat-meta is-warn", text: `Undo #${it.id} failed: ${err.message}` }));
		}
	}

	// ───────────────────────── History ─────────────────────────

	const ENTRY_LABELS = { "claude-vscode": "VS Code", cli: "Terminal", "sdk-cli": "Chat", "sdk-ts": "SDK", "sdk-py": "SDK" };

	function ago(ms) {
		const s = Math.round((Date.now() - ms) / 1000);
		if (s < 60) return "just now";
		if (s < 3600) return Math.round(s / 60) + "m ago";
		if (s < 86400) return Math.round(s / 3600) + "h ago";
		return Math.round(s / 86400) + "d ago";
	}

	function renderHistory(items) {
		els.historyPop.textContent = "";
		els.historyPop.append(h("div", { class: "lccb-chat-pop-title", text: "Recent conversations on this site" }));
		if (!items) return els.historyPop.append(h("div", { class: "lccb-chat-limit-note", text: "Loading…" }));
		if (!items.length) return els.historyPop.append(h("div", { class: "lccb-chat-limit-note", text: "No earlier conversations yet." }));
		const list = h("div", { class: "lccb-chat-history" });
		for (const it of items) {
			const recent = Date.now() - it.mtime < 3 * 60 * 1000 && !it.current;
			list.append(h("button", {
				type: "button", class: "lccb-chat-history-item" + (it.current ? " is-current" : ""),
				title: recent ? "Active in the last few minutes, possibly open in another window. Resuming it here too can make the two copies diverge." : it.id,
				onclick: () => {
					if (it.current) return togglePop(els.historyPop, () => {});
					if (st.busy && !confirm("Stop the current reply and switch conversations?")) return;
					if (recent && !confirm("This conversation was active in the last few minutes, maybe in VS Code or a terminal. Resume it here anyway?")) return;
					els.historyPop.hidden = true;
					send({ t: "resume", sessionId: it.id });
				},
			},
				h("span", { class: "lccb-chat-history-title", text: it.title }),
				h("span", { class: "lccb-chat-history-meta", text: [it.current ? "Open now" : ago(it.mtime), ENTRY_LABELS[it.entrypoint] || it.entrypoint || "", recent ? "recently active" : ""].filter(Boolean).join(" · ") })));
		}
		els.historyPop.append(list);
	}

	// ───────────────────────── State / footer ─────────────────────────

	function applyState(s) {
		st = { ...st, ...s };
		els.send.textContent = st.busy ? "Stop" : "Send";
		els.send.classList.toggle("is-stop", st.busy);
		els.model.value = st.model;
		els.mode.value = st.mode;
		els.dot.dataset.state = !ws || ws.readyState !== 1 ? "off" : st.busy ? "busy" : "on";
		// Approvals pending in the hub but not yet drawn (e.g. after a reconnect).
		for (const req of st.pending || []) renderApproval(req);
		updateWaiting();
		updateFooter();
	}

	// Pinned above the composer so a pending approval can't be missed, however the log is scrolled.
	function updateWaiting() {
		const req = (st.pending || [])[0];
		els.waiting.hidden = !req;
		if (!req) return;
		const p = R.presentTool(req.tool_name, req.input, { relPath });
		els.waiting.textContent = "";
		els.waiting.append(
			h("span", { class: "lccb-chat-waiting-text", text: `Waiting for your approval: ${p.label}${p.arg ? " · " + p.arg : ""}` }),
			h("button", { type: "button", class: "is-primary", onclick: () => approve(req.request_id, "allow") }, "Allow"),
			h("button", { type: "button", onclick: () => { const card = approvals.get(req.request_id); if (card) { card.open = true; card.scrollIntoView({ block: "center", behavior: "smooth" }); } } }, "Review"),
			h("button", { type: "button", onclick: () => approve(req.request_id, "deny") }, "Deny"));
	}

	function updateFooter() {
		const parts = [];
		if (st.rate && st.rate.unifiedWindows && st.rate.unifiedWindows.five_hour) {
			parts.push(`5h usage ${Math.round(st.rate.unifiedWindows.five_hour.utilization * 100)}%`);
		}
		parts.push(st.sessionId ? `session ${st.sessionId.slice(0, 8)}` : "new chat");
		els.footer.textContent = parts.join(" · ");
	}

	// ───────────────────────── Transport ─────────────────────────

	function send(msg) {
		if (ws && ws.readyState === 1) { ws.send(JSON.stringify(msg)); return true; }
		return false;
	}

	function connect() {
		if (ws && ws.readyState <= 1) return;
		ws = new WebSocket(`ws://127.0.0.1:${config.port}/?role=chat&site=${encodeURIComponent(config.site)}&token=${encodeURIComponent(config.token)}`);
		ws.onopen = () => { retryDelay = 1000; els.notice.hidden = true; applyState({}); };
		ws.onmessage = (ev) => {
			let msg;
			try { msg = JSON.parse(ev.data); } catch (_) { return; }
			if (msg.t === "replay") { resetLog(); msg.events.forEach(safeRender); scrollToEnd(true); }
			else if (msg.t === "ev") safeRender(msg.e);
			else if (msg.t === "state") applyState(msg);
			else if (msg.t === "history") renderHistory(msg.items);
			else if (msg.t === "error") append(h("div", { class: "lccb-chat-meta is-warn", text: msg.message }));
		};
		ws.onclose = () => {
			ws = null;
			applyState({});
			els.notice.hidden = false;
			els.notice.innerHTML = "Can't reach the LC Claude Bridge hub. In WordPress open <strong>Tools › Claude Code</strong> and click <strong>Re-check &amp; repair</strong>.";
			setTimeout(connect, retryDelay);
			retryDelay = Math.min(retryDelay * 1.6, 15000);
		};
		ws.onerror = () => {};
	}

	// One odd event must never break the rest of the conversation.
	function safeRender(e) {
		try { render(e); } catch (err) { console.warn("[LC Claude chat] could not render event", e, err); }
	}

	// ───────────────────────── Composer ─────────────────────────

	function submit() {
		if (st.busy && !els.input.value.trim() && !attachments.length) return send({ t: "interrupt" });
		const text = els.input.value;
		if (!text.trim() && !attachments.length) return;
		if (attachBusy) return flash("Still preparing an image…");
		const ok = send({
			t: "send",
			text,
			images: attachments.map((a) => ({ media_type: a.media_type, data: a.data })),
			thumbs: attachments.map((a) => ({ thumb: a.thumb, width: a.width, height: a.height, bytes: a.bytes })),
		});
		if (ok) {
			els.input.value = "";
			attachments = [];
			renderAttachments();
			autosize();
		}
	}

	// ───────────────────────── Attachments ─────────────────────────

	const MAX_IMAGES = 8;
	let attachments = [];
	let attachBusy = 0;

	function flash(text) {
		els.attachNote.textContent = text;
		els.attachNote.hidden = !text;
		clearTimeout(flash.t);
		if (text) flash.t = setTimeout(() => (els.attachNote.hidden = true), 5000);
	}

	async function addImage(promise, label) {
		if (attachments.length >= MAX_IMAGES) return flash(`Up to ${MAX_IMAGES} images per message.`);
		attachBusy++;
		flash(label || "Preparing image…");
		try {
			attachments.push(await promise);
			renderAttachments();
			flash("");
			els.input.focus();
		} catch (err) {
			if (err && err.name === "NotAllowedError") flash("Screen capture was cancelled.");
			else flash(err && err.message ? err.message : "Couldn't attach that image.");
		} finally {
			attachBusy--;
		}
	}

	function addFiles(files) {
		[...(files || [])].filter((f) => /^image\//.test(f.type)).forEach((f) => addImage(window.lccbChatAttach.fromBlob(f, f.name)));
	}

	function renderAttachments() {
		els.attachRow.textContent = "";
		els.attachRow.hidden = !attachments.length;
		attachments.forEach((a, i) => {
			els.attachRow.append(h("div", { class: "lccb-chat-thumb", title: `${a.name} · ${a.width}×${a.height} · ${R.fmtTokens(a.bytes)}B` },
				h("img", { src: a.thumb, alt: a.name }),
				h("button", { type: "button", class: "lccb-chat-thumb-x", "aria-label": "Remove image", onclick: () => { attachments.splice(i, 1); renderAttachments(); } }, "×")));
		});
	}

	function captureMenu() {
		const menu = h("div", { class: "lccb-chat-menu", hidden: true },
			h("button", { type: "button", onclick: () => pick(() => window.lccbChatAttach.capturePreview("visible"), "Capturing the visible preview…") }, h("strong", { text: "Visible preview" }), h("span", { text: "What you can see now, at the current device width" })),
			h("button", { type: "button", onclick: () => pick(() => window.lccbChatAttach.capturePreview("page"), "Capturing the whole page…") }, h("strong", { text: "Whole page" }), h("span", { text: "Top to bottom at the current device width" })),
			h("button", { type: "button", onclick: () => pick(() => window.lccbChatAttach.captureExact(), "Choose this tab in Chrome's dialog…") }, h("strong", { text: "Exact screenshot" }), h("span", { text: "Pixel-perfect; Chrome asks you to confirm" })));
		function pick(fn, label) { menu.hidden = true; addImage(fn(), label); }
		document.addEventListener("click", (e) => { if (!menu.hidden && !menu.contains(e.target) && !e.target.closest(".lccb-chat-cam")) menu.hidden = true; });
		return menu;
	}

	function autosize() {
		els.input.style.height = "auto";
		els.input.style.height = Math.min(els.input.scrollHeight, 220) + "px";
	}

	// ───────────────────────── Tab + panel ─────────────────────────

	function buildPanel() {
		els.dot = h("span", { class: "lccb-chat-dot", "data-state": "off" });
		els.model = h("select", { class: "lccb-chat-select", title: "Model", onchange: () => send({ t: "set", model: els.model.value }) },
			...[["default", "Default model"], ["opus", "Opus"], ["sonnet", "Sonnet"], ["haiku", "Haiku"]].map(([v, l]) => h("option", { value: v, text: l })));
		els.mode = h("select", { class: "lccb-chat-select", title: "Permission mode", onchange: () => send({ t: "set", mode: els.mode.value }) },
			...[["default", MODE_LABELS.default], ["acceptEdits", MODE_LABELS.acceptEdits], ["plan", MODE_LABELS.plan]].map(([v, l]) => h("option", { value: v, text: l })));
		const newBtn = h("button", { type: "button", class: "lccb-chat-btn", onclick: () => { if (!st.busy || confirm("Stop the current reply and start a new chat?")) send({ t: "new" }); } }, "New chat");
		const historyBtn = h("button", { type: "button", class: "lccb-chat-btn", onclick: () => togglePop(els.historyPop, () => { renderHistory(null); send({ t: "history" }); }) }, "History");
		els.ctxFill = h("span");
		els.ctxText = h("span", { class: "lccb-chat-ctx-text" });
		els.ctx = h("button", { type: "button", class: "lccb-chat-ctx", hidden: true, onclick: () => togglePop(els.usagePop, renderUsagePop) },
			h("span", { class: "lccb-chat-ctx-bar" }, els.ctxFill), els.ctxText);
		els.cache = h("span", { class: "lccb-chat-cache", hidden: true });
		els.usagePop = h("div", { class: "lccb-chat-pop lccb-chat-pop-usage", hidden: true });
		els.cpPop = h("div", { class: "lccb-chat-pop lccb-chat-pop-history", hidden: true });
		els.activityPop = h("div", { class: "lccb-chat-pop lccb-chat-pop-history", hidden: true });
		const activityBtn = h("button", { type: "button", class: "lccb-chat-btn", title: "Site-level changes Claude applied (pages, header/footer, templates, tokens, media), with Undo", onclick: () => togglePop(els.activityPop, loadActivity) }, "Activity");
		els.cpBtn = h("button", { type: "button", class: "lccb-chat-btn", hidden: true, title: "Roll the builder back to before one of Claude's replies", onclick: () => togglePop(els.cpPop, renderCheckpoints) }, "Checkpoints");
		els.historyPop = h("div", { class: "lccb-chat-pop lccb-chat-pop-history", hidden: true });
		document.addEventListener("click", (e) => {
			if (!e.target.closest || e.target.closest(".lccb-chat-pop, .lccb-chat-ctx, .lccb-chat-bar .lccb-chat-btn")) return;
			els.usagePop.hidden = true;
			els.historyPop.hidden = true;
			els.cpPop.hidden = true;
			els.activityPop.hidden = true;
		});

		els.log = h("div", { class: "lccb-chat-log" });
		els.notice = h("div", { class: "lccb-chat-notice", hidden: true });
		els.input = h("textarea", { class: "lccb-chat-input", rows: 1, placeholder: "Message Claude…  (Enter to send, Shift+Enter for a new line, Esc to stop)" });
		els.send = h("button", { type: "button", class: "lccb-chat-send", onclick: submit }, "Send");
		els.footer = h("div", { class: "lccb-chat-footer" });
		els.waiting = h("div", { class: "lccb-chat-waiting", hidden: true });
		els.attachRow = h("div", { class: "lccb-chat-attachments", hidden: true });
		els.attachNote = h("div", { class: "lccb-chat-attach-note", hidden: true });
		const fileInput = h("input", { type: "file", accept: "image/png,image/jpeg,image/gif,image/webp", multiple: true, hidden: true });
		fileInput.addEventListener("change", () => { addFiles(fileInput.files); fileInput.value = ""; });
		const menu = captureMenu();
		const tools = h("div", { class: "lccb-chat-composer-tools" },
			h("button", { type: "button", class: "lccb-chat-icon-btn", title: "Attach images (or paste / drop them)", onclick: () => fileInput.click() }, h("span", { class: "fa fa-paperclip" })),
			h("button", { type: "button", class: "lccb-chat-icon-btn lccb-chat-cam", title: "Screenshot the preview", onclick: () => { menu.hidden = !menu.hidden; } }, h("span", { class: "fa fa-camera" })),
			menu, fileInput);

		els.input.addEventListener("paste", (ev) => {
			const files = [...(ev.clipboardData ? ev.clipboardData.items : [])].filter((it) => it.kind === "file" && /^image\//.test(it.type)).map((it) => it.getAsFile());
			if (files.length) { ev.preventDefault(); addFiles(files); }
		});

		els.input.addEventListener("input", autosize);
		els.input.addEventListener("keydown", (ev) => {
			if (ev.key === "Enter" && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); submit(); }
			else if (ev.key === "Escape" && st.busy) { ev.preventDefault(); send({ t: "interrupt" }); }
		});

		const panel = h("div", { id: PANEL_ID, role: "tabpanel", "aria-labelledby": TAB_ID, hidden: true },
			h("div", { class: "lccb-chat-bar" },
				h("div", { class: "lccb-chat-bar-left" }, els.dot, newBtn, historyBtn, els.cpBtn, activityBtn),
				h("div", { class: "lccb-chat-bar-mid" }, els.ctx, els.cache),
				h("div", { class: "lccb-chat-bar-right" }, els.model, els.mode),
				els.usagePop, els.historyPop, els.cpPop, els.activityPop),
			els.notice,
			els.log,
			els.waiting,
			els.attachNote,
			h("div", { class: "lccb-chat-composer" }, els.attachRow, h("div", { class: "lccb-chat-composer-row" }, tools, els.input, els.send)),
			els.footer);

		// Drop images anywhere on the panel.
		panel.addEventListener("dragover", (e) => { if ([...e.dataTransfer.types].includes("Files")) { e.preventDefault(); panel.classList.add("is-drop"); } });
		panel.addEventListener("dragleave", (e) => { if (e.target === panel || !panel.contains(e.relatedTarget)) panel.classList.remove("is-drop"); });
		panel.addEventListener("drop", (e) => { if (e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } panel.classList.remove("is-drop"); });

		// Keep keystrokes inside the panel (LiveCanvas's page-level shortcuts would eat Escape, Cmd+E, …).
		["keydown", "keyup", "keypress"].forEach((type) => panel.addEventListener(type, (e) => e.stopPropagation()));
		resetLog();
		watchCheckpoints();
		return panel;
	}

	function injectTab() {
		const after = document.getElementById("claude-tab") || document.getElementById("js-tab");
		const afterPanel = document.getElementById("lc-claude-terminal") || document.getElementById("lc-js-editor");
		if (!after || !afterPanel || document.getElementById(TAB_ID)) return false;

		const tab = h("a", { id: TAB_ID, href: "#", role: "tab", "aria-label": "Claude Code chat", "aria-controls": PANEL_ID, "aria-selected": "false", tabindex: "-1" },
			h("span", { class: "fa fa-comments lc-editor-tab-icon", "aria-hidden": "true" }),
			h("span", { class: "lc-editor-tab-label" }, h("span", { class: "lc-editor-tab-prefix", text: "CC " }), "Chat"));
		after.after(tab);
		afterPanel.after(buildPanel());

		tab.addEventListener("click", (e) => { e.preventDefault(); showTab(); });
		window.jQuery("body").on("click", "#html-tab, #css-tab, #js-tab, #claude-tab", hideTab);
		return true;
	}

	function showTab() {
		const tab = document.getElementById(TAB_ID);
		document.querySelectorAll(".code-tabber [role='tab']").forEach((t) => {
			const active = t === tab;
			t.classList.toggle("active", active);
			t.setAttribute("aria-selected", active ? "true" : "false");
			t.tabIndex = active ? 0 : -1;
		});
		window.jQuery(".lc-editor-menubar .only-for-html").hide();
		window.jQuery(LC_PANELS).hide();
		const term = document.getElementById("lc-claude-terminal");
		if (term) term.hidden = true; // terminal.js toggles .hidden, so don't leave an inline display:none behind
		document.getElementById("lc-code-editor-window").classList.add("lccb-chat-active");
		document.getElementById(PANEL_ID).hidden = false;
		connect();
		requestAnimationFrame(() => { scrollToEnd(true); els.input.focus(); });
	}

	function hideTab() {
		const panel = document.getElementById(PANEL_ID);
		if (panel) panel.hidden = true;
		document.getElementById("lc-code-editor-window").classList.remove("lccb-chat-active");
	}

	(function waitForEditor(tries) {
		// Wait for terminal.js's CC Terminal tab when it's coming, so CC Chat lands after it.
		const ready = window.jQuery && document.getElementById("js-tab") && (document.getElementById("claude-tab") || tries > 20);
		if (ready && injectTab()) return;
		if (tries < 240) setTimeout(() => waitForEditor(tries + 1), 250);
	})(0);
})();
