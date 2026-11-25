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

/**
 * Context scoping for a notice's role rule.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_awareness\local;

/**
 * Builds the SQL that narrows a {role_assignments} lookup to the contexts a role rule names.
 *
 * helper::user_matches_role_filter() asks it about one user and reads the roles back;
 * audience\estimator tests membership inside an EXISTS to count users. One definition keeps the
 * display path, the write path and the audience estimate from answering differently.
 *
 * The fragments attach to the {role_assignments} row whose alias the caller passes ('ra' by
 * default).
 *
 * A role held in a course counts only with an active enrolment in that course
 * ({@see self::active_enrolment_sql()}): suspending or ending an enrolment leaves the assignment,
 * and a notice for the teachers of the site is not for the ones who no longer teach. Roles held
 * in the system, a category or the front page have no enrolment to ask about.
 */
class role_scope {
    /**
     * Join and where fragments restricting the $ra row to the contexts the rule covers.
     *
     * filter_category and filter_course are read here in their second meaning: as the scope of the
     * role question, not as page-context filters in their own right. A category context consults
     * only the category list; a course context takes the union of the two lists, so holding the
     * role in any course of a listed category counts even when that course is not one named.
     *
     * $suffix makes every alias and parameter name unique, because the estimator embeds this
     * fragment several times in one statement (the combined count and each rule's breakdown chip)
     * and fix_sql_params() rejects a named placeholder that appears more than once. The
     * context-level branches are mutually exclusive, so one call never emits rctxlvl twice.
     *
     * Any other value, 0 included, restricts no context: every assignment is read, and the ones
     * held in a course are asked for their enrolment like the course branch's.
     *
     * @param array $filters Decoded filtervalues, or normalised estimator criteria.
     * @param int $rolectx Context level from filter_role_context; 0 means any context.
     * @param string $suffix Appended to every alias and parameter name this builds.
     * @param string $ra Alias of the {role_assignments} row the fragments attach to.
     * @return array [$join, $where, $params] — fragments plus their bound parameters.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function sql(array $filters, int $rolectx, string $suffix = '', string $ra = 'ra'): array {
        global $DB;

        $join = '';
        $where = '';
        $params = [];
        $ctx = 'ctx' . $suffix;
        $crs = 'crs' . $suffix;
        $sysparam = 'rsysctx' . $suffix;
        $lvlparam = 'rctxlvl' . $suffix;

        if ($rolectx == CONTEXT_SYSTEM) {
            $syscontext = \context_system::instance();
            $where = " AND {$ra}.contextid = :{$sysparam}";
            $params[$sysparam] = (int) $syscontext->id;
        } else if ($rolectx == CONTEXT_COURSECAT) {
            $join = " JOIN {context} {$ctx} ON {$ctx}.id = {$ra}.contextid"
                . " AND {$ctx}.contextlevel = :{$lvlparam}";
            $params[$lvlparam] = CONTEXT_COURSECAT;
            if (!empty($filters['filter_category'])) {
                $catids = array_map('intval', $filters['filter_category']);
                [$catinsql, $catinparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'rcat' . $suffix);
                $where = " AND {$ctx}.instanceid {$catinsql}";
                $params += $catinparams;
            }
        } else if ($rolectx == CONTEXT_COURSE) {
            $join = " JOIN {context} {$ctx} ON {$ctx}.id = {$ra}.contextid"
                . " AND {$ctx}.contextlevel = :{$lvlparam}";
            $params[$lvlparam] = CONTEXT_COURSE;
            $coursewheres = [];
            if (!empty($filters['filter_course'])) {
                $courseids = array_map('intval', $filters['filter_course']);
                [$cinsql, $cinparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rcrs' . $suffix);
                $coursewheres[] = "{$ctx}.instanceid {$cinsql}";
                $params += $cinparams;
            }
            if (!empty($filters['filter_category'])) {
                $catids = array_map('intval', $filters['filter_category']);
                [$catinsql, $catinparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'rccat' . $suffix);
                $join .= " LEFT JOIN {course} {$crs} ON {$crs}.id = {$ctx}.instanceid";
                $coursewheres[] = "{$crs}.category {$catinsql}";
                $params += $catinparams;
            }
            if (!empty($coursewheres)) {
                $where = " AND (" . implode(" OR ", $coursewheres) . ")";
            }
            [$enrolledsql, $enrolledparams] = self::active_enrolment_sql($ra, $ctx, $suffix);
            $where .= " AND {$enrolledsql}";
            $params += $enrolledparams;
        } else {
            // Left joined, so an assignment whose context row is gone still reads as held somewhere.
            $join = " LEFT JOIN {context} {$ctx} ON {$ctx}.id = {$ra}.contextid";
            [$enrolledsql, $enrolledparams] = self::active_enrolment_sql($ra, $ctx, $suffix);
            $where = " AND (COALESCE({$ctx}.contextlevel, 0) <> :{$lvlparam} OR {$enrolledsql})";
            $params[$lvlparam] = CONTEXT_COURSE;
            $params += $enrolledparams;
        }

        return [$join, $where, $params];
    }

    /**
     * A condition that the holder of a course-level assignment is actively enrolled in that course.
     *
     * The test core's get_enrolled_join() makes with $onlyactive: an active enrolment, in an
     * enabled method, inside its dates, the clock rounded as core rounds it so the statement can
     * be cached. And its one exception: nobody has an enrolment row on the front page, which
     * every user belongs to, so a role assigned there needs none.
     *
     * @param string $ra Alias of the {role_assignments} row.
     * @param string $ctx Alias of its {context} row, which must be a course context for the test to mean anything.
     * @param string $suffix Appended to every alias and parameter name this builds.
     * @return array [$sql, $params]
     */
    private static function active_enrolment_sql(string $ra, string $ctx, string $suffix): array {
        $ue = 'rue' . $suffix;
        $e = 're' . $suffix;
        $now = (int) round(time(), -2);
        $params = [
            'rsite' . $suffix => SITEID,
            'ractive' . $suffix => ENROL_USER_ACTIVE,
            'renabled' . $suffix => ENROL_INSTANCE_ENABLED,
            'rnow1' . $suffix => $now,
            'rnow2' . $suffix => $now,
        ];
        $sql = "({$ctx}.instanceid = :rsite{$suffix} OR EXISTS (SELECT 1
                    FROM {user_enrolments} {$ue}
                    JOIN {enrol} {$e} ON {$e}.id = {$ue}.enrolid
                   WHERE {$ue}.userid = {$ra}.userid
                     AND {$e}.courseid = {$ctx}.instanceid
                     AND {$ue}.status = :ractive{$suffix}
                     AND {$e}.status = :renabled{$suffix}
                     AND {$ue}.timestart < :rnow1{$suffix}
                     AND ({$ue}.timeend = 0 OR {$ue}.timeend > :rnow2{$suffix})))";

        return [$sql, $params];
    }
}
