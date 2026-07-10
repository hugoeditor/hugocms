---
title: "URL looks like an accidental copy"
summary: "The slug carries a copy marker (…-copy, new-…) and the source page already exists – most likely an unintended duplicate."
severity: error
see_also: [filename.near_duplicate, title.duplicate, canonical.missing]
---

## Description

The last part of this URL (the **slug**) contains a typical copy marker such as
`-copy`, `-kopie`, `-new`, `-old` or `-draft`, and without that marker it matches
a page that already exists. This is the classic sign of an accidentally
duplicated file: `imprint` becomes `imprint-copy` while copying, and both end up
in the published project.

## Why it matters

- Two nearly identical pages under different addresses are **duplicate content**.
  Search engines have to guess which one is canonical and split visibility
  across both.
- Internal links, shared addresses and the sitemap point inconsistently at one
  version or the other.
- Users who land on the copy may see an outdated or unfinished version.

## How to fix it

Decide which page is the intended one and **remove the copy**:

- If the copy is redundant, delete its source file (in Hugo the corresponding
  `.md` file or folder).
- If the address is still needed, set up a redirect (alias) to the original
  instead of keeping a second page.
- If the new version should replace the original, rename it cleanly and delete
  the old one rather than running both in parallel.

If a duplicate cannot be avoided for now, add a `canonical` link on the copy that
points to the original, so search engines know the authoritative page.
