---
title: "Twitter Card declaration missing"
summary: "Without twitter:card, X/Twitter shows only a plain preview instead of a large one."
severity: hint
see_also: [social.og.image.missing, social.og.title.missing]
---

## Description

This page is missing the `twitter:card` meta tag. It controls how a shared link
is displayed on X (Twitter) – as a small line or as a large image preview.

## Why it matters

- Without the declaration the preview is plainer and gets noticed less often.
- The Open Graph tags (title, image, description) are reused for it.

## How to fix it

Add the card type in the `<head>` – for a large preview image:

    <meta name="twitter:card" content="summary_large_image">

A suitable `og:image` should be present so the large card takes effect.
