---
title: "URL slug with reordered words"
summary: "Two slugs consist of the same words in a different order – possibly the same page twice."
severity: warning
see_also: [filename.near_duplicate, filename.copy_suspect, title.duplicate]
---

## Description

This page's slug contains the same words as another one, just in a different
order (`summer-offer-2024` vs. `offer-summer-2024`). Often this is the same page
stored under two address variants.

## Why it matters

- Two addresses for the same content mean **duplicate content** and split the
  visibility.
- Inconsistent word orders look arbitrary and make the correct address hard to
  remember or link to.

## How to fix it

Agree on one binding word order and remove the second variant:

- Choose the clearer version and delete the other source file.
- If the old address is still requested, set up a redirect to the remaining page.
- If these really are two different pages, make that explicit in the slug with
  distinct terms.
