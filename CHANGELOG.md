# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

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
