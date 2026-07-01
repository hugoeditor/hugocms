---
title: "Alt text too long"
summary: "The alt text is overlong – it should describe briefly, not be a paragraph."
severity: hint
see_also: [img.alt.missing, img.alt.generic]
---

## Description

An image's alt text is very long (well over ~125 characters). Screen readers read
it out in one go; a whole paragraph is tiring and defeats the purpose of a short
description.

## Why it matters

- An overly long alt text makes screen-reader use harder.
- Additional explanation belongs in the visible text, not the attribute.

## How to fix it

Keep the alt text to the **essentials** (around 125 characters). If an image
needs a fuller explanation, write it as visible text or a caption next to it.
