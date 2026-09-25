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
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'noticeid' => new external_value(PARAM_INT, 'notice being edited, 0 while it is new', VALUE_DEFAULT, 0),
            'pathmatch' => new external_value(PARAM_RAW, 'page reach being considered', VALUE_DEFAULT, ''),
            'repeats' => new external_value(PARAM_BOOL, 'whether the notice is set to repeat', VALUE_DEFAULT, false),
            'courseid' => new external_value(PARAM_INT, 'course the editor is scoped to, 0 for the site', VALUE_DEFAULT, 0),
            'perpetual' => new external_value(
                PARAM_BOOL,
                'whether the notice has no window; timeend is then ignored',
                VALUE_DEFAULT,
                true
            ),
            'timeend' => new external_single_structure(
                [
                    'year' => new external_value(PARAM_INT, 'year'),
                    'month' => new external_value(PARAM_INT, 'month, from 1'),
                    'day' => new external_value(PARAM_INT, 'day of the month'),
                    'hour' => new external_value(PARAM_INT, 'hour, from 0'),
                    'minute' => new external_value(PARAM_INT, 'minute'),
                ],
                'end of the window as the date selector holds it, in the user calendar and timezone; null when there is none',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
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
     * @param int $courseid The course the editor is scoped to, 0 for the site.
     * @param bool $perpetual Whether the notice has no window, which makes its end irrelevant.
     * @param array|null $timeend The end date selector's year, month, day, hour and minute; null when the form has none.
     * @return array
     * @throws \required_capability_exception
     */
    public static function execute(
        int $noticeid = 0,
        string $pathmatch = '',
        bool $repeats = false,
        int $courseid = 0,
        bool $perpetual = true,
        ?array $timeend = null
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'noticeid' => $noticeid,
            'pathmatch' => $pathmatch,
            'repeats' => $repeats,
            'courseid' => $courseid,
            'perpetual' => $perpetual,
            'timeend' => $timeend,
        ]);

        // Only reached from the notice editor, and it reports on notices the caller may not otherwise
        // see. The scope gate is explained in estimate_audience::execute().
        $scope = author_scope::for_request(null, (int) $params['courseid']);
        self::validate_context($scope->context());
        helper::require_author($scope, 'manage');

        /*
         * The reach compared is the one the save would store, not the one the client typed: under a
         * course scope the field does not exist and the scope writes the course's main page, so a
         * course author's editor would otherwise compare an empty pattern — which overlaps
         * everything — and warn about every repeating notice on the site.
         */
        $reach = (string) $scope->apply(['pathmatch' => $params['pathmatch']])->criteria()['pathmatch'];

        // The end compared is the one the save would store too: none for a perpetual notice.
        $end = (!$params['perpetual'] && $params['timeend'] !== null) ? self::selector_time($params['timeend']) : 0;

        $clashes = collision::clashes_for(
            (int) $params['noticeid'],
            $reach,
            !empty($params['repeats']) ? 1 : 0,
            $end
        );

        return [
            /*
             * The plain spelling, stripped of tags: the slot is PARAM_TEXT and collision_warning.js
             * writes it through textContent ({@see collision::formatted_titles()}). A rival outside
             * the scope is named for what it is, not by its title.
             */
            'titles' => collision::formatted_titles($clashes, $scope, false),
        ];
    }

    /**
     * The timestamp a date and time selector's parts stand for.
     *
     * Converted as the form converts them when it is saved ({@see \MoodleQuickForm_date_time_selector::exportValue()}):
     * the parts are in the user's calendar and timezone, which only the server knows, so the editor
     * sends them as they are rather than guessing a timestamp from the browser's clock.
     *
     * @param array $parts The selector's year, month, day, hour and minute.
     * @return int
     */
    private static function selector_time(array $parts): int {
        $date = \core_calendar\type_factory::get_calendar_instance()->convert_to_gregorian(
            $parts['year'],
            $parts['month'],
            $parts['day'],
            $parts['hour'],
            $parts['minute']
        );

        // Timezone 99 is the user's, the selector's default and the one notice_form leaves in place.
        return (int) make_timestamp($date['year'], $date['month'], $date['day'], $date['hour'], $date['minute'], 0, 99, true);
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
