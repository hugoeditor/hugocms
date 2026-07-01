---
title: "Multiple canonical links"
summary: "The page has more than one rel=canonical – search engines then usually ignore all of them."
severity: error
see_also: [canonical.missing]
---

## Description

This page contains more than one `<link rel="canonical">`. Given conflicting
values, Google generally ignores the canonical entirely.

## Why it matters

- The canonical's purpose – uniqueness – is turned into its opposite.
- Almost always a layout bug (canonical set in baseof and additionally in a
  partial or theme).

## How to fix it

Output the canonical in only **one** place (`partials/head.html`). Check the theme
and your own partials for a second `rel="canonical"` and remove it.
