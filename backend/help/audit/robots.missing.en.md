---
title: "robots.txt missing"
summary: "No robots.txt was found – recommended for controlling crawling."
severity: hint
see_also: [robots.no_sitemap_ref, sitemap.missing]
---

## Description

No `robots.txt` was found in the built project. This file in the root directory
tells search engines which areas they may crawl and usually points to the
sitemap.

## Why it matters

- Without `robots.txt` search engines still crawl, but you give up the control
  option and the sitemap hint.
- Not critical for small sites, recommended for larger ones.

## How to fix it

Enable robots.txt output in Hugo (`enableRobotsTXT = true` in the config) or add
your own `static/robots.txt`, e.g.:

    User-agent: *
    Allow: /
    Sitemap: https://example.com/sitemap.xml
