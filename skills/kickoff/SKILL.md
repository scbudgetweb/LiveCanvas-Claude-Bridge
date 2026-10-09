---
name: kickoff
description: Start a new LiveCanvas site from a brief - questions, sitemap, design tokens, header and footer, then draft pages. Use when the user wants to begin building a site or asks to "kick off" a project.
argument-hint: "[brief, or a path to a brief file]"
---

# Kick off a new site

The brief: $ARGUMENTS

Work in this order and **get the user's approval at each checkpoint** before writing anything to the site. Site-level changes go through preview → `lc_apply_change`; new pages are drafts.

1. **Understand what's there.** `lc_site_context` (theme, tokens, existing pages, partials, conventions). If the site already has real pages, say so and ask whether this is a fresh start or an extension.
2. **Fill gaps in the brief.** If any of these are missing, ask in one short list (not one at a time): the business and what it sells, the audience, the pages needed, the main call to action (book, call, buy, enquire), tone of voice, brand colours and fonts (or a logo/reference site to take them from), and whether they have an HTML template (`lc_html_templates`) or an old site to migrate (suggest `/from-template` or `/migrate` instead if so).
3. **Propose a sitemap**: each page with its purpose, its sections in order, and its primary CTA. Keep it lean (5-8 pages for most small businesses). **Checkpoint.**
4. **Design tokens.** Propose Picostrap tokens (`$primary`, `$secondary`, body/heading fonts with their Google Fonts `<link>`, `$border-radius`, `$body-color`, `$body-bg`) and a handful of custom properties for Global CSS. **Checkpoint**, then `lc_tokens_update` → `lc_apply_change` → `lc_css_recompile`.
5. **Header and footer** with `lc_partial_update`: logo/brand, menu matching the sitemap, CTA button, footer contact details and legal links. Preview → **checkpoint** → apply.
6. **Draft pages** with `lc_page_create` (status draft, same slugs as the sitemap). Use Bootstrap 5 and the site's own classes; write real, specific copy from the brief (no lorem ipsum; mark genuine unknowns like prices as `[TBC]`). One page at a time: preview → apply.
7. **Check each page**: open it (`lc_open_page`), then `lc_responsive_check` and `lc_lint {scope: "page"}`; fix high-severity issues.
8. **Finish** with a short summary: pages created (with editor links), tokens set, open questions (`[TBC]` items), and suggested next steps (`/images` for photos, `/qa` before launch).
