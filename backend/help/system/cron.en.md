---
title: "Setting up cron jobs"
summary: "HugoCMS runs three scheduled tasks via the server's crontab: build, improve, and the health check. This page explains how to set them up."
see_also: []
---

## What the cron jobs do

HugoCMS runs three scripts from the command line. They live in the installation
under `backend/cli/` and can be started **there only** — not through the browser.

- **Build website** (`cron-build.php`) — publishes any scheduled releases that
  have come due and builds the site with Hugo. It builds only when a release was
  actually due; otherwise the run is skipped (use `--force` to always build). No
  Pro license required. Optionally (project settings, Pro) the run then saves a
  version state — see below.
- **Improve content** (`cron-improve.php`) — lets the AI revise reviewed pages.
  Pro feature.
- **Health check** (`cron-healthcheck.php`) — checks the published website and
  reports problems by email. Pro feature.

The **Cron jobs** section of the system status shows when each task last ran, at
what interval, and whether one is overdue. If it says “Never run”, the matching
crontab entry is probably not set up yet.

## Adding them to the crontab

The scripts are scheduled through the server's crontab. Things to watch:

- **Full PHP path.** Give the complete path to the PHP binary in the crontab
  (e.g. `/usr/bin/php`); plain `php` is usually not on the path there.
- **`--host` for the Pro tasks.** The Pro license is bound to the domain. On the
  command line there is no domain name, so the two Pro scripts need
  `--host=example.com` with exactly the licensed domain.
- **`--mounts` for multiple websites.** If one installation runs several
  websites, each gets its own crontab entry with its mount configuration.

A common example for a single website:

    # Check every 15 minutes, build only when releases are due
    */15 * * * *  /usr/bin/php /path/backend/cli/cron-build.php --quiet

    # Improve three pages nightly
    0 3 * * *     /usr/bin/php /path/backend/cli/cron-improve.php --host=example.com --limit=3

    # Health check in the morning
    30 6 * * *    /usr/bin/php /path/backend/cli/cron-healthcheck.php --host=example.com

Each invocation only ever covers **one** website — there is no run across all
projects. With several websites, each therefore gets its own entries with its
mount file (`--mounts=…/mounts/<hash>.ini`); the two Pro scripts also with their
respective licensed domain (`--host`). Example for two websites:

    # Website 1 (one.example.com)
    */15 * * * *  /usr/bin/php /path/backend/cli/cron-build.php       --mounts=/path/backend/mounts/<hash1>.ini --quiet
    0 3 * * *     /usr/bin/php /path/backend/cli/cron-improve.php     --mounts=/path/backend/mounts/<hash1>.ini --host=one.example.com --limit=3
    30 6 * * *    /usr/bin/php /path/backend/cli/cron-healthcheck.php --mounts=/path/backend/mounts/<hash1>.ini --host=one.example.com

    # Website 2 (two.example.com)
    */15 * * * *  /usr/bin/php /path/backend/cli/cron-build.php       --mounts=/path/backend/mounts/<hash2>.ini --quiet
    0 3 * * *     /usr/bin/php /path/backend/cli/cron-improve.php     --mounts=/path/backend/mounts/<hash2>.ini --host=two.example.com --limit=3
    30 6 * * *    /usr/bin/php /path/backend/cli/cron-healthcheck.php --mounts=/path/backend/mounts/<hash2>.ini --host=two.example.com

You don't have to assemble these lines by hand: the `bin/crontab-entries.sh`
script in the release directory prints them ready-made for every configured
website — it reads the mount path, host, and license status from the mount
files. It does not change the crontab, it only prints the lines; review, then
apply them (`crontab -e`).

## Pausing tasks

Each of the three tasks can be paused per website without touching the host's
crontab. This is configured in the **project settings**; the system status shows
each task's current state and links straight there with a button. When a task is
paused, its script checks this on startup and does nothing; the crontab entry
stays in place and takes effect again as soon as the pause is lifted.

If the **build** is paused, scheduled releases do not go live — the review queue
points this out. If the **improver** is paused, the “to improve” list is not
processed.

## Automatic version state during the build (Pro)

If the source directory is under Git versioning, the cron build can save version
states automatically. Enable it in the **project settings** under “Save version
state automatically”; one toggle, two descriptions (each pre-filled with a
sensible suggestion, and the date is appended):

- **Before the build**, the cron secures pending, not yet versioned changes in
  the source directory with the first description — but only if any exist. This
  check runs on every cron build, even without a due release.
- **After publishing** due releases, the second version state follows with the
  publication description.

The split keeps the publication state limited to the published files, so stray
direct edits do not slip in. As when saving by hand, it includes all pending
changes in the source directory. Requires a valid Pro license; if saving fails,
the build does not abort — it is only logged.

## The special part: automatic improvement

The improver normally leaves its result as a **draft awaiting approval** —
someone reviews it and approves it. In **automatic mode** it schedules every
draft itself instead, at a random time within a daily window. Improved pages
then go live spread across the day rather than all at once.

Automatic mode is switched on in the app: **SEO check → Content review → To
improve**, using the *Schedule automatically* toggle there. The time window
(e.g. 07:00 to 16:00), the amount per day, and optionally excluding Saturday and
Sunday are set in the **project settings**.

How the schedule is distributed: the window is split into as many sections as
pages are allowed per day. Each page gets its own section and a random time
within it — so two releases never fall close together. For 07:00–16:00 and three
pages, that means one in the morning, one at midday, one in the afternoon.

Keep in mind:

- **The build interval sets the precision.** A release marked for 08:22 goes
  live only at the next build **after** that time. Without a regular
  `cron-build.php` nothing happens at the scheduled moment. The build runs only
  when a release was due — if you also schedule via Hugo's front-matter
  `publishDate`, run `cron-build.php --force` so that becomes visible regularly
  too.
- **The times are server time**, not your browser's. This also applies to the
  weekend exclusion: whether a day counts as Saturday or Sunday depends on the
  server's time zone.
- **Exclude weekends** (on by default): nothing is scheduled on Saturdays and
  Sundays; releases move to the next weekday. Can be turned off in the project
  settings if you want to publish on weekends too.
- **A too-narrow window.** If fewer minutes fit in the window than releases are
  wanted, the daily amount is quietly capped; the rest moves to the following
  days. The project settings warn about this.
- **`--limit` and the daily amount are two different things.** `--limit` sets how
  many pages a run processes (and thus the AI service cost); the daily amount,
  how many of them go live per day.

## Check before adding

The two Pro scripts can be run as a harmless trial — they change nothing:

    /usr/bin/php /path/backend/cli/cron-improve.php --dry-run
    /usr/bin/php /path/backend/cli/cron-healthcheck.php --dry-run

The trial run needs neither `--host` nor an INI argument: it calls nothing
license-bound, so `--host` is only required for the real run. The `hugocms.ini`
is never an argument anyway (the file next to the script always applies), and
`--mounts` defaults to `backend/mounts.ini`.

Only with **multiple websites** should the trial run be given the matching mount
file too — the same one the real cron uses (`--mounts=…/mounts/<hash>.ini`) — so
it previews the right backlog.

Afterwards the system status shows under **Cron jobs** whether the real runs are
arriving. A detailed version with all options is in the `README.md` in the
`backend/cli/` directory.
