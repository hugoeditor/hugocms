---
title: "Open Graph title missing"
summary: "Without og:title social networks use a fallback title when sharing."
severity: warning
see_also: [social.og.description.missing, social.og.image.missing]
---

## Description

This page is missing `og:title` (Open Graph). That tag sets the heading of the
preview when the page is shared on social networks or messengers.

## Why it matters

- Without `og:title` the platforms guess (usually the `<title>`) – often with the
  appended brand suffix that looks out of place in the preview.
- A dedicated social title can be more concise and inviting.

## How to fix it

Add an Open Graph title in the `<head>`, in Hugo usually centrally in the layout:

    <meta property="og:title" content="{{ .Title }}">

Check it together with `og:description` and `og:image` so the preview is complete.
