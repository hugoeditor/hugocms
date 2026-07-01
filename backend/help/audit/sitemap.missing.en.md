---
title: "Sitemap missing"
summary: "No sitemap.xml was found – search engines then find pages less easily."
severity: warning
see_also: [robots.no_sitemap_ref, link.orphan_page]
---

## Description

No `sitemap.xml` was found in the built project. The sitemap lists all pages and
helps search engines capture them completely.

## Why it matters

- Especially with many or newly published pages, a sitemap speeds up discovery
  and indexing.
- It complements (but does not replace) internal linking.

## How to fix it

Hugo generates the sitemap automatically at `/sitemap.xml` by default. If it is
missing, check:

- whether sitemap output was disabled (`disableKinds` in the Hugo config contains
  `sitemap`),
- whether a correct `baseURL` is set,
- whether the build completed fully.
