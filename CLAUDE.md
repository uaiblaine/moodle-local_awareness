# Claude instructions for `local_awareness`

This file is auto-loaded whenever Claude works in this plugin's directory tree.
**Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding style, CI gates,
lang-string rules, the `mdl` environment, git rules) — they are not repeated
here. This file keeps only what is true for this plugin.

Plugin context: a Moodle **local** plugin ("Awareness") that shows site
announcements to users in a modal, optionally requiring an acknowledgement and
recording who acknowledged or dismissed each notice. It owns seven tables
(`local_awareness` plus `_ack`, `_lastview`, `_hlinks`, `_hlinks_his`,
`_audience_jobs`, `_slides`). Four hold user-linked rows and are exported and
erased by the privacy provider; `local_awareness` and `_slides` are declared for
their `usermodified` author stamp only and deliberately left out of the
contextlist, export and delete paths.
`tests/privacy/provider_test.php::test_every_user_id_column_is_declared` sweeps
every `userid`/`usermodified` column against `get_metadata()`, because core's
compliance test finds user columns only through foreign keys to `{user}`. It
integrates with core Report Builder (five datasources, two
system reports), the Cohort, Competency and Role subsystems, and injects itself
into every page through the `before_footer_html_generation` hook. Supports
Moodle **4.5 through 5.2** (`$plugin->requires = 2024100700`,
`$plugin->supported = [405, 502]`). CI is the moodle-an-hochschulen reusable
workflow, one job per supported branch in `.github/workflows/ci.yml` — **update
those jobs when `supported` changes**. Development happens on m501; the repo is
mounted at `local/awareness`.

## Agent orchestration budget (fleet rule, repeated here on purpose)

This is section 6 of `~/dev/CLAUDE.md`, mirrored into every repo of the fleet.
It is the one fleet rule these files are allowed to duplicate: a session opened
inside a plugin directory does not always carry the fleet file in context, and
the cost of missing this rule is paid immediately, in tokens, before anyone
notices it was missing.

**Every `Agent` call and every `agent()` inside a Workflow sets `model`
explicitly.** An omitted `model` runs that subagent on the session model — the
most expensive one — and is a defect, not a default:

- `sonnet` — readers, graders, refuters, verifiers, measurers, stale-reference
  sweeps, mechanical renames, test files written against a stated contract.
- `opus` — implementers of non-trivial code, ADR and documentation drafters,
  consolidators, critics, estimators. The alias means the **newest Opus**: since
  2026-09-22 that is Claude Opus 5.5 (`claude-opus-5-5`), measured by asking a
  subagent launched with `model: 'opus'` which model it runs on. Never pin
  `claude-opus-5` or any older Opus id. The `Agent` tool accepts aliases only
  (`sonnet`, `opus`, `haiku`, `fable`); `agent()` in a Workflow accepts an explicit
  id as well, but the alias is what to write — it follows the newest Opus without
  an edit here.
- the session model — only for work done inline in the main loop, never for a
  subagent.
