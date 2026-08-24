# `availability_awareness` — feasibility

**Date:** 2026-08-24 · **Measured against:** Moodle 4.5 (`~/dev/moodle-405`), 5.1 and 5.2, and
`local_awareness` at `968a770`. Every claim below cites code that was read; the negatives were
re-run by a second agent told to refute them, and the ones that did not survive are marked.

## The question

Could a `availability_*` plugin restrict access to a course or activity based on **acceptance of an
Awareness notice**? The appeal is real: it moves the consequence of refusing a notice off the
*session* and onto *access to specific things*, using machinery Moodle already has and teachers
already understand, without destroying work or forcing re-authentication.

## Verdict

**Buildable, but it is not the thing it was hoped to be, and it cannot be built correctly yet.**

Three findings, in descending order of how much they change the decision:

1. **It is a teacher-facing, per-activity tool.** Availability attaches only to sections and
   modules, and is configured by whoever edits the course. An administrator publishing a site-wide
   compliance notice cannot push a restriction onto anything. So this does **not** answer the
   question `forcelogout` was trying to answer.
2. **"Accepted" has no stable meaning in `local_awareness` today.** Five defects, listed below, make
   the predicate either wrong or unanswerable. They must be fixed first — in the plugin, not in the
   condition.
3. **Cost and restore are both solvable**, and neither is the blocker. Details below, because they
   are the parts that would sink a naive implementation.

---

## Q1 — Can it be evaluated without a query per user per activity?

**Yes.** Core offers two independent mechanisms that share no code.

### The per-user path (`is_available`)

There is **no batch API**. `\core_availability\condition::is_available($not, $info, $grabthelot, $userid)`
is abstract (`availability/classes/condition.php:80`, identical on all three branches) and is called
**once per course-module per user**, with `$grabthelot` hardcoded `true`:

| branch | call site |
|---|---|
| 5.1 | `course/classes/cm_info.php:1452-1461` |
| 5.2 | `course/classes/cm_info.php:1505-1509` |
| 4.5 | `lib/modinfolib.php:2514-2518` |

Each call builds a **fresh** `info_module`, which decodes its own tree and constructs its own
condition objects (`availability/classes/info.php:146`). An instance field is therefore useless as a
memo — it must be static on the class or in MUC.

**The pattern to copy is `availability_grade`.** On the first call it fills a MUC application cache
keyed by userid with *every* grade item for the course in one recordset
(`availability/condition/grade/classes/condition.php:236-250`, `:287`), declared with
`staticacceleration` and a 3600s TTL in its `db/caches.php`. Core accepts a TTL'd cache for an
availability decision, which lowers the invalidation bar considerably.

`availability_completion` is the weaker precedent: it delegates to `completion_info::get_data()`,
whose whole-course branch only runs for the **current** user (`lib/completionlib.php:1036`
`$usecache = $userid == $USER->id;`). For any other userid it degrades to one query per activity —
precisely the teacher/report case. An awareness memo keyed by userid rather than restricted to
`$USER` would be strictly better than core's own, and that costs nothing extra to do.

`local_awareness` already owns most of this machinery: `classes/persistent/noticeview.php:154-192`
is a MODE_APPLICATION cache keyed by userid filled by one query. **One caveat before reusing it:**
its invalidation is incomplete — `delete_notice_view()` (`:145-148`) does a raw
`$DB->delete_records()` with no purge, and it has a live caller at `helper.php:432`.

### The bulk path (`get_user_list_sql` / `filter_user_list`)

Opting in means returning `true` from `is_applied_to_user_lists()`; only **three of core's six**
conditions do (group, grouping, profile — completion and grade opt out entirely).

Two things decide whether it is worth it:

- **`local_awareness_lastview` already carries `UNIQUE (userid, noticeid)`** (`db/install.xml:99`,
  live as `m_locaawarlast_usenot_uix`), so a correlated `EXISTS` is an exact two-column probe with
  **no schema change**. `local_awareness_ack` has **no unique key** — it must be reached by `EXISTS`
  from the notice side, never joined.
- **Opting in is a semantic decision, not only a performance one.** `tree::filter_user_list`
  (`availability/classes/tree.php:314-358`) silently `continue`s a non-participating child under
  **AND**, but under **OR** sets `$anyconditions = false; break;` — returning **every** user. A
  condition that declines the bulk path weakens any OR-tree it appears in.

