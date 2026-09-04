# Per-notice modal layouts, screen positions and entrance animations — feasibility

Assessed 2026-09-03 against the current tree (`version.php` 2026082404) and the core checkouts
for 4.5, 5.1 and 5.2, with measurements on the running stacks. The interactive mockups the
proposal was drawn against live in the session artifact
<https://claude.ai/code/artifact/bd47691c-03d7-4a3e-a9f1-79b51a95301d>; once a direction is
approved the HTML belongs in `mockups/` beside the other prototypes.

**Verdict: feasible with changes.** The feature works on all three supported branches, but not
with the mechanics the proposal first described. Three facts decide the shape of the work:

1. **Every new class must be plugin-owned.** The plugin's Bootstrap 4 polyfill gate
   (`body.local-awareness-bs4`) cannot reach the notice modal — `bootstrap::mark_page()` runs on
   four plugin pages while the modal is injected site-wide by a hook — so `modal-fullscreen`,
   `rounded-3`, `sticky-bottom` and the offset utilities are dead on 4.5 with no repair path.
   Layout, position and animation classes are hand-authored under `.awareness`, ungated.
2. **The queue reuses one visible modal, so core gives nothing for free.** `show()` early-returns
   from the second notice onward, `modal-lg` is baked into the markup, and swapping position
   classes on a modal already on screen teleports it. Entrance animation, sizing and shape changes
   are driven explicitly from `notice.js`; a shape change between consecutive notices is
   hide → swap → show.
3. **Preview parity is real work.** Both previews use `core/modal_cancel`; `ModalNotice` binds no
   exit handlers of its own and ignores `removeOnClose`; and an existing notice's background image
   has no URL in the DOM until the picker is re-touched, so the editor preview needs a small new
   draft-file web service.

Recommended v1 scope: `classic`, `hero`, `fullscreen`, `card`; positions center/top/bottom
everywhere plus the four corners for `card`; all five animations. `split`, `minimal` and `banner`
deferred. The video and carousel layouts requested afterwards are assessed in Part 2.

## Decisions taken (2026-09-04)

The prototype was approved as drawn, with these answers to the open questions:

1. **Slides are structured** — a `local_awareness_slides` table and a repeated group in the form,
   as recommended; the `<ul><li>` capture is not implemented.
2. **Video is a link, never an upload** — YouTube, Vimeo, or a direct MP4/WebM link, all played by
   the site's multimedia filter (video.js for files). No video file area, no size cap to carry.
3. **No autoplay.**
4. **Every layout is a real modal for assistive technology**, the card included: backdrop, focus
   trap, `aria-modal`. This was already true and is now said in the field's help text.

Implemented scope: `classic`, `hero`, `fullscreen`, `card`, `video`, `carousel`. `split`, `minimal`
and `banner` stay deferred. One deliberate visible change for existing notices: `center` is a
real centre, so a notice that sat where Bootstrap places a dialogue by default (against the top)
now sits vertically centred; it is recorded in the changelog.

How this was produced: seven read-only lenses over the plugin and core (core/modal mechanics,
the stylelint gate and Boost modal CSS, data model/form/WS, preview parity, accessibility and
motion, Bootstrap 4/5 compatibility, product critique), one refuter per blocker/required finding,
and a consolidation pass that re-opened every cited file. Two lens findings were refuted on
evidence and are recorded as such below.

---

# Part 1 — layouts, positions, animations

# Consolidated findings

Ranked most severe first. Two findings were refuted on evidence and are recorded at the end. Everything below was verified against the files during consolidation, not taken from the lens reports alone.

---

## 1. BLOCKER — The BS4 polyfill gate cannot reach the notice modal, so no Bootstrap-5 utility name may appear in the new classes

*(bs-compat F1, upheld; css-gate F1 and a11y F5 are the same wall seen from two other angles.)*

`classes/local/bootstrap.php` says so in its own docblock: "The notice modal is deliberately NOT covered: notice.js shows it on arbitrary pages, where this marker cannot be on the body." `mark_page()` has exactly four call sites — `editnotice.php:38`, `managenotice.php:58`, `report/dismissed_systemreport.php:48`, `report/acknowledged_systemreport.php:48` — and the modal arrives via `classes/local/hook_callbacks.php:58`, `$PAGE->requires->js_call_amd('local_awareness/notice', 'init', [])` on the `before_footer_html_generation` hook. The two sets never intersect.

The plugin has already paid for this once. `styles.css:957-964` carries the scar: `fw-bold` on the modal title resolved to nothing on 4.5 and had to be re-authored as `.awareness .modal-title { font-weight: 700; }`.

Measured on the running stacks (`curl /theme/styles.php/<theme>/1/all`, regex for standalone rulesets, not raw substrings):

| class | 4.5 boost / boost_union | 5.2 |
|---|---|---|
| `.modal-fullscreen` (+5 `-down` variants) | **0 rules** | 6 real rules |
| `.rounded-1` … `.rounded-5` | **0** | 1 each |
| `.sticky-bottom` | **0** | 1 (`position:sticky;bottom:0;z-index:1020`) |
| `.sticky-top` | 1 | 1 (native both) |

Every raw `.modal-fullscreen` substring on 4.5 belongs to `mod_interactivevideo` / `mod_flexbook` compound selectors (both mounted on m405 per `plugins.conf`), never to Boost. Confirmed at source too: `moodle-405/theme/boost/scss/bootstrap/_modal.scss` has zero occurrences; `moodle-502/public/theme/boost/scss/bootstrap/_modal.scss:207-236` generates them in a `modal-fullscreen-loop`. This is a Bootstrap 4 vs 5 API gap, not a build omission — no theme config on 4.5 could produce it.

**Consequence for the plan:** `fullscreen` ships its own geometry (`width:100vw; max-width:none; height:100%; margin:0` plus a scrollable body), unconditionally, under a plugin-owned name. Same for corner anchoring, banner edges and card radii. Nothing gated, nothing borrowed.

**Specificity is not the problem.** Zero `!important` declarations touch `.modal`, `.modal-dialog`, `.modal-lg` or `.modal-fullscreen` in Boost or Boost Union on any branch; a two-class selector such as `.awareness.la-tpl-fullscreen` (0,2,0) beats core's `.modal-dialog` (0,1,0) regardless of order.

---

## 2. REQUIRED — The regression test that exists to catch exactly this defect has three holes, in exactly the families this feature reaches for

I read `tests/local/bootstrap_compat_test.php:65-92` directly. `bs5_only_utilities()` is 24 regexes. It covers `gap-*`, `text-bg-*`, `object-fit-*`, `ratio*`, `z-*`, `translate-middle`, and the offset family via `'/(?<!border-)\b(top|bottom|start|end)-(0|50|100)\b/'`. It does **not** match `rounded-1`…`rounded-5` (the `rounded-left/right` entries are in `deprecated_bs4_names()`, a different family; `border-[1-5]` is border *width*), nor `sticky-bottom`, nor `modal-fullscreen`.

So the detector — which the plugin's own CLAUDE.md credits with holding a rule at 100% where prose failed 90 times — would stay green while these three ship broken on 4.5.

---

## 3. REQUIRED — The queue reuses one already-visible instance, so core supplies neither the animation trigger nor the sizing, and a live class swap teleports the dialogue

Three separate consequences of one fact, all verified:

**(a) `show()` no-ops from notice #2 onward.** `moodle-502/public/lib/amd/src/modal.js:899-902` — `show() { if (this.isVisible()) { return $.Deferred().resolve(); } …}`, with `isVisible()` at `:858-860` a plain `hasClass('show')` check. Identical on 4.5 (`:866-869`) and 5.1 (`:868-871`). `amd/src/notice.js:61-70` states the invariant: `modal.hide()` is reached only when the queue empties. The reused branch (`notice.js:110-120`) calls `modal.show()` on a modal that never left the screen. So `ModalEvents.shown` / `core/modal:shown` never re-fire — an entrance animation hung off either animates the first notice only.

**(b) `modal-lg` is baked into the markup.** `templates/modal_notice.mustache:44` — `class="modal-dialog awareness modal-lg …"`, and the `{{$classes}}` block is never populated from PHP. `configure({large:true})` therefore calls `setLarge()`, which early-returns because `isLarge()` is already true. `setSmall()` would work but is never called: `grep -rn 'setSmall|setLarge|isSmall|isLarge' amd/src/` returns zero hits, and `styles.css` has no `modal-lg` selector. Compact templates must strip it explicitly, for every notice including the first.

**(c) A shape change between consecutive notices teleports.** `notice.js:110-120` mutates title/body/insistence/bgimage/size live on the visible dialogue. Extending that to position and template means an Informational top-end card followed by a Blocking centred classic relocates and reshapes instantaneously, mid-read. That reads as a bug, not an animation.

The remedy for all three is one mechanism the plugin already owns: the reflow trick at `amd/src/modal_notice.js:230-234` — `dialog.removeClass('jelly-anim'); void dialog[0].offsetWidth; dialog.addClass('jelly-anim');`. That precedent is genuine but small (one class, in place). For a *shape* change it is not sufficient: hide → swap classes → show.

**Green light on the surrounding mechanics.** `hasTransitions()` (`modal.js:879-881`) gates on `.fade`, which `templates/modal_notice.mustache:43` deliberately omits (`class="modal moodle-has-zindex"`), so `hide()` is always synchronous. `diff` of `lib/amd/src/modal.js` 4.5 vs 5.2 differs only in the registry refactor, two `dispatchEvent` calls and scrollbar padding — nothing in `show()`/`hide()`/`configure()`/`setLarge()`/`getBackdrop()`. `modal_backdrop.js` is byte-identical. `FocusLock` and `Aria.hideSiblings` are DOM-structural and indifferent to CSS position.

---

## 4. REQUIRED — Both previews use a different modal class, and `ModalNotice` supplies no working exit buttons on its own

`amd/src/editor_preview.js:34,100-108` uses `ModalCancel.create({… removeOnClose: true})`. `amd/src/preview.js:27-35` (manage list) likewise, fed only by `data-noticecontent` (`classes/table/all_notices.php:461`) — so the manage-list preview already ignores background image and size today, and would ignore template/position/animation entirely.

Switching to `ModalNotice.create()` works mechanically (`ModalNotice.create = Modal.create` is a bare assignment, so `new this(html)` constructs a `ModalNotice`), but produces a dialogue with **dead buttons**:

