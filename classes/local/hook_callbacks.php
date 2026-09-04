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
 * The notice module used to ride on the navigation callback, which fires mid-header wherever the
 * theme touches navigation — a point where $PAGE->url may not be set yet, and which also runs on
 * the navigation-expansion AJAX endpoint where the queued module can never execute. This hook
 * fires at the top of footer rendering: strictly later, with the URL as settled as it will ever
 * be, and only on renders that can actually deliver the module.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * @var string[] Page layouts the notice module never loads on.
     *
     * The first three follow tool_usertours: no notices in maintenance mode, when printing, or on
     * a redirect interstitial. Embedded and popup preserve today's coverage — the navigation
     * callback never fired there, so nothing changes. Secure is a deliberate product decision, not
     * preservation: with a sticky Navigation block the old callback could deliver a notice inside
     * a securewindow quiz attempt, and a modal (with a possible forced logout) inside a locked
     * exam window is accidental behaviour we choose to end. This is a denylist on purpose — an
     * unknown layout loads the module, it never suppresses it.
     */
    public const EXCLUDED_LAYOUTS = ['maintenance', 'print', 'redirect', 'embedded', 'popup', 'secure'];

    /**
     * Purge a course's notices before the course goes.
     *
     * This hook and not the course_deleted event: by the time that event fires the course row and
     * its context are already gone, and an observer resolving either throws inside a catch the
     * event manager keeps to itself — the purge would fail silently on exactly the courses that
     * have notices. The hook runs first, with everything still in place. Hook dispatch has no catch
     * of its own, so this one has: a fault here must not make a course undeletable, and whatever
     * it leaves behind is refused by author_scope::exists() rather than read as the site.
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

        if (!isloggedin() || !get_config('local_awareness', 'enabled')) {
            return false;
        }

        try {
            return helper::has_candidate_notices(page_probe::from_page($page));
        } catch (\Throwable $exception) {
            /*
             * Throwable, not Exception. This runs on essentially every page of the site, so an
             * Error escaping here — a typed setter handed null, a bad argument reaching
             * completion_info — is not a missing notice, it is a fatal on every page for every
             * logged-in user, recoverable only by disabling the plugin from the database. There is
             * no failure of this pipeline worth taking the site down for. page_probe already uses
             * Throwable at each of its four boundaries; this one had been left behind.
             *
             * Same treatment the navigation callback gave a pipeline failure: report, load nothing.
             * Page-rule uncertainty never lands here — page_probe degrades to "admit" internally.
             */
            debugging($exception->getMessage());
            return false;
        }
    }
}
