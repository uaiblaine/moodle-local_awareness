<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_awareness\local;

use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * Who a notice is being written AS, and what that lets it target.
 *
 * Every audience and context field reaches the write paths straight from the client. The form
 * cannot be the boundary: three of its pickers are ajax autocompletes, whose values core declines
 * to validate server-side ("when this was an ajax request, we do not know the allowed list of
 * values"), and HTML_QuickForm_select::exportValue() skips its allowlist whenever the option list
 * is empty. So the only place a value can be checked is on the way into storage and into the
 * audience estimate, and the check has to know who is asking.
 *
 * Two scopes. site() is what every author has today: a site-level manager may target anything on
 * the site, so its rule is existence — a course, category, role, competency, format or theme that
 * is named has to be one the site actually has. course($courseid) is what a course-level author
 * will have once a notice can belong to a course: it forces the course, forbids the fields that
 * reach outside it, and intersects the rest against what that course can see. Nothing in
 * production constructs it yet. It is here, and tested, so that the policy is settled as code
 * before any capability is granted against it, and so the change that grants one is wiring rather
 * than a decision taken under time pressure.
 *
 * The rule table is the contract, and tests/local/author_scope_test.php asserts it complete against
 * the estimator's own field lists: a field added to one and not the other reddens.
 *
 * Why each course rule is what it is — measured in docs/SCOPE-VALIDATOR-FEASIBILITY.md, not assumed:
 *
 *  - filter_course is FORCED because a notice with no filters goes to the whole site
 *    (helper::check_filters() returns true on an empty set). Forcing it does not confine the role
 *    rule on its own: role_scope::sql() reads filter_course only inside its CONTEXT_COURSE branch.
 *  - filter_role_context is FORCED, not restricted, because role_scope::sql() has no else branch.
 *    The form's own default of 0 performs no context restriction at all, and 0 or CONTEXT_SYSTEM
 *    also admit the default-user roles, which the estimator turns into a literal 1 = 1.
 *  - filter_category is FORBIDDEN because it is the one field that widens: under CONTEXT_COURSE
 *    the role lookup OR-joins "any course in a listed category" with the listed courses.
 *    filter_format and filter_theme are forbidden for tidiness only — with the course forced they
 *    can be redundant or self-defeating, never wider.
 *  - reqcourse is RESTRICTED to the course or none rather than forbidden. "Keep asking until they
 *    finish MY course" is legitimate; naming another course would make the audience count a
 *    completion oracle over a course the author does not teach.
 *  - cohorts are RESTRICTED to the cohorts the course actually enrols from. Not to
 *    cohort_get_available_cohorts($coursecontext), which answers a different question — every
 *    visible cohort in the category ancestry plus every system cohort.
 *  - filter_role is RESTRICTED to the roles the site allows at course level,
 *    get_roles_for_contextlevels(CONTEXT_COURSE): a fact about the site, not about the caller,
 *    so it does not silently empty for a non-editing teacher. Safe only together with the two
 *    rules above.
 *  - filter_competency_rules is RESTRICTED to the competencies linked to the course.
 *
 * Cohorts are narrowed silently in both scopes, keeping the precedent helper::allowed_cohorts()
 * set: the pickers only ever offer allowed cohorts, so a disallowed id is a hand-made request, and
 * reporting it would confirm that the cohort exists. Every other correction is reported, so the
 * form can point the author at the field to fix, and a caller that bypassed the form is refused
 * rather than quietly edited.
 *
 * Every list the scope reads is bounded to helper::CRITERIA_LIST_MAX distinct values, because the
 * existence lookups bind one placeholder per id. A caller therefore needs no cap of its own before
 * apply(); the audience estimate keeps one after it, for the statements it builds itself.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class author_scope {
    /** Rule: the scope writes the value; whatever was submitted is replaced. */
    public const RULE_FORCE = 'force';

    /** Rule: the field may not be used in this scope; a submitted value is dropped and reported. */
    public const RULE_FORBID = 'forbid';

    /** Rule: the submitted values are intersected with the set this scope may target. */
    public const RULE_RESTRICT = 'restrict';

    /** Rule: the field passes through untouched. */
    public const RULE_LEAVE = 'leave';

    /** Rule: every value must name something the site has. */
    public const RULE_EXISTS = 'exists';

    /** Problem: a value names something that does not exist, or is not one of the offered choices. */
    public const PROBLEM_MISSING = 'missing';

    /** Problem: the field may not be used in this scope at all. */
    public const PROBLEM_FORBIDDEN = 'forbidden';

    /** Problem: a value names something that exists but lies outside this scope. */
    public const PROBLEM_OUTSIDE = 'outside';

    /**
     * The rule table: every field an author can submit, with its rule under each scope.
     *
     * The order is the order the rules are applied in, which matters only for reading the code.
     */
    public const RULES = [
        'filter_course' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_FORCE],
        'filter_role_context' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_FORCE],
        'filter_category' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_FORBID],
        'filter_format' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_FORBID],
        'filter_theme' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_FORBID],
        'reqcourse' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_RESTRICT],
        'cohorts' => ['site' => self::RULE_RESTRICT, 'course' => self::RULE_RESTRICT],
        'filter_role' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_RESTRICT],
        'filter_competency_rules' => ['site' => self::RULE_EXISTS, 'course' => self::RULE_RESTRICT],
        'pathmatch' => ['site' => self::RULE_LEAVE, 'course' => self::RULE_LEAVE],
        'filter_competency_requireall' => ['site' => self::RULE_LEAVE, 'course' => self::RULE_LEAVE],
    ];

    /** The values filter_role_context may take; the form offers exactly these. */
    public const ROLE_CONTEXTS = [0, CONTEXT_SYSTEM, CONTEXT_COURSECAT, CONTEXT_COURSE];

    /** The marker a multi-select autocomplete posts so that an empty selection still submits. */
    private const MULTISELECT_MARKER = '_qf__force_multiselect_submission';

    /** @var int The course the author writes for; 0 for the site. */
    private int $courseid;

    /**
     * Constructor. Use site() or course().
     *
     * @param int $courseid The course, or 0 for the site.
     */
    private function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    /**
     * The scope of a site-level author: anything the site has.
     *
     * @return self
     */
    public static function site(): self {
        return new self(0);
    }

    /**
     * The scope of an author writing for one course.
     *
     * @param int $courseid The course. The site course is not a course here: it is the site scope.
     * @return self
     * @throws \coding_exception When the id is not a real course id.
     */
    public static function course(int $courseid): self {
        if ($courseid <= SITEID) {
            throw new \coding_exception('A course scope needs a course id above SITEID; the site is author_scope::site().');
        }

        return new self($courseid);
    }

    /**
     * The scope a stored notice belongs to.
     *
     * The one way a notice's scope is read. A course notice carries its course id and a site notice
     * carries 0, and every gate that acts ON a notice asks here rather than testing the column, so
     * the answer cannot drift between callers. A notice whose course has since been deleted still
     * resolves to the course scope it was written under, and helper::require_author() asks exists()
     * before it resolves a context, because the context is gone too: a course author is refused and
     * only the site capability, at the system context, may still act — to delete or disable it —
     * rather than the notice being quietly promoted to the site. The before_course_deleted hook is
     * what makes that case rare.
     *
     * @param awareness $notice The notice.
     * @return self
     */
    public static function of(awareness $notice): self {
        $courseid = (int) $notice->get('courseid');

        return $courseid > SITEID ? self::course($courseid) : self::site();
    }

    /**
     * Whether this is the site scope.
     *
     * @return bool
     */
    public function is_site(): bool {
        return $this->courseid === 0;
    }

    /**
     * The course this scope writes for.
     *
     * @return int The course id, or 0 for the site scope.
     */
    public function get_courseid(): int {
        return $this->courseid;
    }

    /**
     * Whether what this scope names still exists.
     *
     * Always, for the site. For a course, whether its row is still there: a notice can outlive its
     * course when the deletion ran with the plugin uninstalled, at the database, or past the purge's
     * own catch, and the scope it resolves to then has no context to be decided in. Callers ask this
     * before context(), which would throw a missing-record error where a refusal is owed.
     *
     * @return bool
     */
    public function exists(): bool {
        global $DB;

        if ($this->is_site()) {
            return true;
        }

        return $DB->record_exists('course', ['id' => $this->courseid]);
    }

    /**
     * The context a decision about this scope is taken in.
     *
     * The system context for the site scope, the course's own for a course scope. Kept on the
     * scope rather than computed by each caller, so that "which capability, in which context" has
     * one answer: a context arriving from a caller is a context the caller chose.
     *
     * @return \context
     */
    public function context(): \context {
        if ($this->is_site()) {
            return \context_system::instance();
        }

        return \context_course::instance($this->courseid);
    }

    /**
     * The rule this scope applies to a field.
     *
     * @param string $field One of the keys of RULES.
     * @return string One of the RULE_* constants.
     * @throws \coding_exception For a field the table does not know.
     */
    public function rule_for(string $field): string {
        if (!isset(self::RULES[$field])) {
            throw new \coding_exception("author_scope has no rule for '{$field}'");
        }

        return self::RULES[$field][$this->is_site() ? 'site' : 'course'];
    }

    /**
     * Apply the scope to a set of submitted criteria.
     *
     * Only the fields in RULES are touched; anything else in the array is passed through as it
     * came, because it is not this class's business (the estimator ignores what it does not know,
     * and the write path packs only the fields it names). A field absent from the input stays
     * absent from the output, except where the course scope FORCES it.
     *
     * @param array $criteria Field name => value, as submitted by the form or decoded from a request.
     * @return scope_result The criteria as the scope left them, and every field it had to correct.
     */
    public function apply(array $criteria): scope_result {
        $out = $criteria;
        $problems = [];

        // Course.
        if ($this->is_site()) {
            if (array_key_exists('filter_course', $criteria)) {
                $ids = self::bounded(self::int_list($criteria['filter_course']));
                $out['filter_course'] = self::existing_courses($ids);
                if (count($out['filter_course']) !== count($ids)) {
                    $problems['filter_course'] = self::PROBLEM_MISSING;
                }
            }
        } else {
            $out['filter_course'] = [$this->courseid];
        }

        // Role context.
        if ($this->is_site()) {
            if (array_key_exists('filter_role_context', $criteria)) {
                $level = (int) $criteria['filter_role_context'];
                if (!in_array($level, self::ROLE_CONTEXTS, true)) {
                    $level = 0;
                    $problems['filter_role_context'] = self::PROBLEM_MISSING;
                }
                $out['filter_role_context'] = $level;
            }
        } else {
            $out['filter_role_context'] = CONTEXT_COURSE;
        }

        // Category.
        if (array_key_exists('filter_category', $criteria)) {
            $ids = self::bounded(self::int_list($criteria['filter_category']));
            if ($this->is_site()) {
                $out['filter_category'] = self::existing_ids('course_categories', $ids);
                if (count($out['filter_category']) !== count($ids)) {
                    $problems['filter_category'] = self::PROBLEM_MISSING;
                }
            } else {
                $out['filter_category'] = [];
                if (!empty($ids)) {
                    $problems['filter_category'] = self::PROBLEM_FORBIDDEN;
                }
            }
        }

        // Format and theme: installed plugin names, or nothing at all in a course.
        foreach (['filter_format' => 'format', 'filter_theme' => 'theme'] as $field => $plugintype) {
            if (!array_key_exists($field, $criteria)) {
                continue;
            }
            $names = self::bounded(self::name_list($criteria[$field]));
            if ($this->is_site()) {
                $installed = array_keys(\core_component::get_plugin_list($plugintype));
                $out[$field] = array_values(array_intersect($names, $installed));
                if (count($out[$field]) !== count($names)) {
                    $problems[$field] = self::PROBLEM_MISSING;
                }
            } else {
                $out[$field] = [];
                if (!empty($names)) {
                    $problems[$field] = self::PROBLEM_FORBIDDEN;
                }
            }
        }

        // Required course: none, an existing course, or in a course scope only the course itself.
        if (array_key_exists('reqcourse', $criteria)) {
            $required = (int) $criteria['reqcourse'];
            if ($required !== 0) {
                if ($this->is_site()) {
                    if (self::existing_courses([$required]) === []) {
                        $required = 0;
                        $problems['reqcourse'] = self::PROBLEM_MISSING;
                    }
                } else if ($required !== $this->courseid) {
                    $required = 0;
                    $problems['reqcourse'] = self::PROBLEM_OUTSIDE;
                }
            }
            $out['reqcourse'] = $required;
        }

        // Cohorts: silently narrowed, and bounded only afterwards — see bounded().
        if (array_key_exists('cohorts', $criteria)) {
            $allowed = helper::allowed_cohorts(self::int_list($criteria['cohorts']));
            if (!$this->is_site()) {
                $allowed = array_values(array_intersect($allowed, $this->enrolled_cohort_ids()));
            }
            $out['cohorts'] = self::bounded($allowed);
        }

        // Roles.
        if (array_key_exists('filter_role', $criteria)) {
            $ids = self::bounded(self::int_list($criteria['filter_role']));
            if ($this->is_site()) {
                $out['filter_role'] = self::existing_ids('role', $ids);
                $reason = self::PROBLEM_MISSING;
            } else {
                $out['filter_role'] = array_values(array_intersect($ids, $this->course_role_ids()));
                $reason = self::PROBLEM_OUTSIDE;
            }
            if (count($out['filter_role']) !== count($ids)) {
                $problems['filter_role'] = $reason;
            }
        }

        // Competency rules: the rule list, keeping only the rules whose competency is allowed.
        if (array_key_exists('filter_competency_rules', $criteria)) {
            $rules = helper::normalise_competency_rules($criteria['filter_competency_rules']);
            $ids = array_map(static function (array $rule): int {
                return (int) $rule['id'];
            }, $rules);
            if ($this->is_site()) {
                $allowed = self::existing_ids('competency', $ids);
                $reason = self::PROBLEM_MISSING;
            } else {
                $allowed = array_values(array_intersect($ids, $this->course_competency_ids()));
                $reason = self::PROBLEM_OUTSIDE;
            }
            $out['filter_competency_rules'] = array_values(array_filter(
                $rules,
                static function (array $rule) use ($allowed): bool {
                    return in_array((int) $rule['id'], $allowed, true);
                }
            ));
            if (count($out['filter_competency_rules']) !== count($rules)) {
                $problems['filter_competency_rules'] = $reason;
            }
        }

        // The pathmatch and the requireall switch are LEAVE in both scopes: nothing to do.

        return new scope_result($out, $problems);
    }

    /**
     * A submitted list as positive, distinct integers, in the order submitted.
     *
     * Accepts what the three shapes of caller send: an array of ids, a comma-separated string (how
     * the persistent stores cohorts), or a scalar. The empty string and the autocomplete's
     * force-submission marker both cast to 0 and are dropped with it.
     *
     * @param mixed $value The submitted value.
     * @return int[]
     */
    private static function int_list($value): array {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        $ids = array_map('intval', (array) $value);
        $ids = array_filter($ids, static function (int $id): bool {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * A submitted list as distinct, non-empty plugin names, in the order submitted.
     *
     * @param mixed $value The submitted value.
     * @return string[]
     */
    private static function name_list($value): array {
        $names = array_map(static function ($name): string {
            return trim((string) $name);
        }, (array) $value);
        $names = array_filter($names, static function (string $name): bool {
            return $name !== '' && $name !== self::MULTISELECT_MARKER;
        });

        return array_values(array_unique($names));
    }

    /**
     * A list cut to the length one statement can carry.
     *
     * The existence lookups bind one placeholder per id and PostgreSQL refuses past 65535 of them;
     * helper::cap_criteria_lists() bounds the estimator's statements for the same reason, and the
     * scope bounds its own so that no entry point has to remember to. Cohorts are bounded AFTER
     * they are narrowed: that narrowing runs in PHP against the site's own cohort list and costs
     * nothing, and a legitimate id must not be dropped for sitting behind a hand-made run of junk.
     *
     * @param array $list Ids or names, already distinct.
     * @return array The first helper::CRITERIA_LIST_MAX of them.
     */
    private static function bounded(array $list): array {
        return array_slice($list, 0, helper::CRITERIA_LIST_MAX);
    }

    /**
     * The subset of ids that exist in a table, in the order submitted.
     *
     * @param string $table The table, without braces.
     * @param int[] $ids Candidate ids.
     * @return int[]
     */
    private static function existing_ids(string $table, array $ids): array {
        global $DB;

        if (empty($ids)) {
            return [];
        }

        $found = array_map('intval', array_keys($DB->get_records_list($table, 'id', $ids, '', 'id')));

        return array_values(array_intersect($ids, $found));
    }

    /**
     * The subset of ids that are real courses, in the order submitted.
     *
     * The site course is not one: check_filters() resolves a course only above SITEID, the
     * estimator excludes it explicitly, and the course picker never offers it. A notice that named
     * it would reach nobody on the front page and everybody nowhere.
     *
     * @param int[] $ids Candidate ids.
     * @return int[]
     */
    private static function existing_courses(array $ids): array {
        $ids = array_values(array_filter($ids, static function (int $id): bool {
            return $id > SITEID;
        }));

        return self::existing_ids('course', $ids);
    }

    /**
     * The cohorts this scope's course enrols from.
     *
     * enrol_cohort keeps the cohort id in {enrol}.customint1 (enrol/cohort/lib.php). A disabled
     * instance still counts: the question here is which cohorts belong to the course, and an
     * instance an administrator has paused still answers it. Whether a cohort's members are
     * currently enrolled is the enrolment's business, and the display rules read the enrolment.
     *
     * @return int[]
     */
    private function enrolled_cohort_ids(): array {
        global $DB;

        $sql = "SELECT DISTINCT e.customint1
                  FROM {enrol} e
                 WHERE e.enrol = :enrol AND e.courseid = :courseid AND e.customint1 IS NOT NULL";

        return array_map('intval', $DB->get_fieldset_sql($sql, ['enrol' => 'cohort', 'courseid' => $this->courseid]));
    }

    /**
     * The roles the site allows to be assigned at course level.
     *
     * @return int[]
     */
    private function course_role_ids(): array {
        return array_map('intval', array_values(get_roles_for_contextlevels(CONTEXT_COURSE)));
    }

    /**
     * The competencies linked to this scope's course.
     *
     * Read from {competency_coursecomp} directly rather than through
     * core_competency\api::list_course_competencies(), which requires a capability in the course
     * and throws when the subsystem is off. This is a validator: it must answer the same way for
     * every caller, and a stored rule for a competency that was linked while the subsystem was on
     * is still that rule when it is off.
     *
     * @return int[]
     */
    private function course_competency_ids(): array {
        global $DB;

        return array_map(
            'intval',
            $DB->get_fieldset_select('competency_coursecomp', 'competencyid', 'courseid = ?', [$this->courseid])
        );
    }
}