- `ModalNotice.prototype.registerEventListeners` (`amd/src/modal_notice.js:218-254`) fully replaces core's. Its backdrop and Escape branches only `.trigger('click')` on `[data-action="close"]`.
- Core's own hide/destroy handling binds to `SELECTORS.HIDE = '[data-action="hide"]'` (`modal.js:65`) — a selector this template never uses. Its four exit controls carry `data-action="close"` / `"accept"` (`modal_notice.mustache:49,76,79,82`).
- The only code that gives them behaviour is `notice.js:88-104`, which calls the `local_awareness_dismiss` / `local_awareness_acknowledge` web services — precisely what a preview must not do.
- `removeOnClose` is inert on `ModalNotice`: the override never reads `this.removeOnClose` and never calls `destroy()`. Worse, its Escape handler binds on `$(document)`, not `this.getRoot()`, so every fresh Preview instance leaks another document-level keydown closure.

**Preview must bind its own close/not-now/accept handlers calling `modal.destroy()`.**

---

## 5. REQUIRED — Previewing the background image of an existing notice has no URL source; a small new web service is needed

`classes/helper.php:1724` `get_bgimage_url(int $noticeid)` reads the *saved* file area, so it cannot serve an unsaved draft. On initial page load the filepicker renders the filename as plain text — `core_renderer.php:2443-2448` drops `$currentfile` into the container with no anchor; the `<a href>` is written only by `M.form_filepicker.callback` (`lib/form/filepicker.js:5-13`) after a *fresh* pick in the current page load. The obvious fallback is closed too: `core_files_get_files` has no `'ajax' => true` in `lib/db/services.php:948-954`, so `core/ajax` cannot reach it (`external_api.php:107,147,211`).

Net effect: an author who opens an existing notice and clicks Preview without re-touching the picker sees no background — the common case, not the exception.

---

## 6. REQUIRED — The invalid combinations need a real enforcement mechanism, and "no header bar" must not be `display:none`

The proposal names the conflicts and leaves the mechanism unspecified ("the form should constrain them").

**Acknowledge vs compact templates.** `templates/modal_notice.mustache:59-86` is one unconditional footer: `d-flex justify-content-between align-items-center px-4 pb-4` holding the `.form-check` ack row plus three buttons. `setInsistence()` (`modal_notice.js:129-152`) only toggles `d-none` on that fixed set — it never reflows. `styles.css:13-17` sets no `flex-wrap` on `.modal-footer`, and `d-flex` defaults to `nowrap`. So banner/card/minimal + Acknowledge either overflows or (if clipped) silently hides the checkbox — an insistence level the plugin documents as the strongest, rendered unenforceable without the author knowing.

**Position vs fullscreen/banner.** `fullscreen` has no meaningful position; `banner` already bakes one in, duplicating 2 of position's 7 values. The form's only cross-field idiom is `hideIf` on a whole element (`notice_form.php:169,174`), and `amd/src/notice_form.js:45-84` disables whole fields — neither prunes a select's own option list. That is genuinely new work.

**The close button is inside the header.** `modal_notice.mustache:46-53` — `#awareness-closebtn` is a descendant of `.modal-header`, and it is the only close control outside the footer. A naive `.la-tpl-minimal .modal-header { display:none }` deletes the very floating icon the template promises. Worse for a11y: `aria-labelledby="{{uniqid}}-modal-title"` points at the `<h5>` inside that same block, so removing the header from the DOM strips the dialogue's accessible name (WCAG 4.1.2), and `tests/local/modal_contract_test.php:139-152` would not catch it — it regex-scans the static template source, not a live variant.

---

## 7. REQUIRED — Reduced motion is inherited from nowhere, and the one existing animation already ships unguarded

Bootstrap's modal transition *and* its `prefers-reduced-motion: reduce → transition:none` collapse are scoped to `.modal.fade &` (`_modal.scss:61-68`, reaching `mixins/_transition.scss`). This plugin's root omits `fade`, so none of it applies.

`styles.css:82` `@keyframes awareness-jelly` and `:96-98` `.awareness.jelly-anim { animation: awareness-jelly 0.4s ease-in-out; }` have **no** guard — while the only `prefers-reduced-motion` block in the whole file (`:950-957`) guards `.la-spinner` and `.la-btn`, unrelated surfaces. `grep -rn 'matchMedia|reduced-motion' amd/src/*.js` → zero. And `grep -i 'reduced-motion|animation|prefers' tests/local/bootstrap_compat_test.php` → zero.

Core's own pattern is a dual guard: `theme/boost/scss/moodle/core.scss:42-51`, the `optional-animation` mixin disables under both `prefers-reduced-motion: reduce` **and** `body.behat-site`. The plugin ships plain CSS and cannot `@include` it, so both rules are hand-written. The `behat-site` half matters: WebDriver computes a click target from the bounding rect at click time, and `insistence.feature` clicks notice buttons immediately after they appear.

---

## 8. REQUIRED — `has-bg-image`'s hardcoded white scrims are the pattern hero/split would extend, and they are already wrong in dark mode

`styles.css:58-74`: `.awareness .modal-content.has-bg-image .modal-header/.modal-body/.modal-footer` set `rgba(255,255,255,0.85 / 0.80 / 0.90)` with `backdrop-filter: blur(…)` — no `var()` anywhere in those three declarations, unlike `styles.css:317-327` (`--la-brand: var(--bs-primary, var(--primary, #0f6cbf))`), the chain the rest of the file follows.

`grep -rc data-bs-theme moodle-405/theme/boost/scss` → 0; the same grep on 5.2 hits `_root.scss`, `_variables-dark.scss`, `_navbar.scss` and the colour-mode mixin. And nothing overrides text colour under `.has-bg-image` (grepped: only the four selectors exist), so on a 5.x site in dark mode Bootstrap's own `--bs-body-color` goes light while the scrim stays white — light-on-near-white. That is a live defect, not a future one.

`hero` ("reuses the existing background image") and `split` also want a *different* legibility contract: `setBackgroundImage()` (`modal_notice.js:158-172`) is one template-unaware code path (`background-size: cover` on `.modal-content`), designed so an uncontrolled photo reads as a faint watermark. One untyped upload cannot serve a blurred full-bleed backdrop, a sharp wide band with the title over it, and a tall narrow side panel. `lang/en/local_awareness.php:188` promises only the first.

---

## 9. REQUIRED — Data model, upgrade arithmetic and the two web-service places

**Columns, not `filtervalues`.** The audience-hash argument for this was refuted (see below), but columns remain correct: `helper::sanitise_data()` strips any key not in `properties_definition()`, the report-builder entity reads columns directly (`classes/reportbuilder/local/entities/notice.php`), and `modal_width`/`modal_height` (`db/install.xml:27-28`) are the exact precedent.

**Shape.** `PARAM_ALPHAEXT` is safe — `clean_param_value_alphaext()` is `preg_replace('/[^a-zA-Z_-]/i','',…)`, which keeps hyphens and strips digits; no proposed value contains a digit. But `PARAM_ALPHAEXT` validates the character set, not membership: `centre` or `boter` would store happily. `core\persistent` supports an exact enum — `lib/classes/persistent.php:742`, `if (isset($definition['choices']) && !in_array($value, $definition['choices']))`, documented at `:242` and used by core's own `competency.php:77,94`. `awareness.php` currently defines no `validate_*` method, so `choices` is the only server-side gate.

**Version arithmetic.** `version.php:29` is `2026082404` but the last savepoint in `db/upgrade.php` is `2026082402` — a legitimate two-step gap (2026082403 and 2026082404 were DB-free bumps, per `CHANGELOG.md`). A step written as `if ($oldversion < 2026082403)` would therefore **never fire** on any site running the shipped release. The new savepoint must exceed `2026082404`.

**Two web-service places plus one test.** `classes/external/get_notices.php:105` is the payload allowlist loop; `:178-187` is `execute_returns()`, and `clean_returnvalue()` silently strips anything undeclared. `tests/external/notice_external_test.php:802-810` pins the exact sorted key set and will fail by construction — that is the trip-wire working. Its `clean_returnvalue()` round-trip below (`:822-838`) is the half that catches a payload change made *without* a returns change; extend both, do not just relax `$expected`.

---

## 10. ADVISORY — A correction to one lens's recommendation: adding the three fields to `set_optional_section_state()` verbatim would pin the section permanently open

I read the body (`classes/form/notice_form.php:528-552`). The test is `if (is_array($value) ? !empty($value) : trim((string) $value) !== '')`. Since `template`/`position`/`animation` are NOT NULL with defaults `classic`/`center`/`none`, a stored value is *never* empty — so passing them to `set_optional_section_state('header_appearance', […])` makes "Modal appearance" expand on every edit of every notice, defeating the collapse design the helper exists for.

The helper needs a default-aware comparison (a field → default map, treating `value === default` as unused), or the three fields stay out of the call and a separate default-aware check is added.

---

## 11. ADVISORY / DECISION — Card and banner are full modals for assistive technology regardless of insistence; that is already true today

`accessibilityShow()` calls `Aria.hideSiblings()` unconditionally (`modal.js:1021-1029`, byte-identical on 4.5 at `:980-988`), and `FocusLock.trapFocus(this.root[0])` is unconditional in `attachToDOM()` (`:338` / `:306`). `modal_notice.js` overrides neither, and `notice.js:106,118` calls `show()` for every insistence level. So an Informational notice *already* renders the rest of the page inert to AT and keyboard users.

This is inherited, not introduced — the refuter correctly downgraded it from blocker. But the new names invite the opposite expectation: a "corner card" that a sighted user reads as ignorable is, for a screen-reader user, a page-wide modal. The proposal's own text is consistent ("small/edge-anchored modals, not toasts"); the decision is whether to keep that honestly, restrict card/banner to Blocking/Acknowledge, or build a genuinely non-modal variant (skip `hideSiblings` and `trapFocus`, drop `aria-modal`, use `role="region"`). Do not discover this during implementation.

Related, from the same mechanism: the backdrop is a page-wide singleton (`static backdropPromise`, `modal.js:86,386-394`; `ModalBackdrop` appends its own wrapper to `document.body`, `modal_backdrop.js:41-45`) with a hardcoded `position:fixed; width:100vw; height:100vh` body on both branches. It is a **sibling** of the dialogue, so no `.awareness …` selector can reach it, and no `--la-*` custom property inherits into it. A corner card therefore sits behind a full dark scrim, and `modal_notice.js:218-238`'s click-outside handler treats ~95% of the viewport as "outside" — one stray click dismisses it, or jelly-shakes when blocking. If per-template backdrop styling is wanted, it must be a class toggled on `backdrop.getRoot()` in JS or a `body`-level selector — never a descendant rule, and never a second backdrop instance (that static field is shared with every other plugin's modals on the page).

---

## 12. ADVISORY — The stylelint gate: I measured it and it contradicts both the lens and the plugin's own comments

