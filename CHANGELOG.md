# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

### Changed — a notice requiring a course now behaves like every other notice (version 2026081611, audit BIZ-08)

**This changes behaviour on live sites.** A notice with a required course had its recorded view
discarded by one SQL clause, so `resetinterval` had no effect on it and the notice returned at the
start of every session however the author had configured it. Worse, with `reqcourse > 0` +
`reqack = 1` + `resetinterval = 0`, pressing **Accept recorded nothing at all** — the reader was
shown a notice they had already accepted, by a button that could not clear it.

`reqcourse` is an **audience rule**, and six other places in the plugin already treat it as one: the
form puts it under the audience header, the estimator counts it as an audience rule labelled "Has
not completed required course", `is_notice_available_to_user()` and `collect_user_notices()` use it
as an availability gate, and the manage table chips it as targeting. This clause was the only site
reading it as "re-show for ever", and it carried no comment saying so.

The clause is gone. A reqcourse notice now obeys its reset interval, an accepted one stays
accepted, and someone who completes the course leaves the audience. `notice:reqcourse_help` is
reworded in both packs to say what the field actually does — that it targets, and that display
frequency belongs to the reset interval.

Administrators should know that a reqcourse notice configured with no reset interval and no
required acknowledgement now shows **once** rather than on every login. That was the intended
reading of an audience rule; it was not the previous behaviour.

Mutation-tested: restoring the clause turns both new tests red.

### Fixed — the first wave of standards cleanup

Eleven more findings, and eleven others confirmed as already fixed by earlier phases — they only
read as open because the census was written before those phases ran (LANG-03, LANG-07, LANG-10,
LANG-17, TEST-06, TPL-13, WS-16, REPO-08, REPO-13, PRIV-06, SEC-08).

- **The five oldest AMD modules had no GPL header, no `@module` tag and a forbidden `@author`**
  (audit JS-04). `notice.js`, `modal_notice.js`, `preview.js`, `notice_form.js` and
  `course_search.js` now carry the fleet header, and each docblock says what the module is for
  rather than who wrote it.
- **`modal_notice.mustache` used `class="close"`** (audit TPL-12), a Bootstrap 4-only name.
  `btn-close` is inside Moodle 4.5's own forward bridge, so it resolves on both branches.
- **The estimate task had no language string** (audit DB-08), so its name was untranslatable in the
  ad-hoc queue while the purge task beside it had one.
- **`track_link_returns()` declared a `redirecturl` the implementation never returns** (audit
  WS-15) — a contract telling its reader that a click-tracking call might navigate the browser.
- **`create_new_acknowledge_record()` typed its action as a string** (audit LANG-02) while the
  values are the int constants `ACTION_DISMISSED` / `ACTION_ACKNOWLEDGED`.
- **`renderer.php` had no file-level docblock** (audit LANG-16) — the block after the `use`
  statements documents the class.
- **`libxml_use_internal_errors(true)` was never restored** (audit BIZ-10), silencing XML warnings
  for everything later in the request.
- **A Behat step carried a copied comment about forum discussions** (audit LANG-19).
- **`tests/external/audience_external_test.php` declared the wrong namespace** for its directory
  (audit TEST-04).
- **`showAction()`'s JSDoc was wrong** (audit JS-07) — and the fix is the docblock, not the code.
  The calculate button stays visible on purpose: it is the author's manual recalculate control, and
  hiding it whenever an estimate is queued would remove the one thing they can do about a slow
  count. Changing the code would have been a regression dressed as a cleanup.

### Fixed — five behavioural defects the previous summary had written off (version 2026081610)

This release exists because of a mistake in the last one's summary. It said no substantive work
remained and that the open findings were cosmetic. That was wrong: the census never said it, and
the error was collapsing *Low severity* — a label inherited from the August audit — with
*cosmetic*. A re-reading of the open set found eight candidates with behaviour at stake. Six were
real; two were refuted (WS-13, BIZ-09).

- **The "Is perpetual" help never reached a single author (audit LANG-01).** The help string was
  defined in both language packs and maintained — and `addHelpButton()` was never called, so the
  sentence explaining what the field does existed and was invisible. Nothing in the pipeline can
  see that: a help string with no button is not an unused string, and not a broken one. The test
  now walks the language pack and requires every rendered field with a `_help` string to show it.

- **The reqcourse report column summed course ids (audit RB-02).** The column declares
  `TYPE_BOOLEAN` while the stored value is a course id, and Report Builder aggregates such a column
  arithmetically — so a percent aggregation produced a seven-digit percentage and an average
  produced the mean course id. The display callback hid it, because it only asked whether the value
  was empty. Normalised in SQL with a searched `CASE`, which keeps the declared type, stays plain
  ANSI for MariaDB, and leaves stored aggregations valid.

- **Typing a title re-queued an ad-hoc task (audit M4).** Auto mode fires on every change to the
  form, including the title and the body, and `trigger()` never compared the criteria it had just
  read against the previous ones. So each pause in typing blanked the reach figure, queued a job
  row that nothing deletes, and spent a round trip restoring the number already on screen. The
  comparison happens **before** any side effect — bumping the sequence or cancelling the poll first
  would abandon an estimate the author is still waiting for — and the buttons pass `force`, because
  pressing Calculate is the author asking for a recount and a dead Calculate button is a defect
  this panel has already shipped once.

- **Criteria lists reached the database unbounded (audit WS-14).** They arrive as client JSON and
  reach `get_in_or_equal()` with one bound parameter per id, against a PostgreSQL ceiling of 65535
  and a planner being handed something nobody intended long before that. Capped at 500 at the web
  service boundary, and deliberately **not** inside `estimator::normalise()` — that also runs over
  an already-stored notice, so capping there would let the estimate and the hash describe the first
  500 ids while `check_filters()` kept honouring all of them, which is the panel quietly ceasing to
  describe the notice.

- **A failed estimate looked like a measured zero, and stuck (audit TEST-09).** `resultcount` is 0
  on an errored job, and `record()` wrote that 0 with a fresh criteria hash — so the editor showed
  "0 people" as though counted, and the stored hash then matched the criteria, which stopped the
  next unforced refresh from retrying. `record()` now refuses a job that is not `ready`.

Mutation-tested: removing the cap call kills a test, trimming everything to one kills a test,
removing the status guard kills a test, reverting the reqcourse `CASE` kills a test, removing the
help button kills a test, and removing the unchanged-criteria short-circuit kills two.

The 4.05 leg again caught what the others did not — the form render reaches TinyMCE's autosave
plugin, which reads `$PAGE->url`, and an unasserted `debugging()` fails PHPUnit only on 4.5.

**Still open, and a product decision rather than a defect to fix quietly: audit BIZ-08.** A notice
with a required course ignores the recorded view, so `resetinterval` has no effect on it and the
notice returns every session. In the combination `reqcourse > 0` + `reqack = 1` +
`resetinterval = 0`, pressing Accept records **nothing**. The two available fixes are not
equivalent — adding `OR resetinterval > 0` to the predicate is the minimal change and closes half;
dropping the `reqcourse = 0` clause entirely makes `reqcourse` the pure audience rule that the
other six call sites already assume and closes both halves, at the cost of rewording a shipped help
string. That choice belongs to whoever owns the product behaviour.

### Fixed — the privacy declaration now matches what is actually exported (version 2026081609, audit PRIV-01, PRIV-04)

- **The declaration named a subset of what the export ships.** `export_user_data()` selects
  `lv.*`, `ack.*`, `his.*` and `job.*` — whole rows — while `get_metadata()` declared between one
  and five columns per table. Eighteen columns therefore reached a data-subject's export file
  without appearing in the plugin's privacy declaration, which is the half of the contract a person
  reads *before* deciding whether to ask. A narrower declaration than the export is not a smaller
  disclosure; it is an inaccurate one. All four tables now declare every column they ship.

- **`local_awareness.usermodified` was not declared at all.** `core\persistent` stamps the author
  into it on every create and update, so a user id is stored in that table and has to appear in the
  declaration. It is declared and **deliberately** not exported or erased: a notice is site
  configuration, and blanking the column would rewrite the record of who published a site-wide
  announcement. Core treats its own admin-authored configuration identically — `analytics_models`,
  `analytics_models_log` and the `oauth2_*` tables each carry a `usermodified`-only entry with no
  export and no erasure. The test asserts both halves, so a later reader cannot "complete" the
  provider by wiring the table into the delete paths.

Fourteen new language keys in both packs, in alphabetical lockstep (273 keys each).

The first test compares the declaration against the **real columns of each table**, read from the
database, rather than against a list written in the test. A hand-written list would have to be kept
in step with the schema by the same discipline that failed here in the first place; reading
`get_columns()` means adding a column without declaring it turns the test red on its own.

Mutation-tested: dropping `noticetitle` from the acknowledgement declaration kills a test, reverting
the whole audience-jobs widening kills a test, removing the notice-table declaration kills a test,
and erasing `usermodified` along with the user kills a test.

**With this the census has no substantive item left.** The 74 findings still open are cosmetic —
header and docblock drift, the `html_writer` usage that grew from 4 sites to 24, and Mustache
docblocks that no longer describe what their templates read. None of them changes behaviour.

