---
title: "robots.txt without sitemap reference"
summary: "The robots.txt does not name the sitemap – a simple, helpful hint is missing."
severity: hint
see_also: [robots.missing, sitemap.missing]
---

## Description

There is a `robots.txt`, but it contains no `Sitemap:` line. Search engines
therefore lack the direct pointer to the sitemap.

## Why it matters

- The sitemap reference in robots.txt is the simplest way to show search engines
  the complete page list.
- Without it they have to guess the sitemap or find it by other means.

## How to fix it

Add the absolute address of the sitemap to `robots.txt`:

    Sitemap: https://example.com/sitemap.xml

If you use Hugo's automatic robots.txt, add the line to the corresponding
template (`layouts/robots.txt`).