`css-gate F2` claimed `clamp()` and `minmax(max(…))` fail the gate; its refuter claimed the plugin's own `.stylelintrc.json` shadows Moodle's root ruleset. **I reproduced both and the refuter's mechanism is correct.**

`/Users/uaiblaine/dev/moodle-local_awareness/.stylelintrc.json` is `{"rules":{"selector-class-pattern":"^[a-z0-9_-]+$"}}` — no `extends`, no `csstree-validator`, no property/value rules. Moodle's `.grunt/tasks/stylelint.js` passes only `configOverrides`/`customSyntax`, never `configFile`, so stylelint's cosmiconfig search starts at the linted file's own path and stops at the first config found — the plugin's.

Measured with the vendored 5.2 binary, no `--config`:

- With the plugin's `.stylelintrc.json` beside the file: `max-width: clamp(240px, 30vw, 360px)` and `transition: transform 80ms ease-out` → **exit 0, zero problems**.
- Same file, config removed, run from the Moodle root: `2:16 ✖ Invalid value for "max-width" csstree/validator` and `3:27 ✖ Expected a minimum of 100 milliseconds time-min-milliseconds`.

Three other fleet plugins ship the identical file (`auth_loginsteps`, `block_dimensions`, `local_groupdist`).

**But `styles.css:533-537` records the opposite field experience in a code comment** — "Moodle's stylelint rejects the grid spelling that caps a column count: `minmax(max(19rem, 45%), 1fr)` fails csstree/validator outright" — and the fleet standard assumes the strict rules bind. I could not run `mdl ci` to settle which invocation the CI leg actually performs (excluded by the read-only constraint).

**Plan position:** write CSS that satisfies the *strict* ruleset regardless — no `clamp()`/`min()`/`max()` in lengths, no `container-type`, no `!important`, every duration ≥ 100 ms, `flex: 1 1 45%` + `min-width: <rem>` for column capping. Compliance costs nothing; being wrong costs a red leg. Settle it with one `mdl ci moodle-local_awareness --only grunt` before the CSS commit.

---

## Refuted — dropped from the plan's justification

**`data-form-ws F1` (columns are required because `filtervalues` keys poison the audience hash) — refuted.** I read `classes/audience/estimator.php:79-130`. `normalise()` is a strict allowlist: it builds `$out` from nine explicitly named lookups (`cohorts`, `filter_role`/`filter_role_context`, `reqcourse`, `filter_category`, `filter_course`, `filter_format`, `filter_theme`, `filter_competency_rules`/`filter_competency_requireall`, `pathmatch`) with no pass-through of unrecognised keys, and `notice_audience::criteria_for()` returns `estimator::normalise($raw)`, not the raw merge. `hash()` therefore sees the same value with or without extra keys, and no notice would go stale. `tests/audience_estimator_test.php:52-66` confirms the drop behaviour. **The recommendation (real columns) still stands** on the grounds in finding 9 — it is a design-consistency and tooling argument, not a correctness necessity.

**`css-gate F2` — refuted**, see finding 12. Recorded as advisory with a pre-flight check rather than dropped, because the plugin's own comments disagree with the measurement and the constraint is free to honour.

**`a11y F1` — severity corrected** from blocker to required/decision, see finding 11: the behaviour is pre-existing, not introduced by this proposal.


---

# Implementation plan

Ordered as commits. Each is independently green: run `mdl ci moodle-local_awareness --matrix` before pushing, and the single-leg default is **not** the pipeline — a 4.05 static-checks failure is invisible to it (this plugin has been bitten by exactly that).

Suggested v1 scope (see decisions): **classic, hero, fullscreen, card**; positions **center, top, bottom** everywhere plus the four corners for `card` only; all five animations. `split`, `minimal` and `banner` deferred.

---

## Commit 1 — Data model, persistent, upgrade

**`db/install.xml`** — three fields after `outsideclick`, before `audiencecount`:

```xml
<FIELD NAME="template" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="classic" SEQUENCE="false" COMMENT="Dialogue layout; see awareness::TEMPLATES."/>
<FIELD NAME="position" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="center" SEQUENCE="false" COMMENT="Where the dialogue sits; logical start/end flip under RTL."/>
<FIELD NAME="animation" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="none" SEQUENCE="false" COMMENT="Entrance animation; collapses to none under prefers-reduced-motion."/>
```

Bump the file's `VERSION` attribute (currently `20260823`, 8-digit) to `20260903`, keeping the file's own existing convention rather than reformatting it in this commit. Validate: `xmllint --noout --schema /Users/uaiblaine/dev/moodle-502/public/lib/xmldb/xmldb.xsd db/install.xml`.

**`classes/persistent/awareness.php`** — three properties beside `modal_width`/`modal_height` (`:148-157`), each with a `choices` enum. Add three `public const` vocabulary arrays so form, WS and tests read one source:

```php
'template' => [
    'type' => PARAM_ALPHAEXT,
    'null' => NULL_NOT_ALLOWED,
    'default' => 'classic',
    'choices' => self::TEMPLATES,
],
```
…and the same shape for `position` (default `center`) and `animation` (default `none`). `choices` is the only server-side gate — `PARAM_ALPHAEXT` validates the character class, not membership.

**`db/upgrade.php`** — one new block. The savepoint must exceed `version.php`'s current `2026082404`, **not** the last savepoint `2026082402`:

```php
if ($oldversion < 2026090301) {
    // Three add_field() calls, each guarded by field_exists().
    upgrade_plugin_savepoint(true, 2026090301, 'local', 'awareness');
}
```

**`version.php`** → `2026090301`. Use the `moodle-dev:moodle-bump-version` skill so the number is derived from `version.php`, not from the upgrade file. **`CHANGELOG.md`** entry in the same commit.

**Tests:** extend `tests/persistent/` (or wherever the persistent is covered) with one test per field asserting an out-of-vocabulary value fails `validate()`. Mutation-check by deleting one `choices` key — exactly one test must redden.

---

## Commit 2 — Form

**`classes/form/notice_form.php`**, inside the existing `header_appearance` section (after `:445`):

- Three `select` elements — `template`, `position`, `animation` — each with `addHelpButton`. Do **not** add them to `$foreignfields` (`:46-50`); they are real persistent properties and `filter_data_for_persistent()` would strip them.
- Label the field **"Layout"**, not "Template" — `template` is fine as the column name (this codebase already splits column names from labels: `reqack`/`outsideclick` vs "Insistence"), but "Template" reads as a reusable-notice feature the plugin does not have.
- `$mform->hideIf('position', 'template', 'eq', 'fullscreen');` — same idiom as the existing `hideIf('timestart', 'perpetual', 'eq', 1)` at `:169`.
- `validation()` rules for the combinations `hideIf` cannot express, with reasons rather than "invalid": template ∈ {banner, card, minimal} **and** insistence = ACKNOWLEDGE; corner positions with a non-card template. Server-side, because a client `addRule()` never posts the form.
- Consider `validate_template()` on the persistent (reading siblings via `raw_get()`) if the combinations must be unrepresentable through every write path, not just the form. Currently no `validate_*` method exists on this class.

**`set_optional_section_state()` (`:528-552`) needs a default-aware mode before the three fields can be passed to it.** Its test is `trim((string) $value) !== ''`, and these columns are never empty — passing them verbatim pins "Modal appearance" open on every edit of every notice. Add an optional `array $defaults` parameter treating `value === $defaults[$field]` as unused, then call:

```php
$this->set_optional_section_state('header_appearance',
    ['modal_width', 'modal_height', 'template', 'position', 'animation'],
    ['template' => 'classic', 'position' => 'center', 'animation' => 'none']);
```

**Tests:** extend `tests/behat/editor_state.feature` — a notice saved with `template = hero` opens with the section expanded; a default notice opens collapsed. That second scenario is the control, and without it the first passes vacuously.

---

## Commit 3 — Web service

**`classes/external/get_notices.php`** — two coordinated edits:

- `:105`, extend the allowlist loop to `['id', 'title', 'modal_width', 'modal_height', 'template', 'position', 'animation']`.
- `:178-187`, three `new external_value(PARAM_ALPHAEXT, '…')` entries in `execute_returns()`. Without this, `clean_returnvalue()` strips the values silently and the payload change ships nothing.

No `db/services.php` change, so no version bump is strictly owed here — but Commit 5 rebuilds AMD and bumps anyway.

**Tests — `tests/external/notice_external_test.php`:** update **both** halves of `test_get_notices_payload_is_limited_to_what_the_modal_reads()`. The `$expected` array at `:802-810` gains the three keys (sorted: `animation`, `bgimageurl`, `content`, `id`, `insistence`, `modal_height`, `modal_width`, `position`, `template`, `title`), and the `clean_returnvalue()` round-trip at `:822-838` must assert the three new keys survive. Relaxing `$expected` alone would pass while the returns declaration was still wrong.

---

## Commit 4 — CSS and the media region

**`templates/modal_notice.mustache`** — add one `<div class="la-media d-none" data-region="media"></div>` between header and body, hidden by default and revealed by CSS for `hero`/`split` only. Update the `Example context (json):` docblock. Keep the `<h5 id="{{uniqid}}-modal-title" …>` node present in every variant — `aria-labelledby` points at it.

**`styles.css`** — a new delimited block, all selectors compound with `.awareness`, all class names plugin-owned (`la-tpl-*`, `la-pos-*`, `la-anim-*`), **all ungated**:

- **Positions**: `position: fixed` + `inset-inline-start`/`inset-inline-end`/`inset-block-*` on `.awareness.la-pos-*`, with `margin: 0`. Do *not* touch the outer `.modal` — core centres via `.modal-dialog-centered` (`_modal.scss:88-91`) and never changes `.modal`'s own `display`. Logical properties are safe: both branches ship `[dir=rtl]` rules and real `inset-inline`/`margin-inline-start` usage in compiled CSS.
- **Fullscreen**: the plugin's own geometry — `width:100vw; max-width:none; height:100%; margin:0` plus `max-height`/`overflow-y:auto` on the body. Never the class name `modal-fullscreen`.
- **Sizing**: a `.awareness.la-tpl-card`/`la-tpl-minimal` `max-width` written with a fixed value or a `min-width`/`max-width` pair — **no `clamp()`/`min()`/`max()` in a length**, no `container-type`, no grid `minmax(max(…))`. Column capping, if needed: `flex: 1 1 45%` with a `min-width: 19rem` floor.
- **Animations**: five `@keyframes`, every declared duration ≥ 100 ms, each paired with **both** guards, mirroring `theme/boost/scss/moodle/core.scss:42-51`:
  ```css
  @media (prefers-reduced-motion: reduce) { .awareness[class*="la-anim-"] { animation: none; } }
  body.behat-site .awareness[class*="la-anim-"] { animation: none; }
  ```
  Retrofit the same two guards onto `.awareness.jelly-anim` (`styles.css:96-98`) in this commit — reviewers will compare them side by side.
