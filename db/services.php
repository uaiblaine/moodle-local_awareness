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
 * Webservice function registry
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_awareness_dismiss' => [
        'classname' => 'local_awareness\\external\\dismiss_notice',
        'methodname' => 'execute',
        'description' => 'Dismiss a notice',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_acknowledge' => [
        'classname' => 'local_awareness\\external\\acknowledge_notice',
        'methodname' => 'execute',
        'description' => 'Acknowledge a notice',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_tracklink' => [
        'classname' => 'local_awareness\\external\\track_link',
        'methodname' => 'execute',
        'description' => 'Record link clicks',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_getnotices' => [
        'classname' => 'local_awareness\\external\\get_notices',
        'methodname' => 'execute',
        'description' => 'Get notices for current user',
        'type' => 'read',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_check_collision' => [
        'classname' => 'local_awareness\\external\\check_collision',
        'methodname' => 'execute',
        'description' => 'Repeating notices that would compete with this one for the same pages',
        'type' => 'read',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_search_roles' => [
        'classname' => 'local_awareness\\external\\search_roles',
        'methodname' => 'execute',
        'description' => 'Search roles dynamically based on context',
        'type' => 'read',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_search_courses' => [
        'classname' => 'local_awareness\\external\\search_courses',
        'methodname' => 'execute',
        'description' => 'Search courses by name for autocomplete',
        'type' => 'read',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_estimate_audience' => [
        'classname' => 'local_awareness\\external\\estimate_audience',
        'methodname' => 'execute',
        'description' => 'Enqueue an asynchronous audience-estimate job',
        'type' => 'write',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_get_estimate' => [
        'classname' => 'local_awareness\\external\\get_estimate',
        'methodname' => 'execute',
        'description' => 'Poll an audience-estimate job',
        'type' => 'read',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_preview_notice' => [
        'classname' => 'local_awareness\\external\\preview_notice',
        'methodname' => 'execute',
        'description' => 'Render the notice the editor currently holds, as the reader would get it',
        'type' => 'read',
        'capabilities' => 'local/awareness:manage',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_render_notice' => [
        'classname' => 'local_awareness\\external\\render_notice',
        'methodname' => 'execute',
        'description' => 'Render one saved notice for the manage list preview',
        'type' => 'read',
        'capabilities' => 'local/awareness:viewreports',
        'loginrequired' => true,
        'ajax' => true,
    ],
];
