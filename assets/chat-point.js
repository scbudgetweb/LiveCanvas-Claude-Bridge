/**
 * LC Claude Bridge — point at it, ask about it.
 *
 * Ask mode lets the user click an element (or drag a box) in the LiveCanvas preview to attach it to a CC Chat message:
 * a cropped screenshot plus the element's selectors, tag, classes and an excerpt of its rendered HTML.
 * Alt+click in the preview does the same without turning Ask mode on.
 *
 * The highlight lives in the editor document, over the preview iframe, so nothing is ever added to the page itself.
 * Exposes window.lccbPoint = { start(onPick), stop(), active(), describe(el), onAltPick(fn) }.
 * onPick receives a promise of { image, pointer } (image as from lccbChatAttach).
 */
(function () {
	"use strict";

	const HTML_EXCERPT = 3000;
	const DRAG_MIN = 8; // px before a press becomes a region drag

	let on = false;
	let pickCb = null;
	let altCb = null;
	let hover = null;
	let box = null;
	let label = null;
	let drag = null;

	const frame = () => document.getElementById("previewiframe");

	function ensureOverlay() {
		if (box) return;
		box = document.createElement("div");
		box.className = "lccb-point-box";
		label = document.createElement("div");
		label.className = "lccb-point-label";
		box.append(label);
		document.body.append(box);
	}

	/** Place the highlight over a rect given in the preview's viewport coordinates. */
	function place(rect, text) {
		ensureOverlay();
		const f = frame();
		if (!f || !rect) return hide();
		const fr = f.getBoundingClientRect();
		const left = Math.max(fr.left, fr.left + rect.left);
		const top = Math.max(fr.top, fr.top + rect.top);
		const right = Math.min(fr.right, fr.left + rect.left + rect.width);
		const bottom = Math.min(fr.bottom, fr.top + rect.top + rect.height);
		if (right <= left || bottom <= top) return hide();
		Object.assign(box.style, { display: "block", left: left + "px", top: top + "px", width: right - left + "px", height: bottom - top + "px" });
		label.textContent = text || "";
		label.hidden = !text;
		label.classList.toggle("is-below", top - fr.top < 22);
	}

	function hide() {
		if (box) box.style.display = "none";
	}

	// ───────────────────────── Describing an element ─────────────────────────

	const cssEsc = (s) => (window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/[^\w-]/g, "\\$&"));

	/** A short selector that is unique in the rendered preview: nearest id, then tag:nth-of-type steps. */
	function previewSelector(el) {
		const doc = el.ownerDocument;
		const parts = [];
		let node = el;
		while (node && node.nodeType === 1 && node !== doc.documentElement) {
			if (node.id && doc.querySelectorAll("#" + cssEsc(node.id)).length === 1) {
				parts.unshift("#" + cssEsc(node.id));
				break;
			}
			const tag = node.tagName.toLowerCase();
			let step = tag;
			const cls = typeof node.className === "string" ? node.className.trim().split(/\s+/).filter((c) => c && !/^lc-|^is-|^active$|^show$/.test(c))[0] : "";
			if (cls) step += "." + cssEsc(cls);
			const parent = node.parentElement;
			if (parent) {
				const same = [...parent.children].filter((c) => c.tagName === node.tagName);
				if (same.length > 1) step = `${tag}:nth-of-type(${same.indexOf(node) + 1})` + (cls ? "." + cssEsc(cls) : "");
			}
			parts.unshift(step);
			if (doc.querySelectorAll(parts.join(" > ")).length === 1 && parts.length > 1) break;
			node = parent;
		}
		return parts.join(" > ");
	}

	/** The selector in LiveCanvas's own page model (what lc_read_html / lc_edit_html take), when it maps cleanly. */
	function docSelector(el) {
		try {
			const sel = typeof window.CSSelectorForDoc === "function" ? window.CSSelectorForDoc(el) : null;
			const doc = (window.lcMainStore && window.lcMainStore.getDoc()) || window.doc;
			if (!sel || !doc) return null;
			const match = doc.querySelector(sel);
			return match && match.tagName === el.tagName && /^main#lc-main/.test(sel) ? sel : null;
		} catch (_) {
			return null;
		}
	}

	function region(el) {
		if (el.closest("header, #lc-header, .lc-header")) return "header";
		if (el.closest("footer, #lc-footer, .lc-footer")) return "footer";
		if (el.closest("main#lc-main")) return "page";
		return "outside the page content";
	}

	function describe(el) {
		const win = el.ownerDocument.defaultView;
		const r = el.getBoundingClientRect();
		const cs = win.getComputedStyle(el);
		let html = el.outerHTML;
		if (html.length > HTML_EXCERPT) html = html.slice(0, HTML_EXCERPT) + `\n<!-- … ${html.length - HTML_EXCERPT} more characters -->`;
		const section = el.closest("main#lc-main > *");
		const heading = section && section.querySelector("h1,h2,h3,h4");
		return {
			kind: "element",
			preview_selector: previewSelector(el),
			doc_selector: docSelector(el),
			section_selector: section ? docSelector(section) : null,
			section_heading: heading ? heading.textContent.trim().slice(0, 80) : null,
			region: region(el),
			tag: el.tagName.toLowerCase(),
			classes: typeof el.className === "string" && el.className.trim() ? el.className.trim() : null,
			text: (el.textContent || "").trim().replace(/\s+/g, " ").slice(0, 160) || null,
			size: `${Math.round(r.width)}×${Math.round(r.height)}`,
			font: `${cs.fontSize} ${cs.fontWeight} ${cs.fontFamily.split(",")[0].replace(/["']/g, "")}`,
			viewport: win.innerWidth,
			html,
		};
	}

	function shortLabel(el) {
		const cls = typeof el.className === "string" ? el.className.trim().split(/\s+/).slice(0, 2).join(".") : "";
		const r = el.getBoundingClientRect();
		return `${el.tagName.toLowerCase()}${el.id ? "#" + el.id : ""}${cls ? "." + cls : ""}  ${Math.round(r.width)}×${Math.round(r.height)}`;
	}

	// ───────────────────────── Capturing ─────────────────────────

	async function capture(el) {
		const A = window.lccbChatAttach;
		return { image: await A.captureNode(el, "pointed-element.png"), pointer: describe(el) };
	}

	async function captureRegion(rect, pdoc) {
		const A = window.lccbChatAttach;
		const win = pdoc.defaultView;
		const image = await A.captureRect({ x: rect.left, y: rect.top + win.scrollY, width: rect.width, height: rect.height });
		// What's in the box: the elements that sit mostly inside it, outermost first.
		const inside = [];
		const seen = new Set();
		const step = 24;
		for (let y = rect.top + 4; y < rect.top + rect.height; y += step) {
			for (let x = rect.left + 4; x < rect.left + rect.width; x += step) {
				let el = pdoc.elementFromPoint(x, y);
				while (el && el.parentElement && el.parentElement !== pdoc.body) {
					const pr = el.parentElement.getBoundingClientRect();
					if (pr.left < rect.left - 4 || pr.top < rect.top - 4 || pr.right > rect.left + rect.width + 4 || pr.bottom > rect.top + rect.height + 4) break;
					el = el.parentElement;
				}
				if (el && !seen.has(el) && el !== pdoc.body && el !== pdoc.documentElement) { seen.add(el); inside.push(el); }
			}
		}
		const top = inside.filter((el) => !inside.some((o) => o !== el && o.contains(el))).slice(0, 6);
		return {
			image,
			pointer: {
				kind: "region",
				box: `${Math.round(rect.width)}×${Math.round(rect.height)} at x ${Math.round(rect.left)}, y ${Math.round(rect.top + win.scrollY)} (page coordinates)`,
				viewport: win.innerWidth,
				elements: top.map((el) => ({ preview_selector: previewSelector(el), doc_selector: docSelector(el), tag: el.tagName.toLowerCase(), classes: typeof el.className === "string" ? el.className.trim() || null : null, text: (el.textContent || "").trim().replace(/\s+/g, " ").slice(0, 80) || null })),
			},
		};
	}

	// ───────────────────────── Preview listeners ─────────────────────────

	function target(e) {
		const el = e.target && e.target.nodeType === 1 ? e.target : e.target && e.target.parentElement;
		if (!el || el === el.ownerDocument.documentElement || el === el.ownerDocument.body) return null;
		if (el.closest(".lc-contextual-menu, #lc-interface")) return null;
		return el;
	}

	function swallow(e) {
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
	}

	function onMove(e) {
		if (drag) {
			const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
			if (!drag.moved && Math.hypot(dx, dy) > DRAG_MIN) drag.moved = true;
			if (drag.moved) {
				drag.rect = { left: Math.min(e.clientX, drag.x), top: Math.min(e.clientY, drag.y), width: Math.abs(dx), height: Math.abs(dy) };
				place(drag.rect, `${Math.round(drag.rect.width)}×${Math.round(drag.rect.height)}`);
				box.classList.add("is-region");
			}
			return swallow(e);
		}
		if (!on && !e.altKey) { if (hover) { hover = null; hide(); } return; }
		const el = target(e);
		if (el === hover) return;
		hover = el;
		if (el) { box && box.classList.remove("is-region"); place(el.getBoundingClientRect(), shortLabel(el)); }
		else hide();
	}

	function onDown(e) {
		if (!on || e.button !== 0) return;
		drag = { x: e.clientX, y: e.clientY, moved: false, rect: null };
		swallow(e);
	}

	function onUp(e) {
		if (!on || !drag) return;
		const d = drag;
		drag = null;
		swallow(e);
		if (d.moved && d.rect && d.rect.width > 12 && d.rect.height > 12) {
			const pdoc = e.target.ownerDocument || frame().contentDocument;
			deliver(pickCb, captureRegion(d.rect, pdoc));
		} else {
			const el = target(e);
			if (el) deliver(pickCb, capture(el));
		}
		box && box.classList.remove("is-region");
		hide();
		hover = null;
	}

	function onClick(e) {
		if (on) return swallow(e); // handled on mouseup; keep links and LiveCanvas's own click handling out of it
		if (e.altKey && altCb) {
			const el = target(e);
			if (!el) return;
			swallow(e);
			hide();
			deliver(altCb, capture(el));
		}
	}

	function onKey(e) {
		if (e.key === "Escape" && on) { swallow(e); stop(); }
		if (e.key === "Alt" && !on) { hover = null; hide(); }
	}

	function deliver(cb, promise) {
		if (cb) cb(promise);
	}

	function onScroll() {
		if (hover) place(hover.getBoundingClientRect(), shortLabel(hover));
	}

	function onLeave() {
		if (!drag) { hover = null; hide(); }
	}

	// LiveCanvas rewrites the preview document (document.open), which drops its listeners without a load event,
	// so re-add them regularly: adding the same listener twice is a no-op.
	function bind() {
		const f = frame();
		const pdoc = f && f.contentDocument;
		if (!pdoc) return;
		pdoc.addEventListener("mousemove", onMove, true);
		pdoc.addEventListener("mousedown", onDown, true);
		pdoc.addEventListener("mouseup", onUp, true);
		pdoc.addEventListener("click", onClick, true);
		pdoc.addEventListener("keydown", onKey, true);
		pdoc.addEventListener("keyup", onKey, true);
		pdoc.addEventListener("scroll", onScroll, true);
		pdoc.addEventListener("mouseleave", onLeave);
	}

	function start(cb) {
		pickCb = cb;
		on = true;
		bind();
		const f = frame();
		if (f) f.classList.add("lccb-pointing");
		document.addEventListener("keydown", onKey, true);
		emit();
	}

	function stop() {
		on = false;
		drag = null;
		hover = null;
		hide();
		const f = frame();
		if (f) f.classList.remove("lccb-pointing");
		document.removeEventListener("keydown", onKey, true);
		emit();
	}

	const listeners = new Set();
	function emit() { listeners.forEach((fn) => { try { fn(on); } catch (_) {} }); }

	// The preview can be re-rendered (page switch, reload): re-attach to its new document.
	(function watch(tries) {
		const f = frame();
		if (f) {
			bind();
			f.addEventListener("load", bind);
			setInterval(bind, 1000);
		} else if (tries < 240) setTimeout(() => watch(tries + 1), 250);
	})(0);

	window.lccbPoint = {
		start,
		stop,
		active: () => on,
		describe,
		selectorFor: previewSelector,
		onAltPick(fn) { altCb = fn; },
		onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
	};
})();
