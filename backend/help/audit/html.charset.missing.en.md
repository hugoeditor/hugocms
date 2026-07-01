---
title: "Character set (charset) missing"
summary: "Without <meta charset> accented and special characters can be displayed wrongly."
severity: warning
see_also: [html.doctype.missing, html.lang.missing]
---

## Description

The `<head>` is missing the `<meta charset="utf-8">` declaration. It defines how
the browser interprets the page's bytes as characters.

## Why it matters

- Without it (or with the wrong value) accented and special characters appear as
  “mojibake” (e.g. `Ã¼` instead of `ü`).
- The declaration should come as early as possible in the `<head>`.

## How to fix it

Set the character set as the **first** element in the `<head>`:

    <head>
      <meta charset="utf-8">
      …
    </head>

Save the files as UTF-8 as well (the default in Hugo).
