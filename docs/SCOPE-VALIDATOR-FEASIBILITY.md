# The scope validator — the fork before the first line

**Date:** 2026-09-03 · **Measured against:** `main` at `896bf9d` (version `2026082404`), Moodle
4.5, 5.1 and 5.2. Twenty agents: six readers, one per field group, each followed by an adversarial
refuter; five measurers (foundation cost, read-site inventory, validator design, existence APIs,
fixture breakage), two of them refuted independently; one synthesis critic. The load-bearing claims
below were then re-read by hand. **Nothing was executed** — no PHPUnit, no Behat, no `mdl ci` leg —
so every "would break" is a prediction from a code read, and is marked as such.

Companion to [`BLOCK-AWARENESS-FEASIBILITY.md`](BLOCK-AWARENESS-FEASIBILITY.md), whose second
build step this document prices. That document read the eleven audience and context fields and
classified each for a course-scoped author. This one checks the classification against the code,
finds two labels wrong and three right for a different reason than given, and then prices the
question that has to be answered before the validator is written: **what does it validate
against?**

> **Decision (2026-09-03):** the owner chose **(c)**, a `courseid` column when the foundation
> lands, a **new** capability rather than a re-levelled one, and **no default archetypes**. PR 1,
> the validator, is `classes/local/author_scope.php`; the failure policy follows the recommendation
> below (form error, web-service exception, cohorts silent). The other sub-decisions in section 4
> stay open until the PR that needs them.

## The question

Today every author is a site administrator. Both capabilities are `CONTEXT_SYSTEM` with a
`manager` archetype (`db/access.php:40-59`), the table has no `courseid` or `contextid` column
(`db/install.xml`), and both entry pages are `admin_externalpage_setup()`. A validator restricts a
scope, and there is no scope to restrict. So:

- **(a)** build the validator together with the scope concept — the `courseid` column and a
  capability at `CONTEXT_COURSE`, in `tool_monitor`'s shape — so it has something to restrict and
  the tests something to prove;
- **(b)** build only the validator's structure now, with "site scope = everything" as its sole
  implementation, and accept the declared risk that it ships as a no-op with tests that assert
  nothing;
- **(c)**, which the handoff did not name: build the validator with a *scope value object* whose
  course branch is implemented and tested now against hand-built scopes, but is unreachable from
  production until the column lands.

A third thing is worth doing under any of them: **checking that submitted ids exist.** Nothing
does today, for any field but cohorts, and a deleted `reqcourse` silently shows its notice to the
whole site.

## Verdict

**Take (c), in four pull requests.** (b) is the only option that ships a scope abstraction with no
way to test the thing it abstracts: with `site()` as the only value, every assertion about the
rule table passes against an `apply()` whose whole body is `return $filters;`, and the one field
where an existence test would look convincing — cohorts — is already enforced at
`helper.php:257` and `estimate_audience.php:94`, so that test would prove code that already
exists. (a) is right in content but bundles a schema change, a new capability whose default
archetypes carry `RISK_XSS`, thirteen gate rewrites and the fix for an ownership hole it *creates*
into one change that cannot be bisected — and the read-site inventory that priced it missed two of
its own must-change files, which is evidence the surface is not fully enumerated yet.

(c) gets the policy decided, written and genuinely tested before anyone is granted anything, at
roughly a third of (a)'s size. Its one real cost — a course branch nothing in production can
construct, which no tool in the pipeline will flag — is bounded by naming it in the `CHANGELOG` and
by the coverage report, since `classes/local/` is measured.

Six decisions are the owner's whatever the option; they are in section 4.

---

## 1. The classification, verified

"Direction" is what the field can do to a notice's reach **given that `filter_course` is forced to
`[C]`**. Every display block in `check_filters()` (`classes/helper.php:2033`) is an AND — a block
that fails returns `false` — and the estimator joins every rule's fragment with
`implode(' AND ', $where)` (`classes/audience/estimator.php:421`), so a field can only *widen* by
being read for a second meaning somewhere else. Two are.

