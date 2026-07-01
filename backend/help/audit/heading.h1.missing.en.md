---
title: "H1 heading missing"
summary: "The page has no <h1> – the central content heading is absent."
severity: error
see_also: [heading.h1.multiple, heading.hierarchy_jump]
---

## Description

This page contains no `<h1>`. The H1 is the visible main heading and tells users
and search engines in one line what the page is about.

## Why it matters

- After the `<title>`, the H1 is an important topical signal.
- Screen readers use the heading structure for orientation.

## How to fix it

Make sure every page has exactly one `<h1>`. In Hugo it usually comes from the
content heading or the layout:

    <h1>{{ .Title }}</h1>

For Markdown content the first `#` heading becomes the H1 – make sure it is not
created twice (once in the layout, once in the content).
