---
title: "Internal link is broken"
summary: "A link on this page points to an address that does not exist in the built project."
severity: error
see_also: []
---

## Description

An internal link on this page points to an address for which no page was found
in the built `public/` folder. Visitors then land on an error page (404), and
search engines treat broken links negatively.

## Why it matters

- Broken links frustrate visitors and interrupt the path to the goal (e.g. the
  inquiry form).
- Search engines pass “link equity” only through working links.
- The cause is often a typo or an address left over from a rebuild.

## How to fix it

1. Check the target address named in the finding and compare it with the URL
   actually generated.
2. Correct the link in the content or layout file – mind the leading slash and
   the exact spelling.
3. If the target page was removed or renamed, point the link to the new address
   or set up an alias (redirect) in Hugo:

        ---
        aliases: ["/old-address/"]
        ---

## Note

If the target is served by a **PHP handler** (e.g. `/anfrage/` via an
`index.php`) rather than a Hugo page, the link is in fact valid. The check treats
`index.php`/`index.htm` as a directory index, so such a finding should no longer
occur.

## See also

Related rules are listed under “See also” above.