| field | handoff | verified | direction | the reason, in one line |
|---|---|---|---|---|
| `filter_course` | FORCE `[C]` | FORCE `[C]` | oracle only | Narrows at display (`:2109`), in `page_probe` (`:236`) and in `course_scope_sql()` (`:508`, `SITEID` excluded at `:486`). The oracle is its use as raw *input* to `estimate_audience`, which counts active enrolments of any course id submitted. |
| `pathmatch` | LEAVE | LEAVE | neither | In `CONTEXT_FIELDS`, contributes to no count, ANDed independently of the course at both gates; the `FRONTPAGE`/`MY` tokens cannot fire a course notice on the dashboard because `courseid <= 1` rejects first (`:2063`). |
| `filter_category` | FORBID | FORBID | **can widen** | The only field in the group that genuinely widens: `role_scope::sql()`'s `CONTEXT_COURSE` branch OR-joins "role in a listed course" with "role in any course of a listed category" (`classes/local/role_scope.php:103-111`), and its `COURSECAT` branch scopes the role lookup to an arbitrary category with the course never consulted (`:82-91`). `rule_describer::category_names()` also renders a guessed hidden category's real name. |
| `filter_format` | FORBID | FORBID | oracle only | No second meaning (zero hits in `role_scope.php`; control: `filter_category` has five). At display it is redundant or self-defeating. Its breakdown chip is a site-wide "enrolled in any course of format X" count — see section 5. |
| `filter_theme` | FORBID | FORBID, **as a UX rule** | narrows only | In `CONTEXT_FIELDS`, no count, no second meaning, pure AND at display. LEAVE would be equally safe; presenting this as a boundary overstates it. |
| `reqcourse` | FORBID | **RESTRICT to `{0, C}`** | oracle only | A `NOT EXISTS` over `{course_completions}` ANDed into the predicate (`estimator.php:400-413`) plus an AND gate at display (`helper.php:836-838`) and on the write path (`:928-935`). FORBID kills the legitimate "keep showing until they finish *my* course"; restricting to `C` queries C's roster against C, which the teacher already has from the completion report. Naming another course X would make `count(reqcourse=0) - count(reqcourse=X)` the number of C's students who completed X. |
| `cohorts` | RESTRICT | RESTRICT, to a **different set** | oracle only | Already validated today, site-wide (`allowed_cohorts()`, `:498`). The obvious course set, `cohort_get_available_cohorts($coursecontext)` (`cohort/lib.php:260`), is the wrong one: it is built from `get_parent_context_ids()` and returns every visible cohort in C's category ancestry plus every system cohort — ancestry and visibility, not any relation to C. The defensible set is the cohorts C actually enrols from: `SELECT DISTINCT customint1 FROM {enrol} WHERE enrol = 'cohort' AND courseid = :c`. **And RESTRICT does not close the oracle; it makes it smaller and sharper** — see section 5. |
| `filter_role` | RESTRICT | RESTRICT, to `get_roles_for_contextlevels(CONTEXT_COURSE)` | narrows only, **conditionally** | Safe only as one unit with the next two rows: restricting the role *ids* means nothing while `filter_role_context` can be `0`, `SYSTEM` or `COURSECAT`, or `COURSE` with a free `filter_category`. `get_roles_for_contextlevels()` (`lib/accesslib.php:3576`, same line on 4.5 and 5.2) answers "which roles the site allows at course level", which is the right question; `get_assignable_roles()` answers "may this user assign roles here" and empties for a non-editing teacher. |
| `filter_role_context` | RESTRICT | **FORCE `CONTEXT_COURSE`** | **can widen** | Not a restricted set — a singleton. `role_scope::sql()` branches on exactly `SYSTEM`, `COURSECAT`, `COURSE` with no `else` (`role_scope.php:70-115`), so the form's own default `0` performs **no** context restriction: any assignment anywhere on the site satisfies the rule. `0` and `SYSTEM` also inject the default-user roles, which in the estimator becomes a literal `1 = 1` (`estimator.php:393`). Forcing `COURSE` closes both. |
| `filter_competency_rules` | RESTRICT | RESTRICT, to `api::list_course_competencies($courseid)` | oracle only | `competency/classes/api.php:1134`, same line on all three branches, gated on a capability an enrolled teacher holds. Proficiency is read per course (`competency_usercompcourse`, `helper.php:1915`), so an unlinked competency is self-defeating at display. Two enforcement sites, not one: the save path, and the breakdown chip, which computes the competency predicate with the course dropped (section 5). |
| `filter_competency_requireall` | LEAVE | LEAVE | neither | Set only inside `normalise()`'s `if (!empty($rules))`, read once in `competency_sql()` and once in `check_filters()` after the rules array is confirmed non-empty. Cannot act alone. |

