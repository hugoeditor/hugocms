---
title: "Sitemap with an invalid root element"
summary: "The file is valid XML but not a sitemap according to the sitemap protocol."
severity: error
see_also: [sitemap.xml.malformed, sitemap.xml.loc_missing]
---

## Description

The file can be read as XML, but its root element is neither `<urlset>` nor
`<sitemapindex>` – or it does not carry the namespace of the sitemap protocol:

```xml
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
```

## Why it matters

- Search engines recognise the file as a sitemap only by its root element and
  namespace. If either is missing, the file is rejected.
- Often a different file sits under the name `sitemap.xml`, such as an HTML
  error page or an RSS feed.

## How to fix it

- Check a custom sitemap template (`layouts/sitemap.xml`,
  `layouts/sitemapindex.xml`): the root element must carry the attribute
  `xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"`.
- Check whether a file at `static/sitemap.xml` overwrites the sitemap generated
  by Hugo.
- Remove the custom template if it is not needed – Hugo's built-in template is
  correct.
