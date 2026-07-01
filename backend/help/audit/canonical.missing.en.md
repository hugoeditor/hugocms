---
title: "Canonical link missing"
summary: "Without rel=canonical it can be unclear which of several similar URLs is authoritative."
severity: warning
see_also: [canonical.multiple, canonical.self_reference]
---

## Description

This page has no `<link rel="canonical">`. That tag names the “official” address
of a page. It helps when the same page is reachable via several URLs (with/without
`www`, with parameters, http/https).

## Why it matters

- Without a canonical, Google may treat several URL variants as separate pages
  (duplicate content) and split the ranking strength.
- The canonical consolidates the signals onto one address.

## How to fix it

Output the page's canonical URL in the `<head>`. In Hugo:

    <link rel="canonical" href="{{ .Permalink }}">

Make sure `baseURL` is set correctly in the Hugo configuration so the permalinks
are absolute and consistent.