### Fixed — two asynchronous defects in the JavaScript (version 2026081608, audit JS-02, JS-03)

- **A stale estimate could overwrite a fresher one.** `pollOnce()` read the job id at send time and
  never checked the answer still belonged to the current request, and `trigger()` started a new
  estimate without stopping the poll already in flight. Since the editor debounces on typing, every
  pause can start a round trip — so an older answer landing last would repaint the reach figure for
  a question the author had already changed, with nothing on screen to say so. A monotonic
  `state.sequence`, captured at send time and compared on **both** the success and failure paths,
  plus `stopPolling()` at the head of `trigger()`. The counter discards an answer already on the
  wire; `stopPolling()` stops one not yet sent — neither covers the other. Spelled exactly as
  `collision_warning.js` spells it, which already shipped this pattern.

- **A failed dismissal looked exactly like a successful one.** Both click handlers called
  `modal.hide()` synchronously, before the web service had answered. An expired session, a dropped
  connection or a 500 therefore produced the same thing a success produced — the notice vanished —
  while the error went to the browser console and the acknowledgement report simply had no row. For
  a plugin whose purpose is evidence that a notice was seen, that is the worst available failure
  mode. The hide now happens in exactly one place, the empty-queue branch of `nextNotice()`, so a
  call that did not reach the server leaves the notice on screen.

  The re-entry guard that comes with it is required, not scope creep: `modal.hide()` on click was
  what made a second click impossible, and `modal_notice.js` routes outside-click and escape into a
  synthetic close-button click, so the window is not only a fast double-tap. `inflight` is set on
  entry to both write paths and released in `.always()`.

`amd/build` is rebuilt and committed in the same change, and a test asserts the bundles carry the
guards — a source fix shipped without its bundle changes nothing on any site, and fails silently.

**On how these are tested.** Neither defect is reachable by the tests this fleet runs: PHPUnit
never loads a JS file, and Behat drives a real browser where a response arriving out of order is
the one thing that will not reproduce on demand. The observer is therefore a source contract,
`tests/local/async_contract_test.php`, on the same footing as `criteria_contract_test` and
`bootstrap_compat_test` beside it. A source scan pins a shape rather than a behaviour, which is a
real limitation, so each assertion names the defect it exists for.

Two of those assertions were too weak on the first draft and mutation testing is the only reason
that is not still true. One asserted merely that `state.sequence` appeared somewhere in
`pollOnce()`, and passed with the `.then` guard deleted, because the capture line still mentioned
it — it counts the guard now, and requires one on each path. The other scanned a function body
extracted with a hard-coded four-space terminator, which over-read in `notice.js`, where functions
sit at eight inside the `define()` wrapper: the body of `dismissNotice()` came back with
`acknowledgeNotice()` attached, so a guard deleted from the first was still found in the second.
The terminator is explicit per module now, and the reason is recorded in the file.

### Fixed — the site switch now reaches the web services (version 2026081607, audit WS-08)

`local_awareness/enabled` is the only way an administrator can stop this plugin talking to users,
and it reached the footer hook alone. With it off the JavaScript was never injected — but all four
reader-facing web services stayed answerable to a direct POST, so on a site whose administrator had
switched the plugin off a notice could still be read, dismissed, acknowledged and click-tracked.

`helper::is_delivery_enabled()` is the shared reader, and it is called at the four entry points in
`classes/external.php` rather than inside the delivery helpers. The switch is off by default, so a
plain truthy read is correct here — this is not one of the default-ON checkboxes where only a
stored `'0'` counts as off. A disabled plugin returns an empty list and silently records nothing,
rather than raising: it should look like a plugin with nothing to say.

**Where the check goes was the whole problem, and getting it wrong first is what showed why.** The
obvious placement — inside `retrieve_user_notices()`, `is_notice_available_to_user()` and
`track_link()` — broke **34 tests**, because those helpers answer "what would this user be shown",
a question worth being able to ask with the switch off. The lesson was not that 34 tests needed
patching; mass-editing tests to restore green is exactly how this repository has produced tests
that pass while exercising nothing. It was that the switch belongs at each **entry point**, which
is where `hook_callbacks::should_load_on()` has always checked it. Moved there, the blast radius
fell to 14 tests, all in the one file that tests that boundary.

Those 14 now state the precondition once, in `setUp()`, with the reason written down — and
`test_the_site_switch_gates_every_delivery_web_service()` asserts the gate separately, so switching
delivery on for the rest of the file cannot hide the behaviour it was turned on to allow.
Mutation-tested: neutralising any one of the four gates kills a test.

### Fixed — three functional defects, and the repo files that were never there (version 2026081606)

Fourteen more findings closed. Five of those were already fixed by earlier phases and had only
been reading as open because the census listed the same defect under two ids (TPL-04, TPL-07,
RB-05, WS-11, DB-05); the census now says so.

- **The role picker could not find most standard roles (audit WS-03).** `search_roles()` filtered
  in SQL over `role.name` and `role.shortname`, but a standard role ships with an **empty**
  `role.name` and takes its label from the language pack through `role_get_name()`. So "Non-editing
  teacher", "Course creator", "Authenticated user" and "Authenticated user on site home" matched
  nothing in English — and under a translated pack, no standard role matched anything at all. The
  autocomplete does no client-side filtering: it sends the typed string and renders the answer
  verbatim, so what the query missed the admin could not select. The match moved to PHP and
  compares the displayed label, the stored name and the shortname; the stored name stays in because
  `format_string()` entity-escapes an ampersand, and a custom role called "R&D coordinator" has to
  be findable by the text its author typed rather than only by "R&amp;D". The 50-row cap now
  applies **after** filtering — capping in SQL limited the rows considered, hiding matches behind
  fifty non-matches.

- **The dismissed report counted page loads, not people (audit BIZ-04).** A notice requiring
  acknowledgement is deliberately shown again to someone who dismissed it, so the dismissal path
  runs on every page load until they accept — and each run inserted another acknowledgement row.
  The report headed "List of users who dismissed the notice" therefore listed the same person once
  per refusal. Now one row per reader per notice. The **event** still fires every time: a repeated
  refusal is a real event, it is the compliance row that must not duplicate.

- **A path rule matched any URL ending with it (audit BIZ-07).** `check_path_match()` appended a
  trailing `$` and no leading anchor, so a notice scoped to `/mod/quiz/view.php` also fired on
  `/anything/mod/quiz/view.php`. Anchoring the start is the fix, but not on its own: a Moodle
  installed in a subdirectory reports `/moodle/mod/quiz/view.php` while the author writes the path
  they see in the URL bar, so the pattern is tried against the target and against the target with
  the wwwroot's own path segment removed. Anchoring without that would have traded one defect for a
  quieter one — every path rule silently dead on subdirectory installs.

### Added — the fleet-template files, and a per-repo CLAUDE.md (audit REPO-03, REPO-05, REPO-06, REPO-09, REPO-12)

`phpcs.xml`, `.phpcsignore`, `.moodle-plugin-ci.yml`, `.stylelintrc.json` and the pull-request
template were all missing. `.gitattributes` had listed every one of them since before they existed,
so they are already kept out of the release zip — verified with `git archive`. `.gitignore` was
also actively preventing `.stylelintrc.json` from ever being committed, which is why the CHANGELOG
entry claiming it had been added stayed false.

`CLAUDE.md` now exists and records what is true for this plugin and not derivable from the code:
that `docs/RECONCILIACAO-2026-08.md` is the open-work list and the audit is not; that `page_probe`
reimplements `check_filters()`' logic, so tests there guard a different copy of the rules; the
`can_access_course($course, null, '', true)` enrolment trap that makes un-enrolled test users fail
every branch for the wrong reason; why titles and `pathmatch` need `format_string()`/`s()`; why
guests get a session marker rather than a rejection; and that `filter_role_context` is a modifier
rather than a rule, which has been mis-filed as a defect once already.

The README advertised `make ci-awareness-datasource-tests` against a repo with no Makefile, and
described audience targeting as cohort-only when the form offers seven criteria plus an
asynchronous reach estimate. Both corrected.

### Added — coverage that executes the class it claims (audit TEST-07)

`bootstrap_compat_test` declared `@covers \local_awareness\local\bootstrap` while every one of its
rules was a scan over source **text**, so neither `is_bs4()` nor `mark_page()` had ever run. Two
tests now cross the 405/499/500/502 boundary and assert the marker reaches the body on 4.5 and only
there. They swap in a fresh `moodle_page` and restore it: this is a `basic_testcase`, which does
not call `reset_all_data()`, so without that the body class would outlive the test and the negative
assertion would be reading a class left by an earlier run.

Mutation-tested: removing the start anchor kills 3 tests, removing the wwwroot allowance kills 1,
removing the dismissal guard kills 1, keying that guard on the notice alone kills 1, dropping
either the label or the stored name from the role match kills 1 each, inverting `is_bs4()` kills 2,
and making `mark_page()` unconditional kills 1.

