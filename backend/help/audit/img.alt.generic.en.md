---
title: "Alt text is generic"
summary: "The alt text is a placeholder like \"image\" or a file name – it says nothing."
severity: warning
see_also: [img.alt.missing]
---

## Description

An image's alt text consists of a meaningless word (e.g. “image”, “photo”,
“picture”, “DSC_0001.jpg”). It does not describe the image content.

## Why it matters

- A generic alt text helps neither screen-reader users nor image search.
- It often appears when the file name is kept on insertion.

## How to fix it

Describe what the image **actually shows** – short and specific:

    ❌ alt="image1"
    ✅ alt="Technician measuring the camber on the front axle of a BMW 3 Series"
