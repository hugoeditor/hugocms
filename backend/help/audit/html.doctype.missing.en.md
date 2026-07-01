---
title: "DOCTYPE missing"
summary: "Without <!DOCTYPE html> browsers render in “quirks mode” – with rendering bugs."
severity: error
see_also: [html.charset.missing, html.viewport.missing]
---

## Description

This page is missing the `<!DOCTYPE html>` declaration on the very first line. It
switches the browser into standards-compliant rendering mode.

## Why it matters

- Without a DOCTYPE the browser falls into “quirks mode” – older, inconsistent
  rules that cause layout bugs.
- A missing DOCTYPE points to a broken layout skeleton.

## How to fix it

Make sure the HTML output starts with the DOCTYPE. In Hugo it sits at the very top
of `layouts/_default/baseof.html`:

    <!DOCTYPE html>
    <html lang="{{ .Site.Language.Lang }}">

Ensure there is no blank line or output (e.g. from a partial) before it.
