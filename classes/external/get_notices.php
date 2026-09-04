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
         * This function returns rendered notice bodies, and the page URL is what drives the
         * pathmatch and check_filters() rules that decide who may read them. An empty value used
         * to mean "apply no page rules at all", so any authenticated caller could read every
         * role-, category-, course-, format-, theme- and competency-targeted notice on the site
         * just by leaving the parameter out. VALUE_REQUIRED rejects the omission; this rejects the
         * empty string that would still satisfy it.
         */
        if (trim($params['pageurl']) === '') {
            throw new \invalid_parameter_exception('pageurl must not be empty');
        }

        // See dismiss_notice(): the switch is enforced at the boundary, not in the helper. An empty
        // list rather than an error — the client renders nothing and says nothing.
        if (!helper::is_delivery_enabled()) {
            return ['status' => true, 'notices' => []];
        }

        $result = [];
        $result['status'] = true;
        /*
         * One builder for every service that hands a notice to the dialogue - see notice_payload.
         * select_for_display() is what makes this one notice at a time: everything the user is
         * eligible for is computed first; only the head of the queue is sent, so arriving at a
         * page never stacks modals. array_values(), because it keys its result by notice id and
         * an external_multiple_structure is a list.
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
                 * A real structure, not a PARAM_RAW JSON blob. While this was a string core could
                 * not see inside it: clean_returnvalue() had nothing to check, so the allowlist was
                 * whatever the hand-written loop in execute() happened to copy, guarded by a single
                 * PHPUnit assertion. Declaring it moves the guarantee into the framework — an
                 * undeclared key is now stripped by core before it leaves the server, which is what
                 * audit finding WS-01 asked for.
                 *
                 * The prose fields are PARAM_RAW on purpose, and it is not laziness. title and
                 * content carry rendered HTML that has to reach the client byte for byte, and
                 * modal_width / modal_height are PARAM_RAW in the persistent, so they can hold a
                 * character PARAM_TEXT would strip — and a PARAM_TEXT field whose cleaned value
                 * differs from the original THROWS, killing the whole response for every reader
                 * rather than dropping one field. The allowlist is the key set; the types are only
                 * what can safely be said about each value.
                 */
                'notices' => new external_multiple_structure(notice_payload::structure(), 'The notices to display now'),
            ]
        );
    }
}
