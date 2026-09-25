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

namespace local_awareness\local;

use core\hook\output\before_footer_html_generation;
use core_course\hook\before_course_deleted;
use local_awareness\helper;

/**
 * Hook callbacks for local_awareness.
 *
 * The notice module is queued from before_footer_html_generation rather than a navigation
 * callback. Navigation callbacks fire mid-header, where $PAGE->url may not be set yet, and also
 * run on the navigation-expansion AJAX endpoint, where a queued module never executes; this hook
 * fires at the top of footer rendering, with the URL settled, and only on renders that can
 * deliver the module.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * @var string[] Page layouts the notice module never loads on.
     *
     * Maintenance, print and redirect follow {@see \tool_usertours\helper::bootstrap()}: no
     * notices in maintenance mode, when printing, or on a redirect interstitial. Embedded and popup
     * keep the module out of frames and pop-up windows, and secure keeps a modal out of a
     * locked-down quiz attempt window. A denylist on purpose: an unknown layout loads the module.
     */
    public const EXCLUDED_LAYOUTS = ['maintenance', 'print', 'redirect', 'embedded', 'popup', 'secure'];

    /**
     * Purge a course's notices before the course goes.
     *
     * The hook and not the course_deleted event: that event fires after the course row and its
     * context are gone, so a purge resolving either would fail, and the event manager swallows
     * observer exceptions. Hook dispatch does not catch, so this callback does: a failed purge must
     * not make a course undeletable, and any notice it leaves behind is refused through
     * {@see author_scope::exists()} rather than read as a site notice.
     *
     * @param before_course_deleted $hook The hook being dispatched.
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        $courseid = (int) $hook->course->id;
        try {
            helper::purge_course_notices($courseid);
        } catch (\Throwable $exception) {
            debugging("local_awareness could not purge the notices of course {$courseid}: " . $exception->getMessage());
        }
    }

    /**
     * Load the notice module when something could be shown on this page.
     *
     * @param before_footer_html_generation $hook The hook being dispatched.
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if (self::should_load_on($PAGE)) {
            $PAGE->requires->js_call_amd('local_awareness/notice', 'init', []);
        }
    }

    /**
     * Whether the notice module should be loaded for the current user on this page.
     *
     * @param \moodle_page $page The page being rendered.
     * @return bool
     */
    public static function should_load_on(\moodle_page $page): bool {
        if (in_array($page->pagelayout, self::EXCLUDED_LAYOUTS, true)) {
            return false;
        }

        if (!isloggedin() || !helper::is_delivery_enabled()) {
            return false;
        }

        try {
            return helper::has_candidate_notices(page_probe::from_page($page));
        } catch (\Throwable $exception) {
            /*
             * Throwable, not Exception: this runs on almost every page, so an Error escaping here
             * (a typed setter handed null, a bad argument reaching completion_info) would be a fatal
             * on every page for every logged-in user, recoverable only by disabling the plugin in
             * the database. Report and load nothing. Page-rule uncertainty never reaches this catch:
             * page_probe admits on its own failures.
             */
            debugging($exception->getMessage());
            return false;
        }
    }
}
