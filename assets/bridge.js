/**
 * LC Claude Bridge — browser side.
 *
 * Connects the LiveCanvas editor to the local LC Claude Bridge hub (ws://127.0.0.1:<port>, role=editor) and executes
 * commands against LiveCanvas's own globals, so every change flows through the normal
 * editor pipeline (live preview, history/undo, Save):
 *   - HTML  → lc_html_editor (Ace); its change handler patches `doc` + the preview
 *   - CSS   → #wp-custom-css in `doc` + preview (or lc_css_editor when that tab is showing)
 *   - JS    → #lc_script_tag in `doc` + preview (or lc_js_editor when that tab is showing)
 */
(function () {
	"use strict";

	const config = window.lccbConfig || {};
	const PORT = config.port || 8770;
	const MAIN = "main#lc-main";
	const APPLY_DELAY = 350; // lc_html_editor's change handler is throttled at 100ms
	const BEAUTIFY_OPTS = { unformatted: ["script", "style"], indent_size: "1", indent_char: "\t" };

	let ws = null;
	let retryDelay = 1000;
	let superseded = false;
	let pill = null;

	// ───────────────────────── LiveCanvas helpers ─────────────────────────

	const getDoc = () => (window.lcMainStore && window.lcMainStore.getDoc()) || window.doc;
	const $win = () => window.jQuery("#lc-code-editor-window");
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	function editorIsVisible() {
		return $win().is(":visible");
	}

	function activeTab() {
		const tab = document.querySelector(".code-tabber [role='tab'].active");
		return tab ? tab.id.replace("-tab", "") : null; // html | css | js
	}

	function currentSelector() {
		return editorIsVisible() ? $win().attr("selector") || null : null;
	}

	function resolveSelector(selector) {
		const sel = (selector || currentSelector() || MAIN).trim();
		if (!getDoc().querySelector(sel)) throw new Error(`Selector not found in page: ${sel}`);
		return sel;
	}

	function beautify(html) {
		return typeof window.html_beautify === "function" ? window.html_beautify(html, BEAUTIFY_OPTS) : html;
	}

	function htmlEditorIsOn(selector) {
		return editorIsVisible() && activeTab() === "html" && $win().attr("selector") === selector;
	}

	// LiveCanvas edits main#lc-main by innerHTML and every other selector by outerHTML.
	function readHtml(selector) {
		if (htmlEditorIsOn(selector)) return window.lc_html_editor.getValue();
		return beautify(selector === MAIN ? window.getPageHTML(selector) : window.getPageHTMLOuter(selector));
	}

	function openHtmlEditorOn(selector) {
		if (htmlEditorIsOn(selector)) return;
		if (selector === MAIN) window.openMainHtmlCodeEditor();
		else window.openPartialHtmlEditor(selector);
	}

	// While the CC Terminal or CC Chat tab is showing, load the target into the (hidden) HTML editor
	// without switching tabs: the change handler only needs the selector attribute and a fresh snapshot.
	function claudeTabIsActive() {
		const tab = document.querySelector("#claude-tab.active, #chat-tab.active");
		return !!(tab && editorIsVisible());
	}

	function primeHtmlEditorQuietly(selector) {
		$win().attr("selector", selector);
		window.set_html_editor(selector === MAIN ? window.getPageHTML(selector) : window.getPageHTMLOuter(selector));
	}

	async function writeHtml(selector, html) {
		if (claudeTabIsActive()) primeHtmlEditorQuietly(selector);
		else openHtmlEditorOn(selector);
		// Plain setValue (not set_html_editor) so LiveCanvas's change handler applies it to doc + preview + history.
		window.lc_html_editor.session.setValue(html, 1);
		await sleep(APPLY_DELAY);
		const stillResolves = !!getDoc().querySelector(selector);
		return {
			selector,
			applied: true,
			note: stillResolves
				? "Applied live. Not saved yet."
				: `Applied, but selector "${selector}" no longer resolves (element tag/position changed). Call lc_get_context for fresh selectors.`,
		};
	}

	// Global CSS / JS live in <style id="wp-custom-css"> and <script id="lc_script_tag"> inside doc.
	const GLOBALS = {
		css: { node: "#wp-custom-css", editor: () => window.lc_css_editor },
		js: { node: "#lc_script_tag", editor: () => window.lc_js_editor },
	};

	function readGlobal(kind) {
		const g = GLOBALS[kind];
		if (editorIsVisible() && activeTab() === kind) return g.editor().getValue();
		return window.getPageHTML(g.node);
	}

	async function writeGlobal(kind, value) {
		const g = GLOBALS[kind];
		if (!getDoc().querySelector(g.node)) throw new Error(`${g.node} not found in page`);
		if (editorIsVisible() && activeTab() === kind) {
			// The tab is showing — set the editor and let its change handler update doc + preview.
			g.editor().session.setValue(value, 1);
		} else {
			// Same as the change handlers in livecanvas/editor/editor.js; the tab re-reads doc when opened.
			getDoc().querySelector(g.node).innerHTML = value;
			window.previewFrame.contents().find(g.node).html(value);
		}
		await sleep(50);
		return { applied: true, note: "Applied live. Not saved yet." + (kind === "js" ? " JS runs in the preview after a reload/save." : "") };
	}

	function replaceExact(text, oldStr, newStr, replaceAll) {
		if (!oldStr) throw new Error("old_string must not be empty");
		if (oldStr === newStr) throw new Error("old_string and new_string are identical");
		const count = text.split(oldStr).length - 1;
		if (count === 0) throw new Error("old_string not found. Read the current content first (whitespace/indentation must match exactly).");
		if (count > 1 && !replaceAll) throw new Error(`old_string matches ${count} times. Add surrounding context to make it unique, or set replace_all.`);
		return { text: replaceAll ? text.split(oldStr).join(newStr) : text.replace(oldStr, () => newStr), count: replaceAll ? count : 1 };
	}

	function outline() {
		const main = getDoc().querySelector(MAIN);
		if (!main) return [];
		return Array.from(main.children).map((el, i) => {
			const heading = el.querySelector("h1,h2,h3,h4");
			return {
				index: i,
				selector: window.CSSelectorForDoc ? window.CSSelectorForDoc(el) : null,
				tag: el.tagName.toLowerCase(),
				id: el.id || null,
				classes: el.className && typeof el.className === "string" ? el.className : null,
				heading: heading ? heading.textContent.trim().slice(0, 80) : null,
				chars: el.outerHTML.length,
			};
		});
	}

	// ───────────────────────── Commands ─────────────────────────

	const commands = {
		async context() {
			return {
				post: {
					id: window.lc_editor_current_post_id,
					title: window.lc_editor_current_post_page_title_tag,
					type: window.lc_editor_post_type,
					fragment: window.lc_editor_fragment_type || null,
					url: window.lc_editor_url_before_editor || null,
				},
				editor: {
					open: editorIsVisible(),
					activeTab: editorIsVisible() ? activeTab() : null,
					selector: currentSelector(),
					defaultHtmlTarget: currentSelector() || MAIN,
				},
				outline: outline(),
				globalCssChars: (window.getPageHTML(GLOBALS.css.node) || "").length,
				globalJsChars: (window.getPageHTML(GLOBALS.js.node) || "").length,
			};
		},

		async read_html({ selector }) {
			const sel = resolveSelector(selector);
			return { selector: sel, mode: sel === MAIN ? "innerHTML" : "outerHTML", html: readHtml(sel) };
		},

		async select({ selector }) {
			const sel = resolveSelector(selector);
			openHtmlEditorOn(sel);
			return { selector: sel, opened: true };
		},

		async write_html({ selector, html }) {
			return writeHtml(resolveSelector(selector), html);
		},

		async edit_html({ selector, old_string, new_string, replace_all }) {
			const sel = resolveSelector(selector);
			const { text, count } = replaceExact(readHtml(sel), old_string, new_string, replace_all);
			return { ...(await writeHtml(sel, text)), replacements: count };
		},

		async read_css() { return { css: readGlobal("css") }; },
		async write_css({ css }) { return writeGlobal("css", css); },
		async edit_css({ old_string, new_string, replace_all }) {
			const { text, count } = replaceExact(readGlobal("css"), old_string, new_string, replace_all);
			return { ...(await writeGlobal("css", text)), replacements: count };
		},

		async read_js() { return { js: readGlobal("js") }; },
		async write_js({ js }) { return writeGlobal("js", js); },
		async edit_js({ old_string, new_string, replace_all }) {
			const { text, count } = replaceExact(readGlobal("js"), old_string, new_string, replace_all);
			return { ...(await writeGlobal("js", text)), replacements: count };
		},

		/** Screenshot the preview for Claude, optionally at a temporary width. */
		async screenshot({ area = "visible", width, selector }) {
			const A = window.lccbChatAttach;
			if (!A) throw new Error("Screenshot support isn't loaded in the editor.");
			const iframe = document.getElementById("previewiframe");
			if (!iframe) throw new Error("The LiveCanvas preview isn't available.");
			if (area === "element" && !selector) throw new Error("area 'element' needs a selector.");
			const prev = { width: iframe.style.width, height: iframe.style.height, scroll: iframe.contentWindow.scrollY };
			const settle = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 450))));
			try {
				if (width) { iframe.style.width = width + "px"; await settle(); }
				if (selector && area !== "element") {
					const el = iframe.contentDocument.querySelector(selector);
					if (!el) throw new Error(`Nothing in the preview matches "${selector}".`);
					el.scrollIntoView({ block: "start" });
					await settle();
				}
				const img = area === "element" ? await A.captureElement(selector) : await A.capturePreview(area === "page" ? "page" : "visible");
				return {
					media_type: img.media_type, data: img.data, width: img.width, height: img.height,
					viewport: iframe.clientWidth,
					note: area === "page" ? "Whole page" : area === "element" ? `Element ${selector}` : "Visible area",
				};
			} finally {
				iframe.style.width = prev.width;
				iframe.style.height = prev.height;
				if (width || selector) { await settle(); iframe.contentWindow.scrollTo(0, prev.scroll); }
			}
		},

		/** Rendered-preview inspection: markup, box, computed styles, children, matching CSS rules. */
		async inspect({ selector, depth = 1, all = false }) {
			const iframe = document.getElementById("previewiframe");
			if (!iframe || !iframe.contentDocument) throw new Error("The LiveCanvas preview isn't available.");
			const pdoc = iframe.contentDocument;
			const pwin = iframe.contentWindow;
			let matches;
			try { matches = all ? [...pdoc.querySelectorAll(selector)].slice(0, 10) : [pdoc.querySelector(selector)].filter(Boolean); }
			catch (e) { throw new Error(`Invalid selector: ${selector}`); }
			if (!matches.length) throw new Error(`Nothing in the rendered preview matches "${selector}".`);
			const total = pdoc.querySelectorAll(selector).length;
			return {
				selector,
				matched: total,
				viewport: { width: iframe.clientWidth, height: iframe.clientHeight, scrollY: Math.round(pwin.scrollY) },
				elements: matches.map((el) => inspectElement(el, pwin, depth)),
				note: "rules are in stylesheet order; a later rule with equal or higher specificity wins, inline styles and !important beat both.",
			};
		},

		/** Navigate this builder tab to another page/partial/template's LiveCanvas editor. */
		async open_page({ url, title, discard }) {
			if (!url || !/^https?:\/\//.test(url) || new URL(url).origin !== location.origin) throw new Error("Can only open pages of this site.");
			const dirty = typeof window.original_document_html === "string" && window.original_document_html !== window.getPageHTML();
			if (dirty && !discard) {
				throw new Error("The builder has unsaved changes. Ask the user whether to save first (lc_save) or discard them (discard: true).");
			}
			if (dirty) window.original_document_html = window.getPageHTML(); // skip LiveCanvas's "leave without saving?" prompt
			setTimeout(() => { location.href = url; }, 400); // let this reply reach Claude first
			return { opening: title || url, url, discarded_unsaved_changes: !!dirty };
		},

		/**
		 * Rebuild the theme CSS with Picostrap's own in-browser compiler: load its compile URL in a hidden same-origin
		 * frame (admin cookies included), wait for it to save + redirect, surface compiler errors, then refresh the
		 * preview's stylesheet links so the new CSS shows without reloading the builder.
		 */
		async css_recompile({ url }) {
			if (!url || new URL(url).origin !== location.origin) throw new Error("Can only compile this site's CSS.");
			const started = Date.now();
			const frame = document.createElement("iframe");
			frame.setAttribute("aria-hidden", "true");
			frame.style.cssText = "position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0;visibility:hidden;";
			document.body.appendChild(frame);
			let feedback = "";
			try {
				await new Promise((resolve, reject) => {
					const deadline = setTimeout(() => reject(new Error("Picostrap's compiler didn't finish within 110s.")), 110000);
					const tick = setInterval(() => {
						let w;
						try { w = frame.contentWindow; } catch (_) { return; }
						if (!w || !w.document) return;
						const fb = w.document.getElementById("picosass-output-feedback");
						if (fb && fb.textContent.trim()) feedback = fb.textContent.trim().replace(/\s+/g, " ").slice(0, 600);
						if (/error/i.test(feedback)) { clearInterval(tick); clearTimeout(deadline); reject(new Error("SCSS compile error: " + feedback)); return; }
						// Picostrap saves the bundle, then redirects to the same URL without query args: that's "done".
						let search = "";
						try { search = w.location.search; } catch (_) {}
						if (w.document.readyState === "complete" && w.location.href !== "about:blank" && !/compile_sass/.test(search)) {
							clearInterval(tick); clearTimeout(deadline); resolve();
						}
					}, 500);
					frame.src = url;
				});
			} finally {
				frame.remove();
			}
			return { seconds: Math.round((Date.now() - started) / 1000), feedback, preview_refreshed: refreshPreviewCss() > 0 };
		},

		/** Re-fetch the theme's compiled CSS in the preview (after a recompile or a token undo). */
		async refresh_css() {
			return { refreshed: refreshPreviewCss() };
		},

		async save() {
			window.jQuery("#main-save").trigger("click");
			return { triggered: true, note: "Save triggered (HTML, Global CSS and Global JS)." };
		},
	};

	function refreshPreviewCss() {
		let n = 0;
		const iframe = document.getElementById("previewiframe");
		const pdoc = iframe && iframe.contentDocument;
		if (!pdoc) return 0;
		pdoc.querySelectorAll('link[rel="stylesheet"][href*="css-output/"]').forEach((link) => {
			const u = new URL(link.href);
			u.searchParams.set("lccb", Date.now());
			link.href = u.toString();
			n++;
		});
		return n;
	}

	// ───────────────────────── Inspect helpers ─────────────────────────

	const INSPECT_PROPS = [
		"display", "position", "box-sizing", "width", "height", "margin", "padding", "border", "border-radius",
		"color", "background-color", "background-image", "font-family", "font-size", "font-weight", "line-height",
		"letter-spacing", "text-transform", "text-align", "gap", "flex-direction", "justify-content", "align-items",
		"grid-template-columns", "opacity", "box-shadow", "z-index", "overflow", "transition",
	];

	function brief(el) {
		const r = el.getBoundingClientRect();
		return {
			tag: el.tagName.toLowerCase(),
			id: el.id || undefined,
			classes: typeof el.className === "string" && el.className.trim() ? el.className.trim() : undefined,
			text: (el.childElementCount ? "" : (el.textContent || "").trim().slice(0, 80)) || undefined,
			size: `${Math.round(r.width)}×${Math.round(r.height)}`,
		};
	}

	function childTree(el, depth) {
		if (depth <= 0) return undefined;
		const kids = [...el.children].filter((c) => !/^(script|style|template)$/i.test(c.tagName)).slice(0, 30);
		return kids.map((c) => ({ ...brief(c), children: childTree(c, depth - 1) }));
	}

	function matchingRules(el, win) {
		const out = [];
		const sources = new Set();
		const visit = (rules, source, media) => {
			for (const rule of rules) {
				if (out.length >= 40) return;
				if (rule.selectorText !== undefined && rule.style) {
					let hit = false;
					try { hit = el.matches(rule.selectorText); } catch (_) {}
					if (hit) out.push({ selector: rule.selectorText, source, media: media || undefined, css: rule.style.cssText.slice(0, 400) });
				} else if (rule.cssRules) {
					const cond = rule.conditionText || (rule.media && rule.media.mediaText) || "";
					if (rule.type === 4 /* MEDIA */ && cond && !win.matchMedia(cond).matches) continue;
					visit(rule.cssRules, source, cond || media);
				}
			}
		};
		for (const sheet of win.document.styleSheets) {
			const source = sheet.href ? sheet.href.split("/").slice(-2).join("/").split("?")[0] : `inline <style${sheet.ownerNode && sheet.ownerNode.id ? " id=" + sheet.ownerNode.id : ""}>`;
			try { visit(sheet.cssRules, source, ""); } catch (_) { sources.add(source); } // cross-origin sheet
		}
		return { rules: out, unreadable: [...sources] };
	}

	function inspectElement(el, win, depth) {
		const r = el.getBoundingClientRect();
		const cs = win.getComputedStyle(el);
		const computed = {};
		for (const p of INSPECT_PROPS) {
			const v = cs.getPropertyValue(p);
			if (v && v !== "none" && v !== "normal" && v !== "auto" && v !== "0px" && v !== "rgba(0, 0, 0, 0)") computed[p] = v;
		}
		const html = el.outerHTML;
		const m = matchingRules(el, win);
		return {
			...brief(el),
			box: { x: Math.round(r.left), y: Math.round(r.top + win.scrollY), width: Math.round(r.width), height: Math.round(r.height) },
			computed,
			inline_style: el.getAttribute("style") || undefined,
			rules: m.rules,
			unreadable_stylesheets: m.unreadable.length ? m.unreadable : undefined,
			children: childTree(el, depth),
			html: html.length > 20000 ? html.slice(0, 20000) + `\n<!-- … ${html.length - 20000} more characters -->` : html,
		};
	}

	// ───────────────────────── Checkpoints ─────────────────────────
	// Before the first change of each Claude reply, snapshot page HTML + Global CSS + Global JS, so the user
	// can roll the builder back to "before that message". Stored per page in IndexedDB (survives reloads).
	// Chat turns are announced by the hub ({event:"turn"}); changes from other sessions (e.g. CC Terminal)
	// start a new checkpoint after 90s without changes.

	const MUTATING = { edit_html: "html", write_html: "html", edit_css: "css", write_css: "css", edit_js: "js", write_js: "js" };
	const CP_LIMIT = 30;
	const CP_GAP_MS = 90 * 1000;
	const cp = { list: [], pendingTurn: null, current: null, lastChange: 0, listeners: new Set() };
	const cpKey = () => `${config.site}:${window.lc_editor_current_post_id}`;

	function idb() {
		return new Promise((resolve, reject) => {
			const req = indexedDB.open("lc-claude-bridge", 1);
			req.onupgradeneeded = () => req.result.createObjectStore("checkpoints");
			req.onsuccess = () => resolve(req.result);
			req.onerror = () => reject(req.error);
		});
	}

	async function cpLoad() {
		try {
			const db = await idb();
			cp.list = await new Promise((resolve) => {
				const r = db.transaction("checkpoints").objectStore("checkpoints").get(cpKey());
				r.onsuccess = () => resolve(Array.isArray(r.result) ? r.result : []);
				r.onerror = () => resolve([]);
			});
		} catch (_) { cp.list = []; }
		cpEmit();
	}

	async function cpSave() {
		try {
			const db = await idb();
			db.transaction("checkpoints", "readwrite").objectStore("checkpoints").put(cp.list, cpKey());
		} catch (_) { /* memory only */ }
	}

	function cpEmit() {
		const summary = cpPublicList();
		cp.listeners.forEach((fn) => { try { fn(summary); } catch (_) {} });
	}

	function cpPublicList() {
		return cp.list.map(({ id, ts, label, source, turnId, parts }) => ({ id, ts, label, source, turnId, parts: [...parts] }));
	}

	function snapshot() {
		return {
			html: window.getPageHTML(MAIN),
			css: window.getPageHTML(GLOBALS.css.node),
			js: window.getPageHTML(GLOBALS.js.node),
		};
	}

	function cpPush(entry) {
		cp.list.push(entry);
		while (cp.list.length > CP_LIMIT) cp.list.shift();
	}

	/** Called after a mutating command succeeded, with the snapshot taken just before it. */
	function cpRecord(part, before) {
		const now = Date.now();
		if (cp.pendingTurn || !cp.current || now - cp.lastChange > CP_GAP_MS) {
			const turn = cp.pendingTurn;
			cp.current = {
				id: "cp" + now.toString(36),
				ts: now,
				label: turn ? turn.label : "Changes from another Claude session (e.g. CC Terminal)",
				source: turn ? turn.source : "other",
				turnId: turn ? turn.turnId : null,
				parts: [],
				...before,
			};
			cpPush(cp.current);
			cp.pendingTurn = null;
		}
		if (!cp.current.parts.includes(part)) cp.current.parts.push(part);
		cp.lastChange = now;
		cpSave();
		cpEmit();
	}

	async function cpRestore(id) {
		const i = cp.list.findIndex((c) => c.id === id);
		if (i < 0) throw new Error("That checkpoint no longer exists.");
		const target = cp.list[i];
		// Everything changed from this checkpoint onwards goes back to how it was.
		const parts = [...new Set(cp.list.slice(i).flatMap((c) => c.parts))];
		const now = snapshot();
		if (!parts.some((p) => now[p] !== target[p])) return { restored: false, parts: [] };

		// Restoring is itself undoable.
		cpPush({ id: "cp" + Date.now().toString(36) + "r", ts: Date.now(), label: `Before restoring "${target.label}"`, source: "restore", turnId: null, parts, ...now });
		for (const p of parts) {
			if (p === "html") await writeHtml(MAIN, target.html);
			else await writeGlobal(p, target[p]);
		}
		cp.current = null;
		cp.pendingTurn = null;
		cpSave();
		cpEmit();
		const names = { html: "page HTML", css: "Global CSS", js: "Global JS" };
		send({ event: "checkpoint_restored", label: target.label, parts: parts.map((p) => names[p]) });
		return { restored: true, parts };
	}

	window.lccbBridge = { refreshCss: refreshPreviewCss };

	window.lccbCheckpoints = {
		list: cpPublicList,
		restore: cpRestore,
		onChange(fn) { cp.listeners.add(fn); fn(cpPublicList()); return () => cp.listeners.delete(fn); },
	};

	// ───────────────────────── Transport ─────────────────────────

	function send(msg) {
		if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(msg));
	}

	async function handle(msg) {
		const fn = commands[msg.cmd];
		if (!fn) return send({ id: msg.id, ok: false, error: `Unknown command: ${msg.cmd}` });
		setPill("busy", `Claude: ${msg.cmd.replace("_", " ")}…`);
		try {
			const part = MUTATING[msg.cmd];
			const before = part ? snapshot() : null;
			const result = await fn(msg.args || {});
			if (part) cpRecord(part, before);
			send({ id: msg.id, ok: true, result });
		} catch (err) {
			send({ id: msg.id, ok: false, error: err && err.message ? err.message : String(err) });
		} finally {
			setPill("on", "Claude connected");
		}
	}

	function connect() {
		if (superseded) return;
		setPill("off", "Claude: connecting…");
		try {
			ws = new WebSocket(`ws://127.0.0.1:${PORT}/?role=editor&site=${encodeURIComponent(config.site)}&token=${encodeURIComponent(config.token)}`);
		} catch (e) {
			return scheduleReconnect();
		}
		ws.onopen = () => {
			retryDelay = 1000;
			setPill("on", "Claude connected");
			send({ event: "hello", postId: window.lc_editor_current_post_id, title: window.lc_editor_current_post_page_title_tag });
		};
		ws.onmessage = (e) => {
			let msg;
			try { msg = JSON.parse(e.data); } catch (_) { return; }
			if (msg.event === "turn") {
				cp.pendingTurn = { turnId: msg.turnId, label: msg.label, source: msg.source };
				cp.current = null;
				return;
			}
			if (msg.event === "superseded") {
				superseded = true;
				setPill("off", "Claude: in use by another tab (click to reclaim)");
				return;
			}
			if (msg.id && msg.cmd) handle(msg);
		};
		ws.onclose = () => {
			ws = null;
			if (!superseded) scheduleReconnect();
		};
		ws.onerror = () => {}; // onclose follows
	}

	function scheduleReconnect() {
		setPill("off", "Claude: not connected");
		setTimeout(connect, retryDelay);
		retryDelay = Math.min(retryDelay * 1.6, 10000);
	}

	function watchSelection() {
		let last = null;
		const report = () => {
			const sel = currentSelector();
			if (sel !== last) {
				last = sel;
				send({ event: "selection", selector: sel });
			}
		};
		const win = document.getElementById("lc-code-editor-window");
		if (win) new MutationObserver(report).observe(win, { attributes: true, attributeFilter: ["selector", "style", "class"] });
	}

	// ───────────────────────── Status pill ─────────────────────────

	function setPill(state, text) {
		if (!pill) {
			pill = document.createElement("button");
			pill.type = "button";
			pill.className = "lccb-pill";
			pill.addEventListener("click", () => {
				if (superseded || !ws) {
					superseded = false;
					retryDelay = 1000;
					connect();
				}
			});
			const host = document.getElementById("top-toolbar");
			(host || document.body).appendChild(pill);
			if (!host) pill.classList.add("is-floating");
		}
		pill.dataset.state = state;
		pill.textContent = text;
		pill.title = `LC Claude Bridge hub → 127.0.0.1:${PORT} · site ${config.site}`;
	}

	// ───────────────────────── Boot ─────────────────────────

	function ready() {
		return window.jQuery && window.lc_html_editor && window.lc_css_editor && window.lc_js_editor &&
			window.previewFrame && getDoc() && typeof window.openPartialHtmlEditor === "function";
	}

	(function waitForEditor(tries) {
		if (ready()) {
			cpLoad();
			connect();
			watchSelection();
		} else if (tries < 240) {
			setTimeout(() => waitForEditor(tries + 1), 250);
		} else {
			console.warn("[LC Claude Bridge] LiveCanvas editor globals never became available.");
		}
	})(0);
})();