**Deliberately left for their own slices**, with the reason recorded rather than forgotten: WS-08
(the site switch does not reach the web services) changes behaviour for many tests that never set
that config, and patching them en masse to stay green is precisely how vacuous tests get made;
PRIV-01 and PRIV-04 need twelve to thirteen new language keys in both packs; JS-02 and JS-03
require an AMD rebuild and form a natural JavaScript-only slice.

### Added — the coverage gaps the reconciliation named (version 2026081605)

The census in `docs/RECONCILIACAO-2026-08.md` found that the remaining debt was not broken code
but missing tests: nine of the thirteen Medium findings still open were coverage holes or blind
spots in `bootstrap_compat_test` itself. This closes them, and — as in the previous release — the
tests found defects that reading had not.

**Two live defects, both found by widening an existing observer (audit M31, M32, M33).**

- `report/acknowledged_systemreport.php` and `report/dismissed_systemreport.php` never called
  `bootstrap::mark_page()`, so the Bootstrap 4 polyfill in `styles.css` — which is gated on the
  body class that call adds — never reached either page. They rendered unstyled on Moodle 4.5
  while every static gate stayed green. `test_entry_points_mark_the_bootstrap_version()` could not
  see them because it globbed `*.php` in the plugin root only.
- `markup_files()` scanned three named directories, so `report/`, `renderer.php` and every
  root entry point were outside *every* assertion in that file. It now walks the plugin root with
  an exclusion list, which also means a directory added later is covered by default rather than
  invisible by default.

The BS5-only family map gained `ratio*`, `vr`, `fst-normal`, positional `top-*`/`bottom-*`, and
the missing siblings `form-select-lg`, `gap-{breakpoint}-*` and `translate-middle-x/-y`. The
positional pattern needs a `(?<!border-)` lookbehind: `\bbottom-0\b` matches inside
`border-bottom-0`, which is a border utility present on both branches, and without the lookbehind
the widened test reported two false positives in `modal_notice.mustache`.

**The guest account was excluded by a hard-coded id of 1 (audit SQL-04).** `estimator`'s base
predicate bound `guestid => 1`, which is only the guest on a site Moodle installed itself. After a
migration the literal put the real guest back into every audience count while excluding whichever
innocent user inherited the id. It reads `$CFG->siteguest` now; the username predicate beside it
stays, as a second net rather than a substitute.

**New tests (58), each mutation-tested:**

- `tests/helper_capability_test.php` — the plugin's only write gate, `check_manage_capability()`,
  had no negative test at any of its six call sites (audit M28). Each entry point is now run twice:
  refused for a plain user, accepted for a holder of `local/awareness:manage` granted through
  `assign_capability()` rather than `setAdminUser()`, which is what shows *which* capability the
  gate reads.
- `tests/lib_test.php` — `local_awareness_pluginfile()` had no test at all (audit M26), including
  the gate standing between a direct file URL and the attachments of a disabled notice. Removing
  that gate makes the callback **serve the file**, which is what the new case catches.
- `tests/reportbuilder/systemreports_test.php` — `local/awareness:viewreports` had **zero**
  coverage anywhere in the plugin (audit M11). Both reports get the same triple: refused for a
  plain user, refused for a holder of `manage` alone, granted for `viewreports` alone. The middle
  case is the one that matters — it is what would fail if `can_view()` ever read the wrong
  capability.
- `tests/external/search_courses_external_test.php` — `search_courses()` had no test of any kind
  (audit M11, M29) despite carrying a capability check and reaching every course on the site by
  name. Calls go through `call_external_function()`, so `execute_returns()` is applied.
- `tests/check_filters_test.php` — `check_path_match()` had no direct test (audit M30); its only
  coverage ran through `page_probe`, which *reimplements* the category/course/format logic, so
  those tests guarded a different copy of the rules. Four `check_filters()` branches had no case
  asserting false. Note the trap the controls exist to avoid: `check_filters()` resolves the course
  through `can_access_course($course, null, '', true)`, so an un-enrolled user gets `$course = null`
  and every branch returns false for the wrong reason.
- `tests/audience_estimator_test.php` — `test_estimate_excludes_deleted_and_suspended_users` was
  vacuous (audit M27): the deleted user was created already deleted, could not be added to the
  cohort at all, and the assertion was satisfied by the membership clause. Members are now added
  first and flagged afterwards, which is what puts the row in front of the predicate. Removing
  `u.deleted = 0`, `u.suspended = 0`, `u.confirmed = 1`, or both guest predicates now turns it red.
- `tests/external/audience_external_test.php` — a negative test for `get_estimate()`, and the
  first round trip of either audience function through the web-service layer, so
  `estimate_audience_returns()` and `get_estimate_returns()` are applied to a real payload. Those
  declarations are allowlists and `clean_returnvalue()` strips unnamed keys silently.

One mutation survived and is recorded as an equivalent mutant rather than a gap: deleting the
`!$course` guard from the category branch changes nothing, because `null->category` yields null and
the `in_array()` below it returns false anyway. Removing the branch entirely does turn the test red.

### Fixed — the audit reconciled, and the residue it exposed (version 2026081604)

`docs/AUDIT-2026-08.md` had always said it was the snapshot of the starting point rather than the
list of what was open, and that list had never been made. It exists now:
`docs/RECONCILIACAO-2026-08.md` gives all 198 findings a verdict against the current tree with
current-tree evidence. All ten blockers are closed; 87 findings are settled, 111 still want a
decision. Read the reconciliation, not the audit, to know what is open.

Four defects it exposed are fixed here.

- **A site-wide fatal waiting on any `Error` (audit C6).** `hook_callbacks::should_load_on()` caught
  `\Exception`, not `\Throwable`, around the notice pipeline — and it runs during footer generation
  on essentially every page. A `TypeError` from a typed setter, or a bad argument reaching
  `completion_info`, would have taken the whole site down for every logged-in user, recoverable only
  by disabling the plugin from the database. `page_probe` already used `\Throwable` at each of its
  four boundaries; this one had been left behind.

- **Notice titles were never filtered (audit C4, remainder).** An earlier release moved notice
  *content* to the correct arrangement — stored as authored, `format_text()` at render, so a
  multilang notice reads in each user's own language. The title was left on the old path: raw out of
  `to_record()` into the modal payload, and raw into the manage table's title cell and its `title`
  attribute. A multilang title therefore showed its markup literally in the heading above a body
  that resolved correctly. Both paths now go through `format_string()`. `pathmatch`, `PARAM_RAW` and
  emitted through `html_writer::tag()` two lines below, is escaped for the same reason.

- **`awareness_enabled` and `awareness_disabled` had never fired (audit X1-01).** `enable_notice()`
  and `disable_notice()` both created `awareness_updated`, under comments reading "Log enabled
  event" and "Log disable event". The two dedicated classes were complete, carried maintained
  strings in both packs, and appeared in the admin event reference — where an admin could build an
  event-monitor rule that could never fire. This was recorded as closed in the phase-4 plan and had
  not in fact been done; the new event tests are what make that impossible to repeat.

- **Erasure ignored the approver (audit PRIV-02, PRIV-03).** `delete_data_for_users()` took the
  userid from the context and ignored the approved userlist entirely, so a user the data-request
  approver had withheld was erased anyway — a refusal turned into a deletion. It is driven by the
  approved ids now, with the context still bounding what may be touched.
  `delete_data_for_user()` likewise processed only the first context and read the userid from it
  rather than from the contextlist.

### Added — the tests that guard the privacy work (audit M20, TEST-01)

The provider's four repairs (audit H5, H6, M18, M19) had no test of their own: core's compliance
test checks that a table carrying a userid is *declared*, and never calls `export_user_data()` or
any delete method, so every one of them could have been reverted with the suite green.

- `tests/privacy/provider_test.php` — 16 tests. Each of the four user-linked tables is seeded
  **alone**, because a user holding all four rows at once would satisfy the assertions just as well
  against the lastview-only SQL the findings describe. Covers the contextlist, the userlist, export,
  all three delete paths, the MUC view-cache purge, and non-user contexts.
- `tests/event/events_test.php` — 8 tests. Nothing had ever asserted that any of the eight event
  classes was constructed, which is how the enable/disable defect above survived. Each case asserts
  the event *class*, and one case asserts the three update verbs are distinguishable from each other
  — three tests each asserting `awareness_updated` would have passed throughout.

Mutation-tested: reverting the enabled event kills 3 tests, dropping the audience-jobs branch from
the contextlist kills 1, dropping two branches from the userlist kills 2, removing the cache purge
kills 2, and restoring the context-instanceid shortcut in `delete_data_for_users()` kills 1.

### Fixed — the Mustache example context that rendered its loop empty (audit TPL-03)

`editor/audience_panel.mustache` documented and exemplified `summary` as `{labelkey, value}` while
the template reads `{{label}}` and `{{key}}` and the exporter emits `key,label,value`, so the lint
rendered that loop with empty `dt`/`dd`. Exactly the failure the `mustache-continue-on-error`
override used to hide before audit H1 removed it.

### Fixed — the editor, reviewed against the rendered page (version 2026081603)

Five things the page showed that reading the code did not.