- **Tokens**: every colour through `var(--bs-x, var(--x, literal))`, the chain already at `styles.css:317-327`. Declare any new custom properties on `.awareness` itself (it *is* the `.modal-dialog`, so inheritance reaches header/body/footer/media wherever core relocates it) — plus on the editor/preview roots if the preview paints them.
- **Fix `has-bg-image` in the same commit** (`styles.css:58-74`): replace the three hardcoded `rgba(255,255,255,…)` scrims with a token-based chain plus an explicit dark-mode block. It is a live dark-mode defect and it is the exact pattern `hero` would extend.
- **Hero scrim**: a fixed gradient under the title, independent of image content — `text-shadow` cannot bound worst-case contrast against an admin-uploaded photo (WCAG 1.4.3).
- **Minimal/banner header**: never `display:none` on `.modal-header` — `#awareness-closebtn` is its descendant (`modal_notice.mustache:46-53`). Strip the chrome (background, border, padding) and reposition the button, or use `visibility:hidden` with an explicit `visibility:visible` on the button.

**Pre-flight:** `mdl ci moodle-local_awareness --only grunt` before pushing this commit, to settle which stylelint ruleset actually governs (see finding 12).

---

## Commit 5 — JavaScript

**`amd/src/modal_notice.js`** — three setters mirroring `setInsistence`/`setModalSize`:

```
setTemplate(name)   // swap la-tpl-* on getModal(); also add/remove modal-lg per template
setPosition(name)   // swap la-pos-* on getModal()
setAnimation(name)  // remove la-anim-*, void dialog[0].offsetWidth, add la-anim-<name>
```

`setTemplate()` **must** manage `modal-lg` explicitly for every notice including the first — it is hardcoded at `modal_notice.mustache:44` and `configure({large:true})` is a no-op against it.

**`amd/src/notice.js`** — call the three setters in **both** branches of `nextNotice()` (`:73-109` create, `:110-120` reuse). In the reuse branch, add a shape-change guard:

```js
// A template or position change reshapes and relocates a dialogue that is still on screen.
// Hide first so the entrance animation has something to animate into.
if (newtemplate !== currenttemplate || newposition !== currentposition) {
    modal.hide();
}
// …apply setters…
modal.show();
```

Track the previous values on the instance. Do not hang the animation off `show()`'s promise or `ModalEvents.shown` — neither re-fires (`modal.js:899-902`).

Rebuild with `mdl grunt m502 local/awareness`, commit `amd/build/**` (`.min.js` + `.map`) **in this same commit**, and bump `version.php` so the cache revision changes.

**Tests:** the `bootstrap_compat_test.php` scan covers `amd/src/` too, so any Bootstrap name written into the setters is caught once Commit 7 widens the detector.

---

## Commit 6 — Preview parity

**New web service** `classes/external/get_draft_bgimage_url.php` — takes a `draftitemid`, follows the full checklist (`validate_parameters`, `require_login` + guest rejection, `validate_context` derived server-side, `require_capability` to manage notices), and **verifies the draft area belongs to `$USER`** before resolving via `context_user::instance($USER->id)` + `moodle_url::make_draftfile_url()`. Register in `db/services.php` with `'ajax' => true` — **that change requires a `version.php` bump**, services install only on upgrade. Return type `PARAM_URL`, never `PARAM_RAW`.

**`amd/src/editor_preview.js`** — replace `ModalCancel.create()` (`:100-108`) with `ModalNotice.create()`, then:

- read the live form values (`#id_template`, `#id_position`, `#id_animation`, `#id_insistence`, `#id_modal_width`, `#id_modal_height`) and call the matching setters;
- fetch the draft image URL from the new WS at click time (so it reflects same-session adds and removals) and call `setBackgroundImage()`;
- **bind its own exit handlers** — `data-action="close"` (all three buttons), `data-action="accept"`, and the ack checkbox — each calling `modal.destroy()`, never the `local_awareness_dismiss` / `local_awareness_acknowledge` services. `removeOnClose` is inert on `ModalNotice`, so `destroy()` must be explicit or every Preview click leaks a DOM node and a `$(document)` keydown closure.

**`amd/src/preview.js`** + **`classes/table/all_notices.php:461`** — same switch for the manage list. `col_actions()` emits `data-template`, `data-position`, `data-animation`, `data-insistence` and a resolved `data-bgimageurl` (`helper::get_bgimage_url($id)` works here — the row is saved, no draft complication). Without this, the one place administrators browse live notices previews every layout as `classic`.

**Behat:** the `"dialogue"` named selector matches `ModalNotice`'s markup unchanged — `partial_named_selector.php` matches `div[@data-region='modal']` with a descendant `data-region='title'`, both already present at `modal_notice.mustache:44,48`. `tests/behat/preview_dialogue.feature` keeps passing.

---

## Commit 7 — Tests, detector, lang

**`tests/local/bootstrap_compat_test.php`** — three new entries in `bs5_only_utilities()` (`:65-92`), none currently matched:

```php
'/\brounded-[1-5]\b/' => 'rounded-*',
'/\bsticky-bottom\b/' => 'sticky-bottom',
'/\bmodal-fullscreen(-[a-z]{2}-down)?\b/' => 'modal-fullscreen*',
```

Mutation-check each: write the name into a scratch template and confirm the test reddens.

**New test — reduced-motion contract.** Extend the same file (or a sibling `motion_contract_test.php`): scan `styles.css` and assert every `animation:` declaration and every `@keyframes` name has a corresponding `prefers-reduced-motion` collapse **and** a `body.behat-site` guard. Mutation-check by deleting one guard. Prose has already failed in this exact file — `jelly-anim` shipped unguarded.

**`tests/local/modal_contract_test.php`** — extend `test_the_dialogue_element_carries_its_name_and_aria_modal()` (`:139-152`) to assert the `aria-labelledby` target survives for every template value, not just the default shape. Today it regex-scans static source only.

**Behat** — `tests/behat/insistence.feature` or a new `templates.feature`: a Blocking notice previewed from the editor refuses Escape and closes via "Not now" (no scenario exercises this today). `tests/behat/behat_local_awareness.php:49-79` needs **no** change — it is a raw `insert_record()`, so omitted columns take the SQL DEFAULT, and the three fields map 1:1 (`| template | hero |` works directly). `tests/generator/lib.php` needs no change either — it routes through the persistent. **Read every lang string before writing an assertion**, and keep a label and the value it introduces on one source line (`I should see` is a raw `contains()`, with no whitespace normalisation).

**Lang — `lang/en/` and `lang/pt_br/` in lockstep, alphabetical, no section comments.** Verified insertion points against the current file:

| key | English | slot |
|---|---|---|
| `notice:animation` | Entrance animation | after `notice:activefrom_help` (173), before `notice:audience` (174) |
| `notice:animation:fade` / `:none` / `:slide` / `:spring` / `:zoom` | — | same run |
| `notice:animation_help` | states the reduced-motion collapse explicitly | same run |
| `notice:position` | Position | after `notice:perpetual_help` (223), before `notice:preview` (224) |
| `notice:position:bottom` / `:bottomend` / `:bottomstart` / `:center` / `:top` / `:topend` / `:topstart` | — | same run (no hyphens in keys — nothing in this file uses one) |
| `notice:position_help` | states the RTL flip | same run |
| `notice:template` | **Layout** | after `notice:status:live` (232), before `notice:title` (233) |
| `notice:template:banner` / `:card` / `:classic` / `:fullscreen` / `:hero` / `:minimal` / `:split` | — | same run |
| `notice:template_help` | per-value paragraphs, mirroring `notice:insistence_help` (203) | same run |
| `notice:template_invalid_ack` / `notice:position_invalid` | validation messages naming the reason | same run |

Also update `editor:section:appearance:desc` (`:93`, currently "Size and visual fit of the modal window.") to mention layout, position and animation. And relabel `notice:bgimage` / `notice:bgimage_help` (`:187-188`) — the current text promises "cover the entire modal content area", which `hero` and `split` break.

Renderers turning a stored value into a label use a literal `switch`/`match` over fixed keys — **never** `get_string('notice:template:' . $value, …)`.

pt_br mirrors key-for-key: `Animação de entrada`, `Posição`, `Layout`.

---

## CI risks per branch

- **4.05** — the branch that fails alone. `moodle-cs` cannot see PHP attributes there, so keep class-level `@covers` docblocks while `supported` includes 405. Every BS5-only class name is dead here; every new CSS rule must be verified rendering on m405, not just m502.
- **5.02** — `validate` fatals over a *combined member modifier* (`private static`, `public static`) in the files it parses: `db/upgrade.php` and `lang/en/local_awareness.php` for a local plugin. The new vocabulary constants belong on the persistent (`classes/persistent/awareness.php`), which `validate` never parses. Reproduce with `mdl ci moodle-local_awareness --branch MOODLE_502_STABLE --only validate`.
- **All legs** — `phpcs`/`phpdoc`/`grunt` at `--max-warnings 0`; PHPUnit `--fail-on-warning`. `db/services.php` and `amd/build` changes both need the `version.php` bump, and a bump stales the PHPUnit and Behat test sites (`mdl phpunit-init`, `mdl behat-init`) — a stale Behat site exits 0 with zero scenarios run, so judge raw runs by scenario count, never exit status.
- **Report builder** (optional, low risk): three `column::TYPE_TEXT` columns plus `select` filters with `set_options_callback()` on `classes/reportbuilder/local/entities/notice.php`, copying the shape at `classes/reportbuilder/local/entities/acknowledgement.php:195-211`. No joins, no CASE expressions — these are literal stored values, unlike `insistence`.
- **Privacy**: no change. `classes/privacy/provider.php:170` reads `local_awareness` with an explicit `'id, title'` column list, and the table carries no `userid`.


---

# Decisions needed

