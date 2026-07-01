---
title: "Image dimensions missing"
summary: "An image has no width/height – this causes layout shifts while loading."
severity: hint
see_also: [img.alt.missing]
---

## Description

An image is missing the `width` and `height` attributes. Without them the browser
only learns the size once the image has loaded, and the content “jumps”.

## Why it matters

- Layout shifts (Cumulative Layout Shift, CLS) are a Google ranking factor (Core
  Web Vitals) and disrupt usage.
- With known dimensions the browser reserves the space from the start.

## How to fix it

Add `width` and `height` to `<img>` (scale the display with CSS):

    <img src="/img/wheel-alignment.jpg" width="1200" height="800" alt="…">

In Hugo, image resources provide the dimensions automatically:

    {{ $img := resources.Get "wheel-alignment.jpg" }}
    <img src="{{ $img.RelPermalink }}" width="{{ $img.Width }}" height="{{ $img.Height }}" alt="…">
