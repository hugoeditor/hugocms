---
title: "Duplicate id attributes"
summary: "An id value occurs more than once – ids must be unique per page."
severity: warning
see_also: [link.empty_href]
---

## Description

On this page the same `id` value appears on several elements. Per the HTML
standard, every `id` must be unique within a page.

## Why it matters

- Anchor links (`#id`), label associations (`for`) and JavaScript only reach the
  **first** element with that id – the rest becomes unreachable.
- This causes subtle bugs with anchor links and forms.

## How to fix it

Assign each `id` only once. A common cause is repeated building blocks (e.g.
several forms or accordions with a hard-wired id). Use unique values there, for
example with an index or slug:

    <section id="faq-{{ .Anchor }}"> … </section>
