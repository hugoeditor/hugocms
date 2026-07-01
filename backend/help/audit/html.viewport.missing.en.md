---
title: "Viewport declaration missing"
summary: "Without a viewport meta tag the page is not mobile-friendly on phones."
severity: warning
see_also: [html.doctype.missing]
---

## Description

The `<head>` is missing the `viewport` meta tag. It tells mobile browsers to
render the page at device width instead of zooming out a desktop page.

## Why it matters

- Without a viewport the page looks tiny on a smartphone; users have to zoom.
- “Mobile friendliness” is a Google ranking factor (mobile-first indexing).

## How to fix it

Add to the `<head>`:

    <meta name="viewport" content="width=device-width, initial-scale=1">

Then check that the layout responds to narrow widths with responsive CSS.
