---
title: "Software update from the interface"
summary: "Administrators can bring HugoCMS and the Hugo binary up to date from the system status view. This is the same as bin/update.sh --all on the command line."
see_also: [cron]
---

## What the update does

The **Software update** card in the system status runs the shipped script
`bin/update.sh` of the release repo. There are two buttons:

- **Update everything** (`update.sh --all`) — pulls the latest release repo
  state (`git pull`), refreshes the app copies of all configured websites
  (`edit/` and `cms-api/` in the publish directory and in `static/`) and
  updates the Hugo binary if a newer release exists.
- **Update Hugo only** (`update.sh --hugo`) — replaces only the Hugo binary
  under `bin/hugo/hugo` when GitHub offers a newer release. The release repo
  and the websites stay untouched.

**Check for updates** asks beforehand: `git fetch` in the release repo and the
GitHub API for Hugo. Nothing is changed by the check.

During a run HugoCMS holds the website's build lock so no Hugo build collides
with the binary swap. The script output is shown verbatim below the buttons —
on failure it is the error description.

## Prerequisites

The script runs as the PHP process user. It needs:

- permission to start programs (`exec` must not be listed under
  `disable_functions` in `php.ini`),
- `bash` and — for the full run — `git` in the search path,
- write access to the release repo including `.git/` and to the publish
  directories of the websites,
- outbound access to GitHub (release repo and Hugo download).

If a prerequisite is missing, the card names the reason and disables the
affected button. The update remains available via SSH:

    hugocms-release/bin/update.sh --all

On Hetzner web hosting the SSH user is the same as the PHP user; there the
update works from the interface without further setup.

## After the update

The interface itself is replaced during the run. **Reload** the page
afterwards so browser and server use the same version. The build number at
the top of the system status shows the new state.

## Going back to a specific Hugo version

The interface always installs the newest release. To return to an older,
known-good version use SSH:

    hugocms-release/bin/get-hugo.sh --version 0.164.0

## See also

- Setting up cron jobs (`cron`)
