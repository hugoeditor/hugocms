---
title: "Uppercase letters in the URL"
summary: "The URL contains uppercase letters – this easily causes duplicates and broken links."
severity: hint
see_also: [url.underscore, url.space]
---

## Description

This page's address contains uppercase letters. On most servers URLs are
case-sensitive – `/Page/` and `/page/` then count as **two** addresses.

## Why it matters

- Upper/lower variants of the same page act as duplicate content.
- Manually typed or linked addresses easily break if the spelling is not exact.

## How to fix it

Use **lowercase** throughout URLs. In Hugo slugs are lowercase by default; watch
out for manually set `url:`/`slug:` values in the front matter. For already
widespread uppercase addresses, consider an alias to the lowercase variant.