- Scope of the template vocabulary. Seven layouts is far beyond every comparable precedent: tool_usertours offers 4 placements (configuration.php:85-105, with its own help steering authors to 2), theme_boost_union's info banner offers 2 (settings.php:5117-5236), and core's toast has 1 fixed position (lib/templates/local/toast/wrapper.mustache). RECOMMENDED DEFAULT: ship classic, hero, fullscreen and card in v1; defer split (needs a no-image accent-panel fallback that does not exist) and minimal (its floating close icon has no accessible-close precedent in this codebase and needs its own a11y pass); cut banner or hard-scope it to position in {top,bottom} and insistence = Informational only.
- Are card and banner honestly full modals? They aria-hide the whole page and trap focus at every insistence level (modal.js:1021-1029 and :338, identical on 4.5), so a screen-reader user experiences a 'corner card' as a page-wide blocking dialogue. This is already true today, but the new names invite the opposite expectation. RECOMMENDED DEFAULT: keep them real modals (matching the proposal's own text) and say so in notice:template_help; revisit a genuinely non-modal informational variant as separate work rather than discovering it mid-implementation.
- What happens when consecutive notices in one queue have different shapes? RECOMMENDED DEFAULT: hide the reused instance, swap the classes, then show again — only when template or position actually differ; keep today's live in-place update when they match, to avoid a flicker in the common same-shaped queue. The alternative (silently inheriting notice #1's position) makes a stored value lie about what will happen.
- Does a brand-new notice default to an animation, while existing rows keep 'none'? RECOMMENDED DEFAULT: yes — DB default stays 'none' so existing rows render byte-for-byte unchanged, and the form sets 'fade' for new records only. This is safe ONLY if editing an old notice keeps its stored value; pin that with a test mirroring the insistence pattern at notice_form.php:596-600, because any save of this plugin's notices expires acceptances, so a silent drift to 'fade' would be invisible and consequential.
- Site-wide defaults in settings.php for the three fields? RECOMMENDED DEFAULT: no. Every setting in settings.php today is delivery-wide or a numeric limit; bgimage, modal_width and modal_height established that appearance lives entirely on the notice record. Adding appearance defaults would be new architectural territory for a convenience nobody has asked for.
- Does the background image need a crop or focal-point control? One untyped upload cannot serve a blurred full-bleed watermark (today), a sharp wide hero band, and a tall narrow split panel. RECOMMENDED DEFAULT: defer cropping explicitly, relabel the field 'Image', and make its help text template-conditional so authors know which crop each layout wants — rather than shipping silently without it.
- Should the existing has-bg-image dark-mode defect (styles.css:58-74, three hardcoded white scrims with no dark-mode branch) be fixed inside this feature or as a separate commit? RECOMMENDED DEFAULT: inside it, in the CSS commit — hero extends this exact pattern, and leaving it is the one hardcoded exception every new rule has to explain around.

# Constraints the mockups must respect

