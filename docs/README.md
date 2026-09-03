# local_awareness — design documentation

This directory is `export-ignore`d in `.gitattributes`: it is versioned for
development and review, and never ships in the release zip.

## Audit and its reconciliation

[`AUDIT-2026-08.md`](AUDIT-2026-08.md) is the August 2026 audit of commit `896dfc2` — 191 numbered
findings plus 7 from a completeness critic. Its own header warns that it is the snapshot of the
starting point, **not** a list of what is still open.

[`RECONCILIACAO-2026-08.md`](RECONCILIACAO-2026-08.md) is that missing list: a verdict per finding
with current-tree evidence for each. **No High or Medium finding is open** — 105 of the 198 are
settled and the four Medium survivors are partials with the remainder named. Read the
reconciliation, not the audit, to know what is open.

What is left is almost entirely Low and Informational: file-header and docblock drift, the
`html_writer` usage that grew rather than shrank, the missing fleet-template repo files, and
Mustache docblocks that drift from the variables their templates read.

[`PLANO-correcoes.md`](PLANO-correcoes.md) is the four-phase work list that closed 27 items across
PRs #26–#29; it is finished.

## Prospective design

[`AVAILABILITY-AWARENESS-FEASIBILITY.md`](AVAILABILITY-AWARENESS-FEASIBILITY.md) assesses a proposed
`availability_awareness` condition plugin — restricting an activity on acceptance of a notice.
Verdict: buildable, but it is a **teacher-facing, per-activity** tool that cannot express an
administrator's site-wide compliance intent, and five defects in this plugin's own acceptance model
have to be fixed before "accepted" means anything stable enough to gate on. Cost and cross-site
restore are both solved there, including the assumption that did not survive review — core does
**not** uniformly fail closed on a missing referent, so a dangling notice id would silently grant
access under a negated condition.

[`BLOCK-AWARENESS-FEASIBILITY.md`](BLOCK-AWARENESS-FEASIBILITY.md) assesses a course-level surface
letting teachers write to their own participants. Verdict: the goal is reachable, but **not as a
block** — core built this exact shape in `tool_monitor` (a `courseid` column, a capability at
`CONTEXT_COURSE`, one page reached from course navigation, a `course_deleted` observer, and no block
anywhere) — and **display needs nothing new**, because `filter_course` already means "only this
course's pages, only for someone currently in it". The work is a security boundary that does not yet
exist: `sanitise_data()` validates one of twelve audience inputs, a notice with no filters goes
site-wide, and core declines to validate ajax autocomplete values server-side. The document also
corrects a claim in this plugin's own `CLAUDE.md` about `can_access_course()`.

[`SCOPE-VALIDATOR-FEASIBILITY.md`](SCOPE-VALIDATOR-FEASIBILITY.md) prices the second of that
document's build steps, the server-side scope validator, and the fork it forces first: a validator
needs a scope to validate against, and the plugin has none. It checks the eleven-field
classification against the code — nine of eleven labels stand, `filter_role_context` becomes FORCE
and `reqcourse` becomes RESTRICT, and three labels keep their name for a different reason than
given — inventories the entry points the boundary has to sit on, and prices three options. Verdict:
build the validator as a **scope value object whose course branch is implemented and tested now
against hand-built scopes** but is unreachable from production until a `courseid` column and a
course capability land, in four pull requests; a site-scope-only validator is untestable by
construction, and building the foundation in the same change creates an ownership hole in three
files and cannot be bisected. Six decisions are the owner's and are listed there. Nothing in that
analysis was executed.

## Approved UI mockups

The HTML files under [`mockups/`](mockups/) are the self-contained prototypes
the admin-surface redesign was designed and approved against. Open them in any
browser; they carry no external dependencies and follow Moodle Boost's visual
language, including its light and dark palettes.

Labels inside the mockups are in Brazilian Portuguese because they mirror the
approved `pt_br` UI strings — the shipped plugin resolves every label through
the language packs (`en` + `pt_br`). Everything else — file names, markup,
comments and this document — is in English, per the fleet standard.

| File | Screen |
|------|--------|
| [`mockups/manage-notices.html`](mockups/manage-notices.html) | Manage notices (`managenotice.php`) — page shell renders first and the list arrives over AJAX; name search, Status filter (active / draft / **has conflict**), validity filter, 25 per page, summary tiles computed over the filtered set, per-row action menu, empty and no-result states |
| [`mockups/edit-notice.html`](mockups/edit-notice.html) | Edit notice (`editnotice.php`) — single content column, every section an accordion with expand/collapse all, 1/2/3-column field grids, audience estimate after the rules it results from, preview in a modal, actions in core's sticky footer |

