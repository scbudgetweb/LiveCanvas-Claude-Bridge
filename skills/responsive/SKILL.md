---
name: responsive
description: Check pages at phone, tablet and desktop widths and fix the layout problems found (overflow, overlapping text, small tap targets, tiny text). Use when the user asks about mobile/responsive issues or wants pages checked on all screen sizes.
argument-hint: "[page name/id, or 'site'] (default: the open page)"
---

# Responsive check and fix

Scope: $ARGUMENTS (empty = the page open in the builder)

1. **Which pages.** Empty → the open page (`lc_get_context`). A name/id → that page. `site` → `lc_pages_list` (published LiveCanvas pages) and go through them one by one.
2. **For each page**: if it isn't open, `lc_open_page` (if the builder has unsaved changes, ask before discarding). Then `lc_responsive_check` (390, 768, 1200, 1440).
3. **Fix** high and medium issues with `lc_edit_html` / `lc_edit_css`, preferring Bootstrap's responsive utilities (`col-12 col-md-6`, `d-none d-md-block`, `fs-*`, `text-break`, `img-fluid`) and the site's existing classes over new CSS. For header/footer issues use `lc_partial_update`. Re-run `lc_responsive_check` to confirm.
4. **Low-severity** items (missing image dimensions, lazy-loading): list them, fix if quick.
5. Builder edits aren't saved: after each page, show what changed and **ask before `lc_save`** (then move to the next page).
6. **Summarise** per page: fixed, remaining (and why), and anything that needs a design decision.
