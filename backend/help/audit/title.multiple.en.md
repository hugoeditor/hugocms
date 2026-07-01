---
title: "Multiple <title> elements"
summary: "The page contains more than one <title> – exactly one is allowed."
severity: warning
see_also: [title.missing]
---

## Description

This page has more than one `<title>` element in the `<head>`. The HTML standard
allows exactly one. Search engines and browsers usually use the first and ignore
the rest – often not the one you intended.

## Why it matters

- Which title “wins” cannot be controlled reliably.
- It almost always points to a layout bug (e.g. the title set both in baseof and
  in a partial).

## How to fix it

Make sure the title is output in only **one** place – typically
`layouts/_default/baseof.html` or `partials/head.html`. Remove a second
`<title>` from an included partial or theme.
