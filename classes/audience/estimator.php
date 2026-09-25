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
 * Estimates how many users a notice can reach, from a normalised criteria array, in bulk SQL.
 *
 * A rule counts towards the audience when it can be answered about a user. Every rule does except
 * pathmatch and filter_theme, which are properties of the page being rendered; those two are
 * reported as context restrictions instead.
 *
 * The category, course, format and competency rules are page rules and user rules at once:
 * check_filters() only admits them on a course page the user can access, so their reach is bounded
 * by who is enrolled in a course the rule names. See course_scope_sql() for the predicate and what
 * it deliberately does not model.
 *
 * With no rules at all the count is the whole site (every real, active user) rather than zero,
 * which would read as "this notice reaches nobody".
 *
 * The role rule shares its context scoping with the per-user check in
 * helper::user_matches_role_filter(): both call {@see \local_awareness\local\role_scope::sql()}.
 * Here membership is tested inside an EXISTS over every user; there the roles are read back for
 * one user. One deliberate divergence: a filter naming a default role collapses to 1 = 1 here,
 * because an implicit assignment has no rows in {role_assignments} to count.
 * test_the_bulk_count_agrees_with_the_per_user_rule() pins that the two agree.
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
     * Empty rules are dropped and lists are de-duplicated and sorted, so two semantically-equal
     * inputs produce the same hash. filter_role_context is kept only beside filter_role, and
     * filter_competency_requireall only beside filter_competency_rules. Shape only: whether the
     * ids exist is checked by {@see \local_awareness\local\author_scope}, not here.
     *
     * @param array $raw Raw criteria keyed by the notice form's field names, e.g. 'filter_role'.
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
     *  - breakdown: list of [{key, count}], one per audience-shaping rule counted alone; empty
     *    unless $withbreakdown
     *  - context_only_filters: list of [{key, values}], as context_rules_in() returns them
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
         * One statement with a conditional column per count, not one query per rule: every count
         * reads the same {user} rows, so separate statements would scan the table N+1 times.
         *
         * Each fragment is rebuilt under its own suffix rather than reused, because
         * fix_sql_params() throws duplicateparaminsql when a named placeholder appears twice.
         */
        [$totalsql, $totalparams] = self::predicate($criteria, null, 't');
        $columns = ["SUM(CASE WHEN {$totalsql} THEN 1 ELSE 0 END) AS total"];
        $params += $totalparams;

        $keys = [];
        if ($withbreakdown) {
            foreach (array_values($audiencerules) as $i => $rule) {
                /*
                 * isolate_rule() decides what the rule may read: filter_role keeps the category and
                 * course lists that scope it. The second argument decides which rules are applied;
                 * naming only this one stops those lists from also counting as rules of their own
                 * inside the role's chip.
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
     * "The rule alone" means without the other rules, not without its own settings.
     * filter_role_context decides which context level a role is searched at, and filter_category /
     * filter_course narrow it inside that level ({@see \local_awareness\local\role_scope::sql()});
     * dropping them would count a role scoped to one course across the whole site.
     * filter_competency_requireall likewise decides how the competency rules combine.
     *
     * The carried category and course lists are read only as the role's scope: estimate() applies
     * the isolated rule alone, so they do not also count as rules inside the role's chip. Their own
     * chips are computed from criteria that carry no role.
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
     * It is the WHERE of the single statement rather than part of any rule's predicate, so the rows
     * are filtered once and every conditional column is evaluated over the same set.
     *
     * The counts are SUM(CASE …), not COUNT(DISTINCT u.id): the FROM clause is {user} alone and
     * every rule is an EXISTS or a comparison on u, so a user is one row and cannot be counted
     * twice. That depends on the FROM clause staying join-free; a join to a one-to-many table would
     * multiply the rows and overcount, so keep new predicates inside an EXISTS.
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
                'u.username <> :guestname',
            ],
            /*
             * Bound from $CFG->siteguest, not a literal 1: the guest holds id 1 only on a site
             * Moodle installed itself, and after a migration a literal would count the real guest
             * and exclude whoever inherited id 1. The username test is a second net, not a
             * substitute, because the account can be renamed.
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
             * Membership, whatever the group's visibility, as helper::user_in_notice_groups()
             * delivers. The groups name their own course, so this stands on its own; under a course
             * scope the forced filter_course adds the enrolment test through course_scope_sql().
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

            // The default user and front page roles have no {role_assignments} rows; a filter naming
            // one admits every user in the population, as helper::user_matches_role_filter() does.
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
             * Counts users who have NOT completed the required course, as the completion block in
             * helper::collect_user_notices() does; keep the two in step. A {course_completions} row
             * counts only when timecompleted is set, and a course that no longer exists reaches
             * nobody: deletion purges its completion rows, so the NOT EXISTS alone would count the
             * whole site. The id is bound under two names because a named placeholder may appear
             * only once per statement.
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
     * for that course. So the population those rules can reach is the people who hold such a
     * course, and this reproduces that as one EXISTS over the user's enrolments.
     *
     * The enrolment half is core's get_enrolled_join() with $onlyactive = true, inlined because
     * that helper takes one course context and this asks about a set of courses in a single
     * statement. Keep it in step: active user_enrolment, enabled enrol instance, inside the
     * enrolment's own time window. Two of core's special cases land differently here:
     *
     *  - get_enrolled_join() skips the enrolment join for SITEID, where everyone counts as
     *    enrolled. That exemption is not carried over: check_filters() resolves the course only
     *    when the id is greater than 1, so the front page never satisfies these rules, and the
     *    exemption would report the whole site for a rule that reaches nobody. The site course is
     *    excluded explicitly.
     *  - can_access_course() also admits a user with no enrolment who holds moodle/course:view or
     *    has temporary guest access, and refuses a hidden course to anyone without
     *    moodle/course:viewhiddencourses. Capabilities are not resolvable in bulk here, so the
     *    estimate keeps the enrolment branch and the visibility rule and skips the others. It
     *    therefore reads slightly low for a notice aimed at people who view courses they are not
     *    enrolled in; reading low was chosen over reading high because an editor acts on the
     *    number by narrowing.
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
