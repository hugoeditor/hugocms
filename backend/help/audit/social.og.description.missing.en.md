---
title: "Open Graph description missing"
summary: "Without og:description the explanatory text below the heading is missing when sharing."
severity: warning
see_also: [social.og.title.missing, social.og.image.missing]
---

## Description

This page is missing `og:description`. That tag provides the short description
text in the preview when the page is shared.

## Why it matters

- Without a description the shared preview looks incomplete and less inviting.
- A good description increases the click and share rate.

## How to fix it

Add an Open Graph description in the `<head>`, in Hugo often from the page
description:

    <meta property="og:description" content="{{ .Description }}">
