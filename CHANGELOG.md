# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

### Fixed — the course-completion rule cost a query per notice, before the first byte (version 2026081307)

- **Every notice with a required course fetched that course again, inside page generation.** The
  rule loops the notices and, for each one with `reqcourse`, read the course row and asked
  `completion_info` about it. Two notices requiring the *same* course fetched it twice. Courses and
  completion answers are now resolved once for the whole set, in the shape the cohort rule ten lines
  above already uses.

- **Position is what made this the urgent one.** The rule runs with the page rules switched off,
  which means it also runs in `has_candidate_notices()` — the probe every page load calls before any
  HTML is sent. It was the only per-notice cost in the plugin sitting inside the TTFB, and therefore
  the only one delaying first paint; the rest of the per-notice work happens in the asynchronous
  call, after the page is already on screen.

- Reads per page-generation probe, by number of notices requiring a course:

  | notices | 1 | 3 | 6 |
  |---|---|---|---|
  | before | 1 | 3 | 6 |
  | after | 1 | **1** | **1** |

- Behaviour is unchanged, including the edge the guarded fetch produced: a notice whose required
  course no longer exists is still shown rather than withheld.

### Fixed — a cached empty result was treated as a cache miss (version 2026081306)

- **Both caches re-ran their query on every call whenever the answer was empty.** The lookups were
  guarded with `if (!$result = $cache->get(…))`, and an empty array is falsy — so the value was
  stored, found, judged a miss, and fetched again. Comparing against `false`, the only value the
  cache uses to say it does not hold something, is the whole fix.

- **It broke in the state nearly every site is in nearly all of the time:** the plugin installed
  with no notice currently live. That site paid a database read on every page load, for ever, to be
  told there was nothing to show. Measured on a running site with no live notice, reads per call to
  `helper::has_candidate_notices()` across six page loads: **1, 1, 1, 1, 1, 1** before, **1, 0, 0,
  0, 0, 0** after. The same fault hit `noticeview` for every user who had never acted on a notice.

- Covered by tests that count statements rather than inspect the cache, since the cost is the point,
  and paired with tests that a notice created after an empty answer is still found — so the cheap
  result cannot be reached by simply serving nothing.

### Added — the editor warns about a competing notice while you are still choosing (version 2026081305)

- **The collision warning is now live in the notice editor**, not only after saving. A new
  `local_awareness_check_collision` web service answers "who would this compete with", and
  `amd/src/collision_warning.js` asks it as the page-reach and repeat-interval fields change,
  writing the answer next to the page-reach field.

- **Gated on `local/awareness:manage`.** The reply names notices the caller may have no other way of
  seeing, so the capability check is the point rather than a formality. Only titles cross the
  boundary — a notice's page reach and audience are not the editor's to hand out.

- Requests are debounced so typing a path does not fire one per keystroke, and replies carry a
  sequence number so a slow answer cannot overwrite a newer question.

- The warning reads the notice id from the form's hidden field rather than taking it as an init
  argument, which keeps the PHP side of the editor's AMD call unchanged.

### Added — repeating notices that compete for the same pages are flagged (version 2026081304)

- **Two repeating notices aimed at the same pages take turns interrupting the same people.** Now
  that notices are shown one at a time that is a real consequence, and it is invisible while editing
  either notice on its own. `local\collision` finds them, and the author is told in two places: a
  **Competing** badge on the notice list, with a tooltip naming the rivals, and a warning after
  saving a notice that lands in one.

- **Told, never blocked.** Two repeating notices on the same pages is a legitimate thing to want, so
  this is a warning on the way out of the editor and never a validation error refusing the save.

- **Page reach is compared, not audience.** Two notices on the same pages but aimed at disjoint
  cohorts never actually meet, so this over-reports on purpose: computing audience overlap while
  someone types costs more than an occasional unnecessary warning, and a warning that is sometimes
  absent is worse than one that is sometimes redundant. Overlap is judged by asking
  `check_path_match()` — the display path's own matcher — about landmark pages, so the answer cannot
  drift from what actually happens; the `FRONTPAGE` / `MY` / `MYCOURSES` tokens overlap in ways the
  strings do not show.

- Notices that do not repeat are never flagged: one takes its turn and leaves, so it competes with
  nobody. Notices scheduled to start later are flagged, so the author hears about it before it
  starts rather than after.

- The badge is a plain `title` attribute rather than a Bootstrap tooltip, which would need the JS
  data-API and its differing attribute names on 4.5 and 5.x. It carries `text-dark` with
  `bg-warning`, as `tests/local/bootstrap_compat_test.php` requires.

### Changed — one notice at a time (version 2026081303)

- **Arriving at the site no longer stacks modals.** Every applicable notice used to be sent in one
  response; the module rendered them in sequence, so closing one immediately produced the next and
  a user with three pending notices had to clear all three before reaching what they came for.
  `helper::select_for_display()` now hands over the head of the queue only, and the next notice
  waits until the user reaches its situation again — in practice, the next page load where it still
  applies.

