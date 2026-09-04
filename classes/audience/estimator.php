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
        'filter_groups',
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

        $groups = self::sanitise_int_list($raw['filter_groups'] ?? []);
        if (!empty($groups)) {
            $out['filter_groups'] = $groups;
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
     * @param bool $withbreakdown Whether to compute the per-rule counts as well.
     * @return array
     */
    public function estimate(array $criteria, bool $withbreakdown = true): array {
        global $DB;

        $audiencerules = self::audience_rules_in($criteria);
        $contextrules = self::context_rules_in($criteria);

        [$base, $params] = self::base_predicate();

        /*
         * One pass, not one per rule. Every count here reads the same rows — the whole {user} table
         * — and asks a different question of each; as separate statements that was N+1 sequential
         * scans of a table with hundreds of thousands of rows, for answers that differ only in the
         * predicate. Conditional aggregation asks all of them while the rows are already in hand.
         *
         * Each fragment is rebuilt under its own suffix rather than reused. Moodle counts
         * placeholder OCCURRENCES against the parameter array (fix_sql_params) and throws
         * duplicateparaminsql when a name appears twice, so the same predicate appearing in two
         * columns must carry two sets of names.
         */
        [$totalsql, $totalparams] = self::predicate($criteria, null, 't');
        $columns = ["SUM(CASE WHEN {$totalsql} THEN 1 ELSE 0 END) AS total"];
        $params += $totalparams;

        $keys = [];
        if ($withbreakdown) {
            foreach (array_values($audiencerules) as $i => $rule) {
                /*
                 * Two arguments, because "the rule alone" is two separate questions. isolate_rule()
                 * decides what the rule is allowed to READ — filter_role has to keep the category
                 * and course lists or it stops being scoped. The second decides which rules are
                 * APPLIED, and naming only this one is what stops those same lists from also
                 * counting as their own rule inside the role's chip, which would make every chip
                 * approximate the total instead of its own share.
                 */
                [$rulesql, $ruleparams] = self::predicate(self::isolate_rule($criteria, $rule), [$rule], "b{$i}");
                $columns[] = "SUM(CASE WHEN {$rulesql} THEN 1 ELSE 0 END) AS rule{$i}";
                $params += $ruleparams;
                $keys[$i] = $rule;
            }
        }

        $sql = "SELECT " . implode(",\n               ", $columns)
            . "\n          FROM {user} u\n         WHERE " . implode(' AND ', $base);
        $row = $DB->get_record_sql($sql, $params);

        // SUM() over no matching rows is NULL in both drivers, and (int) null is the 0 we want.
        $result = [
            'count' => (int) $row->total,
            'breakdown' => [],
            'context_only_filters' => $contextrules,
            'has_audience_rules' => !empty($audiencerules),
        ];

        foreach ($keys as $i => $rule) {
            $result['breakdown'][] = [
                'key' => $rule,
                'count' => (int) $row->{'rule' . $i},
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
     * The population every count here is taken from: real, active users.
     *
     * Mirrors what Moodle considers a "real" user at login. It is the WHERE of the single statement
     * rather than part of any rule's predicate, so the rows are filtered once and every conditional
     * column is evaluated over the same set.
     *
     * SUM(CASE …), not COUNT(DISTINCT u.id): the FROM clause is {user} alone and every rule is an
     * EXISTS or a comparison on u, so a user is one row and cannot be counted twice.
     *
     * THIS DEPENDS ON THE FROM CLAUSE STAYING JOIN-FREE. Anything that joins a one-to-many table in
     * here multiplies the rows and silently overcounts; keep new predicates inside an EXISTS as
     * every existing one is.
     *
     * @return array [$whereparts, $params]
     */
    private static function base_predicate(): array {
        global $CFG;

        return [
            [
                'u.deleted = 0',
                'u.suspended = 0',
                'u.confirmed = 1',
                'u.id <> :guestid',
                // Guest is excluded by username too, to be robust on sites imported from elsewhere
                // where it does not hold the id $CFG->siteguest names.
                'u.username <> :guestname',
            ],
            /*
             * Bound from $CFG->siteguest, not from a literal 1. The guest account only holds id 1
             * on a site Moodle installed itself; after a migration it is whatever it is, and the
             * literal put the real guest back into every audience count while excluding whichever
             * innocent user inherited the id. The username predicate beside it is a second net,
             * not a substitute — a site can rename the account.
             */
            ['guestid' => (int) ($CFG->siteguest ?? 1), 'guestname' => 'guest'],
        ];
    }

    /**
     * The audience-rule predicate for one conditional column.
     *
     * Everything it builds — parameter names and subquery aliases alike — is suffixed, because
     * several of these end up in the same statement and Moodle rejects a placeholder that appears
     * more than once. With no rules applied it is "1 = 1", the whole site, which is the answer the
     * editor wants for a notice that has not been narrowed yet.
     *
     * @param array $criteria Normalised criteria. Rules absent from it are not applied either way.
     * @param array|null $applyrules Audience rule keys to apply; null applies every rule present.
     *                               A rule may still be READ for another rule's scope while absent
     *                               from this list — see the call in estimate().
     * @param string $suffix Unique per column; appended to every name this builds.
     * @return array [$sql, $params]
     */
    private static function predicate(array $criteria, ?array $applyrules, string $suffix): array {
        global $DB, $CFG;

        $applies = function (string $rule) use ($criteria, $applyrules): bool {
            return !empty($criteria[$rule]) && ($applyrules === null || in_array($rule, $applyrules, true));
        };

        $where = [];
        $params = [];

        if ($applies('cohorts')) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['cohorts']),
                SQL_PARAMS_NAMED,
                'coh' . $suffix
            );
            $cm = 'cm' . $suffix;
            $where[] = "EXISTS (SELECT 1 FROM {cohort_members} {$cm}
                                  WHERE {$cm}.userid = u.id AND {$cm}.cohortid {$insql})";
            $params += $inparams;
        }

        if ($applies('filter_groups')) {
            /*
             * Membership, whatever the group's visibility: a member of a hidden group is still a
             * member, and helper::user_in_notice_groups() delivers on the same terms. The groups
             * name their own course, so this stands on its own; under a course scope the forced
             * filter_course adds the enrolment test through course_scope_sql() below.
             */
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['filter_groups']),
                SQL_PARAMS_NAMED,
                'grp' . $suffix
            );
            $gm = 'gm' . $suffix;
            $where[] = "EXISTS (SELECT 1 FROM {groups_members} {$gm}
                                  WHERE {$gm}.userid = u.id AND {$gm}.groupid {$insql})";
            $params += $inparams;
        }

        if ($applies('filter_role')) {
            $roleids = array_map('intval', $criteria['filter_role']);
            $rolectx = (int) ($criteria['filter_role_context'] ?? 0);
            $ra = 'ra' . $suffix;
            $clauses = [];

            // Real assignments.
            [$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role' . $suffix);

            [$ctxjoin, $ctxwhere, $ctxparams] = role_scope::sql($criteria, $rolectx, $suffix, $ra);
            $inparams += $ctxparams;

            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} {$ra} {$ctxjoin}
                                    WHERE {$ra}.userid = u.id AND {$ra}.roleid {$insql} {$ctxwhere})";
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
            $params['reqcourseid' . $suffix] = (int) $criteria['reqcourse'];
            $params['reqcourseexists' . $suffix] = (int) $criteria['reqcourse'];
            $cc = 'cc' . $suffix;
            $rc = 'rc' . $suffix;
            /*
             * Notice fires for users who have NOT completed the required course. Mirrors the
             * course-completion block in helper::collect_user_notices() — being present in
             * {course_completions} only counts when timecompleted is set — and, like it, counts
             * nobody once the course is gone. Deleting a course purges its completion rows, so
             * the NOT EXISTS on its own went vacuously true and the estimate grew to the whole
             * site the moment the gate stopped meaning anything. The same id is bound under two
             * names because a named placeholder may appear only once per statement.
             */
            $where[] = "EXISTS (SELECT 1 FROM {course} {$rc} WHERE {$rc}.id = :reqcourseexists{$suffix})";
            $where[] = "NOT EXISTS (SELECT 1 FROM {course_completions} {$cc}
                                      WHERE {$cc}.userid = u.id
                                        AND {$cc}.course = :reqcourseid{$suffix}
                                        AND {$cc}.timecompleted IS NOT NULL
                                        AND {$cc}.timecompleted > 0)";
        }

        [$coursesql, $courseparams] = self::course_scope_sql($criteria, $applies, $suffix);
        if ($coursesql !== '') {
            $where[] = $coursesql;
            $params += $courseparams;
        }

        return [empty($where) ? '1 = 1' : '(' . implode(' AND ', $where) . ')', $params];
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
     * @param string $suffix Unique per conditional column; appended to every name this builds.
     * @return array [$sql, $params] — an empty string when no rule here is in play.
     */
    private static function course_scope_sql(array $criteria, callable $applies, string $suffix = ''): array {
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

        $ue = 'ue' . $suffix;
        $e = 'e' . $suffix;
        $c = 'c' . $suffix;

        // Rounded, as core does, so the DB can cache the plan across calls.
        $now = round(time(), -2);
        $params = [
            'csactive' . $suffix => ENROL_USER_ACTIVE,
            'csenabled' . $suffix => ENROL_INSTANCE_ENABLED,
            'csnow1' . $suffix => $now,
            'csnow2' . $suffix => $now,
            'cssiteid' . $suffix => SITEID,
        ];
        $conditions = [
            "{$ue}.userid = u.id",
            "{$ue}.status = :csactive{$suffix}",
            "{$e}.status = :csenabled{$suffix}",
            "{$ue}.timestart < :csnow1{$suffix}",
            "({$ue}.timeend = 0 OR {$ue}.timeend > :csnow2{$suffix})",
            "{$c}.id <> :cssiteid{$suffix}",
            "{$c}.visible = 1",
        ];

        if ($hascategory) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['filter_category']),
                SQL_PARAMS_NAMED,
                'cscat' . $suffix
            );
            $conditions[] = "{$c}.category {$insql}";
            $params += $inparams;
        }

        if ($hascourse) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['filter_course']),
                SQL_PARAMS_NAMED,
                'cscrs' . $suffix
            );
            $conditions[] = "{$c}.id {$insql}";
            $params += $inparams;
        }

        if ($hasformat) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('strval', $criteria['filter_format']),
                SQL_PARAMS_NAMED,
                'csfmt' . $suffix
            );
            $conditions[] = "{$c}.format {$insql}";
            $params += $inparams;
        }

        if ($hascompetency) {
            [$compsql, $compparams] = self::competency_sql($criteria, $suffix, $c);
            $conditions[] = $compsql;
            $params += $compparams;
        }

        $sql = "EXISTS (SELECT 1
                          FROM {user_enrolments} {$ue}
                          JOIN {enrol} {$e} ON {$e}.id = {$ue}.enrolid
                          JOIN {course} {$c} ON {$c}.id = {$e}.courseid
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
     * @param string $suffix Unique per conditional column; appended to every name this builds.
     * @param string $course Alias of the enclosing {course} row to correlate against.
     * @return array [$sql, $params]
     */
    private static function competency_sql(array $criteria, string $suffix, string $course): array {
        $requireall = !empty($criteria['filter_competency_requireall']);
        $conditions = [];
        $params = [];

        foreach (array_values($criteria['filter_competency_rules']) as $i => $rule) {
            $params["cscomp{$suffix}_{$i}"] = (int) $rule['id'];
            $ucc = "ucc{$suffix}_{$i}";
            $exists = "EXISTS (SELECT 1
                                 FROM {competency_usercompcourse} {$ucc}
                                WHERE {$ucc}.userid = u.id
                                  AND {$ucc}.courseid = {$course}.id
                                  AND {$ucc}.competencyid = :cscomp{$suffix}_{$i}
                                  AND {$ucc}.proficiency = 1)";
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
