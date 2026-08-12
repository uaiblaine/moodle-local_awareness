# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

### Fixed — notice content is stored as authored (version 2026081200)

- **Filters and file URLs are applied when a notice is rendered, not when it is saved.**
  `update_hyperlinks()` ran the content through `file_rewrite_pluginfile_urls()` and `format_text()`
  before storing it, which baked three things into the row: absolute `/pluginfile.php` URLs that
  break when `wwwroot` changes, the output of every text filter — freezing a multilang notice into
  whichever language the author happened to be using, for every reader — and a full
  `<!DOCTYPE html><html><body>` wrapper from `saveHTML()`. The parse now uses
  `LIBXML_HTML_NOIMPLIED|NODEFDTD` so it stays a fragment, and the new `helper::render_content()`
  resolves URLs and runs filters at display time. It leaves absolute URLs alone, so notices written
  under the old format render unchanged.
- **The upgrade unwraps existing rows.** Only the document wrapper is removed; the absolute URLs and
  already-expanded filter output cannot be reversed reliably from the stored text and keep rendering
  correctly. A notice is never blanked: if unwrapping yields nothing, the original is kept.
- **AJAX failures are no longer invisible.** Every rejection handler in `amd/src/notice.js` was
  `this.console.log(ex)` — inside a jQuery `fail()` callback `this` is the deferred, not `window`,
  so the handler itself threw and the failure disappeared. They now go through `core/notification`.
  `JSON.parse()` of the payload is also guarded: it runs inside `done()`, where `fail()` cannot see
  it, so a malformed response used to kill the modal silently.

The last item is not theoretical. The first draft of this change moved
`file_rewrite_pluginfile_urls()` onto the read path, which runs inside the web service, where
`lib/filelib.php` had never been loaded — a fatal that surfaced only as "the modal does not appear".
`helper.php` now requires `filelib.php` explicitly. PHPUnit could not catch it (its bootstrap loads
`filelib` for every test); Behat did.

### Fixed — click-history indexes and CI trigger (version 2026081103)

- **`local_awareness_hlinks_his` is indexed on the two columns it is queried by.** The table grows
  one row per link click and had only its primary key, while every read filters on `hlinkid` (the
  join in `linkhistory::count_clicked_links()`) or `userid` (its WHERE clause, and the privacy
  erasure path) — both full scans on a table with no upper bound. Declared as foreign keys, which
  in Moodle create the indexes without emitting a real constraint (`sql_generator::$foreign_keys`
  is false on every driver), so sites carrying click rows whose link no longer exists upgrade
  cleanly.
- **`db/install.xml` carries a current `VERSION`.** It still said `20220321`, against the fleet
  rule that the savepoint version, `version.php` and the install.xml `VERSION` move together.
- **CI no longer runs twice per pull request.** `on: [push, pull_request]` fired both triggers on
  every branch push that had a PR open, running 42 jobs where 21 cover the same commit. `push` is
  now limited to `main` and release tags, with `workflow_dispatch` as the manual escape hatch for a
  branch that has no PR yet.

### Fixed — course access is now judged on active enrolment (version 2026081102)

- **A suspended participant no longer receives the course's notices.** `can_access_course()`
  defaults its `$onlyactive` argument to `false`, which accepts any `{user_enrolments}` row at all
  — including suspended enrolments and ones whose time window has closed. `check_filters()` now
  passes `true`, restricting it to active enrolments in enabled plugins within their time
  restrictions, which is what "is currently in this course" has to mean for a targeted notice.

Recorded alongside it, because it reads like a bug and is not: a course-targeted notice does **not**
appear on that course's own enrolment page, since a user who has not enrolled yet fails the access
check. That is intended — the alternative leaks targeted content to anyone who guesses a course id.
Use a cohort or category filter for notices meant to reach people before they enrol.

### Fixed — audit remediation, second block (version 2026081101)

Repository hygiene and the web-service audience checks.

- **The release zip no longer ships development files to production sites.** The repo had no
  `.gitattributes`, and the install package is built with `git archive`, so `.github/`,
  `.gitignore` and `.claude/` were published with every release. The fleet template is now in
  place; `tests/` still ships, per Moodle convention.
