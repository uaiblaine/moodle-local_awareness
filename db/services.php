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
 * @package local_awareness
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_awareness_dismiss' => [
        'classname' => 'local_awareness\external',
        'methodname' => 'dismiss_notice',
        'description' => 'Dismiss a notice',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_acknowledge' => [
        'classname' => 'local_awareness\external',
        'methodname' => 'acknowledge_notice',
        'description' => 'Acknowledge a notice',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_tracklink' => [
        'classname' => 'local_awareness\external',
        'methodname' => 'track_link',
        'description' => 'Record link clicks',
        'type' => 'write',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_getnotices' => [
        'classname' => 'local_awareness\external',
        'methodname' => 'get_notices',
        'description' => 'Get notices for current user',
        'type' => 'read',
        'loginrequired' => true,
        'ajax' => true,
    ],

    'local_awareness_search_courses' => [
        'classname' => 'local_awareness\external',
        'methodname' => 'search_courses',
        'description' => 'Search courses by name for autocomplete',
        'type' => 'read',
        'loginrequired' => true,
        'ajax' => true,
    ],
];