- **No section ever collapsed, and the accordion was mine to break.** The two-column layout was
  declared on `.fcontainer` — which is the element Bootstrap collapses. `display: flex` there scores
  (0,3,0) against Bootstrap's `.collapse:not(.show)` at (0,2,0), so removing `.show` changed nothing
  on screen. The layout rule is gated on the collapse state now.

  Two reports followed from that one defect. "Expand all" looked wrong because the form collapses
  its optional sections when they carry no values, and the CSS was showing them anyway: core's label
  described the state honestly while the screen contradicted it. And a Behat scenario had come to
  depend on reaching a field inside a collapsed section, which only worked while collapsing did not.

- **The action buttons are in core's sticky footer**, through `set_sticky_footer('buttonar')` — with
  `closeHeaderBefore('buttonar')` before it, which is not optional: the renderer wraps the group IN
  PLACE, so without it the footer is emitted inside the last section's container and collapsing
  "Modal appearance" took Save and Cancel with it.

  Centring them took more than a `justify-content`. The bar carries Bootstrap's
  `.justify-content-end`, and Bootstrap 5 generates its utilities with `!important`, which Moodle's
  stylelint forbids this plugin from outbidding. Giving the group the full width leaves the parent
  nothing to distribute and the centring happens a level below, where no utility competes.

- **Preview is a real preview now, and it moved into the button bar.** It used to draw a mock of the
  notice modal inside a core modal, showing truncated plain text with the formatting stripped — a
  picture of a dialogue, inside a dialogue, that had to be kept in step with the real thing by hand.
  It now puts the content itself into a `core/modal_cancel`, the same shape the manage list uses.
  Gone with it: the mock template, its module, ~2.5 KB of CSS, two renderable helpers and seventeen
  language strings in two packs.

- **The section descriptions read as a caption for the Title field.** Added in the previous release
  as `addElement('static')`, they inherited the label/element grid. They span the row now, under the
  header they describe.

- **The list's actions menu is right-aligned.** The column carried `text-end`, which aligns text and
  does nothing to a flex row; core renders the menu as a `.menubar` with `display: flex`.

The preview button shipped inert for one Behat round, for a reason worth recording: inside the
sticky footer core renders the group from a different template, without the `#fgroup_id_buttonar` id
the module was looking for. The CSS had already been corrected against the measured DOM and the
selector in the JavaScript had not.


### Changed — the editor stops promising things it does not do (version 2026081517)

Phase 4 of `docs/PLANO-correcoes.md`. Mostly removal, plus the one feature that was designed and
never built.

**The autosave was already there, in core.** The owner asked for it to be implemented; measuring
first changed what implementing means. `MoodleQuickForm_editor` declares `'autosave' => true` among
its defaults on both supported branches, `helper::get_file_editor_options()` never overrides it, and
`tiny_autosave` ships in `lib/editor/tiny/plugins/autosave` with its own tab arbitration and its own
privacy provider — so the notice body, the one field where losing work hurts, has been autosaved all
along. `formslib` already wires `core_form/changechecker`, so the tab-close warning is here too. A
plugin draft store would have duplicated the largest and most sensitive field, and would have had to
write into a moodleform to restore it — the pattern the fleet standards ban and the one that
produced this page's last two shipped defects. What ships instead is a save-state line that says
something true: when the notice was last saved, becoming "Unsaved changes" the moment the form is
touched.

**The required-fields banner became a will-not-display banner.** The string it was built for
promised a completeness check the form already enforces — `HTML_QuickForm::validate()` applies
`required` rules regardless of the `client` marker, so an empty title or content is refused server
side and that banner could never fire. What the form genuinely cannot express is a relationship
*between* fields, so the banner now names the three windows no instant can satisfy: expired,
inverted, and a start date with no expiry. The last is the one worth naming — it reads to an author
as "from March onwards" and behaves as "never", because the window check compares `now < 0`.

**The status badge gained a third state.** It read "Live · being shown" from the `enabled` flag
alone, which is true about the flag and can be false about the world. A published notice nobody can
reach now says so, from the same predicate the banner uses, so the chip and the sentence under it
cannot disagree.

**Removed:** the two legacy report pages and everything only they used — both table classes, the
report filter, its two forms and the renderer's two methods, about 1030 lines. Nothing linked to
them; they were reachable only by typing the URL, and they checked the wrong capability, so a
holder of `viewreports` could not open them anyway. The `user_notices` cache, declared, purged and
translated but never read or written, which `docs/ACHADO1-decisao.md` had already decided to delete
and never did. `preview.modal_height`, exported and documented and applied by nothing. The `$formid`
and `$cancelurl` the editor renderable stored without exporting, and the regular expression in
`editnotice.php` that existed to compute the first of them. Sixteen orphaned language strings from
both packs.

**Rendered rather than removed:** the five per-section descriptions, which existed in two languages
and never reached an author. `addElement('static')` is how a moodleform carries prose — no markup
injected, no row relocated.

Two consequences of the deletion, recorded rather than quietly absorbed. The M24 escaping fix from
phase 1 went with the class it lived in; deletion is the stronger fix, and the plugin no longer has
a table that renders `idnumber` at all. And `linkhistory::count_clicked_links()` now has no
production caller — report-builder covers click history with individual rows — but it keeps the
tests written for audit finding M17, so it is flagged in the plan rather than deleted in the same
breath as something it was not part of.


### Fixed — seven correctness and cost defects (version 2026081515)

Phase 3 of `docs/PLANO-correcoes.md`. Every item was re-confirmed against the code as it stands
after phases 1 and 2 before anything was changed; all eight were still real, and one was deferred
rather than fixed.

- **A linked-to filtered list rendered an empty search box, and the first click widened it.**
  `managenotice.php` accepts the filter values as URL parameters on purpose, and the server honoured
  them, but the value reached the template as `filters.namevalue` and no markup consumed it. The
  reader saw a short list with nothing explaining why — then touching Status made the JavaScript
  read the empty box and push a filterset without the name, silently restoring every row. The
  "Clear filters" button had the matching half of the bug: it is revealed by the same function that
  applies filters, which nothing calls until something is touched, so a deep link offered no way out.

- **Recording an audience count re-showed the notice to everyone.** `record()` wrote through the
  persistent, and `core\persistent::update()` is final and stamps `timemodified` unconditionally —
  and in this plugin `timemodified` is not metadata, it is the "the author changed this" signal that
  `must_reshow()` reads and that `reset_notice()` consists entirely of. So clicking Recalculate was
  a silent Reset, and since nothing dedupes acknowledgements, people could write a second row. The
  three measurement columns are written around the persistent now, which also stops `usermodified`
  being falsified by whoever happened to queue a cron job.

- **A second notice stole the first one's queued estimate.** `refresh()` joins an in-flight job by
  criteria hash, and a hash names a set of filters rather than a notice — two site-wide notices with
  no filters hash identically. `attach()` then overwrote the job's owner, leaving the notice that
  raised it waiting for a result that would never be written to it. The fix refuses to *join* rather
  than refusing to attach: a no-op attach would have moved the defect to the other notice.

- **The theme rule never matched a course or category theme.** It read `$PAGE->theme->name` inside
  the get_notices web service, where `$PAGE` never had `set_course()` called, so
  `moodle_page::resolve_theme()` skipped exactly the branches the rule exists for and always
  answered the site theme.

- **Deleting a notice left its files behind for ever.** Nothing removed the `content` and `bgimage`
  file areas, so every uploaded image survived in moodledata and `{files}` — and was unreachable at
  the same time, because the pluginfile gate resolves the notice first and refuses one that is gone.
  The files go with the notice now, not with the optional cleanup setting.

- **The notice list re-scanned the cohort table once per cohort per row.** Measured: 40 reads for a
  page of ten notices with two cohorts each, now 2. The memo lives on the table object, which exists
  for one render — deliberately not the `static` the audit recommends, because
  `phpunit_util::reset_all_data()` resets a hardcoded list of core caches with no hook for plugin
  ones, so a plugin static survives `resetAfterTest()` and leaks across test methods.

- **The audience column issued the per-row query its own comment claimed it avoided.**
  `state_of()` falls through to the jobs table whenever the stored hash does not match, which is
  every notice predating the audience upgrade. The in-flight hashes are resolved once per page now,
  in the same place the clash titles already were. Measured: 10 reads, now 2.

**Deferred, with a reason.** "Remove selected" on the report filter is wired to nothing — confirmed,
but its entire blast radius is inside the two legacy report pages that plan item 4.6 proposes
deleting. Fixing a button on a page that may not survive the next phase is work with a short life,
so it moves behind 4.6 rather than being done here.

Seven mutations, seven dead tests, each with the mutated line printed before the run.

### Fixed — five audit findings that were written down and never fixed (version 2026081514)

Phase 2 of `docs/PLANO-correcoes.md`. Each has carried its own section in `docs/AUDIT-2026-08.md`
since August; each was still true in the code.

