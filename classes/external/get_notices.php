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
use local_awareness\local\notice_payload;

/**
 * The notices the current user should be shown on this page.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_notices extends external_api {
    /**
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pageurl' => new external_value(PARAM_RAW, 'current page url', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'current course id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Gets a list of notices.
     *
     * @param string $pageurl Current page URL. Must not be empty.
     * @param int $courseid Current course ID.
     * @return array
     * @throws \invalid_parameter_exception
     */
    public static function execute(string $pageurl, int $courseid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['pageurl' => $pageurl, 'courseid' => $courseid]
        );

        self::validate_context(\context_system::instance());

        /*
         * The page URL drives the pathmatch and check_filters() rules that decide who may read these
         * rendered bodies, so it cannot be empty. VALUE_REQUIRED only rejects an omitted one; an
         * empty one is refused here as a parameter error rather than reaching
         * retrieve_user_notices(), which throws a coding_exception for it.
         */
        if (trim($params['pageurl']) === '') {
            throw new \invalid_parameter_exception('pageurl must not be empty');
        }

        // Nothing is delivered while delivery is off; dismiss_notice explains why the check sits here.
        // An empty list rather than an error, so the client renders nothing and says nothing.
        if (!helper::is_delivery_enabled()) {
            return ['status' => true, 'notices' => []];
        }

        $result = [];
        $result['status'] = true;
        /*
         * One builder for every service that hands a notice to the dialogue; see notice_payload.
         * select_for_display() picks what to show now, normally only the head of the queue so a page
         * never stacks modals (its docblock gives the one exception). It keys its result by notice
         * id, and an external_multiple_structure is a list, hence array_values().
         */
        $result['notices'] = array_values(
            array_map(
                [notice_payload::class, 'build'],
                helper::select_for_display(
                    helper::retrieve_user_notices($params['pageurl'], (int) $params['courseid'])
                )
            )
        );

        return $result;
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
                /*
                 * A declared structure rather than a PARAM_RAW JSON blob, so clean_returnvalue()
                 * strips any key notice_payload::build() returns without declaring it. Why the prose
                 * fields are PARAM_RAW is explained at notice_payload::structure().
                 */
                'notices' => new external_multiple_structure(notice_payload::structure(), 'The notices to display now'),
            ]
        );
    }
}
