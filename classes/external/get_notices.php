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
            return ['status' => true, 'notices' => '[]'];
        }

        $result = [];
        $result['status'] = true;
        $result['notices'] = json_encode(
            array_map(
                function (awareness $notice): \stdClass {
                    /*
                     * Only what the modal reads crosses the boundary. The record used to be
                     * serialised whole, which shipped the notice's segmentation metadata —
                     * pathmatch, filtervalues, cohorts, the scheduling window, resetinterval —
                     * and the author's user id to every user the notice was displayed to. The
                     * returns declaration is PARAM_RAW JSON, so nothing downstream strips a key;
                     * this allowlist is the only gate. Values are picked from to_record() rather
                     * than re-cast, so the client keeps receiving the exact types it always has.
                     */
                    $record = $notice->to_record();
                    $payload = new \stdClass();
                    foreach (['id', 'title', 'modal_width', 'modal_height'] as $field) {
                        $payload->$field = $record->$field;
                    }
                    /*
                     * One level rather than the three booleans that used to cross the boundary.
                     * The dialogue's job is to be as insistent as the author asked, and it now
                     * reads a single ordered value to decide that — so reqack, outsideclick and
                     * forcelogout no longer travel, and the client cannot recombine them into a
                     * state the server never meant. forcelogout in particular is gone from the
                     * payload because nothing acts on it any more.
                     */
                    $payload->insistence = $notice->get_insistence();
                    // Storage holds the content as authored; filters and pluginfile URLs are
                    // resolved here so a multilang notice reads in each user's own language.
                    $payload->content = helper::render_content($notice);
                    /*
                     * The title gets the same treatment for the same reason. It is stored as the
                     * author typed it, and the modal renders it through {{title}}, so without this
                     * a multilang title shows its markup literally in the heading while the body
                     * beneath it resolves correctly — the one place the two disagree.
                     */
                    $payload->title = format_string(
                        $record->title,
                        true,
                        ['context' => \context_system::instance()]
                    );
                    // Attach background image URL if one exists.
                    if (!empty($record->bgimage)) {
                        $payload->bgimageurl = helper::get_bgimage_url($record->id);
                    } else {
                        $payload->bgimageurl = '';
                    }
                    return $payload;
                },
                /*
                 * select_for_display() is what makes this one notice at a time. Everything the
                 * user is eligible for is computed first; only the head of the queue is sent, so
                 * arriving at a page never stacks modals. See its docblock for the two tiers.
                 */
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
                'notices' => new external_value(PARAM_RAW, 'json of notices', VALUE_DEFAULT, ""),
            ]
        );
    }
}
