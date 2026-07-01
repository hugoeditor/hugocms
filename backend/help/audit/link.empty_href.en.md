---
title: "Empty link (href)"
summary: "A link has an empty or “#”-only target – it goes nowhere."
severity: hint
see_also: [link.internal.broken]
---

## Description

This page has an `<a>` link with an empty `href` (or just `#`). Clicking reloads
the page or jumps to the top instead of leading to a target.

## Why it matters

- Empty links confuse users and screen readers (announced as clickable but doing
  nothing useful).
- Often a placeholder forgotten during editing.

## How to fix it

- If the link should go somewhere: enter the correct target.
- If it is a control (opens a menu etc.) with no real target: use a `<button>`
  instead of a link.
- If the link is not needed: remove it.
