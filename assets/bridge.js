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

		/**
		 * Check the open page at several widths: resize the preview, run layout detectors, capture it, and return
		 * the issues (grouped across widths) plus a composite image with the problems outlined.
		 */
		async responsive_check({ widths, selector, images = "composite", max_height = 2400 }) {
			const A = window.lccbChatAttach;
			if (!A) throw new Error("Screenshot support isn't loaded in the editor.");
			const iframe = document.getElementById("previewiframe");
			if (!iframe || !iframe.contentDocument) throw new Error("The LiveCanvas preview isn't available.");
			const list = (Array.isArray(widths) && widths.length ? widths : [390, 768, 1200, 1440]).map((w) => Math.max(320, Math.min(2560, w | 0))).slice(0, 6);
			if (selector && !iframe.contentDocument.querySelector(selector)) throw new Error(`Nothing in the preview matches "${selector}".`);
			const prev = { width: iframe.style.width, scroll: iframe.contentWindow.scrollY };
			const settle = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 600))));
			const runs = [];
			try {
				for (const w of list) {
					iframe.style.width = w + "px";
					iframe.contentWindow.scrollTo(0, 0);
					await settle();
					const pdoc = iframe.contentDocument;
					const root = selector ? pdoc.querySelector(selector) : pdoc.body;
					if (!root) throw new Error(`"${selector}" disappeared at ${w}px.`);
					const found = detectLayoutIssues(pdoc, root);
					let shot = null;
					if (images !== "none") {
						const origin = selector ? root.getBoundingClientRect() : { left: 0, top: 0 };
						const canvas = selector
							? await window.modernScreenshot.domToCanvas(root, { scale: 1, backgroundColor: "#ffffff", timeout: 15000 })
							: await A.renderPage(max_height);
						shot = { canvas, ox: origin.left, oy: origin.top + (selector ? iframe.contentWindow.scrollY : 0) };
					}
					runs.push({ width: w, viewport: iframe.clientWidth, found, shot });
				}
			} finally {
				iframe.style.width = prev.width;
				await settle();
				iframe.contentWindow.scrollTo(0, prev.scroll);
			}
			return summariseResponsive(runs, images, selector);
		},

		/** Screenshot a section of an original HTML template page (served same-origin from uploads/lccb-template-src). */
		async template_shot({ url, selector, width = 1200 }) {
			const { canvas, height } = await renderTemplateSection(url, selector, width);
			const img = await window.lccbChatAttach.prepareCanvas(canvas, "template-section.png", true);
			return { media_type: img.media_type, data: img.data, width: img.width, height: img.height, viewport: width, note: `Original template ${selector || "page"} (${Math.round(height)}px tall at ${width}px)` + (looksBlank(canvas) ? ". ⚠ " + BLANK_NOTE : "") };
		},

		/**
		 * Compare an original template section with its rebuilt version in the builder at the same width(s):
		 * side-by-side image (original | rebuilt | difference) and a pixel-difference score per width.
		 */
		async compare({ url, template_selector, preview_selector, widths }) {
			const iframe = document.getElementById("previewiframe");
			if (!iframe || !iframe.contentDocument) throw new Error("The LiveCanvas preview isn't available.");
			if (!iframe.contentDocument.querySelector(preview_selector)) throw new Error(`Nothing in the preview matches "${preview_selector}".`);
			const list = (Array.isArray(widths) && widths.length ? widths : [1200, 390]).map((w) => Math.max(320, Math.min(2560, w | 0))).slice(0, 3);
			const prev = { width: iframe.style.width, scroll: iframe.contentWindow.scrollY };
			const settle = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 600))));
			const results = [];
			// Show reveal-on-scroll content in its final state, as in the template render (removed again below).
			const freeze = iframe.contentDocument.createElement("style");
			freeze.id = "lccb-freeze";
			freeze.textContent = FREEZE_CSS;
			iframe.contentDocument.head.appendChild(freeze);
			try {
				for (const w of list) {
					const orig = await renderTemplateSection(url, template_selector, w);
					iframe.style.width = w + "px";
					await settle();
					const el = iframe.contentDocument.querySelector(preview_selector);
					if (!el) throw new Error(`"${preview_selector}" disappeared at ${w}px.`);
					const built = await window.modernScreenshot.domToCanvas(el, { scale: 1, backgroundColor: bgOf(el), timeout: 15000 });
					results.push({ width: w, blank: looksBlank(orig.canvas) ? "original" : looksBlank(built) ? "rebuilt" : null, ...diffCanvases(orig.canvas, built) });
				}
			} finally {
				freeze.remove();
				iframe.style.width = prev.width;
				await settle();
				iframe.contentWindow.scrollTo(0, prev.scroll);
			}
			const images = [];
			for (const r of results) {
				const img = await window.lccbChatAttach.prepareCanvas(r.composite, `compare-${r.width}.png`, true);
				images.push({ media_type: img.media_type, data: img.data, width: img.width, height: img.height, label: `${r.width}px` });
			}
			return {
				widths: results.map((r) => ({
					width: r.width,
					difference: `${r.score.toFixed(1)}%`,
					original_height: r.heightA,
					rebuilt_height: r.heightB,
					verdict: r.blank ? `unreliable: the ${r.blank} capture is blank` : r.score <= 5 ? "very close" : r.score <= 12 ? "close: check the highlighted areas" : r.score <= 25 ? "noticeably different" : "very different",
				})),
				images,
				note: "Each image is original | rebuilt | difference (red = pixels that differ after scaling both to the same width; height differences count too). Content you've deliberately changed (real text, photos) will differ: judge layout, spacing and type, not words.",
			};
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

	// ───────────────────────── Responsive check ─────────────────────────

	const SEVERITY = { overflow: "high", too_wide: "high", text_overlap: "high", small_text: "medium", tap_target: "medium", tap_target_touch: "low", image_overflow: "medium", image_no_size: "low", clipped: "low" };
	const ISSUE_TEXT = {
		overflow: "pushes past the right edge (the page scrolls sideways)",
		too_wide: "is wider than the viewport",
		text_overlap: "text overlaps other text",
		small_text: "text is smaller than 12px",
		tap_target: "tap target is under the 24×24 minimum (WCAG 2.5.8)",
		tap_target_touch: "tap target is under 44×44 on a phone (hard to hit with a thumb)",
		image_overflow: "image is wider than its container",
		image_no_size: "image has no width/height attributes (layout shift while it loads)",
		clipped: "content is cut off by overflow: hidden",
	};
	const PER_TYPE = 12;

	function selectorFor(el) {
		if (window.lccbPoint && window.lccbPoint.selectorFor) return window.lccbPoint.selectorFor(el);
		if (el.id) return "#" + el.id;
		const cls = typeof el.className === "string" ? el.className.trim().split(/\s+/)[0] : "";
		return el.tagName.toLowerCase() + (cls ? "." + cls : "");
	}

	function visible(el, win) {
		const r = el.getBoundingClientRect();
		if (r.width < 1 || r.height < 1) return false;
		const cs = win.getComputedStyle(el);
		return cs.visibility !== "hidden" && cs.opacity !== "0";
	}

	/** The line boxes of an element's own (direct) text: wrapped inline text gives one rect per line, not a union. */
	function ownTextRects(el, pdoc) {
		const rects = [];
		for (const n of el.childNodes) {
			if (n.nodeType !== 3 || !n.textContent.trim()) continue;
			const range = pdoc.createRange();
			range.selectNodeContents(n);
			for (const r of range.getClientRects()) if (r.width >= 1 && r.height >= 1) rects.push({ left: r.left, top: r.top, right: r.right, bottom: r.bottom });
		}
		return rects;
	}

	function unionRect(rects) {
		return rects.reduce((b, r) => ({ left: Math.min(b.left, r.left), top: Math.min(b.top, r.top), right: Math.max(b.right, r.right), bottom: Math.max(b.bottom, r.bottom) }));
	}

	/** How much two line boxes overlap, as a fraction of the smaller one. */
	function overlapFraction(a, b) {
		const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
		const hgt = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
		if (w < 4 || hgt < 4) return 0;
		return (w * hgt) / Math.min((a.right - a.left) * (a.bottom - a.top), (b.right - b.left) * (b.bottom - b.top));
	}

	// Things that deliberately overflow or clip (carousels, sliders, marquees, LiveCanvas UI).
	const INTENTIONAL = ".swiper, .swiper-wrapper, .carousel, .carousel-inner, .owl-carousel, .owl-stage, .splide, .slick-slider, .glide, .marquee, .lc-contextual-menu, #lc-interface, .offcanvas, .dropdown-menu, .modal, [aria-hidden='true']";

	function detectLayoutIssues(pdoc, root) {
		const win = pdoc.defaultView;
		const vw = pdoc.documentElement.clientWidth;
		const sy = win.scrollY;
		const issues = [];
		const add = (type, el, detail, rect) => {
			if (issues.filter((i) => i.type === type).length >= PER_TYPE) return;
			const r = rect || el.getBoundingClientRect();
			issues.push({ type, selector: selectorFor(el), detail, box: { x: Math.round(r.left), y: Math.round(r.top + sy), w: Math.round(r.right - r.left), h: Math.round(r.bottom - r.top) } });
		};
		const all = [root, ...root.querySelectorAll("*")].filter((el) => !/^(script|style|template|noscript|br|svg|path|g|defs|use)$/i.test(el.tagName) && !el.closest(INTENTIONAL));

		// Horizontal overflow: report the outermost offenders only.
		const pageOverflows = pdoc.documentElement.scrollWidth > vw + 1;
		const offenders = all.filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.right > vw + 1 || r.left < -1) && visible(el, win); });
		const outer = offenders.filter((el) => !offenders.some((o) => o !== el && o.contains(el)));
		for (const el of outer) {
			const r = el.getBoundingClientRect();
			if (r.width > vw + 1) add("too_wide", el, `${Math.round(r.width)}px wide in a ${vw}px viewport`);
			else if (pageOverflows) add("overflow", el, `reaches x ${Math.round(r.right)} in a ${vw}px viewport`);
		}
		if (pageOverflows && !outer.length) issues.push({ type: "overflow", selector: "html", detail: `page is ${pdoc.documentElement.scrollWidth}px wide in a ${vw}px viewport`, box: null });

		// Text: size and overlaps.
		const texts = [];
		for (const el of all) {
			const rects = ownTextRects(el, pdoc);
			if (!rects.length || !visible(el, win)) continue;
			const fs = parseFloat(win.getComputedStyle(el).fontSize);
			if (fs < 12) add("small_text", el, `${fs}px: "${el.textContent.trim().replace(/\s+/g, " ").slice(0, 40)}"`, unionRect(rects));
			if (texts.length < 600) texts.push({ el, rects, box: unionRect(rects) });
		}
		for (let i = 0; i < texts.length; i++) {
			for (let j = i + 1; j < texts.length; j++) {
				const a = texts[i], b = texts[j];
				if (overlapFraction(a.box, b.box) === 0 || a.el.contains(b.el) || b.el.contains(a.el)) continue;
				const hit = a.rects.some((ra) => b.rects.some((rb) => overlapFraction(ra, rb) > 0.25));
				if (hit) add("text_overlap", a.el, `overlaps ${selectorFor(b.el)} ("${b.el.textContent.trim().slice(0, 30)}")`, unionRect([a.box, b.box]));
			}
		}

		// Tap targets: under WCAG 2.5.8's 24×24 minimum at any width, and under the 44×44 touch guideline on phones.
		// Exempt, as in WCAG: inline links inside running text, and small targets with enough space around them
		// (a 24px circle on each one's centre touches no other target or circle).
		const phone = vw <= 480;
		const targets = [...root.querySelectorAll("a[href], button, input:not([type=hidden]), select, textarea, [role=button]")]
			.filter((el) => !el.closest(INTENTIONAL) && visible(el, win))
			.map((el) => { const r = el.getBoundingClientRect(); return { el, r, cx: (r.left + r.right) / 2, cy: (r.top + r.bottom) / 2, min: Math.min(r.width, r.height) }; });
		const distToRect = (x, y, r) => Math.hypot(Math.max(r.left - x, 0, x - r.right), Math.max(r.top - y, 0, y - r.bottom));
		const spaced = (t) => targets.every((o) => o === t || o.el.contains(t.el) || t.el.contains(o.el) ||
			(distToRect(t.cx, t.cy, o.r) >= 12 && (o.min >= 24 || Math.hypot(t.cx - o.cx, t.cy - o.cy) >= 24)));
		for (const t of targets) {
			if (t.min >= 44 || (t.min >= 24 && !phone)) continue;
			const cs = win.getComputedStyle(t.el);
			const parentText = t.el.parentElement ? (t.el.parentElement.textContent || "").trim().length : 0;
			if (cs.display === "inline" && parentText > (t.el.textContent || "").trim().length + 20) continue;
			if (t.min < 24 && spaced(t)) { if (phone) add("tap_target_touch", t.el, `${Math.round(t.r.width)}×${Math.round(t.r.height)} (spaced out, so it passes the 24px minimum)`); continue; }
			add(t.min < 24 ? "tap_target" : "tap_target_touch", t.el, `${Math.round(t.r.width)}×${Math.round(t.r.height)}`);
		}

		// Images.
		for (const img of root.querySelectorAll("img")) {
			if (img.closest(INTENTIONAL) || !visible(img, win)) continue;
			const r = img.getBoundingClientRect();
			const parent = img.parentElement;
			if (parent && r.width > parent.clientWidth + 1 && parent.clientWidth > 0) add("image_overflow", img, `${Math.round(r.width)}px in a ${parent.clientWidth}px container`);
			if (!img.getAttribute("width") || !img.getAttribute("height")) add("image_no_size", img, (img.getAttribute("src") || "").split("/").pop().slice(0, 60));
		}

		// Content clipped by overflow: hidden.
		for (const el of all) {
			const cs = win.getComputedStyle(el);
			if (!/hidden|clip/.test(cs.overflowX + cs.overflowY) || cs.textOverflow === "ellipsis" || el === pdoc.body) continue;
			if (/^(img|video|iframe|picture|input|textarea|select)$/i.test(el.tagName) || !visible(el, win)) continue;
			if (el.closest(".visually-hidden, .visually-hidden-focusable, .sr-only, .screen-reader-text") || el.clientWidth <= 2 || el.clientHeight <= 2) continue;
			const cutX = /hidden|clip/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 2;
			const cutY = /hidden|clip/.test(cs.overflowY) && el.scrollHeight > el.clientHeight + 2;
			if ((cutX || cutY) && (el.textContent || "").trim()) add("clipped", el, `${cutX ? `${el.scrollWidth - el.clientWidth}px wider` : ""}${cutX && cutY ? ", " : ""}${cutY ? `${el.scrollHeight - el.clientHeight}px taller` : ""} than its box`);
		}
		return { viewport: vw, page_width: pdoc.documentElement.scrollWidth, page_height: pdoc.documentElement.scrollHeight, issues };
	}

	async function summariseResponsive(runs, images, selector) {
		const A = window.lccbChatAttach;
		// Group the same problem across widths.
		const grouped = new Map();
		runs.forEach((run) => run.found.issues.forEach((iss, k) => {
			const key = iss.type + "|" + iss.selector;
			if (!grouped.has(key)) grouped.set(key, { type: iss.type, severity: SEVERITY[iss.type], selector: iss.selector, problem: ISSUE_TEXT[iss.type], at: [] });
			grouped.get(key).at.push({ width: run.width, detail: iss.detail });
			iss.n = k + 1;
		}));
		const order = { high: 0, medium: 1, low: 2 };
		const issues = [...grouped.values()].sort((a, b) => order[a.severity] - order[b.severity] || b.at.length - a.at.length);
		const out = {
			scope: selector || "whole page",
			widths: runs.map((r) => ({ width: r.width, issues: r.found.issues.length, page_height: r.found.page_height, sideways_scroll: r.found.page_width > r.found.viewport })),
			issues,
			ok: !issues.some((i) => i.severity === "high"),
			note: issues.length
				? "Issue boxes are outlined in red and numbered per width in the image. Selectors are for the rendered preview (lc_inspect); map them to builder selectors with lc_get_context/lc_read_html before editing."
				: "No layout problems detected. Still look at the image: detectors can't judge spacing or visual balance.",
			images: [],
		};
		if (images === "none") return out;

		const outline = (ctx, run, scale, dx, dy) => {
			ctx.save();
			ctx.lineWidth = 2;
			ctx.font = "bold 12px -apple-system, Helvetica, sans-serif";
			run.found.issues.forEach((iss) => {
				if (!iss.box) return;
				const x = dx + (iss.box.x - run.shot.ox) * scale, y = dy + (iss.box.y - run.shot.oy) * scale;
				ctx.strokeStyle = SEVERITY[iss.type] === "high" ? "#e11d48" : SEVERITY[iss.type] === "medium" ? "#f59e0b" : "#3b82f6";
				ctx.strokeRect(x, y, Math.max(4, iss.box.w * scale), Math.max(4, iss.box.h * scale));
				ctx.fillStyle = ctx.strokeStyle;
				ctx.fillRect(x, y - 14, 18, 14);
				ctx.fillStyle = "#fff";
				ctx.fillText(String(iss.n), x + 3, y - 3);
			});
			ctx.restore();
		};

		if (images === "each") {
			for (const run of runs) {
				const c = run.shot.canvas;
				const ctx = c.getContext("2d");
				outline(ctx, run, 1, 0, 0);
				const img = await A.prepareCanvas(c, `responsive-${run.width}.png`, true);
				out.images.push({ media_type: img.media_type, data: img.data, width: img.width, height: img.height, label: `${run.width}px` });
			}
			return out;
		}

		// Composite: one column per width, each scaled to the same column width, labelled.
		const COL = 360, GAP = 16, HEAD = 26, MAX_H = 1500;
		const cols = runs.map((run) => {
			const scale = COL / run.shot.canvas.width;
			return { run, scale, h: Math.min(MAX_H, Math.round(run.shot.canvas.height * scale)) };
		});
		const canvas = document.createElement("canvas");
		canvas.width = cols.length * COL + (cols.length - 1) * GAP;
		canvas.height = HEAD + Math.max(...cols.map((c) => c.h));
		const ctx = canvas.getContext("2d");
		ctx.fillStyle = "#f1f1f1";
		ctx.fillRect(0, 0, canvas.width, canvas.height);
		cols.forEach((c, i) => {
			const x = i * (COL + GAP);
			ctx.drawImage(c.run.shot.canvas, 0, 0, c.run.shot.canvas.width, c.h / c.scale, x, HEAD, COL, c.h);
			outline(ctx, c.run, c.scale, x, HEAD);
			ctx.fillStyle = c.run.found.issues.length ? "#9f1239" : "#166534";
			ctx.font = "bold 14px -apple-system, Helvetica, sans-serif";
			const n = c.run.found.issues.length;
			ctx.fillText(`${c.run.width}px · ${n ? n + " issue" + (n > 1 ? "s" : "") : "no issues"}${c.run.shot.canvas.height * c.scale > MAX_H ? " · top of page" : ""}`, x + 2, 18);
		});
		const img = await A.prepareCanvas(canvas, "responsive-check.png", true);
		out.images.push({ media_type: img.media_type, data: img.data, width: img.width, height: img.height, label: runs.map((r) => r.width + "px").join(" · ") });
		return out;
	}

	// ───────────────────────── HTML templates: render + compare ─────────────────────────

	function bgOf(el) {
		const win = el.ownerDocument.defaultView;
		for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
			const bg = win.getComputedStyle(n).backgroundColor;
			if (bg && bg !== "rgba(0, 0, 0, 0)" && bg !== "transparent") return bg;
		}
		return "#ffffff";
	}

	// Scroll-triggered animations would leave sections blank in a hidden frame: show everything in its final state.
	const FREEZE_CSS = `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition:none!important}
[data-aos],.aos-init,.wow,.appear-animation,[data-appear-animation],.animated,.reveal,.sc-reveal,[data-sal]{opacity:1!important;visibility:visible!important;transform:none!important}
.owl-carousel:not(.owl-loaded),.slick-slider:not(.slick-initialized){display:block!important;opacity:1!important;visibility:visible!important}
.owl-carousel:not(.owl-loaded)>:not(:first-child),.slick-slider:not(.slick-initialized)>:not(:first-child){display:none!important}
.owl-carousel,[class*="owl-carousel-"][class*="-init"]{opacity:1!important}
.owl-carousel .owl-stage>.owl-item:first-child:not(.cloned),.owl-carousel .owl-item.active,.carousel-item:first-child,.swiper-slide:first-child{display:block!important;visibility:visible!important;opacity:1!important}`;

	/** True when a capture is (almost) one flat colour: the content was hidden, not rendered. */
	function looksBlank(canvas) {
		const W = 60, H = Math.max(1, Math.round((canvas.height * W) / Math.max(1, canvas.width)));
		const c = document.createElement("canvas");
		c.width = W;
		c.height = H;
		const ctx = c.getContext("2d");
		ctx.drawImage(canvas, 0, 0, W, H);
		const d = ctx.getImageData(0, 0, W, H).data;
		let min = 255, max = 0;
		for (let i = 0; i < d.length; i += 4) {
			const v = (d[i] + d[i + 1] + d[i + 2]) / 3;
			if (v < min) min = v;
			if (v > max) max = v;
		}
		return max - min < 12;
	}
	const BLANK_NOTE = "This capture is blank: its content is probably hidden until a script or scroll animation runs. Don't trust it; check the other screenshot tools, or compare a different section.";

	async function renderTemplateSection(url, selector, width) {
		if (!window.modernScreenshot) throw new Error("Screenshot library missing.");
		if (!url || new URL(url, location.href).origin !== location.origin) throw new Error("Template pages must be served from this site (uploads/lccb-template-src).");
		const frame = document.createElement("iframe");
		frame.setAttribute("aria-hidden", "true");
		frame.style.cssText = `position:fixed;left:-${width + 4000}px;top:0;width:${width}px;height:900px;border:0;pointer-events:none;`;
		document.body.appendChild(frame);
		try {
			await new Promise((resolve, reject) => {
				const t = setTimeout(() => reject(new Error("The template page didn't load within 30s.")), 30000);
				frame.onload = () => { clearTimeout(t); resolve(); };
				frame.src = url;
			});
			const doc = frame.contentDocument;
			if (!doc) throw new Error("Couldn't read the template page.");
			const style = doc.createElement("style");
			style.textContent = FREEZE_CSS;
			doc.head.appendChild(style);
			doc.querySelectorAll("img").forEach((img) => {
				img.loading = "eager";
				const lazy = img.getAttribute("data-src") || img.getAttribute("data-lazy-src");
				if (lazy && !img.getAttribute("src")) img.src = lazy;
			});
			const el = selector ? doc.querySelector(selector) : doc.body;
			if (!el) throw new Error(`Nothing on the template page matches "${selector}".`);
			el.scrollIntoView({ block: "start" }); // wakes up IntersectionObserver-driven content near it
			const imgs = [...el.querySelectorAll("img")].filter((i) => !i.complete);
			await Promise.race([
				Promise.all([doc.fonts ? doc.fonts.ready : null, ...imgs.map((i) => new Promise((r) => { i.onload = i.onerror = r; }))]),
				new Promise((r) => setTimeout(r, 6000)),
			]);
			await new Promise((r) => setTimeout(r, 500));
			const height = el.getBoundingClientRect().height;
			const shot = await window.modernScreenshot.domToCanvas(el, { scale: 1, backgroundColor: bgOf(el), timeout: 20000 });
			// The capture's canvas belongs to the frame's document and goes blank once the frame is removed: copy it out.
			const canvas = document.createElement("canvas");
			canvas.width = shot.width;
			canvas.height = shot.height;
			canvas.getContext("2d").drawImage(shot, 0, 0);
			return { canvas, height };
		} finally {
			frame.remove();
		}
	}

	/** Pixel difference after scaling both to the same width (missing height counts as different), plus a composite. */
	function diffCanvases(a, b) {
		const W = 300;
		const ha = Math.max(1, Math.round((a.height * W) / a.width));
		const hb = Math.max(1, Math.round((b.height * W) / b.width));
		const H = Math.max(ha, hb);
		const draw = (src, h) => {
			const c = document.createElement("canvas");
			c.width = W;
			c.height = H;
			const ctx = c.getContext("2d");
			ctx.fillStyle = "#ffffff";
			ctx.fillRect(0, 0, W, H);
			ctx.drawImage(src, 0, 0, W, h);
			return ctx.getImageData(0, 0, W, H);
		};
		const da = draw(a, ha).data, db = draw(b, hb).data;
		const mask = new Uint8Array(W * H);
		let diff = 0;
		for (let i = 0, p = 0; i < da.length; i += 4, p++) {
			const d = Math.max(Math.abs(da[i] - db[i]), Math.abs(da[i + 1] - db[i + 1]), Math.abs(da[i + 2] - db[i + 2]));
			if (d > 48) { mask[p] = 1; diff++; }
		}
		// Composite: three columns at a readable size.
		const COL = Math.min(520, Math.max(a.width, b.width)), GAP = 12, HEAD = 24;
		const sa = COL / a.width, sb = COL / b.width;
		const colH = Math.min(1500, Math.max(a.height * sa, b.height * sb));
		const out = document.createElement("canvas");
		out.width = COL * 3 + GAP * 2;
		out.height = HEAD + colH;
		const ctx = out.getContext("2d");
		ctx.fillStyle = "#f1f1f1";
		ctx.fillRect(0, 0, out.width, out.height);
		ctx.drawImage(a, 0, HEAD, COL, a.height * sa);
		ctx.drawImage(b, COL + GAP, HEAD, COL, b.height * sb);
		// Difference: the rebuilt version faded, with differing pixels in red.
		const heat = document.createElement("canvas");
		heat.width = W;
		heat.height = H;
		const hctx = heat.getContext("2d");
		const hd = hctx.createImageData(W, H);
		for (let p = 0; p < mask.length; p++) {
			const g = 255 - (255 - (db[p * 4] + db[p * 4 + 1] + db[p * 4 + 2]) / 3) * 0.25;
			hd.data[p * 4] = mask[p] ? 225 : g;
			hd.data[p * 4 + 1] = mask[p] ? 29 : g;
			hd.data[p * 4 + 2] = mask[p] ? 72 : g;
			hd.data[p * 4 + 3] = 255;
		}
		hctx.putImageData(hd, 0, 0);
		ctx.drawImage(heat, 0, 0, W, H, (COL + GAP) * 2, HEAD, COL, (H * COL) / W);
		ctx.font = "bold 13px -apple-system, Helvetica, sans-serif";
		ctx.fillStyle = "#333";
		const score = (diff / (W * H)) * 100;
		ctx.fillText("Original", 2, 16);
		ctx.fillText("Rebuilt", COL + GAP + 2, 16);
		ctx.fillStyle = score <= 12 ? "#166534" : "#9f1239";
		ctx.fillText(`Difference ${score.toFixed(1)}%`, (COL + GAP) * 2 + 2, 16);
		return { score, composite: out, heightA: Math.round(a.height), heightB: Math.round(b.height) };
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
