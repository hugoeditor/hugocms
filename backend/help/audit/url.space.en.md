---
title: "Space in the URL"
summary: "The URL contains a (encoded) space (%20) – ugly and error-prone."
severity: warning
see_also: [url.uppercase, url.non_ascii]
---

## Description

This page's address contains a space, which appears in the link as `%20`. Spaces
do not belong in clean URLs.

## Why it matters

- `%20` URLs are hard to read, break easily when copied, and look
  unprofessional.
- They usually indicate a file name or slug containing a space.

## How to fix it

Replace spaces with hyphens. Name the content file accordingly
(`wheel-alignment-bmw.md`) or set a clean `slug` in the front matter:

    ---
    slug: "wheel-alignment-bmw-3-series"
    ---
