---
title: "Title identical to the H1 heading"
summary: "The <title> and the page's H1 are word-for-word the same – a missed opportunity."
severity: hint
see_also: [title.too_short]
---

## Description

This page's title (`<title>`) and its visible main heading (`<h1>`) are
identical. This is not an error, but a missed opportunity: the title and the
heading reach the reader in different contexts.

## Why it matters

- The **title** appears in the search result and the browser tab – keywords,
  brand and click appeal matter here.
- The **H1** is read on the page itself – it may be more concise or inviting.
- Two coordinated but not identical phrasings cover more search variants and
  feel less mechanical.

## How to fix it

Phrase the title and the H1 deliberately differently. Example:

    title: "Wheel alignment BMW 3 Series – prices & process | Autoprofis"
    # H1:  "Wheel alignment for your BMW 3 Series"

In Hugo the title lives in the front matter; the H1 usually comes from the
content's heading or the layout.

## See also

Related rules are listed under “See also” above.
