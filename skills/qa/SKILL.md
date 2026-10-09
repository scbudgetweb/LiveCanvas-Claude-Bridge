---
name: qa
description: Run the quality pass (accessibility, SEO, performance, links, forms, basics) on a page or the whole site and fix what can be fixed, critical items first. Use when the user asks for a check, audit, QA or review of the site.
argument-hint: "[page name/id, or 'site'] (default: the open page)"
---

# Quality pass

Scope: $ARGUMENTS (empty = the page open in the builder)

1. **Run** `lc_qa` (`scope: "site"` for the whole site, or `id` for one page).
2. **Report** the summary and the findings grouped by priority, in plain language. For each, say whether you can fix it here or the user needs to (settings, plugins, client details).
3. **Fix critical items first**, then should-fix, asking before anything that changes content or a deliberate design choice:
   - Broken links, headings, alt text, landmarks, contrast: `lc_edit_html` / `lc_edit_css` on the open page (then ask before `lc_save`), `lc_page_update` / `lc_partial_update` for saved pages and header/footer.
   - Images: `/images` workflow tools.
   - Settings, plugins (SEO, cookie consent, SMTP), form recipients: tell the user exactly where to change them.
4. **Re-run** `lc_qa` on what you fixed and confirm.
5. **Summarise**: fixed, left for the user (with where to do it), and deliberately ignored (with why).
