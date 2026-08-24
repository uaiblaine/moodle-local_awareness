# `block_awareness` — feasibility

**Date:** 2026-08-25 · **Measured against:** Moodle 4.5, 5.1 and 5.2, and `local_awareness` at
`ccbabb6`. Five independent readings, each put to an agent told to refute it; the claims that did
not survive are marked, because two of them reversed a conclusion.

Companion to [`AVAILABILITY-AWARENESS-FEASIBILITY.md`](AVAILABILITY-AWARENESS-FEASIBILITY.md),
which found that `availability_awareness` is teacher-facing and per-activity while notices are
admin-owned and site-wide. This proposal is aimed at exactly that mismatch.

## The proposal

A teacher sends notices to the participants of their own course. Notices aimed at teachers and
editors stay site-level. `local_awareness` is the engine; the course surface adds audience filters
restricted to what the teacher can already read — cohorts the course enrols from, competencies
linked to it. A notice that already holds acceptance data cannot be edited; the teacher resets it,
hides it, or duplicates it.

## Verdict

**The goal is sound and reachable. The block is not the right vehicle, and the work is in
`local_awareness`, not in the new plugin.**

Three findings, in the order that should change the plan:

1. **Core has already built this exact shape, and it used no block.** `tool_monitor` is the
   structural analogue, verified line by line.
2. **Display needs nothing new.** `filter_course` already means "only on this course's pages, only
   for someone currently in it". Ownership buys authorship, permissions and reporting — not display.
3. **The immutability rule is aimed at the wrong verb**, and all three of its escape hatches do the
   very thing it exists to prevent.

---

## 1. Not a block: `tool_monitor` is the precedent

Verified against `admin/tool/monitor` on 5.1:

| the shape this proposal needs | how `tool_monitor` does it |
|---|---|
| a capability a teacher can hold in a course | `tool/monitor:managerules` — `contextlevel => CONTEXT_COURSE`, CAP_ALLOW for `teacher`, `editingteacher`, `manager` (`db/access.php:42-50`) |
| rows owned by a course | `courseid` on its rules table, with an index (`db/install.xml:14`, `:28`) |
| a way in from the course | `tool_monitor_extend_navigation_course()` (`lib.php:34`) |
| cleanup when the course goes | a `\core\event\course_deleted` observer (`db/events.php:28-30`) |
| a block | **none.** `ls` shows `edit.php`, `index.php`, `managerules.php`, `settings.php` and no block directory |

One page serves both scopes, reached from course navigation. That is the design to copy.

**The argument I nearly made against blocks was wrong, and is worth recording.** A first reading
claimed new courses get no blocks by default, so a block entry point would have to be hand-added
before a teacher could author anything. Refuted: `course/format/social/lib.php` and one other core
format override `get_default_blocks()` and return real blocks, identically on all three branches.
The case against the block does not need that argument — it rests on `tool_monitor`, on a block
being removable in a way that would strand the notices it created, and on backup (a block
contributes rows only when an instance exists on the course page *and* the backup's "blocks"
checkbox is ticked, which is a fragile vehicle for data that ought to belong to the course).

---

## 2. Display already works; ownership is an authoring concern

`filter_course` is implemented **twice on purpose** — in `page_probe::filters_admit()` for the
cheap page gate and in `helper::check_filters()` for the real decision — and both mean the same
thing. The modal is loaded from exactly one place, the `before_footer_html_generation` hook. So a
course-scoped notice reaches that course's participants today with nothing added.

What ownership adds is therefore: who may author it, who sees it in the manage list, and what the
compliance reports show. That is a much smaller and much more tractable proposal than "a block that
shows notices".

### One correction to this repository's own guidance

`CLAUDE.md` states that `check_filters()` resolves the course through
`can_access_course($course, null, '', true)` and that the `$onlyactive = true` **"demands an ACTIVE
enrolment"**, concluding that a course-targeted notice never appears on that course's enrolment page.

That is true of one leg of the function, not of the function. `can_access_course()` continues past
`is_enrolled($coursecontext, $USER, '', $onlyactive)` to a **temporary guest-access** leg
(`lib/accesslib.php:2070-2082`, identical on 4.5, 5.1 and 5.2): it walks the course's enabled enrol
instances calling `try_guestaccess()`, and returns true if any grants access. So on a course with
guest access enabled, a **non-enrolled** user — and the guest user — passes.

For this proposal that matters directly: a teacher writing to "my participants" would also reach
anyone browsing the course as a guest. It is fixed in `CLAUDE.md` alongside this document.

---

## 3. The immutability rule is aimed at the wrong verb

The rule has a real mechanical basis, and it is stronger than "teachers err more than admins".

`core\persistent::update()` is `final` and stamps `timemodified = time()` unconditionally after
validation (`lib/classes/persistent.php:566` on 5.1; the same on 4.5 and 5.2, with the create-path
twin at `:508`). `helper::interaction_is_stale()` compares every recorded interaction against that
column. **So one save — even a save that changes nothing — expires every acceptance on the notice
and re-shows it to the whole audience.** That is exactly why a teacher should not edit casually.

**But all three offered escapes do the same thing.** `helper::reset_notice()` is literally that
no-op save — `new awareness($id); $notice->update();` plus an event — and clears no row in any
table. Enabling and disabling each go through `update()` too. So reset and hide have the identical
effect on consent that the rule exists to prevent; only their names differ.

