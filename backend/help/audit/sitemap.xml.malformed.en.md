---
title: "Sitemap is not well-formed XML"
summary: "The sitemap cannot be read as XML – search engines discard it entirely."
severity: error
see_also: [sitemap.xml.root_invalid, sitemap.xml.loc_missing, sitemap.missing]
---

## Description

The sitemap in the built project is empty or contains an XML syntax error, such
as an unclosed element or an unescaped `&`. The message names the line of the
first error. For a sitemap index, the child sitemaps it lists are checked as
well; the finding shows the affected file.

## Why it matters

- An XML parser stops at the first syntax error. Search engines then ignore the
  whole file – including the entries before the error.
- The SEO check cannot read any pages from such a sitemap. Pages that would only
  be reachable through the sitemap are therefore also reported as "neither
  listed nor linked".

## How to fix it

Hugo generates the sitemap itself and escapes special characters correctly. An
error usually originates in one of these places:

- a custom template `layouts/sitemap.xml` or `layouts/_default/sitemap.xml`
  outputs values without escaping or leaves an element unclosed,
- the build was aborted and left an incomplete file behind,
- the file was changed by hand or by a script after the build.

Open the file at the given line, fix the cause in the template and rebuild the
site.
