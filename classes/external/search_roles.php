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

namespace local_awareness\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * Search roles for the notice editor's audience picker.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_roles extends external_api {
    /**
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW, 'search query', VALUE_DEFAULT, ''),
            'contextlevel' => new external_value(PARAM_INT, 'context level', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'course the editor is scoped to, 0 for the site', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Search roles dynamically based on context level.
     *
     * @param string $query search string
     * @param int $contextlevel context level (e.g. 10, 40, 50)
     * @param int $courseid The course the editor is scoped to, 0 for the site.
     * @return array
     */
    public static function execute(string $query = '', int $contextlevel = 0, int $courseid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'contextlevel' => $contextlevel,
            'courseid' => $courseid,
        ]);

        // Only reached from the notice editor. Without the gate any authenticated user could
        // enumerate every role defined on the site.
        /*
         * The scope the caller is writing under, from the courseid the editor sends: the site when
         * absent. Validated as a context — which also requires login to the course — and then gated
         * the way every author-side entry point is, so a course author's editor works and a caller
         * naming a course they do not hold is refused before anything is read.
         */
        $scope = author_scope::for_request(null, (int) $params['courseid']);
        self::validate_context($scope->context());
        helper::require_author($scope, 'manage');
        $syscontext = \context_system::instance();

        $query = $params['query'];
        // A course author's roles are the roles a course can hold, whatever level the client named:
        // the scope forces the role context to the course, and this is the same rule at the picker.
        $contextlevel = $scope->is_site() ? $params['contextlevel'] : CONTEXT_COURSE;

        $sql = "SELECT r.id, r.name, r.shortname
                  FROM {role} r
                 WHERE 1=1";

        $sqlparams = [];

        if ($contextlevel > 0) {
            $sql .= " AND EXISTS (SELECT 1 FROM {role_context_levels} rcl
                                   WHERE rcl.roleid = r.id AND rcl.contextlevel = :contextlevel)";
            $sqlparams['contextlevel'] = $contextlevel;
        }

        $sql .= " ORDER BY r.sortorder ASC";

        $records = $DB->get_records_sql($sql, $sqlparams);
        $allroles = role_get_names(null, ROLENAME_ORIGINAL);

        /*
         * The query is matched here rather than in SQL, because the label the picker shows is not
         * in the database. A standard role ships with an EMPTY role.name and takes its label from
         * the language pack through role_get_name(), so a LIKE over name and shortname finds
         * nothing for "Non-editing teacher" or "Course creator" — and under a translated pack it
         * finds nothing at all, for any standard role. The autocomplete does no client-side
         * filtering either: it calls this function with the typed string and renders the answer
         * verbatim, so what this misses the admin cannot select.
         *
         * The stored name stays in the comparison beside the label. role_get_name() runs it
         * through format_string(), which entity-escapes an ampersand, so a custom role called
         * "R&D coordinator" is findable by the text its author actually typed rather than only by
         * "R&amp;D". Three separate comparisons rather than one concatenated haystack, so a query
         * cannot match across a field boundary.
         *
         * Filter first, cap after: capping in SQL would have limited the rows CONSIDERED rather
         * than the rows returned, hiding matches behind fifty non-matches.
         */
        $needle = \core_text::strtolower($query);
        $roles = [];

        foreach ($records as $record) {
            $localname = isset($allroles[$record->id])
                ? $allroles[$record->id]->localname
                : format_string($record->name, true, ['context' => $syscontext]);
            $matches = $needle === ''
                || \core_text::strpos(\core_text::strtolower($localname), $needle) !== false
                || \core_text::strpos(\core_text::strtolower((string) $record->name), $needle) !== false
                || \core_text::strpos(\core_text::strtolower($record->shortname), $needle) !== false;
            if (!$matches) {
                continue;
            }
            $roles[] = [
                'id' => $record->id,
                'name' => $localname,
            ];
            if (count($roles) >= 50) {
                break;
            }
        }

        return ['roles' => json_encode($roles)];
    }

    /**
     * Returns for search_roles.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'roles' => new external_value(PARAM_RAW, 'JSON encoded list of roles'),
        ]);
    }
}
