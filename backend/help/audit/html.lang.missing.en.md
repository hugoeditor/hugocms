---
title: "Language attribute (lang) missing"
summary: "The <html> element is missing the lang attribute – important for accessibility and search."
severity: warning
see_also: [html.charset.missing]
---

## Description

This page's `<html>` element is missing the `lang` attribute. It states the
language the content is written in (e.g. `en`).

## Why it matters

- Screen readers choose the correct pronunciation from `lang`; without it,
  reading aloud sounds wrong.
- Search engines and browsers (translation prompt) use the language marker.

## How to fix it

Set the language in the layout, in Hugo from the page language:

    <html lang="{{ .Site.Language.Lang | default "en" }}">

For multilingual sites Hugo provides `.Site.Language.Lang` correctly per language
version.
