---
title: "Image alt text missing"
summary: "An image has no alt attribute – bad for accessibility and image SEO."
severity: error
see_also: [img.alt.generic, img.alt.too_long]
---

## Description

On this page at least one `<img>` has no `alt` attribute. The alt text describes
the image in words – for people who cannot see it, and for search engines.

## Why it matters

- Screen readers read the alt text out; without it the image is meaningless to
  blind users.
- Google uses alt texts for image search and to understand the page.
- If the image fails to load, the alt text appears instead.

## How to fix it

Give every content image a short, descriptive alt text. In Markdown:

    ![Wheel alignment of a BMW 3 Series on the lift](/img/wheel-alignment-bmw.jpg)

Purely decorative images get an **empty** `alt=""` (then the screen reader
deliberately skips them).
