---
title: "Multiple meta descriptions"
summary: "The page contains more than one description tag – exactly one is allowed."
severity: warning
see_also: [meta.description.missing]
---

## Description

This page has more than one `<meta name="description">`. Search engines then do
not know which description applies and often pick the wrong one.

## Why it matters

- The outcome is not controllable – sometimes one applies, sometimes the other.
- Usually a layout bug (description set in baseof and additionally in a partial
  or theme).

## How to fix it

Output the description in only **one** place (`partials/head.html`). Remove a
second description tag from a theme or partial.
