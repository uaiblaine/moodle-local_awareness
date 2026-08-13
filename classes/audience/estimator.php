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
 * Audience-shaping criteria (participate in the count): cohorts, filter_role,
 * reqcourse (completion). Everything else (pathmatch, category, course list,
 * format, theme, competencies) is page-context dependent and surfaced as a
 * context restriction.
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
    public const AUDIENCE_FIELDS = ['cohorts', 'filter_role', 'reqcourse'];

    /** Field names that only restrict where/when the notice fires. */
    public const CONTEXT_FIELDS = [
        'pathmatch',
        'filter_category',
        'filter_course',
        'filter_format',
        'filter_theme',
        'filter_competency_rules',
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
     *  - has_audience_rules: bool — false means no audience-shaping rule was set
     *
     * @param array $criteria normalised criteria
     * @return array
     */
    public function estimate(array $criteria): array {
        $audiencerules = self::audience_rules_in($criteria);
        $contextrules = self::context_rules_in($criteria);

        $result = [
            'count' => 0,
            'breakdown' => [],
            'context_only_filters' => $contextrules,
            'has_audience_rules' => !empty($audiencerules),
        ];

        if (empty($audiencerules)) {
            return $result;
        }

        $result['count'] = self::count_combined($criteria);
        foreach ($audiencerules as $rule) {
            $result['breakdown'][] = [
                'key' => $rule,
                'count' => self::count_combined(self::isolate_rule($criteria, $rule)),
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
     * filter_category and filter_course are context rules elsewhere and are ignored by the count
     * on their own; they are read only inside the role block, which is why carrying them here
     * cannot widen any other rule.
     *
     * @param array $criteria Normalised criteria.
     * @param string $rule The audience-shaping rule to isolate.
     * @return array
     */
    private static function isolate_rule(array $criteria, string $rule): array {
        $modifiers = [
            'filter_role' => ['filter_role_context', 'filter_category', 'filter_course'],
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
     * @param array $criteria subset containing at least one of cohorts/filter_role/reqcourse
     * @return int
     */
    private static function count_combined(array $criteria): int {
        global $DB, $CFG;

        // Base set: real, active users. Mirrors what Moodle considers a "real" user at login.
        $where = ["u.deleted = 0", "u.suspended = 0", "u.confirmed = 1", "u.id <> :guestid"];
        $params = ['guestid' => 1]; // Guest user has id=1 only on fresh installs; safer to also check by username.

        // Refine: exclude guest by username too, to be robust on imported sites.
        $where[] = "u.username <> :guestname";
        $params['guestname'] = 'guest';

        if (!empty($criteria['cohorts'])) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $criteria['cohorts']),
                SQL_PARAMS_NAMED,
                'coh'
            );
            $where[] = "EXISTS (SELECT 1 FROM {cohort_members} cm
                                  WHERE cm.userid = u.id AND cm.cohortid {$insql})";
            $params += $inparams;
        }

        if (!empty($criteria['filter_role'])) {
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

        if (!empty($criteria['reqcourse'])) {
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

        $sql = "SELECT COUNT(DISTINCT u.id) FROM {user} u WHERE " . implode(' AND ', $where);
        return (int) $DB->count_records_sql($sql, $params);
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
