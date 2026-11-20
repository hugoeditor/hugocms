---
title: "Sitemap entries without an address"
summary: "Entries of the sitemap contain no <loc> element or an empty one."
severity: warning
see_also: [sitemap.xml.malformed, sitemap.xml.root_invalid]
---

## Description

Every entry of a sitemap (`<url>`, in a sitemap index `<sitemap>`) must contain
exactly one `<loc>` element with the full address. The message gives the number
of entries where `<loc>` is missing or empty.

## Why it matters

- `<loc>` is the only mandatory field of an entry. Without an address the entry
  is worthless.
- Some search engines reject a sitemap with invalid entries altogether.

## How to fix it

- Check that a `baseURL` is set in the Hugo configuration.
- Check a custom sitemap template: `<loc>` should output `{{ .Permalink }}`.
  Pages without their own output (for example with `build.render: never`) have
  no permalink and do not belong in the sitemap.
