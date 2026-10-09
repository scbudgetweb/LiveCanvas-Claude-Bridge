---
name: handover
description: Write a client handover pack - what was built and changed, how to edit the site in LiveCanvas, where forms send, and what to look after - as a Markdown file ready to send. Use when the user is finishing a project or handing the site to a client.
argument-hint: "[client name]"
---

# Client handover

Client: $ARGUMENTS

1. **Gather**: `lc_site_context` (pages, header/footer, templates, tokens), `lc_audit_list {limit: 200}` (what was changed and when), `lc_sections_list`, and `lc_qa {scope: "site", checks: ["forms", "basics"]}` for form recipients and site basics. Ask the user for anything client-specific you don't know (client's name, who to contact for support, hosting, renewal dates).
2. **Write `handover-<site>.md`** in the site root, for the **client** (non-technical, friendly, UK English, short sentences), with:
   - **Your new website**: the pages and what each is for (with live URLs).
   - **What we did**: a plain summary of the work (from the audit log and the pages), not a technical changelog.
   - **Editing your site**: how to log in; open a page in LiveCanvas (the "Edit with LiveCanvas" button); change text by clicking it; swap an image; add a section from the Sections library; Save; what not to touch (header/footer affects every page, Global CSS).
   - **Your forms**: where each form's emails go and how to change it.
   - **Images**: keep them under ~300 KB, WebP if possible, always add alt text (with an example).
   - **Looking after it**: updates, backups, what to do if something breaks, who to contact.
3. **Show the user** the file path and a short summary; offer to adjust the tone or add their branding. Remind them not to deploy this file to the live server.