Two traps worth naming now:

- **A naive `record_exists()` inside `is_available()` is worse than it looks.** `info.php:628`
  early-returns for an item with no availability JSON, so core currently pays *nothing* on
  unrestricted activities. Adding this condition is what converts a free early return into a real
  per-call capability query. The plugin does not join an existing cost — it creates one.
- **The one-placeholder-per-statement rule** applies to any bulk SQL. Core ships
  `tree_node::unique_sql_parameter()` (`availability/classes/tree_node.php:248`) for exactly this.

---

## Q2 — What happens on restore, especially onto another site?

This is the genuine risk, and the first instinct about it is wrong.

### The mechanics

- `update_after_restore()` lives on **`tree_node`**, not `condition` (`tree_node.php:124`), defaults
  to `return false`, and its bool means only *"the serialised tree changed"* — it is **not** a
  success signal.
- It has exactly **one** production call site: `restore_update_availability` in
  `backup/moodle2/restore_stepslib.php:906` (sections) and `:926` (modules). That class body is
  md5-identical across 4.5, 5.1 and 5.2, so one implementation covers the range.
- `update_dependency_id()` is **not** part of the restore path at all — zero callers outside
  `availability/` on all three branches.
- The only deliberate way to drop a condition is `include_after_restore()`; the drop is silent
  (`tree.php:679-689` unsets the child with no logger call) and an emptied root tree nulls the
  availability field.

### Why an Awareness notice is the hard case

Core's conditions split by referent scope, and the split decides everything:

| condition | referent | scope | restore hooks |
|---|---|---|---|
| completion, grade, group, grouping | cm / grade item / group / grouping | course | remap, with a same-course fallback |
| date | none | — | shifts the timestamp only |
| **profile** | custom/standard profile field | **site** | **none at all** |

The four course-scoped ones can afford to poison an unmapped id, because they first check the id
still belongs to *this* course (`group/classes/condition.php:152-156` and parallels) — a check a
site-scoped referent structurally cannot perform.

**`availability_profile` — the only site-scoped one — avoids the problem entirely by storing the
field's `shortname`, not its id** (`profile/classes/condition.php:127-140`, `:360-373`). It
implements no restore hook because it does not need one.

**A notice has no portable key.** `db/install.xml` gives `local_awareness` only an autoincrement
`id`, so the profile pattern is not available without a schema addition.

### The assumption that did not survive

The first pass concluded *"every core condition fails closed on a missing referent, so a dangling id
is safe."* **That is false.** In profile, grade, group and grouping the missing-referent result is
computed and *then* inverted:

```php
$allow = /* false, referent missing */;
if ($not) { $allow = !$allow; }   // profile :181-184, grade :109-111, group :86-88, grouping :100-102
```

So a **negated** condition ("has NOT accepted notice N") becomes **available** when its referent is
missing. Only `completion` is inversion-proof, and it says so in its own comment. Group and grouping
additionally fail open outright for anyone holding `moodle/site:accessallgroups`.

**Consequence: a dangling notice id is not a conservative default. It silently grants.**

### Recommendation

In order of preference:

1. **Give notices a portable key** (an `idnumber`-style unique string) and reference *that*. This is
   the `availability_profile` design, and the only one where a cross-site restore can be *correct*
   rather than merely safe.
2. **Drop the condition on any cross-site restore**, via `include_after_restore()`. This is what the
   same author's `availability_competency` already does
   (`~/dev/moodle-availability_competency/classes/condition.php:146-160`, gated on
   `$restorecontroller->is_samesite()`), so the house already has the shape.
3. **Never** keep a dangling id.

### One constraint that binds regardless

`info::is_available()` wraps the whole tree in `try { … } catch (\coding_exception $e) { … return false; }`
(`info.php:197-203`). **A constructor that throws on a structure it cannot resolve takes the entire
item unavailable — every sibling condition with it.** The condition's constructor must not throw.

Separately: if `availability_awareness` is absent or disabled on the target site, the condition is
silently dropped at tree-load time (`tree.php:222-231`) and the restore reports nothing.

---

## Q3 — What counts as "accepted"? (the actual blocker)

Verified directly against the tree. These are defects in `local_awareness`, not in the prospective
plugin, and each one changes what a restriction would mean.

