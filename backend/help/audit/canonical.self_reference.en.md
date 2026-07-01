---
title: "Canonical does not point to itself"
summary: "The canonical points to a different URL – check whether that is intended."
severity: hint
see_also: [canonical.missing]
---

## Description

This page's `rel="canonical"` points **not** to its own address but to a
different URL. That is only correct if the page is deliberately considered a
duplicate of another.

## Why it matters

- A wrong canonical can cause this page not to be indexed at all – Google follows
  the reference to the other address.
- Often a mistake: a hard-coded canonical from a template or a copied example.

## How to fix it

Check whether the reference is intentional. If the page should be indexed itself,
point the canonical to its own URL:

    <link rel="canonical" href="{{ .Permalink }}">