- **M3 — the audience estimate answered a different question from the one the editor asked.**
  `audience_criteria.js` never sent `filter_role_context`, so the server read 0, meaning "any
  context": a rule scoped to one course was estimated across the whole site, and where the role was
  the site's default the estimator took its `1 = 1` shortcut and reported the entire user base. The
  runtime honoured the stored context all along, so the panel and the notice disagreed by orders of
  magnitude. A new `criteria_contract_test` now asserts that the editor sends every field
  `estimator::normalise()` reads, and nothing it does not — the field was *absent*, not wrong, and
  nothing in the pipeline reads a JavaScript file looking for an absence.

- **M12 — a dismissed forced-logout notice could never be cleared.** The display path re-shows one;
  the acknowledge path did not carry that condition, so it reported the notice as already handled
  and `acknowledge_notice()` returned before writing the row, before the event and before the
  logout. The user got the modal back on every page load with an Accept button that did nothing,
  and Close — which logs them out — as the only control with an effect. The two lists of conditions
  are one predicate now, `must_reshow()`, because they drifted precisely by an absence that a
  reader had to notice rather than see.

- **M13 — a notice aimed at a hidden cohort reached nobody.** Three code paths disagreed about what
  membership meant: the form offered hidden cohorts as targets, the estimator counted their members
  with no visibility predicate, and the runtime used `cohort_get_user_cohorts()`, whose SQL demands
  `c.visible = 1`. An author picked one — the ordinary way to model a staff-only audience — the
  panel confirmed a number, and nothing was ever shown, with nothing logged. There is one resolver
  now. Visibility governs who may *target* a cohort, which phase 1 already enforces at save time;
  whether somebody is *in* one is not a question about who is looking.

- **M14 — fixing a typo in a link's label threw away its click history.** Link identity included the
  anchor text, so a renamed label minted a new id and retired the old one; the history rows stayed
  behind an id nothing joins to any more, invisible to every report and impossible to clear by hand.
  A link is identified by where it goes now, and a link that really is removed takes its history
  with it. One consequence worth knowing: two anchors in the same notice pointing at the same URL
  share one tracked link, so their clicks are counted together.

- **M16 — a read-typed web service wrote into core's tables.** The competency rule was evaluated
  through `core_competency\api::get_user_competency_in_course()`, which creates the
  `user_competency_course` relation when none exists. Reached from `local_awareness_getnotices`,
  declared `'type' => 'read'`, it meant that merely opening a course page covered by a
  competency-filtered notice materialised competency state for a user nobody had assessed, and
  core's competency reports began listing them. The row is read directly now; a missing one means
  not proficient, which is what the absent relation meant anyway.

Six mutations, six dead tests: the `filter_role_context` send, the `forcelogout` condition, the
membership resolver, both halves of the link fix and the competency read were each reverted in turn
with the mutated line printed, and the matching test failed every time.

### Security — phase 1 of `docs/PLANO-correcoes.md` (version 2026081513)

Four items, none of them a privilege escalation: each needs `local/awareness:manage` already. They
are controls that did not do what they said.

- **`allow_update` did not protect the write.** `editnotice.php` consulted it only in
  `case 'edit'`, which decides whether to *display* the form, and the save branch runs before that
  switch is reached — so a POST updated a notice with the setting off. The asymmetry is what made it
  easy to miss: `delete_notice()` has re-checked its own setting inside the helper all along.
  `update_notice()` now does the same, and the page says so rather than redirecting in silence.

- **A cohort id off the wire was a membership oracle.** The estimator's predicate is a bare
  `cohortid IN (…)` with no visibility join, so any id a manager cared to type came back with a
  population size — including cohorts in categories they cannot see. Ids are now dropped to the set
  the caller may target, on the web service *and* on the save path, which was the same oracle by a
  slower route: save the notice, read the audience column.

  Not with `cohort_get_cohort()`, which the fleet note names. Measured on the running stack: it
  tests `in_array($cohort->contextid, $currentcontext->get_parent_context_ids())`, and for the
  system context that list is empty, so it returns false for **every** cohort — a visible
  system-level one included, even for an admin — and a site-wide plugin has no narrower context to
  give it. The check goes through the same call that builds the form's menu, so the two cannot drift.

- **Audit M24: `idnumber` reached the acknowledged report unescaped.** `other_cols()` returned
  `null` for every non-numeric column instead of deferring to `parent::other_cols()`, which exists
  precisely to return `s($row->$column)` for `email` and `idnumber`. The table declares an
  `idnumber` column and has no `col_idnumber()`, and users can set their own idnumber on most sites.

- **The capabilities now declare their real risk.** `local/awareness:manage` carried only
  `RISK_CONFIG` while letting its holder put arbitrary markup in front of every logged-in user —
  content is `PARAM_RAW` throughout, `render_content()` passes `'noclean' => true`, and the result
  reaches `Modal.setBody()`, which is innerHTML. That is `RISK_XSS`. `viewreports` declared no risk
  at all and shows users' email and idnumber; it is `RISK_PERSONAL`. The `noclean` stays — notice
  bodies legitimately carry embedded media — but trusting the author is a choice that has to be
  declared, not left implicit.

- **Audit M22 came along for the ride.** Neither table class declared the `$page` property they
  assign in their constructors, so PHP 8.2+ raised a deprecation on every report load. It is fixed
  here rather than in its own phase because the new table test cannot pass while it stands: CI runs
  PHPUnit with `--fail-on-warning`.

Every one of the four fixes was mutation-tested: the guard, the cohort filter on the save path, the
cohort filter on the web service and the escaping were each reverted in turn, the mutated line
printed, and the matching test confirmed to fail.

### Changed — the preview is a dialogue, and the button that opens it now does something (version 2026081512)

- **The preview opens as a `core/modal`.** It was a panel below the form; the approved model asks
  for a dialogue, and the page head has carried a Preview button since the redesign began —
  wired to nothing. A grep for its `data-action` found it in the template and in the design notes,
  and nowhere else: the button was dead markup for the whole of phases 1 and 2.

- **`core/modal`, not a dialogue of our own.** The focus trap, Escape, and returning focus to the
  button that opened it are the reasons — none of which the HTML prototype in `docs/mockups/` does,
  as its own comment says. A Behat scenario asserts Escape closes it, so replacing core's dialogue
  with a hand-made one cannot be a silent change; nothing else in the pipeline would notice.

- **The markup waits in a `<template>` element**, rendered server side once and handed to the modal
  as its body, so the slots keep the values no JavaScript recomputes and the strings resolve once.
  Template content is genuinely inert — not laid out, not focusable, not exposed to assistive
  technology. That distinction is the whole history of this page: the shape it replaced kept the
  form off screen with a 1×1 clip, which is the technique whose *purpose* is to keep content
  available to a screen reader, and shipped two fields a keyboard could reach and an eye could not.

- **The preview reads the form when it opens, instead of mirroring it as the author types.** The
  dialogue covers the form, so there is nothing to mirror while it is open. Reading late also fixes
  audit finding M5: the module used to bind to TinyMCE at boot, which is before core has finished
  injecting it, so `window.tinymce` was normally still undefined, the whole binding was skipped, and
  the content pane stayed empty for the session with nothing logged. At open time the editor is
  there. The reset interval is now read the same way, from the duration selector's own option text,
  rather than left at whatever was saved.

- **The mock modal's action buttons are `<span>`s.** They are a picture of the notice, so real
  buttons made them focusable and announced as actionable inside a dialogue where activating them
  does nothing.

- **Desktop and Mobile are `aria-pressed` toggles, not tabs.** They resize one preview rather than
  switching between two panels, and the previous `role="tab"` markup controlled no `tabpanel` — a
  promise to a screen reader that nothing kept. Their hit area went from 27×15 to 28 px tall, past
  the 24×24 WCAG 2.2 asks of a pointer target.

### Fixed — three things only the rendered page could show (version 2026081512)

Found by running an accessibility probe against the live DOM of each surface, captured from a Behat
faildump and served from the stack's own webroot so that the document, its stylesheet and its fonts
share one origin. Measured, not reasoned about.

- **The preview dialogue rendered with no colour at all.** The plugin's `--la-*` tokens were
  declared on `.local-awareness-editor`; core attaches a modal to its own element on `document.body`,
  a sibling of the editor rather than a descendant, and custom properties inherit down the DOM tree.
  Every `var(--la-*)` inside the dialogue therefore resolved to nothing — which does not fall back
  to the literal, it makes the whole declaration invalid at computed-value time. The hero lost its
  brand fill, the stage its scrim, and "Got it" became white text on nothing. The token block is
  declared on the preview root as well now.

- **The required-field marker was still at the far end of the row.** The previous release recorded
  it as fixed; it was not. Core's `.mform:not(.full-width-labels) .col-form-label .form-label-addon`
  scores (0,4,0), and so did the override, which leaves source order to decide — and in the compiled
  sheet core's rule lands at byte 1295582 against the override at 525383. Matching core's own
  `:not()` takes the override to (0,5,0) and settles it. The screenshot is what caught this: the
  rule was written, shipped, and never applied.

- **The "Notice" chip on the preview's hero read at 3.84:1.** A translucent *white* scrim over the
  brand colour lightens it, so white text on top loses. Darkening instead clears 4.5:1 over every
  brand tried, down to a white one, and over a background image as well.

