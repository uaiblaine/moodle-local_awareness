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
 * Search courses for the notice editor's pickers.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses extends external_api {
    /**
     * Parameters for search_courses.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW, 'search query', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search courses by name, returning up to 50 matches.
     *
     * @param string $query Search term.
     * @return array
     */
    public static function execute(string $query = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['query' => $query]
        );

        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        helper::require_author(author_scope::site(), 'manage');

        $query = trim($params['query']);
        $results = [];

        if (strlen($query) >= 2) {
            $likesql = $DB->sql_like('fullname', ':search', false);
            $courses = $DB->get_records_select(
                'course',
                "id <> :siteid AND {$likesql}",
                ['siteid' => SITEID, 'search' => '%' . $DB->sql_like_escape($query) . '%'],
                'fullname ASC',
                'id, fullname',
                0,
                50
            );
            foreach ($courses as $course) {
                /*
                 * The ESCAPED spelling, because this label is rendered as HTML twice over:
                 * course_search.js hands it to core's autocomplete, which appends it into the
                 * hidden select (lib/amd/src/form-autocomplete.js, updateAjax) and then renders it
                 * back through the triple stash in lib/templates/form_autocomplete_suggestions.
                 * mustache. Nothing between json_encode() and that stash escapes anything, so the
                 * default escape is applied exactly once. It also resolves a multilang fullname,
                 * which otherwise reaches the picker as literal {mlang} markup.
                 *
                 * Deliberately NOT \core_external\util::format_string(): that helper honours
                 * external_settings, whose constructor only sets filter = true when the request is
                 * neither AJAX_SCRIPT, CLI_SCRIPT nor WS_SERVER — and this function is only ever
                 * reached over AJAX, so the core helper would leave the multilang markup unresolved.
                 * The plain call is the one that does what this sink needs.
                 *
                 * The LIKE above still matches the RAW stored fullname, which is what makes a course
                 * called "R&D methods" findable by typing the text its author actually typed.
                 */
                $coursecontext = \context_course::instance($course->id, IGNORE_MISSING) ?: \context_system::instance();
                $results[] = [
                    'id' => (int) $course->id,
                    'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                ];
            }
        }

        return ['courses' => json_encode($results)];
    }

    /**
     * Return parameters for search_courses.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_value(PARAM_RAW, 'JSON array of {id, fullname}', VALUE_DEFAULT, '[]'),
        ]);
    }
}
