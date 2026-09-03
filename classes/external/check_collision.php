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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\local\collision;
use local_awareness\persistent\awareness;

/**
 * Repeating notices that would compete with this one for the same pages.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_collision extends external_api {
    /**
     * Parameters for search_roles.
     *
     * @return external_function_parameters
     */
    /**
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'noticeid' => new external_value(PARAM_INT, 'notice being edited, 0 while it is new', VALUE_DEFAULT, 0),
            'pathmatch' => new external_value(PARAM_RAW, 'page reach being considered', VALUE_DEFAULT, ''),
            'repeats' => new external_value(PARAM_BOOL, 'whether the notice is set to repeat', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Repeating notices the one being edited would compete with, for the editor to show live.
     *
     * The author is told while they are still choosing, rather than after saving. Only the titles
     * cross the boundary: this answers "who would you be competing with", and a notice's page reach
     * or audience is not the editor's to hand out beyond that.
     *
     * @param int $noticeid Notice being edited; 0 while it is new.
     * @param string $pathmatch Page reach being considered.
     * @param bool $repeats Whether the notice is set to repeat.
     * @return array
     * @throws \required_capability_exception
     */
    public static function execute(int $noticeid = 0, string $pathmatch = '', bool $repeats = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'noticeid' => $noticeid,
            'pathmatch' => $pathmatch,
            'repeats' => $repeats,
        ]);

        // Only reached from the notice editor, and it reports on notices the caller may not
        // otherwise be able to see at all.
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        helper::require_author(author_scope::site(), 'manage');

        $clashes = collision::clashes_for(
            (int) $params['noticeid'],
            $params['pathmatch'],
            !empty($params['repeats']) ? 1 : 0
        );

        return [
            /*
             * Stripped, not escaped: the return slot is PARAM_TEXT, whose cleaner runs strip_tags(),
             * and clean_returnvalue() throws when the cleaned value differs from the original — a
             * title carrying a bare "<" before a letter failed the whole response for every author.
             * escape => false keeps the plain spelling the client's own escaping expects, and the
             * strip_tags() of our own is not redundant: format_string() only strips when the site's
             * formatstringstriptags is on, and with it off a "<b>" in a title would come back whole
             * and fail the same cleaning.
             */
            'titles' => array_values(array_map(function (awareness $notice) use ($syscontext): string {
                return strip_tags(
                    format_string($notice->get('title'), true, ['context' => $syscontext, 'escape' => false])
                );
            }, $clashes)),
        ];
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'titles' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'title of a repeating notice reaching the same pages')
            ),
        ]);
    }
}
