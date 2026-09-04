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
use local_awareness\local\notice_payload;

/**
 * One saved notice, rendered as the reader would get it, for the manage list's preview.
 *
 * The list used to carry each notice's rendered content in a data attribute for a plain dialogue
 * to show. That dialogue knew nothing of layouts, positions, images or slides, so the one place
 * an administrator browses live notices previewed every one of them as the classic dialogue. The
 * payload is the same the reader's queue receives; only the gate differs, because the audience is
 * not the question here - the viewer's standing over the notice is.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render_notice extends external_api {
    /**
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'noticeid' => new external_value(PARAM_INT, 'the notice to render', VALUE_REQUIRED),
        ]);
    }

    /**
     * Render one notice for a viewer who may manage it or read its reports.
     *
     * Both verbs open the manage list, and the list offers the preview to both; a reports-only
     * viewer reads the notice they report on. The context is the notice's own scope, resolved
     * server-side from the id.
     *
     * @param int $noticeid The notice id.
     * @return array As notice_payload::structure() declares.
     * @throws \moodle_exception When the id names nothing; resolve_notice() fails closed itself.
     * @throws \required_capability_exception When the viewer holds neither verb over the notice.
     */
    public static function execute(int $noticeid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['noticeid' => $noticeid]);

        $notice = helper::resolve_notice((int) $params['noticeid']);
        if (!$notice) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }

        $scope = author_scope::of($notice);
        self::validate_context($scope->context());
        if (!helper::require_author($scope, 'manage', false) && !helper::require_author($scope, 'viewreports', false)) {
            throw new \required_capability_exception($scope->context(), 'local/awareness:viewreports', 'nopermissions', '');
        }

        return notice_payload::build($notice);
    }

    /**
     * Return parameters: the same shape the reader's queue receives.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return notice_payload::structure();
    }
}