**And "duplicate" does not exist in any form.** The row action menu offers edit, enable/disable,
preview, recalculate, reset, the two reports and delete. Building it is not a row copy: a duplicate
would have to decide, column by column and side table by side table, what travels — and the obvious
implementation silently loses every embedded image, because `notice_form::get_default_data()` keys
the content file-area copy on the persistent's own id. The link rows carry `data-linkid` values
belonging to the source notice, so a raw copy would attribute the duplicate's clicks to the
original.

**What the rule should say instead:** the protected verb is *any save*, not *editing*. A coherent
design either (a) forbids every write once acceptances exist, which means reset and hide need a
path that does not touch `timemodified`, or (b) accepts that expiry is the point of reset and
renames the operations to say so.

---

## 4. The structural blockers, all in `local_awareness`

None of these is affected by the block-versus-page choice.

- **Both capabilities are `CONTEXT_SYSTEM` with a `manager` archetype** (`db/access.php`), so no
  grant a teacher could hold exists. Every capability check in production code resolves to
  `context_system::instance()`.
- **No ownership column.** The `local_awareness` table has 23 columns and not one is a `courseid`
  or `contextid`. The only course-shaped column, `reqcourse`, is a suppression rule.
  `usermodified` cannot substitute: core overwrites it on every write, including reset and disable.
- **`filtervalues` cannot carry ownership.** It is an unindexed text blob no SQL reads inside,
  rewritten wholesale from the POST on every save — so the author would control their own scope.
- **`local_awareness_pluginfile()` refuses any context that is not `CONTEXT_SYSTEM`**, so a
  course-owned notice could not serve its own images.
- **Five of the nine web services hard-gate on `local/awareness:manage` at the system context.**
- **The authoring pages are admin-tree pages** (`admin_externalpage_setup()`).

### The security boundary does not exist yet

This is the part to design first, and it is bigger than it looks.

`helper::sanitise_data()` validates **one** of the twelve audience and context inputs a notice can
carry — `cohorts` — and even that allowlist is site-wide (`built_cohorts_options()` wraps
`cohort_get_all_cohorts(0, 0)`). Everything else is packed into `filtervalues` verbatim from the
POST.

Two consequences a course-level author would exploit without trying:

- **A notice with no filters at all goes to the whole site.** `check_filters()` returns true on an
  empty filter set, and `check_path_match()` returns true on an empty pathmatch. A teacher who
  saves a notice and touches no audience field has just published site-wide. A default in the form
  is not a boundary.
- **The `filter_course`, `filter_role` and `reqcourse` autocompletes are `ajax`, and core declines
  to validate ajax values server-side.** `MoodleQuickForm_autocomplete::exportValue()` says so in as
  many words: *"When this was an ajax request, we do not know the allowed list of values."* A POST
  can name any course or role id on the site.

So every scoping rule must be enforced server-side, in `sanitise_data()` or a context-aware
validator, on **both** `create_new_notice()` and `update_notice()` — never in the form alone.
Scoping a picker's options is worth doing but is unsafe as the only mechanism: QuickForm's
`select::exportValue()` skips its allowlist entirely when the option list is empty, so a teacher
whose course enrols from no cohort would get no filtering at all.

---

## 5. Performance, measured

The enabled-notices cache is a single site-wide key holding every enabled notice **including its
body**. The footer hook pays O(N) PHP per page render: **1.90 ms at 50 notices, 7.37 ms at 200,
37.21 ms at 1000**, with a 2.8 MB payload at the top end. Two consequences specific to this
proposal, which multiplies the number of notices by the number of courses:

- **Any teacher's save purges the cache for the whole site.**
- **A single cohort-carrying notice anywhere adds a database query to every page for every user** —
  and cohorts is this proposal's headline filter.

Per-course scoping should therefore change the cache key, not just add a `WHERE` clause.

---

## 6. What was refuted

Recorded because both reversed a conclusion:

- **"Course notices are starved by site notices."** False. The display queue's tiers key on
  **met versus unmet**, not on scope, and PHP's array union preserves the left operand's order — so
  an unmet course notice outranks every already-met site notice. The trace that appeared to show
  starvation was watching unmet site notices, not scope.
- **"New courses get no blocks by default."** False on all three branches, as above.

Two enumerations were also wrong in ways worth noting for anyone re-running this: the claim that
only `enrol_cohort` links cohorts to courses (roughly twenty files match, including `enrol/self`),
and several counts that did not reproduce (52 context assumptions, not 69; nine external classes,
not ten). The conclusions survived; the numbers did not.

---

## What to build, in order

1. **A `courseid` (or `contextid`) column on `local_awareness`, and capabilities that can live at
   `CONTEXT_COURSE`** — the `tool_monitor` shape.
2. **A server-side scope validator** applied to every write path, that forces the owning course's
   filter, forbids the filters that reach outside it, and intersects the rest against what the
   author may read. Not the form.
3. **Scope the nineteen site-wide read sites**, the audience estimator (which scans `{user}`
   site-wide), the cohort picker, the collision badge (which currently leaks other courses' notice
   titles), the privacy provider, and the cache key.
4. **Decide what a save means once acceptances exist**, and make reset and hide honest about it.
5. **Then** the course surface — a page reached from course navigation, not a block.

## What it will still not do

- It does not make `availability_awareness` easier on its own; that plugin's blockers are its
  acceptance semantics and cross-site restore, both unchanged by this.
- A teacher cannot build custom Report Builder reports: `moodle/reportbuilder:edit` is
  `CONTEXT_SYSTEM` with a manager archetype, and new custom reports are created at the system
  context by construction. A course-context **system** report is possible — `mod_lti` ships a live
  one — but it is developer-defined, not teacher-built.
