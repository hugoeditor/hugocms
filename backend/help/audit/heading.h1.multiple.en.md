---
title: "Multiple H1 headings"
summary: "The page has more than one <h1> – exactly one is the clearest, common structure."
severity: warning
see_also: [heading.h1.missing, heading.hierarchy_jump]
---

## Description

This page contains more than one `<h1>`. Although HTML5 technically allows
several H1s, exactly one main heading is the clearest and best-understood
structure.

## Why it matters

- A single H1 makes the main topic unambiguous – for users, search engines and
  screen readers.
- Multiple H1s often appear by accident (layout H1 plus `#` in the Markdown).

## How to fix it

Choose one H1 as the main heading and demote the others to `<h2>`/`<h3>`. Check
whether the layout **and** the content each create an H1, and remove the
duplicate.
