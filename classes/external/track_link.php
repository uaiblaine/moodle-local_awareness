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

/**
 * Record that the current user followed a link inside a notice.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class track_link extends external_api {
    /**
     * Incoming params.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'linkid' => new external_value(PARAM_INT, 'link id', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Track link.
     *
     * @param int $linkid Link ID.
     * @return array
     */
    public static function execute(int $linkid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['linkid' => $linkid]);

        self::validate_context(\context_system::instance());

        // See dismiss_notice(): the switch is enforced at the boundary, not in the helper.
        if (!helper::is_delivery_enabled()) {
            return ['status' => false];
        }

        return helper::track_link($params['linkid']);
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        // No redirecturl. It was declared here and never returned by helper::track_link(), which
        // told a reader of the contract that this function might navigate the browser — the one
        // thing a click-tracking call must not appear to do.
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
            ]
        );
    }
}