- **The queue has two tiers, separated by whether the user has met the notice before.** One at a
  time on its own would starve the queue, because anything that keeps coming back would hold the
  only slot for ever. So a **first occurrence** goes to the front — a repeating notice is seen
  promptly the first time, which is the point of setting one up — and **anything seen before** goes
  to the back. That last group covers three routes to the same problem: a repeat of a repeating
  notice, an acknowledgement closed without accepting, and a notice simply ignored. Repeats of
  repeating notices sort behind everything else, so they really do wait for the queue to clear.

- **One exception to one-at-a-time:** repeating notices in their first occurrence are delivered as a
  group. Deferring one behind another only delays a notice that is going to interrupt again anyway.

- Notices handed over are remembered in the session rather than the database. Recording a display
  would put a write on the read path, which is the one cost this plugin cannot afford on every page
  view.

- No JavaScript change and no AMD rebuild: the module already stopped when it ran out of notices, so
  the queue is expressed entirely by what the web service sends.

### Changed — the role rule's context scoping is now written once

- **`classes/local/role_scope.php` replaces two copies of the same thirty lines.**
  `helper::user_matches_role_filter()` and `audience\estimator` each built the join and where
  fragments that narrow a `{role_assignments}` lookup to the contexts a role rule names. Diffed with
  whitespace stripped, the two blocks differed only in what the local variables were called
  (`$filters` / `$criteria`, `$params` / `$inparams`) — the same question, asked twice, kept in step
  by nothing.

  They now call one builder. What stays separate is how each consumes the answer: the estimator
  tests membership inside an `EXISTS` over every user, the helper reads the roles back for one. The
  deliberate divergence survives untouched — a default role collapses to `1 = 1` in the estimator,
  because counting cannot enumerate an implicit assignment with no rows in `{role_assignments}`.

  A side effect worth having: the characterisation tests in `tests/role_filter_test.php` cover
  context levels the estimator has no tests of its own for, and now pin its scoping too, because it
  is the same code.

No version bump: this moves code without changing behaviour, so there is nothing for a site to
upgrade.

### Fixed — the audience breakdown ignored the scope of the role rule (version 2026081302)

- **A role rule scoped to a course or category was counted across the whole site in the editor's
  breakdown.** "Calculate reach" renders one chip per audience rule beside the combined total.
  Isolating a rule for its own chip built `[$rule => $criteria[$rule]]`, which for `filter_role`
  dropped `filter_role_context` and the course and category lists that scope it — so a notice
  meaning "teachers of this one course" produced a chip counting *every teacher on the site*, next
  to a total that had it right. The larger the site, the wider the disagreement.

  "The rule alone" means without the *other* rules, not without its own settings. The isolation now
  carries the keys that modify a rule rather than being one. Only `filter_role` has any, and
  `filter_category` / `filter_course` are read nowhere else in the count, so no other rule widens.

- **The bulk count is now pinned against the per-user rule it mirrors.** `estimator` is a second
  implementation of the role rule, `helper::check_filters()` the first, and until now they were kept
  in step by care alone. A test asks both about every user the count claims to cover and requires
  the same answer, for an unscoped rule and a course-scoped one. Verified by mutation in both
  directions: breaking the scoping on either side alone fails it.

### Changed — `check_filters()` no longer takes a page URL it never read

- **The `$pageurl` parameter was dead.** It sat between `$filtervalues` and `$courseid` and was not
  read once in the body: path matching belongs to `check_path_match()`, and the course context is
  decided by `$courseid` alone. Its presence invited the reader to assume the URL was consulted —
  the exact assumption that has to be right when judging who may see a notice — and forced every
  caller that wanted a course id to pass an empty string past it positionally, which is a mistake
  waiting to be made rather than one already made.

No version bump: nothing about this reaches a running site, so there is nothing to upgrade.

### Fixed — the role rule is now enforced when a notice is written to (version 2026081301)

- **A notice targeted at a role could be acknowledged, dismissed or click-tracked by anyone in the
  right cohort.** `helper::is_notice_available_to_user()` is the gate the write web services use.
  It checked `enabled`, the start of the scheduling window, cohort membership and the required
  course, but nothing inside `filtervalues` — because those rules lived in `check_filters()`, which
  needs the page URL a write request has no trustworthy source for.

  That reasoning holds for five of the six rules. It does not hold for the role rule, which asks
  what the user *holds*, not where they *are*, and so can be answered without a page. Until now it
  was not, and the acknowledgement report — the reason this plugin exists — could not be trusted
  for any notice targeted by role.

- **The rule now has one definition.** The role block moved out of `check_filters()` into
  `helper::user_matches_role_filter()`, called from the same position on the display path and from
  the write gate. The moved code is byte-identical to its previous form apart from indentation; the
  only deliberate change of shape is that the "no role filter set" guard now lives inside the
  method, where no caller can forget it.

  The **whole** filters array is passed, not just `filter_role`. `filter_category` and
  `filter_course` carry a second meaning inside that block — they scope which contexts the role
  assignment is searched for in — so dropping them would silently widen "teacher in this one
  course" into "teacher anywhere on the site" on the write path.