- **The orphan gitlink is gone.** `.claude/worktrees/intelligent-lederberg-6f6840` was committed
  as a bare submodule entry (mode 160000) with no `.gitmodules`, so every CI checkout emitted
  `fatal: No url found for submodule path`. Untracked and added to `.gitignore`.
- **The interaction web services check the audience.** `dismiss_notice()`, `acknowledge_notice()`
  and `track_link()` took a notice or link id straight from the client and wrote a row for it. Any
  authenticated user could acknowledge a notice never shown to them, pre-dismiss one before it was
  published, or fabricate click history for arbitrary ids — which made the acknowledgement reports,
  the reason this plugin exists, untrustworthy. They now go through
  `helper::is_notice_available_to_user()`, and `track_link()` additionally requires the link to
  exist and to belong to a notice the caller can see.
- **Guest interactions are no longer persisted, but are remembered for the session.** Every guest
  session shares the single guest user id, so the first guest to close a notice hid it from every
  guest who came after. Guests now get the in-session marker only. Both halves are required:
  `retrieve_user_notices()` suppresses a notice solely by finding it in `$USER->viewednotices`, so
  skipping the marker as well would reopen the modal on every page load — and with `reqack` the JS
  blocks the backdrop and Escape, leaving the guest no way out.
- **A late acknowledgement is no longer discarded.** The write path checks only that the notice has
  STARTED, not that its window is still open. Blocking an unpublished notice is the point of the
  gate; throwing away a genuine Accept because the notice expired while the modal was open would
  lose the record this plugin exists to keep.
- **`get_notices()` no longer trusts the client's course id.** `check_filters()` used it to decide
  that a course- or category-targeted notice applies, so naming a course you cannot enter pulled
  that notice's content. The course is now dropped unless `can_access_course()` allows it.
- **`search_roles()` requires `local/awareness:manage`.** It was the one external function with no
  capability check, letting any authenticated user enumerate every role on the site.

The scheduling-window predicate was extracted into `helper::is_within_active_window()` so
`retrieve_user_notices()` and the new audience check cannot drift apart.

Nine external-function tests were added, each paired with a control that must record, and all
verified to fail without their fix.

### Fixed — audit remediation (version 2026081100)

Ten defects found by a full audit of the plugin, in the order they affect a running site.

- **Editing a notice no longer deletes the files embedded in its content.** `core\form\persistent`
  builds the editor's text/format pair but supplies no `itemid`, so `MoodleQuickForm_editor` minted
  an empty draft area and the save synced it over `local_awareness/content/<noticeid>`, destroying
  every embedded image. `notice_form::get_default_data()` now prepares the draft from the notice's
  own item id; the stray preparation against item 0 in `editnotice.php` is gone.
- **Notices created on a site with no cohorts are visible again.** The cohorts autocomplete posts a
  hidden `_qf__force_multiselect_submission` marker, and core only strips it inside the
  `!empty($this->_options)` branch of `HTML_QuickForm_select::exportValue()` — with no cohorts the
  option list is empty and the literal was stored as the audience, matching nobody.
  `awareness::normalise_cohorts()` now casts the selection to ids on both the create and update path.
- **The manage-notices page survives a deleted cohort.** `helper::get_cohort_name()` indexed the
  options array unguarded, so a removed (or invisible) cohort made the whole listing fatal.
- **The link-click report works on MySQL/MariaDB.** `linkhistory::count_clicked_links()` selected an
  unaliased `COUNT()`, whose result column is named `count` only on PostgreSQL; it is now aliased
  `clickcount` and both consumers were updated.
- **Report Builder no longer fatals when the action columns are aggregated.** The display callbacks
  were typed `?int` and Report Builder invokes them from a `strict_types=1` file, so Average handed
  them a float and raised a TypeError. `noticeview:action` is also declared `TYPE_TEXT` now, matching
  the char column it reads — claiming `TYPE_INTEGER` let Report Builder generate
  `AVG(1.0 * action)`, a hard PostgreSQL error.
- **Report Builder text columns are escaped.** Identity, notice-title and hyperlink columns emitted
  raw database values; they now pass through `s()` or `format_string()`, as core's own entities do.
  The hand-built link cell in the acknowledged-notice table is escaped too.