- **The notice table now has a caption**, hidden visually because the heading above already says it
  in print. A screen reader lists a page's tables by name; unnamed, this one announced as "table".

Everything else the probe reports on these three surfaces belongs to core: the edit-mode switch in
the navbar, three items in the TinyMCE status bar, and the 16×31 close button in core's own modal
header — the last of which the dialogue's full-size footer Close button already satisfies as the
equivalent control WCAG 2.2 SC 2.5.8 allows.

### Fixed — two the review panel found in the dialogue itself (version 2026081512)

- **The Mobile viewport reported itself as pressed over a desktop-width preview.** The dialogue is
  built once and hidden rather than destroyed, so the toggles' pressed state outlives a close — but
  the width was decided in two places, the click handler setting the mobile width and `sync()`
  resetting it to the form's on every open. Reopening therefore redrew the mock at desktop width
  with `aria-pressed="true"` still on Mobile: a screen reader was told a viewport was selected that
  was not on screen, and getting it back meant clicking a control that already claimed to be
  pressed. One function decides the width now, from the one piece of state that should decide it.
  A scenario asserts both halves, because the button alone was already right while the bug was live.

- **The viewport toggles wore the browser's focus ring instead of the plugin's.** Same root cause as
  the token block above — the rule was scoped to `.local-awareness-editor` and the dialogue is not
  inside it. Measured rather than assumed: the toggles do take focus and Chrome does draw
  `outline: auto 1px`, so this was never a bare 2.4.7 failure, but they were the only controls on
  the plugin's surfaces not wearing its 3px brand ring.

### Fixed — the required marker sat at the far end of the row, and the editor gained tests (version 2026081511)

- **The required-field marker is back beside its label.** Boost pushes it away with
  `.mform:not(.full-width-labels) .col-form-label .form-label-addon { margin-left: auto }`, which
  reads correctly while the label column is a narrow `col-md-3` and reads as a stray icon once the
  column spans the row, as it does here. Core's own escape hatch is `set_display_vertical()`, which
  adds `.full-width-labels` — not used, because its effect could not be observed cleanly: the
  compiled stylesheet on the dev stack mixes in a sibling plugin's `.mform.full-width-labels` rules,
  which draw borders and backgrounds from that plugin's own variables. Overriding the one property
  is smaller and has no such reach.

- **Three Behat scenarios pin the editor's structure**, the first of them on the regression the
  rebuild exists for: every field is on the page, named individually for the two that used to be
  lost. It would have failed against the previous release.

- **The validation-error risk recorded in the design proposal needs no code.** A section holding an
  error is expanded by core itself — `formslib.php` calls `setExpanded($header, true, true)` for any
  header whose section contains one — which the plugin now gets simply by letting the form declare
  its own sections. Writing the test is what surfaced that: the modal-width rule is a *client*-side
  one, so the form never posts and there is no server-side error to provoke; the scenario asserts
  what actually happens, and the server-side guarantee is cited rather than faked.

### Fixed — three things the editor rebuild left behind (version 2026081510)

Found by looking at the rendered page, which the previous change had not done.

- **The content editor and the file picker sat in half-width columns**, with "Content" broken
  across two lines. The rules meant to make them span the row matched on the widgets' internal
  markup — `:has(.editor_tiny_wrapper)` and friends — and those class names were guesses, so they
  matched nothing at all. They are listed by field id now: an id cannot miss quietly, because a
  renamed field makes the rule stop applying visibly.

- **Labels sat beside their fields inside a 45 % column.** moodleform lays a row out as
  `col-md-3` + `col-md-9`, which leaves the label a quarter of a half. They stack above the field
  now, which is also what the approved mockup does.

- **The audience estimator had stopped reacting to field changes.** It located the form as
  `form.la-shell`, or inside `#la-moodleform-source` — both removed by the rebuild — and its
  `bindFormChanges()` returns early when it finds neither, so the auto-recompute died without a
  word and every Behat scenario stayed green, because they click Calculate explicitly. It finds the
  form by `form.mform` inside the editor region now. The dead `formSourceId` config the page still
  passed to the AMD module went with it.

Verified on the rendered page: one `h1` where there had been two, and `filter_role_context` is a
normal visible form row rather than a clipped one.

### Fixed — two form fields were reachable by keyboard and painted nowhere (version 2026081509)

The editor hid the whole moodleform in a 1×1 clipped container and moved its rendered rows into
hand-made cards with JavaScript, driven by a map of field names. Anything the map forgot stayed in
that container — and the container is hidden by the *clip* technique, the one that keeps content
available to assistive technology. **`filter_role_context` and the competency filter's label were
invisible on screen while remaining focusable and announced.** A keyboard user tabbed into nothing;
a screen-reader user could set a role-context filter that nobody looking at the page could see or
undo.

- **The form declares its own sections now.** `setDisableShortforms(false)` and one `header` per
  section makes each a core collapsible fieldset, which brings the accordion behaviour, the keyboard
  handling and the expand/collapse-all control with it. Short forms had been disabled precisely
  because core's collapse JS never settled on a hidden form — the reason disappears with the hiding.

- **The relocation is gone**, and with it the field map, the hidden copy, the side nav, the
  scroll-spy and the regex surgery that rewrote the form's own `<form>` tag into a `<div>`. This is
  a class of bug being removed, not an instance: any field added to the form in future is visible by
  construction rather than by remembering to update a list in JavaScript.

- **Sections are reordered around what they answer**: content, display, audience, display
  restrictions, modal appearance. The competency rules moved out of the display restrictions and
  into the audience, which is the question they answer.

- **The last two sections start collapsed, unless the notice already uses them** — and then they
  open past any stored user preference. A collapsed section holding a value is worse than an
  expanded empty one: the author cannot act on a filter the page will not admit is there.

- The sticky action bar is the form's own button group, styled in place. Buttons outside the form
  would need a `form=""` attribute — valid HTML, but the mustache lint cannot validate it in a
  partial rendered on its own, and it would mean two submit paths where one will do. Cancel stays
  native, so `is_cancelled()` keeps working.

- Preview and audience moved from a right-hand rail to panels under the form. The rail was
  `display: none` below 1280 px of *viewport*, which removed both without a substitute on every
  laptop and tablet.

### Added — the notice list has a filter bar, and it refreshes over AJAX (version 2026081508)

The front half of the change whose SQL landed in 2026081505.

- **A filter bar over the list**: search by name, status (active / draft / competing) and validity
  (permanent / current / scheduled / expired), with a "clear filters" button that appears only when
  there is something to clear. The filters are also URL parameters, so a narrowed list can be linked
  to and survives a reload; the AJAX path carries them in the filterset instead, and both end up in
  the same object.

- **No web service of the plugin's own.** `amd/src/manage_list.js` translates the controls into a
  filterset and hands it to `core_table/dynamic`; core fetches and swaps the table's markup. The
  module knows nothing about how a page of notices is loaded.

- **The result count and the empty state are rendered by the table, not by the page around it.**
  Both live inside the region the AJAX refresh replaces, so neither can end up describing a
  different list than the rows on screen. The first attempt put the count in the page and had
  JavaScript read `data-table-total-rows` back out and re-fetch a language string on every
  keystroke; moving it into `wrap_html_start()` deleted that whole path. The empty state has two
  shapes on purpose: a site with no notices is invited to create one, and a filter that matched
  nothing is offered a way back out instead.

- **The debounce registers pending work.** `setFilters()` registers its own, but the 250 ms window
  before it is a gap where nothing is in flight and the page looks idle — long enough for anything
  watching for quiescence to conclude the list had settled and read the previous rows.

- A strip of site totals sits above the table: active, drafts, combined reach and competing. These
  describe the **site**, not the filtered set — the dynamic-table service returns table HTML and
  nothing else, so filtered totals would need a service of the plugin's own, and the result count
  already answers "how many matched".

- Five Behat scenarios cover the filters end to end, including that searching "manutencao" finds
  "Manutenção" through a real browser against a real database. One of them asserts through the
  result count rather than a "should not see" on the other title: the conflict badge names its rival
  inside a visually-hidden explanation, so that title is legitimately in the page text even when its
  row is gone — which is the point of the badge, and makes whole-page negative assertions near it
  unreliable.

- `templates/editor/manage_shell.mustache` is deleted; the manage page had been borrowing the
  editor's chrome.

### Added — the notice list filters and pages in SQL (version 2026081505)

Back end only; the filter bar that drives it lands next.

- **`query_db()` is a real query now.** It read the whole thing through
  `awareness::get_records()`, whose filter argument is equality and nothing else — no `LIKE`, no
  date ranges — so filtering could only have happened in PHP after the fetch. That is the trap this
  change exists to avoid: narrowing rows after the query makes paging lie, fetching a page of 25 and
  rendering however many survived while the pager keeps counting the unfiltered total. A test
  asserts the total describes the filtered set and that every row on every page is one the filter
  kept.

