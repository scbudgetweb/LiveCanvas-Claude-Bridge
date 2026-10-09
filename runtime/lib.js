// Shared paths + config for the hub and the MCP server. PHP (includes/installer.php) writes these files.
import { timingSafeEqual } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

export const SUPPORT_DIR = join(homedir(), "Library", "Application Support", "lc-claude-bridge");
export const CONFIG_FILE = join(SUPPORT_DIR, "config.json");
export const TOKEN_FILE = join(SUPPORT_DIR, "token");
export const DEFAULT_PORT = 8770;

export const log = (...a) => console.error(new Date().toISOString(), ...a);

/**
 * {
 *   port, claudePath, path,
 *   sites: { "<siteId>": { root, origin } }
 * }
 * Re-read on every use so Connect/Disconnect apply without restarting the hub.
 */
export function loadConfig() {
	const defaults = { port: DEFAULT_PORT, claudePath: null, path: "/usr/bin:/bin:/usr/sbin:/sbin", sites: {} };
	if (!existsSync(CONFIG_FILE)) return defaults;
	try {
		return { ...defaults, ...JSON.parse(readFileSync(CONFIG_FILE, "utf8")) };
	} catch (err) {
		log(`config.json unreadable: ${err.message}`);
		return defaults;
	}
}

export function readToken() {
	if (!existsSync(TOKEN_FILE)) return null;
	const t = readFileSync(TOKEN_FILE, "utf8").trim();
	return /^[a-f0-9]{64}$/.test(t) ? t : null;
}

export function tokenMatches(given, expected) {
	if (!expected || typeof given !== "string" || given.length !== expected.length) return false;
	return timingSafeEqual(Buffer.from(given), Buffer.from(expected));
}
