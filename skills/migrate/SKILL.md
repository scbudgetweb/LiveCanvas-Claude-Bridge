---
name: migrate
description: Move an existing website's content into this LiveCanvas site - crawl the old site, propose the new sitemap, import images, rebuild each page in the new design with its SEO, then build the redirect map. Use when the user mentions an old site or migrating.
argument-hint: "<old site URL>"
---

# Migrate from the old site

Old site: $ARGUMENTS

1. **Crawl** with `lc_migrate_scan {url}`; if it returns `partial`, call it again until `done` (tell the user it's progressing). Ask for the URL if none was given.
2. **Overview** with `lc_migrate_site`. Propose the new sitemap as a table: old page → keep / merge into / drop, with the new slug (keep slugs where sensible: no redirect needed). Point out legal pages, thin pages, and duplicate content. **Checkpoint.**
3. **For each page to keep** (one at a time):
   - `lc_migrate_page {url}` for its content, SEO title/description, images and forms.
   - `lc_media_import_batch {urls}` for the images worth keeping (preview → apply); then use the media-library URLs.
   - Rebuild it **in this site's design system** (its sections, classes and tokens; `lc_site_context` if unsure): keep the meaning, headings and key copy; tidy obvious typos; don't invent facts. Legal pages: keep the wording exactly.
   - `lc_page_create` as a draft with the agreed slug (preview → apply). Note the old SEO title/description for the SEO plugin.
   - Forms: recreate with the site's form plugin rather than copying markup.
4. **Redirects**: `lc_redirect_map` (Redirection CSV by default); resolve the unmatched URLs with the user (map to a page, `410`, or home).
5. **Check**: `lc_qa {scope: "site", checks: ["seo", "links"]}` and fix what's broken.
6. **Summarise**: pages migrated (editor links), pages dropped, images imported, the redirect file and how to deploy it, SEO titles/descriptions to enter.