- **`all_notices` implements `\core_table\dynamic`** with an `all_notices_filterset` of three
  optional filters: name, status (live / draft / **competing**) and validity (permanent / current /
  scheduled / expired). No new web service: core's `core_table_get_dynamic_table_content` serves it,
  the way the participants page is served. The constructor's arguments after `$uniqueid` became
  optional because that service builds the table with the unique id alone — a detail that would
  otherwise have failed only over AJAX, never on a page load and never in a test written the way the
  page builds it. A test asserts the whole contract for that reason.

- **The name search is accent-insensitive**, which is what `sql_like_ai()` was ported for: searching
  "manutencao" finds "Manutenção". The test asserts the accent-folding half only where the database
  can do it, and the case-folding half everywhere.

- **"Competing" cannot be a SQL predicate**, because whether two notices clash is decided by
  comparing page-reach patterns through `check_path_match()`. `collision::clashing_ids()` resolves
  the set once and the query narrows with `id IN (...)`, so the predicate stays inside the SQL and
  paging stays honest. It shares its overlap test with the badge, so filter and badge cannot
  disagree about what a clash is. The empty case is its own branch: an empty `IN ()` is not
  portable, and getting it wrong would show the entire table under a filter meaning the opposite.

- Each occurrence of "now" in the validity predicates carries **its own placeholder name**.
  `fix_sql_params()` counts placeholder occurrences against the parameter array and throws
  `duplicateparaminsql` when a name appears twice, so one value compared against both ends of a
  window is two names bound to the same value.

### Changed — the notice list is six columns instead of twelve (version 2026081504)

- **Four yes/no columns became chips in one "Behaviour" column, and only the settings that are ON
  are drawn.** An on/off pair told apart by colour is invisible to a reader with a colour vision
  deficiency and slow for everyone else; absence carries "off", and each chip that is there says
  what it is in words. A notice with nothing special set says so.

- **"Reset every" no longer prints "1 day(s), 0 hour(s), 0 minute(s) and 0 second(s)"** — three
  wrapped lines per cell for a value that is almost always round. It is `format_time()` now: "1 day".

- **Status is a column of its own** rather than a badge glued to the title, so it reads as the data
  it is. The conflict badge now explains itself twice: `title` for the pointer and a
  `visually-hidden` sibling for assistive technology. It relied on `aria-label` on a bare `<span>`,
  which has no role to attach to and is not announced reliably.

- **"Active from" and "Expiry" became one "Validity" column** — "Permanent", or the window in short
  dates with its state (current / scheduled / expired) underneath. The two full `userdate()` columns
  wrapped to two lines each.

- **"Time modified" is gone.** It guided no decision on this page; the value still lives on the
  notice. **"Cohort" is gone as a column** and became a muted line under the audience count, which
  is what it is a property of — and a notice targeting everyone now says nothing instead of spending
  a column to say "All users". **"Content" is gone as a column**: its one link is the Preview action.

- **Row actions are a core `action_menu`.** Edit, enable/disable and preview stay visible;
  recalculate, reset, the two reports and delete move into the kebab menu — seven inline links
  became three plus a menu. It also fixes a clipping bug by construction: `action_menu` emits a
  `.dropdown`, which is what Boost's `.table-responsive .dropdown { position: static }` rule keys
  off so the menu escapes the scroll container's overflow. The old inline links measured 27×15 px,
  under the 24×24 floor of WCAG 2.2 SC 2.5.8; they are 24×24 now.

- The Bootstrap 4 polyfill gained `visually-hidden`, which 4.5 does not define under that name —
  caught by `test_every_bs5_utility_used_is_polyfilled` on the first run after the badge started
  using it, which is what that test is for.

### Changed — the admin stylesheet reads the theme's tokens instead of inventing a palette (version 2026081503)

First slice of the admin-surface redesign; it changes no layout and no markup, only where the
colours and the type come from.

- **Every colour now resolves through `var(--bs-x, var(--x, literal))`.** Measured on the running
  stacks: Moodle 4.5 defines the Bootstrap 4 names on `:root` (`--primary`, `--success`, `--danger`,
  `--warning`) and 5.1/5.2 define the `--bs-*` set — neither defines both, so the chain is what
  works on both branches. The plugin had `--la-brand: #2c4a8a` cravado while Boost's primary is
  `#0f6cbf`, which meant every site with its own brand colour saw the plugin's navy instead of its
  own.

- **The pages follow the site into dark mode.** 5.1 and 5.2 ship it (`.theme-dark` ×77 and
  `[data-bs-theme="dark"]` ×15 in the compiled sheet) and the plugin had thirteen hardcoded light
  values and no handling at all, so the editor rendered a light slab inside a dark page. Reading the
  theme's tokens is the whole fix; there is no second palette to maintain.

- **Gone:** the two `radial-gradient`s behind the editor, the `monospace` family on numbers, pills
  and metadata, and `margin: -1rem -1rem 0`, which made the block 32 px wider than `#region-main`
  and let it ride over the admin tab bar when that bar wrapped. Digits still line up, now through
  `font-variant-numeric: tabular-nums`, which aligns them without changing the typeface.

- A `prefers-reduced-motion` block slows the spinner and drops the button transition.

### Added — the Bootstrap contract now also bans deprecated Bootstrap 4 names (version 2026081503)

- `bootstrap_compat_test` gained `test_markup_carries_no_deprecated_bootstrap4_names()`. The
  asymmetry runs both ways: `ml-1`, `text-left`, `sr-only` and friends *do* resolve on 5.x, but only
  through `bs4-compat.scss`, which wraps each in `@include deprecated-styles()` — a red outline under
  behat-site and themedesignermode — and which Moodle 6.0 removes (MDL-84465). Their Bootstrap 5
  spellings are all inside the 116-line forward bridge 4.5 ships, so the BS5 name alone is correct
  on both branches and the paired form buys nothing.

- It caught one live offender on its first run: the collision badge carried `ml-1 ms-1`. Now `ms-1`.

- The BS5-only detection list was re-measured and widened from five families to twenty-one. It is a
  detector, not a polyfill, so the extra entries cost nothing until somebody reaches for one — and
  the accompanying "polyfill carries nothing unused" test still holds the polyfill itself to exactly
  what the markup uses.

### Added — accent-insensitive search helpers, ported from local_dimensions (version 2026081503)

- `helper::has_unaccent()`, `helper::ensure_unaccent()` and `helper::sql_like_ai()`, so a search for
  "manutencao" also finds "Manutenção". On MySQL/MariaDB the collation already does this; on
  PostgreSQL it needs the `unaccent` extension, which the plugin now provisions from the new
  `db/install.php` and from an upgrade step.

- **Provisioning is DDL, so it never touches a request path.** A least-privilege database account
  cannot create extensions at all; the failure is swallowed and the site simply keeps
  accent-sensitive search, which `sql_like_ai()` learns from `has_unaccent()` rather than from a
  statement that would fail on every keystroke of every search box.

- `has_unaccent()` asks the `pg_extension` catalogue on every call instead of caching. PostgreSQL
  PHPUnit wraps each test in a rolled-back transaction, so a cached "created" flag goes stale the
  moment the `CREATE EXTENSION` is undone, and the next query references a function that no longer
  exists.

- The PostgreSQL technique follows the `local_aise` plugin ("Accent Insensitive Search Enabler",
  © 2023 Austrian Federal Ministry of Education, GPL v3 or later), as `local_dimensions` does.

- The helpers ship ahead of their first caller: the notice-name search in the reworked manage list
  is what will use `sql_like_ai()`.

### Changed — the audience estimate is one pass over {user}, whatever the rule count (version 2026081502)

- **The total and every breakdown chip are now conditional columns of a single statement.** They
  were N+1 separate queries, each a full pass over `{user}` differing only in its predicate — so on
  a site with hundreds of thousands of users the rule count multiplied the cost of a number the
  author reads once. With all seven rules set that was eight scans; it is one.

- Each rule's predicate is **rebuilt under its own suffix** rather than reused between columns.
  Moodle's `fix_sql_params()` counts placeholder *occurrences* against the parameter array and
  throws `duplicateparaminsql` when a name appears twice, so a fragment that appears in the total
  and again in its own chip has to carry two sets of names — and two sets of subquery aliases, so a
  correlated competency lookup cannot bind to the wrong copy of the enclosing course row.
  `role_scope::sql()` takes a suffix for the same reason; called without one it produces exactly the
  SQL it did before, which is what `helper::user_matches_role_filter()` still gets.

- The four course-scoped rules stay **one combined EXISTS** in the total rather than becoming four.
  They are answered against the same course at runtime, and splitting them would quietly change the
  question to "some eligible course satisfies each rule" — a wider audience than the notice has.

- Pinned by two tests that no result can show: one sets **every rule at once** (the only shape that
  can trigger a placeholder collision, and the one no other test reached), and one asserts the
  estimate issues **the same number of reads for seven rules as for one**.

- The `$withbreakdown` flag stays, with its rationale corrected: skipping the chips no longer saves
  a table scan per rule, but each is still a conditional column with its own EXISTS evaluated per
  row, so dropping seven roughly halves the work for the list column's refresh.

### Changed — the audience estimate belongs to the saved notice, not to the form (version 2026081501)