- **Two behaviours nobody had written down are now pinned by tests** (`tests/role_filter_test.php`),
  written against the previous implementation and kept unchanged across the move: a course-scoped
  rule takes the **union** of the course list and the category list, not their intersection; and a
  category-scoped rule reads the category list only, never the course list, unlike the
  course-scoped one which consults both.

- The docblock of `classes/audience/estimator.php` — a third implementation of the same rule, in
  bulk SQL — now points at `user_matches_role_filter()`, and records that its collapse of a default
  role to `1 = 1` is a deliberate divergence rather than drift. A second reference in that file
  cited `helper.php:491-502`, which had already drifted onto the wrong block; it now names the code
  instead of the lines.

### Fixed — an omitted page URL disabled every audience filter (version 2026081300)

- **`local_awareness_getnotices` declared `pageurl` optional, and an empty value meant "apply no
  page rules at all".** `retrieve_user_notices()` guarded both `check_path_match()` and
  `check_filters()` behind `if (!empty($pageurl))`, so any authenticated caller that left the
  parameter out received every enabled notice that passed the cohort and required-course checks —
  including those targeted at other roles, categories, courses, course formats, themes and
  competencies. The response carries the body rendered by `helper::render_content()`, so this
  disclosed notice **content**, not merely the existence of a notice.

  Demonstrated before the fix, with the same user and the same notice: a notice targeted at editing
  teachers returned zero results for a user without the role when a page URL was supplied, and
  returned that notice's title and rendered body when the parameter was omitted.

  `pageurl` is now `VALUE_REQUIRED`, and `get_notices()` rejects the empty string that a required
  parameter still admits. The plugin's own AMD module has always sent
  `window.location.pathname + window.location.search`, so no supported client is affected.

- **The permissive mode is now something a caller asks for by name, not something it falls into.**
  The skip was inferred from an empty string, and one function served both the display path and the
  navigation probe — which is what let the web service opt out of filtering by omission.
  `retrieve_user_notices()` now takes the page URL as a mandatory argument and refuses an empty one;
  `local_awareness_extend_navigation()` calls the new `helper::has_candidate_notices()`, which
  answers the page-independent question it actually has (should the JS load at all?), returns a
  bool, and renders nothing. Both routes share one private implementation whose page rules are
  driven by an explicit argument.

### Added — audience-estimate jobs are now purged (version 2026081201)

- **New scheduled task `local_awareness\task\purge_audience_jobs`.** Every click of "Calculate
  reach" wrote a row to `local_awareness_audience_jobs` and nothing ever removed one, so the table
  grew without bound — carrying a user id, the criteria JSON and timestamps for the life of the
  site. Jobs older than a day are discarded nightly; a completed job stops being reusable after
  `audience_job::DEDUP_WINDOW` (5 minutes), so the window is generous by two orders of magnitude.
  Deletion keys on `timecreated`, not `timecompleted`: a job that errored, or whose ad-hoc task
  never ran, has no completion time and would otherwise be kept for ever.

### Fixed — two long-standing metadata defects (version 2026081201)

- **`$plugin->release` was `'2026061600'`** — a version number in the release field, and one dated
  before the version it shipped with. It now reads `v1.0`, matching the convention used across the
  fleet.
- **The date selectors were hard-capped at the year 2030.** `stopyear` was a literal, so from 2030
  the "Active from" and "Expiry" pickers would offer no selectable year and scheduled notices could
  no longer be created or edited. It is now computed as ten years ahead.

### Changed — file headers follow the house standard, and restore upstream attribution

- **`@copyright  Catalyst IT` is restored on the 39 files derived from
  `catalyst/moodle-local_sitenotice`,** alongside the current maintainer's notice. Those headers had
  been replaced outright. This plugin is a declared GPL-3.0 derivative, and removing the original
  copyright notice from a derivative is a licence-compliance problem, not a formatting preference.

  The derived set was determined by comparing this tree against the upstream `MOODLE_403_STABLE`
  tree — 27 files match by path, and 12 more by the `sitenotice` → `awareness` rename (the eight
  event classes, `classes/persistent/awareness.php`, `lang/en/local_awareness.php`,
  `tests/awareness_test.php` and the Behat context). Every upstream PHP file has a counterpart here.
- **`@author` removed everywhere**, per Moodle convention and the house standard. Authorship stays
  in the git history and in the README credits; `@copyright` is what the licence requires.
- The stray "Forked and adapted by ..." prose line wedged between tags is gone, and every header now
  emits `@package` / `@copyright` / `@license` in that order with the house spacing. Other tags such
  as `@covers` are preserved.

No version bump: this commit changes comments only, so there is nothing for a site to upgrade.

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
