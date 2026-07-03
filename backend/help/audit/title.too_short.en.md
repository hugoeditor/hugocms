---
title: "Page title too short"
summary: "The <title> is under ~30 characters and wastes ranking and click potential."
severity: warning
see_also: [title.identical_to_h1]
---

## Description

This page's title (the `<title>` element in the `<head>`) is very short (under
about 30 characters). The title is one of the most important SEO signals: it
appears as the clickable heading in search results, in the browser tab, and when
the page is shared on social networks.

## Why it matters

- A short title does not use the space available in the search result (Google
  shows up to ~60 characters).
- Important keywords and location context are often missing, so the page ranks
  worse for relevant queries.
- Descriptive titles increase the click-through rate.

## How to fix it

Write a descriptive title of **30–60 characters** that contains the page's main
topic and, where useful, the brand or location. In Hugo the title lives in the
content file's front matter:

    ---
    title: "Wheel alignment BMW 3 Series – prices & process | Autofit"
    ---

Avoid bare keyword lists; write a readable title a human wants to click.

## See also

Related rules are listed under “See also” above.
