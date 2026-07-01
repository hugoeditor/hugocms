---
title: "Heading level skipped"
summary: "The headings skip a level (e.g. from H2 straight to H4)."
severity: hint
see_also: [heading.h1.missing]
---

## Description

On this page a heading is followed by one more than one level deeper (e.g. H2
directly followed by H4, with no H3 in between). The logical outline therefore
has a gap.

## Why it matters

- A clean order (H1 → H2 → H3 …) helps screen readers and search engines
  understand the structure.
- Skips often mean headings were chosen for their size (looks) rather than their
  place in the outline.

## How to fix it

Choose the heading level by the **outline**, not by appearance: after an H2 comes
an H3, then H4. Set the font size with CSS, not by picking a different level.