**S1 — A `reqack = 0` notice does produce acceptance rows.** `acknowledge_notice()` applies no
`reqack` test at all, so its only possible row is an ACKNOWLEDGED one. This contradicts the plugin's
own comment at `classes/helper.php:1151` ("`local_awareness_ack` only ever holds reqack rows").

**S2 — The dedupe docblock is false.** `helper.php:1071-1076` states the check happens "at the only
two writers". `has_acknowledgement_record()` has exactly **one** call site — `helper.php:1142`, the
dismiss path. The acknowledge path instead calls `check_if_already_acknowledged_by_user()`, which
reads a **different table** (`local_awareness_lastview`) and returns false whenever `must_reshow()`
is true.

**S3 — Acceptance is monotone, and never becomes false.** Because of S2, an edit (`timemodified`), a
`resetinterval` expiry, or a disable/re-enable each permit a **second** ACKNOWLEDGED row while the
first survives, carrying its stale `noticetitle` snapshot. "Has an ack row" can only ever grow —
**the opposite of what `resetinterval` means to the author who set it.** A restriction built on it
would open once and never close again.

**S4 — The rows S1 writes are unreachable from the admin UI.** `classes/table/all_notices.php:469-471`
gates the acknowledged- and dismissed-report buttons behind `if ($awareness->get('reqack'))`. So for
exactly the `reqack = 0` notices that accumulate rows, the manage list offers no link to the report
that would display them. (The report pages still answer a hand-built `?noticeid=` URL — invisibility,
not inaccessibility.)

**S5 — No public predicate exists.** Both are private, and the tempting one,
`check_if_already_acknowledged_by_user()`, reads the wrong table *and* **mutates global `$USER`**
(`helper.php:1405`) despite taking `$userid` as a parameter. Calling it from an availability
condition evaluating another user would corrupt the viewing user's session state.

### Two further consequences specific to a restriction

- **Acceptance is path-dependent.** Close-then-Accept on a `reqack = 0` notice writes nothing, ever.
- **It can be unreachable by construction.** A notice targeted at a cohort the student is not in can
  never be accepted by that student — and `local_awareness` has **no user-facing surface at all**, so
  a student blocked by an unreachable notice sees nothing anywhere explaining why. Every top-level
  page is capability-gated to managers.

### The definition to adopt

Once S1–S5 are fixed, **"accepted" should mean an `ACTION_ACKNOWLEDGED` row that has not been
superseded** — that is, one written since the notice's last `timemodified` and within the current
`resetinterval` window. Dismissal must **not** count: the plugin distinguishes the two actions
deliberately, and a restriction satisfied by refusing the notice is not a restriction.

---

## What would actually have to be built

In order, and the first item is not optional:

1. **Fix S1–S5 in `local_awareness`**, ending with a public, side-effect-free predicate that answers
   "has user U accepted notice N" for an arbitrary user — and a bulk form for the same question.
2. **Add a portable key to notices**, or accept option 2 of the restore recommendation.
3. Then, and only then, `availability_awareness`: a constructor that never throws, an
   `is_available()` backed by a userid-keyed MUC memo on the `availability_grade` pattern, an
   explicit decision on `is_applied_to_user_lists()` given the OR-tree behaviour, and
   `include_after_restore()` handling the cross-site case.

## What it will still not do

Worth stating plainly, because it is the reason this was investigated at all:

- It cannot gate a **course** — sections and modules only.
- It is configured **per activity, by course editors** — not by the administrator who publishes the
  notice.
- Teachers bypass it: `moodle/course:ignoreavailabilityrestrictions` is CAP_ALLOW by default for
  manager, coursecreator, editingteacher and teacher (`lib/db/access.php:963-973`, consumed at
  `cm_info.php:1575-1583` and `section_info.php:464`).
- It is inert when `$CFG->enableavailability` is off.

**If the requirement is "cannot continue until acknowledged", the mechanism is not availability.** It
is core's site-policy handler — `$CFG->sitepolicyhandler`, gated in `require_login()`
(`lib/moodlelib.php:2467-2477`) — which survives JavaScript being off and is understood by the
Moodle App. Its cost is that a site has exactly **one** handler (colliding with `tool_policy`) and
that `policyagreed` is a single lifetime boolean on the user record, which cannot express a
per-notice, repeatable block without being reset site-wide.
