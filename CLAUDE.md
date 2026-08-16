# Claude instructions for `local_awareness`

This file is auto-loaded whenever Claude works in this plugin's directory tree.
**Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding style, CI gates,
lang-string rules, the `mdl` environment, git rules) — they are not repeated
here. This file keeps only what is true for this plugin.

Plugin context: a Moodle **local** plugin ("Awareness") that shows site
announcements to users in a modal, optionally requiring an acknowledgement and
recording who acknowledged or dismissed each notice. It owns six tables
(`local_awareness` plus `_ack`, `_lastview`, `_hlinks`, `_hlinks_his`,
`_audience_jobs`), four of which hold user-linked rows and are therefore in the
privacy provider. It integrates with core Report Builder (five datasources, two
system reports), the Cohort, Competency and Role subsystems, and injects itself
into every page through the `before_footer_html_generation` hook. Supports
Moodle **4.5 through 5.2** (`$plugin->requires = 2024100700`,
`$plugin->supported = [405, 502]`). CI is the moodle-an-hochschulen reusable
workflow, one job per supported branch in `.github/workflows/ci.yml` — **update
those jobs when `supported` changes**. Development happens on m501; the repo is
mounted at `local/awareness`.

## Commands

```sh
mdl ci moodle-local_awareness --branch MOODLE_405_STABLE   # lowest supported
mdl ci moodle-local_awareness --branch MOODLE_502_STABLE   # highest supported
mdl phpunit m501 local_awareness
mdl behat m501 @local_awareness
mdl grunt m501 local/awareness
```

**Run both ends of the range before pushing.** This is not a formality here: on
three consecutive releases the 4.05 phpcs leg failed on something the local
suite and the 5.02 leg both accepted (lowercase inline comments twice, a
multi-line `foreach` once). moodle-cs also cannot see PHP attributes on 4.05,
which is why test metadata stays in docblocks — see below.

**Never run Behat and `mdl ci` at the same time.** Measured: the suite went from
9 to 110 minutes and produced two WebDriver session failures that were not
defects. After any `version.php` bump, run `mdl upgrade m501 && mdl behat-init
m501 && mdl phpunit-init m501`, and confirm the init *finishes* — a half-upgraded
Behat site fails every scenario on the same core locator and looks like your bug.

## Where the real documentation is

- **`docs/RECONCILIACAO-2026-08.md` is the open-work list.** It gives every one
  of the 198 findings in `docs/AUDIT-2026-08.md` a verdict against the code with
  current-tree evidence. **Read it, never the audit** — the audit is explicitly
  the August snapshot of the starting point, and treating it as the open list
  sends you to re-investigate a hundred settled findings.
- `docs/PLANO-correcoes.md` — the four-phase plan, closed at 27/27.
- `docs/mockups/` — the approved HTML prototypes the admin surface was designed
  against, and `docs/README.md` records the design decisions that shape the markup.

## Architecture gotchas

- **The page probe is a superset of the display decision, on purpose.**
  `classes/local/page_probe.php` answers "could anything show here?" from the
  page alone, cheaply and fail-open; `helper::check_filters()` makes the real
  decision. `page_probe` **reimplements** the category/course/format logic, so a
  test passing there guards a *different copy* of the rules — `check_filters()`
  needs its own tests, which is why `tests/check_filters_test.php` exists.

- **`check_filters()` resolves the course through
  `can_access_course($course, null, '', true)`.** That `$onlyactive = true`
  demands an ACTIVE enrolment, so in tests an un-enrolled user gets
  `$course = null` and every branch returns false *for the wrong reason* — the
  negative cases pass while exercising nothing. Enrol the user. Consequence in
  production, and deliberate: a course-targeted notice does not appear on that
  course's own enrolment page.

- **A named placeholder may appear only once per statement**, and the privacy
  provider's four-way `EXISTS` union is the place this bites. See the fleet file.

- **Notice content is stored as the author wrote it** — `@@PLUGINFILE@@`
  placeholders, unfiltered markup — and resolved at render by
  `helper::render_content()`. Do not filter at save time: doing so froze
  multilang notices into the author's language for every reader, baked absolute
  URLs that break when `wwwroot` changes, and wrapped the body in a full HTML
  document from `saveHTML()`. Titles go through `format_string()` at every
  output point for the same reason.

- **`title` and `pathmatch` are `PARAM_RAW`** in the persistent, and
  `html_writer::tag()` does not escape its contents. Anything emitting either
  needs `format_string()` (title, which is prose) or `s()` (pathmatch, which is
  a URL pattern).

- **The hook callback runs on essentially every page**, so its `catch` is
  `\Throwable`, not `\Exception`. An `Error` escaping there is a site-wide fatal
  recoverable only from the database, and there is no failure of this pipeline
  worth taking the site down for.

- **Guests are not rejected; they are given a session-only marker.** All guest
  sessions share one user id, so writing shared rows for them let the first
  guest's dismissal suppress the notice for every later guest and corrupted the
  acknowledgement report. `dismiss_notice`, `acknowledge_notice` and `track_link`
  each handle this; do not "simplify" them into a blanket `isguestuser()` reject
  without deciding what guests should see.

- **`filter_role_context` is a MODIFIER of `filter_role`**, not a rule of its
  own: it is absent from both `estimator::AUDIENCE_FIELDS` and `CONTEXT_FIELDS`,
  and never reaches `rule_describer::describe()`. The five keys `describe()`
  handles are exactly the five `audience:rule:*` strings carrying a `{$a}`;
  `ruleLabel()` in the JS discards `display` when the label has no placeholder.
  This has been mis-filed as a defect once — it is not one.

- **Never relocate moodleform rows with JavaScript.** The editor did this once,
  hiding the source form with the clip technique, which is the technique that
  deliberately keeps content available to assistive technology: two fields were
  focusable and announced while painted nowhere. Let the form declare its own
  `header` sections and style the fieldsets.

## Testing notes

- **Test metadata stays in docblocks (`@covers`, `@dataProvider`) while 405 is
  supported.** moodle-cs on the 4.05 leg cannot see PHP attributes and reports
  `moodle.PHPUnit.TestCaseCovers.Missing` for every method in a class carrying
  only `#[CoversClass]` — 22 warnings from one converted file, on exactly one of
  the four legs. Move to attributes in the same commit that drops 405. The
  resulting PHPUnit deprecations (~75) are expected and do not fail the build.
- **Every "it did not happen" assertion needs a control** that proves the force
  which would have caused it was switched on. This repo has shipped vacuous
  tests: one created a deleted user who could never join the cohort, so the
  assertion was satisfied by the membership clause and `u.deleted = 0` could be
  deleted with the suite green.
- **`core_competency\api::is_enabled()` reads `get_config('core_competency',
  'enabled')`**, not `$CFG->enablecompetencies`. Setting the `$CFG` flag in a
  test leaves it returning true and sends the test down the wrong branch.
- **Mutation-test every new test**, and revert the mutation from a *file copy* —
  `git checkout --` restores from HEAD and silently discards uncommitted work
  alongside the mutation.

## When in doubt

Follow the patterns in existing files. The codebase is internally consistent —
if a new file feels like it matches no existing shape, re-examine the approach.