**Score: nine of eleven labels stand; `filter_role_context` and `reqcourse` change.** Three of the
nine keep their label for a reason other than the one given: `filter_theme` is UX, not boundary;
`filter_format` is half UX, half breakdown oracle; `cohorts` is RESTRICT to a set the shorthand
did not name.

Two things the handoff's reasoning omitted about the one field it got most right. Forcing
`filter_course` **does not confine the role rule**: `role_scope::sql()` reads `filter_course` only
inside its `CONTEXT_COURSE` branch (`:97-102`), so at any other `filter_role_context` the forced
course is never consulted by the role lookup — confinement is entirely the other field's job. And
the enforcement point has to be named, because it is not where it looks: `filter_course` is unset
from `$data` and folded into the opaque `filtervalues` JSON at `helper.php:119`
(`create_new_notice`) and `:201` (`update_notice`) **before** `sanitise_data()` runs at `:229`, so
`sanitise_data()` as written structurally cannot see it. One reading in this analysis cited
"`helper.php:170-224`" for both write paths and "`:224/226`" for the merge; those lines are
`update_notice` only and `sanitise_data()`'s docblock respectively, and an implementer following
them would have patched one path of two.

### What a missing referent does today

No field but cohorts is checked for existence on any path. What happens when the thing named has
since been deleted is silent in every case, and not uniform:

