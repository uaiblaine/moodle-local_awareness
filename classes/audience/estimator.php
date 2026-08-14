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

namespace local_awareness\audience;

use local_awareness\local\role_scope;

/**
 * Pure module that estimates the audience size for a notice given a normalised
 * criteria array, in bulk SQL instead of per-user.
 *
 * A rule counts towards the audience when it can be answered about a USER. That is every rule the
 * notice has except two: pathmatch and filter_theme, which are properties of the page being
 * rendered and of nobody in particular. Those two are surfaced as context restrictions instead.
 *
 * The category, course, format and competency rules look like page rules and were treated as such
 * until an author pointed out that the number they were left out of is the number the editor calls
 * "reach". They are page rules AND user rules at once: check_filters() only admits them on a course
 * page the user can actually access, so "how many people could ever see this" is bounded by who is
 * enrolled in a course the rule names. See course_scope_sql() for the predicate and what it
 * deliberately does not model.
 *
 * With no rules at all the count is the whole site — every real, active user — rather than zero.
 * Zero was read as "this notice reaches nobody" when it meant "nothing has been narrowed yet".
 *
 * The role half shares its context scoping with the per-user rule: both call
 * {@see \local_awareness\local\role_scope::sql()}, so which contexts count is
 * one definition rather than two that have to be kept in step. What remains
 * separate is how the answer is consumed — here membership is tested inside an
 * EXISTS over every user, there the roles are read back for one — and one
 * deliberate divergence: a default role standing in for "every user" collapses
 * to 1 = 1 here, because counting cannot enumerate an implicit assignment that
 * has no rows in {role_assignments}. That the two agree in practice is pinned
 * by test_the_bulk_count_agrees_with_the_per_user_rule().
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class estimator {
    /** Field names that contribute to the audience count. */
    public const AUDIENCE_FIELDS = [
        'cohorts',
        'filter_role',
        'reqcourse',
        'filter_category',
        'filter_course',
        'filter_format',
        'filter_competency_rules',
    ];

    /** Field names that only restrict where/when the notice fires. */
    public const CONTEXT_FIELDS = [
        'pathmatch',
        'filter_theme',
    ];

    /**
     * Normalise raw criteria from the form/web service into a deterministic shape.
     *
     * Sorts arrays so that two semantically-equal inputs produce the same hash.
     *
     * @param array $raw
     * @return array
     */
    public static function normalise(array $raw): array {
        $out = [];

        $cohorts = self::sanitise_int_list($raw['cohorts'] ?? []);
        if (!empty($cohorts)) {
            $out['cohorts'] = $cohorts;
        }

        $roles = self::sanitise_int_list($raw['filter_role'] ?? []);
        if (!empty($roles)) {
            $out['filter_role'] = $roles;
            $out['filter_role_context'] = (int) ($raw['filter_role_context'] ?? 0);
        }

        $reqcourse = (int) ($raw['reqcourse'] ?? 0);
        if ($reqcourse > 0) {
            $out['reqcourse'] = $reqcourse;
        }

        $categories = self::sanitise_int_list($raw['filter_category'] ?? []);
        if (!empty($categories)) {
            $out['filter_category'] = $categories;
        }

        $courses = self::sanitise_int_list($raw['filter_course'] ?? []);
        if (!empty($courses)) {
            $out['filter_course'] = $courses;
        }

        $formats = self::sanitise_string_list($raw['filter_format'] ?? []);
        if (!empty($formats)) {
            $out['filter_format'] = $formats;
        }

        $themes = self::sanitise_string_list($raw['filter_theme'] ?? []);
        if (!empty($themes)) {
            $out['filter_theme'] = $themes;
        }

        $rules = \local_awareness\helper::normalise_competency_rules($raw['filter_competency_rules'] ?? []);
        if (!empty($rules)) {
            $out['filter_competency_rules'] = $rules;
            $out['filter_competency_requireall'] = !empty($raw['filter_competency_requireall']) ? 1 : 0;
        }

        $pathmatch = trim((string) ($raw['pathmatch'] ?? ''));
        if ($pathmatch !== '') {
            $out['pathmatch'] = $pathmatch;
        }

        return $out;
    }

    /**
     * SHA-256 hash of the normalised criteria. Stable across calls with equivalent input.
     *
     * @param array $criteria normalised criteria
     * @return string
     */
    public static function hash(array $criteria): string {
        return hash('sha256', json_encode($criteria));
    }

    /**
     * Run the estimate.
     *
     * Returns:
     *  - count: estimated audience size (int)
     *  - breakdown: list of [{key, label_key, count}] for each audience-shaping rule alone
     *  - context_only_filters: list of [{key, label_key, values}]
     *  - has_audience_rules: bool — false means the count is the whole site, nothing was narrowed
     *
     * @param array $criteria normalised criteria
     * @return array
     */
    public function estimate(array $criteria): array {
        $audiencerules = self::audience_rules_in($criteria);
        $contextrules = self::context_rules_in($criteria);

        $result = [
            'count' => self::count_combined($criteria),
            'breakdown' => [],
            'context_only_filters' => $contextrules,
            'has_audience_rules' => !empty($audiencerules),
        ];

        foreach ($audiencerules as $rule) {
            /*
             * Two arguments, because "the rule alone" is two separate questions. isolate_rule()
             * decides what the rule is allowed to READ — filter_role has to keep the category and
             * course lists or it stops being scoped. The second argument decides which rules are
             * APPLIED, and naming only this one is what stops those same lists from also counting
             * as their own rule inside the role's chip, which would make every chip approximate the
             * total instead of its own share.
             */
            $result['breakdown'][] = [
                'key' => $rule,
                'count' => self::count_combined(self::isolate_rule($criteria, $rule), [$rule]),
            ];
        }

        return $result;
    }

    /**
     * Reduce the criteria to a single audience rule, keeping the keys that modify that rule.
     *
     * "The rule alone" means without the OTHER rules, not without its own settings. filter_role is
     * the only one with any: filter_role_context decides which context level is searched, and
     * filter_category / filter_course narrow it further inside that level. Dropping them made the
     * breakdown answer a different question from the one the notice asks — a rule scoped to one
     * course was counted across the whole site, so the editor offered "Teachers: every teacher
     * here" beside a total of the handful who would actually see it.
     *
     * filter_category and filter_course now count on their own as well, so carrying them into the
     * role chip does widen it — from "teachers of this course" to "teachers of this course, or
     * anyone enrolled in it". That is the reading the chip is meant to have: the scope belongs to
     * the rule. Their own chips are computed separately, from criteria that carry no role.
     *
     * @param array $criteria Normalised criteria.
     * @param string $rule The audience-shaping rule to isolate.
     * @return array
     */
    private static function isolate_rule(array $criteria, string $rule): array {
        $modifiers = [
            'filter_role' => ['filter_role_context', 'filter_category', 'filter_course'],
            'filter_competency_rules' => ['filter_competency_requireall'],
        ];

        $single = [$rule => $criteria[$rule]];
        foreach ($modifiers[$rule] ?? [] as $key) {
            if (isset($criteria[$key])) {
                $single[$key] = $criteria[$key];
            }
        }

        return $single;
    }

    /**
     * Audience-shaping rule keys present in the criteria.
     *
     * @param array $criteria
     * @return string[]
     */
    public static function audience_rules_in(array $criteria): array {
        $out = [];
        foreach (self::AUDIENCE_FIELDS as $key) {
            if (!empty($criteria[$key])) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Context-only rule keys present in the criteria with shaped values.
     *
     * @param array $criteria
     * @return array list of {key, values}
     */
    public static function context_rules_in(array $criteria): array {
        $out = [];
        foreach (self::CONTEXT_FIELDS as $key) {
            if (!empty($criteria[$key])) {
                $out[] = [
                    'key' => $key,
                    'values' => $criteria[$key],
                ];
            }
        }
        return $out;
    }

    /**
     * Build and execute the COUNT(DISTINCT u.id) query for an arbitrary subset of audience-shaping rules.
     *
     * With no rules applied this is the site's population of real, active users, which is the
     * answer the editor wants for a notice that has not been narrowed yet.
     *
     * @param array $criteria Normalised criteria. Rules absent from it are not applied either way.
     * @param array|null $applyrules Audience rule keys to apply; null applies every rule present.
     *                               A rule may still be READ for another rule's scope while absent
     *                               from this list — see the call in estimate().
     * @return int
     */
    private static function count_combined(array $criteria, ?array $applyrules = null): int {
        global $DB, $CFG;

        $applies = function (string $rule) use ($criteria, $applyrules): bool {
            return !empty($criteria[$rule]) && ($applyrules === null || in_array($rule, $applyrules, true));
        };

        // Base set: real, active users. Mirrors what Moodle considers a "real" user at login.
        $where = ["u.deleted = 0", "u.suspended = 0", "u.confirmed = 1", "u.id <> :guestid"];
        $params = ['guestid' => 1]; // Guest user has id=1 only on fresh installs; safer to also check by username.

        // Refine: exclude guest by username too, to be robust on imported sites.
        $where[] = "u.username <> :guestname";
        $params['guestname'] = 'guest';

        if ($applies('cohorts')) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['cohorts']),
                SQL_PARAMS_NAMED,
                'coh'
            );
            $where[] = "EXISTS (SELECT 1 FROM {cohort_members} cm
                                  WHERE cm.userid = u.id AND cm.cohortid {$insql})";
            $params += $inparams;
        }

        if ($applies('filter_role')) {
            $roleids = array_map('intval', $criteria['filter_role']);
            $rolectx = (int) ($criteria['filter_role_context'] ?? 0);
            $clauses = [];

            // Real assignments.
            [$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

            [$ctxjoin, $ctxwhere, $ctxparams] = role_scope::sql($criteria, $rolectx);
            $inparams += $ctxparams;

            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra {$ctxjoin}
                                    WHERE ra.userid = u.id AND ra.roleid {$insql} {$ctxwhere})";
            $params += $inparams;

            // Implicit default-user role: applies to every confirmed, non-guest user.
            if ($rolectx == 0 || $rolectx == CONTEXT_SYSTEM) {
                $defaults = [];
                if (!empty($CFG->defaultuserroleid)) {
                    $defaults[] = (int) $CFG->defaultuserroleid;
                }
                if (!empty($CFG->defaultfrontpageroleid)) {
                    $defaults[] = (int) $CFG->defaultfrontpageroleid;
                }
                $defaults = array_unique($defaults);
                if (array_intersect($defaults, $roleids)) {
                    // Filter matches a default role → every user qualifies on the role test.
                    $clauses[] = "1 = 1";
                }
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if ($applies('reqcourse')) {
            $params['reqcourseid'] = (int) $criteria['reqcourse'];
            // Notice fires for users who have NOT completed the required course.
            // Mirrors the course-completion block in helper::retrieve_user_notices() — being
            // present in {course_completions} only counts when timecompleted is set. Named rather
            // than cited by line, which the previous reference had already drifted past.
            $where[] = "NOT EXISTS (SELECT 1 FROM {course_completions} cc
                                      WHERE cc.userid = u.id
                                        AND cc.course = :reqcourseid
                                        AND cc.timecompleted IS NOT NULL
                                        AND cc.timecompleted > 0)";
        }

        [$coursesql, $courseparams] = self::course_scope_sql($criteria, $applies);
        if ($coursesql !== '') {
            $where[] = $coursesql;
            $params += $courseparams;
        }

        $sql = "SELECT COUNT(DISTINCT u.id) FROM {user} u WHERE " . implode(' AND ', $where);
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * The predicate for the four rules that are answered against a course the user is in.
     *
     * helper::check_filters() admits the category, course, format and competency rules only on a
     * course page, and only after can_access_course($course, null, '', true) has accepted the user
     * for that course. So the population those rules can ever reach is the people who hold such a
     * course, and this reproduces that as one EXISTS over the user's enrolments.
     *
     * The enrolment half is core's get_enrolled_join() with $onlyactive = true, inlined because
     * that helper takes ONE course context and this asks about a set of courses in a single
     * statement. It must agree with it: active user_enrolment, enabled enrol instance, inside the
     * enrolment's own time window. Two of core's special cases and how they land here:
     *
     *  - get_enrolled_join() skips the enrolment join entirely for SITEID, where everyone counts as
     *    enrolled. That exemption must NOT be carried over: check_filters() resolves the course only
     *    when the id is greater than 1, so the front page never satisfies these rules in the first
     *    place, and importing the exemption would report the whole site for a rule that reaches
     *    nobody. The site course is excluded explicitly.
     *  - can_access_course() also admits a user with no enrolment at all who holds
     *    moodle/course:view, and refuses a hidden course to anyone without
     *    moodle/course:viewhiddencourses. Capabilities are not resolvable in bulk here, so the
     *    estimate keeps the enrolment branch and the visibility rule and skips the viewer branch.
     *    It therefore reads slightly LOW for a notice aimed at people who only ever view courses
     *    they are not enrolled in. Chosen over reading high, because an editor acts on the number
     *    by narrowing.
     *
     * @param array $criteria Normalised criteria.
     * @param callable $applies Predicate deciding whether a rule key is present and applied.
     * @return array [$sql, $params] — an empty string when no rule here is in play.
     */
    private static function course_scope_sql(array $criteria, callable $applies): array {
        global $DB;

        $hascategory = $applies('filter_category');
        $hascourse = $applies('filter_course');
        $hasformat = $applies('filter_format');
        $hascompetency = $applies('filter_competency_rules');

        if (!$hascategory && !$hascourse && !$hasformat && !$hascompetency) {
            return ['', []];
        }

        /*
         * check_filters() refuses a competency rule outright when the subsystem is off, so the
         * notice reaches nobody rather than everybody.
         */
        if ($hascompetency && !\local_awareness\helper::is_competency_filter_enabled()) {
            return ['1 = 0', []];
        }

        // Rounded, as core does, so the DB can cache the plan across calls.
        $now = round(time(), -2);
        $params = [
            'csactive' => ENROL_USER_ACTIVE,
            'csenabled' => ENROL_INSTANCE_ENABLED,
            'csnow1' => $now,
            'csnow2' => $now,
            'cssiteid' => SITEID,
        ];
        $conditions = [
            'ue.userid = u.id',
            'ue.status = :csactive',
            'e.status = :csenabled',
            'ue.timestart < :csnow1',
            '(ue.timeend = 0 OR ue.timeend > :csnow2)',
            'c.id <> :cssiteid',
            'c.visible = 1',
        ];

        if ($hascategory) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['filter_category']),
                SQL_PARAMS_NAMED,
                'cscat'
            );
            $conditions[] = "c.category {$insql}";
            $params += $inparams;
        }

        if ($hascourse) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['filter_course']),
                SQL_PARAMS_NAMED,
                'cscrs'
            );
            $conditions[] = "c.id {$insql}";
            $params += $inparams;
        }

        if ($hasformat) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('strval', $criteria['filter_format']),
                SQL_PARAMS_NAMED,
                'csfmt'
            );
            $conditions[] = "c.format {$insql}";
            $params += $inparams;
        }

        if ($hascompetency) {
            [$compsql, $compparams] = self::competency_sql($criteria);
            $conditions[] = $compsql;
            $params += $compparams;
        }

        $sql = "EXISTS (SELECT 1
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                         WHERE " . implode("\n                           AND ", $conditions) . ")";

        return [$sql, $params];
    }

    /**
     * The competency rules, as a predicate on the enclosing course row `c`.
     *
     * Mirrors the loop in helper::check_filters(): proficiency is per course, so it is asked of the
     * same course the other rules were asked of, not of the user in general. With requireall every
     * rule demands proficiency; without it each rule demands the state it names, so a rule written
     * as "not proficient" excludes the people who are.
     *
     * @param array $criteria Normalised criteria carrying filter_competency_rules.
     * @return array [$sql, $params]
     */
    private static function competency_sql(array $criteria): array {
        $requireall = !empty($criteria['filter_competency_requireall']);
        $conditions = [];
        $params = [];

        foreach (array_values($criteria['filter_competency_rules']) as $i => $rule) {
            $params["cscomp{$i}"] = (int) $rule['id'];
            $exists = "EXISTS (SELECT 1
                                 FROM {competency_usercompcourse} ucc{$i}
                                WHERE ucc{$i}.userid = u.id
                                  AND ucc{$i}.courseid = c.id
                                  AND ucc{$i}.competencyid = :cscomp{$i}
                                  AND ucc{$i}.proficiency = 1)";
            $conditions[] = ($requireall || !empty($rule['proficient'])) ? $exists : "NOT {$exists}";
        }

        return ['(' . implode(' AND ', $conditions) . ')', $params];
    }

    /**
     * Sanitise a raw list into a sorted array of unique positive integers.
     *
     * @param mixed $values
     * @return int[]
     */
    private static function sanitise_int_list($values): array {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $out[$v] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Sanitise a raw list into a sorted array of unique non-empty strings.
     *
     * @param mixed $values
     * @return string[]
     */
    private static function sanitise_string_list($values): array {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        $names = array_keys($out);
        sort($names, SORT_STRING);
        return $names;
    }
}
