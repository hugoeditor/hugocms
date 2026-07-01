---
title: "Open Graph image missing"
summary: "Without og:image no preview image appears when sharing – links look unattractive."
severity: warning
see_also: []
---

## Description

This page is missing the `og:image` (Open Graph) declaration. This meta tag
defines which preview image is shown when the page is shared on social networks,
messengers (WhatsApp, Signal) or chat apps.

## Why it matters

- Without a preview image a shared link looks bare and gets clicked less often.
- A fitting image noticeably increases reach and trust.
- Search engines and preview services also fall back to `og:image`.

## How to fix it

Add an Open Graph image in the `<head>` (recommended 1200 × 630 px):

    <meta property="og:image" content="https://example.com/image.jpg">

In Hugo this is usually set centrally in the layout (`partials/head.html`) with a
fallback to a default image and, if present, the post image from the front
matter:

    {{ with .Params.image }}
      <meta property="og:image" content="{{ . | absURL }}">
    {{ else }}
      <meta property="og:image" content="{{ "og-default.jpg" | absURL }}">
    {{ end }}

## See also

Also check `og:title` and `og:description` so the preview is complete.
