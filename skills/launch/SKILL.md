---
name: launch
description: Pre-launch check - run the whole-site quality pass in launch mode, fix blockers, walk through the go-live checklist and produce launch-report.md. Use when the user is about to go live, deploy or hand the site over.
---

# Launch check

1. **Run** `lc_qa {scope: "site", launch: true}`. It writes `launch-report.md` in the site root.
2. **Blockers first**: every critical finding must be fixed or explicitly accepted by the user. Fix what you can (see `/qa`); for the rest, give exact instructions.
3. **Walk the go-live checklist** with the user, item by item: search-engine visibility on the live site, redirects (`lc_redirect_map` if an old site was migrated), the migration/backup package, removing LC Claude Bridge (Tools › Claude Code › Disconnect, then delete the plugin), and keeping `.mcp.json`, `.claude/`, `CLAUDE.md`, `launch-report.md` and `redirects-*` files off the live server.
4. **Forms**: confirm the notification recipients are the client's addresses and suggest a test submission on the live site.
5. **Re-run** `lc_qa {scope: "site", launch: true}` after fixes so the report is current.
6. **Finish** with the remaining checklist items and the post-launch steps (Search Console sitemap, phone check, test form, uptime monitoring, backups).