- No Bootstrap 5 utility class may appear anywhere in the mockup's markup or its annotations. Not modal-fullscreen, rounded-1..5, sticky-bottom, top-0/start-0/end-0/bottom-0, gap-*, text-bg-*, object-fit-*, ratio*, d-grid, visually-hidden, fw-*, fs-*, lh-*. All are dead on Moodle 4.5 and the plugin's polyfill gate cannot reach this modal. Use plugin-owned names (la-tpl-*, la-pos-*, la-anim-*) and hand-authored CSS.
- The close button (#awareness-closebtn) must stay inside .modal-header in every layout, and the <h5 id="…-modal-title"> must stay in the DOM — aria-labelledby points at it. 'No header bar' is chrome removal (background, border, padding) or visibility:hidden with an explicit visibility:visible on the button, never display:none on .modal-header.
- The footer is one shared markup: an ack checkbox row plus three buttons in a d-flex justify-content-between with no flex-wrap. Any mockup showing a banner, card or minimal layout must either show the Acknowledge state fitting honestly, or show that combination as unavailable — do not draw a checkbox squeezed into a one-line strip.
- Every layout keeps a full-viewport dark backdrop. The backdrop is a page-wide singleton attached to document.body as a sibling of the dialogue, hardcoded to 100vw x 100vh on both branches. A mockup showing a corner card floating over an undimmed page is not what will ship unless a separate backdrop-retargeting decision is made.
- Show every position variant in both LTR and RTL. Start/end are logical and flip; a mockup that only shows top-start as top-left understates half the vocabulary.
- Show the fullscreen layout with the position control absent, not greyed with a stale value. Position is hidden entirely when template = fullscreen.
- Show the queue transition between two notices of different shape — the second notice arriving after the first is hidden, not teleporting in place. This is the mechanic reviewers most need to see, and the prose ('classes swapped at nextNotice() time') reads as the wrong one.
- Include the reduced-motion state for every animation: none and fade only. Also show the has-bg-image / hero treatment in dark mode, since the current scrim is hardcoded white and this is where the mockup should show the token-based replacement.
- Label the field 'Layout', not 'Template'. 'Template' reads as a reusable-notice feature this plugin does not have.
- The Preview button must render the real dialogue with the layout, position and animation as shipped, with working close and accept buttons that record nothing. A mockup implying today's plain preview dialogue understates the work and misses the point of the feature.

---

# Part 2 — video field and carousel layout

Requested after Part 1 was drawn: a dedicated video field rendered through the site's multimedia
filter, and a photo/video carousel in the style of Untitled UI's centered carousels, with slides
captured from `<ul><li>` items in the content. Assessed the same day by four read-only lenses
(video rendering inside a WS-delivered modal body, the video field, the slide data capture, the
carousel widget), one refuter per blocker/required finding, and a consolidation pass.

**Verdicts: video feasible with changes; carousel feasible with changes.**

## Video field + video layout — feasible with changes

The single blocker raised against it is **refuted by measurement**. The claim was that server-rendered `filter_mediaplugin` output shipped through `get_notices` arrives inert because `media_videojs`'s player JS is queued onto `$PAGE->requires` and the AJAX response never flushes it. True as far as it goes — but the JS does not have to come from the response. `media_videojs/loader` is already in the JS requirements of **every rendered page on all three stacks**, its `setUp()` registers a document-level `core_filters/contentUpdated` listener, and `core/modal.setBody(string)` fires exactly that event on both 4.5 and 5.2. Server-rendered videojs markup injected into the modal body is picked up and initialised. Video is a normal feature, not an architecture problem.

What must change: a new `video` filearea in `lib.php` and in `delete_notice()`'s cleanup loop; a URL wrapped in a real `<a href>` server-side (a bare URL embeds nothing); media stopped when the queue empties; and Preview rebuilt as a server round trip, because it renders the editor's raw HTML client-side today and PHP is where the media filter runs.

## Carousel — feasible with changes

The widget is fine: plugin-owned AMD, plugin-owned classes. Bootstrap's own carousel is genuinely unusable (`.carousel-item-left` 4.5 only, `.carousel-item-start` 5.2 only — measured), and so are `object-fit-cover`, `ratio-*` and `d-grid`, which a photo/video carousel reaches for first.

The weak part is the **data capture**. `<ul><li>` parsing works technically but has no author-facing schema, no save-time validation, and silently reinterprets every pre-existing bullet list the moment a notice is switched to the carousel layout. Recommend a structured slides model instead — core has a direct precedent.

Two owner decisions below are genuinely product calls, not engineering ones.

---

## Findings

Ordered most severe first. Severity is my consolidated judgement after re-opening the files; where I overrode a lens or a refuter, I say so.

---

### F1 — REFUTED: server-rendered video through `get_notices` does **not** lose its player JS (was: blocker)

The `video-field` lens called this a blocker and its refuter confirmed the code paths — correctly, as far as they went. Both stopped one step short: they never asked whether the loader was already on the page. It is.

- **Measured**, `media_videojs/loader` present in the JS requirements of a bare page on every supported branch:
  ```
  for p in 8405 8501 8502; do curl -sL "http://localhost:$p/" | grep -c 'media_videojs/loader'; done
  → 1 / 1 / 1
  ```
  (A first pass read `0` for 8502 `/` and `/course/index.php`; those are **303 redirects** — `curl -o /dev/null -w "%{http_code}"` → `303 1501`. With `-L` they are `1`. The zero was the redirect, not a missing loader.)
- `/Users/uaiblaine/dev/moodle-502/public/media/player/videojs/amd/src/loader.js:52-63` — `setUp()` ends with `document.addEventListener(eventTypes.filterContentUpdated, notifyVideoJS);` and `notifyVideoJS` reads `e.detail.nodes`, finds `.mediaplugin_videojs`, reads the **server-rendered** `data-setup-lazy` config, lazily imports `media_videojs/video-lazy` (+ `Youtube-lazy`) and calls `videojs(id, config)`.
- `/Users/uaiblaine/dev/moodle-502/public/filter/amd/src/events.js:73-78` — `notifyFilterContentUpdated = nodes => … dispatchEvent(eventTypes.filterContentUpdated, {nodes})`. The payload shape matches what the listener reads.
- `core/modal.setBody()` fires it on **both** ends of the supported range: `/Users/uaiblaine/dev/moodle-405/lib/amd/src/modal.js:490-493` — `body.html(value); FilterEvents.notifyFilterContentUpdated(body);` — and the same block at `/Users/uaiblaine/dev/moodle-502/public/lib/amd/src/modal.js:517-525`.

**Consequence:** ship the rendered HTML. Do **not** build the `$PAGE->start_collecting_javascript_requirements()` / `Fragment.processCollectedJavascript` machinery the lens proposed as fallback (b), and do not fall back to a hand-built `<video controls>` (option a) — that would discard the site's configured player, poster, tracks and dimensions for no gain.

**Residual (advisory):** the loader is delivered by the *host page*, not by the notice, so this is a platform behaviour the plugin depends on and does not control. I measured 3 page types × 3 stacks and never saw it absent, but did not prove it exhaustively. Pin it with a Behat scenario asserting the player actually initialised (video.js stamps `vjs-*` classes on the element), not merely that the markup arrived.

---

### F2 — required: `\core\form\persistent::validation()` is `final` — the hook is `extra_validation()`

The `video-field` lens recommended "add a validation() override (there is none to extend)". `notice_form` extends `\core\form\persistent` (`classes/form/notice_form.php:34`), and:

- `/Users/uaiblaine/dev/moodle-502/public/lib/classes/form/persistent.php:304` — `final public function validation($data, $files) {` … which itself runs the persistent's own `get_errors()` and then calls `$this->extra_validation($data, $files, $errors)` at `:315`.
- The declared extension point is `:196` — `protected function extra_validation($data, $files, array &$errors)`.

Declaring `validation()` in `notice_form` is a **PHP fatal**, not a lint finding. Every new server-side rule (video URL, slide completeness) goes in `extra_validation()`.

Related, verified while there: the form's `protected static $foreignfields` (`notice_form.php:50-53`) is the existing, documented mechanism for form fields that are not persistent properties — `bgimage` is already in it. Every new non-column field (`layout` if it stays out of the table, `video_upload`, `slide_*`) must be added there.

---

### F3 — required: `--la-*` tokens are **not** declared on the notice dialogue

No lens caught this; I found it opening `styles.css`. It is the exact defect class the fleet standards record as already having shipped in this plugin once.

- `styles.css:316-330` declares the token block on `.local-awareness-editor` only (`--la-brand`, `--la-paper`, `--la-surface`, `--la-line`, `--la-ink`, `--la-radius`, …), with a comment at `:310-315` explaining precisely why the preview root had to be added beside it: *"core attaches a modal to its own element on document.body — a sibling of the editor, not a descendant… every `var(--la-*)` inside the dialogue would resolve to nothing, which does not fall back to the literal."*
- `.awareness` — the class on the real dialogue's `.modal-dialog` (`templates/modal_notice.mustache:43`) — is **not** in that list. `grep -n "var(--la-" styles.css | head -1` → line 312 (the comment); the `.awareness` rules at `styles.css:2-100` use only `var(--bs-*, …)` chains, so nothing is broken **today**.

The moment a `la-carousel-*` / `la-tpl-video` rule inside the dialogue uses `var(--la-surface)`, the whole declaration is invalid at computed-value time — no background, not the default background. **Add `.awareness` to the token-declaring selector list in the same commit as the first `var(--la-*)` inside the modal**, and check the `box-sizing` and `:focus-visible` blocks for the same scoping gap.

---

### F4 — required: a repeated `filepicker` cannot be read with `file_get_submitted_draft_itemid()`

Decisive for the carousel data model, and it settles the `carousel-data` F4/F5 recommendation (repeat_elements) as workable — but only if built the way core builds it.

- `/Users/uaiblaine/dev/moodle-502/public/lib/filelib.php:838-860` — for an array request value the function looks for `$param['itemid']` and otherwise:
  ```php
  debugging('Missing itemid, maybe caused by unset maxfiles option', DEBUG_DEVELOPER);
  return false;
  ```
  A repeated `filepicker` submits `slide_media[0] = <draftid>`, i.e. `$_REQUEST['slide_media'] = [0 => id]` with no `itemid` key. So it returns `false` **and** emits a developer debugging message — which on 5.1+ Whoops converts into an uncaught `ErrorException` that terminates the request.
- Core's precedent does not use it. `/Users/uaiblaine/dev/moodle-502/public/question/type/ddimageortext/questiontype.php:99-119` reads the array directly:
  ```php
  $info = file_get_draft_area_info($formdata->dragitem[$dragno]);
  … $draftitemid = $formdata->dragitem[$dragno];
  … file_save_draft_area_files($draftitemid, $formdata->context->id, …);
  ```
  with the filepicker declared inside `repeat_elements()` at `edit_ddtoimage_form_base.php:112`.

So: read `$data->slide_media[$i]` from the submitted data; never call `file_get_submitted_draft_itemid('slide_media[0]')`.

---

### F5 — required: Preview cannot show a video or a carousel, because it never reaches the server

- `amd/src/editor_preview.js:97-107` — `const body = getContent().trim() !== '' ? getContent() : await getString(...)`, handed straight to `ModalCancel.create({title, body, …})`. That is the editor's **raw HTML**, client-side.
- `filter_mediaplugin` runs in PHP, inside `helper::render_content_parts()` (`classes/helper.php:1702-1716`).

Today that is merely imprecise (images survive because TinyMCE inserts `draftfile.php` URLs). With video and slides it becomes actively misleading: the author previews a bare `<a>` where the reader gets a player, and a raw `<ul>` where the reader gets a carousel. **Preview must become a web-service round trip** returning `render_content_parts()` output plus the slides array — which is the same change already on the plan for the draft background-image URL, so do them as one commit. Note this makes a `db/services.php` change, hence a `version.php` bump.

---

### F6 — required: three one-line allowlists must gain the new filearea, and one is a silent file leak

Both cited by the `video-render` lens; I confirmed all three.

- `lib.php:48` — `if (!in_array($filearea, ['content', 'bgimage'])) { return false; }` — an uploaded video 404s before the audience gate is even reached. Add `'video'` (and `'slidemedia'` if slides get their own area). It then inherits the deliberately **partial** audience gate documented at `lib.php:61-73`; that is correct and should not be "fixed".
- `classes/helper.php:449-451` — `foreach (['content', 'bgimage'] as $filearea) { $fs->delete_area_files(…, $oldid); }`, whose own comment at `:443-448` says a file area left off this list is *"unreachable and undeletable at the same time"*. Omitting the video area reproduces exactly that.
- `send_stored_file($file, null, 0, $forcedownload, $options)` at `lib.php:95` is already right for video: `/Users/uaiblaine/dev/moodle-502/public/lib/filelib.php:2217-2288` emits `Accept-Ranges: bytes` and serves `HTTP_RANGE` requests, so seeking works. **ok** — no change.

---

### F7 — required: the carousel must re-initialise on the `setBody` path, and that path is already exercised in CI

The `carousel-ui` lens asserted the multi-notice path is reachable; I verified the server half, which makes it certain rather than likely.

- `classes/helper.php:658-660` — `if (!empty($firstrepeating)) { // The one case where more than one notice is handed over at a time. $selected = $firstrepeating; }`
- `tests/behat/display_queue.feature:44-56` — the scenario *"Two repeating notices meeting the user for the first time arrive together"* dismisses the first and asserts the second appears **without navigating**, i.e. it goes through `notice.js:110-120`, the reused-modal `else` branch.

So a carousel bound only inside the `ModalNotice.create().then()` callback is dead on the second notice, in a scenario CI runs today. Bind delegated off `modal.getModal()` (the pattern already used at `notice.js:88-104`), read the notice id live from the DOM (`modal_notice.js:111-113` already does exactly that via `data-noticeid`), and reset slide index + stop media inside the `setBody` branch.

---

### F8 — required: media keeps playing when the queue empties, and the fix has a designated home

- `amd/src/notice.js:62-68` — the only teardown is `modal.hide()`, with the comment *"This is the ONLY place the modal is hidden"*.
- `core/modal.hide()` toggles classes, backdrop and ARIA; it never touches `<video>`/`<audio>`/`<iframe>` or clears the body. Verified on 4.5 and 5.2.
- The mid-queue transition is safe: `setBody`'s `body.html(value)` detaches the old nodes and the browser pauses removed media.

`modal_notice.js:196-217` already overrides `registerEventListeners` **as a prototype method**, with a docblock explaining the constructor-ordering reason. Put the teardown next to it as `ModalNotice.prototype.hide`, calling a `stopMedia(this.getBody())` helper (pause every `video, audio`; blank and restore each `iframe` `src`) then `Modal.prototype.hide.call(this)`. Reuse the same helper from the `setBody` branch, and `dispose()` any video.js player first so the `videojs` registry does not retain orphaned players across a queue.

---

### F9 — required: Bootstrap's carousel is unusable, and so are the utilities a media carousel reaches for first

I re-measured the compiled Boost sheets myself rather than trusting the lens; the utility results correct the fleet's own list for these stacks, in both directions.

```
grep -oF ".<class>" on http://localhost:{8405,8502}/theme/styles.php/boost/1/all
carousel-item-left     4.5=9   5.2=0
carousel-item-start    4.5=0   5.2=5
object-fit-cover       4.5=0   5.2=1
ratio-16x9             4.5=0   5.2=1
d-grid                 4.5=0   5.2=1
btn-close              4.5=39  5.2=38
gap-2                  4.5=2   5.2=3
fw-semibold            4.5=1   5.2=2
visually-hidden        4.5=4   5.2=17
```

The transition classes are applied by Bootstrap's own JS at runtime, so they cannot be dual-written the way `data-toggle`/`data-bs-toggle` can — that settles it: plugin-owned `amd/src/carousel.js` and plugin-owned `la-carousel-*` classes. Separately, `object-fit-cover`, `ratio-*` and `d-grid` are **dead on 4.5**, and they are precisely what a photo/video carousel would use for the media well — so the aspect box, the cover fit and the layout are all plugin CSS. (`btn-close`, `gap-2` and `fw-semibold` do resolve on 4.5 here, contrary to the fleet note; `visually-hidden` appears but sparsely — measure before relying on any of them, and prefer owned classes inside the dialogue, which has no body class to gate a polyfill on.)

---

### F10 — required: new payload fields are `PARAM_RAW`, and one test is written to fail until you say so

`carousel-data` F3's mechanism is right (its refuter softened it to "required", which I agree with — it is a fixable typing choice, not an impossibility).

- `classes/external/get_notices.php:162-177` — the docblock already states the rule: *"a PARAM_TEXT field whose cleaned value differs from the original THROWS, killing the whole response for every reader rather than dropping one field."* `content` and `title` are `PARAM_RAW` at `:181-182`.
- `tests/external/notice_external_test.php:790-810` asserts an exact `$expected` key list. Any new key (`layout`, `videohtml`, `slides`) fails it until the array is updated — by design.

So: `slides[].html` and `slides[].caption` are `PARAM_RAW`; `videohtml` is `PARAM_RAW`; a URL that has been through `clean_param(..., PARAM_URL)` may be `PARAM_URL` like `bgimageurl` (`:186`).

---

### F11 — advisory (severity corrected down): `PARAM_URL` does not silently empty a scheme-less link

`video-field` F3 claimed `clean_param('youtu.be/x', PARAM_URL)` returns `''`. Its refuter measured otherwise on all three stacks — the value comes back **unchanged**, because `lib/classes/param.php` passes `s?` (scheme optional) to `validateUrlSyntax()`. I accept the refutation.

The real, smaller risk runs the other way: a scheme-less value is accepted and then wrapped into `<a href="youtu.be/x">`, which the browser resolves **relative to the current page**. Handle it in `extra_validation()` (F2): reject, or normalise by prepending `https://`. The lens's general observation — that `setType()` alone reports nothing to the author — remains true and is why the rule belongs there rather than in a `setType`.

---

### F12 — advisory: reject `<ul><li>` parsing as the slide capture

`carousel-data` F4/F5 argued this and I agree; `carousel-ui` F7's counter-point (a DOM parser is unit-testable and has an in-repo recipe) is true but answers a different question — feasibility, not correctness.

- The parse would have to run **after** `file_rewrite_pluginfile_urls()` + `format_text()` (`helper.php:1702-1716`), since only then do real URLs and the `.mediaplugin` wrappers exist — so it is new code in the render path, not a reuse of the save-time `update_hyperlinks()` DOM pass at `helper.php:268-291`.
- Nothing validates it at save time: `helper::process_content()` (`:57-71`) stores the editor HTML verbatim, and `get_file_editor_options()` imposes no schema. A two-image `<li>`, a nested list, or prose bullets mixed with slide bullets fail only in front of readers.
- It silently reinterprets every bullet list already authored, the moment a notice is switched to the carousel layout.
- If it is nonetheless chosen, classify each `<li>` by `li.querySelector('.mediaplugin')` — every bundled player emits that token, including `html5audio`, which a `<video>`/`<iframe>` tag check would miss.


---

## Plan additions

Additions to the existing layout/position/animation plan. Ordered as commits; each is independently green under `mdl ci <repo> --matrix`.

---

## C1 — Data model

**`db/install.xml`** — on `local_awareness`:

| field | type | notes |
|---|---|---|
| `layout` | char(20) NOTNULL DEFAULT `'standard'` | `standard` \| `hero` \| `video` \| `carousel` |
| `videourl` | char(1333) NULL | external link, or empty when the video is uploaded |
| `video` | int(1) NOTNULL DEFAULT 0 | has-uploaded-file flag, exactly mirroring the `bgimage` flag at `install.xml:26` |

New table **`local_awareness_slides`**: `id`, `noticeid` (FK + index), `sortorder` int, `mediatype` char(10) (`image`\|`video`\|`none`), `videourl` char(1333) NULL, `caption` text NULL, `captionformat` int DEFAULT 1, `timecreated`, `timemodified`. Every `<FIELD>` declares `SEQUENCE` explicitly. The slide **id is the filearea itemid** for its uploaded media — do not try to encode notice+index into one itemid.

**`classes/persistent/awareness.php`** — add `layout` (`PARAM_ALPHA`, default `'standard'`), `videourl` (`PARAM_RAW`, `NULL_ALLOWED`), `video` (`PARAM_INT`, default 0) to `define_properties()` (`:79-179`). New `classes/persistent/slide.php` for the slides table.

**`db/upgrade.php`** — one step per field/table, each closing with `upgrade_plugin_savepoint(true, <version>, 'local', 'awareness')`. Savepoint version == `version.php` == `install.xml` `VERSION`. Keep every member in this file single-modifier (`mdl ci <repo> --branch MOODLE_502_STABLE --only validate` fatals on a combined modifier).

Validate: `xmllint --noout --schema /Users/uaiblaine/dev/moodle-501/public/lib/xmldb/xmldb.xsd db/install.xml`.

---

## C2 — Form

**`classes/form/notice_form.php`**

- New section header `header_layout` (or extend `header_content`): `addElement('select', 'layout', …)` over the four values.
- Video: `addElement('url', 'videourl', …, ['usefilepicker' => false])` **plus** a separate `addElement('filepicker', 'video_upload', …, ['maxfiles' => 1, 'accepted_types' => ['video']])`. Two elements, not one — the `url` element's own picker returns `FILE_EXTERNAL` and can never populate a plugin filearea. `hideIf('videourl', 'layout', 'noteq', 'video')` and the same for `video_upload`; `hideIf` compiles to `M.form.initFormDependencies`, which works here (ordinary page form).
- Slides: `repeat_elements()` over `[filepicker slide_media, url slide_videourl, text slide_caption, hidden slide_id]`, with `hideIf` on `layout != carousel`. Follow `qtype_ddimageortext/edit_ddtoimage_form_base.php:112` for shape.
- **`extra_validation($data, $files, array &$errors)`** — NOT `validation()`, which is `final` (F2). Rules: `layout === 'video'` requires exactly one of `videourl` / `video_upload`; a `videourl` without a scheme is rejected or normalised to `https://`; `layout === 'carousel'` requires ≥ 2 slides, each with media or a caption.
- Extend `protected static $foreignfields` (`:50-53`) with `video_upload`, `slide_media`, `slide_videourl`, `slide_caption`, `slide_id` (and `layout` only if it stays out of the table — it should not).
- Add the new optional sections to `set_optional_section_state()` so a section holding a value opens regardless (`:454-457`).

---

## C3 — Save path

**`classes/helper.php`**

- `process_video(awareness $awareness)` — a literal mirror of `process_bgimage()` (`:1635-1662`): `file_get_submitted_draft_itemid('video_upload')` (scalar element, so the function is safe here), `file_save_draft_area_files(..., 'video', $id, ['maxfiles' => 1, 'accepted_types' => ['video']])`, then set the `video` flag.
- `save_slides(awareness $awareness, \stdClass $data)` — reconcile `local_awareness_slides` rows against the repeated fields, and read each draft id as **`$data->slide_media[$i]`**, never via `file_get_submitted_draft_itemid('slide_media[0]')` (F4). Save into filearea `slidemedia` with `itemid = slide->id`.
- `delete_notice()` (`:449-451`) — extend the filearea loop to `['content', 'bgimage', 'video', 'slidemedia']` **and** delete the slide rows (each slide's media is keyed on the slide id, so the loop must iterate slides before deleting them).
- Call both from `create_new_notice()` (`:85`) and `update_notice()` (`:156`) beside the existing `process_content()` / `process_bgimage()` calls.

**`lib.php:48`** — `if (!in_array($filearea, ['content', 'bgimage', 'video', 'slidemedia'])) { return false; }`. For `slidemedia` the itemid is a slide id, so resolve the slide → its notice before the existing `is_notice_available_to_user()` gate at `:78-81`; keep that gate's documented partiality intact.

---

## C4 — Render path

**`classes/helper.php`**

- `render_video_html(awareness $notice): string` — build the anchor **server-side** and let the filter do the rest:
  ```php
  $url = $notice->get('videourl') ?: self::get_video_url((int) $notice->get('id'));
  if ($url === '') { return ''; }
  $link = \html_writer::link($url, $url);          // a bare URL embeds NOTHING
  return format_text($link, FORMAT_HTML, ['noclean' => true, 'context' => \context_system::instance()]);
  ```
  A bare URL is invisible to `filter_mediaplugin` (its early return needs `</a>`, `</video>` or `</audio>`), and `filter_urltolink` is off by default. `get_video_url()` mirrors `get_bgimage_url()` (`:1719-1747`).
- `render_slides(awareness $notice): array` — one entry per slide row: `['type' => …, 'html' => <rendered media>, 'caption' => <rendered caption>]`, each field through `render_content_parts()`-equivalent formatting so pluginfile URLs and the media filter both run. Because the model is structured, this is a builder, not a DOM parser — no `DOMDocument` needed at all, which is the main practical win over the `<ul><li>` proposal.

**`classes/external/get_notices.php`**

- `execute()` (`:100-137`) — add `layout`, `videohtml`, `slides` to the payload array beside `bgimageurl`.
- `execute_returns()` (`:158-192`) — `'layout' => PARAM_ALPHA`, `'videohtml' => PARAM_RAW`, `'slides' => new external_multiple_structure(new external_single_structure(['type' => PARAM_ALPHA, 'html' => PARAM_RAW, 'caption' => PARAM_RAW]))`. **`PARAM_RAW` for every field carrying rendered/author HTML** — a `PARAM_TEXT` field whose cleaned value differs throws `invalid_response_exception` and kills the whole response for every reader (the file's own docblock at `:162-177`).
- `version.php` bump in this commit (returns change on upgrade).

---

## C5 — Templates and CSS

**`templates/modal_notice.mustache`** — the body block (`:53-57`) gains a layout hook; new partials `templates/notice/video.mustache` and `templates/notice/carousel.mustache`. Each partial's docblock carries a non-empty `Example context (json):` with at least two slides — the mustache lint renders against it and an empty loop fails. Never write a `{{…}}` tag inside a `{{! … }}` docblock.

Carousel markup: a labelled group of plain `<button>`s with `aria-current` for the dots (not a tablist), `aria-roledescription="carousel"` on the region, `aria-roledescription="slide"` per slide, an `aria-live="polite"` "n of N" region copied from `templates/manage/resultcount.mustache:17-18`, and `aria-label="{{#cleanstr}}carousel:next, local_awareness{{/cleanstr}}"` on the arrows, copying `modal_notice.mustache:49`. Stable ids `#awareness-carousel-prev` / `#awareness-carousel-next` — the existing scenarios click by id (`display_queue.feature:37`), never by label, because pt_br runs in this suite.

**`styles.css`**

1. **Add `.awareness` to the `--la-*` token-declaring selector list at `:316`** (F3) — do this in the same commit as the first `var(--la-*)` inside the dialogue, and check the `box-sizing` block at `:337-341` for the same gap.
2. `la-tpl-video` / `la-carousel-*` rules. The media well owns its own aspect box (percentage `padding-top`, not `ratio-16x9`), its own `object-fit: cover` (not `object-fit-cover`) and flex layout (not `d-grid`) — all three utilities are dead on 4.5 (F9).
3. Inactive slides `display: none`, active `display: block` — mirroring Bootstrap's own `_carousel.scss` structure — so Behat's `isVisible()` spin is deterministic.
4. Motion: extend the `@media (prefers-reduced-motion: reduce)` block at `:950-958` to zero the slide transition, and add a `body.behat-site` guard doing the same (the file has none today: `grep -n 'behat-site' styles.css` → no match).
5. No `!important`; no `clamp()`/`min()`/`max()` in length-valued properties; transitions ≥ 100 ms; two-column caps via `flex: 1 1 45%` + `min-width`, never `repeat(auto-fit, minmax(...))`.

---

## C6 — AMD

- **`amd/src/carousel.js`** — new ES module, ~120 lines, no jQuery, no Bootstrap carousel (F9). `SELECTORS` const of `data-*` hooks. Exposes `mount(bodyEl)` / `reset(bodyEl)`.
- **`amd/src/notice.js`** — call `Carousel.reset()` + `stopMedia()` in the **`else` branch** at `:110-120`, not only in the `create().then()` callback (F7). Bind the arrow-key handler once, delegated, alongside the existing listeners at `:88-104`.
- **`amd/src/modal_notice.js`** — add `ModalNotice.prototype.hide` as a **prototype method** (the file's docblock at `:196-206` explains why a class field would be too late), calling `stopMedia(this.getBody())` then `Modal.prototype.hide.call(this)`. `stopMedia`: `dispose()` any `videojs.getPlayer(id)` (the non-creating getter — bare `videojs(id)` *creates* a player), pause every `video, audio`, blank-and-restore each `iframe` `src`.
- Add a one-line comment recording that **no** `require(['media_videojs/loader'])` is needed and must not be added: the host page already ran `setUp()`, and calling it twice re-registers the document listener and double-initialises every player (F1).
- `mdl grunt <stack> local/awareness`, commit `amd/build/**` + `.map` in the **same** commit, plus a `version.php` bump. `npx eslint --max-warnings 0` is the bar: watch `promise/no-nesting`, `promise/always-return`, `no-nested-ternary`, `max-len` 132.

---

## C7 — Preview becomes a server round trip

New `classes/external/preview_notice.php` following the WS checklist (`validate_parameters` → `require_login` + guest reject → `validate_context` derived server-side → `require_capability('local/awareness:manage')`; read-only, so no event). Returns `{content, videohtml, slides, bgimageurl, layout}` with the same `PARAM_RAW` typing as C4. Import `core_external\…` explicitly — the global `external_value` alias does not exist during a WS request.

`amd/src/editor_preview.js:97-107` stops using `getContent()` and calls the WS, then renders through the **real `ModalNotice`** rather than `ModalCancel` (F5). `db/services.php` + `version.php` bump in the same commit.

---

## C8 — Tests

**PHPUnit** (`tests/`)

- `tests/local/slides_test.php` — `render_slides()` ordering, media-type resolution, empty-caption case. Pure enough for `\basic_testcase` where it does not touch `$DB`.
- `tests/form/notice_form_video_test.php` — `extra_validation()`: scheme-less URL, video layout with neither source, carousel with one slide. **Mutation-check each**: delete the rule, exactly one test must redden.
- `tests/external/notice_external_test.php:802-810` — extend the exact-set `$expected` array (it is written to fail on an unreviewed key addition; that is the point). Add a case asserting a slide caption containing a bare `<` survives the round trip — that is the regression guard for the `PARAM_TEXT` trap, and it must be non-vacuous: assert the caption's *value*, not merely that the call returned.
- `tests/lib_test.php` — `local_awareness_pluginfile()` serves the new fileareas, and refuses them for a user outside the audience. Include a **control**: a file in an allowed area that *is* served, so the test cannot pass by the handler never running.
- `tests/helper_test.php` — `delete_notice()` removes video and slide-media files **and** slide rows. Control: assert a `content` file is gone in the same test, or a broken loop passes silently.
- Extend `tests/local/bootstrap_compat_test.php` (the fleet's `local_dimensions` pattern) to scan `templates/`, `amd/src/` and `classes/` for `object-fit-cover`, `ratio-`, `d-grid`, `carousel-item-start|end|left|right`, and for any `bg-*` badge without an explicit text utility. Mutation-test each assertion — two drafts of that file elsewhere in the fleet passed while blind to the defect they existed for.
- While `$plugin->supported` still includes 405, keep **class-level `@covers` docblocks**, not `#[CoversClass]`: moodle-cs on the 4.05 leg cannot see PHP attributes and reports `moodle.PHPUnit.TestCaseCovers.Missing` for every method.

**Behat** (`tests/behat/`) — thin smoke only

- `video_layout.feature`: a notice with a YouTube link renders a player. Assert the initialised state (a `vjs-` class on the element via `I should see … "css_element"`), not just markup — that is the guard for the F1 residual.
- `carousel.feature`: two slides; click `#awareness-carousel-next`; assert caption 1 is gone and caption 2 present. Keep each label and its value on **one source line** in the template — `I should see` is a raw `contains()` with no whitespace normalisation.
- Extend `display_queue.feature`'s existing *"Two repeating notices arrive together"* scenario so the second notice is a carousel — that is the F7 regression, in the scenario that already exercises the reuse path.
- The generator step is `@Given the following site notices exist` (`behat_local_awareness.php:44`) — **no trailing colon**; a colon makes it a different, missing step. It needs new columns for `layout`/`videourl`, and a companion step for slides.
- Re-run `mdl phpunit-init` + `mdl behat-init` after every `version.php` bump — a plain bump stales both, and an outdated behat site exits 0 with zero scenarios run.

---

## C9 — Lang, docs, versioning

`lang/en` + `lang/pt_br` in lockstep, alphabetical, no section comments. New keys, in their slots:

- `carousel:next`, `carousel:previous`, `carousel:slide` (`Slide {$a->index} of {$a->total}`), `carousel:slidemedia`, `carousel:slidecaption`, `carousel:addslides` — between `cachedef_site_user_count` (en:69) and `collision:badge` (en:70).
- `editor:section:layout`, `editor:section:layout:desc` — in the `editor:section:*` family (en:92-101).
- `notice:layout`, `notice:layout_help`, `notice:layout:carousel|hero|standard|video`, `notice:video`, `notice:video_help`, `notice:videourl`, `notice:videourl_help`, `notice:videourl_invalid`, `notice:video_required`, `notice:slides_required` — in the `notice:*` family (en:172+).

`version.php` bump + `CHANGELOG.md` entry in **every** commit that changes `db/`, `amd/build/**` or `execute_returns()`. README compatibility section unchanged (`$plugin->supported = [405, 502]` stays), so `ci.yml` needs no edit.

---

## CI risks per branch

| leg | risk | mitigation |
|---|---|---|
| **4.05 static** | moodle-cs cannot see PHP attributes → `moodle.PHPUnit.TestCaseCovers.Missing` × every method in a converted file, under `--max-warnings 0` | keep class-level `@covers` docblocks in every new test file; record why in the docblock |
| **4.05 behat** | BS4 data-API; `object-fit-cover`/`ratio-*`/`d-grid` resolve to nothing | plugin-owned CSS for the media well (C5); `bootstrap_compat_test` enforces it |
| **5.02 `validate`** | php-parser v4/v5 collision fatals on a combined member modifier in `db/upgrade.php` or `lang/en/local_awareness.php` | keep those two files free of `private static` / `public static` members; verify with `mdl ci <repo> --branch MOODLE_502_STABLE --only validate` |
| **all, mustache** | new partials' `Example context (json)` must render valid HTML with a **non-empty** slides array | supply two slides plus the `data-*` JSON the AMD reads |
| **all, grunt** | eslint/stylelint at `--max-lint-warnings 0`; `amd/build` must match `amd/src` | `mdl grunt` + commit the bundle in the same commit as the source |
| **all, phpcs** | multi-line call/declaration shape, 132-char soft max, `@var` on property docblocks, no `/** */` inside method bodies | `mdl ci <repo> --only phpcs,phpdoc` before every push |

Run `mdl ci <repo> --matrix --behat` before pushing. A clean default single leg (`MOODLE_501_STABLE`, PHP 8.3, pgsql) proves nothing about 4.05 or 5.02, and both of those carry a real risk above.


---

## Decisions needed

- **How are carousel slides authored?** This is the one genuine product call in the proposal — it trades author friction against correctness, and engineering cannot decide it.
  - **Recommended: a structured slides model.** Own table + `repeat_elements()`, one filepicker/URL + caption per slide, validated at save time. Core precedent: `qtype_ddimageortext`. Each slide is directly assertable as `slide_caption[0]` in tests. Cost: the author fills a repeating form instead of typing a bullet list, and cannot reuse the editor's familiar image-insert flow.
  - **As proposed: parse `<ul><li>` from the content editor.** Lowest friction, reuses what authors already know. But there is no author-facing schema, no save-time validation, and every bullet list already authored is silently reinterpreted as slide data the moment a notice is switched to the carousel layout. A two-image item or a nested list fails in front of readers, not in the editor.
  - **Hybrid:** keep the bullet-list capture but parse it at save time, store the result in the slides table, and show the author what was detected before saving. Gets the ergonomics and the validation, at the cost of the most code and a lossy round trip when the author edits the list again.

- **Should the video field be a plain URL box or a rich-text editor?**
  - **Recommended: a plain URL input** (`url` element, validated in `extra_validation()`) plus a separate `filepicker` for uploads. Whatever plays is then built by the site's own media filter from a link the plugin constructed, so the site's player configuration governs.
  - **A rich-text editor** would let an author paste an embed code from any provider the site's players do not support. But notice content is rendered with `noclean => true` (`classes/helper.php:1712`), so HTMLPurifier never runs: a pasted `<iframe>` or `<script>` would reach every reader verbatim. That is not a new hole — the main content editor already has this property, and only holders of `local/awareness:manage` can author — but it should be an accepted decision rather than an inherited one.

- **Should an uploaded video be capped independently of `$CFG->maxbytes`?**
  - **Recommended: add a `local_awareness` size setting** for the video field, defaulting to something modest, and surface it in the field's help text. The existing `bgimage` filepicker inherits the site-wide cap, which was sized for images; a multi-MB video is a different payload and every reader in the audience downloads it.
  - **Inherit `$CFG->maxbytes`** like `bgimage` does — one less setting, but on a site with a low cap the upload is refused with no plugin-specific guidance, and on a site with a high one nothing stops a 500 MB file being pushed at every user on the platform.
  - **Disallow uploads entirely; external links only.** Simplest and cheapest to serve, and it sidesteps the new filearea, the cleanup loop and the byte-range path. Rules out self-hosted video for sites that cannot use YouTube or Vimeo.

- **What happens to a playing video when the reader dismisses the notice?**
  - **Recommended: stop it.** Pause every `video`/`audio` and blank each iframe `src` in a `ModalNotice.prototype.hide` override. Today `modal.hide()` only toggles CSS classes, so audio would keep playing from an invisible dialogue.
  - **Leave it playing** — arguably right for a long announcement the reader wants to finish listening to, but the dialogue is gone from the screen and there is no control to stop it.
  - **Ask:** should a video-layout notice **autoplay** at all? Recommended default is **no** — muted autoplay is the only kind browsers permit, it fights the `prefers-reduced-motion` guard the layout work is already adding, and it turns every page load into an interruption with a soundtrack.

- **Should `layout` also gate the existing background image?**
  - **Recommended: yes — `hideIf` the `bgimage` field on the `video` and `carousel` layouts.** A hero background behind a video band or a photo carousel is two competing media surfaces in one dialogue, and the stored file is then invisible with nothing on the page to say so.
  - **Leave `bgimage` always available** and let the CSS decide which wins. Fewer form rules, but the author sets a background that silently does nothing — and the fleet's own rule is that a field holding a value must never be hidden from the person who set it, so this would need the field to stay visible _and_ carry a warning.


## Constraints the mockups must respect

- **Every colour, border and surface must come from a token, and the token block must be declared on `.awareness` as well as `.local-awareness-editor`.** `styles.css:316-330` declares `--la-brand`, `--la-paper`, `--la-surface`, `--la-line`, `--la-ink`, `--la-radius` on the editor root only; the dialogue is attached by core to its own element on `document.body`, a sibling. An unresolved `var()` does not fall back to the literal — it invalidates the whole declaration, so `background: var(--la-surface)` becomes _no_ background. Mockups may not introduce a colour that is not one of these tokens, and both 5.1/5.2 dark modes must be checked (the tokens resolve; a hardcoded palette does not).

