---
title: "URL very long"
summary: "The address is unusually long – short, meaningful URLs are better."
severity: hint
see_also: [url.uppercase]
---

## Description

This page's address is very long. Short, meaningful URLs are more readable, more
shareable, and look more trustworthy.

## Why it matters

- Long URLs get truncated in search results and when shared.
- Deeply nested paths often indicate an unnecessarily complicated structure.

## How to fix it

Keep URLs short and meaningful. Reduce nesting (flatter sections) and shorten
overlong slugs to the essential terms. In Hugo the path can be controlled via
`slug`, `url` in the front matter, or the `permalinks` config.