- **Privacy: `local_awareness_audience_jobs` is covered.** The table holds a `userid` and was absent
  from the provider's metadata, export and all three deletion paths — which also made core's
  `core_privacy\privacy\provider_test::test_table_coverage` fail. Deletion is now shared by one
  helper so a new table cannot be wired into one path and forgotten in the others, and it purges the
  `notice_view` cache that bulk deletes leave behind.
- **Privacy: users are found by every table, not just `lastview`.** A link click or an audience
  estimate can exist with no view record, and those users came back with an empty context list — so
  export returned nothing and erasure deleted nothing while reporting success.
  `get_contexts_for_userid()` and `get_users_in_context()` now test all four tables.
- **The Mustache gate is enforced again.** `mustache-continue-on-error: true` was set on all four CI
  legs for two `form="…"` attributes on buttons that `editor/shell.mustache` already renders inside
  the form. The redundant attributes are gone and the override is removed from every job.
- **The datasource stress tests run in CI.** They were gated behind `PHPUNIT_LONGTEST`, which
  moodle-plugin-ci never defines, and were hiding three real failures. Ungating them takes the
  suite from 280 to 3289 assertions.

Regression tests were added for the file-area, cohort-marker, missing-cohort and `COUNT()`-alias
defects, each checked to fail without its fix. The two audience-task tests that printed to stdout
(and one that asserted nothing) were fixed to capture and assert the task's output.

### Added
- GitHub Actions workflows for CI and Moodle plugin release.
- Baseline repository files for quality tooling (`.gitignore`, `.stylelintrc.json`).
- Core Report Builder integration for Awareness:
	- entities for notice, acknowledgement, notice view, and hyperlink click history
	- datasources for all notices, acknowledged notices, dismissed notices, notice views, and link history
	- system report pages for acknowledged and dismissed interactions
- New capability `local/awareness:viewreports` for report access control.
- New Makefile targets to execute datasource tests in CI/local workflows:
	- `ci-awareness-datasource-tests`
	- `ci-awareness-datasource-tests-quick`

### Changed
- Rebranded documentation and test navigation paths from "Site Notice" to "Awareness".
- Expanded plugin documentation with full feature and usage guidance.
- Updated Behat administration navigation path to `Awareness > Settings`.
- Notice management report actions now route to the new system report pages.
- Action links in manage-notice table improved for accessibility (`title`, `aria-label`) and clearer visual grouping.
- Plugin metadata support range updated to Moodle 4.5-5.2 (`supported = [405, 502]`).
- CI migrated from the Catalyst reusable workflow to moodle-an-hochschulen/moodle-workflows (`moodle-plugin-ci.yml`), called once per supported Moodle branch (5.02 full PHP×DB matrix; 5.01/5.00/4.05 PostgreSQL-only).

### Fixed
- PHPUnit data provider typo in awareness tests (`allowdeltion` -> `allowdeletion`).
- CI reusable workflow input mismatch (`disable_phpcpd` removed).
- AMD JSDoc compatibility issue in `amd/src/course_search.js`.
- Frontend now passes core grunt without `disable_grunt`: BEM `__` CSS selectors renamed to hyphenated form (core `selector-class-pattern`), missing AMD JSDoc added across `audience_estimator`/`live_preview`/`notice_editor`/`role_search` (core `eslint`), and AMD bundles rebuilt with source maps.
- Brought the plugin to a fully green MAH CI across Moodle 4.5–5.2: `phpcs`/`phpdoc` compliance, alphabetical lang-string ordering (en + pt_br), the remaining core eslint rules (`promise/*`, `capitalized-comments`, `consistent-return`) and stylelint nits, plus removal of a stray `test_roles.php` debug script.
- Behat: disabled collapsible short-forms so core `collapsesections` no longer hangs on the relocated editor form; let clicks fall through the sticky action bar to the fields behind it; and made the estimator's "Calculate reach" button always available as a manual recalculate.
- Mustache lint set to non-blocking (`mustache-continue-on-error`) for the editor's intentional cross-form `form=` submit pattern that standalone HTML validation rejects.
- Compatibility fixes for Report Builder API differences across Moodle versions:
	- `system_report_factory::create()` used instead of non-existent `make()`
	- system reports rendered via `$report->output()` instead of `$OUTPUT->render($report)`
	- datasource/system report SQL params updated to generated Report Builder-compliant parameter names
- Datasource test stability fixes for typed IDs and strict callback handling.
