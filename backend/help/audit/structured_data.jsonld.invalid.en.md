---
title: "Structured data (JSON-LD) invalid"
summary: "A JSON-LD block on the page is not valid JSON and is ignored."
severity: warning
see_also: []
---

## Description

This page contains a `<script type="application/ld+json">` block whose content is
not valid JSON (e.g. a trailing comma, a missing quote). Search engines cannot
process it.

## Why it matters

- Structured data enables rich results (e.g. ratings, opening hours,
  breadcrumbs). If the JSON is broken, the benefit is lost entirely.
- A single syntax error makes the whole block worthless.

## How to fix it

Check the JSON-LD block for syntax errors – quickest with Google's Rich Results
Test or the Schema Markup Validator. Common causes:

- a comma after the last element,
- unescaped quotes in text,
- empty values produced by templating.

In Hugo it helps to generate JSON-LD from data with the `jsonify` filter instead
of assembling it by hand.
