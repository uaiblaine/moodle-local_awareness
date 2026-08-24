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
use local_awareness\persistent\awareness;

/**
 * Dismiss a notice for the current user.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dismiss_notice extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'noticeid' => new external_value(PARAM_INT, 'notice id', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Dismisses notice.
     *
     * @param int $noticeid Notice ID.
     * @return array
     */
    public static function execute(int $noticeid): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['noticeid' => $noticeid]
        );

        self::validate_context(\context_system::instance());

        $result = [
            'status' => 0,
            'redirecturl' => '',
        ];

        /*
         * The site switch is checked at each entry point, the way should_load_on() checks it for
         * the footer hook, rather than inside the delivery helpers. Those helpers answer "what
         * would this user be shown", which is a question worth being able to ask with the switch
         * off; this is the boundary where the answer becomes an action. A silent no-op rather than
         * an exception: a disabled plugin should look like a plugin with nothing to say.
         */
        if (!helper::is_delivery_enabled()) {
            return $result;
        }

        $notice = awareness::get_record(['id' => $params['noticeid']]);

        /*
         * The notice id comes from the client, so both halves have to be re-established here:
         * the audience test, and the fact that this session was actually served the notice.
         * Without the second one a user in the notice's cohort who is not in the course it targets
         * could post a row that lands in the compliance report as consent given after display —
         * and the report is the reason this plugin exists.
         */
        if ($notice && helper::may_act_on_notice($notice)) {
            $result = helper::dismiss_notice($notice);
        }

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
                'redirecturl' => new external_value(PARAM_TEXT, 'redirect url', VALUE_DEFAULT, ""),
            ]
        );
    }
}
