---
title: "Required front matter field missing"
summary: "A Hugo content file is missing a required front matter field (e.g. title)."
severity: error
see_also: [title.missing, hugo.markdown.dead_link]
---

## Description

A content file (`content/**/*.md`) is missing a required front matter field –
typically `title` or `date`. The front matter sits at the start of the file
between two `---` lines (or `+++` for TOML).

## Why it matters

- Without `title` Hugo produces a page with no meaningful heading and no usable
  `<title>`.
- Missing fields often lead to empty spots in the layout or build warnings.

## How to fix it

Add the missing fields at the top of the file, e.g.:

    ---
    title: "Wheel alignment BMW 3 Series"
    date: 2026-01-15
    ---

Set up sensible templates in `archetypes/` so new pages include the required
fields from the start.
