---
title: "Meta description missing"
summary: "Without <meta name=\"description\"> Google picks the preview text itself."
severity: warning
see_also: [meta.description.too_long, title.missing]
---

## Description

This page has no meta description – the short text below the title in the search
result. When it is missing, Google picks a snippet from the page itself, which is
often less compelling.

## Why it matters

- A good description works like ad copy: it raises the click-through rate, even
  without directly improving the ranking.
- You actively control what searchers read about the page.

## How to fix it

In Hugo set a `description` (about 120–160 characters) in the front matter and
output it in the layout:

    ---
    description: "Wheel alignment for the BMW 3 Series: process, duration and prices. Book online."
    ---

    <meta name="description" content="{{ .Description }}">

Write a distinct, fitting description with a call to action for each page.
