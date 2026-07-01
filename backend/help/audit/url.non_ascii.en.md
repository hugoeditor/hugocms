---
title: "Special characters in the URL"
summary: "The URL contains accented or other non-ASCII characters – better avoided."
severity: hint
see_also: [url.space, url.uppercase]
---

## Description

This page's address contains non-ASCII characters (e.g. accented letters like
`ä`, `ö`, `ü` or `ß`). In the link they appear as cryptic `%` codes (`%C3%A4`
for `ä`).

## Why it matters

- Percent-encoded URLs are hard to read and break easily when copied.
- Different systems encode special characters differently, which can cause
  duplicates or broken links.

## How to fix it

Use only ASCII in URLs: spell accented letters out (`ae`, `oe`, `ue`, `ss`) or
drop them. In Hugo this usually happens automatically for the slug; watch out for
manually set `slug`/`url` values in the front matter:

    slug: "wheel-alignment-for-bmw"
