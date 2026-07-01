---
title: "Page title missing"
summary: "The page has no <title> element – the most important on-page SEO signal is absent."
severity: error
see_also: [title.too_short, title.identical_to_h1]
---

## Description

This page's `<head>` has no `<title>` element (or it is empty). The title is the
clickable heading in the search result, the browser tab label, and the default
text when sharing.

## Why it matters

- Without a title Google generates a replacement from the page content – usually
  worse and less clickable.
- The title is one of the strongest ranking signals a page has.

## How to fix it

In Hugo set a `title` in the front matter and make sure the layout outputs it
(`partials/head.html`):

    <title>{{ if .Title }}{{ .Title }} | {{ .Site.Title }}{{ else }}{{ .Site.Title }}{{ end }}</title>

Every published page needs a unique, descriptive title (30–60 characters).