The mockups link to each other the way the real pages flow.

## Why the redesign

The pages ran a design system parallel to Moodle's: a gradient page
background, a monospace type role, a hardcoded brand colour, and a fixed
three-column grid governed by **viewport** media queries while living inside
`#region-main`, which is narrower than the viewport by however wide the block
drawer happens to be. Measured on a 1440 px viewport with the drawer open, the
grid resolved to `240px │ 315px │ 380px` — the form column was the narrowest of
the three, its title input 161 px wide.

Full diagnosis, measurements and the migration plan live in the design
proposal; the decisions that shape the markup are summarised below.

## Design decisions worth knowing

- **Read the theme's tokens, never invent them.** `var(--bs-primary, var(--primary, #0f6cbf))`
  resolves on both branches: Moodle 4.5 defines the Bootstrap 4 names on
  `:root` (`--primary`) and 5.x defines `--bs-*`; neither defines both. This is
  also what makes the pages follow the theme into dark mode, which 5.1 and 5.2
  ship (`.theme-dark`, `[data-bs-theme="dark"]`).

- **Intrinsic column counts, not media queries — and flex, not grid.** The card's
  width is the only input, so the block drawer cannot break the layout. The
  obvious grid spelling caps nothing: `repeat(auto-fit, minmax(19rem, 1fr))`
  reads as "two columns" and silently yields three on a wide card. Capping it
  needs `minmax(max(19rem, 45%), 1fr)`, and **Moodle's stylelint rejects that** —
  `Invalid value for "grid-template-columns"` from `csstree/validator`, measured
  against the real gate, which also rejects `@container` and `container-type`
  (`Unknown at-rule`, `Unknown property`). A percentage flex-basis does the same
  job in valid CSS: three items of `flex: 1 1 45%` cannot share a row, and a
  `min-width` in rem keeps each column usable.

- **Only what is on is drawn.** The behaviour column shows a chip per enabled
  setting and nothing for the disabled ones. An on/off pair separated by colour
  is invisible to a reader with a colour vision deficiency; absence carries
  "off", and every chip present says what it is in words.

- **Every badge states its text colour.** Bootstrap 4's `.badge` sets no colour
  and Bootstrap 5's defaults to white, so the two branches fail on disjoint sets
  of backgrounds. The mockups pair each background token with its own ink token,
  which is also what keeps them legible when the palette flips to dark.

- **Row action menus use `.dropdown`, never `.btn-group`.** Boost forces
  `.table-responsive .dropdown { position: static }` precisely so the menu's
  containing block lands outside the scroll container and escapes its overflow
  clip. `.btn-group` is `position: relative` and puts the clip straight back —
  the last row's menu is cut off. The shipped page uses core's `\action_menu`,
  which emits the structure that rule expects.

- **The sticky footer carries buttons only.** Core's raw pattern: icon above a
  centred label, no colour variant, the row centred, no status text. It is
  rendered after the page content and therefore outside the moodleform, so the
  submit button carries `form="<form id>"`.

- **Long titles clamp to two lines.** Notice titles have no length cap in the
  database. The full string stays in the node — clipped for the eye, intact for
  a screen reader — with the whole name in the `title` attribute. Paired
  `-webkit-line-clamp` + `line-clamp`, the form the fleet's stylelint accepts.

- **The conflict badge explains itself twice.** A `title` for the pointer and a
  `visually-hidden` sibling for assistive technology: `aria-label` on a bare
  `<span>` has no role to attach to and is not reliably announced. What a
  conflict *is* is explained once, in a help popover on the Status column
  header, rather than repeated on every row.

- **The list loads after the page.** The server request returns shell, filters
  and skeleton without touching `{awareness}`; rows arrive over AJAX. No new web
  service: `table_sql` implements `\core_table\dynamic` with a `filterset`, and
  core's `core_table_get_dynamic_table_content` serves each page — the same
  arrangement core's own participants page uses.

- **Accent-insensitive name search.** Ported from `local_dimensions`: on
  PostgreSQL the query wraps both operands in `unaccent()` when the extension is
  present (provisioned at install/upgrade, never on a request path); on
  MySQL/MariaDB the collation already does it; anywhere else it degrades to an
  accent-sensitive `LIKE`.
