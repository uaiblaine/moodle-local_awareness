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

/**
 * Plugin file serving.
 *
 * The decision to load the notice module lives in
 * \local_awareness\local\hook_callbacks::before_footer_html_generation(), registered in
 * db/hooks.php — not here. It used to be a navigation callback in this file; the hook fires
 * later in the render, when $PAGE->url is settled enough to judge the page rules.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve the files from the MYPLUGIN file areas
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if the file not found, just send the file otherwise and do not return anything
 */
function local_awareness_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    if (!in_array($filearea, ['content', 'bgimage'])) {
        return false;
    }

    require_login($course, true, $cm);

    $itemid = (int) array_shift($args);

    $notice = \local_awareness\persistent\awareness::get_record(['id' => $itemid]);
    if (!$notice) {
        return false;
    }

    /*
     * A file URL carries a notice id and nothing about where the reader came from, so the audience
     * is resolved the same way the web-service writes resolve it. That covers the enabled flag,
     * the start of the scheduling window, the cohort list and the role rule — the legs that used
     * to be missing here, which meant the attachments of a cohort-targeted notice were readable by
     * any authenticated user who guessed the id.
     *
     * It deliberately does NOT cover the page-dependent rules in check_filters() — category,
     * course, format, theme, competency — because those need a page URL this request has not got,
     * exactly as documented on is_notice_available_to_user(). This gate is therefore PARTIAL by
     * construction, and saying so here is the point: a later reader must not "simplify" it against
     * a guarantee it never made.
     *
     * Managers bypass it so the editor and the manage table can still render an unpublished
     * notice, which is the case the old enabled-only test existed for.
     */
    $ismanager = has_capability('local/awareness:manage', \context_system::instance());
    if (!$ismanager && !\local_awareness\helper::is_notice_available_to_user($notice)) {
        return false;
    }

    $filename = array_pop($args);
    if (!$args) {
        $filepath = '/';
    } else {
        $filepath = '/' . implode('/', $args) . '/';
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_awareness', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}
