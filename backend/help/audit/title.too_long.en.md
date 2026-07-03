---
title: "Page title too long"
summary: "The <title> is longer than ~60 characters and gets truncated in the search result."
severity: hint
see_also: [title.too_short]
---

## Description

This page's title is longer than about 60 characters. Google truncates long
titles in the search result (…), so the end – often the brand or the most
important addition – is no longer visible.

## Why it matters

- Truncated titles look unprofessional and lose meaning.
- The most important words should come first, before truncation happens.

## How to fix it

Shorten the title to **30–60 characters**. Put the main topic at the front and
drop filler words:

    ❌ "Professional wheel alignment for your BMW 3 Series at low prices at Autofit in the region"
    ✅ "Wheel alignment BMW 3 Series – prices & process | Autofit"

Account for an automatically appended `| {{ .Site.Title }}` when shortening.
