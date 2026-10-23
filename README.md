[![ci](https://github.com/uaiblaine/moodle-local_awareness/actions/workflows/ci.yml/badge.svg)](https://github.com/uaiblaine/moodle-local_awareness/actions/workflows/ci.yml)

# Awareness

Awareness is a Moodle local plugin to display policy, compliance, communication, and onboarding notices in modal form.

The plugin supports three levels of insistence, from an informational notice through to one that cannot be clicked away and must be acknowledged, plus audience targeting and reporting for dismissed and acknowledged interactions.

## Credits

This plugin is a derivative work based on:

https://github.com/catalyst/moodle-local_sitenotice

Original project and historical contributors include Catalyst IT and contributors from the fork history
(such as Nathan Nguyen, Jwalit Shah, Dmitrii Metelkin, and Cameron Ball).

This repository is currently maintained and evolved under the Awareness direction.

## Feature overview

- Site-wide modal notices.
- Three levels of insistence: informational, blocking, and must-acknowledge.
- A named exit at every level, so a reader can always decline. At the two insistent levels the decline is recorded and the notice is shown again; an informational notice is dismissed once and stays dismissed.
- Audience targeting by cohort, role (optionally scoped to a course or category),
  course category, course, course format, theme, competency rules and, for a course
  notice, the course's groups.
- An audience estimate in the editor, showing the reach of the rules: refreshed as they are
  edited on a site with at most "Interactive audience estimate limit" users, and computed in the
  background on request (which needs cron) on a larger one.
- Optional requirement to complete a selected course before the notice stops appearing.
- URL path matching to scope notices to specific pages. Without a `%` the pattern must match the
  whole page address (path and query string); with a `%` anywhere, each `%` stands for any text and
  only the beginning of the address has to match.
- Scheduling via start date and expiry date.
- Perpetual notices (always active while enabled).
- Recurring re-display using reset intervals.
- Hyperlink extraction and click tracking from notice content.
- Reports for acknowledgements and dismissals with export options.
- Report Builder integration for custom report creation.
- System reports for acknowledged and dismissed interactions, integrated into notice management actions.
- Additional report data sources for notice views and hyperlink click history.
- Optional clean-up of related tracking/interaction data when deleting notices.
- Optional modal background image.
- Optional modal dimensions (width and height).
- Outside-click close behavior control.
- Cached notice resolution: the enabled notices and each user's view records are held in
  application caches, so a page asks the database only when something changed.

## Compatibility

This branch supports **Moodle 5.3** (PHP 8.3 or later). Each Moodle version has its own branch:

| Moodle | Branch | Releases |
|---|---|---|
| 5.3 | `main` (`MOODLE_503_STABLE`) | `v5.3-r<N>` |
| 5.2 | `MOODLE_502_STABLE` | `v5.2-r<N>` |
| 5.1 | `MOODLE_501_STABLE` | `v5.1-r<N>` |
| 4.5 | `MOODLE_405_STABLE` | `v4.5-r<N>` |

Each release is a GitHub release with its zip attached. A site that ran a version numbered by date
(up to 2026101303) switches as `CHANGELOG.md` describes.

## Administration paths

- Settings: Site administration > Awareness > Settings
- Management: Site administration > Awareness > Manage notice

## Capabilities

- `local/awareness:manage` creates, edits and deletes notices, at the site and in every course.
- `local/awareness:viewreports` reads the acknowledgement and dismissal reports. On its own it
  opens Manage notice read-only: each notice can be previewed and its reports opened, and nothing
  can be created or changed.
- `local/awareness:managecourse` and `local/awareness:viewreportscourse` are their course-level
  counterparts; see [Course notices](#course-notices).

The "Audience estimate finished" notification goes to whoever saved or recalculated the notice, a
site or a course author. It is not tied to a capability, so every user sees it listed in their
notification preferences.

## Configuration

### Enable plugin
- Setting: Enabled
- Effect: enables Awareness notices site-wide.

### Allow notice update
- Setting: Allow notice update
- Effect: permits editing existing notices.

### Allow notice deletion
- Setting: Allow notice deletion
- Effect: permits deleting notices.

### Clean up deleted notice data
- Setting: Clean up info related to the deleted notice
- Effect: when deletion is allowed, removes related records such as hyperlinks, hyperlink history, acknowledgements, and last view records.

### Link-click history lifetime
- Setting: Keep link-click history for (`local_awareness/linkhistory_lifetime`)
- Effect: how long each record of a reader following a link inside a notice is kept: for ever (the
  default), or 30, 90, 180, 365 or 730 days, purged nightly by the *Purge old link-click history* task.

### Interactive audience estimate limit
- Setting: Interactive audience estimate limit (`local_awareness/audience_sync_limit`, default 1000)
- Effect: on a site with at most this many users the editor's audience estimate is interactive;
  above it the estimate runs in the background on request. 0 never estimates interactively.

All settings are stored per plugin (`local_awareness/<name>`). The scheduled tasks are *Purge spent
audience estimate jobs* (daily at 03:20) and *Purge old link-click history* (daily at 03:40); the
*Estimate a notice audience* adhoc task runs a background estimate.

## Notice authoring

When creating or editing a notice, you can configure:

- Title and rich content.
- Start/end window and perpetual mode.
- Reset interval.
- Insistence: informational, blocking, or must-acknowledge.
- Cohort visibility.
- Required course completion.
- URL path matching.
- Modal presentation options (background image, width, height).

## Reports

From Manage notice, each notice from the Blocking level up provides access to:

- Acknowledgement report.
- Dismiss report.

The links are offered to whoever may read that notice's reports, and open the reports directly.
An informational notice records neither acceptances nor refusals, so it offers no report. Reports
include filtering and downloadable exports.

Each row names the user, and keeps the username and ID number the user had when the row was
written. Those two are identity fields: the reports show them, and let them be filtered on, only
when the site lists them in *Show user identity* (`showuseridentity`) and the viewer holds
`moodle/site:viewuseridentity` in the notice's context (the course, for a course notice), as core's
own reports do. With core's default (email only) neither column appears.

A course also lists **Notice acknowledgements and dismissals** on its Reports page: one row per
acknowledgement or dismissal across all of the course's notices, with the notice as a column. It is
offered to whoever may read the course's reports (`local/awareness:viewreportscourse`, or
`local/awareness:viewreports` inherited from the site), and it keeps to the same group separation
as the list. Core shows the Reports page only to holders of `moodle/site:viewreports` in the
course; anyone else reads each notice's reports from the course's notice list.

### Report Builder

Awareness also provides Report Builder entities and data sources for:

- all notices
- acknowledged notices
- dismissed notices
- notice views
- hyperlink click history

This enables custom report composition with filters, columns, export, and reuse of core Report Builder capabilities.

## Development notes

- PHPUnit and Behat tests live under `tests/`, and ship in the release zip as Moodle expects.
- CI is the moodle-an-hochschulen reusable workflow, one job naming this branch's Moodle version.
  Run every leg locally before pushing:

  ```sh
  mdl ci moodle-local_awareness --matrix --behat
  ```

- Targeted runs: `mdl phpunit m503 local_awareness`, `mdl behat m503 @local_awareness`.
- Releases are tags `v<moodle>-r<N>`; the zip attached to each GitHub release is the `git archive`
  of the tag.

## Course notices

A notice can belong to a course. Grant `local/awareness:managecourse` in the course to whoever may
author its notices, and `local/awareness:viewreportscourse` to whoever may read their reports;
neither has a default archetype. The holder also needs to be enrolled, or to hold
`moodle/course:view`, because the pages live inside the course and `require_login()` is a gate of
its own. A course notice appears on its course's main page, to anyone who can open it — guests
included where the course allows guest access — so add a role, cohort or group rule to narrow it
further; category, theme and course-format filters do not apply to it, and it does not travel with a
course backup. Groups follow the course's group mode the way Moodle applies it everywhere else: in
separate groups mode an author without `moodle/site:accessallgroups` may address only their own
groups and sees only the notices aimed at them; otherwise, any group of the course. The course's
group mode does not have to be set for a notice to address a group — that setting governs how
activities separate participants, not who a notice reaches. The same separation applies to the
list's preview and to both reports. A holder of `viewreportscourse` alone sees the course's list
read-only, with the reports of each notice and nothing to create or change; a holder of
`managecourse` alone manages the notices and is offered no report links. What happens to a course
notice is logged in the course's context, so it appears in the course's logs. Deleting a course
deletes its notices; one left behind all the same (after a failed deletion, for example) stays in
the site list, where a holder of `local/awareness:manage` can delete, disable or enable it but not
edit it, because no page can show it any more.

## Data stored

The plugin owns seven tables:

- `local_awareness`: the notices (title, content, schedule, audience rules, layout), with the id of
  the user who last created or edited each (`usermodified`). A notice stays until a holder of
  `local/awareness:manage` (or, for a course notice, `local/awareness:managecourse`) deletes it,
  which needs *Allow notice deletion*, or until its course is deleted.
- `local_awareness_slides`: the carousel slides of a notice (media and caption), with
  `usermodified`. Removed with their notice.
- `local_awareness_hlinks`: the links found in a notice's content (text and address). No personal
  data. Removed when edited out of the notice, or with the notice when *Clean up info related to
  the deleted notice* is on.
- `local_awareness_hlinks_his`: one row per click on a notice link: the user, the link and the
  time. Kept for *Keep link-click history for* (for ever by default), and removed with its link.
- `local_awareness_lastview`: per user and notice, the last time the notice was shown and what
  the user did (dismissed or acknowledged). Kept until the notice is deleted with the clean-up
  setting on.
- `local_awareness_ack`: one row per acknowledgement or dismissal: the user's id, and copies of
  their username, first name, last name and ID number and of the notice title as they were at the
  time, the action and the time. These rows are the reports' records, kept as `_lastview` is.
- `local_awareness_audience_jobs`: audience estimate jobs: the user who asked, the audience
  criteria, the count and its per-rule breakdown. Deleted after a day by the *Purge spent audience
  estimate jobs* task.

Notice content, background images and slide media are stored as files of the plugin in the system
context, and go with their notice. Deleting a user account removes none of these rows; a data
deletion request does.

All of it is covered by the Privacy API. A data request exports, into the user's own context, the
user's rows of `_lastview`, `_ack`, `_hlinks_his` and `_audience_jobs`, with dates and the notice
title or link they refer to. A deletion request removes those rows and the user's cached view
records. The author stamp in `local_awareness` and `_slides` is declared but neither exported nor
erased, because blanking it would rewrite the record of who published a notice. The "Audience
estimate finished" notification is kept by core's messaging, whose provider exports and deletes it.

## Translations

The English strings ship in the package. The Brazilian Portuguese translation is maintained in this
repository (`lang/pt_br`) and reaches sites through [AMOS](https://lang.moodle.org/), as the plugins
directory requires; it is left out of the release package.

## Contributing

Issues and pull requests are welcome:

https://github.com/uaiblaine/moodle-local_awareness/issues

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html)
(`LICENSE`, `COPYING.txt`).

Copyright: Catalyst IT and contributors (the original `local_sitenotice`), and 2026 Anderson
Blaine.
