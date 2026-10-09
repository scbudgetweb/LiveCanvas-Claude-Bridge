/**
 * LC Claude Bridge — CC Terminal tab.
 *
 * Adds a fourth tab to the LiveCanvas code editor that hosts an xterm.js terminal attached to the
 * local LC Claude Bridge hub (runtime/hub.js, role=terminal), which runs real Claude Code in the site root.
 */
(function () {
	"use strict";

	const config = window.lccbConfig || {};
	const PANEL_ID = "lc-claude-terminal";
	const TAB_ID = "claude-tab";
	const LC_PANELS = "#lc-html-editor, #lc-css-editor, #lc-js-editor";

	let term = null;
	let fit = null;
	let ws = null;
	let retryDelay = 1000;
	let connecting = false;
	let statusEl = null;
	let hintEl = null;

	// ───────────────────────── Tab + panel ─────────────────────────

	function injectTab() {
		const jsTab = document.getElementById("js-tab");
		const jsPanel = document.getElementById("lc-js-editor");
		if (!jsTab || !jsPanel || document.getElementById(TAB_ID)) return false;

		const tab = document.createElement("a");
		tab.id = TAB_ID;
		tab.href = "#";
		tab.setAttribute("role", "tab");
		tab.setAttribute("aria-label", "Claude Code terminal");
		tab.setAttribute("aria-controls", PANEL_ID);
		tab.setAttribute("aria-selected", "false");
		tab.tabIndex = -1;
		tab.innerHTML =
			'<span class="fa fa-terminal lc-editor-tab-icon" aria-hidden="true"></span>' +
			'<span class="lc-editor-tab-label"><span class="lc-editor-tab-prefix">CC </span>Terminal</span>';
		jsTab.after(tab);

		const panel = document.createElement("div");
		panel.id = PANEL_ID;
		panel.setAttribute("role", "tabpanel");
		panel.setAttribute("aria-labelledby", TAB_ID);
		panel.hidden = true;
		panel.innerHTML =
			'<div class="lccb-term-bar">' +
			'<span class="lccb-term-status" data-state="off">Not connected</span>' +
			'<button type="button" class="lccb-term-restart" title="Kill this Claude Code session and start a new one">' +
			'<span class="fa fa-refresh" aria-hidden="true"></span> Restart</button>' +
			"</div>" +
			'<div class="lccb-term-hint" hidden></div>' +
			'<div class="lccb-term-screen"></div>';
		jsPanel.after(panel);

		statusEl = panel.querySelector(".lccb-term-status");
		hintEl = panel.querySelector(".lccb-term-hint");
		panel.querySelector(".lccb-term-restart").addEventListener("click", () => {
			if (!ws || ws.readyState !== 1) return connect();
			if (confirm("Restart Claude Code? The current conversation in this tab will end.")) send({ t: "restart" });
		});

		// Keep keystrokes inside the terminal. LiveCanvas binds page-level keydown handlers
		// (editor.js → handleKeyboardEvents) that would otherwise eat Escape, Cmd+E, etc.
		["keydown", "keyup", "keypress"].forEach((type) =>
			panel.addEventListener(type, (e) => e.stopPropagation())
		);

		tab.addEventListener("click", (e) => {
			e.preventDefault();
			showClaudeTab();
		});

		// When LiveCanvas's own tabs are clicked, hide ours; its handlers then show their panel.
		window.jQuery("body").on("click", "#html-tab, #css-tab, #js-tab, #chat-tab", hideClaudeTab);

		new ResizeObserver(() => refit()).observe(panel);
		return true;
	}

	function showClaudeTab() {
		const tab = document.getElementById(TAB_ID);
		document.querySelectorAll(".code-tabber [role='tab']").forEach((t) => {
			const active = t === tab;
			t.classList.toggle("active", active);
			t.setAttribute("aria-selected", active ? "true" : "false");
			t.tabIndex = active ? 0 : -1;
		});
		window.jQuery(".lc-editor-menubar .only-for-html").hide();
		window.jQuery(LC_PANELS).hide();
		document.getElementById("lc-code-editor-window").classList.add("lccb-term-active");
		document.getElementById(PANEL_ID).hidden = false;
		ensureTerminal();
		requestAnimationFrame(() => { refit(); term && term.focus(); });
	}

	function hideClaudeTab() {
		const panel = document.getElementById(PANEL_ID);
		if (panel) panel.hidden = true;
		document.getElementById("lc-code-editor-window").classList.remove("lccb-term-active");
	}

	// ───────────────────────── Terminal ─────────────────────────

	function ensureTerminal() {
		if (term) return;
		if (typeof window.Terminal !== "function") {
			showHint("xterm.js failed to load (assets/vendor is missing from the plugin). Reinstall the plugin from the packaged zip.");
			return;
		}
		term = new window.Terminal({
			cursorBlink: true,
			fontFamily: 'Menlo, Monaco, "SF Mono", Consolas, monospace',
			fontSize: 13,
			lineHeight: 1.15,
			scrollback: 5000,
			allowProposedApi: true,
			macOptionIsMeta: true,
			theme: {
				background: "#1e1f2b",
				foreground: "#e6e6e6",
				cursor: "#d97757",
				selectionBackground: "rgba(217,119,87,0.35)",
			},
		});
		fit = new window.FitAddon.FitAddon();
		term.loadAddon(fit);
		if (window.WebLinksAddon) term.loadAddon(new window.WebLinksAddon.WebLinksAddon());
		term.open(document.querySelector(`#${PANEL_ID} .lccb-term-screen`));
		term.onData((d) => send({ t: "i", d }));
		term.onResize(({ cols, rows }) => send({ t: "resize", cols, rows }));
		refit();
		connect();
	}

	function refit() {
		const panel = document.getElementById(PANEL_ID);
		if (!term || !fit || !panel || panel.hidden || !panel.offsetHeight) return;
		try { fit.fit(); } catch (_) {}
	}

	// ───────────────────────── Transport ─────────────────────────

	function send(msg) {
		if (ws && ws.readyState === 1) ws.send(JSON.stringify(msg));
	}

	function setStatus(state, text) {
		if (!statusEl) return;
		statusEl.dataset.state = state;
		statusEl.textContent = text;
	}

	function showHint(html) {
		if (!hintEl) return;
		hintEl.innerHTML = html;
		hintEl.hidden = !html;
	}

	function connect() {
		if (connecting || (ws && ws.readyState <= 1)) return;
		connecting = true;
		setStatus("off", "Connecting…");
		const url = `ws://127.0.0.1:${config.port}/?role=terminal&site=${encodeURIComponent(config.site)}&token=${encodeURIComponent(config.token)}`;
		ws = new WebSocket(url);

		ws.onopen = () => {
			connecting = false;
			retryDelay = 1000;
			showHint("");
			setStatus("on", "Claude Code · " + config.site);
			if (term) send({ t: "resize", cols: term.cols, rows: term.rows });
		};
		ws.onmessage = (e) => {
			let msg;
			try { msg = JSON.parse(e.data); } catch (_) { return; }
			if (msg.t === "o") term && term.write(msg.d);
			else if (msg.t === "replay") { if (term) { term.reset(); term.write(msg.d); } }
			else if (msg.t === "status") {
				if (msg.state === "running") setStatus("on", "Claude Code · " + config.site);
				else if (msg.code === undefined || msg.code === null) send({ t: "start" }); // never started: start now
				else setStatus("idle", "Session ended · press Enter");
			}
		};
		ws.onclose = () => {
			connecting = false;
			ws = null;
			setStatus("off", "Hub not reachable · retrying");
			showHint(
				"Can't reach the LC Claude Bridge hub on port " + config.port + ". In WordPress open " +
				"<strong>Tools › Claude Code</strong> and click <strong>Re-check &amp; repair</strong>. " +
				"Log: <code>~/Library/Logs/lc-claude-bridge.log</code>"
			);
			setTimeout(connect, retryDelay);
			retryDelay = Math.min(retryDelay * 1.6, 15000);
		};
		ws.onerror = () => {};
	}

	// ───────────────────────── Boot ─────────────────────────

	(function waitForEditor(tries) {
		if (window.jQuery && document.getElementById("js-tab") && injectTab()) return;
		if (tries < 240) setTimeout(() => waitForEditor(tries + 1), 250);
	})(0);
})();