- `effort` is set beside `model` on every call, never inherited: `high` for
  verifiers, readers and refuters, `xhigh` for implementers and fixers (the
  owner's rule of 2026-09-17). An omitted effort inherits the session's, and on
  Opus 5.5 an explicit one matters twice over — that model's own default is
  `medium`, one level below Opus 5.

Multi-agent workflows stay opt-in and lean whatever mode is on: size the fan-out
to the question (roughly 10 to 25 agents), one refuter per finding and only for
blocking findings, no open-ended "investigate every gap" rounds. Stop and resume
with `resumeFromRunId` rather than relaunching, so completed agents stay cached.
State which model each role got when reporting a launch.

Measured 2026-09-02 on the hub category-context gap analysis: 7 lenses x 2
refuters x 2 measurers plus a critic round, every one of them on the session
model, had to be interrupted for cost — 36 agents with the refuters on Sonnet
produced the same verified result. The rule has been restated three times
(2026-09-01, 2026-09-02, 2026-09-04), the last time over implementers launched
without `model` while the reviewers around them were correctly downgraded.

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

- **`docs/RECONCILIACAO-2026-08.md` is closed except REPO-10**, the missing
  release tag and released CHANGELOG section, which is a release decision for
  the owner, not a defect. It gives every one of the 198 findings in
  `docs/AUDIT-2026-08.md` a verdict against the code with current-tree evidence.
  **Read it, never the audit** — the audit is explicitly the August snapshot of
  the starting point, and treating it as an open list sends you to re-investigate
  a hundred settled findings.
- The September 2026 comment audit and its fixes (PR #74, version `2026092401`)
  closed a further 73 findings, outside those 198. Its reports are kept outside
  the repo, in `~/dev/moodle-dev/data/comment-audit/local_awareness-2026-09-23/`.
- The August audit's M12, M13, M14 and M16 are pinned in `tests/helper_test.php` by
  `test_a_refused_blocking_notice_can_still_be_acknowledged`,
  `test_a_notice_targeting_a_hidden_cohort_reaches_its_members`,
  `test_renaming_a_link_keeps_its_identity_and_its_history` and
  `test_reading_a_competency_rule_creates_no_competency_state`, for cross-referencing the
  reconciliation.
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
  `can_access_course($course, null, '', true)`.** In tests an un-enrolled user
  gets `$course = null` and every branch returns false *for the wrong reason* —
  the negative cases pass while exercising nothing. Enrol the user.

  **`$onlyactive = true` constrains one leg of that function, not the function.**
  This note used to say it "demands an ACTIVE enrolment", and that is only true
  of the `is_enrolled($coursecontext, $USER, '', $onlyactive)` call. Execution
  continues to a **temporary guest-access** leg (`lib/accesslib.php:2070-2082`,
  identical on 4.5, 5.1 and 5.2) which walks the course's enabled enrol instances
  calling `try_guestaccess()` and returns true if any grants it. So on a course
  with guest access switched on, a **non-enrolled** user — and the guest user —
  passes, and a course-targeted notice DOES reach them. The old claim that such a
  notice never appears on the course's enrolment page holds only where guest
  access is off.

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

- **How insistent a notice is has ONE source of truth, and it is derived.**
  `awareness::get_insistence()` maps the two stored columns (`reqack`,
  `outsideclick`) to Informational / Blocking / Acknowledge; the form, the web
  service payload, `must_reshow()`, the manage-list chips and the report column
  all read the level rather than the columns. Force logout was retired in phase
  23 — the column survives for history and its report column is deprecated, but
  nothing reads it at runtime, and there is no `require_logout()` and no
  `is_siteadmin()` exemption anywhere in this plugin any more. Callers ask
  `>= INSISTENCE_BLOCKING`, never `=== `, so a level added above Acknowledge
  does not silently fall out of those tests. The Behat generator maps an
  `insistence` column through the same `awareness::INSISTENCE_*` constants: only
  the file's top level runs before `config.php`, and a step body runs after it,
  so the classes autoload there. Only the two `>=` comparisons are repeated
  beside `helper::sanitise_data()`, which is private. The slide step likewise
  uses `slide::FILEAREA`.

- **Every authoring action that SAVES a notice expires every acceptance on it.**
  `core\persistent::update()` is final and stamps `timemodified` unconditionally,
  and that column is what both `must_reshow()` and `acceptance_is_current()`
  judge a recorded interaction against. So `reset_notice()` — whose entire body
  is a no-op save — and `enable_notice()`/`disable_notice()` all supersede
  recorded consent. Re-displaying on re-enable was always deliberate; expiring
  consent arrived with the acceptance predicate, which reads the same column.
  The rows are never deleted, so the reports still show them; what changes is
  whether they count as current. `tests/consent_expiry_test.php` pins it with an
  untouched control. Anything that gates ACCESS on acceptance inherits this: a
  thing opened by acceptance closes again the next time an admin toggles the
  notice's visibility.

- **Every rule withholds a notice whose referent it cannot resolve, `reqcourse` included.** A
  deleted required course used to show its notice to everyone for ever, on the display path, the
  write-path gate and the estimator, whose `NOT EXISTS` over `{course_completions}` went vacuously
  true because deletion purges those rows. All three now fail closed, and there is deliberately no
  `course_deleted` observer clearing the field: clearing it widens the audience, and an observer
  can be missed where the consumers cannot. The editor drops the dead course on the next edit and
  the scope refuses it on save. `tests/reqcourse_missing_course_test.php` pins the three beside a
  live control; keep them in step. The theme rule does too: `check_filters()` returns false when
  `current_theme_name()` throws (a course in a missing category under category themes, or a page
  with no context), pinned by `check_filters_test::test_an_unresolvable_theme_withholds_a_theme_notice`.
  `get_notices` validates its context first, so an ordinary request never lands there, and
  `page_probe` still admits on uncertainty, so it stays a superset.

- **`filter_role_context` is a MODIFIER of `filter_role`**, not a rule of its
  own: it is absent from both `estimator::AUDIENCE_FIELDS` and `CONTEXT_FIELDS`,
  and never reaches `rule_describer::describe()`. The six keys `describe()`
  handles are exactly the six `audience:rule:*` strings carrying a `{$a}`;
  `ruleLabel()` in the JS discards `display` when the label has no placeholder.
  This has been mis-filed as a defect once — it is not one.

- **Never relocate moodleform rows with JavaScript.** The editor did this once,
  hiding the source form with the clip technique, which is the technique that
  deliberately keeps content available to assistive technology: two fields were
  focusable and announced while painted nowhere. Let the form declare its own
  `header` sections and style the fieldsets.

- **`author_scope` is the boundary for every audience and context field; nothing else is.**
  The form's three ajax autocompletes are not validated by core, a non-ajax select skips its
  allowlist when its option list is empty, and `sanitise_data()` cannot see any `filter_*` key —
  they are folded into the `filtervalues` JSON before it runs. Both write paths, the
  `estimate_audience` web service and `notice_form::extra_validation()` call the scope, in that
  shape, and existence checks stay OUT of `estimator::normalise()`, which is a pure shape-and-hash
  function unit-tested with literal ids. `helper::require_author($scope, $verb)` is where every
  plugin capability is checked — every page, verb, web service and report passes through it — the
  pages and web services with the scope of their request, every verb that acts ON a notice with
  `author_scope::of($notice)`, the notice's own scope read from its `courseid`. The one check made
  elsewhere is the site list's `admin_externalpage` in `settings.php`, which core makes before
  `managenotice.php` or `editnotice.php` runs in site mode: it names `local/awareness:manage` and
  `local/awareness:viewreports`, and `check_access()` admits either, so a reports-only user opens
  the list and `editnotice.php`, which shares the page for its navigation, still refuses them
  through `require_author($scope, 'manage')`. `local/awareness:managecourse` and
  `viewreportscourse` are declared with no archetype; an administrator grants them in the course.
  `helper::resolve_notice()` is how a page turns an id into a notice; it fails closed, because the
  editor's create-or-update branch once keyed on "not found". Ownership is pinned on every write
  path and never read from the submission; a course whose row is gone makes
  `author_scope::exists()` false, and `require_author()` asks that before it resolves a context, so
  an orphan is refused to course authors, not fatal, not promoted to the site, and still reached by
  the site capability at the system context. `group_scope::groupmode()` reads a missing course as
  `NOGROUPS`, so an orphan aimed at groups confines nobody, and `render_notice` validates the
  system context for it; `tests/local/group_scope_orphan_test.php` pins both. `editnotice.php`
  builds `notice_form` only for `create` and `edit`, and refuses both for an orphan with a redirect
  to the site list (`notification:orphannotice`): its forced course filter matches no page, and the
  course-scoped form and save read a context and groups the course no longer has (`group_scope`
  reaches `groups_get_all_groups()`, which calls `context_course::instance()`). The list's other
  actions (delete, disable, enable, reset, recalculate) need no form and work on an orphan, and an
  orphan's URLs carry no `courseid`, and the list offers an orphan no Edit
  (`all_notices::is_orphan()`). A saved link naming a deleted course is gated on the URL's missing
  scope before any notice is resolved: whoever holds the site capability for the page's verb (either
  verb for `managenotice.php`, manage for `editnotice.php`) is redirected to the site list with
  `notification:coursenotfound`, and anyone else gets core's `invalidcourseid`;
  `tests/deleted_course_link_test.php` pins it.
  `tests/editnotice_orphan_test.php` pins it. `helper::may_serve_files_of()` is the file gate:
  author bypass in the notice's scope, then course access for a course notice, then the audience.
  Course deletion purges through the `before_course_deleted` hook —
  the `course_deleted` event fires after the course and its context are gone — via
  `helper::purge_notice()`, which is not a verb and asks nothing; `delete_notice()` is the verb.
  **The course editor** (phase 31) is where a course scope becomes reachable: the navigation
  callback (guarded for the site course, which core hands it on front-page activity pages), the
  two pages in course mode with `author_scope::for_request()` turning the URL into a scope — the
  URL's scope gated BEFORE the notice is resolved, the notice's scope winning after — the manage
  table reading its scope from the filterset (absent filterset → the site), the form reading its
  scope from customdata (absent → the site) and rendering only what the scope admits, and the six
  editor web services (`check_collision`, `estimate_audience`, `get_estimate`, `preview_notice`,
  `search_courses`, `search_roles`) taking `courseid`, gating on it and answering inside it. Their
  JavaScript reads the scope through one module, `local_awareness/editor_scope` (`courseId()`),
  from the `data-courseid` that `editor_page` renders on `[data-region="la-editor"]` from the scope
  the page resolved; no editor module reads it from the URL, where a course notice opened without
  `?courseid=` would read as the site. `tests/local/editor_js_contract_test.php` pins it. The
  per-rule chips are withheld at the READ for a course scope, because jobs are shared by criteria
  hash across scopes; a rival outside the scope is described, never named. A course-notice role
  needs enrolment or `moodle/course:view` beside `managecourse`.

- **The dialogue's layout, position and entrance are `la-*` classes with hand-written CSS, and
  nothing in it may be a Bootstrap 5 utility.** `bootstrap::mark_page()` gates the BS4 polyfill on
  a body class four plugin pages add; the dialogue is injected on every page by the hook, so the
  gate can never reach it — `modal-fullscreen`, `rounded-3`, `sticky-bottom`, `visually-hidden`
  are dead on 4.5 there with no repair path (`fw-bold` was the first casualty). Specificity is
  rarely the problem: `.awareness.la-x` (0,2,0) beats core's layout rules on `.modal-dialog`, and
  Bootstrap's `.modal.fade .modal-dialog` and `.modal.show .modal-dialog` (0,3,0) set only the
  transform and transition, which the plugin does not fight. What cannot be beaten is a utility,
  generated with `!important`, so the template carries no `bg-*`, and its one border utility is
  `border-0` on `.modal-content`, which both branches define; an edge the plugin wants is drawn
  some other way (the banner's brand edge). The keyboard focus ring is
  `.awareness .modal-content button:focus-visible` (and `input`), (0,3,1), a 3px outline in
  `var(--la-brand)`: core's `button.btn-close:focus` and `input[type="checkbox"]:focus` zero the
  outline at (0,2,1) and come later in the compiled sheet, so a two-class selector ties and loses.
  `motion_contract_test::test_the_dialogue_keeps_a_visible_focus_ring` checks the specificity and
  refuses any `outline: 0` or `outline: none` in a dialogue focus rule. The vocabularies live on the persistent (`awareness::TEMPLATES`, `POSITIONS`,
  `ANIMATIONS`, `positions_for()`, `accepts_acknowledgement()`) and its `choices` gate is the one
  server-side check — the PARAM types only constrain the character set. `notice_form.js` and
  `modal_notice.js` carry hand copies of the corners and the sized/compact layouts;
  `tests/local/motion_contract_test.php` pins them against the persistent.

- **A layout is three lists on the persistent, and the JavaScript copies them by hand.**
  `insistence_levels_for()` says which levels a layout can honour (banner and image: Informational
  only — a close and nothing else; card and minimal: no acknowledgement box), `positions_for()`
  which positions (banner: the two edges, `POSITIONS_STRIP`), `COMPACT` which layouts drop
  `modal-lg` and ignore the author's size, `BAND` which paint the image in the media band rather
  than as a cover. `notice_form.js` mirrors the ceilings and the strip, `modal_notice.js` the
  compact and band lists, and `motion_contract_test` pins every mirror against the persistent — a
  layout added to `TEMPLATES` without a decision in each fails there, not in front of a reader.
  The image layout is the picture alone: the text is optional and offscreen (the picture's
  description), the title is its alt, the footer is `position: absolute; visibility: hidden` —
  never `display: none`, which the footer's own `d-flex` beats with `!important` — and the
  header's close floats outside the corner. The same trap holds for every utility the template
  wears (`rounded` was dropped from it for this reason, `px-4 py-3 pb-4` remain): a plugin rule
  setting a property a utility owns is dead, and `motion_contract_test` refuses one: every class on
  an addressed element of `modal_notice.mustache` must appear in its `$owners` map with the
  properties it owns, so a utility added to the template without an entry fails there. The banner
  flattens its header with `display: contents`, never `display: none`, because the header holds the
  dialogue's name and the close button.

- **The queue reuses one dialogue, and core's `show()` returns early on a visible one.** So nothing
  core emits fires for the second notice onward: the entrance is `setAnimation()` after every
  `show()` (reflow trick, class dropped on `animationend`), `modal-lg` is toggled by `setTemplate()`
  because the template bakes it in and `configure({large: true})` is a no-op against it, and a
  notice of another shape is carried by that replayed entrance — `modal.hide()` stays in the one
  place `async_contract_test` pins, when the queue is empty. `ModalNotice.prototype.hide`
  stops media — core's only toggles classes — and `destroy` takes off the namespaced document
  keydown listener each dialogue registers. `setMedia()` fills the band through
  `Templates.replaceNodeContents()`, which already announces the new nodes to the filters; that
  announcement is what makes `media_videojs/loader` — on every page — initialise a player it did
  not render, and announcing twice initialises twice.

- **Slides are rows, read before `sanitise_data()` runs.** `helper::sanitise_data()` keeps only the
  notice's own columns, so the repeated `slide_*` arrays are lifted out first by `slide_rows()` and
  saved after by `process_slides()`, keyed by the hidden `slide_id`. A repeated file picker's draft id
  is read from `$data->slide_image[$i]`, never through `file_get_submitted_draft_itemid()`, which
  cannot address a repeated element and returns false with a developer warning. A slide's image is
  keyed by the **slide** id in filearea `slidemedia`; `lib.php` resolves the slide to its notice
  before the audience gate, and `slide::delete_for_notice()` takes the files while the rows still
  say which ids exist.

- **Every rule the layout imposes lives in `extra_validation()`.** `\core\form\persistent::validation()`
  is `final`; `hideIf` hides a field without stopping its value; a client rule never posts the form.
  `apply_layout_rules()` on the save path sets what a hidden field would have carried (centre for
  fullscreen, no link outside the video layout). `set_optional_section_state()` is default-aware:
  the appearance columns are never empty, so "holds a value" means "differs from the default", or
  the section opens on every edit.

- **Three services hand a notice to the dialogue, and one class builds all three.**
  `local\notice_payload::structure()` declares the shape for `get_notices`, `preview_notice` (the
  editor's) and `render_notice` (the manage list's); `build()` fills it for `get_notices` and
  `render_notice`, while `preview_notice` builds its own payload from the author's draft areas. A
  field added to `build()` without `structure()` is stripped by `clean_returnvalue()` in silence, and
  `tests/external/notice_external_test.php` pins the exact key set. The video link is wrapped in a
  real anchor before `format_text()` — the multimedia filter embeds nothing from a bare URL — and the
  captions are `PARAM_RAW` on the way out, escaped once by the template's double stash.

- **Core puts a grouped radio INSIDE its label, and `.d-flex` is `!important`.**
  `element-radio-inline.mustache` (4.5 and 5.2 alike) renders `<label><input type="radio"> label</label>`,
  so a rule written `input:checked + label` matches nothing; the layout picker's states read
  `input:checked + .la-layout-option`, the sibling the radio actually has, and a position label is
  found with `label:has(input...)`, which core's own Boost stylesheet uses on both branches. The
  group wraps its children in a `fieldset` before the `.d-flex`, whose `display: flex !important` no
  plugin rule can beat: the position grid is flex geometry (fixed cells, a container three cells
  wide, margins on the centre cell, radios emitted in reading order by `notice_form::POSITION_GRID`),
  never `display: grid` on that element. `tests/form/picker_render_test.php` pins the markup;
  `motion_contract_test` pins the selectors and refuses `display` on any Bootstrap display utility.

- **Groups are a course rule, decided by core's own group rules, and reach is a gate of its own.**
  `local\group_scope` lifts `groups_get_activity_allowed_groups()` to the course (separate mode
  without `moodle/site:accessallgroups` → the author's own participation groups; anything else →
  every one of them). **The group MODE does not gate the picker** — it governs how activities
  separate participants, and a course can hold hundreds of groups at `NOGROUPS`, which is how core
  ships it; gating on it hid the field on every real course on the dev site. What gates the picker
  is having a group to offer. The scope table RESTRICTS `filter_groups` in a
  course and FORBIDS it at the site. Delivery is MEMBERSHIP, not visibility — `helper::user_group_ids()`
  reads `groups_get_user_groups(..., includehidden: true)`, because `groups_get_all_groups()` filters
  by what the CURRENT user may see and would drop a member of a hidden group. Reach is enforced in
  six places that must stay in step: `resolve_notice_as_author()` (pages), `require_group_reach()`
  (the five action methods), the pluginfile author branch, `all_notices::unreachable_notices_sql()`
  (the list), `render_notice` (the list's preview) and the `can_view()` of both system reports,
  which core's report web services build from client parameters and ask nothing else.
  `tests/group_audience_test.php` pins all six. The list finds its candidates with a LIKE on the
  JSON key, joined in the same query to courses in separate groups mode with their contexts
  preloaded; a viewer holding `moodle/site:accessallgroups` in the course is admitted without a
  `group_scope` being built, and only a confined viewer reaches `admits()`
  (`tests/table/all_notices_group_reach_test.php`). The exclusion applies with or without a
  filterset. `render_notice` answers every refusal, reach included, with the
  `notification:noticedoesnotexist` the pages give, and gates BEFORE `validate_context()`, whose
  login check on another course's context would otherwise refuse differently and say the id
  exists.
  `narrow()` and `admits()` answer DIFFERENT questions — what may be SAVED, and who may REACH what
  is saved — and only the second is about separation: a deleted group, a non-participation group, a
  deleted course and the site scope confine nobody, or deleting a group would hide the notices naming
  it from everyone including the administrator who has to fix them.

- **A course notice's page reach is the scope's, not the author's.** `pathmatch` is FORCED to
  `author_scope::COURSE_PATHMATCH` (`/course/view.php%` — the wildcard because the reader's page
  arrives as path plus query) in a course and LEAVE at the site, and the course form renders no
  display-restrictions section at all. Three consumers had to learn it: `apply_author_scope()` now
  writes `pathmatch` back onto the record beside `cohorts` and `reqcourse` (it never had to before,
  because no scope wrote it, and the first save stored null); `check_collision` and `editnotice.php`
  ask the scope for the reach they compare, or a course author compares the empty pattern, which
  overlaps everything; and `collision_warning.js` renders into `#fitem_id_scope_line` where the
  page-reach field is absent. The forced reach is not part of the audience question either:
  `external\estimate_audience` unsets `pathmatch` under a course scope, and
  `notice_audience::criteria_for()` leaves it out of a course notice's criteria, so the two hash
  alike and a save joins the editor's job in flight rather than queueing a second estimate
  (`audience_notice_audience_test` pins both scopes; upgrade step 2026092401 re-stamped the counts
  stored before). `get_estimate` returns the context rules in every scope; under a course scope
  they come out empty because neither criteria set carries `pathmatch` and the scope forbids
  `filter_theme`. The per-rule breakdown is what it withholds there. The successor once intended
  for the forced pattern is a page-type choice shaped like a block's ("any course page", "the
  course's main page") with `COURSE_PATHMATCH` as its first member; that decision belongs in
  `docs/SCOPE-VALIDATOR-FEASIBILITY.md`.

- **Notice events are logged in the notice's own context.** `helper::event_context()` gives the
  course context for a course notice, and the system context for a site notice or an orphan whose
  course row is gone; a deletion during course deletion still has its context, because the purge
  runs from `before_course_deleted`. The nine notice event classes add `?courseid=` to `get_url()`
  in a course context. `awareness_audience_estimated` stays in the system context: it describes a
  job shared across scopes by criteria hash, not a notice. Pinned by
  `events_test::test_a_course_notice_logs_every_event_in_its_course`.

- **Core's implicit roles count where core holds them.** The default user role counts for role
  context "All" (0) and System; the front page role for "All" only, because core holds it in the
  site course's context and a course-level rule never means the site course; the guest holds
  neither. `helper::user_matches_role_filter()` and `estimator::predicate()` must stay in step;
  `role_filter_test::test_front_page_role_follows_core` and
  `audience_estimator_test::test_the_front_page_role_counts_in_any_context_only` pin them. The Role
  context field's help (`filter_role_context_help`, en and pt_br) states the same rule for authors;
  change it with the code. `notice_form_test::test_every_field_with_a_help_string_has_its_help_button`
  reads both `notice:<field>_help` and `<field>_help` keys and asserts `filter_role_context` is among
  the fields it examined.

- **A cohort name has two spellings, and the helper hands out the raw one.**
  `helper::built_cohorts_options()` and `get_cohort_name()` return names as stored, for callers
  that format for their own sink; the manage list formats them unescaped in the system context,
  because its lines are double stashes and the option list carries no cohort context. The notice
  form takes `helper::cohort_menu_options()` through `author_scope::cohort_options()`: escaped, in
  each cohort's own context, because `element-autocomplete.mustache` prints options through a
  triple stash. Both lists come from one `listable_cohorts()`, so `allowed_cohorts()` validates
  exactly what the menu offers. In the manage list only the title's triple stash takes the escaped
  `format_string()`; the tooltip, the course chip, the group and cohort lines and the conflict
  explanation take `['escape' => false]`. `tests/table/all_notices_rendering_test.php` and
  `author_scope_test` pin both directions with a bare `&`. The same split holds for rivals in a
  collision warning: `collision::formatted_titles($clashes, $scope, $escape)` is the one place
  they are redacted and spelt, escaped for a notification message (`editnotice.php`), plain and
  stripped for `check_collision`'s PARAM_TEXT; `clash_titles_for()` and `visible_title()` stay raw.

- **The list's report links go straight to the report pages.** They are offered per row when the
  notice is Blocking or above and `helper::require_author(author_scope::of($notice), 'viewreports')`
  holds, whatever the viewer may do to the notice; the report pages gate on that verb themselves
  (`resolve_notice_as_author()` plus each report's `can_view()`). `editnotice.php` no longer has
  report actions: it demands the manage verb before its switch, which is what sent reports-only
  viewers to a permission error. The empty list offers "Create" only when the manage verb holds,
  as `manage_page` does for its own button.

- **An action code in a report column must decide what Sum and Average print.** `noticeview:action`
  is `TYPE_TEXT`, which keeps the two off it. `acknowledgement:action` stays `TYPE_INTEGER`, and its
  formatter `acknowledgement::format_action()` prints the number under Sum (the count of
  acknowledgements) and Average (the acknowledged share) and the action's name otherwise. The
  aggregation's name is the callback's fourth argument on both branches. The formatter reads the raw
  aggregate from `$row->action`, not `$value`, because 4.5's `column::format_value()` casts `$value`
  to the column type before the callbacks run, so an Average of 0.5 arrives as 0. Disabling the two
  aggregations would not have fixed saved reports: core's `datasource::get_active_columns()` applies
  a stored aggregation without consulting `get_disabled_aggregation()`, which only the editor's menu
  reads. `test_sum_and_average_of_the_action_are_numbers` in both `acknowledged_notices_test` and
  `dismissed_notices_test` pins it through real reports, and
  `acknowledged_notices_test::test_a_fractional_average_is_not_read_as_an_action` calls the
  formatter with the arguments 4.5 passes.

- **Competing notices are enabled repeaters that have not ended.** `collision::enabled_repeating_notices()`
  applies only the upper bound of the window (`window::open_prefilter_sql()`), so a notice scheduled
  for later still competes and an ended one does not. `clash_titles_for()` badges a listed notice
  only when it is itself in that set, the one `clashing_ids()` walks. The edited notice's own end
  counts too: `clashes_for()` takes it (`$timeend`, 0 for none) and returns nothing once
  `window::has_ended()` holds, the same half-open test the rivals get. The editor sends `perpetual`
  and the end date selector's parts as `timeend` {year, month, day, hour, minute}, not a timestamp,
  because only the server knows the author's timezone and calendar; `check_collision::selector_time()`
  converts them as `MoodleQuickForm_date_time_selector::exportValue()` does (calendar
  `convert_to_gregorian()`, then `make_timestamp(..., 99)`), and ignores them for a perpetual notice,
  as `editnotice.php` does. Both parameters are optional, so an older client gets the old answer.
  `collision_warning.js` re-checks on `change` of the perpetual select and of every end-date part.
  `editor_js_contract_test::test_the_collision_warning_sends_the_notice_window` pins the request keys
  against `execute_parameters()`, the field ids against the rendered site and course forms, and the
  build against the source; `collision_external_test::test_the_end_is_read_in_the_authors_timezone`
  pins the conversion. A disabled notice is still warned about, because it can be enabled from the
  list without passing through the editor. The warning after a save asks
  `collision::clashes_for_save()`, which takes the reach through the scope and the submitted end
  (already zeroed for a perpetual notice), so it agrees with the editor's;
  `collision_test::test_the_save_warning_judges_the_submitted_end_and_the_scope_reach` pins it.

- **`notice_audience::refresh()` has a fourth outcome, `STATE_ERROR`.**
  `task\estimate_audience::resolve()` swallows every failure into the job, so `resolve_inline()`
  reads the job's status to tell a stored count from nothing stored. `state_of()` never returns it.
  `editnotice.php` reports it as an error on recalculate and as a warning after a save. No real
  notice makes the estimate throw (a stored notice's criteria pass through `estimator::normalise()`),
  so `task\estimate_audience::resolve()` takes the estimator from core's container,
  `\core\di::get(estimator::class)`, and a test substitutes one that throws with
  `\core\di::set(estimator::class, $stub)`; core resets the container after every test on 4.5 and
  5.2. `resolve()` is the one body behind the adhoc task, `resolve_inline()` and the
  `estimate_audience` web service.
  `audience_notice_audience_test::test_refresh_reports_a_failed_inline_estimate_as_an_error` pins
  `STATE_ERROR` and `STATE_CURRENT` through `refresh()`, and
  `task\estimate_audience_test::test_a_failed_queued_estimate_is_recorded_and_not_announced` the
  queued path (job in error, no count stored, no message). The estimator must stay stateless, because
  the container hands out one shared instance.

- **The `audience_estimate_ready` message provider names no capability, on purpose.** A provider
  takes one capability, and `message_send()` refuses any recipient for whom
  `message_get_providers_for_user()` does not list the provider; with `local/awareness:manage`
  every course author's message was dropped. The side effect is that every user sees the provider
  in their notification preferences. The message links a course notice to its course's list.

- **The editor's buttons are added last.** `notice_form::define_buttons()` runs after
  `define_behaviour()` in both scopes: core wraps the sticky footer around the group where it is
  added, so the call order is the DOM and tab order. `closeHeaderBefore('buttonar')` keeps the
  footer out of the last section's collapsible container.
  `notice_form_test::test_the_buttons_come_after_every_field` pins it.

- **The reader's dialogue tracks `a[data-linkid]` only.** `helper::update_hyperlinks()` tags the
  anchors of the stored content at save time; anchors made at render time (a media fallback link,
  filter output) carry no id, and `local_awareness_tracklink` refuses a click without one, which
  showed the reader an error. Repeat clicks are not throttled: each click is one row of the link
  history report source, and every throttle considered merged genuine clicks.
  `modal_notice.js` routes the backdrop and Escape exits through `pressClose()`, which clicks only
  the first of the three `data-action="close"` buttons; a `trigger()` on the collection ran the
  close handler once per button.

- **The Bootstrap 4 polyfill holds one behaviour backport as well as utilities.**
  `body.local-awareness-bs4 .local-awareness-manage .no-overflow .dropdown { position: static }`
  lets the list's row menu escape the scroll wrapper on 4.5, where `flexible_table` wraps a
  responsive table in `.no-overflow`; 5.x uses `.table-responsive`, where Boost has the rule. It is
  listed in `bootstrap_compat_test::backports()`, which exempts it from the utility-token checks,
  and `test_the_row_menu_escapes_its_scroll_wrapper` reads core's wrapper class on the running
  branch; a future backport goes in that list too.

- **A dev site that upgraded mid-change never registers a web service added afterwards.**
  `external_functions` is refreshed only when `$plugin->version` rises; a stack already at the bumped
  version when `db/services.php` gained a function keeps its old list, and the AJAX call dies with
  `invalidrecord` from `external_function_info()`. Fix in place from a CLI script with
  `external_update_descriptions('local_awareness')`, or bump the version again.

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
- **The datasource tests extend `tests/reportbuilder/datasource/datasource_testcase.php`, not
  core's testcase directly.** Core memoises a report's active elements behind a `microtime(true)`
  comparison, and the aggregation stress helper asserts exactly one deprecation `debugging()` per
  fetch (this plugin has one deprecated column). A second reading not strictly later than the
  first — a backwards step, measured on the Docker Desktop VM: steps of up to 4.4 ms, seventeen in
  twenty-five minutes while CI legs ran — makes the memo miss on all four calls a fetch makes, and
  the helper sees four. The base class rebrackets each fetch so
  the memo is clock-independent and checks the fetch used the stored elements. Keep new datasource
  tests on it, and keep the deprecated column: the stress test's whole point is that it runs.
  Two things that would have saved an hour: a debugging-count failure already prints every message
  with its backtrace, and `mdl ci --matrix` keeps a failed leg's log under `$TMPDIR/mdlci-matrix-*`
  — read it before theorising. And never time a suite on the dev stack to judge a CI cost: m501
  runs it about 13 times slower than the runner (developer debug, Xdebug loaded).
- **Mutation-test every new test**, and revert the mutation from a *file copy* —
  `git checkout --` restores from HEAD and silently discards uncommitted work
  alongside the mutation.
- **File-serving tests never serve a file.** They ask `local_awareness_pluginfile()` for the
  area's `.` directory entry with `['dontdie' => true]` (`probe()` in `tests/lib_test.php` and
  `tests/slidemedia_pluginfile_test.php`): `send_stored_file()` returns silently for a directory
  under dontdie, identically on 4.5 and 5.2, so null means every check passed and the entry was
  found, and false means a refusal or a miss. Deleting the file and asserting false cannot tell a
  gate refusal from a missing file, and asserting a refusal with a real file in place makes a
  missing gate end the PHPUnit process instead of failing a test.
- **Competency proficiency is memoised in an ad hoc `MODE_REQUEST` cache**
  (`local_awareness`/`proficiency`), not a function static, so it is purged between tests. A test
  that changes proficiency and asks again within the same test must purge
  (`\cache_helper::purge_all()`). For the same reason, never memoise
  `helper::built_cohorts_options()` in a static: a test creating or deleting a cohort between two
  calls would read the old list.
- **`helper.php` requires `filelib.php` for the AJAX read path**, and only Behat guards it: the
  PHPUnit process has usually loaded filelib already, so a missing require shows only in an
  end-to-end request.
- **A page script can be tested in-process.** `tests/editnotice_orphan_test.php` and
  `tests/deleted_course_link_test.php` run a page through `tests/fixtures/<page>_request.php`,
  whose top-level code binds core's globals and requires the page in the calling method's scope (a
  `global` list inside the method reads as unused variables to phpmd, which does not analyse
  top-level code). Reset `$PAGE`, `$OUTPUT` and `$COURSE` (to `clone($SITE)`, or `get_course()`
  answers from the previous run) per run, set `$_SERVER['REQUEST_METHOD']` and `$_GET` or `$_POST`,
  and capture the output. The create page ends in `die`, so use an Edit link to reach the form.
  `redirect()` throws `redirecterrordetected` in a CLI process, so a redirect is that exception and
  a confirmation page is the captured output. The redirect's message and target are not observable
  that way: assert URLs through a rendered button, or through state.
- **The content editor's draft area is prepared in `notice_form::get_default_data()`**, where the
  notice id is known; preparing it in `editnotice.php` against item 0 hands the editor an empty
  area.
- **A slide's fields cannot be one moodleform group.** `hideIf` and `setType` do reach a group's
  children and the delete button's client hints can be restored by hand, but the group drops its
  children's labels; `tests/form/picker_render_test.php` pins the row shape that replaced it.
- **The source-scanning tests are strict on purpose.** `lang_usage_test` skips `tests/` and strips
  comments, so a key named only in a test or a comment is dead, and it exempts only `_help`,
  `cachedef_` and `messageprovider:` keys (every task fetches its `task_` string in `get_name()`).
  `bootstrap_compat_test::code_lines()` follows multi-line `{{! }}` and `/* */` comments, so
  template docblock prose is never scanned as markup; the recipe for re-deriving its BS5-only list
  is in the fleet file. `stylesheet_contract_test` fails on a `la-*`, `local-awareness-*` or
  `competency-picker-*` class the stylesheet styles and no shipped code emits, on a property set
  twice in one media context, on a form id selector not scoped to `.local-awareness-editor`, and
  on a `.la-pagehead` heading level the shell does not render: removing markup that emits a class
  means deleting its rule in the same change.
- **Removed API, and what replaced it in tests.** `awareness::get_all_notices()`,
  `window::open_sql()` and `linkhistory::count_clicked_links()` are gone: tests read notices with
  `awareness::get_records()`, and that two clicks are two rows is pinned by
  `purge_link_history_test` counting `{local_awareness_hlinks_his}` rows.

## When in doubt

Follow the patterns in existing files. The codebase is internally consistent —
if a new file feels like it matches no existing shape, re-examine the approach.
