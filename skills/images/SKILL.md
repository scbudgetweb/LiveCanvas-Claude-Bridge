---
name: images
description: Audit and improve the site's images - convert heavy or oversized images to WebP at the right size, write missing alt text by looking at each image, and add dimensions and lazy-loading. Use when the user asks about images, page speed from images, or alt text.
argument-hint: "[page name/id, or 'site'] (default: the open page)"
---

# Images: audit, optimise, alt text

Scope: $ARGUMENTS (empty = the page open in the builder)

1. **Audit** with `lc_image_audit` (`scope: "site"` for the whole site; the open page also gets measured display sizes). Summarise: total weight, heavy/oversized images, missing alt text.
2. **Optimise** heavy, oversized and JPEG/PNG images: `lc_image_optimise {ids, format: "webp", max_width}` with `max_width` ≈ 2× the largest displayed width (min 800). Preview shows real savings → **checkpoint** → apply. If a page using them is open in the builder, save and open another page first.
3. **Alt text** for images without it: for each, `lc_media_read {id}` to look at it, then write alt text that says what it shows and why it's there (one sentence, specific, no "image of"; decorative images get `alt=""`). Save in one batch with `lc_media_update {updates: [...]}` (fills the pages too) → **checkpoint** → apply.
4. **Markup**: add `width`/`height` and `loading="lazy"` (not on the first image near the top) with `lc_edit_html` on the open page.
5. **Photos needed?** If the user wants stock photos for gaps, `lc_stock_search` (look at the contact sheet, pick ones that fit the brand) → `lc_stock_import`; mention licence credits for CC BY images.
6. **Summarise**: kilobytes saved, alt text written, what's left.
