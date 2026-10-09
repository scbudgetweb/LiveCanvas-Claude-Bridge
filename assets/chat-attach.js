/**
 * LC Claude Bridge — CC Chat attachments: image preparation and LiveCanvas preview capture.
 * Exposes window.lccbChatAttach. Every function resolves to a prepared image:
 *   { media_type, data (base64, no prefix), thumb (small JPEG data URL), width, height, bytes, name }
 */
(function () {
	"use strict";

	const MAX_EDGE = 1568; // long edge Claude handles without downscaling it again
	const MAX_BYTES = 4.5 * 1024 * 1024; // API limit is 5 MB per image; keep headroom
	const THUMB_EDGE = 160;

	function loadBitmap(blob) {
		if (window.createImageBitmap) return createImageBitmap(blob);
		return new Promise((resolve, reject) => {
			const img = new Image();
			img.onload = () => resolve(img);
			img.onerror = () => reject(new Error("Couldn't read that image."));
			img.src = URL.createObjectURL(blob);
		});
	}

	function canvasFrom(source, maxEdge) {
		const w = source.width, h = source.height;
		const scale = Math.min(1, maxEdge / Math.max(w, h));
		const c = document.createElement("canvas");
		c.width = Math.max(1, Math.round(w * scale));
		c.height = Math.max(1, Math.round(h * scale));
		const ctx = c.getContext("2d");
		ctx.imageSmoothingQuality = "high";
		ctx.drawImage(source, 0, 0, c.width, c.height);
		return c;
	}

	function toBlob(canvas, type, quality) {
		return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
	}

	function blobToBase64(blob) {
		return new Promise((resolve, reject) => {
			const r = new FileReader();
			r.onload = () => resolve(String(r.result).split(",")[1]);
			r.onerror = () => reject(r.error);
			r.readAsDataURL(blob);
		});
	}

	/**
	 * Resize to ≤ MAX_EDGE and ≤ MAX_BYTES. Screenshots stay PNG while they fit (crisper text); photos and
	 * anything too big become JPEG, stepping quality and size down until it fits.
	 */
	async function prepareCanvas(source, name, preferPng) {
		let edge = MAX_EDGE;
		let canvas = canvasFrom(source, edge);
		let blob = preferPng ? await toBlob(canvas, "image/png") : null;
		let type = "image/png";
		if (!blob || blob.size > MAX_BYTES) {
			type = "image/jpeg";
			for (const q of [0.88, 0.8, 0.7, 0.6]) {
				blob = await toBlob(canvas, type, q);
				if (blob.size <= MAX_BYTES) break;
			}
			while (blob.size > MAX_BYTES && edge > 600) {
				edge = Math.round(edge * 0.8);
				canvas = canvasFrom(source, edge);
				blob = await toBlob(canvas, type, 0.7);
			}
		}
		if (blob.size > MAX_BYTES) throw new Error("That image is too large even after compressing it.");
		const thumb = canvasFrom(canvas, THUMB_EDGE).toDataURL("image/jpeg", 0.7);
		return { media_type: type, data: await blobToBase64(blob), thumb, width: canvas.width, height: canvas.height, bytes: blob.size, name: name || "image" };
	}

	async function fromBlob(blob, name) {
		if (!blob || !/^image\//.test(blob.type)) throw new Error("Only images can be attached.");
		const bmp = await loadBitmap(blob);
		return prepareCanvas(bmp, name || blob.name, blob.type === "image/png" || blob.type === "image/gif");
	}

	// ───────────────────────── LiveCanvas preview capture ─────────────────────────

	function previewFrame() {
		const iframe = document.getElementById("previewiframe");
		if (!iframe || !iframe.contentDocument) throw new Error("The LiveCanvas preview isn't available.");
		return iframe;
	}

	/**
	 * Re-render the preview with modern-screenshot (no permission prompt). `area` is "visible" (what you can see
	 * in the preview right now) or "page" (the whole page at the current device width).
	 */
	async function capturePreview(area) {
		if (!window.modernScreenshot) throw new Error("Screenshot library missing (assets/vendor/modern-screenshot.js).");
		const iframe = previewFrame();
		const doc = iframe.contentDocument;
		const win = iframe.contentWindow;
		const vw = iframe.clientWidth;
		const vh = iframe.clientHeight;
		const scrollY = win.scrollY || doc.documentElement.scrollTop || 0;
		const fullH = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
		const bg = win.getComputedStyle(doc.body || doc.documentElement).backgroundColor;

		const full = await window.modernScreenshot.domToCanvas(doc.documentElement, {
			width: vw,
			height: area === "page" ? Math.min(fullH, 12000) : Math.min(fullH, scrollY + vh),
			scale: 1,
			backgroundColor: bg && bg !== "rgba(0, 0, 0, 0)" ? bg : "#ffffff",
			timeout: 15000,
			// LiveCanvas editing chrome inside the preview isn't part of the design.
			filter: (node) => !(node.classList && (node.classList.contains("lc-contextual-menu") || node.id === "lc-interface")),
		});
		let out = full;
		if (area !== "page") {
			out = document.createElement("canvas");
			out.width = vw;
			out.height = Math.min(vh, full.height);
			out.getContext("2d").drawImage(full, 0, Math.min(scrollY, Math.max(0, full.height - vh)), vw, out.height, 0, 0, vw, out.height);
		}
		return prepareCanvas(out, `preview-${vw}px${area === "page" ? "-full" : ""}.png`, true);
	}

	/**
	 * Pixel-exact screenshot of the preview, using Chrome's tab capture (it asks for confirmation every time).
	 * Uses Region Capture to crop to the preview iframe when the browser supports it.
	 */
	async function captureExact() {
		if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) throw new Error("This browser can't capture the tab. Use Chrome, or paste a screenshot (Cmd+Shift+4, then Cmd+V).");
		const iframe = previewFrame();
		const stream = await navigator.mediaDevices.getDisplayMedia({
			video: { displaySurface: "browser", frameRate: 5 },
			audio: false,
			preferCurrentTab: true,
			selfBrowserSurface: "include",
		});
		const [track] = stream.getVideoTracks();
		try {
			if (window.CropTarget && track.cropTo) {
				await track.cropTo(await window.CropTarget.fromElement(iframe));
			}
			const video = document.createElement("video");
			video.muted = true;
			video.playsInline = true;
			video.srcObject = stream;
			await video.play();
			// Let a couple of frames through so the crop has applied.
			await new Promise((r) => setTimeout(r, 350));
			const c = document.createElement("canvas");
			c.width = video.videoWidth;
			c.height = video.videoHeight;
			c.getContext("2d").drawImage(video, 0, 0);
			return prepareCanvas(c, "preview-exact.png", true);
		} finally {
			stream.getTracks().forEach((t) => t.stop());
		}
	}

	/** Capture just one rendered element of the preview (CSS selector in the preview document). */
	async function captureElement(selector) {
		if (!window.modernScreenshot) throw new Error("Screenshot library missing (assets/vendor/modern-screenshot.js).");
		const iframe = previewFrame();
		const doc = iframe.contentDocument;
		const el = doc.querySelector(selector);
		if (!el) throw new Error(`Nothing in the preview matches "${selector}".`);
		const win = iframe.contentWindow;
		let bg = win.getComputedStyle(el).backgroundColor;
		if (!bg || bg === "rgba(0, 0, 0, 0)") bg = win.getComputedStyle(doc.body).backgroundColor;
		const canvas = await window.modernScreenshot.domToCanvas(el, {
			scale: 1,
			backgroundColor: bg && bg !== "rgba(0, 0, 0, 0)" ? bg : "#ffffff",
			timeout: 15000,
		});
		return prepareCanvas(canvas, "element.png", true);
	}

	window.lccbChatAttach = { fromBlob, capturePreview, captureElement, captureExact, MAX_EDGE };
})();
