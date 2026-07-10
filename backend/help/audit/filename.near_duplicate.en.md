---
title: "URL slugs almost identical"
summary: "Two pages have practically the same slug – differing only in case, separators or accents."
severity: error
see_also: [filename.copy_suspect, filename.similar, title.duplicate]
---

## Description

The last part of the URL (the **slug**) differs from another page's slug only in
trivial ways: upper/lower case, a hyphen instead of an underscore, or spelled-out
accents (`ueber-uns` vs. `ueber_uns`, `Contact` vs. `contact`). To visitors and
search engines these look like two different addresses for the same thing.

## Why it matters

- Near-identical addresses cause **duplicate content** and dilute the ranking of
  both pages.
- Such pairs almost always appear by accident – through inconsistent naming or a
  copied file.
- Links and shares scatter randomly across both variants.

## How to fix it

Settle on **one** spelling and remove the second:

- Delete the redundant source file or rename it consistently.
- Follow a single convention: lower case, hyphens as word separators, accents
  handled uniformly.
- If the old address is still requested, set up a redirect to the remaining page.
