---
name: from-template
description: Build this site's pages from an HTML template in the user's template library (or a folder/zip) - scan, map tokens, import assets, rebuild header/footer and pages, and check each section against the original. Use when the user wants to use or start from a template.
argument-hint: "<template name or path> [pages, e.g. the beauty-salon demo]"
---

# Build from an HTML template

Template and pages: $ARGUMENTS

1. **Find it.** If no template is named, `lc_html_templates` and ask which one. If pages are described in words ("the beauty-salon demo"), `lc_html_templates {find}` to locate them. Never search the disk with shell commands.
2. **Scan** with `lc_html_template_scan {path, filter}`. Tell the user the framework and the recommended strategy (convert to Bootstrap 5, keep its CSS scoped, or native Tailwind), the pages you'll use, and any warnings (jQuery plugins, Bootstrap 4). **Checkpoint.**
3. **Tokens.** Map `design_tokens` onto Picostrap with `lc_tokens_update` (primary, body/heading fonts + their `<link>`, radius) → apply → `lc_css_recompile`.
4. **Assets.** `lc_html_template_assets {path, pages, scope?}` for the chosen pages: preview → **checkpoint** → apply. Skip vendor scripts the rebuilt sections won't need (carousels you'll rebuild with Bootstrap, etc.) via `exclude`.
5. **Header and footer** from the template's header/footer (`lc_html_template_read {section: "header"}`) into the LiveCanvas partials with `lc_partial_update`, re-linking the menu to this site's pages.
6. **Pages**, one at a time: `lc_html_template_read {path, page}` for the outline, then each section (`section: n`, with `strategy: "convert"` for non-Bootstrap templates and finish the `still_to_convert` classes yourself). Assemble the page with `lc_page_create` (draft), or into the open page with `lc_edit_html`. Swap template text for the client's content where you have it.
7. **Check every section** with `lc_compare` at 1200 and 390px; iterate until it's "close" (≤12%) unless content was deliberately changed. Then `lc_responsive_check` and `lc_lint` per page.
8. **Reusable sections** that appear on several pages (CTA bands, Instagram strips): save one with `lc_section_create` and embed it rather than copying.
9. **Summarise**: pages built (editor links), sections that still differ and why, and anything left from the template to tidy.
