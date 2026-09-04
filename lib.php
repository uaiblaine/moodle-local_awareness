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

    if (!in_array($filearea, ['content', 'bgimage', \local_awareness\persistent\slide::FILEAREA])) {
        return false;
    }

    require_login($course, true, $cm);

    $itemid = (int) array_shift($args);

    // A slide's image is keyed by the slide id; the gate below is still the notice's.
    $noticeid = $itemid;
    if ($filearea === \local_awareness\persistent\slide::FILEAREA) {
        $slide = \local_awareness\persistent\slide::get_record(['id' => $itemid]);
        if (!$slide) {
            return false;
        }
        $noticeid = (int) $slide->get('noticeid');
    }

    $notice = \local_awareness\persistent\awareness::get_record(['id' => $noticeid]);
    if (!$notice) {
        return false;
    }

    /*
     * The gate itself lives in helper::may_serve_files_of(), where it can be tested without serving
     * a file; what it covers, and what it deliberately does not, is written there. It used to be
     * enabled-only, which left the attachments of a cohort-targeted notice readable by any
     * authenticated user who guessed the id.
     */
    if (!\local_awareness\helper::may_serve_files_of($notice)) {
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

/**
 * Add the course's notices to its navigation for the people who may author or report on them.
 *
 * @param navigation_node $navigation The course settings node.
 * @param stdClass $course The course.
 * @param context $context The course context.
 */
function local_awareness_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    /*
     * Core hands this callback the SITE course too: settings_navigation's CONTEXT_MODULE branch
     * calls load_course_settings() with no site guard, so every activity on the front page reaches
     * here with course id 1. The site has no course scope — its notices live in Site administration
     * — and author_scope::course() refuses the site course by design, so this returns before it is
     * asked. It has to be the first statement.
     */
    if ((int) $course->id <= SITEID) {
        return;
    }

    // The manage page opens for either verb: the reports capability alone gets a read-only list, from
    // which each notice's two reports are reached. A link is shown only where its page will open.
    $scope = \local_awareness\local\author_scope::course((int) $course->id);
    if (
        !\local_awareness\helper::require_author($scope, 'manage', false)
        && !\local_awareness\helper::require_author($scope, 'viewreports', false)
    ) {
        return;
    }

    $navigation->add(
        get_string('coursenotices', 'local_awareness'),
        new moodle_url('/local/awareness/managenotice.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'localawareness',
        new pix_icon('i/settings', '')
    );
}
