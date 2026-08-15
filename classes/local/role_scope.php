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
 * Two places ask which role assignments a notice's rule covers, and they ask it differently.
 * helper::user_matches_role_filter() asks about one user and reads the roles back;
 * audience\estimator asks how many users there are and tests membership inside an EXISTS. The
 * predicate is the same question either way, and it used to be written out in both — thirty lines
 * apiece, differing only in what the local variables were called. They are one definition now, so
 * the display path, the write path and the audience estimate cannot answer differently.
 *
 * The fragments are built to sit in a query whose {role_assignments} row is aliased `ra`, which is
 * the shape both callers already had.
 */
class role_scope {
    /**
     * Join and where fragments restricting `ra` to the contexts the rule covers.
     *
     * filter_category and filter_course are read here in their SECOND meaning — as the scope of the
     * role question, not as page-context filters in their own right. A category context consults
     * only the category list; a course context takes the UNION of the two lists, so holding the
     * role in any course of a listed category counts even when that course is not one named.
     *
     * The estimator now asks this question several times in ONE statement — once for the combined
     * count and once per rule for the breakdown chips — so both the aliases and the parameter names
     * have to be unique per instance. $suffix is what makes them so. Moodle counts placeholder
     * OCCURRENCES against the parameter array and rejects any name that appears twice, so reusing a
     * fragment verbatim is not an option; it has to be rebuilt under a fresh suffix. The default is
     * empty, which is exactly the single-fragment SQL this produced before.
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

        if ($rolectx == CONTEXT_SYSTEM) {
            $syscontext = \context_system::instance();
            $where = " AND {$ra}.contextid = " . $syscontext->id;
        } else if ($rolectx == CONTEXT_COURSECAT) {
            $join = " JOIN {context} {$ctx} ON {$ctx}.id = {$ra}.contextid"
                . " AND {$ctx}.contextlevel = " . CONTEXT_COURSECAT;
            if (!empty($filters['filter_category'])) {
                $catids = array_map('intval', $filters['filter_category']);
                [$catinsql, $catinparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'rcat' . $suffix);
                $where = " AND {$ctx}.instanceid {$catinsql}";
                $params += $catinparams;
            }
        } else if ($rolectx == CONTEXT_COURSE) {
            $join = " JOIN {context} {$ctx} ON {$ctx}.id = {$ra}.contextid"
                . " AND {$ctx}.contextlevel = " . CONTEXT_COURSE;
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
        }

        return [$join, $where, $params];
    }
}
