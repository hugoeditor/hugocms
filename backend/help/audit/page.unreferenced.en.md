---
title: "Page unfindable (neither linked nor in the sitemap)"
summary: "This page is not in the sitemap and cannot be reached from the home page along any link path – practically invisible to search engines."
severity: error
see_also: [link.orphan_page, sitemap.missing, link.internal.broken]
---

## Description

The audit determines which pages are reachable: the home page, every entry in
the sitemap, and everything reached from there via internal links (followed step
by step). This HTML page belongs to none of these groups – it is neither in the
sitemap nor is there a link path from the home page to it.

As a result, it can only be reached by someone who already knows its exact
address.

## Difference from an orphan page

- **Orphan page** (`link.orphan_page`, hint): no internal link points to the
  page, but it is in the sitemap – so search engines still find it.
- **Unfindable** (this rule, error): both are missing. The page cannot be
  discovered by any usual route.

## Why it matters

- Search engines discover pages through the sitemap and through links. With
  neither, the page is usually not indexed and does not appear in results.
- Such a page is most often a mistake: a forgotten link, a page dropped from the
  navigation, or an incomplete sitemap.

## Fix

Depending on whether the page is meant to be public:

- **Yes:** Link it where it belongs thematically (navigation, an overview or
  category page, related posts), and make sure it appears in the sitemap. In
  Hugo the sitemap is generated automatically; a page is usually missing because
  its front matter excludes it from `sitemap` or sets `private`/`draft`.
- **No:** If the page is not intended for visitors at all (a test page, an
  internal helper document), it does not belong in the published `public/`
  folder – or exclude it from the check via the SEO report exclusions (project
  settings or `[seo_report]`).

If the sitemap is missing entirely, the audit reports `sitemap.missing` instead;
then all pages count as roots and this rule does not apply.
