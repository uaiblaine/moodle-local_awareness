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
 * Inject to every page.
 *
 * @package local_awareness
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_awareness\helper;

/**
 * A callback to extend navigation.
 *
 * @param \global_navigation $navigation Navigation instance.
 */
function local_awareness_extend_navigation(global_navigation $navigation) {
    global $PAGE;

    if (!isloggedin() || !get_config('local_awareness', 'enabled')) {
        return;
    }

    try {
        // Note: retrieve_user_notices() without a pageurl uses $PAGE->url fallback.
        // The definitive path/filter check happens in the AJAX call (get_notices)
        // where the real page URL is passed from JavaScript.
        $usernotices = helper::retrieve_user_notices();
        if (!empty($usernotices)) {
            $PAGE->requires->js_call_amd('local_awareness/notice', 'init', []);
        }
    } catch (Exception $exception) {
        debugging($exception->getMessage());
        return;
    }
}
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

    $itemid = array_shift($args);
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
