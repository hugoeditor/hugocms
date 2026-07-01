---
title: "H1 heading is empty"
summary: "There is an <h1>, but with no text – it carries no meaning."
severity: warning
see_also: [heading.h1.missing]
---

## Description

This page has an `<h1>` that contains no text (empty, or only an image/icon
without alternative text). The important heading signal is lost.

## Why it matters

- An empty H1 is worthless to search engines and screen readers.
- Often an empty title variable or a bare logo sits in the H1.

## How to fix it

Fill the H1 with a meaningful text heading. If a logo sits there, give it an
`alt` text or move the logo out of the H1 and use a real text heading:

    <h1>{{ .Title | default "Wheel alignment for your BMW 3 Series" }}</h1>
