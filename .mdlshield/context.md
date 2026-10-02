# Review context for local_awareness

`local_awareness` ("Awareness") shows site or course announcements to logged-in users in a
modal, optionally asks for an acknowledgement, and records who dismissed, acknowledged or
clicked each notice. Notices are aimed by rules (cohort, role, course, category, theme, page
path, groups, competency) and written by site managers or, per course, by course authors. It
is a local plugin supporting Moodle 4.5 through 5.2 on one branch, with seven tables of its
own: `local_awareness`, `_slides`, `_ack`, `_hlinks`, `_hlinks_his`, `_lastview`, `_audience_jobs`.

## Who is trusted

- Site administrators are fully trusted.
- `local/awareness:manage` (system, `RISK_CONFIG | RISK_XSS`, default `manager`) and
  `local/awareness:managecourse` (course, `RISK_XSS`, no archetype) author notices. Content is
  stored raw and rendered with `format_text(..., ['noclean' => true])`, so **the holder can put
  arbitrary markup in front of every user a notice reaches**. That is the accepted boundary of
  these two capabilities; anyone who can write a notice without one of them is a finding.
- `local/awareness:viewreports` (system, `RISK_PERSONAL`, default `manager`) and
  `local/awareness:viewreportscourse` (course, `RISK_PERSONAL`, no archetype) open the
  dismissed and acknowledged reports, which show username and idnumber. A course holder
  reads only the reports of that course's notices.
- Every plugin capability is checked in one place, `helper::require_author()`: the site
  capability is evaluated in the scope's own context (so it inherits down), the course
  capability counts only under a course scope. A page, verb or web service that checks a
  capability anywhere else, or not at all, is a finding.
- Every logged-in user is untrusted, and so is every stored name or value: titles, captions,
  `pathmatch`, course, cohort and group names, and the `pageurl` a browser reports.

## Surfaces

- 11 web service functions, all `ajax` and `loginrequired`. Reader side, no capability, any
  logged-in user including guests: `local_awareness_getnotices`, `_dismiss`, `_acknowledge`,
  `_tracklink`; the three writes re-check that the notice is available to the user and was
  delivered to this session (`helper::may_act_on_notice()`), and all four do nothing while
  delivery is switched off. Author side, `require_author($scope, 'manage')` under the scope
  the `courseid` parameter names (`local/awareness:manage` or `managecourse`):
  `_check_collision`, `_search_roles`, `_search_courses`, `_estimate_audience`,
  `_get_estimate`, `_preview_notice`. `_render_notice` accepts either verb (`manage` or
  `viewreports`, site or course), answers every refusal with one generic error, and checks
  group reach.
- Pages: `editnotice.php` (manage; `require_sesskey()` on every POST and state-changing
  action), `managenotice.php` (either verb), `report/acknowledged_systemreport.php` and
  `report/dismissed_systemreport.php` (viewreports, through the notice's own scope).
- File serving: `local_awareness_pluginfile()` serves `content`, `bgimage` and `slidemedia`
  at system context with `send_stored_file()`, behind `helper::may_serve_files_of()`.
- Hooks: `before_footer_html_generation` loads the AMD module (it catches `\Throwable` on
  purpose, since it runs on nearly every page); `before_course_deleted` purges the course's
  notices. Tasks: scheduled `purge_audience_jobs` and `purge_link_history`, adhoc
  `estimate_audience`. No observers.
- User-controlled input rendered as markup or code: the editor's rich text, the video link
  (checked as an http(s) URL, embedded by the site's multimedia filter) and `pathmatch`, a
  quoted pattern whose only wildcard is `%`, never an author-written regular expression.
- Five report builder datasources join the core user entity and add no capability of their
  own: who may build or read a custom report on them is core's report builder model.
- Privacy: a full provider (metadata, request, userlist) over every user-linked table,
  including `usermodified` on `local_awareness` and `_slides`.

## Facts that look like findings but are by design

- **`noclean` on notice content is the feature** (embedded media must survive). It is safe
  only because authoring is gated as above. Content is stored as typed, with
  `@@PLUGINFILE@@` placeholders, and resolved at render so multilang notices work; do not
  clean at save time. Titles go through `format_string()` and `pathmatch` through `s()` at
  every output; both columns are `PARAM_RAW`.
- **The file gate is partial by construction.** A file URL carries a notice id but no page,
  so the gate checks authorship in scope, course access for a course notice, and audience,
  but not the page rules (category, theme, format, competency).
- **`pageurl` and `pathmatch` are client assertions** on the read path; forging them costs a
  reader no more than forging a write would.
- **Guests are not rejected.** They get a session-only marker, because all guest sessions
  share one user id and a shared row would corrupt the reports.
- **Link clicks are not rate-limited**: each click is a row of the link history report, and
  the table is bounded by age through `purge_link_history`.
- **A course author never sees site-wide numbers.** Estimates are confined to the course,
  the per-rule breakdown is withheld under a course scope, and `get_estimate` checks a shared
  job against the caller's scope. Under the site scope `search_courses` lists every course
  name, hidden ones included, to a `manage` holder.
- **Saving expires recorded acceptances** (`timemodified` is what the acceptance check
  compares), and a notice whose referent is gone (required course, theme) is withheld, never
  shown to a wider audience.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source.
- `lang/**`, `docs/**` (design notes and mockups) and `tests/**` carry no production behaviour.
- `styles.css` and the Mustache templates, unless they show data the viewer should not see.
  The Bootstrap 4 polyfill gated by a body class is a compatibility measure.
- Code that branches on `$CFG->branch` or probes for a helper first is deliberate: one branch
  serves Moodle 4.5 to 5.2.
