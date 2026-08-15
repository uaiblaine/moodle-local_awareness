# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning principles where possible.

## [Unreleased]

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