Sized for the sites this plugin actually runs on, which carry more than 200,000 active users. At
that size the estimate is not an interactive operation, and the previous design — a live widget
re-estimating as the author typed — was wrong in kind, not merely in tuning.

- **The editor no longer estimates on its own above the interactive limit.** The auto-trigger had no
  user-count gate at all: it fired 800ms after each form change, so an author on a large site
  queued a scan of every user row by typing a title. Sites at or below the new limit (default
  **1000** users, down from a 25000 that was reasoned rather than measured) keep the live behaviour
  and answer during the request; above it the panel waits to be asked. One limit governs both, in
  `audience\live_mode` — separable settings would allow the worst combination, an editor that
  auto-fires a job it then waits on cron for.

- **The site's user count is cached** (MUC, one hour) and is not counted at all when the limit is 0.
  It is a full scan of `{user}`, and it was previously paid on every estimate on exactly the sites
  where it is expensive, only to reach the same "too large" answer.

- **A saved notice now carries its audience size**, with the criteria hash it was computed from and
  the time it was computed. The manage list gained a **Target audience** column reading those
  columns directly, and a per-notice **Recalculate audience** action. Saving recomputes only when
  the hash changed, so editing a title costs nothing; on a large site the work is queued and the
  author is notified when it lands, as asynchronous course backup does.

- **The stored number is labelled with what it is a statement about.** A count describes a
  particular set of filters, so once they change it is not merely old — it is about something else.
  Comparing hashes separates "old but true" from "about filters that no longer exist", which a
  timestamp cannot, and the column says "Filters changed since …" rather than presenting a wrong
  number as current.

- **`COUNT(DISTINCT u.id)` became `COUNT(*)`.** The FROM clause is `{user}` alone and every rule is
  an EXISTS, so a user matches at most once and DISTINCT only bought a sort over the whole table.
  The per-rule breakdown is now skipped when refreshing a notice's stored total: it costs one extra
  full pass per rule — eight scans instead of one with every rule set — for chips only the editor's
  panel draws.

- A real audience of zero rendered as "not calculated", because the panel tested the count itself as
  a Mustache section. It tests a separate flag now.

**Still open, deliberately:** the per-rule breakdown could be one pass instead of N with conditional
aggregation (`SUM(CASE WHEN … END)` per rule). It is a real win but a self-contained rewrite of the
query builder, and it now runs in a worker rather than in a request, so it is left to be reviewed on
its own rather than bundled with a schema and UX change.

### Changed — the audience estimate answers on the click, deduplicates, and names what it counts (version 2026081402)

- **The estimate no longer needs cron on an ordinary site.** It is a handful of `COUNT` queries, and
  handing every one of them to an adhoc task cost the author minutes of "Calculating in the
  background…" for work measured in milliseconds — and on a site whose cron is not running it never
  resolved at all, ending in a timeout after five minutes. Sites at or below the new
  **Audience estimate inline limit** setting (default 25000 users) compute it during the request;
  larger ones keep the queued path, where the cost is real. Setting it to 0 always queues. An unset
  setting means the default rather than 0, so a site that has not yet stored it does not silently
  fall back to the old behaviour.

- The task's body moved to `estimate_audience::resolve()` so the inline and queued paths cannot
  drift apart, and it keeps the try/catch — an inline caller cannot turn a failed estimate into a
  failed request. The client now polls immediately for any non-pending status instead of testing for
  `ready`, so an error surfaces as fast as a result.

- **Identical estimates no longer pile up as duplicate adhoc tasks.** Only completed jobs were
  deduplicated, so the editor re-estimating on every form change queued a burst of identical tasks —
  each a full estimate the site ran and discarded, because the client was already polling a later
  one. A request now joins a job already queued for the same criteria, within the same window the
  client is still willing to wait (`audience_job::PENDING_WINDOW`). Past that window it starts a
  fresh job, so a stuck queue cannot trap every later caller behind one dead entry.

- **Chips name their values instead of showing raw ids.** "Course category: 4" is the one thing an
  author cannot check against the form they just filled in; it now reads "Course category:
  Engineering school", with courses, formats and themes resolved the same way and long lists
  collapsed to the first few plus a count. Resolution happens in `audience\rule_describer` at READ
  time, never stored on the job: jobs are shared between callers by criteria hash, so a name baked
  into the stored result would serve the next reader the first reader's language. A test asserts the
  stored breakdown carries no names, which is the mechanism rather than the symptom.

### Fixed — Calculate reach answers for every filter, and for no filter at all (version 2026081402)

- **The button did nothing on a notice with no audience filter.** `trigger()` returned early
  whenever the criteria named none of cohorts, role or required course, reprinting the sentence
  already on screen — so pressing Calculate reach on a fresh notice was indistinguishable from a
  dead control. An empty criteria set is now a question with an answer: every real, active user on
  the site, reported with its own status line rather than the filtered one.

- **Four of the seven filters were left out of the number they belong to.** Course category,
  course, course format and competency were classified as page-context restrictions and only ever
  rendered as chips. They are page rules and user rules at once: `check_filters()` admits them only
  on a course page the user can enter, so they bound who can ever see the notice. Each now
  contributes to the count and gets its own breakdown chip. `pathmatch` and the theme filter stay
  context-only — neither says anything about a user.

- The new predicate inlines core's `get_enrolled_join($onlyactive = true)` because it asks about a
  set of courses in one statement. It deliberately does **not** carry over that helper's SITEID
  exemption, under which every user counts as enrolled on the front page: `check_filters()` resolves
  a course only above id 1, so importing it would report the whole site for a rule that reaches
  nobody. It models the enrolment branch of `can_access_course()` and not the viewer branch, making
  the estimate a lower bound; a test pins the size of that gap so it cannot drift.

- **Context chips rendered their values as empty.** The rule labels were fetched with `param: ''`,
  which makes `get_string()` substitute `{$a}` server-side, so every chip read "Course category: "
  with nothing after it. The strings are fetched without a param and substituted client-side, and
  the breakdown chips now share that one code path instead of formatting labels separately.

### Fixed — the notices payload no longer ships targeting metadata to the browser

- **`get_notices` serialised each displayed notice's whole record into the response.** Verified on
  a live 5.2 site: the JSON carried `pathmatch`, `filtervalues`, `cohorts`, `timestart`/`timeend`,
  `resetinterval`, the timestamps and `usermodified` — a user id — to any user the notice was
  displayed to, while the modal reads nine fields (`id`, `title`, `content`, `reqack`,
  `forcelogout`, `bgimageurl`, `modal_width`, `modal_height`, `outsideclick`). The returns
  declaration is PARAM_RAW JSON, so `clean_returnvalue()` strips nothing; the payload is now built
  from an allowlist of exactly those nine fields, with values picked from the record unchanged so
  the client keeps receiving the types it always has. No JavaScript change and no AMD rebuild.

- Pinned by an exact-set test on the payload keys, which fails in both directions — a leaked extra
  field and a dropped needed one.

### Changed — the notice module loads only where a notice could appear (version 2026081400)

- **Every page view used to cost one authenticated AJAX request even where nothing could be
  shown.** The probe that decides whether to load `local_awareness/notice` deliberately ignored the
  page rules, so a site whose only notice was restricted to the Dashboard still fired
  `local_awareness_getnotices` — a full authenticated Moodle bootstrap, serialised on the user's
  session lock — on every page, for every logged-in user, to receive an empty list. The cost grew
  with users × page views, independent of the number of notices.

- **The decision moved from the navigation callback to the `before_footer_html_generation` hook**
  (the architecture `tool_usertours` uses for the same problem). The navigation callback fired
  mid-header, where `$PAGE->url` is not always set yet — measured across both supported branches:
  ~94% of page renders had the URL at navigation time (`/contentbank/index.php` initialises
  navigation before `set_url()` on both), 100% had it at footer time. The callback also ran on the
  navigation-expansion AJAX endpoint, where the queued module can never execute — a pure-waste
  probe that disappears with the move. The module itself, and the web service contract, are
  untouched: the AJAX call remains the definitive filter and the only source of notice content.

- **The probe now judges the page, using only rules that are zero-query and safe.** `pathmatch` is
  evaluated with the display path's own matcher against both URL representations (the path the
  browser will report, and the wwwroot-relative one — so patterns keep working on subdirectory
  installs); the course, category and format filters are judged against `$PAGE->course`, the same
  object the client's `courseid` is derived from; the theme filter only while course and category
  theme overrides are off, because with either on this render can resolve a different theme than
  the web service request will. Role, competency and course-access rules cost queries and always
  count as a match. Every uncertainty — no trustworthy URL, an exception, a missing course
  property, an unknown page layout — loads the module: the acceptable failure is one wasted
  request, never a notice that fails to appear.

- **Page layouts `maintenance`, `print`, `redirect`, `embedded` and `popup` never load the module**
  (the first three follow `tool_usertours`; the last two had no module before either). `secure` is
  a deliberate change, not preservation: with a sticky Navigation block the old callback could
  deliver a notice — including one with a forced logout — inside a securewindow quiz attempt; it
  no longer does.

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
