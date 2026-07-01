---
title: "Dead link in the Markdown source"
summary: "A link in a content file points to a file/page that does not exist."
severity: warning
see_also: [link.internal.broken, hugo.frontmatter.required]
---

## Description

In a Hugo content file (`content/**/*.md`) a Markdown link or image reference
points to a file or page that does not exist in the project (e.g. a wrong
relative path or a deleted target file).

## Why it matters

- The link later leads nowhere on the website (404) and disturbs users and search
  engines.
- The error is already in the source – best fixed there before it goes online.

## How to fix it

Check the link named in the finding in the source file:

- For internal pages prefer Hugo's `relref`/`ref` – the build then reports broken
  references immediately:

      [to the overview]({{< relref "wheel-alignment/_index.md" >}})

- For images, check the path and provide the file (page bundle or `static/`).
  Replace deleted targets with the new address.
