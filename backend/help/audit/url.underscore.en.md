---
title: "Underscores in the URL"
summary: "The URL uses underscores instead of hyphens to separate words."
severity: hint
see_also: [url.uppercase, url.space]
---

## Description

This page's address separates words with underscores (`_`). As a word separator
in URLs the **hyphen** (`-`) is the established standard.

## Why it matters

- Google treats the hyphen as a word separator, but the underscore as a joiner
  (`word_word` counts as one word).
- Hyphenated URLs are more readable and consistent.

## How to fix it

Use hyphens to separate words: `/wheel-alignment-bmw-3-series/` instead of
`/wheel_alignment_bmw_3_series/`. In Hugo the file name or `slug` determines the
URL – use hyphens there. For already widespread underscore addresses, consider an
alias.
