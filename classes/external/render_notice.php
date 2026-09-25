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
 * The payload is the one the reader's queue receives ({@see notice_payload}), so the preview shows
 * the notice's layout, position, image and slides. Only the gate differs: the question here is the
 * viewer's standing over the notice, not whether they are in its audience.
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
     * Every refusal is the same "no such notice", as {@see helper::resolve_notice_as_author()}
     * answers the pages: an id naming nothing, a notice outside the viewer's authority, and one
     * aimed only at groups the viewer may not reach, which the manage list does not show them. The
     * gate is asked before validate_context(), whose login check for another course's context
     * would otherwise refuse differently and say that the id names a notice in some course.
     *
     * @param int $noticeid The notice id.
     * @return array As notice_payload::structure() declares.
     * @throws \moodle_exception notification:noticedoesnotexist, for every refusal.
     */
    public static function execute(int $noticeid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['noticeid' => $noticeid]);

        $notice = helper::resolve_notice((int) $params['noticeid']);
        if (!$notice) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }

        $scope = author_scope::of($notice);
        $authorised = helper::require_author($scope, 'manage', false) || helper::require_author($scope, 'viewreports', false);
        if (!$authorised || !helper::may_reach_groups($notice)) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }
        // A notice whose course is gone has no course context; require_author() admitted the site
        // capability for it at the system context, so that is the context validated.
        self::validate_context($scope->exists() ? $scope->context() : \context_system::instance());

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
