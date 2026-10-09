/**
 * CHAT tab sessions: Claude Code in headless streaming mode, one long-lived process per site.
 *
 *   claude -p --input-format stream-json --output-format stream-json --verbose --include-partial-messages
 *          --permission-prompts host --permission-prompt-tool stdio [--model m] [--permission-mode m] [--resume id]
 *
 * Protocol verified against Claude Code 2.1.286 (see .claude/plans/04-chat-tab.md).
 *
 * Browser ↔ hub (role=chat), JSON:
 *   client → {t:"send", text, images:[{media_type, data}]}
 *            {t:"approve", request_id, decision:"allow"|"allow_session"|"deny", message?}
 *            {t:"interrupt"}  {t:"new"}  {t:"set", model?, mode?}
 *            {t:"history"}  {t:"resume", sessionId}
 *   hub    → {t:"replay", events:[…]}   everything worth re-rendering after a reload
 *            {t:"ev", e}                one stream event (live; deltas are not kept for replay)
 *            {t:"state", …}             busy / alive / sessionId / model / mode / pending approvals
 *            {t:"history", items:[{id, title, mtime, size, entrypoint, current}]}
 *            {t:"error", message}
 */
import { spawn } from "node:child_process";
import { readdirSync, statSync, openSync, readSync, closeSync, readFileSync, existsSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const LOG_LIMIT = 3 * 1024 * 1024; // bytes of replayable events kept per site
const IDLE_MS = 30 * 60 * 1000; // close an idle process; the next message resumes the session
const MAX_IMAGES = 8;
const MAX_IMAGE_BYTES = 5 * 1024 * 1024; // API limit per image (decoded)
const IMAGE_TYPES = new Set(["image/png", "image/jpeg", "image/gif", "image/webp"]);
const MODELS = new Set(["default", "opus", "sonnet", "haiku"]);
const MODES = new Set(["default", "acceptEdits", "plan"]);

export function createChat({ loadConfig, log, notifyEditor = () => {} }) {
	/** site → session */
	const sessions = new Map();
	let seq = 0;

	const send = (socket, msg) => { if (socket.readyState === 1) socket.send(JSON.stringify(msg)); };

	function sessionFor(site) {
		let s = sessions.get(site);
		if (!s) {
			s = {
				site, proc: null, sessionId: null, model: "default", mode: "default",
				busy: false, pending: new Map(), clients: new Set(), log: [], logBytes: 0,
				idleTimer: null, stdoutBuf: "", pendingNote: "",
				awaitingUuid: [], turnUuids: new Map(), controls: new Map(),
			};
			sessions.set(site, s);
		}
		return s;
	}

	function broadcast(s, msg) { for (const c of s.clients) send(c, msg); }

	function record(s, e) {
		const size = JSON.stringify(e).length;
		s.log.push(e);
		s.logBytes += size;
		while (s.logBytes > LOG_LIMIT && s.log.length > 1) s.logBytes -= JSON.stringify(s.log.shift()).length;
	}

	/** Push an event to viewers; keep it for replay unless it's a streaming delta. */
	function emit(s, e, { keep = true } = {}) {
		if (keep) record(s, e);
		broadcast(s, { t: "ev", e });
	}

	function state(s) {
		return {
			t: "state",
			alive: !!s.proc,
			busy: s.busy,
			sessionId: s.sessionId,
			model: s.model,
			mode: s.mode,
			pending: [...s.pending.values()],
		};
	}

	function pushState(s) { broadcast(s, state(s)); }

	function touch(s) {
		clearTimeout(s.idleTimer);
		s.idleTimer = setTimeout(() => {
			if (s.proc && !s.busy && !s.pending.size) {
				log(`[${s.site}] chat idle: closing process (session ${s.sessionId} resumes on next message)`);
				stop(s);
			}
		}, IDLE_MS);
	}

	function write(s, obj) {
		if (s.proc && s.proc.stdin.writable) s.proc.stdin.write(JSON.stringify(obj) + "\n");
	}

	// ───────────────────────── Process ─────────────────────────

	function start(s) {
		const config = loadConfig();
		const siteConf = config.sites[s.site];
		if (!siteConf || !config.claudePath) throw new Error("Site not connected. Open Tools › Claude Code in WordPress and click Connect.");

		const args = [
			"-p", "--input-format", "stream-json", "--output-format", "stream-json", "--verbose",
			"--include-partial-messages", "--permission-prompts", "host", "--permission-prompt-tool", "stdio",
			"--replay-user-messages", // gives each user message Claude Code's own uuid, needed for file rewind
		];
		if (s.model !== "default") args.push("--model", s.model);
		if (s.mode !== "default") args.push("--permission-mode", s.mode);
		if (s.sessionId) args.push("--resume", s.sessionId);

		// A clean environment on purpose: inheriting another Claude Code session's variables changes its behaviour.
		const env = {
			HOME: process.env.HOME,
			USER: process.env.USER,
			LOGNAME: process.env.LOGNAME || process.env.USER,
			SHELL: process.env.SHELL || "/bin/zsh",
			PATH: config.path,
			LANG: process.env.LANG || "en_US.UTF-8",
			// File checkpoints, so "Restore to before this" can also rewind files Claude edited (verified 2.1.286).
			CLAUDE_CODE_ENABLE_SDK_FILE_CHECKPOINTING: "true",
		};
		const proc = spawn(config.claudePath, args, { cwd: siteConf.root, env, stdio: ["pipe", "pipe", "pipe"] });
		s.proc = proc;
		s.appliedModel = s.model;
		s.stdoutBuf = "";
		log(`[${s.site}] chat process started (pid ${proc.pid}${s.sessionId ? `, resuming ${s.sessionId}` : ""})`);

		proc.stdout.on("data", (chunk) => {
			s.stdoutBuf += chunk;
			let i;
			while ((i = s.stdoutBuf.indexOf("\n")) >= 0) {
				const line = s.stdoutBuf.slice(0, i);
				s.stdoutBuf = s.stdoutBuf.slice(i + 1);
				if (!line.trim()) continue;
				let e;
				try { e = JSON.parse(line); } catch (_) { continue; }
				onEvent(s, e);
			}
		});
		let stderr = "";
		proc.stderr.on("data", (d) => { stderr = (stderr + d).slice(-2000); });
		proc.on("exit", (code) => {
			if (s.proc !== proc) return;
			s.proc = null;
			s.busy = false;
			s.pending.clear();
			if (code) log(`[${s.site}] chat process exited ${code}: ${stderr.trim().split("\n").pop() || ""}`);
			emit(s, { type: "hub", subtype: "process_exit", code, stderr: code ? stderr.trim().slice(-500) : "" });
			pushState(s);
		});
		proc.stdin.on("error", () => {}); // EPIPE if it died mid-write; "exit" handles it
		pushState(s);
	}

	function stop(s) {
		const p = s.proc;
		s.proc = null;
		s.awaitingUuid = [];
		s.busy = false;
		s.pending.clear();
		if (p) try { p.kill(); } catch (_) {}
	}

	function onEvent(s, e) {
		switch (e.type) {
			case "control_request":
				if (e.request && e.request.subtype === "can_use_tool") {
					const req = { request_id: e.request_id, ...e.request };
					s.pending.set(e.request_id, req);
					emit(s, { type: "hub", subtype: "approval_request", request: req });
					pushState(s);
				} else {
					// Nothing else is supported from the host side yet; answer so Claude doesn't wait forever.
					write(s, { type: "control_response", response: { subtype: "error", request_id: e.request_id, error: `Unsupported request: ${e.request && e.request.subtype}` } });
				}
				return;
			case "control_response": {
				const r = e.response || {};
				const done = s.controls.get(r.request_id);
				if (done) { s.controls.delete(r.request_id); done(r); }
				return; // other acks: interrupt / set_model / set_permission_mode
			}
			case "user": {
				// --replay-user-messages echoes what we sent: map it to our turn, don't render it twice.
				const c = e.message && e.message.content;
				const isToolResult = Array.isArray(c) && c.some((b) => b.type === "tool_result");
				if (!isToolResult && e.uuid) {
					const turnId = s.awaitingUuid.shift();
					if (turnId) {
						s.turnUuids.set(turnId, e.uuid);
						emit(s, { type: "hub", subtype: "turn_uuid", turnId, uuid: e.uuid });
					}
					return;
				}
				break;
			}
			case "system":
				if (e.subtype === "commands_changed") return;
				if (e.subtype === "init") {
					s.sessionId = e.session_id;
					// The tool and slash-command lists are huge; keep what the UI needs.
					emit(s, { type: "system", subtype: "init", session_id: e.session_id, model: e.model, permissionMode: e.permissionMode, cwd: e.cwd });
					pushState(s);
					return;
				}
				break;
			case "stream_event": {
				// Thinking start/stop become replayable hub events, so "Thought for Ns" survives a reload.
				const ev = e.event || {};
				if (ev.type === "content_block_start" && ev.content_block && ev.content_block.type === "thinking") {
					s.thinkingStarted = Date.now();
					emit(s, { type: "hub", subtype: "thinking", phase: "start" });
				} else if (ev.type === "content_block_stop" && s.thinkingStarted) {
					emit(s, { type: "hub", subtype: "thinking", phase: "done", ms: Date.now() - s.thinkingStarted });
					s.thinkingStarted = 0;
				}
				emit(s, e, { keep: false });
				return;
			}
			case "result":
				s.busy = false;
				e._ts = Date.now(); // for the cache-warm countdown after a reload
				emit(s, e);
				applyModel(s);
				pushState(s);
				touch(s);
				return;
		}
		emit(s, e);
	}

	// ───────────────────────── Client messages ─────────────────────────

	function validImages(images) {
		if (!Array.isArray(images) || !images.length) return [];
		if (images.length > MAX_IMAGES) throw new Error(`At most ${MAX_IMAGES} images per message.`);
		return images.map((img) => {
			if (!img || !IMAGE_TYPES.has(img.media_type) || typeof img.data !== "string") throw new Error("Unsupported image.");
			if (Buffer.byteLength(img.data, "base64") > MAX_IMAGE_BYTES) throw new Error("Image larger than 5 MB.");
			return { type: "image", source: { type: "base64", media_type: img.media_type, data: img.data } };
		});
	}

	// Images dropped into CC Chat are also saved where lc_media_import can reach them.
	const INBOX_TTL_MS = 24 * 60 * 60 * 1000;
	const EXT = { "image/png": "png", "image/jpeg": "jpg", "image/gif": "gif", "image/webp": "webp" };

	function saveToInbox(site, turnId, images) {
		const root = (loadConfig().sites[site] || {}).root;
		const uploads = root && join(root, "wp-content", "uploads");
		if (!uploads || !existsSync(uploads)) return [];
		const inbox = join(uploads, "lccb-inbox");
		try {
			mkdirSync(inbox, { recursive: true });
			if (!existsSync(join(inbox, ".htaccess"))) writeFileSync(join(inbox, ".htaccess"), "Require all denied\n");
			if (!existsSync(join(inbox, "index.php"))) writeFileSync(join(inbox, "index.php"), "<?php // Silence.\n");
			for (const d of readdirSync(inbox)) { // housekeeping: drop day-old turns
				const full = join(inbox, d);
				try { if (statSync(full).isDirectory() && Date.now() - statSync(full).mtimeMs > INBOX_TTL_MS) rmSync(full, { recursive: true, force: true }); } catch (_) {}
			}
			const dir = join(inbox, turnId);
			mkdirSync(dir, { recursive: true });
			return images.map((img, i) => {
				const file = join(dir, `image-${i + 1}.${EXT[img.source.media_type] || "png"}`);
				writeFileSync(file, Buffer.from(img.source.data, "base64"));
				return file;
			});
		} catch (err) {
			log(`[${site}] couldn't save chat images to the inbox: ${err.message}`);
			return [];
		}
	}

	function onSend(s, msg) {
		const text = String(msg.text || "").trim();
		const images = validImages(msg.images);
		if (!text && !images.length) return;
		if (!s.proc) start(s);
		const turnId = `t${Date.now().toString(36)}${(++seq).toString(36)}`;
		const content = [...images, ...(text ? [{ type: "text", text }] : [])];
		const saved = images.length ? saveToInbox(s.site, turnId, images) : [];
		if (saved.length) {
			content.push({ type: "text", text: `[Note from the LiveCanvas bridge: the image${saved.length > 1 ? "s" : ""} above ${saved.length > 1 ? "are" : "is"} also saved at ${saved.join(", ")}. To use ${saved.length > 1 ? "them" : "it"} on the site, add ${saved.length > 1 ? "them" : "it"} to the media library with lc_media_import {path}.]` });
		}
		if (s.pendingNote) {
			// The user rolled the builder back since Claude last looked: say so, or it assumes its edits are still there.
			content.unshift({ type: "text", text: s.pendingNote });
			s.pendingNote = "";
		}
		write(s, { type: "user", message: { role: "user", content } });
		s.awaitingUuid.push(turnId);
		notifyEditor(s.site, { event: "turn", turnId, label: text.slice(0, 120) || "(image)", source: "chat" });
		// Claude doesn't echo user turns; record one for the transcript (images as small placeholders).
		const thumbs = Array.isArray(msg.thumbs) ? msg.thumbs : [];
		emit(s, {
			type: "hub", subtype: "user", text, turnId, _ts: Date.now(),
			images: images.map((img, i) => {
				const t = thumbs[i] || {};
				// Small JPEG data URLs only (the browser makes them ~160px); anything else is dropped.
				const thumb = typeof t.thumb === "string" && t.thumb.startsWith("data:image/jpeg;base64,") && t.thumb.length < 80000 ? t.thumb : null;
				return { media_type: img.source.media_type, bytes: Buffer.byteLength(img.source.data, "base64"), thumb, width: t.width | 0, height: t.height | 0 };
			}),
		});
		s.busy = true;
		touch(s);
		pushState(s);
	}

	function onApprove(s, msg) {
		const req = s.pending.get(msg.request_id);
		if (!req) return;
		s.pending.delete(msg.request_id);
		let response;
		if (msg.decision === "deny") {
			response = { behavior: "deny", message: String(msg.message || "").trim() || "The user denied this action." };
		} else {
			response = { behavior: "allow", updatedInput: req.input };
			if (msg.decision === "allow_session" && Array.isArray(req.permission_suggestions)) response.updatedPermissions = req.permission_suggestions;
		}
		write(s, { type: "control_response", response: { subtype: "success", request_id: req.request_id, response } });
		emit(s, { type: "hub", subtype: "approval_resolved", request_id: req.request_id, decision: msg.decision, message: response.message || "" });
		pushState(s);
	}

	function onSet(s, msg) {
		if (msg.model && MODELS.has(msg.model) && msg.model !== s.model) {
			s.model = msg.model;
			if (!s.busy) applyModel(s); // otherwise applied when the current turn finishes
		}
		if (msg.mode && MODES.has(msg.mode) && msg.mode !== s.mode) {
			s.mode = msg.mode;
			if (s.proc) write(s, { type: "control_request", request_id: `c${++seq}`, request: { subtype: "set_permission_mode", mode: msg.mode } });
		}
		pushState(s);
	}

	/** Bring a running process in line with s.model. */
	function applyModel(s) {
		if (!s.proc || s.appliedModel === s.model) return;
		if (s.model === "default") {
			stop(s); // there's no verified "unset model" request: the next message resumes without --model
		} else {
			write(s, { type: "control_request", request_id: `c${++seq}`, request: { subtype: "set_model", model: s.model } });
			s.appliedModel = s.model;
		}
	}

	function onNew(s) {
		stop(s);
		s.sessionId = null;
		s.log = [];
		s.logBytes = 0;
		broadcast(s, { t: "replay", events: [] });
		pushState(s);
	}

	// ───────────────────────── History (Claude Code's own session files) ─────────────────────────
	// ~/.claude/projects/<site root with every non-alphanumeric char as "-">/<session id>.jsonl
	// The format is Claude Code's and undocumented: read defensively, skip anything unexpected.

	function projectDir(site) {
		const root = (loadConfig().sites[site] || {}).root;
		return root ? join(homedir(), ".claude", "projects", root.replace(/[^a-zA-Z0-9]/g, "-")) : null;
	}

	function readHead(file, bytes) {
		const fd = openSync(file, "r");
		try {
			const buf = Buffer.alloc(bytes);
			const n = readSync(fd, buf, 0, bytes, 0);
			return buf.subarray(0, n).toString("utf8");
		} finally { closeSync(fd); }
	}

	function userText(entry) {
		if (!entry || entry.type !== "user" || entry.isMeta || entry.isSidechain) return "";
		const c = entry.message && entry.message.content;
		const text = typeof c === "string" ? c : Array.isArray(c) ? c.filter((b) => b.type === "text").map((b) => b.text).join("\n") : "";
		const t = text.trim();
		// Slash-command plumbing and hook/system injections aren't something the user typed.
		if (!t || t.startsWith("<command-") || t.startsWith("<local-command") || t.startsWith("<system-reminder") || t.startsWith("Caveat:")) return "";
		return t;
	}

	function listHistory(s) {
		const dir = projectDir(s.site);
		if (!dir || !existsSync(dir)) return [];
		const files = readdirSync(dir).filter((f) => /^[0-9a-f-]{36}\.jsonl$/.test(f)).map((f) => {
			const st = statSync(join(dir, f));
			return { id: f.slice(0, -6), file: join(dir, f), mtime: st.mtimeMs, size: st.size };
		}).sort((a, b) => b.mtime - a.mtime).slice(0, 30);
		return files.map((f) => {
			let title = "", summary = "", entrypoint = "";
			try {
				for (const line of readHead(f.file, 256 * 1024).split("\n")) {
					let j;
					try { j = JSON.parse(line); } catch (_) { continue; }
					if (j.type === "summary" && j.summary && !summary) summary = j.summary;
					if (!entrypoint && j.entrypoint) entrypoint = j.entrypoint;
					if (!title) title = userText(j);
					if (title && entrypoint) break;
				}
			} catch (_) { /* unreadable file: still list it */ }
			return { id: f.id, title: (summary || title || "(no messages)").replace(/\s+/g, " ").slice(0, 140), mtime: f.mtime, size: f.size, entrypoint, current: f.id === s.sessionId };
		}).filter((x) => x.title !== "(no messages)" || x.current);
	}

	/** Turn a session file into the same replay events the live stream produces (images become placeholders). */
	function transcript(file) {
		const events = [];
		let bytes = 0;
		for (const line of readFileSync(file, "utf8").split("\n")) {
			let j;
			try { j = JSON.parse(line); } catch (_) { continue; }
			if (j.isSidechain || !j.message) continue;
			if (j.type === "user") {
				const c = j.message.content;
				if (Array.isArray(c) && c.some((b) => b.type === "tool_result")) {
					const content = c.map((b) => (b.type === "tool_result" && Array.isArray(b.content) ? { ...b, content: b.content.map((x) => (x.type === "image" ? { type: "text", text: "[image]" } : x)) } : b));
					events.push({ type: "user", message: { role: "user", content } });
				} else {
					const text = userText(j);
					const images = Array.isArray(c) ? c.filter((b) => b.type === "image").map((b) => ({ media_type: b.source && b.source.media_type, bytes: 0, thumb: null })) : [];
					if (text || images.length) events.push({ type: "hub", subtype: "user", text, images });
				}
			} else if (j.type === "assistant") {
				const content = (j.message.content || []).filter((b) => b.type === "text" || b.type === "tool_use");
				if (content.length) events.push({ type: "assistant", message: { id: j.message.id, model: j.message.model, content, usage: j.message.usage } });
			}
		}
		// Keep the most recent part of very long sessions.
		const out = [];
		for (let i = events.length - 1; i >= 0; i--) {
			bytes += JSON.stringify(events[i]).length;
			if (bytes > LOG_LIMIT) break;
			out.unshift(events[i]);
		}
		if (out.length < events.length) out.unshift({ type: "hub", subtype: "note", text: `Earlier messages not shown (${events.length - out.length} more in this session).` });
		return out;
	}

	function onResume(s, msg) {
		const id = String(msg.sessionId || "");
		const dir = projectDir(s.site);
		if (!/^[0-9a-f-]{36}$/.test(id) || !dir || !existsSync(join(dir, id + ".jsonl"))) throw new Error("That session can't be found.");
		stop(s);
		s.sessionId = id;
		s.log = [];
		s.logBytes = 0;
		let events = [];
		try { events = transcript(join(dir, id + ".jsonl")); } catch (err) { events = [{ type: "hub", subtype: "note", text: "Couldn't read this session's earlier messages; it will still continue where it left off." }]; }
		for (const e of events) record(s, e);
		record(s, { type: "hub", subtype: "note", text: "Resumed. Your next message continues this conversation." });
		broadcast(s, { t: "replay", events: s.log });
		pushState(s);
	}

	// ───────────────────────── Public ─────────────────────────

	function accept(socket, site) {
		const s = sessionFor(site);
		s.clients.add(socket);
		send(socket, { t: "replay", events: s.log });
		send(socket, state(s));

		socket.on("message", (raw) => {
			let msg;
			try { msg = JSON.parse(raw.toString()); } catch (_) { return; }
			try {
				switch (msg.t) {
					case "send": onSend(s, msg); break;
					case "approve": onApprove(s, msg); break;
					case "interrupt":
						if (s.proc) write(s, { type: "control_request", request_id: `c${++seq}`, request: { subtype: "interrupt" } });
						break;
					case "set": onSet(s, msg); break;
					case "new": onNew(s); break;
					case "history": send(socket, { t: "history", items: listHistory(s) }); break;
					case "resume": onResume(s, msg); break;
					case "rewind_files": onRewindFiles(s, msg); break;
					case "site_note": {
						// The user undid a site-level change from the Activity popover: tell Claude next time.
						const text = String(msg.text || "").slice(0, 500);
						if (!text) break;
						s.pendingNote = (s.pendingNote ? s.pendingNote + " " : "") + `[Note from the LiveCanvas bridge: ${text} Re-read before editing that item again.]`;
						emit(s, { type: "hub", subtype: "note", text: text + " Claude will be told with your next message." });
						break;
					}
				}
			} catch (err) {
				send(socket, { t: "error", message: err.message });
			}
		});
		socket.on("close", () => s.clients.delete(socket));
	}

	/** Called by the hub's sweep with the currently registered sites (or [] when the token is gone). */
	function sweep(registered) {
		for (const [site, s] of sessions) {
			if (registered.includes(site)) continue;
			stop(s);
			for (const c of s.clients) try { c.close(); } catch (_) {}
			sessions.delete(site);
			log(`[${site}] no longer registered: closed its chat`);
		}
	}

	/** Roll files Claude edited back to before a message (Claude Code's own file checkpoints). */
	function onRewindFiles(s, msg) {
		const uuid = s.turnUuids.get(String(msg.turnId || ""));
		if (!uuid) throw new Error("No file checkpoint for that message (it was sent before this chat process started).");
		if (!s.proc) start(s); // resumes the same session; its file history lives with it
		const request_id = `rw${++seq}`;
		s.controls.set(request_id, (r) => {
			const ok = r.subtype === "success" && (!r.response || r.response.canRewind !== false);
			emit(s, { type: "hub", subtype: "note", text: ok ? "Files Claude edited were restored to before that message." : `Couldn't restore files: ${(r.error || (r.response && r.response.error) || "nothing to rewind").toString().slice(0, 200)}` });
			if (ok) s.pendingNote = (s.pendingNote ? s.pendingNote + " " : "") + "[Note from the LiveCanvas bridge: files you edited after that point were also restored to their earlier contents.]";
		});
		write(s, { type: "control_request", request_id, request: { subtype: "rewind_files", user_message_id: uuid } });
	}

	/** The bridge restored a checkpoint: show it in the chat and tell Claude with the next message. */
	function noteRestore(site, msg) {
		const s = sessionFor(site);
		const parts = (msg.parts || []).join(", ") || "page";
		const what = msg.label ? `to before "${String(msg.label).slice(0, 80)}"` : "to an earlier checkpoint";
		s.pendingNote = (s.pendingNote ? s.pendingNote + " " : "") +
			`[Note from the LiveCanvas bridge: the user restored the ${parts} ${what}. ` +
			`Changes made after that point were undone in the builder, so re-read anything you need (e.g. lc_read_html / lc_read_css) before editing again.]`;
		emit(s, { type: "hub", subtype: "note", text: `Restored ${parts} ${what}. Claude will be told with your next message.` });
	}

	function killAll() { for (const s of sessions.values()) stop(s); }

	function running() { return [...sessions].filter(([, s]) => s.proc).map(([site]) => site); }

	return { accept, sweep, killAll, running, noteRestore };
}