| referent gone | display | write path | estimate |
|---|---|---|---|
| course in `filter_course`, category, role, cohort, theme | never shows | — | narrows to zero |
| **course in `reqcourse`** | **shows to everyone** (`helper.php:825-827`, in the code's own words: "A course that no longer exists simply has no entry, which leaves its notices shown") | falls through to `return true` (`:937`) | `NOT EXISTS` vacuously true |
| competency | **depends on the rule**: fails closed under `requireall` or `proficient = 1`; a permanent pass under `requireall = 0` with `proficient = 0` (`:2162-2171`) | — | — |
| theme, when resolution *fails* rather than the name being unknown | block skipped — fails open (`:2131-2141`) | — | — |

The `reqcourse` row is a live defect at site scope, independent of any of this: an administrator's
completion gate silently disappears when the course it names is deleted, in a plugin whose every
other filter fails closed.

---

## 2. Where the boundary has to sit

The entry points that accept audience or context criteria from a client, and what each validates:

| entry point | accepts | validates today | must call the validator |
|---|---|---|---|
| `helper::create_new_notice()` (`:85`) | the whole form POST: eight `filter_*` keys, `cohorts`, `reqcourse`, `pathmatch` | cohorts only, through `sanitise_data()` (`:229`) | yes |
| `helper::update_notice()` (`:156`) | same | same | yes |
| `external\estimate_audience::execute()` (`:66`) | a criteria JSON, speculative, before any save | cohorts only (`:93-97`); `cap_criteria_lists()` (`:1068`) is an `array_slice`, a length cap, not a validation | yes — the pre-save oracle |
| `external\search_courses` (`:52`), `search_roles` (`:53`) | a search string | capability at system context; result sets are site-wide | yes, once a course scope exists — these feed the very pickers a course author would use |
| `notice_form` | the form | `\core\form\persistent::validation()` is **final** (`lib/classes/form/persistent.php:304-315`), so `tool_monitor`'s override pattern cannot be copied; the hook is `extra_validation()` (`:196`, present on 4.5 and 5.1) | yes, for the human-facing error |
| `external\check_collision` | a `pathmatch` | capability only | no — but it leaks titles, section 5 |
| `notice_audience::criteria_for()` | a stored notice | — | no — reads, never writes |
| Behat generator, `tests/generator/lib.php` | feature-file rows | none, by design: loaded before `config.php`, writes the table directly | no |

The validator's shape, as the design measurer proposed it and as the repository's conventions
suggest: a value object under `classes/local/` — `author_scope::site()` and
`author_scope::course(int $courseid)` — carrying the eleven-field rule table above, with one method
that overwrites FORCE fields, drops FORBID fields the way `sanitise_data()` already drops unknown
keys, narrows RESTRICT fields through the existing helpers (`allowed_cohorts()`,
`normalise_competency_rules()`) plus the two new sets named in the table, passes LEAVE fields
through, and checks existence for every id- and name-bearing field using the core APIs the
measurers confirmed on all three branches (`$DB->record_exists('course')`,
`core_course_category::get($id, IGNORE_MISSING)`, `role_get_names()`,
`\core_competency\competency::record_exists()`, `core_component::get_plugin_list('format'|'theme')`,
and the fixed vocabulary for `filter_role_context`).

**Keep existence checks out of `estimator::normalise()`.** It is a pure shape function that also
feeds `hash()`, and it is unit-tested with literal ids. Validate at the entry points, before it.

---

## 3. The fork, priced

Costs are the measurers' estimates from reading, not from doing. The foundation figure rests on one
reader and was not refuted; the read-site inventory and the fixture count each were.

### (a) Foundation + validator + read-site scoping, together

**Includes.** A `courseid` column with foreign key and a `(courseid, enabled)` index, the upgrade
step, a new `CONTEXT_COURSE` capability with strings in both packs,
`local_awareness_extend_navigation_course()` — the callback is invoked for local plugins on all
three branches (`lib/navigationlib.php:4960` on 4.5, `lib/classes/navigation/settings_navigation.php`
on 5.1 and 5.2) — a widened `pluginfile` gate (`lib.php:44` refuses any non-system context today,
so course notices' images would 404 silently), dual-mode `managenotice.php` and `editnotice.php`
in the `managerules.php:33-46` shape, the plugin's first `db/events.php` with a `course_deleted`
observer, the manage list's `get_context()` and query, the validator wired in, and the read sites.

**Cost.** Foundation alone: 19 files, ~500 lines, 18 of them mandatory. With the read sites and the
validator: roughly 32 files, 950–1100 lines, in one unit. Plus rewriting all **thirteen** capability
gates that pass `\context_system::instance()` literally (`helper.php:1429`, `all_notices.php:118`,
the five external classes, both system reports' `can_view()`, both `report/*.php` at `:36`,
`lib.php:77`, `settings.php:114`) — a role assigned only at course level satisfies none of them,
by the same inheritance rule that makes those sites safe today.

**Non-vacuous tests.** The six course-scope tests below, the capability gate (removing it must
redden exactly one assertion), and the observer — which is non-vacuous **only** if it seeds a
site-wide notice and another course's notice that both survive the same `delete_course()`. Without
those controls it is the exact shape this repository has already shipped once.

**Risks.** The largest is an ownership hole the change *creates*: `editnotice.php:60` loads
`awareness::get_record(['id' => $noticeid])` from a bare parameter with no ownership check, and
every action branch through `:283` mutates it, delete included; `report/acknowledged_systemreport.php:36`
and `report/dismissed_systemreport.php:36` do the same with `MUST_EXIST`. Grant a course
capability without fixing all three and any holder edits, deletes or reports on any notice on the
site. Second, the archetype default ships with the capability and cannot be deferred — section 4.
Third, `tool_monitor` ships no backup or restore for its own course-owned table (`rg -l backup_`
over `admin/tool/monitor` returns nothing; the same search finds real hits in `admin/tool/log` and
`mod/assign`), so following the precedent means a course notice does not travel with "Duplicate
course" and nothing tells the teacher why. Fourth, one change of this size cannot be bisected.

**Leaves open.** The cohort oracle. Category-level ownership, if `courseid` is chosen. Backup.

### (b) Structure only, site scope as the sole implementation

**Includes.** The class with `site()` only, called from `sanitise_data()` (one place, both write
paths), from `estimate_audience::execute()` before `normalise()`, and in the two search endpoints;
existence checks for the eight fields that have none; `extra_validation()` on the form; the fixture
repair.

**Cost.** 8–10 files, 200–280 lines. No schema, no capability, no observer, no read-site work.

**Non-vacuous tests.** The existence tests are genuinely non-vacuous: submit a nonexistent course,
category, role, competency or format *beside a real one* and assert the fake is refused and the
real survives — the pairing that makes the one existing test of this kind mutation-proof
(`tests/helper_test.php:385-416`, `assertNotContains` at `:414` with its `assertContains` control at
`:416`). **What is vacuous is everything about scope**, and this is the risk the handoff named:
`site()->apply($filters)` returning `$filters` unchanged passes identically against an `apply()`
that is `return $filters;` and against one whose FORCE, FORBID and RESTRICT branches are dead or
wrong. There is no second scope value to contrast against, so no assertion can tell a working rule
table from an absent one. The cohorts test is nearly vacuous too, for the reason above.

**Unblocks.** Real hardening today on the save path and on the speculative estimator, whose
`filter_course` oracle is live now for anyone holding the manage capability. The failure-policy
precedent.

**Leaves open.** Every course-scope question; the rule table exists only as prose. Everything in
section 5.

### (c) A scope value object with the course branch built and tested now

**Includes.** Everything in (b), plus `course(int $courseid)` implementing the corrected table:
FORCE `filter_course = [C]` and `filter_role_context = CONTEXT_COURSE`; FORBID
`filter_category`, `filter_theme`, `filter_format`; RESTRICT `reqcourse` to `{0, C}`, cohorts to
the `enrol_cohort`-wired set, `filter_role` to `get_roles_for_contextlevels(CONTEXT_COURSE)`,
`filter_competency_rules` to `list_course_competencies($courseid)`; LEAVE the other two. The
course branch is instantiated from PHPUnit only; nothing in production can build it.

**Cost.** 10–12 files, 350–420 lines: (b) plus ~150 lines of course logic and six test methods.

**Non-vacuous tests.** All six are constructible today with no schema change, because each builds
the scope by hand and each carries a control that fails under the opposite mutation: the FORCE
overwrite of a foreign course id, with an idempotence control; the FORBID strip asserted **in the
same call** as a surviving `pathmatch`, so a `return []` implementation reddens; cohort A wired to
C by an `enrol_cohort` instance survives while unwired B drops; a course-level role survives while
a role set to `CONTEXT_SYSTEM` only drops; competency K1 added through
`api::add_competency_to_course()` survives while K2 drops; `pathmatch` and `requireall`
byte-identical under both scopes **in a test that also asserts a FORCE field changed** — the
combined assertion is what rules out an `apply()` that is secretly a no-op, which is precisely the
vacuity (b) cannot escape.

**Risks.** Dead code in production terms until three things land together: the column, a course
capability, and a page that resolves a course context. No tool will say so: the strict gate's
ruleset (`moodle-dev/ci/phpmd-moodle.xml`) keeps `UnusedLocalVariable`, `UnusedPrivateField`,
`EmptyCatchBlock`, `EvalExpression`, `GotoStatement`, `DevelopmentCodeFragment` and
`ConstantNamingConventions`, has no unused-public rule, and explicitly drops `UnusedPrivateMethod`.
The one signal is coverage, which would read the branch as covered by its own tests. And the table
can drift between this PR and the wiring PR — a `filter_*` field added in between defaults to
unhandled, so the rule table should be asserted complete against `estimator::AUDIENCE_FIELDS` and
`CONTEXT_FIELDS`.

**Unblocks.** The scope decision becomes reviewable as code with a meaningful green suite before
anyone is granted anything; the foundation PR becomes wiring with the policy settled.

### The order it implies

1. **PR 1 — the validator.** `author_scope` with both branches and the corrected table; existence
   checks in both write paths (ahead of `sanitise_data()`, which cannot see the fields) and in
   `estimate_audience::execute()`; `notice_form::extra_validation()`; the fixtures repaired;
   version bump and `CHANGELOG`. The search endpoints need no existence check — they return rows —
   and get their scope restriction in PR 4.
2. **PR 2 — the seam.** One gate, `helper::require_author($scope, $verb)`, that every author-side
   page, helper verb, web service, table and report passes through with `author_scope::site()`
   today; `author_scope::context()`; the course capability `local/awareness:managecourse`, declared
   with no archetypes so that the seam's course branch is real code with real tests; a fail-closed
   `helper::resolve_notice()` for the identity defect in `editnotice.php`, where a save posted
   against a deleted or forged id ran the create branch; the collision web service stripping titles
   for its `PARAM_TEXT` slot; and the collision decision below. What the first cut called "the
   ownership check in `editnotice.php:60`" was not one — nothing owns a notice yet — but an
   identity defect was there. The `report/` wrappers keep their order: both orders fail closed.
3. **PR 3 — the foundation.** Column, upgrade step, `db/events.php` and observer, navigation entry,
   the `pluginfile` gate, dual-mode pages; and the owner's call on a course-level reports
   capability, which the seam already leaves room for.
4. **PR 4 — the wiring.** `author_scope::of($notice)` at the real entry points; the system reports'
   `can_view()` honouring the context they are handed, in the same change as their row set, because
   a report whose rows come from a site-wide table must not accept a course context first;
   `search_courses` and `search_roles` returning only what the scope allows; the manage list, the
   estimator and the cache key scoped; the collision surface redacted as decided below.

**The collision decision.** Today every caller of the collision surface holds
`local/awareness:manage` at the system context, so the titles it discloses are titles the same
person can read by scrolling the manage list: no teeth, and no redaction. Once notices carry a
`courseid`, the two functions that own the computation, `collision::clashes_for()` and
`clash_titles_for()`, take the caller's scope and disclose a title only for a notice inside it. A
competing notice outside the scope is still reported — as a site notice, or a notice in another
course, without its title or audience — because the warning exists to say that the pages are
contested, and an author who cannot see the rival still needs to know. The overlap test stays
pathmatch-only, as its own docblock argues: a warning that is occasionally unnecessary costs less
than one that is occasionally absent.

---

## 4. Decisions only the owner can make

| decision | options | the trade, measured |
|---|---|---|
| **Scope column** | `courseid int NOT NULL DEFAULT 0` (`tool_monitor`, `install.xml:14`) vs `contextid` | `courseid` needs no migration: `DEFAULT 0` already means "site-wide" for every existing row, matching the plugin's own `reqcourse` sentinel. `contextid` cannot carry a literal default — a system context id is assigned per install — so it needs a real `UPDATE` backfill, and every gate resolves a context instead of testing `courseid == 0`. Its gain: category-level ownership later needs no second schema change. Recommendation: `courseid`, with the limitation written down. |
| **New capability vs re-levelling `local/awareness:manage`** | add one at `CONTEXT_COURSE` vs change the existing one's level | Re-levelling accomplishes nothing: `update_capabilities()` (`lib/accesslib.php:2304-2354`) rewrites the `contextlevel` column on upgrade but writes `role_capabilities` rows only for genuinely **new** capabilities, and changes nothing about what the thirteen sites check. It would only change the meaning of a capability already assigned on every site. Recommendation: new capability. |
| **Default archetypes** | copy `tool_monitor` (`teacher` + `editingteacher` + `manager`), `editingteacher` only, or nobody | Not by analogy. `db/access.php:29-43` declares `RISK_XSS` because notice content is rendered with `format_text(noclean)` into `Modal.setBody()`; `tool/monitor:managerules` carries `RISK_XSS` too but only lets a user pick rule actions from a fixed vocabulary. Copying its defaults grants every non-editing teacher on every course the ability to broadcast arbitrary markup to that course, by default, on upgrade. Owner's call. |
| **Failure policy** | silent drop (the `allowed_cohorts()` precedent), a form error, a web-service exception | The three paths want different answers, and the plugin already contains all three idioms. Save path: a form error via `extra_validation()` — a human is there, and a silent drop is a filter the author set that is simply gone next time. Web-service path: `\invalid_parameter_exception`, which `get_notices.php:73` already uses. Cohorts keep silent drop on purpose: `estimate_audience.php:83-91` argues that excluding a cohort the caller may not see reveals nothing either way, a privacy argument that does not generalise to courses or roles. |
| **`reqcourse`** | RESTRICT to `{0, C}` vs FORBID | Section 1. RESTRICT closes the same oracle and keeps the legitimate case. |
| **Already-stored notices with deleted referents** | validate on write only; also re-validate at display; a one-off report | Read-time re-validation changes display behaviour on every existing site and is the dangerous change. Write-only grandfathers today's silence, which is not uniform (section 1). The plugin has no `db/events.php` to notice a deletion, and `notice_form` re-resolves a competency's cached name only when it is already empty (`notice_form.php:277-287`), so a stale label persists for ever. Cheap version: a dangling-referent badge on the manage list. Thorough: a scheduled audit task. |
| **The six fixtures** | rewrite with generated referents vs exempt the paths | Two agents converged on the same six `file:line` sites, the strongest agreement in this analysis: `tests/audience_estimator_test.php:54` (`cohorts` `[3, 1, 2, 0, '']`), `:56` (`filter_format` `['weekly', …]` — `weekly` is not a shipped format; `weeks` is), `:78` and `:79` (the two halves of one hash-determinism test), and `tests/external/audience_external_test.php:471` (`range(1, 750)`) and `:498` (`range(1, 12)`), both `filter_course`. **The number depends on placement**: the four `normalise()` unit tests break only if existence checks go inside `normalise()`, which section 2 argues against; at the entry points, only the two cap tests are touched, and those need either real courses or a field the validator does not existence-check. A five-minute call at implementation time, to be made deliberately. |

---

## 5. Found on the way

None of these is a scoping question; each is true on `main` today or becomes true the day a course
capability exists.

- **`reqcourse` fails open on a deleted course** — the only shows-to-everyone missing referent in
  the plugin, documented in its own comment (`helper.php:825-827`), on all three consumers. Worth
  fixing on its own.
- **The theme filter fails open on a resolution failure** (`helper.php:2131-2141`): the block is
  skipped when `current_theme_name()` throws or returns empty. Value-independent; a real fail-open
  in a plugin whose other filters fail closed, and possibly the intended behaviour. Decide, and say.
- **The breakdown chips are course-unbounded, and that is documented intent.**
  `isolate_rule()`'s docblock (`estimator.php:219-236`) says "the rule alone" means without the
  *other* rules, and lists the course as a modifier of `filter_role` only. For an administrator
  that is the reading the chip is meant to have. For a course-scoped author every chip becomes a
  site-wide oracle — "how many users hold role X anywhere", "how many are proficient in K in any
  course", "how many are enrolled in any course of category Y" — so under a course scope the forced
  course has to become a modifier of every rule, or the chips have to go. One reading in this
  analysis called this a correctness fix to land on its own merits; the docblock says otherwise,
  and it should not be "fixed" for administrators.
- **The cohort membership oracle gets worse under a course scope, not better.** The estimator
  returns a plain unbucketed integer (`estimator.php:202`). Today it answers "how many of the whole
  site are in cohort X". Forced to C, it answers "how many of my named students are in cohort X",
  over a roster the teacher already has by name; with the acknowledgement report beside it, that
  can identify individuals. RESTRICT shrinks which cohorts can be probed and does not remove the
  primitive. This was reasoned from two verified facts, not constructed — present it to the owner
  as a risk to rule on, not a demonstrated exploit.
- **Under (a), an ownership hole appears in three files** (`editnotice.php:60`, both
  `report/*.php:36`). Not a defect today — only site managers reach them — and the first thing to
  close before any grant.
- **`check_collision` returns the titles of every enabled repeating notice on the site** — its
  docblock says so (`:55-60`: "only the titles cross the boundary") — because `collision::enabled_repeating_notices()` (`:213`) reads every enabled repeating
  notice on the site. Inert today; a title leak the moment a narrower capability holds that gate.
- **The read-site inventory's search scope silently omitted `report/`.** `rg` over
  `classes lib.php *.php` reproduced the 51 occurrences in 22 files it was told to expect, and the
  positive control passed — and the two must-change files under `report/` were still missing,
  found only by the synthesis critic reading the tree. A count that matches the expectation is not
  proof of a complete scope. Walk the root with an exclusion list.

---

## 6. What was refuted, and what was not verified

**Refuted and corrected.** The roles reading survived on its verdicts but carried five citation
errors, one of them a false premise: it rejected `get_assignable_roles()` because "an ordinary
course teacher does not hold `moodle/role:assign` by default", and core grants it to
`editingteacher` at course level on all three branches (`lib/db/access.php:650-661`). The
recommendation stands on the correct ground — that helper answers a question about the caller,
not about the site — but the premise is struck. The synthesis's "isolate_rule() correctness fix"
was refuted by the docblock, above. The `filter_category` widening was narrowed by its refuter: the
exploit needs the list to carry both C's real category and the sibling, because `check_filters()`'s
own category block gates display on the same array.

**Not verified.**

- Nothing was executed. The six breaking fixtures are predicted, not observed. The claim that no
  existing test would catch a course-unbounded competency chip was made by reading fixtures (every
  competency scenario uses a single enrolled course) rather than by writing the two-course test.
- 4.5 and 5.2 coverage is uneven. The existence APIs and the navigation callback were checked on all
  three; `update_capabilities()`, `delete_role()`, the `moodle/role:assign` archetypes, the
  `delete_course` completion-data chain and the whole `tool_monitor` precedent were read on 5.1
  only (the `tool_monitor` capability file is byte-identical on 4.5 and 5.2; the rest is assumed).
- The foundation cost and the validator design each rest on one reader; neither was refuted. The
  read-site inventory was, and its refuter is what exposed the `report/` omission.
- The competency restrict set was not tested against a course whose competencies arrive through a
  learning plan or template rather than a direct `course_competency` link.
- The auxiliary fixture totals are disputed (108 sites by the measurer, 189 by its refuter, a
  site-definition difference), so only the six agreed `file:line` are quoted here.
